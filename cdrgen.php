#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require __DIR__ . '/src/autoload.php';

use CdrGen\Concurrency\ConcurrencySemantics;
use CdrGen\Concurrency\ExpectedConcurrencyCalculator;
use CdrGen\Cli\WizardDateRange;
use CdrGen\Database\CdrRepository;
use CdrGen\Database\ActiveRunStore;
use CdrGen\Database\ConnectionSettings;
use CdrGen\Database\LiveRunGuard;
use CdrGen\Database\ProcessLock;
use CdrGen\Database\SignalCleanup;
use CdrGen\Database\RunIdentity;
use CdrGen\GenerationRequest;
use CdrGen\Generator;
use CdrGen\Random\SeededRandomSource;
use CdrGen\TrafficProfile;
use CdrGen\TrunkProfiler;
use CdrGen\Version;

$cdrgenOptionSpec = [
    'profile' => 'value', 'seed' => 'value', 'rows' => 'value',
    'start' => 'value', 'end' => 'value', 'trunks' => 'value',
    'fake-trunks' => 'value', 'concurrency-semantics' => 'value',
    'timezone' => 'value', 'dry-run' => 'flag',
    'fixture-accountcode' => 'flag', 'keep' => 'flag', 'help' => 'flag',
];
$cdrgenOptions = $argc === 1 ? runWizard() : parseCommandLine(array_slice($argv, 1), $cdrgenOptionSpec);
if (isset($cdrgenOptions['help'])) {
    usage(0);
}

$cdrgenProfileName = (string) ($cdrgenOptions['profile'] ?? 'light');
try {
    $cdrgenProfile = TrafficProfile::named($cdrgenProfileName);
} catch (Throwable $error) {
    fail('--profile must be light, medium, or heavy');
}
$cdrgenRows = isset($cdrgenOptions['rows'])
    ? parsePositiveInt($cdrgenOptions['rows'], '--rows')
    : $cdrgenProfile->rows();
$cdrgenSeed = isset($cdrgenOptions['seed'])
    ? parseInt($cdrgenOptions['seed'], '--seed')
    : random_int(1, 0x7fffffff);
$cdrgenTimezoneName = (string) ($cdrgenOptions['timezone'] ?? date_default_timezone_get());
try {
    $cdrgenTimezone = new DateTimeZone($cdrgenTimezoneName);
} catch (Throwable $error) {
    fail('--timezone is not a valid timezone identifier');
}
$cdrgenEnd = isset($cdrgenOptions['end'])
    ? parseDateTime((string) $cdrgenOptions['end'], $cdrgenTimezone, '--end')
    : time();
$cdrgenStart = isset($cdrgenOptions['start'])
    ? parseDateTime((string) $cdrgenOptions['start'], $cdrgenTimezone, '--start')
    : $cdrgenEnd - $cdrgenProfile->days() * 86400;
if ($cdrgenStart >= $cdrgenEnd) {
    fail('--start must be earlier than --end');
}
$cdrgenSemantics = (string) ($cdrgenOptions['concurrency-semantics'] ?? ConcurrencySemantics::ANSWERED_MEDIA);
try {
    ConcurrencySemantics::validate($cdrgenSemantics);
} catch (Throwable $error) {
    fail($error->getMessage());
}

$cdrgenScenarioRandom = new SeededRandomSource($cdrgenSeed);
$cdrgenProfilingRandom = $cdrgenScenarioRandom->fork('trunk-profiling-v1');
$cdrgenProfiler = new TrunkProfiler();
$cdrgenCdrPdo = null;
$cdrgenMetadata = [];
$cdrgenRepository = null;
$cdrgenLiveGuard = null;
$cdrgenProcessLock = null;

if (isset($cdrgenOptions['dry-run'])) {
    $cdrgenExplicitTrunks = (string) ($cdrgenOptions['trunks'] ?? 'PJSIP/Primary-In,PJSIP/Primary-Out,SIP/Failover-Test');
    $cdrgenTrunks = profilesFromList($cdrgenExplicitTrunks, $cdrgenProfiler, $cdrgenProfilingRandom);
    if (isset($cdrgenOptions['fake-trunks'])) {
        $cdrgenTrunks = array_merge(
            $cdrgenTrunks,
            fakeTrunkProfiles(parsePositiveInt($cdrgenOptions['fake-trunks'], '--fake-trunks'), $cdrgenProfiler, $cdrgenProfilingRandom)
        );
    }
} else {
    echo "Loading FreePBX configuration. Please wait...\n";
    $bootstrap_settings['freepbx_auth'] = false;
    require_once '/etc/freepbx.conf';
    $cdrgenCdrPdo = connectCdrPdo($amp_conf ?? []);
    try {
        $cdrgenConfigPdo = connectConfigPdo($amp_conf ?? []);
    } catch (Throwable $error) {
        $cdrgenConfigPdo = $cdrgenCdrPdo;
    }
    echo "Discovering configured trunks. Please wait...\n";
    $cdrgenTrunks = resolveTrunks($cdrgenConfigPdo, $cdrgenOptions, $cdrgenProfiler, $cdrgenProfilingRandom);
    echo "Checking CDR schema and recovery state. Please wait...\n";
    $cdrgenMetadata = loadCdrColumns($cdrgenCdrPdo);
    $cdrgenStateDirectory = '/var/lib/cdrgen';
    $cdrgenProcessLock = ProcessLock::acquire($cdrgenStateDirectory . '/live-run.lock');
    $cdrgenRepository = new CdrRepository($cdrgenCdrPdo, $cdrgenMetadata);
    $cdrgenRepository->assertSchemaSafe();
    $cdrgenLiveGuard = new LiveRunGuard(
        $cdrgenRepository,
        new ActiveRunStore($cdrgenStateDirectory . '/active-run.json')
    );
    $cdrgenRecovered = $cdrgenLiveGuard->recoverAndRequireClean();
    foreach ($cdrgenRecovered as $cdrgenRecoveredAccountcode => $cdrgenRecoverySource) {
        echo "Recovered and verified stale CDRgen run {$cdrgenRecoveredAccountcode} ({$cdrgenRecoverySource}).\n";
    }
    $cdrgenRepository->assertTransactionalStorage();
}

if ($cdrgenTrunks === []) {
    fail('At least one trunk is required');
}

$cdrgenExtensions = ['2001', '2002', '2003', '2004', '2005', '2010', '2011', '2020', '2100', '2200'];
$cdrgenExtensionNames = [
    '2001' => 'Alice Nguyen', '2002' => 'Ben Carter', '2003' => 'Carla Singh',
    '2004' => 'Diego Martinez', '2005' => 'Evelyn Brooks', '2010' => 'Front Desk',
    '2011' => 'Support Desk', '2020' => 'Sales Queue', '2100' => 'Warehouse',
    '2200' => 'Billing',
];
$cdrgenRequestOptions = [
    'rows' => $cdrgenRows,
    'extension_names' => $cdrgenExtensionNames,
    'accountcode' => 'CCTESTPENDING',
    'timezone' => $cdrgenTimezoneName,
];
$cdrgenIdentityRequest = new GenerationRequest(
    $cdrgenProfile,
    $cdrgenStart,
    $cdrgenEnd,
    $cdrgenScenarioRandom,
    $cdrgenExtensions,
    $cdrgenTrunks,
    $cdrgenRequestOptions
);
$cdrgenAccountcode = isset($cdrgenOptions['fixture-accountcode'])
    ? RunIdentity::deterministicAccountcode($cdrgenIdentityRequest->datasetIdentity())
    : RunIdentity::randomAccountcode();
$cdrgenRequestOptions['accountcode'] = $cdrgenAccountcode;
$cdrgenRequest = new GenerationRequest(
    $cdrgenProfile,
    $cdrgenStart,
    $cdrgenEnd,
    $cdrgenScenarioRandom,
    $cdrgenExtensions,
    $cdrgenTrunks,
    $cdrgenRequestOptions
);

if ($cdrgenLiveGuard !== null) {
    SignalCleanup::assertSupported();
    $cdrgenKeep = isset($cdrgenOptions['keep']);
    $cdrgenLiveGuard->prepare($cdrgenAccountcode, $cdrgenKeep);
    SignalCleanup::register($cdrgenLiveGuard, $cdrgenAccountcode);
}

echo "cdrgen\n======\n";
echo 'Version: ' . Version::VERSION . " (testing only)\n";
echo "Profile: {$cdrgenProfileName}\nRows: {$cdrgenRows}\nSeed: {$cdrgenSeed}\nTimezone: {$cdrgenTimezoneName}\n";
echo 'Range: ' . formatTimestamp($cdrgenStart, $cdrgenTimezone) . ' to ' . formatTimestamp($cdrgenEnd, $cdrgenTimezone) . "\n";
echo "Accountcode: {$cdrgenAccountcode}\n\n";

$cdrgenStarted = microtime(true);
$cdrgenGenerationProgress = cliProgress('Generating CDRs', $cdrgenRows);
$cdrgenResult = (new Generator())->generate($cdrgenRequest, $cdrgenGenerationProgress, 500);
$cdrgenElapsed = microtime(true) - $cdrgenStarted;
echo 'Dataset identity: ' . $cdrgenResult->datasetIdentity() . "\n\n";

if ($cdrgenCdrPdo !== null) {
    $cdrgenLiveGuard->armCleanup();
    $cdrgenWriteProgress = cliProgress('Writing CDRs', $cdrgenRows);
    $cdrgenInserted = $cdrgenRepository->insertAll($cdrgenResult->rows(), $cdrgenWriteProgress, 500);
    if ($cdrgenInserted !== count($cdrgenResult->rows())) {
        throw new RuntimeException("Committed row count {$cdrgenInserted} does not match generated count " . count($cdrgenResult->rows()));
    }
    echo "Inserted {$cdrgenInserted} rows into asteriskcdrdb.cdr\n\n";
} else {
    echo 'Generated in memory in ' . number_format($cdrgenElapsed, 3) . "s (no database writes)\n\n";
}
echo "Calculating/reporting concurrency...\n";
printStatistics($cdrgenResult->statistics());
echo "Please wait...\n\n";
$cdrgenConfiguredTrunkChannels = array_map(static function (array $trunk): string {
    return (string) ($trunk['channel'] ?? '');
}, $cdrgenTrunks);
printExpected((new ExpectedConcurrencyCalculator($cdrgenSemantics))->calculate(
    $cdrgenResult->rows(),
    $cdrgenConfiguredTrunkChannels
));

if ($cdrgenLiveGuard !== null) {
    if ($cdrgenLiveGuard->isRetained()) {
        echo "\nRows deliberately retained because --keep was supplied.\n";
        echo "Recovery record: {$cdrgenStateDirectory}/active-run.json\n";
        echo "The next live CDRgen start will recover this exact run before proceeding.\n";
    } else {
        echo "\nCleaning up temporary CDRs...\n";
        $cdrgenDeleted = $cdrgenLiveGuard->cleanupTemporary();
        echo "\nCleanup verified: deleted {$cdrgenDeleted} rows for {$cdrgenAccountcode}; zero CCTEST rows remain.\n";
    }
}

function cliProgress(string $label, int $total): callable
{
    $interactive = function_exists('stream_isatty') && stream_isatty(STDOUT);
    $nextLog = max(1, (int) ceil($total / 10));
    $lastLogged = 0;
    $render = static function (int $completed) use ($label, $total): string {
        $percent = $total > 0 ? (int) floor($completed * 100 / $total) : 100;
        return "{$label}: {$completed} / {$total} [{$percent}%]";
    };

    if ($interactive) {
        echo "\r" . $render(0);
        flush();
    } else {
        echo $render(0) . "\n";
    }

    return static function (int $completed, int $reportedTotal) use (
        $interactive,
        $total,
        $nextLog,
        &$lastLogged,
        $render
    ): void {
        if ($reportedTotal !== $total) {
            throw new RuntimeException('Progress total changed during operation');
        }
        if ($interactive) {
            echo "\r" . $render($completed);
            if ($completed === $total) {
                echo "\n";
            }
            flush();
            return;
        }
        if ($completed === $total || $completed - $lastLogged >= $nextLog) {
            echo $render($completed) . "\n";
            $lastLogged = $completed;
            flush();
        }
    };
}

function parseCommandLine(array $arguments, array $optionSpec): array
{
    $options = [];
    foreach ($arguments as $argument) {
        if (strpos($argument, '--') !== 0 || $argument === '--') {
            fail("Unknown command-line argument: {$argument}");
        }
        $option = substr($argument, 2);
        $separator = strpos($option, '=');
        $name = $separator === false ? $option : substr($option, 0, $separator);
        if (!isset($optionSpec[$name])) {
            fail("Unknown option: --{$name}");
        }
        if ($optionSpec[$name] === 'flag') {
            if ($separator !== false) {
                fail("Option --{$name} does not accept a value");
            }
            $options[$name] = false;
            continue;
        }
        if ($separator === false || substr($option, $separator + 1) === '') {
            fail("Option --{$name} requires a value in --{$name}=VALUE form");
        }
        $options[$name] = substr($option, $separator + 1);
    }
    return $options;
}

function usage(int $exitCode): void
{
    echo 'cdrgen ' . Version::VERSION . "\n";
    echo "Usage: cdrgen --profile=light|medium|heavy [--seed=N] [--rows=N] [--start=DATE] [--end=DATE]\n";
    echo "       [--trunks=LIST] [--fake-trunks=N] [--timezone=ZONE]\n";
    echo "       [--concurrency-semantics=answered|cdr] [--fixture-accountcode] [--keep] [--dry-run]\n";
    exit($exitCode);
}

function runWizard(): array
{
    echo "cdrgen interactive wizard\n=========================\n";
    echo 'Version: ' . Version::VERSION . " (testing only)\n\n";
    $options = [];
    $profileName = ask('Profile [light/medium/heavy]', 'light', static function (string $input) {
        return matchProfile($input) ?? false;
    });
    $options['profile'] = $profileName;
    $seedInput = ask('Seed (number, blank for random)', null, static function (string $input) {
        return $input === '' || preg_match('/^-?\d+$/', $input) === 1;
    });
    $seedWasRandom = $seedInput === '';
    $options['seed'] = (string) ($seedWasRandom ? random_int(1, 0x7fffffff) : (int) $seedInput);

    echo "\nDate range:\n  1) Use profile default\n  2) Custom range\n";
    $dateChoice = ask('Choose [1-2]', '1', static function (string $input) {
        return in_array($input, ['1', '2'], true);
    });
    $profile = TrafficProfile::named($profileName);
    if ($dateChoice === '1') {
        $options = array_merge(
            $options,
            WizardDateRange::profileDefault($profile, time(), date_default_timezone_get())
        );
    } else {
        while (true) {
            $start = ask('Start date (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS)', null, static function (string $input) {
                return normalizeWizardDate($input, false) !== null;
            });
            $end = ask('End date (blank for now)', null, static function (string $input) {
                return $input === '' || normalizeWizardDate($input, true) !== null;
            });
            $normalizedStart = normalizeWizardDate($start, false);
            $normalizedEnd = $end === '' ? date('Y-m-d H:i:s') : normalizeWizardDate($end, true);
            if ($normalizedStart !== null && $normalizedEnd !== null && strtotime($normalizedEnd) > strtotime($normalizedStart)) {
                $options['start'] = $normalizedStart;
                $options['end'] = $normalizedEnd;
                break;
            }
            echo "End date must be after start date.\n";
        }
    }

    echo "\nTrunks:\n  1) Use configured FreePBX trunks (recommended)\n";
    echo "  2) Specify trunks explicitly\n  3) Use CDR-only fake trunks\n";
    $trunkChoice = ask('Choose [1-3]', '1', static function (string $input) {
        return in_array($input, ['1', '2', '3'], true);
    });
    $trunkSummary = 'configured FreePBX trunks';
    if ($trunkChoice === '2') {
        $options['trunks'] = ask('Enter trunks (comma-separated)', null, static function (string $input) {
            return trim($input) !== '';
        });
        $trunkSummary = $options['trunks'];
    } elseif ($trunkChoice === '3') {
        $options['fake-trunks'] = ask('How many fake trunks?', '4', static function (string $input) {
            return ctype_digit($input) && (int) $input > 0;
        });
        $trunkSummary = $options['fake-trunks'] . ' CDR-only fake trunks';
    }

    $summaryEnd = $options['end'];
    $summaryStart = $options['start'];
    echo "\nAbout to generate:\n";
    echo "  Profile: {$profileName}\n  Rows: {$profile->rows()} (profile default)\n";
    echo "  Date: {$summaryStart} to {$summaryEnd}" . ($dateChoice === '1' ? ' (profile default)' : '') . "\n";
    echo '  Seed: ' . $options['seed'] . ($seedWasRandom ? ' (random)' : '') . "\n";
    echo "  Trunks: {$trunkSummary}\n\n";
    while (true) {
        echo "Proceed? [Y/n]:\n> ";
        $answer = strtolower(trim(readStdinOrExit()));
        if ($answer === '' || $answer === 'y' || $answer === 'yes') {
            echo "Preparing confirmed run. Please wait...\n";
            return $options;
        }
        if ($answer === 'n' || $answer === 'no') { echo "Cancelled.\n"; exit(1); }
        echo "Please answer y or n.\n";
    }
}

function ask(string $prompt, ?string $default = null, ?callable $validator = null): string
{
    while (true) {
        echo $prompt . ($default !== null ? " (default: {$default})" : '') . ":\n> ";
        $input = trim(readStdinOrExit());
        if ($input === '' && $default !== null) return $default;
        if ($validator === null) return $input;
        $result = $validator($input);
        if ($result !== false) return is_string($result) ? $result : $input;
        echo "Invalid input. Please try again.\n";
    }
}

function readStdinOrExit(): string
{
    $line = fgets(STDIN);
    if ($line === false) { echo "\nCancelled.\n"; exit(1); }
    return $line;
}

function matchProfile(string $input): ?string
{
    $input = strtolower(trim($input));
    if ($input === '') return null;
    foreach (['light', 'medium', 'heavy'] as $profile) {
        if (strpos($profile, $input) === 0) return $profile;
    }
    return null;
}

function normalizeWizardDate(string $input, bool $isEnd): ?string
{
    $input = trim($input);
    if ($input === '') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $input) === 1) {
        $input .= $isEnd ? ' 23:59:59' : ' 00:00:00';
    }
    $timestamp = strtotime($input);
    if ($timestamp === false || $timestamp > time()) return null;
    return date('Y-m-d H:i:s', $timestamp);
}

function fail(string $message): void { fwrite(STDERR, $message . "\n"); exit(1); }

function parseInt($value, string $name): int
{
    if (filter_var($value, FILTER_VALIDATE_INT) === false) fail("{$name} must be an integer");
    return (int) $value;
}

function parsePositiveInt($value, string $name): int
{
    $integer = parseInt($value, $name);
    if ($integer < 1) fail("{$name} must be greater than zero");
    return $integer;
}

function parseDateTime(string $value, DateTimeZone $timezone, string $name): int
{
    try {
        return (new DateTimeImmutable($value, $timezone))->getTimestamp();
    } catch (Throwable $error) {
        fail("{$name} could not be parsed as a date/time");
    }
}

function formatTimestamp(int $timestamp, DateTimeZone $timezone): string
{
    return (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone)->format('Y-m-d H:i:s');
}

function connectCdrPdo(array $configuration): PDO
{
    if (class_exists('FreePBX')) {
        try {
            $cdr = null;
            if (method_exists('FreePBX', 'create')) $cdr = \FreePBX::create()->Cdr();
            if ($cdr === null) $cdr = \FreePBX::Cdr();
            if (method_exists($cdr, 'getCdrDbHandle')) {
                $handle = $cdr->getCdrDbHandle();
                if ($handle instanceof PDO) return configurePdo($handle);
            }
        } catch (Throwable $error) {
            // Fall through to FreePBX configuration values.
        }
    }
    return createPdo(ConnectionSettings::cdr($configuration));
}

function connectConfigPdo(array $configuration): PDO
{
    return createPdo(ConnectionSettings::config($configuration));
}

function createPdo(array $settings): PDO
{
    return configurePdo(new PDO(
        ConnectionSettings::dsn($settings),
        $settings['user'],
        $settings['password']
    ));
}

function configurePdo(PDO $pdo): PDO
{
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    return $pdo;
}

function resolveTrunks(PDO $pdo, array $options, TrunkProfiler $profiler, $random): array
{
    if (isset($options['trunks'])) return profilesFromList((string) $options['trunks'], $profiler, $random);
    $profiles = loadConfiguredTrunks($pdo, $profiler, $random);
    if ($profiles === [] && !isset($options['fake-trunks'])) $profiles = promptForConfiguredTrunks($pdo, $profiler, $random);
    if (isset($options['fake-trunks'])) {
        $profiles = array_merge($profiles, fakeTrunkProfiles(parsePositiveInt($options['fake-trunks'], '--fake-trunks'), $profiler, $random));
    }
    return $profiles;
}

function promptForConfiguredTrunks(PDO $pdo, TrunkProfiler $profiler, $random): array
{
    while (true) {
        echo "\nNo configured FreePBX trunks were detected.\n";
        echo "Create harmless enabled test trunks, then press ENTER to retry.\n";
        echo "Type FAKE to use CDR-only fake trunks, or QUIT to exit: ";
        $line = fgets(STDIN);
        if ($line === false) fail('STDIN closed and no trunks were detected; no rows generated');
        $answer = strtoupper(trim($line));
        if ($answer === 'QUIT' || $answer === 'EXIT') { echo "No rows generated.\n"; exit(1); }
        if ($answer === 'FAKE') return fakeTrunkProfiles(3, $profiler, $random);
        $profiles = loadConfiguredTrunks($pdo, $profiler, $random);
        if ($profiles !== []) return $profiles;
        echo "Still no configured trunks detected.\n";
    }
}

function loadConfiguredTrunks(PDO $pdo, TrunkProfiler $profiler, $random): array
{
    try {
        $columns = [];
        foreach ($pdo->query('SHOW COLUMNS FROM trunks')->fetchAll() as $column) $columns[$column['Field']] = true;
        if (!isset($columns['channelid'])) return [];
        $fields = array_values(array_intersect(['trunkid', 'tech', 'channelid', 'name', 'disabled'], array_keys($columns)));
        $sql = 'SELECT `' . implode('`, `', $fields) . '` FROM trunks';
        if (isset($columns['disabled'])) $sql .= " WHERE disabled IN ('off', 'false', '0', '') OR disabled IS NULL";
        if (isset($columns['trunkid'])) $sql .= ' ORDER BY trunkid';
        $profiles = [];
        foreach ($pdo->query($sql)->fetchAll() as $index => $row) {
            $channelId = trim((string) $row['channelid']);
            if ($channelId === '') continue;
            $technology = strtoupper(trim((string) ($row['tech'] ?? 'PJSIP')));
            if (!in_array($technology, ['PJSIP', 'SIP', 'IAX2'], true)) $technology = 'PJSIP';
            $channel = strpos($channelId, '/') === false ? "{$technology}/{$channelId}" : $channelId;
            [$dids, $prefixes] = numberPools($index);
            $profiles[] = $profiler->profile($channel, $dids, $prefixes, $index, ($row['name'] ?? '') . ' ' . $channel, $random);
        }
        return $profiles;
    } catch (Throwable $error) {
        return [];
    }
}

function profilesFromList(string $list, TrunkProfiler $profiler, $random): array
{
    $profiles = [];
    foreach (explode(',', $list) as $index => $value) {
        $value = trim($value);
        if ($value === '') continue;
        [$dids, $prefixes] = numberPools($index);
        $profiles[] = $profiler->profile($value, $dids, $prefixes, $index, $value, $random);
    }
    return $profiles;
}

function fakeTrunkProfiles(int $count, TrunkProfiler $profiler, $random): array
{
    $names = ['peerless-west', 'bulkvs-ld', 'inteliquent-overflow', 'telnyx-backup', 'questblue-lcr', 'thinQ-failover'];
    $profiles = [];
    for ($index = 0; $index < $count; $index++) {
        [$dids, $prefixes] = numberPools($index + 10);
        $name = $names[$index % count($names)] . '-' . ($index + 1);
        $technology = $index % 3 === 0 ? 'SIP' : 'PJSIP';
        $profiles[] = $profiler->profile("{$technology}/{$name}", $dids, $prefixes, $index + 10, $name, $random);
    }
    return $profiles;
}

function numberPools(int $index): array
{
    $areas = ['212', '646', '718', '800', '888', '415', '617', '202', '303', '310'];
    $prefixSets = [['1212', '1646', '1718'], ['1415', '1617', '1888'], ['1202', '1303', '1310'], ['1800', '1888', '1877']];
    $area = $areas[$index % count($areas)];
    $base = 100 + $index * 7;
    return [[
        $area . '555' . sprintf('%04d', $base % 10000),
        $area . '555' . sprintf('%04d', ($base + 1) % 10000),
    ], $prefixSets[$index % count($prefixSets)]];
}

function loadCdrColumns(PDO $pdo): array
{
    $metadata = $pdo->query('SHOW COLUMNS FROM cdr')->fetchAll();
    foreach ($metadata as $column) {
        if ($column['Field'] === 'accountcode') return $metadata;
    }
    throw new RuntimeException('cdr table does not contain accountcode column');
}

function printStatistics(array $statistics): void
{
    echo "Generated traffic mix\n---------------------\n";
    foreach ($statistics as $kind => $values) {
        echo ucfirst(str_replace('_', ' ', $kind)) . ":\n";
        foreach ($values as $name => $count) echo "  {$name}: {$count}\n";
    }
    echo "\n";
}

function printExpected(array $expected): void
{
    echo 'Expected ANSWERED concurrency (' . $expected['semantics'] . ")\n";
    echo "----------------------------------------\nGlobal peak: {$expected['global']}\n";
    foreach (['extensions_handled', 'extensions_channel', 'trunks'] as $kind) {
        echo "\n" . ucfirst(str_replace('_', ' ', $kind)) . ":\n";
        foreach ($expected[$kind] as $name => $peak) echo "  {$name}: {$peak}\n";
    }
}
