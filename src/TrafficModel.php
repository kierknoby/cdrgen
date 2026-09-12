<?php

namespace CdrGen;

use CdrGen\Random\RandomSource;

final class TrafficModel
{
    private $random;
    private $timezone;

    public function __construct(RandomSource $random, string $timezone = 'UTC')
    {
        $this->random = $random;
        $this->timezone = new \DateTimeZone($timezone);
    }

    public function schedule(int $start, int $end, int $rows): array
    {
        $schedule = [];
        $lastBurst = null;
        for ($index = 0; $index < $rows; $index++) {
            if ($lastBurst !== null && $this->random->int(1, 100) <= 42) {
                $timestamp = min($end - 1, max($start, $lastBurst + $this->random->int(0, 90)));
            } else {
                $timestamp = $this->realisticTimestamp($start, $end);
                $lastBurst = $this->random->int(1, 100) <= 18 ? $timestamp : null;
            }
            $schedule[$timestamp] = ($schedule[$timestamp] ?? 0) + 1;
        }
        ksort($schedule, SORT_NUMERIC);
        return $schedule;
    }

    public function weight(int $timestamp, bool $allowSpike = true): int
    {
        $hour = (int) $this->format('G', $timestamp);
        $minute = (int) $this->format('i', $timestamp);
        $weekday = (int) $this->format('N', $timestamp);
        if ($weekday >= 6) {
            if ($hour >= 10 && $hour < 14) $base = 22;
            elseif ($hour >= 14 && $hour < 18) $base = 14;
            elseif ($hour >= 8 && $hour < 20) $base = 8;
            else $base = 2;
        } else {
            if ($hour >= 8 && $hour < 10) $base = 58;
            elseif ($hour >= 10 && $hour < 12) $base = 84;
            elseif ($hour >= 12 && $hour < 13) $base = 46;
            elseif ($hour >= 13 && $hour < 16) $base = 96;
            elseif ($hour >= 16 && $hour < 18) $base = 68;
            elseif ($hour >= 18 && $hour < 21) $base = 18;
            elseif ($hour >= 7 && $hour < 8) $base = 18;
            else $base = 3;
        }
        if ($minute < 5 || ($minute >= 30 && $minute < 35)) $base += 8;
        if ($allowSpike && $this->random->int(1, 1000) <= 7) $base *= $this->random->int(3, 8);
        return max(1, $base);
    }

    public function directionWeights(int $timestamp): array
    {
        $hour = (int) $this->format('G', $timestamp);
        if ($hour < 8 || $hour >= 18) return ['inbound' => 54, 'outbound' => 28, 'internal' => 18];
        if ($hour >= 12 && $hour < 13) return ['inbound' => 48, 'outbound' => 36, 'internal' => 16];
        return ['inbound' => 43, 'outbound' => 45, 'internal' => 12];
    }

    public function dispositionWeights(string $direction, int $timestamp): array
    {
        $hour = (int) $this->format('G', $timestamp);
        $afterHours = $hour < 8 || $hour >= 18;
        if ($direction === 'internal') return ['ANSWERED' => 82, 'NO ANSWER' => 11, 'BUSY' => 5, 'FAILED' => 2];
        if ($direction === 'inbound') {
            return $afterHours
                ? ['ANSWERED' => 42, 'NO ANSWER' => 38, 'BUSY' => 12, 'FAILED' => 8]
                : ['ANSWERED' => 76, 'NO ANSWER' => 13, 'BUSY' => 7, 'FAILED' => 4];
        }
        return $afterHours
            ? ['ANSWERED' => 58, 'NO ANSWER' => 20, 'BUSY' => 12, 'FAILED' => 10]
            : ['ANSWERED' => 70, 'NO ANSWER' => 16, 'BUSY' => 8, 'FAILED' => 6];
    }

    public function format(string $format, int $timestamp): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($this->timezone)->format($format);
    }

    private function realisticTimestamp(int $start, int $end): int
    {
        for ($attempt = 0; $attempt < 500; $attempt++) {
            $timestamp = $this->random->int($start, $end - 1);
            if ($this->random->int(1, 800) <= $this->weight($timestamp)) return $timestamp;
        }
        return $this->random->int($start, $end - 1);
    }
}
