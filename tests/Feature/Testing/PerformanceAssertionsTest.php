<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Testing\PerformanceProfile;
use Cbox\Telemetry\Testing\PestExpectations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\AssertionFailedError;

/*
 * The assertions run against a real application: the service provider's
 * own TraceRequest middleware and QueryInstrumentation listener, a real
 * sqlite connection, Telemetry::fake() swapped in afterwards — exactly what
 * an app's test suite gets.
 */

beforeEach(function () {
    DB::statement('create table orders (id integer primary key)');
    DB::statement('create table items (id integer primary key, order_id integer)');

    foreach (range(1, 5) as $id) {
        DB::insert('insert into orders (id) values (?)', [$id]);
        DB::insert('insert into items (order_id) values (?)', [$id]);
    }

    // N+1: one items query per order.
    Route::get('/orders/slow', function () {
        foreach (DB::select('select * from orders') as $order) {
            DB::select('select * from items where order_id = ?', [$order->id]);
        }

        return 'ok';
    });

    // The fix: one whereIn.
    Route::get('/orders/fast', function () {
        $ids = array_column(DB::select('select id from orders'), 'id');
        DB::table('items')->whereIn('order_id', $ids)->get();

        return 'ok';
    });

    // The same lookup three times with the same value.
    Route::get('/orders/repeat', function () {
        foreach (range(1, 3) as $ignored) {
            DB::select('select * from orders where id = ?', [1]);
        }

        return 'ok';
    });

    $this->fake = Telemetry::fake();
});

function failureOf(Closure $assertion): string
{
    try {
        $assertion();
    } catch (AssertionFailedError $e) {
        return $e->getMessage();
    }

    throw new RuntimeException('The assertion was expected to fail, and passed.');
}

it('records the queries of a request made after fake()', function () {
    $this->get('/orders/fast')->assertOk();

    $profiles = $this->fake->profiles();

    expect($profiles)->toHaveCount(1)
        ->and($profiles[0]->label())->toContain('orders/fast')
        ->and($profiles[0]->queryCount())->toBe(2);
});

it('passes a request that stays within budget', function () {
    $this->get('/orders/fast');

    Telemetry::assertQueryCount(2);
    Telemetry::assertQueryCount('<=', 10);
    Telemetry::assertNoNPlusOne();
    Telemetry::assertNoDuplicateQueries();
    Telemetry::assertMaxDuration(60_000);
});

it('fails a query count with every shape, its count and the line that ran it', function () {
    $this->get('/orders/slow');

    $message = failureOf(fn () => Telemetry::assertQueryCount('<=', 3));

    expect($message)
        ->toContain('ran 6 database queries; expected <= 3')
        ->toContain('5× select * from items where order_id = ?')
        ->toContain('1× select * from orders')
        ->toContain('PerformanceAssertionsTest.php:')
        ->toContain('TELEMETRY_INSTRUMENT_QUERY_ORIGIN=all');
});

it('fails an N+1 with the shape, how often it ran and where', function () {
    $this->get('/orders/slow');

    $message = failureOf(fn () => Telemetry::assertNoNPlusOne());

    expect($message)
        ->toContain('orders/slow] has 1 N+1 query pattern')
        ->toContain('Eager-load')
        ->toContain('5× select * from items where order_id = ?')
        ->toMatch('/at .*PerformanceAssertionsTest\.php:\d+/');

    // Same values every time is not an N+1.
    Telemetry::assertNoDuplicateQueries();
});

it('fails duplicates, and tells them apart from an N+1', function () {
    $this->get('/orders/repeat');

    $message = failureOf(fn () => Telemetry::assertNoDuplicateQueries());

    expect($message)
        ->toContain('ran 1 query repeatedly with identical values')
        ->toContain('3× select * from orders where id = ?')
        ->toContain('PerformanceAssertionsTest.php:');

    Telemetry::assertNoNPlusOne();
});

it('checks every request on its own and names the one that failed', function () {
    $this->get('/orders/fast');
    $this->get('/orders/slow');

    $message = failureOf(fn () => Telemetry::assertQueryCount('<=', 2));

    expect($message)->toContain('orders/slow]')->not->toContain('orders/fast]');
});

it('fails a duration budget with the slowest spans', function () {
    $this->get('/orders/slow');

    $message = failureOf(fn () => Telemetry::assertMaxDuration(0.0001));

    expect($message)
        ->toContain('the budget is 0.0001 ms')
        ->toContain('Slowest spans:')
        ->toContain('db.query');
});

it('fails slow queries over a threshold', function () {
    $this->get('/orders/slow');

    Telemetry::assertNoSlowQueries();

    expect(failureOf(fn () => Telemetry::assertNoSlowQueries(0.0)))
        ->toContain('slow query shapes (at or over 0 ms)')
        ->toContain('select * from items where order_id = ?');
});

it('refuses to pass when no trace was recorded', function () {
    expect(failureOf(fn () => Telemetry::assertQueryCount('<=', 10)))
        ->toContain('found no recorded trace');
});

it('refuses to pass when the detector it reads is switched off', function () {
    $this->get('/orders/slow');

    config()->set('telemetry.instrument.query_duplicates', false);

    expect(failureOf(fn () => Telemetry::assertNoNPlusOne()))
        ->toContain('[telemetry.instrument.query_duplicates] is off')
        ->toContain('TELEMETRY_INSTRUMENT_QUERY_DUPLICATES=true');
});

it('refuses to count queries when the query instrumentation is off', function () {
    $this->get('/orders/fast');

    config()->set('telemetry.instrument.queries', false);

    expect(failureOf(fn () => Telemetry::assertQueryCount('<=', 10)))
        ->toContain('[telemetry.instrument.queries] is off');
});

it('rejects an unknown operator', function () {
    $this->get('/orders/fast');

    Telemetry::assertQueryCount('~', 2);
})->throws(InvalidArgumentException::class, 'Unknown operator [~]');

describe('budget()', function () {
    it('holds a closure outside any request to a budget and returns its value', function () {
        $orders = Telemetry::budget(fn () => DB::select('select * from orders'), queries: 1);

        expect($orders)->toHaveCount(5);
    });

    it('fails a closure that runs an N+1, even under its query budget', function () {
        $message = failureOf(fn () => Telemetry::budget(function () {
            foreach (DB::select('select * from orders') as $order) {
                DB::select('select * from items where order_id = ?', [$order->id]);
            }
        }, queries: 50));

        expect($message)
            ->toContain('[budget] has 1 N+1 query pattern')
            ->toContain('5× select * from items where order_id = ?');
    });

    it('allows an N+1 when told to, and still counts', function () {
        $message = failureOf(fn () => Telemetry::budget(function () {
            foreach (DB::select('select * from orders') as $order) {
                DB::select('select * from items where order_id = ?', [$order->id]);
            }
        }, queries: 3, allowNPlusOne: true));

        expect($message)->toContain('[budget] ran 6 database queries; expected <= 3');
    });

    it('covers requests made inside it, summed', function () {
        Telemetry::budget(function () {
            $this->get('/orders/fast');
            $this->get('/orders/fast');
        }, queries: 4);

        expect(failureOf(fn () => Telemetry::budget(function () {
            $this->get('/orders/fast');
            $this->get('/orders/fast');
        }, queries: 3)))->toContain('[budget] ran 4 database queries; expected <= 3');
    });

    it('counts only what ran inside it', function () {
        $this->get('/orders/slow');

        Telemetry::budget(fn () => $this->get('/orders/fast'), queries: 2);
    });

    it('fails a duration budget on wall time', function () {
        expect(failureOf(fn () => Telemetry::budget(fn () => usleep(2000), ms: 1)))
            ->toContain('[budget] took');
    });
});

describe('Pest expectations', function () {
    beforeEach(fn () => PestExpectations::register());

    it('passes and fails a budget', function () {
        expect(fn () => $this->get('/orders/fast'))->toStayWithinBudget(queries: 2)
            ->and(fn () => $this->get('/orders/fast'))->toQueryAtMost(2)
            ->and(fn () => $this->get('/orders/repeat'))->toHaveNoNPlusOne()
            ->and(fn () => $this->get('/orders/slow'))->toHaveNoDuplicateQueries();

        expect(failureOf(fn () => expect(fn () => $this->get('/orders/slow'))->toHaveNoNPlusOne()))
            ->toContain('N+1')
            ->and(failureOf(fn () => expect(fn () => $this->get('/orders/fast'))->toQueryAtMost(1)))
            ->toContain('ran 2 database queries; expected <= 1');
    });
});

it('exposes the profile for scoped assertions', function () {
    $this->get('/orders/fast');
    $this->get('/orders/slow');

    [$fast, $slow] = $this->fake->profiles();

    expect($fast)->toBeInstanceOf(PerformanceProfile::class);

    $fast->assertQueryCount(2)->assertNoNPlusOne();

    expect($slow->nPlusOnes())->toHaveCount(1)
        ->and($slow->nPlusOnes()[0]->count)->toBe(5)
        ->and($slow->nPlusOnes()[0]->origin)->toContain('PerformanceAssertionsTest.php:');
});
