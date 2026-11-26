<?php

namespace Tests\Unit;

use Easi\DB2\Database\Schema\Blueprint;

function typesSql(callable $define): string
{
    $blueprint = new Blueprint(blueprintTestConnection(), 'products', function (Blueprint $table) use ($define) {
        $table->create();
        $define($table);
    });

    return $blueprint->toSql()[0];
}

describe('enum and set columns', function () {
    it('compiles an enum to a varchar sized to its longest value with a check', function () {
        expect(typesSql(fn (Blueprint $t) => $t->enum('status', ['draft', 'published'])))
            ->toBe("create table products (status varchar(9) check (status in ('draft', 'published')) not null)");
    });

    it('sizes an enum in bytes, not characters', function () {
        expect(typesSql(fn (Blueprint $t) => $t->enum('city', ['roma', 'città'])))
            ->toContain('city varchar(6)');
    });

    it('escapes a quote inside an enum value', function () {
        expect(typesSql(fn (Blueprint $t) => $t->enum('kind', ["o'clock"])))
            ->toContain("check (kind in ('o''clock'))");
    });

    it('compiles a set to a varchar long enough for every value', function () {
        expect(typesSql(fn (Blueprint $t) => $t->set('flags', ['a', 'bb'])))
            ->toBe('create table products (flags varchar(4) not null)');
    });
});

describe('boolean defaults', function () {
    it('compiles boolean defaults to 0 and 1', function () {
        $false = typesSql(fn (Blueprint $t) => $t->boolean('active')->default(false));
        $true = typesSql(fn (Blueprint $t) => $t->boolean('active')->default(true));

        expect($false)->toContain('default 0')
            ->and($true)->toContain('default 1')
            ->and($false)->not->toMatch('/default\s*(\)|$)/')
            ->and($true)->not->toMatch('/default\s*(\)|$)/');
    });
});

describe('float columns', function () {
    it('compiles a float with the default precision to double', function () {
        expect(typesSql(fn (Blueprint $t) => $t->float('score')))
            ->toBe('create table products (score double not null)');
    });

    it('compiles a single precision float to real', function () {
        expect(typesSql(fn (Blueprint $t) => $t->float('score', 24)))
            ->toBe('create table products (score real not null)')
            ->and(typesSql(fn (Blueprint $t) => $t->float('score', 25)))
            ->toBe('create table products (score double not null)');
    });
});

describe('boolean check', function () {
    it('wraps the column name inside the check', function () {
        expect(typesSql(fn (Blueprint $t) => $t->boolean('is"on')))
            ->toContain('check(is""on in(0, 1))');
    });
});

describe('string defaults', function () {
    it('escapes a single quote inside a string default', function () {
        expect(typesSql(fn (Blueprint $t) => $t->string('label')->default("it's")))
            ->toContain("default 'it''s'");
    });
});
