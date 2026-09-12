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
}
