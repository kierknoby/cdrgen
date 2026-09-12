<?php
namespace CdrGen\Random;

/** Platform-stable SHA-256 counter stream. The identity is never reduced to machine-sized state. */
final class HashStreamRandomSource implements RandomSource
{
    private $identity;
    private $counter = 0;
    private $buffer = '';

    public function __construct(string $identity)
    {
        if ($identity === '') {
            throw new \InvalidArgumentException('Random identity must not be empty');
        }
        $this->identity = $identity;
    }

    public function identity(): string
    {
        return $this->identity;
    }

    public function fork(string $domain): RandomSource
    {
        if ($domain === '') {
            throw new \InvalidArgumentException('Random substream domain must not be empty');
        }
        return new self($this->identity . "\0substream:" . $domain);
    }

    public function int(int $min, int $max): int
    {
        if ($min > $max) {
            throw new \InvalidArgumentException('Minimum exceeds maximum');
        }
        $range = $max - $min + 1;
        if ($range === 1) {
            return $min;
        }
        if ($range < 1 || $range > 0x7fffffff) {
            throw new \InvalidArgumentException('Random range must be between 1 and 2147483647');
        }
        $limit = intdiv(0x100000000, $range) * $range;
        do {
            $bytes = $this->bytes(4);
            $value = (ord($bytes[0]) * 0x1000000) + (ord($bytes[1]) * 0x10000)
                + (ord($bytes[2]) * 0x100) + ord($bytes[3]);
        } while ($value >= $limit);
        return $min + ($value % $range);
    }

    private function bytes(int $length): string
    {
        while (strlen($this->buffer) < $length) {
            $counter = sprintf('%08x%08x', intdiv($this->counter, 0x100000000), $this->counter % 0x100000000);
            $this->buffer .= hash('sha256', "cdrgen-random-v1\0" . $this->identity . "\0" . $counter, true);
            $this->counter++;
        }
        $out = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);
        return $out;
    }
}
