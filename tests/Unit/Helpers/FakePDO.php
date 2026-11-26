<?php

namespace Tests\Unit\Helpers;

use Exception;
use PDO;
use PDOStatement;

class FakePDO extends PDO
{
    public ?Exception $exceptionToThrow = null;

    /** When set, the exception is only thrown by statements whose SQL contains this. */
    public ?string $throwOnlyFor = null;

    public array $selectResults = [];

    /** @var array<string, list<object>> Rows returned by statements whose SQL contains the key. */
    public array $resultsByQuery = [];

    /** @var list<array{0: string, 1: list<mixed>}> Every statement with the values bound to it. */
    public array $bound = [];

    /** @var list<string> Every statement prepared, in order. */
    public array $preparedQueries = [];

    private bool $inTransaction = false;

    /** @noinspection PhpMissingParentConstructorInspection */
    public function __construct() {}

    public function prepare($query, $options = []): PDOStatement|false
    {
        $this->preparedQueries[] = $query;

        $statement = new FakePDOStatement;
        $statement->exceptionToThrow = $this->throwOnlyFor === null || str_contains($query, $this->throwOnlyFor)
            ? $this->exceptionToThrow
            : null;
        $statement->results = $this->selectResults;
        foreach ($this->resultsByQuery as $needle => $rows) {
            if (str_contains($query, $needle)) {
                $statement->results = $rows;
            }
        }
        $statement->log = &$this->bound;
        $statement->query = $query;

        return $statement;
    }

    public function beginTransaction(): bool
    {
        return $this->inTransaction = true;
    }

    public function commit(): bool
    {
        $this->inTransaction = false;

        return true;
    }

    public function rollBack(): bool
    {
        $this->inTransaction = false;

        return true;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }
}
