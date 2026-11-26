<?php

namespace Easi\DB2\Database\Query\Processors;

use Easi\DB2\Support\ConnectionGuard;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\Processor;
use Easi\DB2\Database\Query\Grammars\DB2Grammar;

/**
 * Class DB2Processor
 *
 * @package Easi\DB2\Database\Query\Processors
 */
class DB2Processor extends Processor
{
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Process an "insert get ID" query.
     *
     * @param Builder $query
     * @param string $sql
     * @param array $values
     * @param string|array|null $sequence
     *
     * @return int|string|array
     */
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null): int|string|array
    {
        $sequenceStr = $sequence ?: 'id';

        if (is_array($sequence)) {
            $grammar = new DB2Grammar(ConnectionGuard::assertConcrete($query->getConnection()));
            $sequenceStr = $grammar->columnize($sequence);
        }

        $sqlStr = 'select %s from new table (%s)';

        $finalSql = sprintf($sqlStr, $sequenceStr, $sql);
        $results = $query->getConnection()
                         ->select($finalSql, $values);

        $result = (array) $results[0];

        if (is_array($sequence)) {
            $ids = [];
            foreach ($sequence as $column) {
                $ids[$column] = $this->resolveColumn($result, $column);
            }

            return $ids;
        } else {
            $id = $this->resolveColumn($result, $sequenceStr);

            return is_numeric($id) ? (int) $id : $id;
        }
    }

    /**
     * Look up a column in a result row, falling back to its uppercased name.
     *
     * @param array $result
     * @param string $column
     * @return mixed
     */
    private function resolveColumn(array $result, string $column): mixed
    {
        return $result[$column] ?? $result[strtoupper($column)];
    }

    /**
     * Process the results of a "select" query.
     *
     * @param Builder $query
     * @param  array  $results
     * @return array
     */
    public function processSelect(Builder $query, $results): array
    {
        return array_map(function ($row) {
            $columns = array_map(function ($el) {
                if (! is_string($el)) {
                    return $el;
                }

                $el = trim($el);

                if (isset($this->config['from_encoding'])) {
                    return iconv($this->config['from_encoding'], 'utf-8', $el);
                }

                return $el;
            }, (array) $row);

            // Preserve the shape PDO handed us. The connection's fetch mode isn't
            // fixed (callers can pass a $fetchUsing mode to select()), so rows may
            // arrive as objects or arrays; the (array) cast above — needed to map
            // over the columns — is undone only when the original row was an object.
            return is_object($row) ? (object) $columns : $columns;
        }, $results);
    }

    /**
     * Process the results of a schemas query.
     *
     * @param list<array<string, mixed>|object> $results
     * @return list<array{name: string, path: string|null, default: bool}>
     */
    public function processSchemas($results)
    {
        return array_map(function ($result) {
            $row = $this->catalogRow($result);

            return [
                'name' => $this->catalogName($row['name']),
                'path' => null,
                'default' => (bool) ($row['default'] ?? false),
            ];
        }, $results);
    }

    /**
     * Process the results of a tables query.
     *
     * @param list<array<string, mixed>|object> $results
     * @return list<array{name: string, schema: string|null, schema_qualified_name: string, size: int|null, comment: string|null, collation: string|null, engine: string|null}>
     */
    public function processTables($results)
    {
        return array_map(function ($result) {
            $row = $this->catalogRow($result);
            $name = $this->catalogName($row['name']);
            $schema = isset($row['schema']) ? $this->catalogName($row['schema']) : null;

            return [
                'name' => $name,
                'schema' => $schema,
                'schema_qualified_name' => $schema !== null ? $schema . '.' . $name : $name,
                'size' => isset($row['size']) ? (int) $row['size'] : null,
                'comment' => $this->catalogText($row['comment'] ?? null),
                'collation' => null,
                'engine' => null,
            ];
        }, $results);
    }

    /**
     * Process the results of a views query.
     *
     * @param list<array<string, mixed>|object> $results
     * @return list<array{name: string, schema: string|null, schema_qualified_name: string, definition: string}>
     */
    public function processViews($results)
    {
        return array_map(function ($result) {
            $row = $this->catalogRow($result);
            $name = $this->catalogName($row['name']);
            $schema = isset($row['schema']) ? $this->catalogName($row['schema']) : null;

            return [
                'name' => $name,
                'schema' => $schema,
                'schema_qualified_name' => $schema !== null ? $schema . '.' . $name : $name,
                'definition' => trim((string) ($row['definition'] ?? '')),
            ];
        }, $results);
    }

    /**
     * Process the results of a columns query.
     *
     * @param list<array<string, mixed>|object> $results
     * @return list<array{name: string, type: string, type_name: string, collation: string|null, nullable: bool, default: mixed, auto_increment: bool, comment: string|null, generation: array{type: string|null, expression: string|null}|null}>
     */
    public function processColumns($results)
    {
        return array_map(function ($result) {
            $row = $this->catalogRow($result);
            $typeName = $this->catalogTypeName((string) ($row['type_name'] ?? ''));
            $default = $this->catalogText($row['column_default'] ?? null);

            return [
                'name' => $this->catalogName($row['name']),
                'type_name' => $typeName,
                'type' => $this->catalogColumnType($typeName, $row['length'] ?? null, $row['numeric_scale'] ?? null),
                'collation' => null,
                'nullable' => $this->catalogFlag($row['is_nullable'] ?? null, 'Y'),
                'default' => $default !== null && strcasecmp($default, 'NULL') === 0 ? null : $default,
                'auto_increment' => $this->catalogFlag($row['is_identity'] ?? null, 'YES'),
                'comment' => $this->catalogText($row['comment'] ?? null),
                'generation' => null,
            ];
        }, $results);
    }

    /**
     * Process the results of an indexes query.
     *
     * Rows are one key column each, from SQL indexes (kind I) and from primary key (P) and
     * unique (U) constraints. An index that repeats the columns of a unique key constraint is
     * the access path IBM i builds for that constraint, so the constraint wins.
     *
     * @param list<array<string, mixed>|object> $results
     * @return list<array{name: string, columns: list<string>, type: string|null, unique: bool, primary: bool}>
     */
    public function processIndexes($results)
    {
        $groups = [];

        foreach ($results as $result) {
            $row = $this->catalogRow($result);

            if (! isset($row['column_name'])) {
                continue;
            }

            $kind = strtoupper(trim((string) ($row['kind'] ?? 'I')));
            $name = $this->catalogName($row['name']);
            $key = $kind . ':' . $name;

            $groups[$key] ??= [
                'name' => $name,
                'kind' => $kind,
                'unique' => $kind !== 'I' || strtoupper(trim((string) ($row['is_unique'] ?? ''))) === 'U',
                'columns' => [],
            ];
            $groups[$key]['columns'][(int) ($row['ordinal_position'] ?? count($groups[$key]['columns']) + 1)]
                = $this->catalogName($row['column_name']);
        }

        $constraintColumns = [];
        foreach ($groups as $group) {
            if ($group['kind'] !== 'I') {
                ksort($group['columns']);
                $constraintColumns[] = array_values($group['columns']);
            }
        }

        $indexes = [];
        foreach ($groups as $group) {
            ksort($group['columns']);
            $columns = array_values($group['columns']);

            if ($group['kind'] === 'I' && $group['unique'] && in_array($columns, $constraintColumns, true)) {
                continue;
            }

            $indexes[] = [
                'name' => $group['name'],
                'columns' => $columns,
                'type' => null,
                'unique' => $group['unique'],
                'primary' => $group['kind'] === 'P',
            ];
        }

        return $indexes;
    }

    /**
     * Process the results of a foreign keys query.
     *
     * Rows are one referencing/referenced column pair each, grouped by constraint name.
     *
     * @param list<array<string, mixed>|object> $results
     * @return list<array{name: string|null, columns: list<string>, foreign_schema: string|null, foreign_table: string, foreign_columns: list<string>, on_update: string|null, on_delete: string|null}>
     */
    public function processForeignKeys($results)
    {
        $groups = [];

        foreach ($results as $result) {
            $row = $this->catalogRow($result);
            $name = $this->catalogName($row['name']);

            $groups[$name] ??= [
                'name' => $name,
                'pairs' => [],
                'foreign_schema' => isset($row['foreign_schema']) ? $this->catalogName($row['foreign_schema']) : null,
                'foreign_table' => $this->catalogName($row['foreign_table']),
                'on_update' => $this->catalogReferentialAction($row['update_rule'] ?? null),
                'on_delete' => $this->catalogReferentialAction($row['delete_rule'] ?? null),
            ];
            $groups[$name]['pairs'][] = [
                (int) ($row['ordinal_position'] ?? count($groups[$name]['pairs']) + 1),
                $this->catalogName($row['column_name']),
                $this->catalogName($row['foreign_column']),
            ];
        }

        return array_values(array_map(function (array $group) {
            usort($group['pairs'], fn (array $a, array $b) => $a[0] <=> $b[0]);

            return [
                'name' => $group['name'],
                'columns' => array_column($group['pairs'], 1),
                'foreign_schema' => $group['foreign_schema'],
                'foreign_table' => $group['foreign_table'],
                'foreign_columns' => array_column($group['pairs'], 2),
                'on_update' => $group['on_update'],
                'on_delete' => $group['on_delete'],
            ];
        }, $groups));
    }

    /**
     * Normalise a catalog row to an array with lower-case keys, whatever the PDO case setting.
     *
     * @param array<string, mixed>|object $row
     * @return array<string, mixed>
     */
    private function catalogRow(array|object $row): array
    {
        return array_change_key_case((array) $row, CASE_LOWER);
    }

    /**
     * Lower-case an object name the catalog folded to upper case, dropping CHAR padding.
     */
    private function catalogName(mixed $name): string
    {
        return strtolower(trim((string) $name));
    }

    private function catalogText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function catalogFlag(mixed $value, string $truthy): bool
    {
        return strtoupper(trim((string) $value)) === $truthy;
    }

    /**
     * Lower-case a catalog data type and expand the short names IBM i reports.
     */
    private function catalogTypeName(string $type): string
    {
        $type = strtolower(trim($type));

        return match ($type) {
            'timestmp' => 'timestamp',
            'varg' => 'vargraphic',
            'varbin' => 'varbinary',
            default => $type,
        };
    }

    /**
     * Build the full column type, for example varchar(20) or decimal(9,2).
     */
    private function catalogColumnType(string $typeName, mixed $length, mixed $scale): string
    {
        if (! is_numeric($length)) {
            return $typeName;
        }

        return match ($typeName) {
            'char', 'varchar', 'graphic', 'vargraphic', 'binary', 'varbinary', 'clob', 'blob', 'dbclob' => sprintf('%s(%d)', $typeName, $length),
            'decimal', 'numeric' => sprintf('%s(%d,%d)', $typeName, $length, is_numeric($scale) ? $scale : 0),
            default => $typeName,
        };
    }

    /**
     * Map a catalog referential rule to the lower-case words Laravel uses.
     */
    private function catalogReferentialAction(mixed $rule): ?string
    {
        $rule = $this->catalogText($rule);

        return $rule === null ? null : strtolower($rule);
    }
}
