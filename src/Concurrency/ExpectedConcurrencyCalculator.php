<?php

namespace CdrGen\Concurrency;

final class ExpectedConcurrencyCalculator
{
    private $semantics;
    private $chunkSeconds;
    private $maxSeconds;

    public function __construct(
        string $semantics = ConcurrencySemantics::ANSWERED_MEDIA,
        int $chunkSeconds = 3600,
        int $maxSeconds = 86400
    ) {
        $this->semantics = ConcurrencySemantics::validate($semantics);
        $this->chunkSeconds = $chunkSeconds;
        $this->maxSeconds = $maxSeconds;
    }

    public function calculate(array $rows, array $configuredTrunkChannels = []): array
    {
        $configuredPjsipTrunks = $this->configuredPjsipTrunks($configuredTrunkChannels);
        $intervals = [
            'global' => [],
            'extensions_handled' => [],
            'extensions_channel' => [],
            'trunks' => [],
        ];

        foreach ($rows as $row) {
            if (!$this->isEligible($row)) {
                continue;
            }

            $interval = $this->interval($row);
            if ($interval === null) {
                continue;
            }

            $intervals['global'][] = $interval;
            $handled = $this->handledPjsipExtension($row);
            if ($handled !== null && !isset($configuredPjsipTrunks[$handled])) {
                $intervals['extensions_handled'][$handled][] = $interval;
            }
            foreach ($this->visiblePjsipExtensions($row) as $extension) {
                if (isset($configuredPjsipTrunks[$extension])) {
                    continue;
                }
                $intervals['extensions_channel'][$extension][] = $interval;
            }

            $trunk = $row['_trunk'] ?? ($row['trunk'] ?? null);
            if ($trunk !== null && $trunk !== '') {
                $intervals['trunks'][$trunk][] = $interval;
            }
        }

        $result = [
            'global' => $this->peak($intervals['global']),
            'extensions_handled' => [],
            'extensions_channel' => [],
            'trunks' => [],
            'semantics' => $this->semantics,
        ];
        foreach (['extensions_handled', 'extensions_channel', 'trunks'] as $kind) {
            ksort($intervals[$kind]);
            foreach ($intervals[$kind] as $name => $values) {
                $result[$kind][$name] = $this->peak($values);
            }
        }
        return $result;
    }

    public function peak(array $intervals): int
    {
        if ($intervals === []) {
            return 0;
        }

        $minimum = null;
        $maximum = null;
        foreach ($intervals as $interval) {
            $minimum = $minimum === null ? $interval[0] : min($minimum, $interval[0]);
            $maximum = $maximum === null ? $interval[1] : max($maximum, $interval[1]);
        }

        $peak = 0;
        $firstChunk = $minimum - ($minimum % $this->chunkSeconds);
        for ($chunkStart = $firstChunk; $chunkStart <= $maximum; $chunkStart += $this->chunkSeconds) {
            $chunkEnd = min($chunkStart + $this->chunkSeconds - 1, $maximum);
            $events = [];
            foreach ($intervals as $interval) {
                $from = max($interval[0], $chunkStart);
                $to = min($interval[1], $chunkEnd);
                if ($to < $from) {
                    continue;
                }
                $events[$from] = ($events[$from] ?? 0) + 1;
                if ($to < $chunkEnd) {
                    $events[$to + 1] = ($events[$to + 1] ?? 0) - 1;
                }
            }
            ksort($events, SORT_NUMERIC);
            $active = 0;
            foreach ($events as $delta) {
                $active += $delta;
                $peak = max($peak, $active);
            }
        }
        return $peak;
    }

    private function isEligible(array $row): bool
    {
        if (($row['disposition'] ?? '') !== 'ANSWERED') {
            return false;
        }
        if ($this->semantics === ConcurrencySemantics::CDR) {
            return true;
        }
        return strpos((string) ($row['channel'] ?? ''), 'PJSIP/') === 0
            || strpos((string) ($row['dstchannel'] ?? ''), 'PJSIP/') === 0;
    }

    private function interval(array $row): ?array
    {
        if ($this->semantics === ConcurrencySemantics::CDR) {
            $start = $row['_start_ts'] ?? strtotime($row['calldate']);
            $fallbackLength = (int) ($row['duration'] ?? 0);
        } else {
            $start = $row['_answer_ts'] ?? (isset($row['answer']) ? strtotime($row['answer']) : null);
            $fallbackLength = (int) ($row['billsec'] ?? 0);
        }
        if ($start === null || $start === false) {
            return null;
        }
        $end = $row['_end_ts'] ?? ((int) $start + $fallbackLength);
        $end = min((int) $end, (int) $start + $this->maxSeconds);
        return $end < $start ? null : [(int) $start, $end];
    }

    private function handledPjsipExtension(array $row): ?string
    {
        if (preg_match('/^[19]/', (string) ($row['dst'] ?? '')) === 1) {
            return null;
        }
        foreach (['dstchannel', 'channel'] as $field) {
            if (preg_match('/^PJSIP\/([0-9]+)-/', (string) ($row[$field] ?? ''), $matches) === 1) {
                return $matches[1];
            }
        }
        return null;
    }

    private function visiblePjsipExtensions(array $row): array
    {
        $extensions = [];
        foreach (['channel', 'dstchannel'] as $field) {
            if (preg_match_all('/(?:^|&)PJSIP\/([0-9]+)-/', (string) ($row[$field] ?? ''), $matches)) {
                foreach ($matches[1] as $extension) {
                    $extensions[$extension] = true;
                }
            }
        }
        return array_keys($extensions);
    }

    private function configuredPjsipTrunks(array $channels): array
    {
        $trunks = [];
        foreach ($channels as $channel) {
            if (is_string($channel)
                && preg_match('/^PJSIP\/([^\/]+)$/', $channel, $matches) === 1
            ) {
                $trunks[$matches[1]] = true;
            }
        }
        return $trunks;
    }
}
