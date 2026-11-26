<?php

namespace Easi\DB2\Database\Schema\Grammars;

use Easi\DB2\Database\DB2Connection;
use Illuminate\Database\Schema\ColumnDefinition;
use RuntimeException;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Fluent;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Database\Schema\Blueprint;

/**
 * Schema grammar for Db2 for i.
 *
 * The introspection queries (compileSchemas, compileTables, compileViews, compileColumns,
 * compileIndexes, compileForeignKeys) read the QSYS2 catalog. Their approach follows
 * emkcloud/laravel-iseries-db2, https://github.com/emkcloud/laravel-iseries-db2, MIT licensed.
 * Copyright (c) 2025 Massimo Della Rovere. The MIT permission notice is reproduced in this package's README.
 *
 * @property DB2Connection $connection
 */
class DB2Grammar extends Grammar
{
    /**
     * The possible column modifiers.
     *
     * @var array
     */
    protected array $preModifiers = ['ForColumn'];
    protected $modifiers = [
        'Nullable',
        'Default',
        'Generated',
        'Increment',
        'StartWith',
        'Before',
        'ImplicitlyHidden',
    ];
    /**
     * The possible column serials
     *
     * @var array
     */
    protected array $serials = [
        'smallInteger',
        'integer',
        'bigInteger',
    ];

    /**
     * Wrap a single string in keyword identifiers.
     *
     * @param  string $value
     *
     * @return string
     */
    protected function wrapValue($value): string
    {
        if ($value === '*') {
            return $value;
        }

        return str_replace('"', '""', $value);
    }

    /**
     * Compile the query to determine if a given table exists.
     *
     * Reads the QSYS2 catalog like the other introspection queries, counting base tables
     * (SQL tables and physical files) only, as compileTables() lists them.
     *
     * @param  string|null  $schema
     * @param  string  $table
     * @return string
     */
    public function compileTableExists($schema, $table): string
    {
        return sprintf(
            'select count(*) as "exists" from qsys2.systables where table_schema = %s and table_name = %s'
            . " and table_type in ('T', 'P')",
            $this->quoteString(strtoupper($schema ?? $this->connection->getDefaultSchema())),
            $this->quoteString(strtoupper($table))
        );
    }

    /**
     * Compile the query to determine the list of columns.
     *
     * Reads the column catalog, so a column is found whether or not it is part of an index.
     *
     * @return string
     */
    public function compileColumnExists(): string
    {
        return 'select column_name from qsys2.syscolumns where table_schema = ? and table_name = ? order by ordinal_position';
    }

    /**
     * Compile the query to determine the schemas (libraries).
     *
     * @return string
     */
    public function compileSchemas(): string
    {
        return sprintf(
            'select schema_name as "name", case when schema_name = %s then 1 else 0 end as "default" '
            . 'from qsys2.sysschemas order by schema_name',
            $this->quoteString(strtoupper($this->connection->getDefaultSchema()))
        );
    }

    /**
     * Compile the query to determine the base tables (physical files and SQL tables).
     *
     * @param  string|string[]|null  $schema
     * @return string
     */
    public function compileTables($schema): string
    {
        return 'select table_name as "name", table_schema as "schema", coalesce(long_comment, table_text) as "comment" '
            . 'from qsys2.systables where ' . $this->compileCatalogSchemaClause($schema, 'table_schema')
            . " and table_type in ('T', 'P') order by table_schema, table_name";
    }

    /**
     * Compile the query to determine the views.
     *
     * @param  string|string[]|null  $schema
     * @return string
     */
    public function compileViews($schema): string
    {
        return 'select table_name as "name", table_schema as "schema", view_definition as "definition" '
            . 'from qsys2.sysviews where ' . $this->compileCatalogSchemaClause($schema, 'table_schema')
            . ' order by table_schema, table_name';
    }

    /**
     * Compile the query to determine the columns of a table.
     *
     * @param  string|null  $schema
     * @param  string  $table
     * @return string
     */
    public function compileColumns($schema, $table): string
    {
        return sprintf(
            'select column_name as "name", data_type as "type_name", length as "length", '
            . 'numeric_scale as "numeric_scale", is_nullable as "is_nullable", column_default as "column_default", '
            . 'has_default as "has_default", is_identity as "is_identity", coalesce(long_comment, column_text) as "comment" '
            . 'from qsys2.syscolumns where %s and table_name = %s order by ordinal_position',
            $this->compileCatalogSchemaClause($schema, 'table_schema'),
            $this->quoteString(strtoupper($table))
        );
    }

    /**
     * Compile the query to determine the indexes of a table.
     *
     * Two sources are merged: SQL indexes (SYSINDEXES with SYSKEYS), and the primary key and
     * unique constraints (SYSCST with SYSKEYCST), which IBM i keeps as constraints.
     * Each row is one key column. "kind" is I (index), P (primary key) or U (unique constraint).
     *
     * @param  string|null  $schema
     * @param  string  $table
     * @return string
     */
    public function compileIndexes($schema, $table): string
    {
        return sprintf(
            'select i.index_name as "name", k.column_name as "column_name", k.ordinal_position as "ordinal_position", '
            . "'I' as \"kind\", i.is_unique as \"is_unique\" "
            . 'from qsys2.sysindexes i join qsys2.syskeys k on k.index_schema = i.index_schema and k.index_name = i.index_name '
            . 'where %1$s and i.table_name = %2$s '
            . 'union all '
            . 'select c.constraint_name, k.column_name, k.ordinal_position, '
            . "case c.constraint_type when 'PRIMARY KEY' then 'P' else 'U' end, 'U' "
            . 'from qsys2.syscst c join qsys2.syskeycst k on k.constraint_schema = c.constraint_schema and k.constraint_name = c.constraint_name '
            . "where %3\$s and c.table_name = %2\$s and c.constraint_type in ('PRIMARY KEY', 'UNIQUE') "
            . 'order by 1, 3',
            $this->compileCatalogSchemaClause($schema, 'i.table_schema'),
            $this->quoteString(strtoupper($table)),
            $this->compileCatalogSchemaClause($schema, 'c.table_schema')
        );
    }

    /**
     * Compile the query to determine the foreign keys of a table.
     *
     * Each row is one key column pair: the referencing column and the column it references,
     * with the parent table resolved through the referenced constraint.
     *
     * @param  string|null  $schema
     * @param  string  $table
     * @return string
     */
    public function compileForeignKeys($schema, $table): string
    {
        return sprintf(
            'select c.constraint_name as "name", fk.column_name as "column_name", fk.ordinal_position as "ordinal_position", '
            . 'pc.table_schema as "foreign_schema", pc.table_name as "foreign_table", pk.column_name as "foreign_column", '
            . 'r.update_rule as "update_rule", r.delete_rule as "delete_rule" '
            . 'from qsys2.syscst c '
            . 'join qsys2.syskeycst fk on fk.constraint_schema = c.constraint_schema and fk.constraint_name = c.constraint_name '
            . 'join qsys2.sysrefcst r on r.constraint_schema = c.constraint_schema and r.constraint_name = c.constraint_name '
            . 'join qsys2.syscst pc on pc.constraint_schema = r.unique_constraint_schema and pc.constraint_name = r.unique_constraint_name '
            . 'join qsys2.syskeycst pk on pk.constraint_schema = r.unique_constraint_schema '
            . 'and pk.constraint_name = r.unique_constraint_name and pk.ordinal_position = fk.ordinal_position '
            . "where c.constraint_type = 'FOREIGN KEY' and %s and c.table_name = %s "
            . 'order by c.constraint_name, fk.ordinal_position',
            $this->compileCatalogSchemaClause($schema, 'c.table_schema'),
            $this->quoteString(strtoupper($table))
        );
    }

    /**
     * Compile a catalog predicate on a schema column.
     *
     * A null or empty schema means the connection's default schema, an array becomes an IN list.
     *
     * @param  string|string[]|null  $schema
     * @param  string  $column
     * @return string
     */
    protected function compileCatalogSchemaClause($schema, string $column): string
    {
        $schemas = array_values(array_filter((array) $schema, fn ($name) => $name !== null && $name !== ''));

        if ($schemas === []) {
            $schemas = [$this->connection->getDefaultSchema()];
        }

        $quoted = array_map(fn ($name) => $this->quoteString(strtoupper($name)), $schemas);

        return count($quoted) === 1
            ? $column . ' = ' . $quoted[0]
            : $column . ' in (' . implode(', ', $quoted) . ')';
    }

    /**
     * Compile a create table command.
     *
     * @param \Easi\DB2\Database\Schema\Blueprint $blueprint
     * @param Fluent $command
     * @return string
     */
    public function compileCreate(Blueprint $blueprint, Fluent $command): string
    {
        $columns = implode(', ', $this->getColumns($blueprint));
        $sql = 'create table ' . $this->wrapTable($blueprint);

        if ($blueprint->systemName !== null) {
            $sql .= ' for system name ' . $blueprint->systemName;
        }

        $sql .= " ($columns)";

        return $sql;
    }

    /**
     * Compile a label command.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return string
     */
    public function compileLabel(Blueprint $blueprint, Fluent $command): string
    {
        return 'label on table ' . $this->wrapTable($blueprint) . ' is \'' . $command->label . '\'';
    }

    /**
     * Compile the blueprint's column definitions.
     *
     * @param Blueprint $blueprint
     *
     * @return array
     */
    protected function getColumns(Blueprint $blueprint): array
    {
        $columns = [];

        foreach ($blueprint->getColumns() as $column) {
            $columns[] = $this->getColumn($blueprint, $column);
        }

        return $columns;
    }

    /**
     * Compile a single column definition, including its modifiers.
     *
     * @param Blueprint $blueprint
     * @param ColumnDefinition $column
     *
     * @return string
     */
    protected function getColumn(Blueprint $blueprint, $column): string
    {
        // Each of the column types have their own compiler functions which are tasked
        // with turning the column definition into its SQL format for this platform
        // used by the connection. The column's modifiers are compiled and added.
        $sql = $this->addPreModifiers($this->wrap($column), $blueprint, $column);
        $sql .= ' ' . $this->getType($column);

        return $this->addModifiers($sql, $blueprint, $column);
    }

    /**
     * Add the column modifiers to the definition.
     *
     * @param string $sql
     * @param Blueprint $blueprint
     * @param Fluent $column
     *
     * @return string
     */
    protected function addPreModifiers(string $sql, Blueprint $blueprint, Fluent $column): string
    {
        foreach ($this->preModifiers as $preModifier) {
            if (method_exists($this, $method = "modify{$preModifier}")) {
                $sql .= $this->{$method}($blueprint, $column);
            }
        }

        return $sql;
    }

    /**
     * Compile a create table command.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     *
     * @return array|string
     */
    public function compileAdd(Blueprint $blueprint, Fluent $command): array|string
    {
        $table = $this->wrapTable($blueprint);
        $column = $this->getColumn($blueprint, $command->column);

        return 'alter table ' . $table . ' add column ' . $column;
    }

    /**
     * Compile a primary key command.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     *
     * @return string
     */
    public function compilePrimary(Blueprint $blueprint, Fluent $command): string
    {
        $table = $this->wrapTable($blueprint);
        $columns = $this->columnize($command->columns);

        // Aucune utilité d'avoir le nom du schéma dans le nom de la contrainte Primary
        $schemaTable = explode(".", $table);

        if (count($schemaTable) > 1) {
            $command->index = str_replace($schemaTable[0] . "_", "", $command->index);
        }

        return "alter table {$table} add constraint {$command->index} primary key ({$columns})";
    }

    /**
     * Compile a foreign key command.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     *
     * @return string
     */
    public function compileForeign(Blueprint $blueprint, Fluent $command): string
    {
        $table = $this->wrapTable($blueprint);
        $on = $this->wrapTable($command->on);

        // We need to prepare several of the elements of the foreign key definition
        // before we can create the SQL, such as wrapping the tables and convert
        // an array of columns to comma-delimited strings for the SQL queries.
        $columns = $this->columnize($command->columns);
        $onColumns = $this->columnize((array) $command->references);

        // Aucune utilité d'avoir le nom du schéma dans le nom de la contrainte Foreign
        $schemaTable = explode(".", $table);

        if (count($schemaTable) > 1) {
            $command->index = str_replace($schemaTable[0] . "_", "", $command->index);
        }

        $sql = "alter table {$table} add constraint {$command->index} ";
        $sql .= "foreign key ({$columns}) references {$on} ({$onColumns})";

        // Once we have the basic foreign key creation statement constructed we can
        // build out the syntax for what should happen on an update or delete of
        // the affected columns, which will get something like "cascade", etc.
        if (!is_null($command->onDelete)) {
            $sql .= " on delete {$command->onDelete}";
        }

        if (!is_null($command->onUpdate)) {
            $sql .= " on update {$command->onUpdate}";
        }

        return $sql;
    }

    /**
     * Compile a unique key command.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     *
     * @return string
     */
    public function compileUnique(Blueprint $blueprint, Fluent $command): string
    {
        $table = $this->wrapTable($blueprint);
        $columns = $this->columnize($command->columns);

        // Aucune utilité d'avoir le nom du schéma dans le nom de la contrainte Unique
        $schemaTable = explode(".", $table);

        if (count($schemaTable) > 1) {
            $command->index = str_replace($schemaTable[0] . "_", "", $command->index);
        }

        return "alter table {$table} add constraint {$command->index} unique({$columns})";
    }

    /**
     * Compile a plain index key command.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     *
     * @return string
     */
    public function compileIndex(Blueprint $blueprint, Fluent $command): string
    {
        $table = $this->wrapTable($blueprint);
        $columns = $this->columnize($command->columns);

        // Aucune utilité d'avoir le nom du schéma dans le nom de la contrainte Index
        $schemaTable = explode(".", $table);

        if (count($schemaTable) > 1) {
            $command->index = str_replace($schemaTable[0] . "_", "", $command->index);
        }

        $sql = "create index {$command->index}";

        if ($command->indexSystem) {
            $sql .= " for system name {$command->indexSystem}";
        }

        $sql .= " on {$table}($columns)";

        //return "create index {$command->index} for system name on {$table}($columns)";
        return $sql;
    }

    /**
     * Compile a drop table command.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     *
     * @return string
     */
    public function compileDrop(Blueprint $blueprint, Fluent $command): string
    {
        return 'drop table ' . $this->wrapTable($blueprint);
    }

    /**
     * Compile a drop table (if exists) command.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     *
     * @return string
     */
    public function compileDropIfExists(Blueprint $blueprint, Fluent $command): string
    {
        return 'drop table if exists ' . $this->wrapTable($blueprint);
    }

    /**
     * Compile a drop column command.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     *
     * @return string
     */
    public function compileDropColumn(Blueprint $blueprint, Fluent $command): string
    {
        $columns = $this->prefixArray('drop', $this->wrapArray($command->columns));
        $table = $this->wrapTable($blueprint);

        return 'alter table ' . $table . ' ' . implode(' ', $columns);
    }

    /**
     * Compile a drop primary key command.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     *
     * @return string
     */
    public function compileDropPrimary(Blueprint $blueprint, Fluent $command): string
    {
        return 'alter table ' . $this->wrapTable($blueprint) . ' drop primary key';
    }

    /**
     * Compile a drop unique key command.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     *
     * @return string
     */
    public function compileDropUnique(Blueprint $blueprint, Fluent $command): string
    {
        $table = $this->wrapTable($blueprint);

        // Aucune utilité d'avoir le nom du schéma dans le nom de la contrainte Unique
        $schemaTable = explode(".", $table);

        if (count($schemaTable) > 1) {
            $command->index = str_replace($schemaTable[0] . "_", "", $command->index);
        }

        return "alter table {$table} drop index {$command->index}";
    }

    /**
     * Compile a drop index command.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     *
     * @return string
     */
    public function compileDropIndex(Blueprint $blueprint, Fluent $command): string
    {
        $table = $this->wrapTable($blueprint);

        // Aucune utilité d'avoir le nom du schéma dans le nom de la contrainte Index
        $schemaTable = explode(".", $table);

        if (count($schemaTable) > 1) {
            $command->index = str_replace($schemaTable[0] . "_", "", $command->index);
            return "drop index {$schemaTable[0]}.{$command->index}";
        }

        return "drop index {$command->index}";
    }

    /**
     * Compile a drop foreign key command.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     *
     * @return string
     */
    public function compileDropForeign(Blueprint $blueprint, Fluent $command): string
    {
        $table = $this->wrapTable($blueprint);

        // Aucune utilité d'avoir le nom du schéma dans le nom de la contrainte Foreign
        $schemaTable = explode(".", $table);

        if (count($schemaTable) > 1) {
            $command->index = str_replace($schemaTable[0] . "_", "", $command->index);
        }

        return "alter table {$table} drop foreign key {$command->index}";
    }

    /**
     * Compile a rename table command.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     *
     * @return string
     */
    public function compileRename(Blueprint $blueprint, Fluent $command): string
    {
        $from = $this->wrapTable($blueprint);

        return "rename table {$from} to " . $this->wrapTable($command->to);
    }

    /**
     * Create the column definition for a char type.
     *
     * @param Fluent $column
     *
     * @return string
     */
    protected function typeChar(Fluent $column): string
    {
        return "char({$column->length})";
    }

    /**
     * Create the column definition for a string type.
     *
     * @param Fluent $column
     *
     * @return string
     */
    protected function typeString(Fluent $column): string
    {
        return "varchar({$column->length})";
    }

    /**
     * Create the column definition for a tiny text type.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeTinyText(Fluent $column): string
    {
        $colLength = ($column->length ? $column->length : 255);
        return "varchar($colLength)";
    }

    /**
     * Create the column definition for a text type.
     *
     * @param Fluent $column
     *
     * @return string
     */
    protected function typeText(Fluent $column): string
    {
        // A varchar counts against DB2's 32766-byte row limit, so a few wide text columns
        // (e.g. failed_jobs) overflow it with SQL0101; a clob is stored off-row. Like the
        // framework's own grammars, the length is not applied.
        return 'clob';
    }

    /**
     * Create the column definition for a medium text type.
     *
     * @param Fluent $column
     *
     * @return string
     */
    protected function typeMediumText(Fluent $column): string
    {
        return 'clob';
    }

    /**
     * Create the column definition for a long text type.
     *
     * @param Fluent $column
     *
     * @return string
     */
    protected function typeLongText(Fluent $column): string
    {
        return "clob";
    }

    /**
     * Create the column definition for a big integer type.
     *
     * @param Fluent $column
     *
     * @return string
     */
    protected function typeBigInteger(Fluent $column): string
    {
        return 'bigint';
    }

    /**
     * Create the column definition for an integer type.
     *
     * @param Fluent $column
     *
     * @return string
     */
    protected function typeInteger(Fluent $column): string
    {
        return 'int';
    }

    /**
     * Create the column definition for a medium integer type.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeMediumInteger(Fluent $column): string
    {
        return 'int';
    }

    /**
     * Create the column definition for a small integer type.
     *
     * @param Fluent $column
     *
     * @return string
     */
    protected function typeSmallInteger(Fluent $column): string
    {
        return 'smallint';
    }

    /**
     * Create the column definition for a tiny integer type.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeTinyInteger(Fluent $column): string
    {
        return 'smallint';
    }

    /**
     * Create the column definition for a numeric type.
     *
     * @param Fluent $column
     *
     * @return string
     */
    protected function typeNumeric(Fluent $column): string
    {
        return "numeric({$column->total}, {$column->places})";
    }

    /**
     * Create the column definition for a float type.
     *
     * @param Fluent $column
     *
     * @return string
     */
    protected function typeFloat(Fluent $column): string
    {
        return "decimal({$column->total}, {$column->places})";
    }

    /**
     * Create the column definition for a double type.
     *
     * @param Fluent $column
     *
     * @return string
     */
    protected function typeDouble(Fluent $column): string
    {
        if ($column->total && $column->places) {
            return "double({$column->total}, {$column->places})";
        }

        return 'double';
    }

    /**
     * Create the column definition for a decimal type.
     *
     * @param Fluent $column
     *
     * @return string
     */
    protected function typeDecimal(Fluent $column): string
    {
        return "decimal({$column->total}, {$column->places})";
    }

    /**
     * Create the column definition for a boolean type.
     *
     * @param Fluent $column
     *
     * @return string
     */
    protected function typeBoolean(Fluent $column): string
    {
        $definition = 'smallint constraint %s_%s_%s check(%s in(0, 1)) %s';

        return sprintf($definition, $column->type, $column->prefix, $column->name, $column->name, is_null($column->default) ? ' default 0' : '');
    }

    /**
     * Create the column definition for an enum type.
     *
     * @param Fluent $column
     *
     * @return string
     */
    protected function typeEnum(Fluent $column): string
    {
        return "enum('" . implode("', '", $column->allowed) . "')";
    }

    /**
     * Create the column definition for a date type.
     *
     * @param Fluent $column
     *
     * @return string
     */
    protected function typeDate(Fluent $column): string
    {
        if (!$column->nullable) {
            return 'date default current_date';
        }

        return 'date';
    }

    /**
     * Create the column definition for a date-time type.
     *
     * @param Fluent $column
     *
     * @return string
     */
    protected function typeDateTime(Fluent $column): string
    {
        return $this->typeTimestamp($column);
    }

    /**
     * Create the column definition for a time type.
     *
     * @param Fluent $column
     *
     * @return string
     */
    protected function typeTime(Fluent $column): string
    {
        if (!$column->nullable) {
            return 'time default current_time';
        }

        return 'time';
    }

    /**
     * Create the column definition for a timestamp type.
     *
     * @param Fluent $column
     *
     * @return string
     */
    protected function typeTimestamp(Fluent $column): string
    {
        if (!$column->nullable) {
            return 'timestamp default current_timestamp';
        }

        return 'timestamp';
    }

    /**
     * Create the column definition for a binary type.
     *
     * @param Fluent $column
     *
     * @return string
     */
    protected function typeBinary(Fluent $column): string
    {
        return 'blob';
    }

    /**
     * Create the column definition for a uuid type.
     *
     * @param Fluent $column
     *
     * @return string
     */
    protected function typeUuid(Fluent $column): string
    {
        return 'char(36)';
    }

    /**
     * Create the column definition for a JSON type.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeJson(Fluent $column): string
    {
        return 'clob';
    }

    /**
     * Create the column definition for a jsonb type.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeJsonb(Fluent $column): string
    {
        return 'blob';
    }

    /**
     * Get the SQL for a nullable column modifier.
     *
     * @param Blueprint $blueprint
     * @param Fluent $column
     *
     * @return string|null
     */
    protected function modifyNullable(Blueprint $blueprint, Fluent $column): ?string
    {
        return $column->nullable ? '' : ' not null';
    }

    /**
     * Get the SQL for a default column modifier.
     *
     * @param Blueprint $blueprint
     * @param Fluent $column
     *
     * @return string|null
     */
    protected function modifyDefault(Blueprint $blueprint, Fluent $column): ?string
    {
        if (!is_null($column->default)) {
            return " default " . $this->getDefaultValue($column->default);
        }

        return null;
    }

    /**
     * Get the SQL for an auto-increment column modifier.
     *
     * @param Blueprint $blueprint
     * @param Fluent $column
     *
     * @return string|null
     */
    protected function modifyIncrement(Blueprint $blueprint, Fluent $column): ?string
    {
        if (!in_array($column->type, $this->serials) || !$column->autoIncrement) {
            return null;
        }

        // DB2 identity columns do not have to be the key. Leave the primary key off when it
        // is declared elsewhere, or opted out of with ->withoutPrimaryKey(); a second primary
        // key on the same table is a SQL0624 error.
        // Note ->primary(false) is not the opt-out: Laravel reads that as a primary key whose
        // name is false, which compiles to "add constraint  primary key (...)".
        if ($column->withoutPrimaryKey === true || $this->hasCommand($blueprint, 'primary')) {
            return ' generated by default as identity';
        }

        return ' generated by default as identity constraint ' . $blueprint->getTable() . '_' . $column->name . '_primary primary key';
    }

    /**
     * Get the SQL for an increment column clause
     *
     * @param Fluent $column
     *
     * @return string
     */
    protected function compileIncrement(Fluent $column): string
    {
        $type = in_array($column->type, ['bigInteger', 'bigIncrements', 'unsignedBigInteger'], true)
            ? 'BIGINT'
            : 'INTEGER';

        return sprintf(
            '%s GENERATED BY DEFAULT AS IDENTITY (START WITH 1, INCREMENT BY 1, NO MINVALUE NO MAXVALUE NO CYCLE)',
            $type
        );
    }

    /**
     * Get the SQL for a "before" column modifier.
     *
     * @param Blueprint $blueprint
     * @param Fluent $column
     *
     * @return string|null
     */
    protected function modifyBefore(Blueprint $blueprint, Fluent $column): ?string
    {
        if (!is_null($column->before)) {
            return ' before ' . $this->wrap($column->before);
        }

        return null;
    }

    /**
     * Get the SQL for a "for column" column modifier.
     *
     * @param Blueprint $blueprint
     * @param Fluent $column
     *
     * @return string|null
     */
    protected function modifyForColumn(Blueprint $blueprint, Fluent $column): ?string
    {
        if (!is_null($column->forColumn)) {
            return ' for column ' . $this->wrap($column->forColumn);
        }

        return null;
    }

    /**
     * Get the SQL for a "generated" column modifier.
     *
     * @param Blueprint $blueprint
     * @param Fluent $column
     *
     * @return string|null
     */
    protected function modifyGenerated(Blueprint $blueprint, Fluent $column): ?string
    {
        if (!is_null($column->generated)) {
            return ' generated ' . ($column->generated === true ? 'always' : $this->wrap($column->generated));
        }

        return null;
    }

    /**
     * Get the SQL for a "startWith" column modifier.
     *
     * @param Blueprint $blueprint
     * @param Fluent $column
     *
     * @return string|null
     */
    protected function modifyStartWith(Blueprint $blueprint, Fluent $column): ?string
    {
        if (!is_null($column->startWith)) {
            return ' (start with ' . $column->startWith . ')';
        }

        return null;
    }

    /**
     * Get the SQL for an "implicitly hidden" column modifier.
     *
     * @param Blueprint $blueprint
     * @param Fluent $column
     *
     * @return string|null
     */
    protected function modifyImplicitlyHidden(Blueprint $blueprint, Fluent $column): ?string
    {
        if (!is_null($column->implicitlyHidden)) {
            return ' implicitly hidden';
        }

        return null;
    }

    /**
     * Format a value so that it can be used in "default" clauses.
     *
     * @param mixed $value
     *
     * @return float|bool|int|string|Expression
     */
    protected function getDefaultValue($value): float|bool|int|string|Expression
    {
        if ($value instanceof Expression || is_bool($value) || is_numeric($value)) {
            return $value;
        }

        return "'" . strval($value) . "'";
    }

    /**
     * Compile a executeCommand command.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     *
     * @return string
     */
    private function compileExecuteCommand(Blueprint $blueprint, Fluent $command): string
    {
        return "CALL QSYS2.QCMDEXC('" . $command->command . "')";
    }

    /**
     * Compile an addReplyListEntry command.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return string
     */
    public function compileAddReplyListEntry(Blueprint $blueprint, Fluent $command): string
    {
        $sequenceNumberQuery = <<<EOT
            with reply_list_info(sequence_number) as (
                values(1)
                union all
                select sequence_number + 1
                from reply_list_info
                where sequence_number + 1 between 2 and 9999
            )
            select min(sequence_number) sequence_number
            from reply_list_info
            where not exists (
                select 1
                from qsys2.reply_list_info rli
                where rli.sequence_number = reply_list_info.sequence_number
            )
EOT;

        $blueprint->setReplyListSequenceNumber($sequenceNumber = $this->connection->selectOne($sequenceNumberQuery)->sequence_number);
        $command->command = "ADDRPYLE SEQNBR($sequenceNumber) MSGID(CPA32B2) RPY(''I'')";

        return $this->compileExecuteCommand($blueprint, $command);
    }

    /**
     * Compile a removeReplyListEntry command.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     *
     * @return string
     */
    public function compileRemoveReplyListEntry(Blueprint $blueprint, Fluent $command): string
    {
        $sequenceNumber = $blueprint->getReplyListSequenceNumber();
        $command->command = "RMVRPYLE SEQNBR($sequenceNumber)";

        return $this->compileExecuteCommand($blueprint, $command);
    }

    /**
     * Compile a changeJob command.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     *
     * @return string
     */
    public function compileChangeJob(Blueprint $blueprint, Fluent $command): string
    {
        $command->command = 'CHGJOB INQMSGRPY(*SYSRPYL)';

        return $this->compileExecuteCommand($blueprint, $command);
    }

    /**
     * Compile a rename column command.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     *
     * @throws RuntimeException This driver does not support this operation
     */
    public function compileRenameColumn(Blueprint $blueprint, Fluent $command): void
    {
        throw new RuntimeException('This database driver does not support renaming columns.');
    }

    /**
     * Compile the SQL needed to retrieve all table names.
     *
     * @return string
     */
    public function compileGetAllTables(): string
    {
        return 'select trim(table_schema) || \'.\' || trim(table_name)
                from information_schema.tables
                where table_schema = ? and table_type = \'BASE TABLE\'';
    }
}
