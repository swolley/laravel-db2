<?php

namespace Easi\DB2\Database\Query\Grammars;

use DateTimeInterface;
use Easi\DB2\Database\ReservedWords;
use Easi\DB2\Database\Query\DB2ParameterType;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Builder;
use RuntimeException;

/**
 * Class DB2Grammar
 *
 * @package Easi\DB2\Database\Query\Grammars
 */
class DB2Grammar extends Grammar
{
    /**
     * The format for database-stored dates.
     *
     * @var string
     */
    protected string $dateFormat;

    /**
     * Offset compatibility mode true triggers FETCH FIRST X ROWS and ROW_NUM behavior for older versions of DB2
     * @var bool
     */
    protected bool $offsetCompatibilityMode = true;

    /**
     * Most variables and constants DB2 for i accepts in a single SQL statement.
     *
     * Every value in an upsert becomes one, so this caps rows x columns per MERGE.
     */
    protected const int MAX_STATEMENT_VARIABLES = 32700;

    /** The alias an upsert gives the merge target. */
    protected const string TARGET_ALIAS = 't';

    /** The alias an upsert gives the derived table holding the values. */
    protected const string SOURCE_ALIAS = 'x';

    /**
     * Wrap a single string in keyword identifiers.
     *
     * With the `quote_reserved_words` connection option on, a Db2 reserved word is quoted
     * upper-cased (the catalog's folded form); everything else is left bare.
     *
     * @param string $value
     *
     * @return string
     */
    protected function wrapValue($value): string
    {
        if ($value === '*') {
            return $value;
        }

        $escaped = str_replace('"', '""', $value);

        if ($this->connection->getConfig('quote_reserved_words') === true && ReservedWords::contains($value)) {
            return '"' . strtoupper($escaped) . '"';
        }

        return $escaped;
    }

    /**
     * Compile the "limit" portions of the query.
     *
     * @param Builder $query
     * @param int                                $limit
     *
     * @return string
     */
    protected function compileLimit(Builder $query, $limit): string
    {
        if($this->offsetCompatibilityMode){
            return "FETCH FIRST $limit ROWS ONLY";
        }
        return parent::compileLimit($query, $limit);
    }

    /**
     * Compile the lock into SQL.
     *
     * @param Builder $query
     * @param bool|string $value
     *
     * @return string
     */
    protected function compileLock(Builder $query, $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return $value
            ? 'with rs use and keep exclusive locks'
            : 'with rs';
    }

    /**
     * Compile a "where like" clause. Case-insensitive unless caseSensitive is set,
     * upper-casing both sides because DB2 has no ILIKE. The case-insensitive pattern
     * marker is cast because DB2 rejects an untyped parameter marker inside a function (SQL0418);
     * the cast is a character type sized from the pattern, as the upsert casts are.
     *
     * @param Builder $query
     * @param array{column: mixed, value: mixed, caseSensitive: bool, not: bool} $where
     *
     * @return string
     */
    protected function whereLike(Builder $query, $where): string
    {
        $column = $this->wrap($where['column']);
        $operator = $where['not'] ? 'not like' : 'like';

        if ($where['caseSensitive']) {
            return "$column $operator ".$this->parameter($where['value']);
        }

        // A pattern is matched as text whatever PHP type it arrives as, so it is typed from
        // its string form: an integer would otherwise be cast to BIGINT, which upper() rejects.
        $pattern = $where['value'];
        $type = DB2ParameterType::forColumn([is_scalar($pattern) ? (string) $pattern : $pattern]);

        return "upper($column) $operator upper(".$this->parameterWithType($pattern, $type).')';
    }

    /**
     * Compile a select query into SQL.
     *
     * @param Builder $query
     *
     * @return string
     */
    public function compileSelect(Builder $query): string
    {
        if (!$this->offsetCompatibilityMode) {
            $sql = parent::compileSelect($query);
        } else {
            if (is_null($query->columns)) {
                $query->columns = ['*'];
            }

            $components = $this->compileComponents($query);

            // If an offset is present on the query, we will need to wrap the query in
            // a big "ANSI" offset syntax block. This is very nasty compared to the
            // other database systems but is necessary for implementing features.
            $sql = $query->offset > 0
                ? $this->compileAnsiOffset($query, $components)
                : $this->concatenate($components);
        }

        /** @var array<string, Builder> $expressions */
        $expressions = get_object_vars($query)['expressions'] ?? [];
        if (count($expressions) === 0) {
            return $sql;
        }

        $cteParts = collect($expressions)
            ->map(fn (Builder $expr, string $name) => "$name AS ({$this->compileSelect($expr)})")
            ->implode(', ');

        return "WITH $cteParts $sql";
    }

    /**
     * Create a full ANSI offset clause for the query.
     *
     * @param Builder $query
     * @param array $components
     *
     * @return string
     */
    protected function compileAnsiOffset(Builder $query, array $components): string
    {
        // An ORDER BY clause is required to make this offset query work, so if one does
        // not exist, we'll just create a dummy clause to trick the database, and so it
        // does not complain about the queries for not having an "order by" clause.
        if (!isset($components['orders'])) {
            $components['orders'] = 'order by 1';
        }

        unset($components['limit']);

        // We need to add the row number to the query so we can compare it to the offset
        // and limit values given for the statements. So we will add an expression to
        // the "select" that will give back the row numbers on each of the records.
        $orderings = $components['orders'];

        $columns = (!empty($components['columns']) ? $components['columns'] . ', ' : 'select');

        if ($columns == 'select *, ' && $query->from) {
            $columns = 'select ' . $this->connection->getTablePrefix() . $query->from . '.*, ';
        }

        $components['columns'] = $this->compileOver($orderings, $columns);

        // if there are bindings in the order, we need to move them to the select since we are moving the parameter
        // markers there with the OVER statement
        if(isset($query->getRawBindings()['order'])){
            $query->addBinding($query->getRawBindings()['order'], 'select');
            $query->setBindings([], 'order');
        }

        unset($components['orders']);

        // Next, we need to calculate the constraints that should be placed on the query
        // to get the right offset and limit from our query. However, if there is no limit
        // set, we will just handle the offset only since that is all that matters.
        $start = $query->offset + 1;

        $constraint = $this->compileRowConstraint($query);

        $sql = $this->concatenate($components);

        // We are now ready to build the final SQL query, so we'll create a common table
        // expression from the query and get the records with row numbers within our
        // given limit and offset value that we just put on as a query constraint.
        return $this->compileTableExpression($sql, $constraint);
    }

    /**
     * Compile the over statement for a table expression.
     *
     * @param string $orderings
     * @param        $columns
     *
     * @return string
     */
    protected function compileOver(string $orderings, $columns): string
    {
        return "{$columns} row_number() over ({$orderings}) as row_num";
    }

    /**
     * @param $query
     *
     * @return string
     */
    protected function compileRowConstraint($query): string
    {
        $start = $query->offset + 1;

        if ($query->limit > 0) {
            $finish = $query->offset + $query->limit;

            return "between {$start} and {$finish}";
        }

        return ">= {$start}";
    }

    /**
     * Compile a common table expression for a query.
     *
     * @param string $sql
     * @param string $constraint
     *
     * @return string
     */
    protected function compileTableExpression(string $sql, string $constraint): string
    {
        return "select * from ({$sql}) as temp_table where row_num {$constraint}";
    }

    /**
     * Compile the "offset" portions of the query.
     *
     * @param Builder $query
     * @param int                                $offset
     *
     * @return string
     */
    protected function compileOffset(Builder $query, $offset): string
    {
        if($this->offsetCompatibilityMode){
            return '';
        }
        return parent::compileOffset($query, $offset);
    }

    /**
     * Compile an exists statement into SQL.
     *
     * @param Builder $query
     * @return string
     */
    public function compileExists(Builder $query): string
    {
        $existsQuery = clone $query;

        $existsQuery->columns = [];

        return $this->compileSelect($existsQuery->selectRaw('1 exists')->limit(1));
    }

    /**
     * @inheritDoc
     *
     */
    public function compileExistsColumn($sql): string
    {
        return '(select count(*) from (values (1)) as d(x) where exists('.$sql.'))';
    }

    /**
     * Get the format for database-stored dates.
     *
     * @return string
     */
    public function getDateFormat(): string
    {
        return $this->dateFormat ?? parent::getDateFormat();
    }

    /**
     * Set the format for database-stored dates.
     *
     * @param $dateFormat
     */
    public function setDateFormat($dateFormat): void
    {
        $this->dateFormat = $dateFormat;
    }

    /**
     * Set offset compatibility mode to trigger FETCH FIRST X ROWS and ROW_NUM behavior for older versions of DB2
     *
     * @param $bool
     */
    public function setOffsetCompatibilityMode($bool): void
    {
        $this->offsetCompatibilityMode = $bool;
    }

    /**
     * Compile the SQL statement to define a savepoint.
     *
     * @param  string  $name
     * @return string
     */
    public function compileSavepoint($name): string
    {
        return 'SAVEPOINT '.$name.' ON ROLLBACK RETAIN CURSORS';
    }

    /**
     * Compile an "upsert" statement into SQL.
     *
     * @param Builder $query
     * @param  array  $values
     * @param  array  $uniqueBy
     * @param  array  $update
     * @return string
     */
    public function compileUpsert(Builder $query, array $values, array $uniqueBy, array $update): string
    {
        $table = $this->wrapTable($query->from);

        // The merge target and the derived source table. Declaration and reference both go
        // through wrap(), so a grammar that quotes identifiers quotes the alias on both
        // sides: a bare "as t" declares T, and a reference the wrapper has quoted has to
        // agree with it.
        $target = $this->wrap(self::TARGET_ALIAS);
        $source = $this->wrap(self::SOURCE_ALIAS);

        $columns = array_keys($values[0]);
        $columnList = "(".$this->columnize($columns).")";

        // One cast per column, never per cell: every row feeds the same derived-table
        // column, so deciding the type value by value would give a column that holds an
        // int in one row and a string in the next two conflicting types. Character
        // columns are also sized from their values, so the type has to see all of them.
        $types = [];
        foreach ($columns as $column)
        {
            $types[$column] = DB2ParameterType::forColumn(array_map(
                fn (array $row): mixed => $this->asBound($row[$column] ?? null),
                $values,
            ));
        }

        $this->guardStatementVariables($values, $columns, $update);

        // Columns sized one by one can still add up to a row DB2 will not build.
        $types = DB2ParameterType::fitRow($types);

        $tuples = [];
        foreach ($values as $row)
        {
            $placeholders = [];
            foreach ($columns as $column)
            {
                $placeholders[] = $this->parameterWithType($row[$column] ?? null, $types[$column]);
            }

            $tuples[] = "(".implode(', ', $placeholders).")";
        }

        // Unique key constraint
        $predicates = [];
        foreach ($uniqueBy as $uniqueCol)
        {
            $predicates[] = $target.'.'.$this->wrap($uniqueCol).' = '.$source.'.'.$this->wrap($uniqueCol);
        }

        // When matched => update. A string key is Laravel's "set this column to a literal"
        // form, whose binding Builder::upsert() appends after the values.
        $assignments = [];
        foreach ($update as $key => $col)
        {
            $assignments[] = is_int($key)
                ? $target.'.'.$this->wrap($col).' = '.$source.'.'.$this->wrap($col)
                : $target.'.'.$this->wrap($key).' = '.$this->parameter($col);
        }

        // When no match => INSERT
        $insertValues = "VALUES (".implode(', ', array_map(
            fn (string $column): string => $source.'.'.$this->wrap($column),
            $columns,
        )).")";

        $clauses = [
            "MERGE INTO $table as $target USING (VALUES".implode(",\n", $tuples).") as $source $columnList",
            "ON ".implode(' AND ', $predicates),
            "WHEN NOT MATCHED THEN INSERT $columnList $insertValues",
        ];

        if ($assignments !== []) {
            $clauses[] = "WHEN MATCHED THEN UPDATE SET";
            $clauses[] = implode(",\n", $assignments);
        }

        return implode("\n", $clauses);
    }

    /**
     * Refuse an upsert that would bind more parameters than DB2 accepts in one statement.
     *
     * A MERGE binds one marker per value, so the batch size a caller can pass is bounded
     * by the columns they are writing. DB2 reports this obscurely, and the caller is the
     * only one who can decide how to split the write, so say plainly what the limit is
     * and let them chunk rather than quietly running several statements on their behalf.
     *
     * @param  array  $values
     * @param  array  $columns
     * @param  array  $update
     * @return void
     *
     * @throws RuntimeException
     */
    protected function guardStatementVariables(array $values, array $columns, array $update): void
    {
        // A string-keyed update entry binds its literal on top of the values.
        $literals = count(array_filter(array_keys($update), fn ($key): bool => ! is_int($key)));

        $markers = count($values) * count($columns) + $literals;

        if ($markers <= self::MAX_STATEMENT_VARIABLES) {
            return;
        }

        throw new RuntimeException(sprintf(
            'This upsert binds %s parameters, over DB2\'s limit of %s per statement: %s rows of '
            .'%s columns. Split the rows into chunks of at most %s and upsert each chunk, wrapping '
            .'them in a transaction if they have to succeed or fail together.',
            number_format($markers),
            number_format(self::MAX_STATEMENT_VARIABLES),
            number_format(count($values)),
            number_format(count($columns)),
            number_format(intdiv(self::MAX_STATEMENT_VARIABLES - $literals, max(1, count($columns)))),
        ));
    }

    /**
     * Render a value as the connection will bind it.
     *
     * Sizing a character cast means measuring the string that actually arrives, and
     * Connection::prepareBindings() formats dates on the way to PDO.
     *
     * @param  mixed  $value
     * @return mixed
     */
    protected function asBound(mixed $value): mixed
    {
        return $value instanceof DateTimeInterface
            ? $value->format($this->getDateFormat())
            : $value;
    }

    /**
     * Create query parameter place-holders for an array.
     *
     * Types each value on its own. Prefer resolving the type across the whole column,
     * as compileUpsert() does, whenever the placeholders share a derived-table column.
     *
     * @param  array  $values
     * @return string
     */
    public function parameterizeWithTypes(array $values): string
    {
        return implode(', ', array_map([$this, 'parameterWithType'], $values));
    }

    /**
     * Get the appropriate query parameter place-holder for a value.
     *
     * @param  mixed  $value
     * @param  DB2ParameterType|null  $type  The column's type; derived from the value when omitted.
     * @return string
     */
    public function parameterWithType(mixed $value, ?DB2ParameterType $type = null): string
    {
        if ($this->isExpression($value)) {
            return $this->getValue($value);
        }

        return ($type ?? DB2ParameterType::forColumn([$value]))->placeholder();
    }

    /**
     * Wrap the given JSON selector as a JSON_VALUE call in lax mode.
     *
     * The result is declared as a VARCHAR: left implicit, the returned type may be a LOB
     * on IBM i, which cannot be compared, ordered or grouped like a string.
     *
     * @param  string  $value
     * @return string
     */
    protected function wrapJsonSelector($value): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($value);

        return 'JSON_VALUE('.$field.$path.' RETURNING VARCHAR(4000))';
    }

    /**
     * Wrap the given JSON path as a lax SQL/JSON path literal.
     *
     * @param  string  $value
     * @param  string  $delimiter
     * @return string
     */
    protected function wrapJsonPath($value, $delimiter = '->'): string
    {
        return "'lax ".substr(parent::wrapJsonPath($value, $delimiter), 1);
    }

    /**
     * Wrap a JSON path segment, keeping a key the caller already quoted.
     *
     * @param  string  $segment
     * @return string
     */
    protected function wrapJsonPathSegment($segment): string
    {
        if (strlen($segment) > 1 && str_starts_with($segment, '"') && str_ends_with($segment, '"')) {
            return $segment;
        }

        return parent::wrapJsonPathSegment($segment);
    }

    /**
     * Compare a JSON boolean as the JSON_VALUE string it produces.
     *
     * @param  string  $value
     * @return string
     */
    protected function wrapJsonBooleanValue($value): string
    {
        return "'".$value."'";
    }

    /**
     * Compile a basic where clause, extracting JSON numbers as decimals.
     *
     * @param  Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereBasic(Builder $query, $where): string
    {
        $column = $where['column'];

        if (is_string($column) && $this->isJsonSelector($column) && (is_int($where['value']) || is_float($where['value']))) {
            [$field, $path] = $this->wrapJsonFieldAndPath($column);

            return 'JSON_VALUE('.$field.$path.' RETURNING DECFLOAT(34)) '
                .str_replace('?', '??', $where['operator']).' '.$this->parameter($where['value']);
        }

        return parent::whereBasic($query, $where);
    }

    /**
     * Compile a "where JSON contains" clause; an array value needs every element.
     *
     * @param  Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereJsonContains(Builder $query, $where): string
    {
        $not = $where['not'] ? 'not ' : '';
        $value = $where['value'];

        if (! is_array($value)) {
            return $not.$this->compileJsonContains($where['column'], $this->parameter($value));
        }

        if ($value === []) {
            return $not.'(1 = 1)';
        }

        $clauses = array_map(
            fn (): string => $this->compileJsonContains($where['column'], '?'),
            $value,
        );

        return $not.(count($clauses) > 1 ? '('.implode(' AND ', $clauses).')' : $clauses[0]);
    }

    /**
     * Compile a "JSON contains" test: some element of the array at the path equals the value.
     *
     * @param  string  $column
     * @param  string  $value
     * @return string
     */
    protected function compileJsonContains($column, $value): string
    {
        return 'EXISTS (SELECT 1 FROM '.$this->jsonTableOverElements($column).' WHERE JT.V = '.$value.')';
    }

    /**
     * Compile a "JSON length" test as a count of the array elements at the path.
     *
     * @param  string  $column
     * @param  string  $operator
     * @param  string  $value
     * @return string
     */
    protected function compileJsonLength($column, $operator, $value): string
    {
        return '(SELECT COUNT(*) FROM '.$this->jsonTableOverElements($column).') '.$operator.' '.$value;
    }

    /**
     * Bind each scalar as the text JSON_TABLE yields for it; an array becomes one binding per element.
     *
     * @param  mixed  $binding
     * @return string|list<string>
     */
    public function prepareBindingForJsonContains($binding): string|array
    {
        $toText = static fn (mixed $item): string => match (true) {
            is_bool($item) => $item ? 'true' : 'false',
            $item === null => 'null',
            is_scalar($item) => (string) $item,
            default => (string) json_encode($item, JSON_UNESCAPED_UNICODE),
        };

        return is_array($binding) ? array_map($toText, array_values($binding)) : $toText($binding);
    }

    /**
     * Build the JSON_TABLE source listing the elements of the array at the column's path.
     *
     * @param  string  $column
     * @return string
     */
    protected function jsonTableOverElements(string $column): string
    {
        $parts = explode('->', $column, 2);
        $path = count($parts) > 1 ? $this->wrapJsonPath($parts[1]) : "'lax $'";

        return 'JSON_TABLE('.$this->wrap($parts[0]).', '.substr($path, 0, -1)."[*]' COLUMNS (V VARCHAR(4000) PATH '$')) AS JT";
    }

    /**
     * Compile the random statement into SQL.
     *
     * @param string|int $seed
     *
     * @return string
     */
    public function compileRandom($seed): string
    {
        return $seed === '' || $seed === null ? 'RAND()' : 'RAND(' . (int) $seed . ')';
    }
}
