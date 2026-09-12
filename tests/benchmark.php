#!/usr/bin/env php
<?php

require __DIR__ . '/../src/autoload.php';

use CdrGen\Concurrency\ExpectedConcurrencyCalculator;
use CdrGen\GenerationRequest;
use CdrGen\Generator;
use CdrGen\Random\SeededRandomSource;
use CdrGen\TrafficProfile;
use CdrGen\TrunkProfiler;

$name = $argv[1] ?? 'light';
$profile = TrafficProfile::named($name);
$scenario = new SeededRandomSource(303);
$profiling = $scenario->fork('trunk-profiling-v1');
$profiler = new TrunkProfiler();
$trunks = [];
foreach (['PJSIP/Primary-In', 'PJSIP/Primary-Out', 'SIP/Failover-Test'] as $index => $channel) {
    $trunks[] = $profiler->profile(
        $channel,
        ['2125550100'],
        ['1212', '1800'],
        $index,
        $channel,
        $profiling
    );
}
$end = strtotime('2026-05-31 00:00:00 UTC');
$request = new GenerationRequest(
    $profile,
    $end - $profile->days() * 86400,
    $end,
    $scenario,
    ['2001', '2002', '2003', '2010', '2011', '2020', '2200'],
    $trunks,
    ['timezone' => 'UTC']
);

$started = microtime(true);
$result = (new Generator())->generate($request);
$generationSeconds = microtime(true) - $started;
$started = microtime(true);
$expected = (new ExpectedConcurrencyCalculator())->calculate($result->rows());
$concurrencySeconds = microtime(true) - $started;

echo sprintf(
    "%s rows=%d generation=%.3fs concurrency=%.3fs peak_php_memory=%.1fMiB peak=%d\n",
    $name,
    count($result->rows()),
    $generationSeconds,
    $concurrencySeconds,
    memory_get_peak_usage(true) / 1048576,
    $expected['global']
);
