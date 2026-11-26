<?php

namespace Tests\Unit;

use Easi\DB2\Database\DB2Connection;
use Tests\Unit\Helpers\FakePDO;

function schemaTransactionsConnection(array $config = []): DB2Connection
{
    $connection = new DB2Connection(new FakePDO(), 'TESTDB', '', [
        'driver' => 'db2_ibmi_odbc',
        'schema' => 'test',
    ] + $config);

    $connection->useDefaultSchemaGrammar();

    return $connection;
}

it('does not wrap migrations in transactions by default', function () {
    $grammar = schemaTransactionsConnection()->getSchemaGrammar();

    expect($grammar->supportsSchemaTransactions())->toBeFalse();
});

it('wraps migrations in transactions when the option is on', function () {
    $grammar = schemaTransactionsConnection(['schema_transactions' => true])->getSchemaGrammar();

    expect($grammar->supportsSchemaTransactions())->toBeTrue();
});

it('keeps migrations unwrapped when the option is explicitly off', function () {
    $grammar = schemaTransactionsConnection(['schema_transactions' => false])->getSchemaGrammar();

    expect($grammar->supportsSchemaTransactions())->toBeFalse();
});

it('reads the schema_transactions option as a boolean', function (mixed $value, bool $expected) {
    $grammar = schemaTransactionsConnection(['schema_transactions' => $value])->getSchemaGrammar();

    expect($grammar->supportsSchemaTransactions())->toBe($expected);
})->with([
    'boolean true' => [true, true],
    'string true' => ['true', true],
    'string 1' => ['1', true],
    'integer 1' => [1, true],
    'boolean false' => [false, false],
    'string false' => ['false', false],
    'string 0' => ['0', false],
    'integer 0' => [0, false],
    'empty string' => ['', false],
    'null' => [null, false],
    'garbage' => ['yes please', false],
]);
