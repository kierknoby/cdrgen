<?php

namespace CdrGen;

final class TrafficProfile
{
    private const DEFINITIONS = [
        'light' => ['rows' => 1000, 'days' => 1, 'min' => 15, 'max' => 720],
        'medium' => ['rows' => 5000, 'days' => 1, 'min' => 15, 'max' => 1200],
        'heavy' => ['rows' => 20000, 'days' => 1, 'min' => 15, 'max' => 2400],
    ];

    private $name;
    private $definition;

    private function __construct(string $name, array $definition)
    {
        $this->name = $name;
        $this->definition = $definition;
    }

    public static function named(string $name): self
    {
        if (!isset(self::DEFINITIONS[$name])) {
            throw new \InvalidArgumentException('Unknown traffic profile: ' . $name);
        }
        return new self($name, self::DEFINITIONS[$name]);
    }

    public static function definitions(): array
    {
        return self::DEFINITIONS;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function rows(): int
    {
        return $this->definition['rows'];
    }

    public function days(): int
    {
        return $this->definition['days'];
    }

    public function minimumDuration(): int
    {
        return $this->definition['min'];
    }

    public function maximumDuration(): int
    {
        return $this->definition['max'];
    }
}
