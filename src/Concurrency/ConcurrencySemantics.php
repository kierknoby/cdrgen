<?php

namespace CdrGen\Concurrency;

final class ConcurrencySemantics
{
    public const CDR = 'cdr';
    public const ANSWERED_MEDIA = 'answered';

    public static function validate(string $value): string
    {
        if (!in_array($value, [self::CDR, self::ANSWERED_MEDIA], true)) {
            throw new \InvalidArgumentException('Concurrency semantics must be cdr or answered');
        }
        return $value;
    }

    private function __construct()
    {
    }
}
