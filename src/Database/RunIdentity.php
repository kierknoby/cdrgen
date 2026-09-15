<?php

namespace CdrGen\Database;

final class RunIdentity
{
    /** Cryptographically random 56-bit suffix; not a mathematical uniqueness guarantee. */
    public static function randomAccountcode(): string
    {
        return AccountcodePolicy::random();
    }

    /** Kept as a compatibility alias for the first 1.1 development snapshot. */
    public static function uniqueAccountcode(): string
    {
        return self::randomAccountcode();
    }

    public static function deterministicAccountcode(string $datasetIdentity): string
    {
        return AccountcodePolicy::deterministic($datasetIdentity);
    }

    private function __construct()
    {
    }
}
