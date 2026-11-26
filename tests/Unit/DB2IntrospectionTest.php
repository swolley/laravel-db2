<?php

namespace Tests\Unit;

use Easi\DB2\Database\DB2Connection;
use Tests\Unit\Helpers\FakePDO;

function introspectionConnection(array $selectResults = []): DB2Connection
{
    $pdo = new FakePDO();
    $pdo->selectResults = $selectResults;

    $connection = new DB2Connection($pdo, 'TESTDB', '', ['driver' => 'db2_ibmi_odbc', 'schema' => 'test']);
    $connection->getSchemaBuilder();

    return $connection;
}

function introspectionPdo(DB2Connection $connection): FakePDO
{
    /** @var FakePDO */
    return $connection->getPdo();
}

describe('catalog queries', function () {
    it('lists schemas from QSYS2.SYSSCHEMAS and flags the default one', function () {
        $sql = introspectionConnection()->getSchemaGrammar()->compileSchemas();

        expect(strtolower($sql))->toContain('qsys2.sysschemas')
            ->and($sql)->toContain("schema_name = 'TEST'");
    });

    it('lists base tables from QSYS2.SYSTABLES for the default schema when none is given', function () {
        $sql = introspectionConnection()->getSchemaGrammar()->compileTables(null);

        expect(strtolower($sql))->toContain('qsys2.systables')
            ->and($sql)->toContain("table_schema = 'TEST'")
            ->and(strtolower($sql))->toContain("table_type in ('t', 'p')");
    });

    it('accepts one schema or an array of schemas', function () {
        $grammar = introspectionConnection()->getSchemaGrammar();

        expect($grammar->compileTables('inventory'))->toContain("table_schema = 'INVENTORY'")
            ->and($grammar->compileTables(['inventory', 'users']))
            ->toContain("table_schema in ('INVENTORY', 'USERS')");
    });

    it('lists views from QSYS2.SYSVIEWS', function () {
        $sql = introspectionConnection()->getSchemaGrammar()->compileViews('inventory');

        expect(strtolower($sql))->toContain('qsys2.sysviews')
            ->and(strtolower($sql))->toContain('view_definition')
            ->and($sql)->toContain("table_schema = 'INVENTORY'");
    });

    it('reads columns from QSYS2.SYSCOLUMNS in ordinal order', function () {
        $sql = introspectionConnection()->getSchemaGrammar()->compileColumns('inventory', 'products');

        expect(strtolower($sql))->toContain('qsys2.syscolumns')
            ->and($sql)->toContain("table_schema = 'INVENTORY'")
            ->and($sql)->toContain("table_name = 'PRODUCTS'")
            ->and(strtolower($sql))->toContain('order by ordinal_position');
    });

    it('reads indexes from SYSINDEXES with SYSKEYS plus the key constraints', function () {
        $sql = introspectionConnection()->getSchemaGrammar()->compileIndexes(null, 'products');

        expect(strtolower($sql))->toContain('qsys2.sysindexes')
            ->and(strtolower($sql))->toContain('qsys2.syskeys')
            ->and(strtolower($sql))->toContain('qsys2.syscst')
            ->and(strtolower($sql))->toContain('qsys2.syskeycst')
            ->and($sql)->toContain("table_schema = 'TEST'")
            ->and($sql)->toContain("table_name = 'PRODUCTS'");
    });

    it('reads foreign keys from SYSREFCST with SYSKEYCST', function () {
        $sql = introspectionConnection()->getSchemaGrammar()->compileForeignKeys('inventory', 'order_lines');

        expect(strtolower($sql))->toContain('qsys2.sysrefcst')
            ->and(strtolower($sql))->toContain('qsys2.syskeycst')
            ->and(strtolower($sql))->toContain("'foreign key'")
            ->and($sql)->toContain("table_schema = 'INVENTORY'")
            ->and($sql)->toContain("table_name = 'ORDER_LINES'");
    });
});

describe('processors', function () {
    it('maps catalog rows to Laravel column shapes', function () {
        $columns = introspectionConnection()->getPostProcessor()->processColumns([
            (object) [
                'name' => 'SKU', 'type_name' => 'VARCHAR', 'length' => 20, 'numeric_scale' => 0,
                'is_nullable' => 'N', 'column_default' => null, 'has_default' => 'N',
                'is_identity' => 'NO', 'comment' => null,
            ],
            (object) [
                'name' => 'PRICE', 'type_name' => 'DECIMAL', 'length' => 9, 'numeric_scale' => 2,
                'is_nullable' => 'Y', 'column_default' => '0', 'has_default' => 'Y',
                'is_identity' => 'NO', 'comment' => 'Unit price',
            ],
            (object) [
                'name' => 'CREATED', 'type_name' => 'TIMESTMP', 'length' => 26, 'numeric_scale' => 6,
                'is_nullable' => 'Y', 'column_default' => null, 'has_default' => 'N',
                'is_identity' => 'NO', 'comment' => null,
            ],
        ]);

        expect($columns[0])->toBe([
            'name' => 'sku',
            'type_name' => 'varchar',
            'type' => 'varchar(20)',
            'collation' => null,
            'nullable' => false,
            'default' => null,
            'auto_increment' => false,
            'comment' => null,
            'generation' => null,
        ])->and($columns[1]['type'])->toBe('decimal(9,2)')
            ->and($columns[1]['default'])->toBe('0')
            ->and($columns[1]['comment'])->toBe('Unit price')
            ->and($columns[1]['nullable'])->toBeTrue()
            ->and($columns[2]['type_name'])->toBe('timestamp')
            ->and($columns[2]['type'])->toBe('timestamp');
    });

    it('accepts upper-case catalog keys', function () {
        $columns = introspectionConnection()->getPostProcessor()->processColumns([
            (object) ['NAME' => 'SKU', 'TYPE_NAME' => 'CHAR', 'LENGTH' => 3, 'IS_NULLABLE' => 'Y', 'IS_IDENTITY' => 'NO'],
        ]);

        expect($columns[0]['name'])->toBe('sku')
            ->and($columns[0]['type'])->toBe('char(3)');
    });

    it('lower-cases catalog names', function () {
        $processor = introspectionConnection()->getPostProcessor();

        $tables = $processor->processTables([(object) ['name' => 'ORDER', 'schema' => 'INVENTORY', 'comment' => null]]);
        $views = $processor->processViews([(object) ['name' => 'ORDER_LINES', 'schema' => 'INVENTORY', 'definition' => 'select 1']]);
        $schemas = $processor->processSchemas([(object) ['name' => 'INVENTORY', 'default' => 1]]);

        expect($tables[0]['name'])->toBe('order')
            ->and($tables[0]['schema'])->toBe('inventory')
            ->and($tables[0]['schema_qualified_name'])->toBe('inventory.order')
            ->and($views[0]['name'])->toBe('order_lines')
            ->and($views[0]['schema_qualified_name'])->toBe('inventory.order_lines')
            ->and($schemas[0])->toBe(['name' => 'inventory', 'path' => null, 'default' => true]);
    });

    it('marks identity columns as auto-increment', function () {
        $columns = introspectionConnection()->getPostProcessor()->processColumns([
            (object) ['name' => 'ID', 'type_name' => 'INTEGER', 'length' => 4, 'is_nullable' => 'N', 'is_identity' => 'YES', 'column_default' => null],
        ]);

        expect($columns[0]['auto_increment'])->toBeTrue()
            ->and($columns[0]['type'])->toBe('integer');
    });

    it('groups multi-column indexes and flags the primary key', function () {
        $indexes = introspectionConnection()->getPostProcessor()->processIndexes([
            (object) ['name' => 'PK_ORDER_LINES', 'column_name' => 'ORDER_ID', 'ordinal_position' => 1, 'kind' => 'P', 'is_unique' => 'U'],
            (object) ['name' => 'PK_ORDER_LINES', 'column_name' => 'LINE_NO', 'ordinal_position' => 2, 'kind' => 'P', 'is_unique' => 'U'],
            (object) ['name' => 'UQ_ORDER_LINES_SKU', 'column_name' => 'SKU', 'ordinal_position' => 1, 'kind' => 'U', 'is_unique' => 'U'],
            (object) ['name' => 'IX_ORDER_LINES_SKU_QTY', 'column_name' => 'SKU', 'ordinal_position' => 1, 'kind' => 'I', 'is_unique' => 'D'],
            (object) ['name' => 'IX_ORDER_LINES_SKU_QTY', 'column_name' => 'QTY', 'ordinal_position' => 2, 'kind' => 'I', 'is_unique' => 'D'],
        ]);

        expect($indexes)->toBe([
            ['name' => 'pk_order_lines', 'columns' => ['order_id', 'line_no'], 'type' => null, 'unique' => true, 'primary' => true],
            ['name' => 'uq_order_lines_sku', 'columns' => ['sku'], 'type' => null, 'unique' => true, 'primary' => false],
            ['name' => 'ix_order_lines_sku_qty', 'columns' => ['sku', 'qty'], 'type' => null, 'unique' => false, 'primary' => false],
        ]);
    });

    it('drops the implicit index that backs a key constraint', function () {
        $indexes = introspectionConnection()->getPostProcessor()->processIndexes([
            (object) ['name' => 'PK_PRODUCTS', 'column_name' => 'SKU', 'ordinal_position' => 1, 'kind' => 'P', 'is_unique' => 'U'],
            (object) ['name' => 'Q_INVENTORY_PRODUCTS_SKU_00001', 'column_name' => 'SKU', 'ordinal_position' => 1, 'kind' => 'I', 'is_unique' => 'U'],
        ]);

        expect($indexes)->toHaveCount(1)
            ->and($indexes[0]['primary'])->toBeTrue();
    });

    it('maps referential actions to Laravel words', function () {
        $foreignKeys = introspectionConnection()->getPostProcessor()->processForeignKeys([
            (object) ['name' => 'FK_ORDER_LINES_ORDERS', 'column_name' => 'ORDER_ID', 'foreign_schema' => 'INVENTORY', 'foreign_table' => 'ORDERS', 'foreign_column' => 'ID', 'update_rule' => 'NO ACTION', 'delete_rule' => 'CASCADE'],
            (object) ['name' => 'FK_ORDER_LINES_PRODUCTS', 'column_name' => 'SKU', 'foreign_schema' => 'INVENTORY', 'foreign_table' => 'PRODUCTS', 'foreign_column' => 'SKU', 'update_rule' => 'RESTRICT', 'delete_rule' => 'SET NULL'],
            (object) ['name' => 'FK_ORDER_LINES_CUSTOMERS', 'column_name' => 'CUSTOMER_ID', 'foreign_schema' => 'INVENTORY', 'foreign_table' => 'CUSTOMERS', 'foreign_column' => 'ID', 'update_rule' => 'SET DEFAULT', 'delete_rule' => null],
        ]);

        expect($foreignKeys[0])->toBe([
            'name' => 'fk_order_lines_orders',
            'columns' => ['order_id'],
            'foreign_schema' => 'inventory',
            'foreign_table' => 'orders',
            'foreign_columns' => ['id'],
            'on_update' => 'no action',
            'on_delete' => 'cascade',
        ])->and($foreignKeys[1]['on_update'])->toBe('restrict')
            ->and($foreignKeys[1]['on_delete'])->toBe('set null')
            ->and($foreignKeys[2]['on_update'])->toBe('set default')
            ->and($foreignKeys[2]['on_delete'])->toBeNull();
    });

    it('groups composite foreign keys in key order', function () {
        $foreignKeys = introspectionConnection()->getPostProcessor()->processForeignKeys([
            (object) ['name' => 'FK_STOCK', 'column_name' => 'WAREHOUSE', 'ordinal_position' => 2, 'foreign_schema' => 'INVENTORY', 'foreign_table' => 'PRODUCT_STOCK', 'foreign_column' => 'WH', 'update_rule' => 'NO ACTION', 'delete_rule' => 'NO ACTION'],
            (object) ['name' => 'FK_STOCK', 'column_name' => 'SKU', 'ordinal_position' => 1, 'foreign_schema' => 'INVENTORY', 'foreign_table' => 'PRODUCT_STOCK', 'foreign_column' => 'SKU', 'update_rule' => 'NO ACTION', 'delete_rule' => 'NO ACTION'],
        ]);

        expect($foreignKeys)->toHaveCount(1)
            ->and($foreignKeys[0]['columns'])->toBe(['sku', 'warehouse'])
            ->and($foreignKeys[0]['foreign_columns'])->toBe(['sku', 'wh']);
    });
});

describe('bound catalog names', function () {
    it('never wraps a marker or a literal in a function', function () {
        $statements = [introspectionConnection()->getSchemaGrammar()->compileGetAllTables()];

        foreach (['db2_ibmi_odbc', 'db2_expressc_odbc'] as $driver) {
            $connection = new DB2Connection(new FakePDO(), 'TESTDB', '', ['driver' => $driver, 'schema' => 'test']);
            $connection->getSchemaBuilder();
            $grammar = $connection->getSchemaGrammar();

            array_push(
                $statements,
                $grammar->compileSchemas(),
                $grammar->compileTables(['inventory', 'users']),
                $grammar->compileViews(null),
                $grammar->compileColumns('inventory', 'products'),
                $grammar->compileIndexes(null, 'products'),
                $grammar->compileForeignKeys('inventory', 'order_lines'),
                $grammar->compileTableExists(null, 'products'),
                $grammar->compileColumnExists(),
            );
        }

        foreach ($statements as $sql) {
            expect(strtolower($sql))->not->toContain('upper(')->not->toContain('lower(');
        }
    });

    it('compares catalog columns with a bare marker', function () {
        $grammar = introspectionConnection()->getSchemaGrammar();

        expect($grammar->compileColumnExists())->toContain('table_schema = ? and table_name = ?')
            ->and($grammar->compileGetAllTables())->toContain('table_schema = ?');
    });

    it('upper-cases the schema and table bound by getColumnListing', function () {
        $connection = introspectionConnection([(object) ['column_name' => 'SKU']]);

        $connection->getSchemaBuilder()->getColumnListing('Inventory.Products');

        expect(array_values(introspectionPdo($connection)->preparedStatements[0]->boundValues))
            ->toBe(['INVENTORY', 'PRODUCTS']);
    });

    it('upper-cases the default schema and an unqualified table bound by getColumnListing', function () {
        $connection = introspectionConnection([(object) ['column_name' => 'SKU']]);

        $connection->getSchemaBuilder()->getColumnListing('Products');

        expect(array_values(introspectionPdo($connection)->preparedStatements[0]->boundValues))
            ->toBe(['TEST', 'PRODUCTS']);
    });

    it('upper-cases the default schema bound by getAllTables', function () {
        $connection = introspectionConnection();

        $connection->getSchemaBuilder()->getAllTables();

        expect(array_values(introspectionPdo($connection)->preparedStatements[0]->boundValues))
            ->toBe(['TEST']);
    });

    it('upper-cases the literals hasTable compares against', function () {
        $connection = introspectionConnection();

        $connection->getSchemaBuilder()->hasTable('Products');

        expect(introspectionPdo($connection)->preparedQueries[0])
            ->toContain("table_schema = 'TEST' and table_name = 'PRODUCTS'");
    });
});

describe('through the schema builder', function () {
    it('finds a column that is in no index', function () {
        $connection = introspectionConnection([
            (object) ['column_name' => 'SKU'],
            (object) ['column_name' => 'DESCRIPTION'],
        ]);

        $builder = $connection->getSchemaBuilder();

        expect($builder->hasColumn('products', 'sku'))->toBeTrue()
            ->and($builder->getColumnListing('products'))->toBe(['sku', 'description']);

        $sql = strtolower(introspectionPdo($connection)->preparedQueries[0]);

        expect($sql)->toContain('qsys2.syscolumns')
            ->and($sql)->not->toContain('index')
            ->and($sql)->not->toContain('syskeys');
    });

    it('lists columns in lower case and splits schema.table', function () {
        $connection = introspectionConnection([(object) ['column_name' => 'SKU']]);

        $columns = $connection->getSchemaBuilder()->getColumnListing('inventory.products');

        expect($columns)->toBe(['sku'])
            ->and(introspectionPdo($connection)->preparedQueries)->toHaveCount(1);
    });

    it('returns introspection results in Laravel shapes from getTables', function () {
        $connection = introspectionConnection([
            (object) ['name' => 'PRODUCTS', 'schema' => 'TEST', 'comment' => 'Catalogue'],
        ]);

        $tables = $connection->getSchemaBuilder()->getTables();

        expect($tables[0]['schema_qualified_name'])->toBe('test.products')
            ->and($tables[0]['comment'])->toBe('Catalogue')
            ->and($tables[0]['size'])->toBeNull();
    });
});
