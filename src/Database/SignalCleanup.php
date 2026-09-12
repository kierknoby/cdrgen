<?php

namespace CdrGen\Database;

final class SignalCleanup
{
    public static function assertSupported(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            throw new \RuntimeException(
                'Live CDRgen requires the PHP pcntl extension for verified interruption cleanup'
            );
        }
    }

    public static function register(LiveRunGuard $guard, string $accountcode): void
    {
        self::assertSupported();
        $cleanup = static function () use ($guard, $accountcode): bool {
            try {
                $guard->emergencyCleanup();
                return true;
            } catch (\Throwable $error) {
                fwrite(
                    STDERR,
                    "\nCDRgen emergency cleanup failed for exact accountcode {$accountcode}: "
                    . $error->getMessage() . "\nRecovery record: " . $guard->recoveryPath() . "\n"
                );
                return false;
            }
        };

        register_shutdown_function($cleanup);
        pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
            pcntl_signal($signal, static function (int $received) use ($cleanup): void {
                $successful = $cleanup();
                exit($successful ? 128 + $received : 1);
            });
        }
    }

    private function __construct()
    {
    }
}
