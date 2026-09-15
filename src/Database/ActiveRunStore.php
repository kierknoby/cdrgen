<?php

namespace CdrGen\Database;

final class ActiveRunStore
{
    private $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function write(string $accountcode, bool $retain): void
    {
        AccountcodePolicy::assertValid($accountcode);
        $record = [
            'format' => 1,
            'accountcode' => $accountcode,
            'retain' => $retain,
            'created_at' => gmdate('c'),
        ];
        $record['checksum'] = $this->checksum($record);
        $directory = dirname($this->path);
        StateDirectory::ensureSecure($directory);
        StateDirectory::assertRegularOrAbsent($this->path, 'recovery record');
        if (file_exists($this->path)) {
            throw new \RuntimeException(
                "Active CDRgen recovery record already exists and must be recovered first: {$this->path}"
            );
        }
        $temporary = $this->path . '.tmp.' . bin2hex(random_bytes(4));
        $encoded = json_encode($record, JSON_UNESCAPED_SLASHES) . "\n";
        $handle = fopen($temporary, 'x+b');
        if ($handle === false) {
            throw new \RuntimeException("Cannot create CDRgen recovery record temporary file {$temporary}");
        }
        $written = fwrite($handle, $encoded);
        $flushed = $written === strlen($encoded) && fflush($handle);
        $synced = !function_exists('fsync') || ($flushed && fsync($handle));
        fclose($handle);
        $secured = chmod($temporary, 0600);
        if (!$flushed || !$synced || !$secured) {
            $this->failAfterTemporaryFile(
                "Cannot write CDRgen recovery record {$this->path}",
                $temporary
            );
        }
        if (!rename($temporary, $this->path)) {
            $this->failAfterTemporaryFile(
                "Cannot activate CDRgen recovery record {$this->path}",
                $temporary
            );
        }
        StateDirectory::assertRegularOrAbsent($this->path, 'recovery record');
        $this->assertSecurePermissions();
    }

    public function read(): ?array
    {
        StateDirectory::ensureSecure(dirname($this->path));
        StateDirectory::assertRegularOrAbsent($this->path, 'recovery record');
        if (!file_exists($this->path)) {
            return null;
        }
        $this->assertSecurePermissions();
        $contents = file_get_contents($this->path);
        $record = is_string($contents) ? json_decode($contents, true) : null;
        if (!is_array($record)
            || ($record['format'] ?? null) !== 1
            || !isset($record['accountcode'], $record['retain'], $record['created_at'], $record['checksum'])
            || !is_bool($record['retain'])
            || !is_string($record['accountcode'])
            || !is_string($record['created_at'])
            || \DateTimeImmutable::createFromFormat(DATE_ATOM, $record['created_at']) === false
            || !is_string($record['checksum'])
            || preg_match('/^[0-9a-f]{64}$/D', $record['checksum']) !== 1
            || count($record) !== 5
        ) {
            throw new \RuntimeException("Invalid CDRgen recovery record {$this->path}; refusing to continue");
        }
        AccountcodePolicy::assertValid((string) $record['accountcode']);
        $checksum = $record['checksum'];
        unset($record['checksum']);
        if (!hash_equals((string) $checksum, $this->checksum($record))) {
            throw new \RuntimeException("CDRgen recovery record checksum failed for {$this->path}");
        }
        $record['checksum'] = $checksum;
        return $record;
    }

    public function clear(): void
    {
        StateDirectory::ensureSecure(dirname($this->path));
        StateDirectory::assertRegularOrAbsent($this->path, 'recovery record');
        if (file_exists($this->path)) {
            $this->assertSecurePermissions();
        }
        if (file_exists($this->path) && !unlink($this->path)) {
            throw new \RuntimeException("Cannot remove CDRgen recovery record {$this->path}");
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    private function checksum(array $record): string
    {
        return hash('sha256', json_encode($record, JSON_UNESCAPED_SLASHES));
    }

    private function assertSecurePermissions(): void
    {
        clearstatcache(true, $this->path);
        if ((fileperms($this->path) & 0777) !== 0600) {
            throw new \RuntimeException("CDRgen recovery record permissions are not 0600: {$this->path}");
        }
    }

    private function failAfterTemporaryFile(string $primaryFailure, string $temporary): void
    {
        $cleanupFailure = '';
        if (file_exists($temporary) && !unlink($temporary)) {
            $cleanupFailure = "; additionally failed to remove temporary recovery file {$temporary}";
        }
        throw new \RuntimeException($primaryFailure . $cleanupFailure);
    }
}
