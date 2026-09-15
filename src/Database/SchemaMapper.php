<?php

namespace CdrGen\Database;

final class SchemaMapper
{
    public function projection(array $metadata, array $row): array
    {
        $projection = [];

        foreach ($metadata as $column) {
            $name = $column['Field'];
            if ($this->isAutoIncrement($column)) {
                continue;
            }

            if (array_key_exists($name, $row)) {
                $this->assertRepresentable($name, $column, $row[$name]);
                $projection[$name] = $row[$name];
                continue;
            }

            $nullable = ($column['Null'] ?? 'NO') === 'YES';
            $defaulted = array_key_exists('Default', $column) && $column['Default'] !== null;
            if ($nullable || $defaulted) {
                // Omit the column so its database default applies (or NULL is selected).
                continue;
            }

            throw new \RuntimeException(
                "Unsupported mandatory cdr column '{$name}' has no generated value, default, or NULL allowance"
            );
        }

        if (!array_key_exists('accountcode', $projection)) {
            throw new \RuntimeException('cdr table must contain a writable accountcode column');
        }

        return $projection;
    }

    private function isAutoIncrement(array $metadata): bool
    {
        return stripos((string) ($metadata['Extra'] ?? ''), 'auto_increment') !== false;
    }

    public function assertAccountcodeCapacity(array $metadata): void
    {
        foreach ($metadata as $column) {
            if (($column['Field'] ?? '') !== 'accountcode') {
                continue;
            }
            $length = $this->stringLength($column);
            if ($length === null) {
                throw new \RuntimeException('accountcode must be a bounded CHAR or VARCHAR column');
            }
            if ($length < AccountcodePolicy::LENGTH) {
                throw new \RuntimeException(
                    "accountcode column length {$length} cannot safely hold the required "
                    . AccountcodePolicy::LENGTH . '-character CDRgen identity'
                );
            }
            return;
        }
        throw new \RuntimeException('cdr table does not contain accountcode column');
    }

    public function assertRecoveryMarkerCapacity(array $metadata): void
    {
        foreach ($metadata as $column) {
            if (($column['Field'] ?? '') !== 'userfield') {
                continue;
            }
            $type = (string) ($column['Type'] ?? '');
            $length = $this->stringLength($column);
            if ($length !== null && $length >= strlen('cdrgen ')) {
                return;
            }
            if (preg_match('/^(?:tiny|medium|long)?text\b/i', $type) === 1) {
                return;
            }
            throw new \RuntimeException(
                'cdr.userfield cannot safely hold the required CDRgen recovery marker'
            );
        }
        throw new \RuntimeException(
            'cdr table must provide userfield so power-loss recovery can corroborate CDRgen ownership'
        );
    }

    private function assertRepresentable(string $name, array $metadata, $value): void
    {
        if ($value === null || !is_string($value)) {
            return;
        }
        $length = $this->stringLength($metadata);
        // SHOW COLUMNS does not expose enough charset detail to prove character
        // representability. Byte length is deliberately conservative and fail-closed.
        if ($length !== null && strlen($value) > $length) {
            throw new \RuntimeException(
                "Value for cdr.{$name} is " . strlen($value)
                . " bytes but schema permits only {$length}; refusing possible truncation"
            );
        }
    }

    private function stringLength(array $metadata): ?int
    {
        $type = (string) ($metadata['Type'] ?? '');
        if (preg_match('/^(?:var)?char\((\d+)\)/i', $type, $matches) !== 1) {
            return null;
        }
        return (int) $matches[1];
    }
}
