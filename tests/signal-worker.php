#!/usr/bin/env php
<?php

require __DIR__ . '/../src/autoload.php';

use CdrGen\Database\AccountcodePolicy;
use CdrGen\Database\ActiveRunStore;
use CdrGen\Database\CdrRepository;
use CdrGen\Database\LiveRunGuard;
use CdrGen\Database\SignalCleanup;

$databasePath = $argv[1];
$stateDirectory = $argv[2];
$mode = $argv[3];
$accountcode = AccountcodePolicy::deterministic('signal-' . $mode);

$pdo = new PDO('sqlite:' . $databasePath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(
    'CREATE TABLE cdr ('
    . 'id INTEGER PRIMARY KEY AUTOINCREMENT, accountcode TEXT NOT NULL, '
    . 'src TEXT NOT NULL, userfield TEXT NULL)'
);
$metadata = [
    ['Field' => 'id', 'Type' => 'integer', 'Extra' => 'auto_increment', 'Null' => 'NO', 'Default' => null],
    ['Field' => 'accountcode', 'Type' => 'varchar(20)', 'Extra' => '', 'Null' => 'NO', 'Default' => null],
    ['Field' => 'src', 'Type' => 'varchar(20)', 'Extra' => '', 'Null' => 'NO', 'Default' => null],
    ['Field' => 'userfield', 'Type' => 'varchar(255)', 'Extra' => '', 'Null' => 'YES', 'Default' => null],
];
$repository = new CdrRepository($pdo, $metadata);
$store = new ActiveRunStore($stateDirectory . '/active-run.json');
$guard = new LiveRunGuard($repository, $store);
$guard->prepare($accountcode, $mode === 'keep');
$guard->armCleanup();
$repository->insertAll([[
    'accountcode' => $accountcode,
    'src' => '2001',
    'userfield' => 'cdrgen signal-test',
]]);
if ($mode === 'failure') {
    $pdo->exec(
        "CREATE TRIGGER restore_deleted AFTER DELETE ON cdr "
        . "BEGIN INSERT INTO cdr(accountcode, src, userfield) "
        . "VALUES (OLD.accountcode, OLD.src, OLD.userfield); END"
    );
}
SignalCleanup::register($guard, $accountcode);
echo "READY {$accountcode}\n";
flush();
while (true) {
    usleep(100000);
}
