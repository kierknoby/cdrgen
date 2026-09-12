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

            $this->pdo->commit();
            return $count;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function cleanup(string $accountcode): int
    {
        if (strpos($accountcode, 'CCTEST') !== 0) {
            throw new \InvalidArgumentException('Cleanup is restricted to an exact CCTEST accountcode');
        }

        $statement = $this->pdo->prepare('DELETE FROM cdr WHERE accountcode = :accountcode');
        $statement->execute([':accountcode' => $accountcode]);
        return $statement->rowCount();
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
}
