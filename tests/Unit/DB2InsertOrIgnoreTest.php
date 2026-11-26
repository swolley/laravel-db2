<?php

namespace Tests\Unit;

use Easi\DB2\Database\DB2Connection;
use Easi\DB2\Exceptions\TranslatedQueryException;
use PDOException;
use Tests\Unit\Helpers\FakePDO;

function insertOrIgnoreConnection(?string $sqlState = null, array $failOn = []): array
{
    $pdo = new FakePDO();

    if ($sqlState !== null) {
        $exception = new PDOException("SQLSTATE[$sqlState]: statement failed");
        $exception->errorInfo = [$sqlState, -803, 'statement failed'];
        $pdo->exceptionToThrow = $exception;
        $pdo->throwOnStatements = $failOn;
    }

    return [new DB2Connection($pdo, 'TESTDB', '', ['driver' => 'db2_ibmi_odbc', 'schema' => 'test']), $pdo];
}

describe('insertOrIgnore', function () {
    it('stores every row in one statement when none is a duplicate', function () {
        [$connection, $pdo] = insertOrIgnoreConnection();

        $stored = $connection->table('products')->insertOrIgnore([['sku' => 'a'], ['sku' => 'b']]);

        expect($stored)->toBe(2)
            ->and($pdo->preparedQueries)->toHaveCount(1)
            ->and($pdo->preparedQueries[0])->toStartWith('insert into products');
    });

    it('returns 0 for an empty array without running a statement', function () {
        [$connection, $pdo] = insertOrIgnoreConnection();

        expect($connection->table('products')->insertOrIgnore([]))->toBe(0)
            ->and($pdo->preparedQueries)->toBe([]);
    });

    it('falls back to one row at a time when the batch hits a unique violation', function () {
        [$connection, $pdo] = insertOrIgnoreConnection('23505', [1, 3]);

        $stored = $connection->table('products')->insertOrIgnore([['sku' => 'a'], ['sku' => 'b']]);

        expect($stored)->toBe(1)
            ->and($pdo->preparedQueries)->toHaveCount(3);
    });

    it('counts a duplicate inside the same batch once', function () {
        [$connection, $pdo] = insertOrIgnoreConnection('23505', [1, 3]);

        $stored = $connection->table('products')->insertOrIgnore([['sku' => 'a'], ['sku' => 'a']]);

        expect($stored)->toBe(1);
    });

    it('lets any other error through', function () {
        [$connection] = insertOrIgnoreConnection('42704');

        $connection->table('products')->insertOrIgnore([['sku' => 'a']]);
    })->throws(TranslatedQueryException::class);
});

describe('inRandomOrder', function () {
    it('compiles random ordering to RAND()', function () {
        [$connection] = insertOrIgnoreConnection();

        expect($connection->table('products')->inRandomOrder()->toSql())->toContain('order by RAND()');
    });
});

describe('insertOrIgnore portability', function () {
    it('does not depend on the PHP 8.5 array_first function', function () {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Database/Query/Builder.php');

        expect($source)->not->toMatch('/(?<![\w:>])array_first\(/')
            ->and($source)->toContain('Arr::first(');
    });

    it('wraps a single associative row', function () {
        [$connection, $pdo] = insertOrIgnoreConnection();

        expect($connection->table('products')->insertOrIgnore(['sku' => 'a', 'name' => 'b']))->toBe(1)
            ->and($pdo->preparedQueries)->toHaveCount(1);
    });
});
