<?php

// Regression sentinel for audit #P1-10: the subdomain-allocation retry loop in
// SiteProvisioningService::tryCreateSite() must isolate its unique-violation
// (23505) from the OUTER signup transaction opened in UserBootstrapService.
//
// PostgreSQL aborts the entire transaction on ANY statement error. Before the
// fix, the 23505 raised by an insert into site.sites (unique on lower(subdomain))
// poisoned the outer DB::transaction(): every subsequent statement failed with
// 25P02 "current transaction is aborted", so the whole signup rolled back and
// surfaced as an unhandled exception. The fix wraps the insert in a nested
// DB::transaction() — Laravel emits SAVEPOINT / ROLLBACK TO SAVEPOINT — so the
// violation only rolls the savepoint back, leaving the outer transaction healthy.
//
// SQLite (the default `composer test` driver) does NOT abort the transaction on
// a statement error, so it cannot reproduce this bug at all — a SQLite test would
// pass with OR without the fix and prove nothing. These tests therefore target a
// REAL PostgreSQL connection — tests/SchemaTestCase.php below skips (or, in the
// schema-tests CI job, fails) the whole file when one isn't configured.
//
// To run against Supabase dev (or any real Postgres):
//   DB_CONNECTION=pgsql DB_HOST=... DB_PORT=... DB_DATABASE=... \
//   DB_USERNAME=... DB_PASSWORD=... \
//   php artisan test --filter SiteProvisioningSavepointTest

use App\Services\User\SiteProvisioningService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\SchemaTestCase;

uses(SchemaTestCase::class)->in(__FILE__);

// ─── 1. Mechanism sentinel — savepoint isolates 23505 from the outer tx ───────
//
// Runs as app_backend (needs only a scratch table in `public`, which it owns).
// Reproduces the EXACT failure mode the fix relies on, independent of the
// site.sites FK chain, so it's runnable on any real Postgres without auth.users
// INSERT rights.

it('a nested transaction isolates a unique-violation so the outer transaction survives', function () {
    $table = 'savepoint_probe_'.Str::lower(Str::random(8));
    DB::statement("CREATE TEMPORARY TABLE {$table} (val text UNIQUE)");

    try {
        // Outer transaction == the signup transaction in UserBootstrapService.
        $result = DB::transaction(function () use ($table) {
            DB::table($table)->insert(['val' => 'taken']);

            // First "candidate" collides — wrapped in a nested transaction exactly
            // as tryCreateSite() now does, so the 23505 only rolls back to the
            // savepoint instead of aborting the outer transaction.
            $caught = false;
            try {
                DB::transaction(function () use ($table) {
                    DB::table($table)->insert(['val' => 'taken']); // → 23505
                });
            } catch (QueryException $e) {
                $caught = $e->getCode() === '23505';
            }

            // The poison test: this statement runs in the SAME outer transaction.
            // Pre-fix (no savepoint) the outer tx would be aborted and this throws
            // 25P02. Post-fix the savepoint rollback kept the outer tx clean.
            DB::table($table)->insert(['val' => 'free']);

            return [
                'caught' => $caught,
                'rows' => DB::table($table)->count(),
            ];
        });

        expect($result['caught'])->toBeTrue('Expected the duplicate insert to raise a 23505 unique violation.');
        expect($result['rows'])->toBe(2, 'Outer transaction should hold both the baseline and the retry row.');
    } finally {
        DB::statement("DROP TABLE IF EXISTS {$table}");
    }
});

it('without a savepoint, the same 23505 poisons the whole outer transaction (negative control)', function () {
    $table = 'savepoint_probe_'.Str::lower(Str::random(8));
    DB::statement("CREATE TEMPORARY TABLE {$table} (val text UNIQUE)");

    // This documents the bug the fix prevents: a flat catch (NO nested
    // transaction) leaves the outer transaction aborted, so the follow-up insert
    // fails with 25P02. If a future refactor ever removes the savepoint, the
    // sentinel above flips red; this control proves that red would be meaningful.
    try {
        DB::beginTransaction();
        DB::table($table)->insert(['val' => 'taken']);

        try {
            DB::table($table)->insert(['val' => 'taken']); // → 23505, no savepoint
        } catch (QueryException $e) {
            // Swallowed at PHP level, but the PG transaction is now aborted.
        }

        $poisoned = false;
        try {
            DB::table($table)->insert(['val' => 'free']); // → 25P02
        } catch (QueryException $e) {
            $poisoned = $e->getCode() === '25P02';
        }

        expect($poisoned)->toBeTrue(
            'Expected the aborted-transaction state (25P02) when no savepoint isolates the 23505.'
        );
    } finally {
        DB::rollBack();
        DB::statement("DROP TABLE IF EXISTS {$table}");
    }
});

// ─── 2. Real-service integration — createSiteWithRetry under an outer tx ───────
//
// Drives the ACTUAL service against real site.sites. Everything runs inside a
// test-owned outer transaction that is rolled back at the end (sentinel throw),
// so nothing persists to the shared dev DB. Skips if the FK-parent core.users
// row can't be created (e.g. app_backend lacks INSERT on auth.users) — in that
// case the mechanism sentinel above is the regression guard.

it('createSiteWithRetry retries past a taken subdomain without aborting the outer signup transaction', function () {
    $base = 'sp-'.Str::lower(Str::random(10));

    // Build the FK chain: a new site needs core.users(id), which FKs auth.users.
    // app_backend has no grant on auth.users, so this may fail — skip cleanly.
    $authId = (string) Str::uuid();
    $existingUserId = (string) Str::uuid();
    $newUserId = (string) Str::uuid();

    try {
        DB::beginTransaction();

        try {
            DB::table('auth.users')->insert(['id' => $authId, 'email' => $authId.'@savepoint.test']);
            insertSavepointTestUser($existingUserId, $authId.'-a', $base.'-existing');
            insertSavepointTestUser($newUserId, $authId.'-b', $base.'-new');
        } catch (QueryException $e) {
            DB::rollBack();
            $this->markTestSkipped(
                'Cannot seed the core.users FK chain as this role (likely no INSERT on auth.users): '.$e->getMessage()
            );
        }

        // Pre-occupy the canonical subdomain so the first allocation attempt hits
        // a real 23505 and the loop must fall through to the next candidate.
        DB::table('site.sites')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $existingUserId,
            'subdomain' => $base,
            'is_published' => true,
            'settings' => '{}',
        ]);

        // The provisioning runs inside the same outer transaction as the seed —
        // mirroring UserBootstrapService::bootstrap(). Pre-fix, the first 23505
        // would abort this transaction and the next statement would throw 25P02.
        $site = app(SiteProvisioningService::class)->createSiteWithRetry($newUserId, $base);

        // (a) the retry produced a DIFFERENT, suffixed subdomain.
        expect($site->subdomain)->not->toBe($base);
        expect($site->subdomain)->toStartWith($base);

        // (b) the outer transaction is still alive: a follow-up statement must
        // succeed (it would throw 25P02 if the tx had been aborted).
        $stillAlive = DB::table('site.sites')->where('id', $site->id)->exists();
        expect($stillAlive)->toBeTrue('Outer transaction was aborted — the new site row is not visible.');
    } finally {
        // Never persist to the shared DB.
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }
});

if (! function_exists('insertSavepointTestUser')) {
    function insertSavepointTestUser(string $id, string $authUserId, string $handle): void
    {
        DB::table('core.users')->insert([
            'id' => $id,
            'auth_user_id' => $authUserId,
            'handle' => $handle,
            'handle_lc' => Str::lower($handle),
            'display_name' => 'Savepoint Test',
            'primary_email' => $handle.'@savepoint.test',
            'first_name' => 'Save',
            'account_type' => 'partna',
            'status' => 'active',
        ]);
    }
}
