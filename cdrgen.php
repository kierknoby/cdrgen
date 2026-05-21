<?php

/**
 * CDRgen
 *
 * Synthetic realistic CDR generator for FreePBX/Asterisk systems.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$bootstrap_settings['freepbx_auth'] = false;
require_once '/etc/freepbx.conf';

const ACCOUNT_PREFIX = 'CCTEST';

$profiles = [
    'light'  => ['rows' => 250,   'days' => 1,  'min' => 15, 'max' => 720],
    'medium' => ['rows' => 2500,  'days' => 7,  'min' => 15, 'max' => 1200],
    'heavy'  => ['rows' => 15000, 'days' => 30, 'min' => 15, 'max' => 2400],
];

$opts = getopt('', [
    'profile::',
    'seed::',
    'rows::',
    'start::',
    'end::',
    'trunks::',
    'fake-trunks::',
    'help',
]);

if (isset($opts['help'])) {
    usage(0);
}

$profile = (string)($opts['profile'] ?? 'light');
if (!isset($profiles[$profile])) {
    fwrite(STDERR, "--profile must be light, medium, or heavy\n");
    usage(1);
}

$cfg = $profiles[$profile];
$rows = isset($opts['rows']) ? parsePositiveInt($opts['rows'], '--rows') : $cfg['rows'];
$seed = isset($opts['seed']) ? parseInt($opts['seed'], '--seed') : random_int(1, 0x7fffffff);
$end = isset($opts['end']) ? parseDateTime($opts['end'], '--end') : time();
$start = isset($opts['start']) ? parseDateTime($opts['start'], '--start') : ($end - ($cfg['days'] * 86400));

if ($start >= $end) {
    fwrite(STDERR, "--start must be earlier than --end\n");
    exit(1);
}

mt_srand($seed);

$accountcode = ACCOUNT_PREFIX . sprintf('%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff));
$pdo = connectCdrPdo($amp_conf ?? []);
try {
    $configPdo = connectConfigPdo($amp_conf ?? []);
} catch (Throwable $e) {
    $configPdo = $pdo;
}
$columns = loadCdrColumns($pdo);
$insert = buildInsertStatement($pdo, $columns);

$extensions = ['2001', '2002', '2003', '2004', '2005', '2010', '2011', '2020', '2100', '2200'];
$extensionNames = [
    '2001' => 'Alice Nguyen',
    '2002' => 'Ben Carter',
    '2003' => 'Carla Singh',
    '2004' => 'Diego Martinez',
    '2005' => 'Evelyn Brooks',
    '2010' => 'Front Desk',
    '2011' => 'Support Desk',
    '2020' => 'Sales Queue',
    '2100' => 'Warehouse',
    '2200' => 'Billing',
];
$trunkProfiles = buildTrunkProfiles(
    $configPdo,
    isset($opts['trunks']) ? (string)$opts['trunks'] : '',
    isset($opts['fake-trunks']) ? parsePositiveInt($opts['fake-trunks'], '--fake-trunks') : 0
);
$externalNumbers = [
    '12125550111',
    '12125550112',
    '16465550130',
    '17185550140',
    '13105550150',
    '18005550160',
    '14155550170',
    '16175550180',
    '12025550190',
    '13035550200',
];
$inboundTargets = [
    ['type' => 'extension', 'value' => '2010', 'weight' => 22],
    ['type' => 'extension', 'value' => '2011', 'weight' => 17],
    ['type' => 'extension', 'value' => '2020', 'weight' => 13],
    ['type' => 'extension', 'value' => '2200', 'weight' => 10],
    ['type' => 'extension', 'value' => '2001', 'weight' => 8],
    ['type' => 'extension', 'value' => '2002', 'weight' => 8],
    ['type' => 'ringgroup', 'value' => '600', 'weight' => 7],
    ['type' => 'queue', 'value' => '800', 'weight' => 9],
    ['type' => 'ivr', 'value' => '700', 'weight' => 6],
];
$outboundPrefixes = ['1212', '1646', '1718', '1310', '1415', '1617', '1202', '1303', '1800', '1888'];
$expected = [
    'global' => [],
    'extensions' => [],
    'trunks' => [],
];
$coverageRows = [
    ['inbound', 'ANSWERED'],
    ['outbound', 'ANSWERED'],
    ['internal', 'ANSWERED'],
    ['inbound', 'NO ANSWER'],
    ['outbound', 'BUSY'],
    ['inbound', 'FAILED'],
    ['outbound', 'NO ANSWER'],
];

echo "CDRgen\n";
echo "======\n";
echo "Profile: {$profile}\n";
echo "Rows: {$rows}\n";
echo "Seed: {$seed}\n";
echo "Range: " . date('Y-m-d H:i:s', $start) . " to " . date('Y-m-d H:i:s', $end) . "\n";
echo "Accountcode: {$accountcode}\n";
echo "\n";

$inserted = 0;
$schedule = buildTrafficSchedule($start, $end, $rows);
$stats = [
    'directions' => [],
    'dispositions' => [],
    'trunks' => [],
];

$pdo->beginTransaction();
try {
    for ($ts = $start; $ts < $end; $ts++) {
        if ($inserted >= $rows) {
            break;
        }

        $toCreate = $schedule[$ts] ?? 0;

        for ($i = 0; $i < $toCreate && $inserted < $rows; $i++) {
            $row = generateCdrRow(
                $ts,
                $cfg,
                $accountcode,
                $extensions,
                $extensionNames,
                $trunkProfiles,
                $externalNumbers,
                $inboundTargets,
                $outboundPrefixes,
                $coverageRows[$inserted][0] ?? null,
                $coverageRows[$inserted][1] ?? null
            );

            insertRow($insert, $columns, $row);
            $inserted++;
            incrementStat($stats['directions'], $row['_direction']);
            incrementStat($stats['dispositions'], $row['disposition']);
            if ($row['_trunk'] !== null) {
                incrementStat($stats['trunks'], $row['_trunk']);
            }

            if ($row['disposition'] === 'ANSWERED') {
                addExpectedCall($expected, $row);
            }
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

echo "Inserted {$inserted} rows into asteriskcdrdb.cdr\n\n";
printStats($stats);
printExpected($expected);

$cleanupOne = "DELETE FROM cdr WHERE accountcode = '{$accountcode}';";
$cleanupAll = "DELETE FROM cdr WHERE accountcode LIKE '" . ACCOUNT_PREFIX . "%';";
echo "\nCleanup SQL\n";
echo "-----------\n";
echo "mysql asteriskcdrdb -e \"" . $cleanupOne . "\"\n";
echo "mysql asteriskcdrdb -e \"" . $cleanupAll . "\"\n";
promptCleanup($pdo, $accountcode);

function usage(int $exitCode): void
{
    $script = basename(__FILE__);
    echo "Usage: php {$script} --profile=light|medium|heavy [--seed=N] [--rows=N] [--start=DATE] [--end=DATE] [--trunks=LIST] [--fake-trunks=N]\n";
    echo "Dates are parsed by PHP strtotime(), for example: 2026-05-01 00:00:00\n";
    echo "Trunks are comma-separated channel names, for example: PJSIP/primary,SIP/backup\n";
    exit($exitCode);
}

function promptCleanup(PDO $pdo, string $accountcode): void
{
    echo "\nType DELETE to remove rows from this run, or KEEP to retain rows and exit.\n";

    while (true) {
        echo "[" . date('Y-m-d H:i:s') . "] DELETE or KEEP: ";
        $read = [STDIN];
        $write = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, 60);

        if ($ready === false) {
            $answer = trim((string)fgets(STDIN));
        } elseif ($ready > 0) {
            $line = fgets(STDIN);
            if ($line === false) {
                echo "\nSTDIN closed; rows retained.\n";
                return;
            }

            $answer = trim($line);
        } else {
            echo "\nStill waiting. Rows remain tagged with accountcode {$accountcode}.\n";
            continue;
        }

        if ($answer === '') {
            echo "Still waiting. Type DELETE to clean up, or KEEP to exit.\n";
            continue;
        }

        if ($answer === 'DELETE') {
            $stmt = $pdo->prepare('DELETE FROM cdr WHERE accountcode = :accountcode');
            $stmt->execute([':accountcode' => $accountcode]);
            echo "Deleted " . $stmt->rowCount() . " rows for {$accountcode}\n";
            return;
        }

        if ($answer === 'KEEP') {
            echo "Rows retained.\n";
            return;
        }

        echo "Unrecognized input. Type DELETE to clean up, or KEEP to exit.\n";
    }
}

function parseInt($value, string $name): int
{
    if (!is_numeric($value) || (string)(int)$value !== (string)$value) {
        fwrite(STDERR, "{$name} must be an integer\n");
        exit(1);
    }

    return (int)$value;
}

function parsePositiveInt($value, string $name): int
{
    $int = parseInt($value, $name);
    if ($int < 1) {
        fwrite(STDERR, "{$name} must be greater than zero\n");
        exit(1);
    }

    return $int;
}

function parseDateTime(string $value, string $name): int
{
    $ts = strtotime($value);
    if ($ts === false) {
        fwrite(STDERR, "{$name} could not be parsed as a date/time\n");
        exit(1);
    }

    return $ts;
}

function connectCdrPdo(array $ampConf): PDO
{
    if (class_exists('FreePBX')) {
        try {
            $cdr = null;

            if (method_exists('FreePBX', 'create')) {
                $freepbx = \FreePBX::create();
                $cdr = $freepbx->Cdr();
            }

            if ($cdr === null) {
                $cdr = \FreePBX::Cdr();
            }

            if (method_exists($cdr, 'getCdrDbHandle')) {
                $handle = $cdr->getCdrDbHandle();
                if ($handle instanceof PDO) {
                    $handle->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $handle->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                    $handle->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

                    return $handle;
                }
            }
        } catch (Throwable $e) {
            // Fall back to the database settings from /etc/freepbx.conf.
        }
    }

    $host = $ampConf['CDRDBHOST'] ?? $ampConf['AMPDBHOST'] ?? 'localhost';
    $port = $ampConf['CDRDBPORT'] ?? $ampConf['AMPDBPORT'] ?? null;
    $name = $ampConf['CDRDBNAME'] ?? 'asteriskcdrdb';
    $user = $ampConf['CDRDBUSER'] ?? $ampConf['AMPDBUSER'] ?? 'asteriskuser';
    $pass = $ampConf['CDRDBPASS'] ?? $ampConf['AMPDBPASS'] ?? '';

    $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
    if ($port !== null && $port !== '') {
        $dsn .= ";port={$port}";
    }

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function connectConfigPdo(array $ampConf): PDO
{
    $host = $ampConf['AMPDBHOST'] ?? 'localhost';
    $port = $ampConf['AMPDBPORT'] ?? null;
    $name = $ampConf['AMPDBNAME'] ?? 'asterisk';
    $user = $ampConf['AMPDBUSER'] ?? 'asteriskuser';
    $pass = $ampConf['AMPDBPASS'] ?? '';

    $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
    if ($port !== null && $port !== '') {
        $dsn .= ";port={$port}";
    }

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function loadCdrColumns(PDO $pdo): array
{
    $stmt = $pdo->prepare('SHOW COLUMNS FROM cdr');
    $stmt->execute();
    $columns = [];

    foreach ($stmt->fetchAll() as $column) {
        $columns[$column['Field']] = $column;
    }

    if (!isset($columns['accountcode'])) {
        throw new RuntimeException('cdr table does not contain accountcode column');
    }

    return $columns;
}

function buildInsertStatement(PDO $pdo, array $columns): PDOStatement
{
    $insertColumns = [];

    foreach ($columns as $column => $meta) {
        if (isAutoIncrementColumn($meta)) {
            continue;
        }

        $insertColumns[] = $column;
    }

    $quotedColumns = [];
    $placeholders = [];
    foreach ($insertColumns as $column) {
        $quotedColumns[] = '`' . str_replace('`', '``', $column) . '`';
        $placeholders[] = ":{$column}";
    }
    $sql = 'INSERT INTO cdr (' . implode(', ', $quotedColumns) . ') VALUES (' . implode(', ', $placeholders) . ')';

    return $pdo->prepare($sql);
}

function insertRow(PDOStatement $stmt, array $columns, array $row): void
{
    $params = [];

    foreach ($columns as $column => $meta) {
        if (isAutoIncrementColumn($meta)) {
            continue;
        }

        $params[":{$column}"] = $row[$column] ?? defaultColumnValue($column, $meta);
    }

    $stmt->execute($params);
}

function isAutoIncrementColumn(array $meta): bool
{
    return isset($meta['Extra']) && stripos($meta['Extra'], 'auto_increment') !== false;
}

function defaultColumnValue(string $column, array $meta)
{
    if ($meta['Null'] === 'YES') {
        return null;
    }

    if ($meta['Default'] !== null) {
        return $meta['Default'];
    }

    if (stripos($meta['Type'], 'int') !== false || stripos($meta['Type'], 'decimal') !== false || stripos($meta['Type'], 'float') !== false) {
        return 0;
    }

    if (stripos($meta['Type'], 'datetime') !== false || stripos($meta['Type'], 'timestamp') !== false) {
        return date('Y-m-d H:i:s');
    }

    return '';
}

function buildTrunkProfiles(PDO $pdo, string $customTrunks, int $fakeTrunks): array
{
    if ($customTrunks !== '') {
        $profiles = [];
        $parts = explode(',', $customTrunks);
        foreach ($parts as $index => $part) {
            $channel = normalizeTrunkChannel(trim($part));
            if ($channel === '') {
                continue;
            }

            $profiles[] = inferredTrunkProfile(
                $channel,
                didPoolFor($index),
                prefixPoolFor($index),
                $index,
                $channel
            );
        }

        if ($profiles !== []) {
            return $profiles;
        }
    }

    $profiles = loadConfiguredTrunkProfiles($pdo);

    if ($profiles === [] && $fakeTrunks < 1) {
        $profiles = promptForConfiguredTrunks($pdo);
    }

    $names = ['peerless-west', 'bulkvs-ld', 'inteliquent-overflow', 'telnyx-backup', 'questblue-lcr', 'thinQ-failover'];
    for ($i = 0; $i < $fakeTrunks; $i++) {
        $tech = $i % 3 === 0 ? 'SIP' : 'PJSIP';
        $name = $names[$i % count($names)] . '-' . ($i + 1);
        $profiles[] = inferredTrunkProfile(
            "{$tech}/{$name}",
            didPoolFor($i + 10),
            prefixPoolFor($i + 10),
            $i + 10,
            $name
        );
    }

    return $profiles;
}

function promptForConfiguredTrunks(PDO $pdo): array
{
    while (true) {
        echo "\nNo configured FreePBX trunks were detected.\n";
        echo "Create harmless enabled test trunks in FreePBX before generating CDR data.\n";
        echo "Suggested names: Primary-In, Primary-Out, Failover-Test.\n";
        echo "A dummy SIP server such as test.test.com is fine; the trunks do not need to register.\n";
        echo "Disabled trunks may be hidden from reports and skipped by automatic discovery.\n";
        echo "\nPress ENTER after creating trunks to retry detection, type FAKE to use CDR-only fake trunks, or QUIT to exit: ";

        $line = fgets(STDIN);
        if ($line === false) {
            fwrite(STDERR, "\nSTDIN closed and no trunks were detected; exiting before generating rows.\n");
            exit(1);
        }

        $answer = strtoupper(trim($line));
        if ($answer === 'QUIT' || $answer === 'EXIT') {
            echo "No rows generated.\n";
            exit(1);
        }

        if ($answer === 'FAKE') {
            echo "Using explicit CDR-only fake trunks. These may not appear in reports that require configured FreePBX trunks.\n";
            return [
                inferredTrunkProfile('PJSIP/flowroute-main', ['2125550100', '2125550101'], ['1212', '1646', '1718', '1800'], 0, 'main primary'),
                inferredTrunkProfile('PJSIP/twilio-elastic', ['6465550110', '6465550111'], ['1646', '1415', '1617', '1888'], 1, 'elastic'),
                inferredTrunkProfile('SIP/backup-carrier', ['2125550190'], ['1212', '1646', '1718'], 2, 'backup failover'),
            ];
        }

        $profiles = loadConfiguredTrunkProfiles($pdo);
        if ($profiles !== []) {
            return $profiles;
        }

        echo "Still no configured trunks detected.\n";
    }
}

function loadConfiguredTrunkProfiles(PDO $pdo): array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $stmt->execute([':table' => 'trunks']);
        if ($stmt->fetchColumn() === false) {
            return [];
        }

        $columns = [];
        $stmt = $pdo->prepare('SHOW COLUMNS FROM trunks');
        $stmt->execute();
        foreach ($stmt->fetchAll() as $column) {
            $columns[$column['Field']] = true;
        }

        $select = [];
        foreach (['trunkid', 'tech', 'channelid', 'name', 'disabled'] as $field) {
            if (isset($columns[$field])) {
                $select[] = "`{$field}`";
            }
        }

        if ($select === [] || !isset($columns['channelid'])) {
            return [];
        }

        $sql = 'SELECT ' . implode(', ', $select) . ' FROM trunks';
        if (isset($columns['disabled'])) {
            $sql .= " WHERE disabled IN ('off', 'false', '0', '') OR disabled IS NULL";
        }
        if (isset($columns['trunkid'])) {
            $sql .= ' ORDER BY trunkid';
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $profiles = [];
        $index = 0;

        foreach ($stmt->fetchAll() as $row) {
            $channel = configuredTrunkChannel($row);
            if ($channel === '') {
                continue;
            }

            $profiles[] = inferredTrunkProfile(
                $channel,
                didPoolFor($index),
                prefixPoolFor($index),
                $index,
                trim((string)($row['name'] ?? '')) . ' ' . $channel
            );
            $index++;
        }

        return $profiles;
    } catch (Throwable $e) {
        return [];
    }
}

function configuredTrunkChannel(array $row): string
{
    $channelid = trim((string)($row['channelid'] ?? ''));
    if ($channelid === '') {
        return '';
    }

    if (strpos($channelid, '/') !== false) {
        return normalizeTrunkChannel($channelid);
    }

    $tech = strtolower(trim((string)($row['tech'] ?? '')));
    if ($tech === 'pjsip') {
        return "PJSIP/{$channelid}";
    }

    if ($tech === 'sip') {
        return "SIP/{$channelid}";
    }

    if ($tech === 'iax2') {
        return "IAX2/{$channelid}";
    }

    return "PJSIP/{$channelid}";
}

function inferredTrunkProfile(string $channel, array $dids, array $prefixes, int $index, string $label): array
{
    $traits = inferTrunkTraits($label . ' ' . $channel);
    $weight = max(2, 80 - ($index * 10));
    $inboundWeight = mt_rand(38, 66);
    $outboundWeight = mt_rand(38, 66);
    $degraded = $index >= 3;

    if ($traits['primary']) {
        $weight += 28;
        $inboundWeight += 12;
        $outboundWeight += 12;
        $degraded = false;
    }

    if ($traits['secondary']) {
        $weight = max(10, (int)floor($weight * 0.65));
        $inboundWeight = max(12, $inboundWeight - 14);
        $outboundWeight = max(12, $outboundWeight - 14);
    }

    if ($traits['backup']) {
        $weight = max(3, (int)floor($weight * 0.25));
        $inboundWeight = max(8, (int)floor($inboundWeight * 0.45));
        $outboundWeight = max(12, (int)floor($outboundWeight * 0.60));
        $degraded = true;
    }

    if ($traits['inbound']) {
        $inboundWeight += 45;
        $outboundWeight = max(6, (int)floor($outboundWeight * 0.35));
    }

    if ($traits['outbound']) {
        $outboundWeight += 45;
        $inboundWeight = max(6, (int)floor($inboundWeight * 0.35));
    }

    if ($traits['tollfree']) {
        $inboundWeight += 55;
        $outboundWeight = max(6, (int)floor($outboundWeight * 0.30));
        $prefixes = ['1800', '1888', '1877', '1866'];
    }

    if ($traits['international']) {
        $outboundWeight += 32;
        $prefixes = ['01144', '01149', '01161', '01133'];
    }

    if ($traits['fax']) {
        $weight = max(2, (int)floor($weight * 0.30));
        $inboundWeight += 18;
        $outboundWeight += 10;
    }

    if ($traits['emergency']) {
        $weight = max(1, (int)floor($weight * 0.10));
        $outboundWeight += 12;
    }

    return trunkProfile(
        $channel,
        $weight,
        $inboundWeight,
        $outboundWeight,
        $dids,
        $prefixes,
        $degraded
    );
}

function inferTrunkTraits(string $value): array
{
    $text = strtolower($value);
    $tokens = preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    $tokens = $tokens === false ? [] : $tokens;
    $compact = implode('', $tokens);
    $scores = [
        'inbound' => traitScore($tokens, $compact, ['in', 'inb', 'inbound', 'incoming', 'ingress', 'did', 'dids', 'ddi', 'recv', 'receive', 'rx', 'orig', 'origination']),
        'outbound' => traitScore($tokens, $compact, ['out', 'outb', 'outbound', 'outgoing', 'egress', 'send', 'tx', 'term', 'terminate', 'termination']),
        'primary' => traitScore($tokens, $compact, ['primary', 'pri', 'main', 'default', 'prod', 'production', 'active', 'preferred', 'prefered', 'first', 'one', 'a']),
        'secondary' => traitScore($tokens, $compact, ['secondary', 'second', 'alt', 'alternate', 'alternative', 'overflow', 'spare', 'standby', 'two', 'b']),
        'backup' => traitScore($tokens, $compact, ['backup', 'back', 'bak', 'bkp', 'failover', 'fail-over', 'failsafe', 'standby', 'redundant', 'redundancy', 'dr', 'disasterrecovery']),
        'tollfree' => traitScore($tokens, $compact, ['tollfree', 'toll-free', 'tf', 'freephone', '800', '888', '877', '866', '855', '844', '833']),
        'international' => traitScore($tokens, $compact, ['intl', 'international', 'global', 'world', 'ld', 'longdistance', 'long-distance', 'overseas']),
        'fax' => traitScore($tokens, $compact, ['fax', 'facsimile', 't38', 't38fax']),
        'emergency' => traitScore($tokens, $compact, ['emergency', 'e911', '911', 'psap']),
    ];

    return [
        'inbound' => $scores['inbound'] >= 2 && $scores['inbound'] >= $scores['outbound'],
        'outbound' => $scores['outbound'] >= 2 && $scores['outbound'] >= $scores['inbound'],
        'primary' => $scores['primary'] >= 2 && $scores['primary'] >= $scores['secondary'] && $scores['primary'] >= $scores['backup'],
        'secondary' => $scores['secondary'] >= 2 && $scores['secondary'] > $scores['primary'],
        'backup' => $scores['backup'] >= 2,
        'tollfree' => $scores['tollfree'] >= 2,
        'international' => $scores['international'] >= 2,
        'fax' => $scores['fax'] >= 2,
        'emergency' => $scores['emergency'] >= 2,
    ];
}

function traitScore(array $tokens, string $compact, array $needles): int
{
    $score = 0;

    foreach ($needles as $needle) {
        $normalizedNeedle = strtolower(preg_replace('/[^a-z0-9]+/', '', $needle));
        if ($normalizedNeedle === '') {
            continue;
        }

        foreach ($tokens as $token) {
            $score += fuzzyTokenScore($token, $normalizedNeedle);
        }

        if (strlen($normalizedNeedle) >= 4 && strpos($compact, $normalizedNeedle) !== false) {
            $score += 2;
        }
    }

    return $score;
}

function fuzzyTokenScore(string $token, string $needle): int
{
    if ($token === $needle) {
        return 4;
    }

    if (strlen($needle) >= 4 && (strpos($token, $needle) !== false || strpos($needle, $token) !== false)) {
        return 2;
    }

    if (strlen($needle) >= 5 && strlen($token) >= 5) {
        $distance = levenshtein($token, $needle);
        if ($distance <= 1) {
            return 3;
        }

        if ($distance <= 2) {
            return 2;
        }
    }

    return 0;
}

function trunkProfile(string $channel, int $weight, int $inboundWeight, int $outboundWeight, array $dids, array $prefixes, bool $degraded): array
{
    return [
        'channel' => $channel,
        'name' => trunkName($channel),
        'weight' => max(1, $weight),
        'inbound_weight' => max(1, $inboundWeight),
        'outbound_weight' => max(1, $outboundWeight),
        'dids' => $dids,
        'prefixes' => $prefixes,
        'failed_boost' => $degraded ? mt_rand(3, 8) : 0,
        'busy_boost' => $degraded ? mt_rand(2, 6) : 0,
        'jitter' => mt_rand(0, 4),
    ];
}

function normalizeTrunkChannel(string $value): string
{
    if ($value === '') {
        return '';
    }

    if (strpos($value, '/') === false) {
        return "PJSIP/{$value}";
    }

    return $value;
}

function didPoolFor(int $index): array
{
    $areaCodes = ['212', '646', '718', '800', '888', '415', '617', '202', '303', '310'];
    $area = $areaCodes[$index % count($areaCodes)];
    $base = 100 + ($index * 7);

    return [
        $area . '555' . sprintf('%04d', $base % 10000),
        $area . '555' . sprintf('%04d', ($base + 1) % 10000),
    ];
}

function prefixPoolFor(int $index): array
{
    $sets = [
        ['1212', '1646', '1718'],
        ['1415', '1617', '1888'],
        ['1202', '1303', '1310'],
        ['1800', '1888', '1877'],
    ];

    return $sets[$index % count($sets)];
}

function pickTrunkProfile(array $profiles, string $direction): array
{
    $weights = [];

    foreach ($profiles as $index => $profile) {
        $weight = (int)$profile['weight'];
        if ($direction === 'inbound') {
            $weight = (int)$profile['inbound_weight'];
        } elseif ($direction === 'outbound') {
            $weight = (int)$profile['outbound_weight'];
        }

        $weights[(string)$index] = max(1, $weight + mt_rand(0, (int)$profile['jitter']));
    }

    return $profiles[(int)weightedChoice($weights)];
}

function trunkDispositionWeights(string $direction, int $ts, array $trunkProfile): array
{
    $weights = dispositionWeights($direction, $ts);

    if ($direction !== 'internal') {
        $weights['FAILED'] += (int)$trunkProfile['failed_boost'];
        $weights['BUSY'] += (int)$trunkProfile['busy_boost'];
        $weights['ANSWERED'] = max(20, $weights['ANSWERED'] - (int)floor(((int)$trunkProfile['failed_boost'] + (int)$trunkProfile['busy_boost']) / 2));
    }

    return $weights;
}

function buildTrafficSchedule(int $start, int $end, int $rows): array
{
    $schedule = [];
    $lastBurst = null;

    for ($i = 0; $i < $rows; $i++) {
        if ($lastBurst !== null && mt_rand(1, 100) <= 42) {
            $ts = min($end - 1, max($start, $lastBurst + mt_rand(0, 90)));
        } else {
            $ts = realisticTimestamp($start, $end);
            $lastBurst = mt_rand(1, 100) <= 18 ? $ts : null;
        }

        $schedule[$ts] = ($schedule[$ts] ?? 0) + 1;
    }

    ksort($schedule, SORT_NUMERIC);

    return $schedule;
}

function trafficWeight(int $ts): int
{
    $hour = (int)date('G', $ts);
    $minute = (int)date('i', $ts);
    $weekday = (int)date('N', $ts);

    if ($weekday >= 6) {
        if ($hour >= 10 && $hour < 14) {
            $base = 22;
        } elseif ($hour >= 14 && $hour < 18) {
            $base = 14;
        } elseif ($hour >= 8 && $hour < 20) {
            $base = 8;
        } else {
            $base = 2;
        }
    } else {
        if ($hour >= 8 && $hour < 10) {
            $base = 58;
        } elseif ($hour >= 10 && $hour < 12) {
            $base = 84;
        } elseif ($hour >= 12 && $hour < 13) {
            $base = 46;
        } elseif ($hour >= 13 && $hour < 16) {
            $base = 96;
        } elseif ($hour >= 16 && $hour < 18) {
            $base = 68;
        } elseif ($hour >= 18 && $hour < 21) {
            $base = 18;
        } elseif ($hour >= 7 && $hour < 8) {
            $base = 18;
        } else {
            $base = 3;
        }
    }

    if ($minute < 5 || ($minute >= 30 && $minute < 35)) {
        $base += 8;
    }

    if (mt_rand(1, 1000) <= 7) {
        $base *= mt_rand(3, 8);
    }

    return max(1, $base);
}

function realisticTimestamp(int $start, int $end): int
{
    $maxWeight = 800;

    for ($attempt = 0; $attempt < 500; $attempt++) {
        $ts = mt_rand($start, $end - 1);
        if (mt_rand(1, $maxWeight) <= trafficWeight($ts)) {
            return $ts;
        }
    }

    return mt_rand($start, $end - 1);
}

function directionWeights(int $ts): array
{
    $hour = (int)date('G', $ts);

    if ($hour < 8 || $hour >= 18) {
        return ['inbound' => 54, 'outbound' => 28, 'internal' => 18];
    }

    if ($hour >= 12 && $hour < 13) {
        return ['inbound' => 48, 'outbound' => 36, 'internal' => 16];
    }

    return ['inbound' => 43, 'outbound' => 45, 'internal' => 12];
}

function dispositionWeights(string $direction, int $ts): array
{
    $hour = (int)date('G', $ts);
    $afterHours = $hour < 8 || $hour >= 18;

    if ($direction === 'internal') {
        return ['ANSWERED' => 82, 'NO ANSWER' => 11, 'BUSY' => 5, 'FAILED' => 2];
    }

    if ($direction === 'inbound') {
        return $afterHours
            ? ['ANSWERED' => 42, 'NO ANSWER' => 38, 'BUSY' => 12, 'FAILED' => 8]
            : ['ANSWERED' => 76, 'NO ANSWER' => 13, 'BUSY' => 7, 'FAILED' => 4];
    }

    return $afterHours
        ? ['ANSWERED' => 58, 'NO ANSWER' => 20, 'BUSY' => 12, 'FAILED' => 10]
        : ['ANSWERED' => 70, 'NO ANSWER' => 16, 'BUSY' => 8, 'FAILED' => 6];
}

function durationFor(string $direction, string $disposition, array $cfg): int
{
    if ($disposition === 'NO ANSWER') {
        return mt_rand(18, 65);
    }

    if ($disposition === 'BUSY') {
        return mt_rand(3, 18);
    }

    if ($disposition === 'FAILED') {
        return mt_rand(1, 12);
    }

    $short = mt_rand(35, 180);
    $medium = mt_rand(181, min(720, (int)$cfg['max']));
    $long = mt_rand(min(721, (int)$cfg['max']), (int)$cfg['max']);

    if ($direction === 'internal') {
        $duration = weightedNumericChoice([$short => 62, $medium => 34, $long => 4]);
    } elseif ($direction === 'inbound') {
        $duration = weightedNumericChoice([$short => 35, $medium => 52, $long => 13]);
    } else {
        $duration = weightedNumericChoice([$short => 45, $medium => 45, $long => 10]);
    }

    return max((int)$cfg['min'], min((int)$cfg['max'], $duration));
}

function ringSeconds(string $direction): int
{
    if ($direction === 'internal') {
        return mt_rand(1, 10);
    }

    return mt_rand(4, 28);
}

function endpointChannel(string $extension): string
{
    $tech = mt_rand(1, 100) <= 86 ? 'PJSIP' : 'SIP';

    return channelInstance("{$tech}/{$extension}");
}

function channelInstance(string $base): string
{
    return $base . '-' . sprintf('%08x', mt_rand(1, 0x7fffffff));
}

function externalNumber(array $externalNumbers, array $outboundPrefixes): string
{
    if (mt_rand(1, 100) <= 58) {
        return pick($externalNumbers);
    }

    return pick($outboundPrefixes) . sprintf('%06d', mt_rand(0, 999999));
}

function callerName(): string
{
    $names = [
        'Acme Supply',
        'Bayside Dental',
        'City Logistics',
        'Customer Service',
        'Eastside Clinic',
        'Hamilton Group',
        'Metro Pharmacy',
        'Northwind LLC',
        'Prime Services',
        'Unknown Caller',
    ];

    return pick($names);
}

function lastAppFor(string $disposition, string $kind): string
{
    if ($kind === 'ivr') {
        return $disposition === 'FAILED' ? 'Hangup' : 'Goto';
    }

    if ($kind === 'queue') {
        return $disposition === 'FAILED' ? 'Hangup' : 'Queue';
    }

    if ($disposition === 'BUSY') {
        return 'Busy';
    }

    if ($disposition === 'FAILED') {
        return weightedChoice(['Congestion' => 60, 'Hangup' => 40]);
    }

    return 'Dial';
}

function hangupCause(string $disposition): int
{
    if ($disposition === 'ANSWERED' || $disposition === 'NO ANSWER') {
        return 16;
    }

    if ($disposition === 'BUSY') {
        return 17;
    }

    return weightedNumericChoice([1 => 25, 34 => 30, 38 => 25, 41 => 20]);
}

function pickWeightedTarget(array $targets): array
{
    $weights = [];

    foreach ($targets as $index => $target) {
        $weights[(string)$index] = (int)$target['weight'];
    }

    return $targets[(int)weightedChoice($weights)];
}

function shuffledValues(array $values): array
{
    $copy = array_values($values);

    for ($i = count($copy) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        $tmp = $copy[$i];
        $copy[$i] = $copy[$j];
        $copy[$j] = $tmp;
    }

    return $copy;
}

function generateCdrRow(
    int $ts,
    array $cfg,
    string $accountcode,
    array $extensions,
    array $extensionNames,
    array $trunkProfiles,
    array $externalNumbers,
    array $inboundTargets,
    array $outboundPrefixes,
    ?string $forcedDirection = null,
    ?string $forcedDisposition = null
): array {
    $direction = $forcedDirection ?? weightedChoice(directionWeights($ts));
    $trunkProfile = $direction === 'internal' ? null : pickTrunkProfile($trunkProfiles, $direction);
    $disposition = $forcedDisposition ?? weightedChoice(
        $trunkProfile === null ? dispositionWeights($direction, $ts) : trunkDispositionWeights($direction, $ts, $trunkProfile)
    );
    $duration = durationFor($direction, $disposition, $cfg);
    $billsec = $disposition === 'ANSWERED' ? max(1, $duration - ringSeconds($direction)) : 0;
    $answerOffset = $disposition === 'ANSWERED' ? ($duration - $billsec) : 0;
    $endTs = $ts + $duration;
    $uniqueid = sprintf('%d.%06d', $ts, mt_rand(0, 999999));
    $sequence = mt_rand(1, 9999);
    $extension = pick($extensions);
    $peerExtension = pick(array_values(array_diff($extensions, [$extension])));
    $trunk = $trunkProfile['channel'] ?? '';
    $external = externalNumber($externalNumbers, $trunkProfile['prefixes'] ?? $outboundPrefixes);
    $did = pick($trunkProfile['dids'] ?? ['2125550100']);
    $route = 'cdrgen';
    $hangupSource = '';
    $hangupCause = hangupCause($disposition);
    $recording = '';
    $queue = '';
    $allExtensions = [];

    if ($direction === 'inbound') {
        $target = pickWeightedTarget($inboundTargets);
        $extension = $target['type'] === 'extension' ? $target['value'] : pick(['2010', '2011', '2020', '2200']);
        $src = $external;
        $dst = $extension;
        $callerName = callerName();
        $clid = "\"{$callerName}\" <{$external}>";
        $channel = channelInstance($trunk);
        $dstchannel = $disposition === 'FAILED' ? '' : endpointChannel($extension);
        $dcontext = 'from-trunk';
        $lastapp = lastAppFor($disposition, $target['type']);
        $lastdata = $target['type'] === 'ivr' ? "ivr-{$target['value']},s,1" : "PJSIP/{$extension},30,Ttr";
        if ($target['type'] === 'ringgroup') {
            $ringMembers = array_slice(shuffledValues(['2010', '2011', '2020', '2200']), 0, mt_rand(2, 4));
            $ringChannels = [];
            foreach ($ringMembers as $member) {
                $ringChannels[] = "PJSIP/{$member}";
            }
            $lastdata = implode('&', $ringChannels) . ',30,Ttr';
            $allExtensions = [$extension];
        } elseif ($target['type'] === 'queue') {
            $queue = $target['value'];
            $lastapp = $disposition === 'ANSWERED' ? 'Queue' : lastAppFor($disposition, 'queue');
            $lastdata = "{$queue},tT,,,60";
            $allExtensions = [$extension];
        } else {
            $allExtensions = [$extension];
        }
        $didValue = $did;
        $cnum = $external;
        $outboundCnum = '';
        $trunkName = trunkName($trunk);
        $route = $target['type'] === 'ivr' ? 'ivr' : "inbound-{$target['type']}";
        $hangupSource = $disposition === 'ANSWERED' ? $dstchannel : $channel;
    } elseif ($direction === 'outbound') {
        $src = $extension;
        $dst = $external;
        $extName = $extensionNames[$extension] ?? "Extension {$extension}";
        $clid = "\"{$extName}\" <{$extension}>";
        $channel = endpointChannel($extension);
        $dstchannel = $disposition === 'FAILED' ? '' : channelInstance($trunk);
        $dcontext = 'from-internal';
        $lastapp = lastAppFor($disposition, 'dial');
        $lastdata = "{$trunk}/{$external},300,Ttr";
        $didValue = '';
        $cnum = $extension;
        $outboundCnum = $extension;
        $trunkName = trunkName($trunk);
        $allExtensions = [$extension];
        $hangupSource = $disposition === 'ANSWERED' ? $channel : ($dstchannel !== '' ? $dstchannel : $channel);
        $route = 'outbound-' . pick(['local', 'ld', 'tollfree', 'intl']);
    } else {
        $src = $extension;
        $dst = $peerExtension;
        $extName = $extensionNames[$extension] ?? "Extension {$extension}";
        $clid = "\"{$extName}\" <{$extension}>";
        $channel = endpointChannel($extension);
        $dstchannel = $disposition === 'FAILED' ? '' : endpointChannel($peerExtension);
        $dcontext = 'from-internal';
        $lastapp = lastAppFor($disposition, 'dial');
        $lastdata = "PJSIP/{$peerExtension},30,Ttr";
        $didValue = '';
        $cnum = $extension;
        $outboundCnum = '';
        $trunkName = 'internal';
        $allExtensions = [$extension, $peerExtension];
        $hangupSource = $disposition === 'ANSWERED' ? $channel : ($dstchannel !== '' ? $dstchannel : $channel);
        $route = 'internal';
    }

    if ($disposition === 'ANSWERED' && mt_rand(1, 100) <= 72) {
        $recording = date('Y/m/d', $ts) . "/{$uniqueid}-{$src}-{$dst}.wav";
    }

    return [
        'calldate' => date('Y-m-d H:i:s', $ts),
        'clid' => $clid,
        'src' => $src,
        'dst' => $dst,
        'dcontext' => $dcontext,
        'channel' => $channel,
        'dstchannel' => $dstchannel,
        'lastapp' => $lastapp,
        'lastdata' => $lastdata,
        'duration' => $duration,
        'billsec' => $billsec,
        'disposition' => $disposition,
        'amaflags' => 3,
        'accountcode' => $accountcode,
        'uniqueid' => $uniqueid,
        'userfield' => "cdrgen {$direction} {$route}",
        'did' => $didValue,
        'recordingfile' => $recording,
        'cnum' => $cnum,
        'cnam' => $direction === 'inbound' ? ($callerName ?? "Caller {$external}") : ($extName ?? "Extension {$extension}"),
        'outbound_cnum' => $outboundCnum,
        'outbound_cnam' => $outboundCnum !== '' ? ($extensionNames[$extension] ?? "Extension {$extension}") : '',
        'dst_cnam' => $direction === 'inbound' ? ($extensionNames[$extension] ?? "Extension {$extension}") : '',
        'linkedid' => $uniqueid,
        'peeraccount' => '',
        'sequence' => $sequence,
        'hangupsource' => $hangupSource,
        'hangupcause' => $hangupCause,
        'answer' => $disposition === 'ANSWERED' ? date('Y-m-d H:i:s', $ts + $answerOffset) : null,
        'end' => date('Y-m-d H:i:s', $endTs),
        'start' => date('Y-m-d H:i:s', $ts),
        'dstaccountcode' => '',
        'trunk' => $direction === 'internal' ? '' : $trunkName,
        'trunkname' => $direction === 'internal' ? '' : $trunkName,
        'carrier' => $direction === 'internal' ? '' : $trunkName,
        'direction' => $direction,
        'queue' => $queue,
        '_direction' => $direction,
        '_answer_ts' => $ts + $answerOffset,
        '_end_ts' => $endTs,
        '_extension' => $direction === 'inbound' ? $dst : $src,
        '_extensions' => $allExtensions,
        '_trunk' => $direction === 'internal' ? null : $trunkName,
    ];
}

function addExpectedCall(array &$expected, array $row): void
{
    $start = (int)$row['_answer_ts'];
    $end = (int)$row['_end_ts'];

    if ($end <= $start) {
        return;
    }

    addEvent($expected['global'], $start, 1);
    addEvent($expected['global'], $end, -1);
    foreach ($row['_extensions'] as $extension) {
        if (!isset($expected['extensions'][$extension])) {
            $expected['extensions'][$extension] = [];
        }

        addEvent($expected['extensions'][$extension], $start, 1);
        addEvent($expected['extensions'][$extension], $end, -1);
    }

    if ($row['_trunk'] !== null) {
        if (!isset($expected['trunks'][$row['_trunk']])) {
            $expected['trunks'][$row['_trunk']] = [];
        }

        addEvent($expected['trunks'][$row['_trunk']], $start, 1);
        addEvent($expected['trunks'][$row['_trunk']], $end, -1);
    }
}

function addEvent(array &$events, int $ts, int $delta): void
{
    if (!isset($events[$ts])) {
        $events[$ts] = 0;
    }

    $events[$ts] += $delta;
}

function incrementStat(array &$stats, string $name): void
{
    if (!isset($stats[$name])) {
        $stats[$name] = 0;
    }

    $stats[$name]++;
}

function printStats(array $stats): void
{
    echo "Generated traffic mix\n";
    echo "---------------------\n";

    ksort($stats['directions']);
    foreach ($stats['directions'] as $direction => $count) {
        echo "  {$direction}: {$count}\n";
    }

    echo "\nDisposition mix:\n";
    ksort($stats['dispositions']);
    foreach ($stats['dispositions'] as $disposition => $count) {
        echo "  {$disposition}: {$count}\n";
    }

    echo "\nTrunk mix:\n";
    ksort($stats['trunks']);
    foreach ($stats['trunks'] as $trunk => $count) {
        echo "  {$trunk}: {$count}\n";
    }

    echo "\n";
}

function printExpected(array $expected): void
{
    echo "Expected answered-only concurrency\n";
    echo "----------------------------------\n";
    echo "Global peak: " . peakConcurrency($expected['global']) . "\n";

    echo "\nPer-extension peak:\n";
    printPeakMap($expected['extensions']);

    echo "\nPer-trunk peak:\n";
    printPeakMap($expected['trunks']);
}

function printPeakMap(array $eventMap): void
{
    ksort($eventMap);

    foreach ($eventMap as $name => $events) {
        echo "  {$name}: " . peakConcurrency($events) . "\n";
    }
}

function peakConcurrency(array $events): int
{
    ksort($events, SORT_NUMERIC);
    $current = 0;
    $peak = 0;

    foreach ($events as $delta) {
        $current += $delta;
        if ($current > $peak) {
            $peak = $current;
        }
    }

    return $peak;
}

function weightedChoice(array $weights): string
{
    $total = array_sum($weights);
    $pick = mt_rand(1, $total);

    foreach ($weights as $value => $weight) {
        $pick -= $weight;
        if ($pick <= 0) {
            return (string)$value;
        }
    }

    return (string)firstArrayKey($weights);
}

function weightedNumericChoice(array $weights): int
{
    $total = array_sum($weights);
    $pick = mt_rand(1, $total);

    foreach ($weights as $value => $weight) {
        $pick -= $weight;
        if ($pick <= 0) {
            return (int)$value;
        }
    }

    return (int)firstArrayKey($weights);
}

function firstArrayKey(array $values)
{
    foreach ($values as $key => $_) {
        return $key;
    }

    return null;
}

function pick(array $values): string
{
    return (string)$values[array_rand($values)];
}

function randomHex(int $length): string
{
    $chars = '0123456789abcdef';
    $out = '';

    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[mt_rand(0, 15)];
    }

    return $out;
}

function trunkName(string $channel): string
{
    $parts = explode('/', $channel, 2);

    return $parts[1] ?? $channel;
}
