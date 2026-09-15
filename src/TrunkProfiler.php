<?php

namespace CdrGen;

use CdrGen\Random\RandomSource;

final class TrunkProfiler
{
    public function profile(
        string $channel,
        array $dids,
        array $prefixes,
        int $index,
        string $label,
        RandomSource $random
    ): array {
        $channel = $this->normalizeChannel($channel);
        $traits = $this->traits($label . ' ' . $channel);
        $weight = max(2, 80 - $index * 10);
        $inboundWeight = $random->int(38, 66);
        $outboundWeight = $random->int(38, 66);
        $degraded = $index >= 3;

        if ($traits['primary']) {
            $weight += 28; $inboundWeight += 12; $outboundWeight += 12; $degraded = false;
        }
        if ($traits['secondary']) {
            $weight = max(10, (int) floor($weight * 0.65));
            $inboundWeight = max(12, $inboundWeight - 14);
            $outboundWeight = max(12, $outboundWeight - 14);
        }
        if ($traits['backup']) {
            $weight = max(3, (int) floor($weight * 0.25));
            $inboundWeight = max(8, (int) floor($inboundWeight * 0.45));
            $outboundWeight = max(12, (int) floor($outboundWeight * 0.60));
            $degraded = true;
        }
        if ($traits['inbound']) {
            $inboundWeight += 45;
            $outboundWeight = max(6, (int) floor($outboundWeight * 0.35));
        }
        if ($traits['outbound']) {
            $outboundWeight += 45;
            $inboundWeight = max(6, (int) floor($inboundWeight * 0.35));
        }
        if ($traits['tollfree']) {
            $inboundWeight += 55;
            $outboundWeight = max(6, (int) floor($outboundWeight * 0.30));
            $prefixes = ['1800', '1888', '1877', '1866'];
        }
        if ($traits['international']) {
            $outboundWeight += 32;
            $prefixes = ['01144', '01149', '01161', '01133'];
        }
        if ($traits['fax']) {
            $weight = max(2, (int) floor($weight * 0.30));
            $inboundWeight += 18; $outboundWeight += 10;
        }
        if ($traits['emergency']) {
            $weight = max(1, (int) floor($weight * 0.10));
            $outboundWeight += 12;
        }

        return [
            'channel' => $channel,
            'name' => $this->name($channel),
            'weight' => max(1, $weight),
            'inbound_weight' => max(1, $inboundWeight),
            'outbound_weight' => max(1, $outboundWeight),
            'dids' => $dids,
            'prefixes' => $prefixes,
            'failed_boost' => $degraded ? $random->int(3, 8) : 0,
            'busy_boost' => $degraded ? $random->int(2, 6) : 0,
            'jitter' => $random->int(0, 4),
            'traits' => $traits,
        ];
    }

    public function traits(string $value): array
    {
        $tokens = preg_split('/[^a-z0-9]+/', strtolower($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $compact = implode('', $tokens);
        $sets = [
            'inbound' => ['in', 'inb', 'inbound', 'incoming', 'ingress', 'did', 'dids', 'ddi', 'recv', 'receive', 'rx', 'orig', 'origination'],
            'outbound' => ['out', 'outb', 'outbound', 'outgoing', 'egress', 'send', 'tx', 'term', 'terminate', 'termination'],
            'primary' => ['primary', 'pri', 'main', 'default', 'prod', 'production', 'active', 'preferred', 'prefered', 'first', 'one', 'a'],
            'secondary' => ['secondary', 'second', 'alt', 'alternate', 'alternative', 'overflow', 'spare', 'standby', 'two', 'b'],
            'backup' => ['backup', 'back', 'bak', 'bkp', 'failover', 'failsafe', 'standby', 'redundant', 'redundancy', 'dr', 'disasterrecovery'],
            'tollfree' => ['tollfree', 'tf', 'freephone', '800', '888', '877', '866', '855', '844', '833'],
            'international' => ['intl', 'international', 'global', 'world', 'ld', 'longdistance', 'overseas'],
            'fax' => ['fax', 'facsimile', 't38', 't38fax'],
            'emergency' => ['emergency', 'e911', '911', 'psap'],
        ];
        $scores = [];
        foreach ($sets as $trait => $needles) {
            $scores[$trait] = $this->score($tokens, $compact, $needles);
        }
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

    public function normalizeChannel(string $value): string
    {
        return $value === '' ? '' : (strpos($value, '/') === false ? 'PJSIP/' . $value : $value);
    }

    public function name(string $channel): string
    {
        $parts = explode('/', $channel, 2);
        return $parts[1] ?? $channel;
    }

    private function score(array $tokens, string $compact, array $needles): int
    {
        $score = 0;
        foreach ($needles as $needle) {
            foreach ($tokens as $token) {
                if ($token === $needle) $score += 4;
                elseif (strlen($needle) >= 4 && (strpos($token, $needle) !== false || strpos($needle, $token) !== false)) $score += 2;
                elseif (strlen($needle) >= 5 && strlen($token) >= 5) {
                    $distance = levenshtein($token, $needle);
                    if ($distance <= 1) $score += 3;
                    elseif ($distance <= 2) $score += 2;
                }
            }
            if (strlen($needle) >= 4 && strpos($compact, $needle) !== false) $score += 2;
        }
        return $score;
    }
}
