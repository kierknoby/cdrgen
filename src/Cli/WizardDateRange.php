<?php

namespace CdrGen\Cli;

use CdrGen\TrafficProfile;

final class WizardDateRange
{
    public static function profileDefault(TrafficProfile $profile, int $now, string $timezone): array
    {
        $zone = new \DateTimeZone($timezone);
        $format = static function (int $timestamp) use ($zone): string {
            return (new \DateTimeImmutable('@' . $timestamp))
                ->setTimezone($zone)
                ->format('Y-m-d H:i:s');
        };

        return [
            'start' => $format($now - $profile->days() * 86400),
            'end' => $format($now),
        ];
    }

    private function __construct()
    {
    }
}
