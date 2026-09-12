<?php

namespace CdrGen\Database;

final class TransactionalStoragePolicy
{
    public static function assertSupported(string $driver, ?string $engine = null): void
    {
        $driver = strtolower(trim($driver));
        if ($driver === 'sqlite') {
            return;
        }
        if ($driver !== 'mysql') {
            throw new \RuntimeException(
                "Unsupported PDO driver '{$driver}'; CDRgen cannot establish transactional rollback safety"
            );
        }

        $detected = $engine === null || trim($engine) === '' ? '<unknown>' : trim($engine);
        if (strcasecmp($detected, 'InnoDB') !== 0) {
            throw new \RuntimeException(
                "Detected cdr table engine {$detected}; CDRgen requires InnoDB for transactional rollback safety"
            );
        }
    }
}
