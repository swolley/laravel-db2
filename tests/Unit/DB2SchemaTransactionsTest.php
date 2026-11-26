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
