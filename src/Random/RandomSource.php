<?php
namespace CdrGen\Random;

interface RandomSource
{
    public function int(int $min, int $max): int;
    public function identity(): string;
    public function fork(string $domain): RandomSource;
}
