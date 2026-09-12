<?php

namespace CdrGen\Database;

final class CdrRepository
{
    private $pdo;
    private $metadata;
    private $mapper;

    public function __construct(\PDO $pdo, array $metadata, ?SchemaMapper $mapper = null)
    {
        $this->pdo = $pdo;
        $this->metadata = $metadata;
        $this->mapper = $mapper ?? new SchemaMapper();
    }

    /**
     * Supports varying row projections by preparing one statement per exact shape.
     * Omitted nullable/defaulted values remain omitted rather than being bound as NULL.
     */
    public function insertAll(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $this->mapper->assertAccountcodeCapacity($this->metadata);
        $accountcode = $this->uniformAccountcode($rows);
        AccountcodePolicy::assertValid($accountcode);
        $this->assertTransactionalStorage();
        if ($this->countExact($accountcode) !== 0) {
            throw new \RuntimeException("Refusing to insert over existing exact accountcode {$accountcode}");
        }

        $statements = [];
        $count = 0;
        $this->pdo->beginTransaction();

        try {
            foreach ($rows as $rowNumber => $row) {
                $projection = $this->mapper->projection($this->metadata, $row);
                $columns = array_keys($projection);
                $shape = implode("\0", $columns);

                if (!isset($statements[$shape])) {
                    $statements[$shape] = $this->prepareInsert($columns);
                }

                $parameters = [];
                foreach ($projection as $column => $value) {
                    $parameters[':' . $column] = $value;
                }

                $statements[$shape]->execute($parameters);
                $count++;
            }

            $verified = $this->countExact($accountcode);
            if ($verified !== count($rows)) {
                throw new \RuntimeException(
                    "Exact accountcode verification failed before commit for {$accountcode}: "
                    . 'expected ' . count($rows) . ", found {$verified}; transaction rolled back"
                );
            }
            $marked = $this->countExactMarker($accountcode);
            if ($marked !== count($rows)) {
                throw new \RuntimeException(
                    "Byte-exact userfield marker verification failed before commit for {$accountcode}: "
                    . 'expected ' . count($rows) . ", found {$marked}; transaction rolled back"
                );
            }
            $this->pdo->commit();
            return $count;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function assertSchemaSafe(): void
    {
        $this->mapper->assertAccountcodeCapacity($this->metadata);
        $this->mapper->assertRecoveryMarkerCapacity($this->metadata);
    }

    public function assertTransactionalStorage(): void
    {
        try {
            $driver = (string) $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        } catch (\Throwable $error) {
            throw new \RuntimeException(
                'Cannot determine PDO driver; CDRgen cannot establish transactional rollback safety',
                0,
                $error
            );
        }
        if (strtolower(trim($driver)) === 'sqlite') {
            TransactionalStoragePolicy::assertSupported($driver);
            return;
        }
        if (strtolower(trim($driver)) !== 'mysql') {
            TransactionalStoragePolicy::assertSupported($driver);
            return;
        }

        try {
            $statement = $this->pdo->query(
                "SELECT ENGINE FROM information_schema.TABLES "
                . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cdr'"
            );
            $engines = $statement->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable $error) {
            throw new \RuntimeException(
                'Cannot determine cdr table engine; CDRgen requires InnoDB for transactional rollback safety',
                0,
                $error
            );
        }
        if (count($engines) !== 1 || !is_string($engines[0])) {
            $engine = count($engines) > 1 ? '<multiple rows>' : null;
        } else {
            $engine = $engines[0];
        }
        TransactionalStoragePolicy::assertSupported($driver, $engine);
    }

    public function cleanup(string $accountcode): int
    {
        AccountcodePolicy::assertValid($accountcode);

        $statement = $this->pdo->prepare('DELETE FROM cdr WHERE HEX(accountcode) = :accountcode_hex');
        $statement->execute([':accountcode_hex' => strtoupper(bin2hex($accountcode))]);
        $deleted = $statement->rowCount();
        $remaining = $this->countExact($accountcode);
        if ($remaining !== 0) {
            throw new \RuntimeException(
                "Cleanup verification failed for {$accountcode}: {$remaining} rows remain"
            );
        }
        return $deleted;
    }

    public function countExact(string $accountcode): int
    {
        AccountcodePolicy::assertValid($accountcode);
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM cdr WHERE HEX(accountcode) = :accountcode_hex');
        $statement->execute([':accountcode_hex' => strtoupper(bin2hex($accountcode))]);
        return (int) $statement->fetchColumn();
    }

    private function countExactMarker(string $accountcode): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM cdr WHERE HEX(accountcode) = :accountcode_hex '
            . 'AND HEX(SUBSTR(userfield, 1, 7)) = :marker_hex'
        );
        $statement->execute([
            ':accountcode_hex' => strtoupper(bin2hex($accountcode)),
            ':marker_hex' => strtoupper(bin2hex('cdrgen ')),
        ]);
        return (int) $statement->fetchColumn();
    }

    public function generatedCounts(): array
    {
        $statement = $this->pdo->query(
            "SELECT accountcode, COUNT(*) AS row_count FROM cdr "
            . "WHERE accountcode LIKE 'CCTEST%' GROUP BY accountcode ORDER BY accountcode"
        );
        $counts = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['accountcode']] = (int) $row['row_count'];
        }
        return $counts;
    }

    public function assertNoGeneratedRows(): void
    {
        $counts = $this->generatedCounts();
        if ($counts !== []) {
            $parts = [];
            foreach ($counts as $accountcode => $count) {
                $parts[] = "{$accountcode}={$count}";
            }
            throw new \RuntimeException(
                'Unexpected existing CDRgen rows; refusing live run: ' . implode(', ', $parts)
            );
        }
    }

    /**
     * Recovers database-orphaned runs after loss of the filesystem sidecar.
     * Every row must have both the strict new-format accountcode and CDRgen userfield marker.
     */
    public function recoverRecognizedGeneratedRuns(): array
    {
        if (!$this->hasColumn('userfield')) {
            return [];
        }
        $recovered = [];
        foreach ($this->generatedCounts() as $accountcode => $total) {
            if (!AccountcodePolicy::isValid($accountcode)) {
                continue;
            }
            $marked = $this->countExactMarker($accountcode);
            if ($marked !== $total) {
                continue;
            }
            $this->cleanup($accountcode);
            $recovered[$accountcode] = $total;
        }
        return $recovered;
    }

    private function prepareInsert(array $columns): \PDOStatement
    {
        $quoted = array_map(static function (string $column): string {
            return '`' . str_replace('`', '``', $column) . '`';
        }, $columns);
        $placeholders = array_map(static function (string $column): string {
            return ':' . $column;
        }, $columns);

        return $this->pdo->prepare(
            'INSERT INTO cdr (' . implode(', ', $quoted) . ') VALUES (' . implode(', ', $placeholders) . ')'
        );
    }

    private function uniformAccountcode(array $rows): string
    {
        $accountcode = null;
        foreach ($rows as $index => $row) {
            if (!isset($row['accountcode']) || !is_string($row['accountcode'])) {
                throw new \RuntimeException("Generated row {$index} has no string accountcode");
            }
            if ($accountcode === null) {
                $accountcode = $row['accountcode'];
            } elseif ($row['accountcode'] !== $accountcode) {
                throw new \RuntimeException('All rows in one transaction must use one exact accountcode');
            }
        }
        return (string) $accountcode;
    }

    private function hasColumn(string $name): bool
    {
        foreach ($this->metadata as $column) {
            if (($column['Field'] ?? '') === $name) {
                return true;
            }
        }
        return false;
    }
}
