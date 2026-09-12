<?php

namespace CdrGen;

use CdrGen\Random\RandomSource;

final class GenerationRequest
{
    private $profile;
    private $start;
    private $end;
    private $rows;
    private $random;
    private $extensions;
    private $extensionNames;
    private $trunks;
    private $technologies;
    private $accountcode;
    private $timezone;

    public function __construct(
        TrafficProfile $profile,
        int $start,
        int $end,
        RandomSource $random,
        array $extensions,
        array $trunks,
        array $options = []
    ) {
        if ($start >= $end) {
            throw new \InvalidArgumentException('Start must be earlier than end');
        }
        if (count($extensions) < 2) {
            throw new \InvalidArgumentException('At least two extensions are required');
        }
        if ($trunks === []) {
            throw new \InvalidArgumentException('At least one trunk is required');
        }

        $this->profile = $profile;
        $this->start = $start;
        $this->end = $end;
        $this->random = $random;
        $this->rows = (int) ($options['rows'] ?? $profile->rows());
        if ($this->rows < 1) {
            throw new \InvalidArgumentException('Rows must be positive');
        }
        $this->extensions = array_values(array_map('strval', $extensions));
        $this->extensionNames = $options['extension_names'] ?? [];
        $this->trunks = array_values($trunks);
        $this->technologies = array_values($options['technologies'] ?? ['PJSIP', 'SIP']);
        if ($this->technologies === []) {
            throw new \InvalidArgumentException('At least one endpoint technology is required');
        }
        $this->accountcode = (string) ($options['accountcode'] ?? 'CCTESTFIXTURE');
        $this->timezone = (string) ($options['timezone'] ?? 'UTC');
        new \DateTimeZone($this->timezone);
    }

    public function profile(): TrafficProfile
    {
        return $this->profile;
    }

    public function start(): int
    {
        return $this->start;
    }

    public function end(): int
    {
        return $this->end;
    }

    public function rows(): int
    {
        return $this->rows;
    }

    public function random(): RandomSource
    {
        return $this->random;
    }

    public function extensions(): array
    {
        return $this->extensions;
    }

    public function extensionNames(): array
    {
        return $this->extensionNames;
    }

    public function trunks(): array
    {
        return $this->trunks;
    }

    public function technologies(): array
    {
        return $this->technologies;
    }

    public function accountcode(): string
    {
        return $this->accountcode;
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    public function deterministicInputs(): array
    {
        return [
            'profile' => $this->profile->name(),
            'start' => $this->start,
            'end' => $this->end,
            'rows' => $this->rows,
            'random' => $this->random->identity(),
            'extensions' => $this->extensions,
            'extension_names' => $this->extensionNames,
            'trunks' => $this->trunks,
            'technologies' => $this->technologies,
            'accountcode' => $this->accountcode,
            'timezone' => $this->timezone,
        ];
    }

    public function datasetIdentityInputs(): array
    {
        $inputs = $this->deterministicInputs();
        unset($inputs['accountcode']);
        $inputs['core_version'] = Version::VERSION;
        return $inputs;
    }

    public function datasetIdentity(): string
    {
        return hash('sha256', json_encode($this->datasetIdentityInputs(), JSON_UNESCAPED_SLASHES));
    }
}
