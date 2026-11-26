<?php

namespace Tests\Unit;

use Easi\DB2\Database\DB2Connection;
use Tests\Unit\Helpers\FakePDO;

function makeSchemaConnection(FakePDO $pdo): DB2Connection
{
    return new DB2Connection($pdo, 'TESTDB', '', ['driver' => 'db2_ibmi_odbc', 'schema' => 'test']);
}

function fakeCatalog(): FakePDO
{
    $pdo = new FakePDO();
    $pdo->resultsByQuery = [
        'qsys2.syscst' => [
            (object) ['constraint_schema' => 'TEST', 'constraint_name' => 'orders_customer_fk', 'table_schema' => 'TEST', 'table_name' => 'orders'],
            (object) ['constraint_schema' => 'TEST', 'constraint_name' => 'products_parent_fk', 'table_schema' => 'TEST', 'table_name' => 'products'],
        ],
        'qsys2.systables' => [
            (object) ['00001' => 'TEST.orders'],
            (object) ['00001' => 'TEST.customers'],
            (object) ['00001' => 'TEST.products'],
        ],
        'qsys2.sysviews' => [
            (object) ['00001' => 'TEST.order_lines'],
            (object) ['00001' => 'TEST.product_stock'],
        ],
    ];

    return $pdo;
}

/** @return list<string> */
function executedStatements(FakePDO $pdo): array
{
    return array_values(array_filter(
        $pdo->preparedQueries,
        fn (string $sql): bool => str_starts_with($sql, 'alter table') || str_starts_with($sql, 'drop '),
    ));
}

it('drops foreign keys before any table', function () {
    $pdo = fakeCatalog();
    makeSchemaConnection($pdo)->getSchemaBuilder()->dropAllTables();

    $statements = executedStatements($pdo);

    expect($statements)->toHaveCount(5)
        ->and($statements[0])->toBe('alter table TEST.orders drop constraint orders_customer_fk')
        ->and($statements[1])->toBe('alter table TEST.products drop constraint products_parent_fk')
        ->and(array_slice($statements, 2))->each->toStartWith('drop table');
});

it('does not drop a view as a table', function () {
    $pdo = fakeCatalog();
    makeSchemaConnection($pdo)->getSchemaBuilder()->dropAllTables();

    $dropped = implode("\n", executedStatements($pdo));

    expect($dropped)->not->toContain('order_lines')
        ->and($dropped)->not->toContain('product_stock');

    $tableQuery = collect($pdo->preparedQueries)->first(fn (string $sql): bool => str_contains($sql, 'qsys2.systables'));
    expect($tableQuery)->toContain("'T'")->toContain("'P'")->not->toContain("'V'");
});

it('drops every view of the schema', function () {
    $pdo = fakeCatalog();
    makeSchemaConnection($pdo)->getSchemaBuilder()->dropAllViews();

    expect(executedStatements($pdo))->toBe([
        'drop view TEST.order_lines',
        'drop view TEST.product_stock',
    ]);
});

it('reads the catalog of the default schema only', function () {
    $pdo = fakeCatalog();
    makeSchemaConnection($pdo)->getSchemaBuilder()->dropAllTables();
    makeSchemaConnection($pdo)->getSchemaBuilder()->dropAllViews();

    $catalogReads = array_filter($pdo->bound, fn (array $entry): bool => str_contains($entry[0], 'qsys2.'));

    expect($catalogReads)->not->toBeEmpty();
    foreach ($catalogReads as [, $values]) {
        expect($values)->toBe(['TEST']);
    }
});
