<?php

namespace Tests\Unit;

use Easi\DB2\Database\DB2Connection;
use Easi\DB2\Database\Schema\Blueprint;
use InvalidArgumentException;
use Tests\Unit\Helpers\FakePDO;

function ccsidSql(mixed $ccsid, callable $define, bool $omit = false): string
{
    $config = ['driver' => 'db2_ibmi_odbc', 'schema' => 'test'];

    if (! $omit) {
        $config['ccsid'] = $ccsid;
    }

    $connection = new DB2Connection(new FakePDO(), 'TESTDB', '', $config);
    $connection->useDefaultSchemaGrammar();

    $blueprint = new Blueprint($connection, 'products', function (Blueprint $table) use ($define) {
        $table->create();
        $define($table);
    });

    return $blueprint->toSql()[0];
}

describe('column ccsid', function () {
    it('tags character and text columns when the option is set', function () {
        $sql = ccsidSql(1208, function (Blueprint $t) {
            $t->char('code', 3);
            $t->string('name', 40);
            $t->tinyText('note');
            $t->text('body');
            $t->mediumText('summary');
            $t->longText('content');
            $t->json('meta');
            $t->uuid('ref');
        });

        expect($sql)->toBe('create table products ('
            . 'code char(3) ccsid 1208 not null, '
            . 'name varchar(40) ccsid 1208 not null, '
            . 'note varchar(255) ccsid 1208 not null, '
            . 'body clob ccsid 1208 not null, '
            . 'summary clob ccsid 1208 not null, '
            . 'content clob ccsid 1208 not null, '
            . 'meta clob ccsid 1208 not null, '
            . 'ref char(36) ccsid 1208 not null)');
    });

    it('leaves numeric, date, binary and blob columns alone', function () {
        $sql = ccsidSql(1208, function (Blueprint $t) {
            $t->integer('qty');
            $t->decimal('price', 8, 2);
            $t->date('sold_on')->nullable();
            $t->timestamp('sold_at')->nullable();
            $t->binary('picture');
            $t->jsonb('raw');
        });

        expect($sql)->not->toContain('ccsid');
    });

    it('leaves every column alone when the option is absent, null or empty', function (mixed $ccsid, bool $omit) {
        $sql = ccsidSql($ccsid, function (Blueprint $t) {
            $t->string('name', 40);
            $t->text('body');
        }, $omit);

        expect($sql)->toBe('create table products (name varchar(40) not null, body clob not null)');
    })->with([
        'absent' => [null, true],
        'null' => [null, false],
        'empty' => ['', false],
    ]);

    it('accepts an integer given as a string, as env() returns it', function () {
        expect(ccsidSql('1208', fn (Blueprint $t) => $t->string('name', 40)))
            ->toContain('name varchar(40) ccsid 1208');
    });

    it('rejects a value that is not a positive integer', function (mixed $ccsid) {
        ccsidSql($ccsid, fn (Blueprint $t) => $t->string('name', 40));
    })->with(['utf8', '12.5', 0, -1, true])->throws(InvalidArgumentException::class);

    it('tags a column added to an existing table', function () {
        $connection = new DB2Connection(new FakePDO(), 'TESTDB', '', ['driver' => 'db2_ibmi_odbc', 'schema' => 'test', 'ccsid' => 1208]);
        $connection->useDefaultSchemaGrammar();

        $blueprint = new Blueprint($connection, 'products', function (Blueprint $table) {
            $table->string('label', 20)->nullable();
        });

        expect($blueprint->toSql())->toBe(['alter table products add column label varchar(20) ccsid 1208']);
    });
});
