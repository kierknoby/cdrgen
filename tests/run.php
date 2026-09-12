#!/usr/bin/env php
<?php

require __DIR__ . '/../src/autoload.php';

use CdrGen\Concurrency\ConcurrencySemantics;
use CdrGen\Concurrency\ExpectedConcurrencyCalculator;
use CdrGen\Cli\WizardDateRange;
use CdrGen\Database\CdrRepository;
use CdrGen\Database\ConnectionSettings;
use CdrGen\Database\AccountcodePolicy;
use CdrGen\Database\ActiveRunStore;
use CdrGen\Database\LiveRunGuard;
use CdrGen\Database\ProcessLock;
use CdrGen\Database\StateDirectory;
use CdrGen\Database\SignalCleanup;
use CdrGen\Database\TransactionalStoragePolicy;
use CdrGen\Database\RunIdentity;
use CdrGen\Database\SchemaMapper;
use CdrGen\GenerationRequest;
use CdrGen\Generator;
use CdrGen\Random\HashStreamRandomSource;
use CdrGen\Random\SeededRandomSource;
use CdrGen\TrafficModel;
use CdrGen\TrafficProfile;
use CdrGen\TrunkProfiler;
use CdrGen\Version;

$tests = [];

final class SkipTest extends RuntimeException
{
}

final class StoragePolicyFailurePdo extends PDO
{
    public $beginTransactionCalls = 0;
    public $prepareCalls = 0;

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->exec(
            'CREATE TABLE cdr (id INTEGER PRIMARY KEY AUTOINCREMENT, accountcode TEXT NOT NULL, '
            . 'src TEXT NOT NULL, userfield TEXT NULL)'
        );
    }

    #[\ReturnTypeWillChange]
    public function getAttribute($attribute)
    {
        if ($attribute === PDO::ATTR_DRIVER_NAME) {
            throw new RuntimeException('controlled storage-policy discovery failure');
        }
        return parent::getAttribute($attribute);
    }

    public function beginTransaction(): bool
    {
        $this->beginTransactionCalls++;
        return parent::beginTransaction();
    }

    #[\ReturnTypeWillChange]
    public function prepare($query, $options = [])
    {
        $this->prepareCalls++;
        return parent::prepare($query, $options);
    }
}

final class TransactionCountingPdo extends PDO
{
    public $beginCalls = 0;
    public $commitCalls = 0;
    public $rollbackCalls = 0;

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->exec(
            'CREATE TABLE cdr (id INTEGER PRIMARY KEY AUTOINCREMENT, accountcode TEXT NOT NULL, '
            . 'src TEXT NOT NULL, userfield TEXT NULL)'
        );
    }

    public function beginTransaction(): bool
    {
        $this->beginCalls++;
        return parent::beginTransaction();
    }

    public function commit(): bool
    {
        $this->commitCalls++;
        return parent::commit();
    }

    public function rollBack(): bool
    {
        $this->rollbackCalls++;
        return parent::rollBack();
    }
}

function test(string $name, callable $callback): void
{
    global $tests;
    $tests[$name] = $callback;
}

function assertTrue($condition, string $message = 'assertion failed'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSame($actual, $expected, string $message = 'values differ'): void
{
    if ($actual !== $expected) {
        throw new RuntimeException(
            $message . '; expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

function requireSignalSupport(): void
{
    if (!function_exists('pcntl_signal')
        || !function_exists('pcntl_async_signals')
        || !function_exists('posix_kill')
    ) {
        throw new SkipTest('pcntl_signal, pcntl_async_signals, and posix_kill are required');
    }
}

function fixtureRequest(
    int $seed = 42,
    int $rows = 500,
    array $extensions = ['2001', '2002', '2010', '2011', '2020', '2200'],
    string $accountcode = 'CCTESTfixture',
    array $technologies = ['PJSIP', 'SIP']
): GenerationRequest {
    $scenario = new SeededRandomSource($seed);
    $profiling = $scenario->fork('trunk-profiling-v1');
    $profiler = new TrunkProfiler();
    $trunks = [];
    foreach (['PJSIP/Primary-In', 'PJSIP/Primary-Out', 'SIP/Backup-Failover'] as $index => $name) {
        $trunks[] = $profiler->profile(
            $name,
            ['2125550100', '2125550101'],
            ['1212', '1800'],
            $index,
            $name,
            $profiling
        );
    }
    return new GenerationRequest(
        TrafficProfile::named('medium'),
        strtotime('2026-05-04 00:00:00 UTC'),
        strtotime('2026-05-11 00:00:00 UTC'),
        $scenario,
        $extensions,
        $trunks,
        [
            'rows' => $rows,
            'accountcode' => $accountcode,
            'timezone' => 'UTC',
            'technologies' => $technologies,
        ]
    );
}

function fixtureResult(...$arguments)
{
    return (new Generator())->generate(fixtureRequest(...$arguments));
}

function safetyMetadata(int $accountcodeLength = 20): array
{
    return [
        ['Field' => 'id', 'Type' => 'integer', 'Extra' => 'auto_increment', 'Null' => 'NO', 'Default' => null],
        ['Field' => 'accountcode', 'Type' => "varchar({$accountcodeLength})", 'Extra' => '', 'Null' => 'NO', 'Default' => null],
        ['Field' => 'src', 'Type' => 'varchar(20)', 'Extra' => '', 'Null' => 'NO', 'Default' => null],
        ['Field' => 'userfield', 'Type' => 'varchar(255)', 'Extra' => '', 'Null' => 'YES', 'Default' => null],
    ];
}

function safetyDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE cdr (id INTEGER PRIMARY KEY AUTOINCREMENT, accountcode TEXT NOT NULL, src TEXT NOT NULL, userfield TEXT NULL)');
    return $pdo;
}

function runSignalWorker(string $mode, int $signal): array
{
    $directory = sys_get_temp_dir() . '/cdrgen-signal-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700, true);
    $database = $directory . '/cdr.sqlite';
    $command = 'exec ' . escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(__DIR__ . '/signal-worker.php') . ' '
        . escapeshellarg($database) . ' '
        . escapeshellarg($directory) . ' '
        . escapeshellarg($mode);
    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start signal test worker');
    }
    fclose($pipes[0]);
    $ready = fgets($pipes[1]);
    assertTrue(is_string($ready) && strpos($ready, 'READY ') === 0, 'signal worker did not become ready');
    $accountcode = trim(substr($ready, 6));
    $status = proc_get_status($process);
    assertTrue(posix_kill($status['pid'], $signal), 'could not signal worker');
    $exitCode = null;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        usleep(20000);
        $status = proc_get_status($process);
        if (!$status['running']) {
            $exitCode = $status['exitcode'];
            break;
        }
    }
    if ($exitCode === null) {
        posix_kill($status['pid'], SIGKILL);
        throw new RuntimeException('signal worker did not exit');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $pdo = new PDO('sqlite:' . $database);
    $rows = (int) $pdo->query('SELECT COUNT(*) FROM cdr')->fetchColumn();
    $stateExists = is_file($directory . '/active-run.json');
    return [$exitCode, $rows, $stateExists, $stderr, $directory, $database, $accountcode];
}

function removeSignalArtifacts(string $directory, string $database): void
{
    foreach (glob($database . '*') as $path) {
        unlink($path);
    }
    $record = $directory . '/active-run.json';
    if (is_file($record)) {
        unlink($record);
    }
    rmdir($directory);
}

test('profile definitions and version metadata', static function (): void {
    assertSame(TrafficProfile::named('light')->rows(), 250);
    assertSame(TrafficProfile::named('medium')->days(), 7);
    assertSame(TrafficProfile::named('heavy')->maximumDuration(), 2400);
    assertSame(Version::VERSION, '1.1.0-dev');
    assertTrue(preg_match('/^[0-9a-f]{40}$/', Version::BASE_REVISION) === 1);
    assertSame(Version::SOURCE_REVISION, null);
});

test('hash stream is stable, high entropy, restartable, and forkable', static function (): void {
    $identity = '00112233445566778899aabbccddeeff';
    $first = new HashStreamRandomSource($identity);
    $second = new HashStreamRandomSource($identity);
    $different = new HashStreamRandomSource('00112233445566778899aabbccddeefe');
    $firstValues = [];
    $secondValues = [];
    for ($index = 0; $index < 100; $index++) {
        $firstValues[] = $first->int(0, 1000000);
        $secondValues[] = $second->int(0, 1000000);
    }
    assertSame($firstValues, $secondValues);
    assertTrue($firstValues[0] !== $different->int(0, 1000000));
    assertSame(
        $first->fork('traffic')->int(0, 1000000),
        $second->fork('traffic')->int(0, 1000000),
        'fork must restart from canonical identity, not parent stream position'
    );
    assertTrue($first->fork('traffic')->int(0, 1000000) !== $first->fork('profiling')->int(0, 1000000));
});

test('generation ignores prior random consumption and repeats from one request', static function (): void {
    $request = fixtureRequest();
    $generator = new Generator();
    $first = $generator->generate($request);
    for ($index = 0; $index < 1000; $index++) {
        $request->random()->int(0, 1000000);
    }
    $profilingStream = $request->random()->fork('trunk-profiling-v1');
    for ($index = 0; $index < 1000; $index++) {
        $profilingStream->int(0, 1000000);
    }
    $second = $generator->generate($request);
    $third = $generator->generate($request);
    assertSame($first->rows(), $second->rows());
    assertSame($second->rows(), $third->rows());
    assertSame($first->datasetIdentity(), $second->datasetIdentity());
});

test('generation progress checkpoints preserve deterministic output', static function (): void {
    $request = fixtureRequest(42, 503);
    $generator = new Generator();
    $withoutProgress = $generator->generate($request);
    $checkpoints = [];
    $withProgress = $generator->generate(
        $request,
        static function (int $completed, int $total) use (&$checkpoints): void {
            $checkpoints[] = [$completed, $total];
        },
        100
    );
    $differentBatch = $generator->generate($request, static function (): void {
    }, 37);

    assertSame($checkpoints, [[100, 503], [200, 503], [300, 503], [400, 503], [500, 503], [503, 503]]);
    assertSame($withoutProgress->rows(), $withProgress->rows());
    assertSame($withProgress->rows(), $differentBatch->rows());
    assertSame($withoutProgress->statistics(), $withProgress->statistics());
    assertSame($withoutProgress->datasetIdentity(), $withProgress->datasetIdentity());
});

test('same canonical input reproduces and changed identity changes rows', static function (): void {
    $first = fixtureResult(10);
    $second = fixtureResult(10);
    $different = fixtureResult(11);
    assertSame($first->rows(), $second->rows());
    assertSame($first->datasetIdentity(), $second->datasetIdentity());
    assertTrue($first->rows() !== $different->rows());
    assertTrue($first->datasetIdentity() !== $different->datasetIdentity());
});

test('dataset identity includes core version and meaningful inventory', static function (): void {
    $request = fixtureRequest(12);
    $inputs = $request->datasetIdentityInputs();
    assertSame($inputs['core_version'], Version::VERSION);
    $expected = hash('sha256', json_encode($inputs, JSON_UNESCAPED_SLASHES));
    assertSame($request->datasetIdentity(), $expected);
    assertTrue($request->datasetIdentity() !== fixtureRequest(12, 500, ['2001', '2002', '2099'])->datasetIdentity());
});

test('generated rows cover traffic and maintain CDR invariants', static function (): void {
    $result = fixtureResult();
    $rows = $result->rows();
    assertSame(count($rows), 500);
    $directions = $dispositions = $types = $identifiers = [];
    foreach ($rows as $index => $row) {
        $directions[$row['direction']] = true;
        $dispositions[$row['disposition']] = true;
        if ($row['direction'] === 'inbound') $types[$row['_inbound_type']] = true;
        assertTrue($row['_start_ts'] >= strtotime('2026-05-04 UTC'));
        assertTrue($row['_start_ts'] < strtotime('2026-05-11 UTC'));
        assertTrue($row['_end_ts'] >= $row['_start_ts']);
        assertTrue($row['billsec'] <= $row['duration']);
        if ($row['disposition'] === 'ANSWERED') {
            assertTrue($row['_answer_ts'] !== null);
            assertSame($row['billsec'], $row['_end_ts'] - $row['_answer_ts']);
        } else {
            assertSame($row['billsec'], 0);
            assertSame($row['_answer_ts'], null);
        }
        assertTrue(!isset($identifiers[$row['uniqueid']]), 'duplicate uniqueid');
        $identifiers[$row['uniqueid']] = true;
        assertSame($row['linkedid'], $row['uniqueid']);
        assertSame($row['sequence'], $index + 1);
        if ($row['direction'] === 'internal') assertSame($row['trunk'], '');
        else assertTrue($row['trunk'] !== '');
    }
    foreach (['inbound', 'outbound', 'internal'] as $value) assertTrue(isset($directions[$value]));
    foreach (['ANSWERED', 'NO ANSWER', 'BUSY', 'FAILED'] as $value) assertTrue(isset($dispositions[$value]));
    foreach (['extension', 'ringgroup', 'queue', 'ivr'] as $value) assertTrue(isset($types[$value]));
    assertSame(array_sum($result->statistics()['directions']), 500);
});

test('traffic weighting and deterministic burst behavior', static function (): void {
    $model = new TrafficModel(new SeededRandomSource(7), 'UTC');
    $weekday = strtotime('2026-05-04 14:15 UTC');
    $weekend = strtotime('2026-05-03 03:15 UTC');
    assertTrue($model->weight($weekday, false) > $model->weight($weekend, false));
    assertTrue($model->weight(strtotime('2026-05-04 14:02 UTC'), false) > $model->weight($weekday, false));
    $schedule = $model->schedule(strtotime('2026-05-01 UTC'), strtotime('2026-05-02 UTC'), 500);
    $clusterFound = false;
    $previous = null;
    foreach (array_keys($schedule) as $timestamp) {
        if ($previous !== null && $timestamp - $previous <= 90) $clusterFound = true;
        $previous = $timestamp;
    }
    assertTrue($clusterFound, 'no deterministic burst found');
});

test('trunk intelligence and behavioral weights', static function (): void {
    $profiler = new TrunkProfiler();
    $cases = [
        'Primary-In' => ['primary', 'inbound'], 'Primary-Out' => ['primary', 'outbound'],
        'Main' => ['primary'], 'Secondary' => ['secondary'], 'Overflow' => ['secondary'],
        'Backup' => ['backup'], 'Failover' => ['backup'], 'Incoming' => ['inbound'],
        'Outgoing' => ['outbound'], 'TollFree' => ['tollfree'],
        'International' => ['international'], 'Fax' => ['fax'], 'Emergency' => ['emergency'],
        'incomming' => ['inbound'], 'prefered-outboud' => ['primary', 'outbound'],
    ];
    foreach ($cases as $name => $expectedTraits) {
        $traits = $profiler->traits($name);
        foreach ($expectedTraits as $trait) assertTrue($traits[$trait], "{$name} missing {$trait}");
    }
    $random = new SeededRandomSource(3);
    $primary = $profiler->profile('PJSIP/Primary-In', [], [], 0, 'Primary-In', $random);
    $backup = $profiler->profile('PJSIP/Backup', [], [], 1, 'Backup', $random);
    assertTrue($primary['weight'] > $backup['weight']);
    assertTrue($primary['inbound_weight'] > $primary['outbound_weight']);
    assertTrue($backup['failed_boost'] > 0);
});

test('legacy and CDR concurrency technology contracts', static function (): void {
    $row = static function (string $channel, string $dstchannel, string $direction = 'outbound'): array {
        return [
            'calldate' => '1970-01-01 00:16:40', 'duration' => 10, 'billsec' => 8,
            'disposition' => 'ANSWERED', 'channel' => $channel, 'dstchannel' => $dstchannel,
            'dst' => $direction === 'outbound' ? '2125550100' : '2002',
            '_start_ts' => 1000, '_answer_ts' => 1002, '_end_ts' => 1010,
            '_trunk' => $direction === 'internal' ? null : 'main', 'trunk' => $direction === 'internal' ? '' : 'main',
        ];
    };
    $legacy = new ExpectedConcurrencyCalculator(ConcurrencySemantics::ANSWERED_MEDIA);
    $pjsip = $legacy->calculate([$row('PJSIP/2001-a', 'PJSIP/main-a')]);
    assertSame($pjsip['global'], 1);
    assertSame($pjsip['trunks']['main'], 1);
    assertSame($pjsip['extensions_channel']['2001'], 1);
    assertSame($legacy->calculate([$row('SIP/2001-a', 'SIP/main-a')])['global'], 0);
    $mixed = $legacy->calculate([$row('SIP/main-a', 'PJSIP/2002-a', 'inbound')]);
    assertSame($mixed['global'], 1);
    assertSame($mixed['trunks']['main'], 1);
    assertSame($mixed['extensions_handled']['2002'], 1);
    assertSame($mixed['extensions_channel']['2002'], 1);
    $internal = $legacy->calculate([$row('PJSIP/2001-a', 'PJSIP/2002-a', 'internal')]);
    assertSame($internal['global'], 1);
    assertTrue($internal['trunks'] === []);
    assertSame($internal['extensions_handled']['2002'], 1);
    assertSame($internal['extensions_channel']['2001'], 1);
    assertSame($internal['extensions_channel']['2002'], 1);
    $cdr = (new ExpectedConcurrencyCalculator(ConcurrencySemantics::CDR))->calculate([
        $row('SIP/2001-a', 'SIP/main-a'),
    ]);
    assertSame($cdr['global'], 1, 'CDR semantics must include SIP-only answered rows');
});

test('configured trunk identity takes precedence over PJSIP extension heuristics', static function (): void {
    $row = static function (string $channel, string $dstchannel, string $trunk): array {
        return [
            'calldate' => '1970-01-01 00:16:40', 'duration' => 10, 'billsec' => 8,
            'disposition' => 'ANSWERED', 'channel' => $channel, 'dstchannel' => $dstchannel,
            'dst' => '2001', '_start_ts' => 1000, '_answer_ts' => 1002, '_end_ts' => 1010,
            '_trunk' => $trunk, 'trunk' => $trunk,
        ];
    };
    $rows = [
        $row('PJSIP/2001-a', 'PJSIP/20260827-a', '20260827'),
        $row('PJSIP/provider-a', 'PJSIP/2010-a', 'provider'),
    ];
    $calculator = new ExpectedConcurrencyCalculator();
    $configured = ['PJSIP/20260827', 'PJSIP/provider'];
    $first = $calculator->calculate($rows, $configured);
    $second = $calculator->calculate($rows, $configured);

    assertSame($first, $second, 'configured-trunk classification is not deterministic');
    assertSame($first['trunks']['20260827'], 1);
    assertSame($first['trunks']['provider'], 1);
    assertTrue(!isset($first['extensions_handled']['20260827']));
    assertTrue(!isset($first['extensions_channel']['20260827']));
    assertSame($first['extensions_channel']['2001'], 1);
    assertSame($first['extensions_handled']['2010'], 1);
    assertSame($first['extensions_channel']['2010'], 1);
});

test('inclusive concurrency, chunk boundaries, exclusions, and long calls', static function (): void {
    $calculator = new ExpectedConcurrencyCalculator();
    assertSame($calculator->calculate([])['global'], 0);
    assertSame($calculator->peak([[100, 200], [200, 300]]), 2);
    assertSame($calculator->peak([[3598, 3602], [3602, 3605]]), 2);
    assertSame($calculator->peak([[0, 90000]]), 1);
});

test('schema mapper optional, defaulted, nullable, auto, and mandatory columns', static function (): void {
    $mapper = new SchemaMapper();
    $metadata = [
        ['Field' => 'id', 'Extra' => 'auto_increment', 'Null' => 'NO', 'Default' => null],
        ['Field' => 'accountcode', 'Type' => 'varchar(20)', 'Extra' => '', 'Null' => 'NO', 'Default' => null],
        ['Field' => 'src', 'Extra' => '', 'Null' => 'NO', 'Default' => null],
        ['Field' => 'nullable', 'Extra' => '', 'Null' => 'YES', 'Default' => null],
        ['Field' => 'defaulted', 'Extra' => '', 'Null' => 'NO', 'Default' => 'database-default'],
    ];
    assertSame(
        $mapper->projection($metadata, ['accountcode' => 'CCTESTx', 'src' => '2001']),
        ['accountcode' => 'CCTESTx', 'src' => '2001']
    );
    $unsupported = $metadata;
    $unsupported[] = ['Field' => 'mandatory_unknown', 'Extra' => '', 'Null' => 'NO', 'Default' => null];
    try {
        $mapper->projection($unsupported, ['accountcode' => 'CCTESTx', 'src' => '2001']);
        throw new RuntimeException('mandatory column accepted');
    } catch (RuntimeException $error) {
        assertTrue(strpos($error->getMessage(), 'mandatory_unknown') !== false);
    }
});

test('repository supports varying projections and preserves database defaults', static function (): void {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE cdr (id INTEGER PRIMARY KEY AUTOINCREMENT, accountcode TEXT NOT NULL, src TEXT NOT NULL, userfield TEXT NOT NULL, optional TEXT NULL, defaulted TEXT NOT NULL DEFAULT 'db')");
    $metadata = [
        ['Field' => 'id', 'Extra' => 'auto_increment', 'Null' => 'NO', 'Default' => null],
        ['Field' => 'accountcode', 'Type' => 'varchar(20)', 'Extra' => '', 'Null' => 'NO', 'Default' => null],
        ['Field' => 'src', 'Extra' => '', 'Null' => 'NO', 'Default' => null],
        ['Field' => 'userfield', 'Type' => 'varchar(255)', 'Extra' => '', 'Null' => 'NO', 'Default' => null],
        ['Field' => 'optional', 'Extra' => '', 'Null' => 'YES', 'Default' => null],
        ['Field' => 'defaulted', 'Extra' => '', 'Null' => 'NO', 'Default' => 'db'],
    ];
    $repository = new CdrRepository($pdo, $metadata);
    assertSame($repository->insertAll([
        ['accountcode' => 'CCTEST00000000000001', 'src' => 'first', 'userfield' => 'cdrgen test'],
        ['accountcode' => 'CCTEST00000000000001', 'src' => 'second', 'userfield' => 'cdrgen test', 'optional' => 'later', 'defaulted' => 'override'],
        ['accountcode' => 'CCTEST00000000000001', 'src' => 'third', 'userfield' => 'cdrgen test', 'optional' => 'first-only'],
        ['accountcode' => 'CCTEST00000000000001', 'src' => 'fourth', 'userfield' => 'cdrgen test'],
    ]), 4);
    $rows = $pdo->query('SELECT src, optional, defaulted FROM cdr ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    assertSame($rows[0], ['src' => 'first', 'optional' => null, 'defaulted' => 'db']);
    assertSame($rows[1], ['src' => 'second', 'optional' => 'later', 'defaulted' => 'override']);
    assertSame($rows[3], ['src' => 'fourth', 'optional' => null, 'defaulted' => 'db']);
});

test('ordinary generated CDR rows have a uniform schema projection', static function (): void {
    $rows = array_slice(fixtureResult(22, 30)->rows(), 0, 30);
    $metadata = [];
    foreach (array_keys($rows[0]) as $field) {
        if (strpos($field, '_') === 0) {
            continue;
        }
        $metadata[] = [
            'Field' => $field,
            'Extra' => '',
            'Null' => 'YES',
            'Default' => null,
        ];
    }
    $mapper = new SchemaMapper();
    $shape = array_keys($mapper->projection($metadata, $rows[0]));
    foreach ($rows as $row) {
        assertSame(array_keys($mapper->projection($metadata, $row)), $shape);
    }
});

test('repository transaction rollback and exact cleanup', static function (): void {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE cdr (id INTEGER PRIMARY KEY AUTOINCREMENT, accountcode TEXT NOT NULL, src TEXT NOT NULL, userfield TEXT NOT NULL)');
    $metadata = [
        ['Field' => 'id', 'Extra' => 'auto_increment', 'Null' => 'NO', 'Default' => null],
        ['Field' => 'accountcode', 'Type' => 'varchar(20)', 'Extra' => '', 'Null' => 'NO', 'Default' => null],
        ['Field' => 'src', 'Extra' => '', 'Null' => 'NO', 'Default' => null],
        ['Field' => 'userfield', 'Type' => 'varchar(255)', 'Extra' => '', 'Null' => 'NO', 'Default' => null],
    ];
    $repository = new CdrRepository($pdo, $metadata);
    assertSame($repository->insertAll([['accountcode' => 'CCTEST00000000000002', 'src' => '2001', 'userfield' => 'cdrgen test']]), 1);
    $pdo->exec("INSERT INTO cdr(accountcode,src,userfield) VALUES ('REAL','9999','production')");
    assertSame($repository->cleanup('CCTEST00000000000002'), 1);
    assertSame((int) $pdo->query('SELECT COUNT(*) FROM cdr')->fetchColumn(), 1);
    $pdo->exec("CREATE TRIGGER reject_bad BEFORE INSERT ON cdr WHEN NEW.src = 'bad' BEGIN SELECT RAISE(ABORT, 'bad row'); END");
    try {
        $repository->insertAll([
            ['accountcode' => 'CCTEST00000000000003', 'src' => 'good', 'userfield' => 'cdrgen test'],
            ['accountcode' => 'CCTEST00000000000003', 'src' => 'bad', 'userfield' => 'cdrgen test'],
        ]);
        throw new RuntimeException('failed insert committed');
    } catch (PDOException $error) {
        // Expected.
    }
    assertSame((int) $pdo->query("SELECT COUNT(*) FROM cdr WHERE accountcode='CCTEST00000000000003'")->fetchColumn(), 0);
});

test('insertion progress preserves one transaction and complete rollback', static function (): void {
    $pdo = new TransactionCountingPdo();
    $repository = new CdrRepository($pdo, safetyMetadata());
    $first = AccountcodePolicy::deterministic('progress-success');
    $progress = [];
    $rows = [];
    foreach (['2001', '2002', '2003'] as $source) {
        $rows[] = ['accountcode' => $first, 'src' => $source, 'userfield' => 'cdrgen progress'];
    }
    $repository->insertAll($rows, static function (int $completed, int $total) use (&$progress): void {
        $progress[] = [$completed, $total];
    }, 2);
    assertSame($progress, [[2, 3], [3, 3]]);
    assertSame([$pdo->beginCalls, $pdo->commitCalls, $pdo->rollbackCalls], [1, 1, 0]);

    $repository->cleanup($first);
    $pdo->exec(
        "CREATE TRIGGER reject_progress BEFORE INSERT ON cdr WHEN NEW.src = 'bad' "
        . "BEGIN SELECT RAISE(ABORT, 'bad row'); END"
    );
    $failed = AccountcodePolicy::deterministic('progress-failure');
    $failedProgress = [];
    try {
        $repository->insertAll([
            ['accountcode' => $failed, 'src' => 'good', 'userfield' => 'cdrgen progress'],
            ['accountcode' => $failed, 'src' => 'bad', 'userfield' => 'cdrgen progress'],
            ['accountcode' => $failed, 'src' => 'later', 'userfield' => 'cdrgen progress'],
        ], static function (int $completed, int $total) use (&$failedProgress): void {
            $failedProgress[] = [$completed, $total];
        }, 1);
        throw new RuntimeException('mid-insertion failure committed');
    } catch (PDOException $error) {
        // Expected.
    }
    assertSame($failedProgress, [[1, 3]]);
    assertSame([$pdo->beginCalls, $pdo->commitCalls, $pdo->rollbackCalls], [2, 1, 1]);
    assertSame($repository->countExact($failed), 0);
});

test('progress callback failure rolls back the active insertion transaction', static function (): void {
    $pdo = new TransactionCountingPdo();
    $repository = new CdrRepository($pdo, safetyMetadata());
    $accountcode = AccountcodePolicy::deterministic('progress-callback-failure');
    try {
        $repository->insertAll([
            ['accountcode' => $accountcode, 'src' => '2001', 'userfield' => 'cdrgen progress'],
            ['accountcode' => $accountcode, 'src' => '2002', 'userfield' => 'cdrgen progress'],
        ], static function (int $completed): void {
            assertSame($completed, 1);
            throw new RuntimeException('deliberate progress callback failure');
        }, 1);
        throw new RuntimeException('progress callback failure was ignored');
    } catch (RuntimeException $error) {
        assertSame($error->getMessage(), 'deliberate progress callback failure');
    }
    assertSame([$pdo->beginCalls, $pdo->commitCalls, $pdo->rollbackCalls], [1, 0, 1]);
    assertSame($repository->countExact($accountcode), 0);
});

test('accountcodes fit varchar 20 with stable deterministic identity', static function (): void {
    $seen = [];
    for ($index = 0; $index < 128; $index++) {
        $accountcode = AccountcodePolicy::random();
        assertSame(strlen($accountcode), 20);
        assertTrue(preg_match('/^CCTEST[0-9a-f]{14}$/D', $accountcode) === 1);
        $seen[$accountcode] = true;
    }
    assertSame(count($seen), 128, 'random run identities unexpectedly collided in test sample');
    $fixture = AccountcodePolicy::deterministic('scenario-one');
    assertSame(strlen($fixture), 20);
    assertSame($fixture, AccountcodePolicy::deterministic('scenario-one'));
    assertTrue($fixture !== AccountcodePolicy::deterministic('scenario-two'));

    $pdo = safetyDatabase();
    $repository = new CdrRepository($pdo, safetyMetadata(20));
    $repository->insertAll([['accountcode' => $fixture, 'src' => '2001', 'userfield' => 'cdrgen test']]);
    assertSame($pdo->query('SELECT accountcode FROM cdr')->fetchColumn(), $fixture);
    $repository->cleanup($fixture);
});

test('accountcode capacity and bounded strings fail before writing', static function (): void {
    $pdo = safetyDatabase();
    $repository = new CdrRepository($pdo, safetyMetadata(19));
    try {
        $repository->insertAll([['accountcode' => AccountcodePolicy::random(), 'src' => '2001']]);
        throw new RuntimeException('short accountcode schema accepted');
    } catch (RuntimeException $error) {
        assertTrue(strpos($error->getMessage(), 'cannot safely hold') !== false);
    }
    assertSame((int) $pdo->query('SELECT COUNT(*) FROM cdr')->fetchColumn(), 0);

    $withoutMarker = array_values(array_filter(safetyMetadata(), static function (array $column): bool {
        return $column['Field'] !== 'userfield';
    }));
    try {
        (new CdrRepository($pdo, $withoutMarker))->assertSchemaSafe();
        throw new RuntimeException('live schema without recovery marker accepted');
    } catch (RuntimeException $error) {
        assertTrue(strpos($error->getMessage(), 'power-loss recovery') !== false);
    }

    $mapper = new SchemaMapper();
    try {
        $mapper->projection([
            ['Field' => 'accountcode', 'Type' => 'varchar(20)', 'Extra' => '', 'Null' => 'NO', 'Default' => null],
            ['Field' => 'src', 'Type' => 'varchar(3)', 'Extra' => '', 'Null' => 'NO', 'Default' => null],
        ], ['accountcode' => AccountcodePolicy::random(), 'src' => '2001']);
        throw new RuntimeException('oversized string accepted');
    } catch (RuntimeException $error) {
        assertTrue(strpos($error->getMessage(), 'possible truncation') !== false);
    }
    try {
        $mapper->projection([
            ['Field' => 'accountcode', 'Type' => 'varchar(20)', 'Extra' => '', 'Null' => 'NO', 'Default' => null],
            ['Field' => 'src', 'Type' => 'varchar(2)', 'Extra' => '', 'Null' => 'NO', 'Default' => null],
        ], ['accountcode' => AccountcodePolicy::random(), 'src' => 'éé']);
        throw new RuntimeException('conservative multibyte bound was not enforced');
    } catch (RuntimeException $error) {
        assertTrue(strpos($error->getMessage(), 'possible truncation') !== false);
    }
});

test('transactional storage policy accepts SQLite and InnoDB only', static function (): void {
    TransactionalStoragePolicy::assertSupported('sqlite');
    TransactionalStoragePolicy::assertSupported('mysql', 'InnoDB');
    TransactionalStoragePolicy::assertSupported('mysql', 'innodb');

    foreach (['MyISAM', 'MEMORY', '', null, 'unknown'] as $engine) {
        try {
            TransactionalStoragePolicy::assertSupported('mysql', $engine);
            throw new RuntimeException('unsafe MySQL engine accepted: ' . var_export($engine, true));
        } catch (RuntimeException $error) {
            assertTrue(strpos($error->getMessage(), 'requires InnoDB') !== false);
        }
    }
    try {
        TransactionalStoragePolicy::assertSupported('pgsql');
        throw new RuntimeException('unsupported PDO driver accepted');
    } catch (RuntimeException $error) {
        assertTrue(strpos($error->getMessage(), 'Unsupported PDO driver') !== false);
    }

    $repository = new CdrRepository(safetyDatabase(), safetyMetadata());
    $repository->assertTransactionalStorage();
});

test('transactional storage failure occurs before generated insertion begins', static function (): void {
    $pdo = new StoragePolicyFailurePdo();
    $repository = new CdrRepository($pdo, safetyMetadata());
    try {
        $repository->insertAll([[
            'accountcode' => AccountcodePolicy::random(),
            'src' => '2001',
            'userfield' => 'cdrgen test',
        ]]);
        throw new RuntimeException('insert proceeded without established transactional storage');
    } catch (RuntimeException $error) {
        assertTrue(strpos($error->getMessage(), 'transactional rollback safety') !== false);
    }
    assertSame($pdo->beginTransactionCalls, 0);
    assertSame($pdo->prepareCalls, 0);
    assertSame((int) $pdo->query('SELECT COUNT(*) FROM cdr')->fetchColumn(), 0);
});

test('exact identity verification rolls back simulated silent truncation', static function (): void {
    $pdo = safetyDatabase();
    $pdo->exec(
        "CREATE TRIGGER truncate_account AFTER INSERT ON cdr "
        . "BEGIN UPDATE cdr SET accountcode = substr(NEW.accountcode, 1, 19) WHERE id = NEW.id; END"
    );
    $repository = new CdrRepository($pdo, safetyMetadata());
    $accountcode = AccountcodePolicy::random();
    try {
        $repository->insertAll([
            ['accountcode' => $accountcode, 'src' => '2001'],
            ['accountcode' => $accountcode, 'src' => '2002'],
        ]);
        throw new RuntimeException('silently truncated transaction committed');
    } catch (RuntimeException $error) {
        assertTrue(strpos($error->getMessage(), 'verification failed') !== false);
    }
    assertSame((int) $pdo->query('SELECT COUNT(*) FROM cdr')->fetchColumn(), 0);
});

test('byte-exact marker verification rolls back hostile userfield mutation', static function (): void {
    $pdo = safetyDatabase();
    $pdo->exec(
        "CREATE TRIGGER mutate_marker AFTER INSERT ON cdr "
        . "BEGIN UPDATE cdr SET userfield = 'CDRGEN hostile' WHERE id = NEW.id; END"
    );
    $repository = new CdrRepository($pdo, safetyMetadata());
    $accountcode = AccountcodePolicy::random();
    try {
        $repository->insertAll([
            ['accountcode' => $accountcode, 'src' => '2001', 'userfield' => 'cdrgen expected'],
            ['accountcode' => $accountcode, 'src' => '2002', 'userfield' => 'cdrgen expected'],
        ]);
        throw new RuntimeException('mutated recovery markers committed');
    } catch (RuntimeException $error) {
        assertTrue(strpos($error->getMessage(), 'marker verification failed') !== false);
    }
    assertSame((int) $pdo->query('SELECT COUNT(*) FROM cdr')->fetchColumn(), 0);
});

test('cleanup is exact, verified, idempotent, and rejects invalid input', static function (): void {
    $pdo = safetyDatabase();
    $repository = new CdrRepository($pdo, safetyMetadata());
    $target = AccountcodePolicy::deterministic('cleanup-target');
    $other = AccountcodePolicy::deterministic('cleanup-other');
    $repository->insertAll([['accountcode' => $target, 'src' => '2001', 'userfield' => 'cdrgen test']]);
    $pdo->prepare('INSERT INTO cdr(accountcode, src) VALUES (:accountcode, :src)')
        ->execute([':accountcode' => $other, ':src' => '2002']);
    assertSame($repository->cleanup($target), 1);
    assertSame($repository->cleanup($target), 0);
    assertSame($repository->countExact($other), 1);
    try {
        $repository->cleanup('CCTEST%');
        throw new RuntimeException('unsafe cleanup input accepted');
    } catch (InvalidArgumentException $error) {
        assertSame($repository->countExact($other), 1);
    }
});

test('cleanup verification fails when a database leaves the exact row behind', static function (): void {
    $pdo = safetyDatabase();
    $repository = new CdrRepository($pdo, safetyMetadata());
    $accountcode = AccountcodePolicy::deterministic('undeletable-row');
    $repository->insertAll([['accountcode' => $accountcode, 'src' => '2001', 'userfield' => 'cdrgen test']]);
    $pdo->exec(
        "CREATE TRIGGER restore_deleted AFTER DELETE ON cdr "
        . "BEGIN INSERT INTO cdr(accountcode, src) VALUES (OLD.accountcode, OLD.src); END"
    );
    try {
        $repository->cleanup($accountcode);
        throw new RuntimeException('unverified cleanup reported success');
    } catch (RuntimeException $error) {
        assertTrue(strpos($error->getMessage(), 'Cleanup verification failed') !== false);
    }
    assertSame($repository->countExact($accountcode), 1);
});

test('live guard recovers stale runs and refuses an unexpected dirty database', static function (): void {
    $directory = sys_get_temp_dir() . '/cdrgen-test-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700, true);
    $pdo = safetyDatabase();
    $repository = new CdrRepository($pdo, safetyMetadata());
    $store = new ActiveRunStore($directory . '/active-run.json');
    $stale = AccountcodePolicy::deterministic('stale-run');
    $store->write($stale, false);
    $pdo->prepare('INSERT INTO cdr(accountcode, src) VALUES (:accountcode, :src)')
        ->execute([':accountcode' => $stale, ':src' => '2001']);
    $guard = new LiveRunGuard($repository, $store);
    assertSame($guard->recoverAndRequireClean(), [$stale => 'recovery-record']);
    assertSame($repository->generatedCounts(), []);
    assertTrue(!is_file($directory . '/active-run.json'));

    $dirty = AccountcodePolicy::deterministic('unexpected-dirty');
    $pdo->prepare('INSERT INTO cdr(accountcode, src) VALUES (:accountcode, :src)')
        ->execute([':accountcode' => $dirty, ':src' => '2002']);
    try {
        $guard->recoverAndRequireClean();
        throw new RuntimeException('dirty database accepted');
    } catch (RuntimeException $error) {
        assertTrue(strpos($error->getMessage(), $dirty . '=1') !== false);
    }
    $repository->cleanup($dirty);
    rmdir($directory);
});

test('database markers recover power-loss orphans without a sidecar', static function (): void {
    $directory = sys_get_temp_dir() . '/cdrgen-test-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700, true);
    $pdo = safetyDatabase();
    $repository = new CdrRepository($pdo, safetyMetadata());
    $store = new ActiveRunStore($directory . '/active-run.json');
    $orphan = AccountcodePolicy::deterministic('power-loss-orphan');
    $statement = $pdo->prepare(
        'INSERT INTO cdr(accountcode, src, userfield) VALUES (:accountcode, :src, :userfield)'
    );
    foreach (['2001', '2002'] as $source) {
        $statement->execute([
            ':accountcode' => $orphan,
            ':src' => $source,
            ':userfield' => 'cdrgen inbound direct',
        ]);
    }
    $guard = new LiveRunGuard($repository, $store);
    assertSame($guard->recoverAndRequireClean(), [$orphan => 'database-marker:2']);
    assertSame($repository->generatedCounts(), []);

    $uppercase = AccountcodePolicy::deterministic('uppercase-marker');
    $mixedCase = AccountcodePolicy::deterministic('mixed-case-marker');
    $partiallyMarked = AccountcodePolicy::deterministic('partially-marked');
    foreach ([
        [$uppercase, '2003', 'CDRGEN something'],
        [$mixedCase, '2004', 'CdrGen something'],
        [$partiallyMarked, '2005', 'cdrgen something'],
        [$partiallyMarked, '2006', 'CDRGEN something'],
    ] as [$accountcode, $source, $userfield]) {
        $statement->execute([
            ':accountcode' => $accountcode,
            ':src' => $source,
            ':userfield' => $userfield,
        ]);
    }
    try {
        $guard->recoverAndRequireClean();
        throw new RuntimeException('non-exact database markers were automatically deleted');
    } catch (RuntimeException $error) {
        assertTrue(strpos($error->getMessage(), $uppercase . '=1') !== false);
        assertTrue(strpos($error->getMessage(), $mixedCase . '=1') !== false);
        assertTrue(strpos($error->getMessage(), $partiallyMarked . '=2') !== false);
    }
    assertSame($repository->countExact($uppercase), 1);
    assertSame($repository->countExact($mixedCase), 1);
    assertSame($repository->countExact($partiallyMarked), 2);
    $repository->cleanup($uppercase);
    $repository->cleanup($mixedCase);
    $repository->cleanup($partiallyMarked);
    rmdir($directory);
});

test('temporary interruption cleans while explicit retention alone preserves', static function (): void {
    $directory = sys_get_temp_dir() . '/cdrgen-test-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700, true);
    $pdo = safetyDatabase();
    $repository = new CdrRepository($pdo, safetyMetadata());
    $store = new ActiveRunStore($directory . '/active-run.json');

    $temporary = AccountcodePolicy::deterministic('interrupted-temporary');
    $guard = new LiveRunGuard($repository, $store);
    $guard->prepare($temporary, false);
    $guard->armCleanup();
    $repository->insertAll([['accountcode' => $temporary, 'src' => '2001', 'userfield' => 'cdrgen test']]);
    $guard->emergencyCleanup();
    assertSame($repository->generatedCounts(), []);
    assertTrue(!is_file($directory . '/active-run.json'));

    $retained = AccountcodePolicy::deterministic('explicit-retention');
    $guard = new LiveRunGuard($repository, $store);
    $guard->prepare($retained, true);
    $guard->armCleanup();
    $repository->insertAll([['accountcode' => $retained, 'src' => '2002', 'userfield' => 'cdrgen test']]);
    $guard->emergencyCleanup();
    assertSame($repository->countExact($retained), 1);
    assertTrue(is_file($directory . '/active-run.json'));

    $nextRun = new LiveRunGuard($repository, $store);
    assertSame($nextRun->recoverAndRequireClean(), [$retained => 'recovery-record']);
    assertSame($repository->generatedCounts(), []);
    rmdir($directory);
});

test('live guard boundaries are either empty or exactly recoverable', static function (): void {
    $directory = sys_get_temp_dir() . '/cdrgen-test-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700, true);
    $pdo = safetyDatabase();
    $repository = new CdrRepository($pdo, safetyMetadata());
    $store = new ActiveRunStore($directory . '/active-run.json');

    // After prepare and before arming: no rows, durable exact recovery identity.
    $beforeArm = AccountcodePolicy::deterministic('before-arm');
    $guard = new LiveRunGuard($repository, $store);
    $guard->prepare($beforeArm, false);
    $guard->emergencyCleanup();
    assertSame($repository->generatedCounts(), []);
    assertTrue(is_file($store->path()));
    assertSame((new LiveRunGuard($repository, $store))->recoverAndRequireClean(), [
        $beforeArm => 'recovery-record',
    ]);

    // After arming and before beginTransaction: cleanup is safe and idempotent.
    $beforeBegin = AccountcodePolicy::deterministic('before-begin');
    $guard = new LiveRunGuard($repository, $store);
    $guard->prepare($beforeBegin, false);
    $guard->armCleanup();
    $guard->emergencyCleanup();
    $guard->emergencyCleanup();
    assertSame($repository->generatedCounts(), []);
    assertTrue(!is_file($store->path()));

    // During the insertion transaction / immediately before commit.
    $duringTransaction = AccountcodePolicy::deterministic('during-transaction');
    $guard = new LiveRunGuard($repository, $store);
    $guard->prepare($duringTransaction, false);
    $guard->armCleanup();
    $pdo->beginTransaction();
    $statement = $pdo->prepare('INSERT INTO cdr(accountcode, src, userfield) VALUES (?, ?, ?)');
    $statement->execute([$duringTransaction, '2001', 'cdrgen boundary-test']);
    $guard->emergencyCleanup();
    $pdo->commit();
    assertSame($repository->generatedCounts(), []);
    assertTrue(!is_file($store->path()));

    // Immediately after commit / after insertAll returns.
    $afterCommit = AccountcodePolicy::deterministic('after-commit');
    $guard = new LiveRunGuard($repository, $store);
    $guard->prepare($afterCommit, false);
    $guard->armCleanup();
    $repository->insertAll([[
        'accountcode' => $afterCommit,
        'src' => '2002',
        'userfield' => 'cdrgen boundary-test',
    ]]);
    $guard->emergencyCleanup();
    assertSame($repository->generatedCounts(), []);
    assertTrue(!is_file($store->path()));

    // After database cleanup but before recovery-record removal, startup can
    // safely repeat exact cleanup and then clear the sidecar.
    $afterDatabaseCleanup = AccountcodePolicy::deterministic('after-database-cleanup');
    $guard = new LiveRunGuard($repository, $store);
    $guard->prepare($afterDatabaseCleanup, false);
    $guard->armCleanup();
    $repository->insertAll([[
        'accountcode' => $afterDatabaseCleanup,
        'src' => '2003',
        'userfield' => 'cdrgen boundary-test',
    ]]);
    $repository->cleanup($afterDatabaseCleanup);
    assertTrue(is_file($store->path()));
    assertSame((new LiveRunGuard($repository, $store))->recoverAndRequireClean(), [
        $afterDatabaseCleanup => 'recovery-record',
    ]);
    assertSame($repository->generatedCounts(), []);
    assertTrue(!is_file($store->path()));
    rmdir($directory);
});

test('real SIGINT, SIGTERM, and SIGHUP callbacks clean rows and recovery state', static function (): void {
    requireSignalSupport();
    foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
        [$exitCode, $rows, $stateExists, $stderr, $directory, $database] = runSignalWorker('temporary', $signal);
        assertSame($exitCode, 128 + $signal);
        assertSame($rows, 0, 'signal left temporary rows behind');
        assertTrue(!$stateExists, 'signal removed rows but left recovery state');
        assertSame($stderr, '');
        removeSignalArtifacts($directory, $database);
    }
});

test('live signal support is required fail-closed', static function (): void {
    if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
        SignalCleanup::assertSupported();
        return;
    }
    try {
        SignalCleanup::assertSupported();
        throw new RuntimeException('live mode accepted missing pcntl support');
    } catch (RuntimeException $error) {
        assertTrue(strpos($error->getMessage(), 'requires the PHP pcntl extension') !== false);
    }
});

test('real signal honors explicit retention and preserves recovery state', static function (): void {
    requireSignalSupport();
    [$exitCode, $rows, $stateExists, $stderr, $directory, $database] = runSignalWorker('keep', SIGINT);
    assertSame($exitCode, 128 + SIGINT);
    assertSame($rows, 1);
    assertTrue($stateExists);
    assertSame($stderr, '');
    removeSignalArtifacts($directory, $database);
});

test('real signal cleanup failure is nonzero and preserves recovery state', static function (): void {
    requireSignalSupport();
    [$exitCode, $rows, $stateExists, $stderr, $directory, $database, $accountcode]
        = runSignalWorker('failure', SIGTERM);
    assertSame($exitCode, 1);
    assertSame($rows, 1);
    assertTrue($stateExists);
    assertTrue(strpos($stderr, $accountcode) !== false);
    assertTrue(strpos($stderr, 'Recovery record:') !== false);
    removeSignalArtifacts($directory, $database);
});

test('concurrent live process locks are rejected', static function (): void {
    $directory = sys_get_temp_dir() . '/cdrgen-test-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700, true);
    $path = $directory . '/live.lock';
    $first = ProcessLock::acquire($path);
    try {
        ProcessLock::acquire($path);
        throw new RuntimeException('concurrent lock accepted');
    } catch (RuntimeException $error) {
        assertTrue(strpos($error->getMessage(), 'already holds') !== false);
    }
    $first->release();
    $second = ProcessLock::acquire($path);
    $second->release();
    unlink($path);
    rmdir($directory);
});

test('state paths reject insecure permissions, symlinks, and non-regular files', static function (): void {
    $base = sys_get_temp_dir() . '/cdrgen-test-' . bin2hex(random_bytes(6));
    mkdir($base, 0700, true);
    $insecure = $base . '/insecure';
    mkdir($insecure, 0770);
    chmod($insecure, 0770);
    try {
        StateDirectory::ensureSecure($insecure);
        throw new RuntimeException('group-writable state directory accepted');
    } catch (RuntimeException $error) {
        assertTrue(strpos($error->getMessage(), 'mode 0700') !== false);
    }
    $target = $base . '/target';
    file_put_contents($target, 'protected');
    $link = $base . '/live-run.lock';
    symlink($target, $link);
    try {
        StateDirectory::assertRegularOrAbsent($link, 'process lock');
        throw new RuntimeException('symlink lock accepted');
    } catch (RuntimeException $error) {
        assertTrue(strpos($error->getMessage(), 'symlink') !== false);
    }
    assertSame(file_get_contents($target), 'protected');
    unlink($link);
    unlink($target);
    rmdir($insecure);
    rmdir($base);
});

test('installer rejects a state-directory symlink without modifying its target', static function (): void {
    $base = sys_get_temp_dir() . '/cdrgen-install-' . bin2hex(random_bytes(6));
    $target = $base . '/target';
    $statePath = $base . '/state';
    mkdir($target, 0755, true);
    file_put_contents($target . '/sentinel', 'unchanged');
    $targetMode = fileperms($target) & 0777;
    symlink($target, $statePath);

    $script = file_get_contents(__DIR__ . '/../install.sh');
    $script = str_replace('STATE_DIR="/var/lib/cdrgen"', 'STATE_DIR=' . escapeshellarg($statePath), $script);
    $script = str_replace('[ "$(id -u)" -ne 0 ]', '[ 0 -ne 0 ]', $script);
    $installer = $base . '/install.sh';
    file_put_contents($installer, $script);
    file_put_contents($base . '/cdrgen.php', 'fixture');

    $output = [];
    $status = 0;
    exec('bash ' . escapeshellarg($installer) . ' 2>&1', $output, $status);
    assertTrue($status !== 0, 'installer accepted a state-directory symlink');
    assertTrue(strpos(implode("\n", $output), 'must not be a symlink') !== false);
    assertSame(file_get_contents($target . '/sentinel'), 'unchanged');
    assertSame(fileperms($target) & 0777, $targetMode);

    unlink($installer);
    unlink($base . '/cdrgen.php');
    unlink($statePath);
    unlink($target . '/sentinel');
    rmdir($target);
    rmdir($base);
});

test('FreePBX connection settings preserve CDR and AMP fallbacks and ports', static function (): void {
    $cdr = ConnectionSettings::cdr([
        'CDRDBHOST' => 'cdr.example', 'CDRDBPORT' => '3307', 'CDRDBNAME' => 'cdrdb',
        'CDRDBUSER' => 'cdruser', 'CDRDBPASS' => 'cdrpass',
    ]);
    assertSame(ConnectionSettings::dsn($cdr), 'mysql:host=cdr.example;dbname=cdrdb;charset=utf8mb4;port=3307');
    $fallback = ConnectionSettings::cdr([
        'AMPDBHOST' => 'amp.example', 'AMPDBPORT' => 3308,
        'AMPDBUSER' => 'ampuser', 'AMPDBPASS' => 'amppass',
    ]);
    assertSame($fallback['host'], 'amp.example');
    assertSame($fallback['port'], 3308);
    assertSame($fallback['user'], 'ampuser');
    assertSame(ConnectionSettings::config(['AMPDBNAME' => 'asterisk_custom'])['database'], 'asterisk_custom');
});

test('dataset and run accountcode identities remain separate', static function (): void {
    $first = fixtureResult(9, 20, ['2001', '2002'], 'CCTESTone');
    $second = fixtureResult(9, 20, ['2001', '2002'], 'CCTESTtwo');
    assertSame($first->datasetIdentity(), $second->datasetIdentity());
    $firstRows = $first->rows();
    $secondRows = $second->rows();
    foreach ($firstRows as $index => $row) {
        unset($row['accountcode'], $secondRows[$index]['accountcode']);
        assertSame($row, $secondRows[$index]);
    }
    $random = RunIdentity::randomAccountcode();
    assertTrue(preg_match('/^CCTEST[0-9a-f]{14}$/', $random) === 1, 'normal run tag shape is weak or invalid');
    $light = fixtureRequest(9, 20)->datasetIdentity();
    $differentRange = new GenerationRequest(
        TrafficProfile::named('light'), 100, 200, new SeededRandomSource(9),
        ['2001', '2002'], fixtureRequest(9, 20)->trunks(), ['rows' => 20, 'timezone' => 'UTC']
    );
    assertTrue(RunIdentity::deterministicAccountcode($light) !== RunIdentity::deterministicAccountcode($differentRange->datasetIdentity()));
});

test('CLI help and restored wizard contracts are present', static function (): void {
    $output = [];
    $status = 0;
    exec(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/../cdrgen.php') . ' --help 2>&1', $output, $status);
    assertSame($status, 0);
    $help = implode("\n", $output);
    foreach (['--fixture-accountcode', '--concurrency-semantics=answered|cdr', '--dry-run', '--timezone=ZONE'] as $option) {
        assertTrue(strpos($help, $option) !== false, 'help missing ' . $option);
    }
    $source = file_get_contents(__DIR__ . '/../cdrgen.php');
    foreach (['Use profile default', 'Custom range', 'Use configured FreePBX trunks',
        'Specify trunks explicitly', 'CDR-only fake trunks', 'FAKE', 'QUIT',
        'About to generate', '--keep'] as $phrase) {
        assertTrue(strpos($source, $phrase) !== false, 'wizard contract missing ' . $phrase);
    }
});

test('opaque CLI stages and confirmed wizard transition provide wait feedback', static function (): void {
    $source = file_get_contents(__DIR__ . '/../cdrgen.php');
    foreach ([
        'Preparing confirmed run. Please wait...',
        'Loading FreePBX configuration. Please wait...',
        'Discovering configured trunks. Please wait...',
        'Checking CDR schema and recovery state. Please wait...',
        'Calculating/reporting concurrency. Please wait...',
    ] as $message) {
        assertTrue(strpos($source, $message) !== false, 'missing wait feedback: ' . $message);
    }
    assertTrue(
        strpos($source, 'Loading FreePBX configuration. Please wait...')
            < strpos($source, "require_once '/etc/freepbx.conf';"),
        'FreePBX wait feedback occurs after bootstrap'
    );
    $wizardStart = strpos($source, 'function runWizard(): array');
    $wizardEnd = strpos($source, 'function ask(', $wizardStart);
    $wizardSource = substr($source, $wizardStart, $wizardEnd - $wizardStart);
    assertTrue(
        strpos($wizardSource, 'Preparing confirmed run. Please wait...')
            < strpos($wizardSource, 'return $options;'),
        'wizard acknowledgement does not precede confirmed return'
    );
});

test('CLI rejects unknown and malformed options before generation', static function (): void {
    $entrypoint = escapeshellarg(__DIR__ . '/../cdrgen.php');
    $output = [];
    $status = 0;
    exec(PHP_BINARY . ' ' . $entrypoint
        . ' --profile=light --definitely-not-an-option --dry-run 2>&1', $output, $status);
    $text = implode("\n", $output);
    assertTrue($status !== 0);
    assertTrue(strpos($text, 'Unknown option: --definitely-not-an-option') !== false);
    assertTrue(strpos($text, "cdrgen\n======") === false);
    assertTrue(strpos($text, 'Generating CDRs:') === false);

    foreach (['--dry-run=yes', '--keep=no', '--help=foo'] as $malformedFlag) {
        $output = [];
        $status = 0;
        exec(PHP_BINARY . ' ' . $entrypoint . ' ' . escapeshellarg($malformedFlag) . ' 2>&1', $output, $status);
        assertTrue($status !== 0, "valued flag {$malformedFlag} was accepted");
        assertTrue(strpos(implode("\n", $output), 'does not accept a value') !== false);
    }

    $output = [];
    $status = 0;
    exec(PHP_BINARY . ' ' . $entrypoint . ' --profile 2>&1', $output, $status);
    assertTrue($status !== 0, 'missing value option was accepted');
    assertTrue(strpos(implode("\n", $output), '--profile requires a value') !== false);
});

test('CLI accepts valid boolean flags with normal value options', static function (): void {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../cdrgen.php')
        . ' --profile=light --seed=123 --start=' . escapeshellarg('2026-05-01 00:00:00')
        . ' --end=' . escapeshellarg('2026-05-02 00:00:00')
        . ' --fixture-accountcode --keep --dry-run 2>&1';
    $output = [];
    $status = 0;
    exec($command, $output, $status);
    $text = implode("\n", $output);
    assertSame($status, 0);
    assertTrue(strpos($text, 'Generating CDRs: 250 / 250 [100%]') !== false);
    assertTrue(strpos($text, 'no database writes') !== false);
});

test('CLI dry-run reports progress and performs no database writes', static function (): void {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../cdrgen.php')
        . ' --profile=light --seed=123 --start=' . escapeshellarg('2026-05-01 00:00:00')
        . ' --end=' . escapeshellarg('2026-05-02 00:00:00') . ' --dry-run 2>&1';
    $output = [];
    $status = 0;
    exec($command, $output, $status);
    $text = implode("\n", $output);
    assertSame($status, 0);
    assertTrue(strpos($text, 'Generating CDRs: 0 / 250 [0%]') !== false);
    assertTrue(strpos($text, 'Generating CDRs: 250 / 250 [100%]') !== false);
    assertTrue(strpos($text, 'no database writes') !== false);
    assertTrue(strpos($text, 'Writing CDRs:') === false);
    assertTrue(strpos($text, 'Calculating/reporting concurrency. Please wait...') !== false);
});

test('wizard accepts abbreviated profile and prints confirmation summary without PBX access', static function (): void {
    $command = "printf 'l\\n123\\n1\\n2\\nPJSIP/Test\\nn\\n' | "
        . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../cdrgen.php') . ' 2>&1';
    $output = [];
    $status = 0;
    exec($command, $output, $status);
    $text = implode("\n", $output);
    assertSame($status, 1);
    assertTrue(strpos($text, 'Profile: light') !== false);
    assertTrue(strpos($text, 'Date:') !== false);
    assertTrue(strpos($text, 'Trunks: PJSIP/Test') !== false);
    assertTrue(strpos($text, 'Cancelled.') !== false);
});

test('wizard profile-default dates are frozen as exact execution options', static function (): void {
    $now = 1789245078;
    $profile = TrafficProfile::named('heavy');
    $options = WizardDateRange::profileDefault($profile, $now, 'UTC');
    assertSame($options, [
        'start' => '2026-08-13 20:31:18',
        'end' => '2026-09-12 20:31:18',
    ]);

    $timezone = new DateTimeZone('UTC');
    $executionStart = (new DateTimeImmutable($options['start'], $timezone))->getTimestamp();
    $executionEnd = (new DateTimeImmutable($options['end'], $timezone))->getTimestamp();
    assertSame($executionStart, $now - $profile->days() * 86400);
    assertSame($executionEnd, $now);
    assertSame(
        (new DateTimeImmutable('@' . $executionStart))->setTimezone($timezone)->format('Y-m-d H:i:s'),
        $options['start']
    );
    assertSame(
        (new DateTimeImmutable('@' . $executionEnd))->setTimezone($timezone)->format('Y-m-d H:i:s'),
        $options['end']
    );
});

test('FreePBX bootstrap globals cannot overwrite CDRgen timezone state', static function (): void {
    $cdrgenTimezone = new DateTimeZone('America/New_York');
    require __DIR__ . '/fixtures/freepbx-bootstrap-pollution.php';
    assertSame($timezone, 'UTC', 'pollution fixture did not simulate generic timezone');
    assertTrue($cdrgenTimezone instanceof DateTimeZone);
    assertSame($cdrgenTimezone->getName(), 'America/New_York');

    $source = file_get_contents(__DIR__ . '/../cdrgen.php');
    assertTrue(strpos($source, 'formatTimestamp($cdrgenStart, $cdrgenTimezone)') !== false);
    $beforeBootstrap = strstr($source, "require_once '/etc/freepbx.conf';", true);
    foreach (['$timezone =', '$profile =', '$options =', '$metadata ='] as $unsafeDeclaration) {
        assertTrue(
            strpos($beforeBootstrap, $unsafeDeclaration) === false,
            'generic bootstrap-crossing declaration remains: ' . $unsafeDeclaration
        );
    }
});

$failures = 0;
$skipped = 0;
$started = microtime(true);
foreach ($tests as $name => $callback) {
    try {
        $callback();
        echo "PASS {$name}\n";
    } catch (SkipTest $error) {
        $skipped++;
        echo "SKIP {$name}: {$error->getMessage()}\n";
    } catch (Throwable $error) {
        $failures++;
        echo "FAIL {$name}: {$error->getMessage()}\n";
    }
}
echo "\n" . count($tests) . " tests, {$failures} failures, {$skipped} skipped, "
    . number_format(microtime(true) - $started, 3) . "s\n";
exit($failures === 0 ? 0 : 1);
