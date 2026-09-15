<?php

namespace CdrGen\Database;

final class StateDirectory
{
    public static function ensureSecure(string $directory): void
    {
        if (is_link($directory)) {
            throw new \RuntimeException("CDRgen state directory must not be a symlink: {$directory}");
        }
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new \RuntimeException("Cannot create CDRgen state directory {$directory}");
            }
        }
        clearstatcache(true, $directory);
        $metadata = lstat($directory);
        if ($metadata === false || (($metadata['mode'] & 0170000) !== 0040000)) {
            throw new \RuntimeException("CDRgen state path is not a regular directory: {$directory}");
        }
        $permissions = $metadata['mode'] & 0777;
        if ($permissions !== 0700) {
            throw new \RuntimeException(
                sprintf('CDRgen state directory %s must have mode 0700, found %04o', $directory, $permissions)
            );
        }
        $isSystemState = rtrim($directory, '/') === '/var/lib/cdrgen';
        if ($isSystemState && ($metadata['uid'] !== 0 || $metadata['gid'] !== 0)) {
            throw new \RuntimeException(
                'CDRgen system state directory /var/lib/cdrgen must be owned by root:root'
            );
        }
        if ($isSystemState && function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            throw new \RuntimeException('Live CDRgen must run as root to access trusted system state');
        }
        if (!$isSystemState && function_exists('posix_geteuid') && $metadata['uid'] !== posix_geteuid()) {
            throw new \RuntimeException(
                "CDRgen state directory {$directory} must be owned by the effective administrative user"
            );
        }
    }

    public static function assertRegularOrAbsent(string $path, string $description): void
    {
        clearstatcache(true, $path);
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path)) {
            throw new \RuntimeException("CDRgen {$description} must not be a symlink: {$path}");
        }
        $metadata = lstat($path);
        if ($metadata === false || (($metadata['mode'] & 0170000) !== 0100000)) {
            throw new \RuntimeException("CDRgen {$description} is not a regular file: {$path}");
        }
        if (function_exists('posix_geteuid') && $metadata['uid'] !== posix_geteuid()) {
            throw new \RuntimeException("CDRgen {$description} is not owned by the effective administrative user");
        }
    }

    private function __construct()
    {
    }
}
