<?php

namespace CdrGen;

final class GenerationResult
{
    private $rows;
    private $profile;
    private $identity;
    private $statistics;
    private $metadata;

    public function __construct(array $rows, string $profile, string $identity, array $metadata = [])
    {
        $this->rows = $rows;
        $this->profile = $profile;
        $this->identity = $identity;
        $this->statistics = $this->deriveStatistics($rows);
        $this->metadata = $metadata;
    }

    public function rows(): array
    {
        return $this->rows;
    }

    public function profile(): string
    {
        return $this->profile;
    }

    public function datasetIdentity(): string
    {
        return $this->identity;
    }

    public function statistics(): array
    {
        return $this->statistics;
    }

    public function metadata(): array
    {
        return $this->metadata;
    }

    public function version(): string
    {
        return Version::VERSION;
    }

    private function deriveStatistics(array $rows): array
    {
        $statistics = [
            'directions' => [],
            'dispositions' => [],
            'trunks' => [],
            'inbound_types' => [],
        ];
        foreach ($rows as $row) {
            $this->increment($statistics['directions'], $row['direction']);
            $this->increment($statistics['dispositions'], $row['disposition']);
            if ($row['trunk'] !== '') {
                $this->increment($statistics['trunks'], $row['trunk']);
            }
            if ($row['direction'] === 'inbound') {
                $this->increment($statistics['inbound_types'], $row['_inbound_type']);
            }
        }
        foreach ($statistics as &$values) {
            ksort($values);
        }
        return $statistics;
    }

    private function increment(array &$values, string $name): void
    {
        $values[$name] = ($values[$name] ?? 0) + 1;
    }
}
