<?php

namespace Tests\Unit\Helpers;

use Exception;
use PDO;
use PDOStatement;

class FakePDOStatement extends PDOStatement
{
    public ?Exception $exceptionToThrow = null;

    public array $results = [];

    public string $query = '';

    /** @var list<array{0: string, 1: list<mixed>}> */
    public array $log = [];

    private array $values = [];

    public function __construct() {}

    public function bindValue($param, $value, $type = PDO::PARAM_STR): bool
    {
        $this->values[] = $value;

        return true;
    }

    public function execute(?array $params = null): bool
    {
        if ($this->exceptionToThrow) {
            throw $this->exceptionToThrow;
        }

        $this->log[] = [$this->query, $this->values];

        return true;
    }

    public function rowCount(): int
    {
        return 1;
    }

    public function fetchAll(int $mode = PDO::FETCH_OBJ, ...$args): array
    {
        return $this->results;
    }

    public function setFetchMode($mode, ...$args): true
    {
        return true;
    }
}