<?php

namespace Tests\Unit;

use Easi\DB2\Database\DB2Connection;
use Tests\Unit\Helpers\FakePDO;

function useWhereLikeTestConnection(): DB2Connection
{
    return new DB2Connection(new FakePDO(), 'TESTDB', '', ['driver' => 'db2_ibmi_odbc', 'schema' => 'test']);
}

it('compiles a case-insensitive like by upper-casing both sides', function () {
    $query = useWhereLikeTestConnection()->table('products')->whereLike('name', '%ab%');

    expect($query->toSql())->toBe('select * from products where upper(name) like upper(?)')
        ->and($query->getBindings())->toBe(['%ab%']);
});

it('compiles a case-sensitive like as a plain like', function () {
    $query = useWhereLikeTestConnection()->table('products')->whereLike('name', '%ab%', caseSensitive: true);

    expect($query->toSql())->toBe('select * from products where name like ?')
        ->and($query->getBindings())->toBe(['%ab%']);
});

it('compiles a negated case-insensitive like', function () {
    $query = useWhereLikeTestConnection()->table('products')->whereNotLike('name', '%ab%');

    expect($query->toSql())->toBe('select * from products where upper(name) not like upper(?)');
});

it('compiles a negated case-sensitive like as a plain not like', function () {
    $query = useWhereLikeTestConnection()->table('products')->whereNotLike('name', '%ab%', caseSensitive: true);

    expect($query->toSql())->toBe('select * from products where name not like ?');
});

it('joins an or where like with or', function () {
    $query = useWhereLikeTestConnection()->table('products')
        ->where('sku', 'ABC-1')
        ->orWhereLike('name', '%ab%');

    expect($query->toSql())->toBe('select * from products where sku = ? or upper(name) like upper(?)')
        ->and($query->getBindings())->toBe(['ABC-1', '%ab%']);
});
