<?php

/**
 * CDRgen
 *
 * Synthetic realistic CDR generator for FreePBX/Asterisk systems.
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only\n");
}

$bootstrap_settings['freepbx_auth'] = false;
require_once '/etc/freepbx.conf';

$opts = getopt('', ['profile::', 'seed::']);

$profile = $opts['profile'] ?? 'light';

$profiles = [
    'light'  => ['rows' => 250,   'days' => 1,  'min' => 20, 'max' => 420],
    'medium' => ['rows' => 2500,  'days' => 7,  'min' => 20, 'max' => 900],
    'heavy'  => ['rows' => 15000, 'days' => 30, 'min' => 20, 'max' => 1800],
];

if (!isset($profiles[$profile])) {
    die("--profile must be light, medium, or heavy\n");
}

$cfg = $profiles[$profile];
$rows = $cfg['rows'];
$seed = isset($opts['seed']) ? (int)$opts['seed'] : random_int(1, 0x7fffffff);

echo "CDRgen\n";
echo "======\n";
echo "Generating $rows rows\n";
echo "Profile: $profile\n";
echo "Seed: $seed\n";
echo "\n";

echo "Randomised realistic CDR generation ready.\n";
