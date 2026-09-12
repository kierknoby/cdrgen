<?php

namespace CdrGen\Database;

final class ProcessLock
{
    private $handle;
    private $path;

    private function __construct($handle, string $path)
    {
        $this->handle = $handle;
        $this->path = $path;
    }

    public static function acquire(string $path): self
    {
        $directory = dirname($path);
        StateDirectory::ensureSecure($directory);
        StateDirectory::assertRegularOrAbsent($path, 'process lock');
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open CDRgen process lock {$path}");
        }
        $metadata = fstat($handle);
        if ($metadata === false || (($metadata['mode'] & 0170000) !== 0100000)) {
            fclose($handle);
            throw new \RuntimeException("CDRgen process lock is not a regular file: {$path}");
        }
        if (function_exists('posix_geteuid') && $metadata['uid'] !== posix_geteuid()) {
            fclose($handle);
            throw new \RuntimeException("CDRgen process lock is not owned by the effective administrative user");
        }
        if (!chmod($path, 0600)) {
            fclose($handle);
            throw new \RuntimeException("Cannot secure CDRgen process lock permissions: {$path}");
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new \RuntimeException('Another live CDRgen process already holds the safety lock');
        }
        if (!ftruncate($handle, 0)
            || fwrite($handle, (string) getmypid() . "\n") === false
            || !fflush($handle)
        ) {
            flock($handle, LOCK_UN);
            fclose($handle);
            throw new \RuntimeException("Cannot update CDRgen process lock: {$path}");
        }
        return new self($handle, $path);
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
