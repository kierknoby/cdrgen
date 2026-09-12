<?php

namespace CdrGen;

use CdrGen\Random\RandomSource;

final class Generator
{
    private const COVERAGE_ROWS = [
        ['inbound', 'ANSWERED'],
        ['outbound', 'ANSWERED'],
        ['internal', 'ANSWERED'],
        ['inbound', 'NO ANSWER'],
        ['outbound', 'BUSY'],
        ['inbound', 'FAILED'],
        ['outbound', 'NO ANSWER'],
    ];

    public function generate(
        GenerationRequest $request,
        ?callable $progress = null,
        int $batchSize = 500
    ): GenerationResult
    {
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('Generation batch size must be positive');
        }
        // Always restart from the canonical traffic substream. Neither caller-side
        // consumption nor a previous generate() call can affect these rows.
        $random = $request->random()->fork('traffic-generation-v1');
        $traffic = new TrafficModel($random, $request->timezone());
        $schedule = $traffic->schedule($request->start(), $request->end(), $request->rows());
        $rows = [];
        $sequence = 1;

        foreach ($schedule as $timestamp => $count) {
            for ($index = 0; $index < $count; $index++) {
                $forced = self::COVERAGE_ROWS[count($rows)] ?? [null, null];
                $rows[] = $this->generateRow(
                    (int) $timestamp,
                    $request,
                    $traffic,
                    $random,
                    $forced[0],
                    $forced[1],
                    $sequence++
                );
                $completed = count($rows);
                if ($progress !== null
                    && ($completed % $batchSize === 0 || $completed === $request->rows())
                ) {
                    $progress($completed, $request->rows());
                }
            }
        }

        return new GenerationResult(
            $rows,
            $request->profile()->name(),
            $request->datasetIdentity(),
            [
                'random_identity' => $request->random()->identity(),
                'timezone' => $request->timezone(),
                'core_version' => Version::VERSION,
            ]
        );
    }

    private function generateRow(
        int $timestamp,
        GenerationRequest $request,
        TrafficModel $traffic,
        RandomSource $random,
        ?string $forcedDirection,
        ?string $forcedDisposition,
        int $sequence
    ): array {
        $direction = $forcedDirection ?? $this->weightedChoice(
            $traffic->directionWeights($timestamp),
            $random
        );
        $trunk = $direction === 'internal'
            ? null
            : $this->pickTrunk($request->trunks(), $direction, $random);
        $weights = $traffic->dispositionWeights($direction, $timestamp);
        if ($trunk !== null) {
            $weights['FAILED'] += (int) $trunk['failed_boost'];
            $weights['BUSY'] += (int) $trunk['busy_boost'];
            $weights['ANSWERED'] = max(
                20,
                $weights['ANSWERED'] - (int) floor(($trunk['failed_boost'] + $trunk['busy_boost']) / 2)
            );
        }
        $disposition = $forcedDisposition ?? $this->weightedChoice($weights, $random);
        $duration = $this->duration($direction, $disposition, $request->profile(), $random);
        $ringSeconds = $disposition === 'ANSWERED'
            ? ($direction === 'internal' ? $random->int(1, 10) : $random->int(4, 28))
            : 0;
        $billsec = $disposition === 'ANSWERED' ? max(1, $duration - $ringSeconds) : 0;
        $endTimestamp = $timestamp + $duration;

        $extensions = $request->extensions();
        $extension = $this->pick($extensions, $random);
        $peerExtension = $this->pick(array_values(array_diff($extensions, [$extension])), $random);
        $external = $this->externalNumber(
            $trunk['prefixes'] ?? ['1212', '1646', '1718', '1310', '1415', '1617', '1202', '1303', '1800', '1888'],
            $random
        );
        $did = $this->pick($trunk['dids'] ?? ['2125550100'], $random);
        $uniqueId = sprintf('%d.%06d.%05d', $timestamp, $random->int(0, 999999), $sequence);
        $names = $request->extensionNames();
        $queue = '';
        $inboundType = '';
        $route = 'cdrgen';

        if ($direction === 'inbound') {
            $call = $this->inboundFields(
                $extension,
                $extensions,
                $external,
                $did,
                $trunk,
                $disposition,
                $request,
                $random
            );
            $queue = $call['queue'];
            $inboundType = $call['inbound_type'];
            $route = $call['route'];
        } elseif ($direction === 'outbound') {
            $call = $this->outboundFields(
                $extension,
                $external,
                $trunk,
                $disposition,
                $request,
                $random
            );
            $route = $call['route'];
        } else {
            $call = $this->internalFields(
                $extension,
                $peerExtension,
                $disposition,
                $request,
                $random
            );
            $route = 'internal';
        }

        $recording = '';
        if ($disposition === 'ANSWERED' && $random->int(1, 100) <= 72) {
            $recording = $traffic->format('Y/m/d', $timestamp)
                . "/{$uniqueId}-{$call['src']}-{$call['dst']}.wav";
        }
        $answerTimestamp = $disposition === 'ANSWERED' ? $timestamp + $ringSeconds : null;

        return [
            'calldate' => $traffic->format('Y-m-d H:i:s', $timestamp),
            'clid' => $call['clid'],
            'src' => $call['src'],
            'dst' => $call['dst'],
            'did' => $call['did'],
            'dcontext' => $call['context'],
            'channel' => $call['channel'],
            'dstchannel' => $call['dstchannel'],
            'lastapp' => $call['lastapp'],
            'lastdata' => $call['lastdata'],
            'duration' => $duration,
            'billsec' => $billsec,
            'disposition' => $disposition,
            'amaflags' => 3,
            'accountcode' => $request->accountcode(),
            'uniqueid' => $uniqueId,
            'linkedid' => $uniqueId,
            'userfield' => "cdrgen {$direction} {$route}",
            'recordingfile' => $recording,
            'cnum' => $call['cnum'],
            'cnam' => $call['cnam'],
            'outbound_cnum' => $call['outbound_cnum'],
            'outbound_cnam' => $call['outbound_cnam'],
            'dst_cnam' => $call['dst_cnam'],
            'peeraccount' => '',
            'sequence' => $sequence,
            'hangupsource' => $call['hangupsource'],
            'hangupcause' => $this->hangupCause($disposition, $random),
            'start' => $traffic->format('Y-m-d H:i:s', $timestamp),
            'answer' => $answerTimestamp === null
                ? null
                : $traffic->format('Y-m-d H:i:s', $answerTimestamp),
            'end' => $traffic->format('Y-m-d H:i:s', $endTimestamp),
            'dstaccountcode' => '',
            'trunk' => $call['trunk_name'],
            'trunkname' => $call['trunk_name'],
            'carrier' => $call['trunk_name'],
            'direction' => $direction,
            'queue' => $queue,
            '_direction' => $direction,
            '_start_ts' => $timestamp,
            '_answer_ts' => $answerTimestamp,
            '_end_ts' => $endTimestamp,
            '_extension' => $direction === 'inbound' ? $call['dst'] : $call['src'],
            '_extensions' => $call['visible_extensions'],
            '_trunk' => $direction === 'internal' ? null : $call['trunk_name'],
            '_inbound_type' => $inboundType,
        ];
    }

    private function inboundFields(
        string $extension,
        array $extensions,
        string $external,
        string $did,
        array $trunk,
        string $disposition,
        GenerationRequest $request,
        RandomSource $random
    ): array {
        $targets = [
            ['extension', '2010', 22], ['extension', '2011', 17],
            ['extension', '2020', 13], ['extension', '2200', 10],
            ['extension', '2001', 8], ['extension', '2002', 8],
            ['ringgroup', '600', 7], ['queue', '800', 9], ['ivr', '700', 6],
        ];
        $target = $this->weightedTarget($targets, $random);
        $type = $target[0];
        if ($type === 'extension' && in_array($target[1], $extensions, true)) {
            $extension = $target[1];
        }
        $callerName = $this->callerName($random);
        $channel = $this->channelInstance($trunk['channel'], $random);
        $dstchannel = $disposition === 'FAILED' ? '' : $this->endpointChannel($extension, $request, $random);
        $lastapp = $this->lastApplication($disposition, $type, $random);
        $lastdata = "PJSIP/{$extension},30,Ttr";
        $queue = '';
        if ($type === 'ringgroup') {
            $members = array_slice(
                $this->shuffle($extensions, $random),
                0,
                min(count($extensions), $random->int(2, min(4, count($extensions))))
            );
            $lastdata = implode('&', array_map(static function (string $member): string {
                return 'PJSIP/' . $member;
            }, $members)) . ',30,Ttr';
        } elseif ($type === 'queue') {
            $queue = $target[1];
            $lastapp = $disposition === 'ANSWERED'
                ? 'Queue'
                : $this->lastApplication($disposition, 'queue', $random);
            $lastdata = "{$queue},tT,,,60";
        } elseif ($type === 'ivr') {
            $lastdata = "ivr-{$target[1]},s,1";
        }
        $names = $request->extensionNames();
        return [
            'src' => $external, 'dst' => $extension,
            'clid' => '"' . $callerName . '" <' . $external . '>',
            'channel' => $channel, 'dstchannel' => $dstchannel,
            'context' => 'from-trunk', 'lastapp' => $lastapp, 'lastdata' => $lastdata,
            'did' => $did, 'cnum' => $external, 'cnam' => $callerName,
            'outbound_cnum' => '', 'outbound_cnam' => '',
            'dst_cnam' => $names[$extension] ?? "Extension {$extension}",
            'trunk_name' => $trunk['name'], 'queue' => $queue,
            'route' => $type === 'ivr' ? 'ivr' : 'inbound-' . $type,
            'inbound_type' => $type,
            'hangupsource' => $disposition === 'ANSWERED' ? $dstchannel : $channel,
            'visible_extensions' => [$extension],
        ];
    }

    private function outboundFields(
        string $extension,
        string $external,
        array $trunk,
        string $disposition,
        GenerationRequest $request,
        RandomSource $random
    ): array {
        $name = $request->extensionNames()[$extension] ?? "Extension {$extension}";
        $channel = $this->endpointChannel($extension, $request, $random);
        $dstchannel = $disposition === 'FAILED' ? '' : $this->channelInstance($trunk['channel'], $random);
        return [
            'src' => $extension, 'dst' => $external,
            'clid' => '"' . $name . '" <' . $extension . '>',
            'channel' => $channel, 'dstchannel' => $dstchannel,
            'context' => 'from-internal',
            'lastapp' => $this->lastApplication($disposition, 'dial', $random),
            'lastdata' => $trunk['channel'] . "/{$external},300,Ttr",
            'did' => '', 'cnum' => $extension, 'cnam' => $name,
            'outbound_cnum' => $extension, 'outbound_cnam' => $name, 'dst_cnam' => '',
            'trunk_name' => $trunk['name'], 'queue' => '',
            'route' => 'outbound-' . $this->pick(['local', 'ld', 'tollfree', 'intl'], $random),
            'hangupsource' => $disposition === 'ANSWERED' ? $channel : ($dstchannel ?: $channel),
            'visible_extensions' => [$extension],
        ];
    }

    private function internalFields(
        string $extension,
        string $peer,
        string $disposition,
        GenerationRequest $request,
        RandomSource $random
    ): array {
        $name = $request->extensionNames()[$extension] ?? "Extension {$extension}";
        $channel = $this->endpointChannel($extension, $request, $random);
        $dstchannel = $disposition === 'FAILED' ? '' : $this->endpointChannel($peer, $request, $random);
        return [
            'src' => $extension, 'dst' => $peer,
            'clid' => '"' . $name . '" <' . $extension . '>',
            'channel' => $channel, 'dstchannel' => $dstchannel,
            'context' => 'from-internal',
            'lastapp' => $this->lastApplication($disposition, 'dial', $random),
            'lastdata' => "PJSIP/{$peer},30,Ttr",
            'did' => '', 'cnum' => $extension, 'cnam' => $name,
            'outbound_cnum' => '', 'outbound_cnam' => '', 'dst_cnam' => '',
            'trunk_name' => '', 'queue' => '',
            'hangupsource' => $disposition === 'ANSWERED' ? $channel : ($dstchannel ?: $channel),
            'visible_extensions' => [$extension, $peer],
        ];
    }

    private function duration(string $direction, string $disposition, TrafficProfile $profile, RandomSource $random): int
    {
        if ($disposition === 'NO ANSWER') return $random->int(18, 65);
        if ($disposition === 'BUSY') return $random->int(3, 18);
        if ($disposition === 'FAILED') return $random->int(1, 12);
        $short = $random->int(35, 180);
        $medium = $random->int(181, min(720, $profile->maximumDuration()));
        $long = $random->int(min(721, $profile->maximumDuration()), $profile->maximumDuration());
        if ($direction === 'internal') $weights = [$short => 62, $medium => 34, $long => 4];
        elseif ($direction === 'inbound') $weights = [$short => 35, $medium => 52, $long => 13];
        else $weights = [$short => 45, $medium => 45, $long => 10];
        $duration = (int) $this->weightedChoice($weights, $random);
        return max($profile->minimumDuration(), min($profile->maximumDuration(), $duration));
    }

    private function pickTrunk(array $profiles, string $direction, RandomSource $random): array
    {
        $weights = [];
        foreach ($profiles as $index => $profile) {
            $base = $direction === 'inbound' ? $profile['inbound_weight'] : $profile['outbound_weight'];
            $weights[(string) $index] = max(1, $base + $random->int(0, (int) $profile['jitter']));
        }
        return $profiles[(int) $this->weightedChoice($weights, $random)];
    }

    private function weightedChoice(array $weights, RandomSource $random): string
    {
        $choice = $random->int(1, array_sum($weights));
        foreach ($weights as $value => $weight) {
            $choice -= $weight;
            if ($choice <= 0) return (string) $value;
        }
        foreach ($weights as $value => $_) return (string) $value;
        throw new \LogicException('Cannot choose from empty weights');
    }

    private function weightedTarget(array $targets, RandomSource $random): array
    {
        $weights = [];
        foreach ($targets as $index => $target) $weights[(string) $index] = $target[2];
        return $targets[(int) $this->weightedChoice($weights, $random)];
    }

    private function endpointChannel(string $extension, GenerationRequest $request, RandomSource $random): string
    {
        $technologies = $request->technologies();
        if (in_array('PJSIP', $technologies, true) && in_array('SIP', $technologies, true)) {
            $technology = $random->int(1, 100) <= 86 ? 'PJSIP' : 'SIP';
        } else {
            $technology = $this->pick($technologies, $random);
        }
        return $this->channelInstance("{$technology}/{$extension}", $random);
    }

    private function externalNumber(array $prefixes, RandomSource $random): string
    {
        $pool = ['12125550111', '12125550112', '16465550130', '17185550140', '13105550150',
            '18005550160', '14155550170', '16175550180', '12025550190', '13035550200'];
        return $random->int(1, 100) <= 58
            ? $this->pick($pool, $random)
            : $this->pick($prefixes, $random) . sprintf('%06d', $random->int(0, 999999));
    }

    private function channelInstance(string $base, RandomSource $random): string
    {
        return $base . '-' . sprintf('%08x', $random->int(1, 0x7fffffff));
    }

    private function pick(array $values, RandomSource $random): string
    {
        return (string) $values[$random->int(0, count($values) - 1)];
    }

    private function callerName(RandomSource $random): string
    {
        return $this->pick(['Acme Supply', 'Bayside Dental', 'City Logistics', 'Customer Service',
            'Eastside Clinic', 'Hamilton Group', 'Metro Pharmacy', 'Northwind LLC',
            'Prime Services', 'Unknown Caller'], $random);
    }

    private function lastApplication(string $disposition, string $kind, RandomSource $random): string
    {
        if ($kind === 'ivr') return $disposition === 'FAILED' ? 'Hangup' : 'Goto';
        if ($kind === 'queue') return $disposition === 'FAILED' ? 'Hangup' : 'Queue';
        if ($disposition === 'BUSY') return 'Busy';
        if ($disposition === 'FAILED') return $this->weightedChoice(['Congestion' => 60, 'Hangup' => 40], $random);
        return 'Dial';
    }

    private function hangupCause(string $disposition, RandomSource $random): int
    {
        if ($disposition === 'ANSWERED' || $disposition === 'NO ANSWER') return 16;
        if ($disposition === 'BUSY') return 17;
        return (int) $this->weightedChoice([1 => 25, 34 => 30, 38 => 25, 41 => 20], $random);
    }

    private function shuffle(array $values, RandomSource $random): array
    {
        for ($index = count($values) - 1; $index > 0; $index--) {
            $other = $random->int(0, $index);
            $temporary = $values[$index];
            $values[$index] = $values[$other];
            $values[$other] = $temporary;
        }
        return $values;
    }
}
