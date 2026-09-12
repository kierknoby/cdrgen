<?php

namespace CdrGen\Database;

final class AccountcodePolicy
{
    public const PREFIX = 'CCTEST';
    public const HEX_LENGTH = 14;
    public const LENGTH = 20;

    public static function random(): string
    {
        return self::PREFIX . substr(bin2hex(random_bytes(7)), 0, self::HEX_LENGTH);
    }

    public static function deterministic(string $datasetIdentity): string
    {
        return self::PREFIX . substr(hash('sha256', 'fixture:' . $datasetIdentity), 0, self::HEX_LENGTH);
    }

    public static function isValid(string $accountcode): bool
    {
        return preg_match('/^CCTEST[0-9a-f]{14}$/D', $accountcode) === 1;
    }

    public static function assertValid(string $accountcode): void
    {
        if (!self::isValid($accountcode)) {
            throw new \InvalidArgumentException(
                'CDRgen accountcode must be CCTEST followed by exactly 14 lowercase hexadecimal characters'
            );
        }
    }

    private function __construct()
    {
    }
}
