<?php

// #LIFE-11: GoogleBusinessAutoSync::seedWorkplace() used to read
// site.workplaces via Workplace::firstOrNew() (no lock, no transaction), then
// per-field blank()-check and save() — a classic read-modify-write against the
// SAME row the dashboard PATCH (UserWorkplaceController::upsert /
// setPreviousWebsite) writes completely unlocked. An owner typing a workplace
// description while a Google Business enrich job runs could lose it: the job
// reads the column blank, the owner's save lands, the job's later save()
// overwrites it — last writer wins, and the last writer was never the owner.
//
// The fix wraps the read-check-write in a DB transaction with
// Workplace::query()->lockForUpdate() — a row lock Postgres enforces against
// EVERY writer, cooperating or not (mirrors IdentitySync::
// applyWorkplaceFields, LIFE-108, same table, same hazard). A Cache::lock()
// was considered and rejected: the owner's write path
// (UserWorkplaceController) never acquires it, so an advisory lock would only
// look fixed.
//
// SQLite can't prove any of this — lockForUpdate() silently compiles to a
// no-op there (see IdentitySyncConcurrencyTest.php's header for the same
// caveat on the sibling fix). This file uses a genuinely SEPARATE Postgres
// backend connection ('pgsql_second', an aliased copy of 'pgsql') to attempt
// the "owner's concurrent write" the instant the job's locking SELECT
// executes — fork-free, the same technique tests/Postgres/
// EnquiryReconcileSkipLockedTest.php and ClaimConcurrencyTest.php's Race 2
// use for deterministic lock-holding on a second, independently-resolved
// connection. A short lock_timeout on that second connection turns a genuine
// block into a fast, observable error instead of a hung test.

use App\Services\Platforms\GoogleBusinessAutoSync;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');

    foreach (['core', 'site'] as $schema) {
        $pg->statement("CREATE SCHEMA IF NOT EXISTS {$schema}");
    }

    // core.users / site.sites are SHARED across this lane (siblings FK to
    // them and never drop them) — create with the full common shape, then
    // ALTER-heal, mirroring ClaimConcurrencyTest.php's pattern.
    $pg->statement('CREATE TABLE IF NOT EXISTS core.users (
        id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        auth_user_id  uuid,
        handle        character varying(63),
        handle_lc     character varying(63),
        primary_email text,
        display_name  text,
        status        text NOT NULL DEFAULT \'active\',
        account_type  text NOT NULL DEFAULT \'partna\',
        deleted_at    timestamptz,
        created_at    timestamptz NOT NULL DEFAULT now(),
        updated_at    timestamptz NOT NULL DEFAULT now()
    )');
    foreach ([
        'auth_user_id' => 'uuid',
        'handle' => 'character varying(63)',
        'handle_lc' => 'character varying(63)',
        'primary_email' => 'text',
        'display_name' => 'text',
        'status' => 'text NOT NULL DEFAULT \'active\'',
        'account_type' => 'text NOT NULL DEFAULT \'partna\'',
        'deleted_at' => 'timestamptz',
        'created_at' => 'timestamptz NOT NULL DEFAULT now()',
        'updated_at' => 'timestamptz NOT NULL DEFAULT now()',
    ] as $col => $type) {
        $pg->statement("ALTER TABLE core.users ADD COLUMN IF NOT EXISTS {$col} {$type}");
    }

    $pg->statement('CREATE TABLE IF NOT EXISTS site.sites (
        id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id    uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
        subdomain  character varying(63) NOT NULL DEFAULT \'\',
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now()
    )');
    $pg->statement('ALTER TABLE site.sites ALTER COLUMN subdomain SET DEFAULT \'\'');

    // site.workplaces — no sibling in this lane creates it; owned exclusively
    // by this file, safe to drop and rebuild fresh each test. Shape mirrors
    // supabase/migrations/20260726000000_baseline_pilot.sql:2152-2170.
    $pg->statement('DROP TABLE IF EXISTS site.workplaces CASCADE');
    $pg->statement('CREATE TABLE site.workplaces (
        site_id          uuid PRIMARY KEY REFERENCES site.sites(id) ON DELETE CASCADE,
        name             text,
        address_line1    text,
        city             text,
        state            text,
        postcode         text,
        country          text,
        latitude         double precision,
        longitude        double precision,
        phone            text,
        website          text,
        previous_website text,
        category         text,
        description      text,
        created_at       timestamptz,
        updated_at       timestamptz,
        opening_hours    jsonb,
        contact_email    text,
        field_sources    jsonb NOT NULL DEFAULT \'{}\'::jsonb
    )');
});

afterAll(function () {
    $pg = DB::connection('pgsql');
    $pg->statement('DROP TABLE IF EXISTS site.workplaces CASCADE');
    // core.users / site.sites are shared — clean only this file's rows.
    $pg->table('site.sites')->where('subdomain', 'like', 'gbworkplace-%')->delete();
    $pg->table('core.users')->where('handle', 'like', 'gbworkplace-%')->delete();
});

/** @return array{userId:string, siteId:string} */
function gbwSeed(string $handle): array
{
    $pg = DB::connection('pgsql');

    $userId = (string) Str::uuid();
    $pg->table('core.users')->insert([
        'id' => $userId, 'handle' => $handle, 'handle_lc' => $handle,
        'display_name' => 'GB Workplace Race', 'account_type' => 'partna', 'status' => 'active',
    ]);

    $siteId = (string) Str::uuid();
    $pg->table('site.sites')->insert([
        'id' => $siteId, 'user_id' => $userId, 'subdomain' => $handle,
    ]);

    // Pre-existing row, description blank — the exact shape the fix's
    // lockForUpdate()->first() locks (a brand-new INSERT race is a narrower,
    // separately-reasoned hazard covered by the source-contract test below).
    $pg->table('site.workplaces')->insert([
        'site_id' => $siteId, 'name' => 'Ollies', 'description' => null,
        'field_sources' => json_encode([]),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return ['userId' => $userId, 'siteId' => $siteId];
}

it('blocks a genuinely separate Postgres connection from writing the workplace row mid-enrich, so the owner\'s concurrent PATCH survives (LIFE-11)', function () {
    ['userId' => $userId, 'siteId' => $siteId] = gbwSeed('gbworkplace-lock');

    config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);

    $attempt = ['ran' => false, 'blocked' => false, 'sqlstate' => null, 'message' => null];
    $fired = false;

    DB::listen(function (QueryExecuted $query) use (&$fired, &$attempt, $siteId) {
        if ($fired || $query->connectionName !== 'pgsql' || ! str_contains(strtolower($query->sql), 'workplaces')) {
            return;
        }
        $fired = true;
        $attempt['ran'] = true;

        // A genuinely separate Postgres backend session — NOT the connection
        // the job under test holds its transaction on — standing in for
        // UserWorkplaceController's unlocked write landing mid-enrich, at the
        // exact instant the job's (locked, post-fix) read has just executed.
        $second = DB::connection('pgsql_second');

        try {
            $second->statement('SET lock_timeout = 300'); // fail fast, never hang the suite
            $second->statement(
                'UPDATE site.workplaces SET description = ?, updated_at = now() WHERE site_id = ?',
                ['Owner typed this while the job was running', $siteId]
            );
        } catch (QueryException $e) {
            $attempt['blocked'] = true;
            $attempt['sqlstate'] = $e->errorInfo[0] ?? $e->getCode();
            $attempt['message'] = $e->getMessage();
        } finally {
            DB::purge('pgsql_second');
        }
    });

    $autoSync = app(GoogleBusinessAutoSync::class);
    $reflection = new ReflectionMethod($autoSync, 'seedWorkplace');
    $reflection->setAccessible(true);
    $reflection->invoke($autoSync, $userId, ['editorialSummary' => 'From Google: best ramen in town.']);

    // Sanity: the hook actually fired against the job's own connection —
    // otherwise every assertion below would pass vacuously (nothing raced).
    expect($attempt['ran'])->toBeTrue();

    // THE assertion: a genuinely separate session could NOT write the row
    // while the job's transaction was open. Pre-fix (no transaction, no
    // lock), there is nothing to block on and this is false.
    expect($attempt['blocked'])->toBeTrue(
        'A second Postgres connection wrote site.workplaces while the enrich job was mid-flight — the row is not locked. '
        .'attempt='.json_encode($attempt)
    );
    expect($attempt['sqlstate'])->toBe('55P03'); // lock_not_available specifically, not some other failure

    // The job's own write DID land (uncontended, once the second session's
    // attempt failed out on lock_timeout).
    $afterJob = DB::connection('pgsql')->table('site.workplaces')->where('site_id', $siteId)->first();
    expect($afterJob->description)->toBe('From Google: best ramen in town.');

    // Now the lock is released (the job's transaction committed) — the
    // owner's write, a plain unlocked UPDATE exactly like
    // UserWorkplaceController issues, lands cleanly on top and wins.
    DB::connection('pgsql')->table('site.workplaces')->where('site_id', $siteId)->update([
        'description' => 'Owner typed this after the job committed',
    ]);
    $final = DB::connection('pgsql')->table('site.workplaces')->where('site_id', $siteId)->first();
    expect($final->description)->toBe('Owner typed this after the job committed');
});

// ── Source contract (weak by construction — mirrors IdentitySyncConcurrencyTest.php) ──
// The assertion above is the real proof; this exists only to catch an
// accidental revert that somehow still passed the behavioural test above
// (e.g. a lock taken on the wrong connection in a config nobody runs here).

it('GoogleBusinessAutoSync.php source calls lockForUpdate and opens a transaction in seedWorkplace', function () {
    $source = file_get_contents(app_path('Services/Platforms/GoogleBusinessAutoSync.php'));

    expect($source)->toContain('lockForUpdate');
    expect($source)->toContain('->transaction(');
});
