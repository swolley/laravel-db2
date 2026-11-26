<?php

namespace Tests\Unit;

use Easi\DB2\Database\DB2Connection;
use Tests\Unit\Helpers\FakePDO;

function jsonTestConnection(): DB2Connection
{
    return new DB2Connection(new FakePDO(), 'TESTDB', '', [
        'driver' => 'db2_ibmi_odbc',
        'schema' => 'test',
    ]);
}

function jsonQuery(): \Illuminate\Database\Query\Builder
{
    return jsonTestConnection()->table('products');
}

describe('JSON path queries', function () {
    it('compiles a json selector with JSON_VALUE in lax mode', function () {
        $query = jsonQuery()->where('meta->color', 'red');

        expect($query->toSql())->toBe('select * from products where JSON_VALUE(meta, \'lax $."color"\') = ?');
        expect($query->getBindings())->toBe(['red']);
    });

    it('compiles nested keys and array indexes', function () {
        expect(jsonQuery()->where('meta->items[0]->sku', 'A')->toSql())
            ->toBe('select * from products where JSON_VALUE(meta, \'lax $."items"[0]."sku"\') = ?');
    });

    it('quotes a key that contains a dot', function () {
        expect(jsonQuery()->where('meta->"a.b"', 'A')->toSql())
            ->toBe('select * from products where JSON_VALUE(meta, \'lax $."a.b"\') = ?');
    });

    it('compares json booleans as true and false', function () {
        $query = jsonQuery()->where('meta->active', true);

        expect($query->toSql())->toBe('select * from products where JSON_VALUE(meta, \'lax $."active"\') = \'true\'');
        expect($query->getBindings())->toBe([]);
        expect(jsonQuery()->where('meta->active', false)->toSql())->toEndWith('= \'false\'');
    });

    it('extracts numbers as decimals when the value is numeric', function () {
        expect(jsonQuery()->where('meta->qty', '>', 3)->toSql())
            ->toBe('select * from products where JSON_VALUE(meta, \'lax $."qty"\' RETURNING DECFLOAT(34)) > ?');
        expect(jsonQuery()->where('meta->price', '<', 2.5)->toSql())
            ->toContain('RETURNING DECFLOAT(34)');
    });

    it('does not add a numeric cast for numeric strings or plain columns', function () {
        expect(jsonQuery()->where('meta->qty', '>', '3')->toSql())->not->toContain('RETURNING');
        expect(jsonQuery()->where('sort_order', '>', 3)->toSql())
            ->toBe('select * from products where sort_order > ?');
    });

    it('compiles json contains', function () {
        $query = jsonQuery()->whereJsonContains('tags', 'red');

        expect($query->toSql())->toBe(
            'select * from products where EXISTS (SELECT 1 FROM JSON_TABLE(tags, \'lax $[*]\' COLUMNS (V VARCHAR(4000) PATH \'$\')) AS JT WHERE JT.V = ?)'
        );
        expect($query->getBindings())->toBe(['red']);
    });

    it('compiles json contains on a nested path', function () {
        expect(jsonQuery()->whereJsonContains('meta->tags', 'red')->toSql())->toContain(
            'JSON_TABLE(meta, \'lax $."tags"[*]\' COLUMNS'
        );
    });

    it('requires every element of an array value', function () {
        $query = jsonQuery()->whereJsonContains('tags', ['red', 'blue']);

        expect(substr_count($query->toSql(), 'EXISTS'))->toBe(2);
        expect($query->toSql())->toContain(') AND EXISTS (');
        expect(substr_count($query->toSql(), '?'))->toBe(2);
        expect($query->getBindings())->toBe(['red', 'blue']);
    });

    it('compiles json doesnt contain', function () {
        $query = jsonQuery()->whereJsonDoesntContain('tags', 'red');

        expect($query->toSql())->toBe(
            'select * from products where not EXISTS (SELECT 1 FROM JSON_TABLE(tags, \'lax $[*]\' COLUMNS (V VARCHAR(4000) PATH \'$\')) AS JT WHERE JT.V = ?)'
        );
        expect($query->getBindings())->toBe(['red']);
    });

    it('compiles json length', function () {
        $query = jsonQuery()->whereJsonLength('meta->tags', '>', 1);

        expect($query->toSql())->toBe(
            'select * from products where (SELECT COUNT(*) FROM JSON_TABLE(meta, \'lax $."tags"[*]\' COLUMNS (V VARCHAR(4000) PATH \'$\')) AS JT) > ?'
        );
        expect($query->getBindings())->toBe([1]);
    });
});
