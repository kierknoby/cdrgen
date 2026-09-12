<?php

namespace CdrGen\Random;

final class SeededRandomSource implements RandomSource
{
    private $stream;
    private $seed;

    public function __construct(int $seed)
    {
        $this->seed = $seed;
        $this->stream = new HashStreamRandomSource('integer-seed:' . $seed);
    }

    public function int(int $min, int $max): int
    {
        return $this->stream->int($min, $max);
    }

    public function identity(): string
    {
        return 'seed:' . $this->seed;
    }

    public function fork(string $domain): RandomSource
    {
        return $this->stream->fork($domain);
    }
}
