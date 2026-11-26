<?php

namespace Tests\Unit;

use Easi\DB2\Database\DB2Connection;
use Easi\DB2\Database\ReservedWords;
use Easi\DB2\Database\Schema\Blueprint;
use Tests\Unit\Helpers\FakePDO;

function quotingConnection(bool $quote): DB2Connection
{
    $connection = new DB2Connection(new FakePDO(), 'TESTDB', '', [
        'driver' => 'db2_ibmi_odbc',
        'schema' => 'test',
        'quote_reserved_words' => $quote,
    ]);

    $connection->useDefaultSchemaGrammar();

    return $connection;
}

describe('reserved word quoting', function () {
    it('leaves identifiers alone when the option is off', function () {
        $sql = quotingConnection(false)->query()->select('order')->from('products')->toSql();

        expect($sql)->toBe('select order from products');
    });

    it('quotes a reserved word upper-cased when the option is on', function () {
        $connection = quotingConnection(true);

        expect($connection->query()->select('position')->from('products')->toSql())
            ->toBe('select "POSITION" from products')
            ->and($connection->query()->select('Type')->from('products')->toSql())
            ->toBe('select "TYPE" from products');
    });

    it('leaves ordinary identifiers unquoted', function () {
        $sql = quotingConnection(true)->query()->select('sku')->from('products')->toSql();

        expect($sql)->toBe('select sku from products');
    });

    it('quotes each segment on its own', function () {
        $sql = quotingConnection(true)->query()->select('products.position')->from('products')->toSql();

        expect($sql)->toBe('select products."POSITION" from products');
    });

    it('keeps star and aliases working', function () {
        $connection = quotingConnection(true);

        expect($connection->query()->select('*')->from('products')->toSql())
            ->toBe('select * from products')
            ->and($connection->query()->select('position as pos')->from('products')->toSql())
            ->toBe('select "POSITION" as pos from products');
    });

    it('quotes a reserved column in a schema statement', function () {
        $connection = quotingConnection(true);

        $blueprint = new Blueprint($connection, 'products', function (Blueprint $table) {
            $table->create();
            $table->integer('position');
        });

        expect($blueprint->toSql())->toBe(['create table products ("POSITION" int not null)']);
    });

    it('quotes a reserved column wrapped by hand inside raw SQL', function () {
        $connection = quotingConnection(true);
        $grammar = $connection->getQueryGrammar();

        $sql = $connection->query()->from('products')->whereRaw($grammar->wrap('position') . ' > ?', [1])->toSql();

        expect($sql)->toBe('select * from products where "POSITION" > ?');
    });

    it('quotes a reserved table name in create table and in select', function () {
        $connection = quotingConnection(true);

        $blueprint = new Blueprint($connection, 'cache', function (Blueprint $table) {
            $table->create();
            $table->string('key');
        });

        expect($blueprint->toSql()[0])->toStartWith('create table "CACHE" (')
            ->and($connection->query()->from('cache')->toSql())->toBe('select * from "CACHE"');
    });

    it('quotes a reserved boolean column inside its check', function () {
        $blueprint = new Blueprint(quotingConnection(true), 'products', function (Blueprint $table) {
            $table->create();
            $table->boolean('default');
        });

        expect($blueprint->toSql()[0])->toContain('check("DEFAULT" in(0, 1))');
    });

    it('looks words up case-insensitively', function () {
        expect(ReservedWords::contains('Order'))->toBeTrue()
            ->and(ReservedWords::contains('sku'))->toBeFalse();
    });
});
