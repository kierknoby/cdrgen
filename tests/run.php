#!/usr/bin/env php
<?php

require __DIR__ . '/../src/autoload.php';

use CdrGen\Concurrency\ConcurrencySemantics;
use CdrGen\Concurrency\ExpectedConcurrencyCalculator;
use CdrGen\Database\CdrRepository;
use CdrGen\Database\ConnectionSettings;
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
        ['Field' => 'accountcode', 'Extra' => '', 'Null' => 'NO', 'Default' => null],
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
    $pdo->exec("CREATE TABLE cdr (id INTEGER PRIMARY KEY AUTOINCREMENT, accountcode TEXT NOT NULL, src TEXT NOT NULL, optional TEXT NULL, defaulted TEXT NOT NULL DEFAULT 'db')");
    $metadata = [
        ['Field' => 'id', 'Extra' => 'auto_increment', 'Null' => 'NO', 'Default' => null],
        ['Field' => 'accountcode', 'Extra' => '', 'Null' => 'NO', 'Default' => null],
        ['Field' => 'src', 'Extra' => '', 'Null' => 'NO', 'Default' => null],
        ['Field' => 'optional', 'Extra' => '', 'Null' => 'YES', 'Default' => null],
        ['Field' => 'defaulted', 'Extra' => '', 'Null' => 'NO', 'Default' => 'db'],
    ];
    $repository = new CdrRepository($pdo, $metadata);
    assertSame($repository->insertAll([
        ['accountcode' => 'CCTESTrows', 'src' => 'first'],
        ['accountcode' => 'CCTESTrows', 'src' => 'second', 'optional' => 'later', 'defaulted' => 'override'],
        ['accountcode' => 'CCTESTrows', 'src' => 'third', 'optional' => 'first-only'],
        ['accountcode' => 'CCTESTrows', 'src' => 'fourth'],
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
    $pdo->exec('CREATE TABLE cdr (id INTEGER PRIMARY KEY AUTOINCREMENT, accountcode TEXT NOT NULL, src TEXT NOT NULL)');
    $metadata = [
        ['Field' => 'id', 'Extra' => 'auto_increment', 'Null' => 'NO', 'Default' => null],
        ['Field' => 'accountcode', 'Extra' => '', 'Null' => 'NO', 'Default' => null],
        ['Field' => 'src', 'Extra' => '', 'Null' => 'NO', 'Default' => null],
    ];
    $repository = new CdrRepository($pdo, $metadata);
    assertSame($repository->insertAll([['accountcode' => 'CCTESTrun1', 'src' => '2001']]), 1);
    $pdo->exec("INSERT INTO cdr(accountcode,src) VALUES ('REAL','9999')");
    assertSame($repository->cleanup('CCTESTrun1'), 1);
    assertSame((int) $pdo->query('SELECT COUNT(*) FROM cdr')->fetchColumn(), 1);
    $pdo->exec("CREATE TRIGGER reject_bad BEFORE INSERT ON cdr WHEN NEW.src = 'bad' BEGIN SELECT RAISE(ABORT, 'bad row'); END");
    try {
        $repository->insertAll([
            ['accountcode' => 'CCTESTrun2', 'src' => 'good'],
            ['accountcode' => 'CCTESTrun2', 'src' => 'bad'],
        ]);
        throw new RuntimeException('failed insert committed');
    } catch (PDOException $error) {
        // Expected.
    }
    assertSame((int) $pdo->query("SELECT COUNT(*) FROM cdr WHERE accountcode='CCTESTrun2'")->fetchColumn(), 0);
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
    assertTrue(preg_match('/^CCTEST[0-9a-f]{16}$/', $random) === 1, 'normal run tag shape is weak or invalid');
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
        'About to generate', 'DELETE or KEEP'] as $phrase) {
        assertTrue(strpos($source, $phrase) !== false, 'wizard contract missing ' . $phrase);
    }
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
$started = microtime(true);
foreach ($tests as $name => $callback) {
    try {
        $callback();
        echo "PASS {$name}\n";
    } catch (Throwable $error) {
        $failures++;
        echo "FAIL {$name}: {$error->getMessage()}\n";
    }
}
echo "\n" . count($tests) . " tests, {$failures} failures, "
    . number_format(microtime(true) - $started, 3) . "s\n";
exit($failures === 0 ? 0 : 1);
