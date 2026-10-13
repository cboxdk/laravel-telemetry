<?php

declare(strict_types=1);

use Cbox\Telemetry\Support\SqlShape;

it('replaces string and numeric literals with placeholders', function () {
    expect(SqlShape::normalize("select * from users where name = 'O''Brien' and age > 42"))
        ->toBe('select * from users where name = ? and age > ?');
});

it('leaves identifiers with digits alone', function () {
    expect(SqlShape::normalize('select "t1"."id" from "orders2" as t1 where t1.v2 = ?'))
        ->toBe('select "t1"."id" from "orders2" as t1 where t1.v2 = ?');
});

it('collapses in-lists of any length to one shape', function () {
    $three = SqlShape::normalize('select * from "posts" where "user_id" in (?, ?, ?)');
    $five = SqlShape::normalize('select * from "posts" where "user_id" in (?,?,?,?,?)');
    $literal = SqlShape::normalize('select * from "posts" where "user_id" in (1, 2)');

    expect($three)->toBe('select * from "posts" where "user_id" in (?)')
        ->and($five)->toBe($three)
        ->and($literal)->toBe($three);
});

it('collapses multi-row inserts to one row', function () {
    expect(SqlShape::normalize('insert into "t" ("a", "b") values (?, ?), (?, ?), (?, ?)'))
        ->toBe('insert into "t" ("a", "b") values (?, ?)');
});

it('collapses whitespace', function () {
    expect(SqlShape::normalize("select *\n   from  users\twhere id = ?"))
        ->toBe('select * from users where id = ?');
});

it('scopes the hash to the connection', function () {
    expect(SqlShape::hash('mysql', 'select 1'))->toHaveLength(16)
        ->and(SqlShape::hash('mysql', 'select 1'))->toBe(SqlShape::hash('mysql', 'select 2'))
        ->and(SqlShape::hash('mysql', 'select 1'))->not->toBe(SqlShape::hash('pgsql', 'select 1'));
});

it('tells reads from writes', function () {
    expect(SqlShape::isRead('select 1'))->toBeTrue()
        ->and(SqlShape::isRead('  WITH x as (select 1) select * from x'))->toBeTrue()
        ->and(SqlShape::isRead('(select 1) union (select 2)'))->toBeTrue()
        ->and(SqlShape::isRead('insert into t values (?)'))->toBeFalse()
        ->and(SqlShape::isRead('update t set a = ?'))->toBeFalse();
});
