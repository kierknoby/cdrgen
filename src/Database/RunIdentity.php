<?php

namespace CdrGen\Database;

final class RunIdentity
{
    /** Cryptographically random 64-bit suffix; not a mathematical uniqueness guarantee. */
    public static function randomAccountcode(): string
    {
        return 'CCTEST' . bin2hex(random_bytes(8));
    }

    /** Kept as a compatibility alias for the first 1.1 development snapshot. */
    public static function uniqueAccountcode(): string
    {
        return self::randomAccountcode();
    }

    public static function deterministicAccountcode(string $datasetIdentity): string
    {
        return 'CCTEST' . substr(hash('sha256', 'fixture:' . $datasetIdentity), 0, 16);
    }

    private function __construct()
    {
    }
}
