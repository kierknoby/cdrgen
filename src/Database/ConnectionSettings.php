<?php

namespace CdrGen\Database;

final class ConnectionSettings
{
    public static function cdr(array $configuration): array
    {
        return [
            'host' => $configuration['CDRDBHOST'] ?? $configuration['AMPDBHOST'] ?? 'localhost',
            'port' => $configuration['CDRDBPORT'] ?? $configuration['AMPDBPORT'] ?? null,
            'database' => $configuration['CDRDBNAME'] ?? 'asteriskcdrdb',
            'user' => $configuration['CDRDBUSER'] ?? $configuration['AMPDBUSER'] ?? 'asteriskuser',
            'password' => $configuration['CDRDBPASS'] ?? $configuration['AMPDBPASS'] ?? '',
        ];
    }

    public static function config(array $configuration): array
    {
        return [
            'host' => $configuration['AMPDBHOST'] ?? 'localhost',
            'port' => $configuration['AMPDBPORT'] ?? null,
            'database' => $configuration['AMPDBNAME'] ?? 'asterisk',
            'user' => $configuration['AMPDBUSER'] ?? 'asteriskuser',
            'password' => $configuration['AMPDBPASS'] ?? '',
        ];
    }

    public static function dsn(array $settings): string
    {
        $dsn = "mysql:host={$settings['host']};dbname={$settings['database']};charset=utf8mb4";
        if ($settings['port'] !== null && $settings['port'] !== '') {
            $dsn .= ';port=' . $settings['port'];
        }
        return $dsn;
    }

    private function __construct()
    {
    }
}
