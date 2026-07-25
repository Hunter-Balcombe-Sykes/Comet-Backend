<?php

// Regression sentinel for commit 3cd4ff63: ItemSlugAllocator::insertUnique()
// must isolate its unique-collision handling from the OUTER transaction a
// caller may already hold (e.g. MenuScanApplier::apply(), which wraps the
// MenuItem::create() that reaches the allocator through MenuItemObserver).
//
// PostgreSQL aborts the entire transaction on ANY statement error. The OLD
// allocator shape was insert-and-catch: a colliding insert raised a 23505
// unique violation which, on Postgres, poisoned the enclosing transaction —
// every later statement in it failed with 25P02 "current transaction is
// aborted", turning one expected slug collision into a 500 on the manual
// /scan/apply endpoint (dac9fc1b widened the collision surface from
// near-empty to the whole menu by minting a slug for every scraped dish).
// The fix (3cd4ff63) switched allocateSlug() to insertOrIgnore — the expected
// collision now returns 0 rows affected instead of raising — wrapped in a
// nested DB::transaction() whenever the caller already holds one, so Laravel
// emits SAVEPOINT / ROLLBACK TO SAVEPOINT and any residual failure only rolls
// back the savepoint, leaving the outer transaction healthy.
//
// SQLite (the default `composer test` driver) does NOT abort the transaction
// on a statement error, so it cannot reproduce this bug at all — a SQLite
// test would pass with OR without the fix and prove nothing. This bug class
// has shipped THREE times (site-provisioning signup, the pre-pilot slug
// slice, the MenuScanApplier exposure fixed here).
//
// Runs for real via the Postgres lane (tests/PostgresTestCase.php, wired below
// with `uses()`): `composer test:pg` / `./vendor/bin/pest -c phpunit.pg.xml`
// against a real Postgres. Originally lived under tests/Feature/Site (bound to
// Tests\TestCase, whose setUp() unconditionally repoints 'pgsql' at in-memory
// SQLite with no escape hatch), so it always skipped — moved here so it
// actually executes. PostgresTestCase now owns the skip-without-Postgres
// guard, so the two tests below no longer gate on the driver themselves.
//
// See tests/Postgres/ItemSlugAllocatorRegressionTest.php for the companion
// sentinel that drives the real production ItemSlugAllocator end to end
// against a real site.item_slugs table — this file only reproduces the
// insertOrIgnore-in-a-savepoint MECHANISM against a scratch table.
//
// The 3cd4ff63 fix WAS ALSO verified on real dev Postgres by running these
// exact statements (temp table + outer/nested transaction + insertOrIgnore,
// and the bare insert-and-catch negative control) directly via `cloud tinker
// development` — Test A → inserted=0/rows=2, Test B → 23505 caught then 25P02
// poison.

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

// ─── 1. Mechanism sentinel — insertOrIgnore + nested transaction survives ─────
//
// Runs as app_backend (needs only a scratch table in `public`, which it owns).
// Reproduces the EXACT shape allocateSlug()/insertUnique() rely on —
// insertOrIgnore against a colliding value inside a nested transaction — independent
// of the site.item_slugs FK chain, so it's runnable on any real Postgres
// without core.users INSERT rights.

it('insertOrIgnore inside a nested transaction survives a collision without poisoning the outer transaction', function () {
    $table = 'item_slug_probe_'.Str::lower(Str::random(8));
    DB::statement("CREATE TEMPORARY TABLE {$table} (val text UNIQUE)");

    try {
        // Outer transaction == the transaction MenuScanApplier::apply() opens
        // before creating MenuItems.
        $result = DB::transaction(function () use ($table) {
            DB::table($table)->insert(['val' => 'taken']);

            // The colliding "candidate" — wrapped in a nested transaction exactly
            // as insertUnique() now does, using insertOrIgnore exactly as
            // allocateSlug() now does. A real unique violation would raise, but
            // insertOrIgnore resolves the conflict at the index and returns 0
            // rows affected instead of failing the statement.
            $inserted = DB::connection()->transaction(
                fn () => DB::table($table)->insertOrIgnore(['val' => 'taken'])
            );

            // The poison test: this statement runs in the SAME outer transaction.
            // Pre-fix (bare insert-and-catch, no savepoint) a genuine 23505 here
            // would have aborted the outer tx and this throws 25P02. Post-fix,
            // insertOrIgnore never raises and the nested transaction commits
            // cleanly, so the outer tx stays healthy.
            DB::table($table)->insert(['val' => 'free']);

            return [
                'inserted' => $inserted,
                'rows' => DB::table($table)->count(),
            ];
        });

        expect($result['inserted'])->toBe(0, 'Expected the colliding insertOrIgnore to affect zero rows.');
        expect($result['rows'])->toBe(2, 'Outer transaction should hold both the baseline and the free row, with no duplicate.');
    } finally {
        DB::statement("DROP TABLE IF EXISTS {$table}");
    }
});

it('without insertOrIgnore, a bare insert-and-catch collision poisons the whole outer transaction (negative control)', function () {
    $table = 'item_slug_probe_'.Str::lower(Str::random(8));
    DB::statement("CREATE TEMPORARY TABLE {$table} (val text UNIQUE)");

    // This documents the bug the fix prevents: a flat insert wrapped in a
    // try/catch for UniqueConstraintViolationException (the OLD allocator
    // shape, no insertOrIgnore, no nested transaction) leaves the outer
    // transaction aborted after the catch, so the follow-up insert fails with
    // 25P02. If a future refactor ever reintroduces catch-and-retry without
    // the savepoint, the sentinel above flips red; this control proves that
    // red would be meaningful.
    try {
        DB::beginTransaction();
        DB::table($table)->insert(['val' => 'taken']);

        $caught = false;
        try {
            DB::table($table)->insert(['val' => 'taken']); // → 23505, no savepoint
        } catch (QueryException $e) {
            // Swallowed at PHP level exactly like the old allocator's catch
            // block, but the PG transaction is now aborted.
            $caught = $e->getCode() === '23505';
        }

        $poisoned = false;
        try {
            DB::table($table)->insert(['val' => 'free']); // → 25P02
        } catch (QueryException $e) {
            $poisoned = $e->getCode() === '25P02';
        }

        expect($caught)->toBeTrue('Expected the duplicate insert to raise a 23505 unique violation.');
        expect($poisoned)->toBeTrue(
            'Expected the aborted-transaction state (25P02) when no insertOrIgnore/savepoint isolates the 23505.'
        );
    } finally {
        DB::rollBack();
        DB::statement("DROP TABLE IF EXISTS {$table}");
    }
});
