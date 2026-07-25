<?php

// Regression sentinel for #FFLAG-1 (shipped in commit 8e97b901, 2026-05-22):
// StaffFeatureFlagOverrideController::store() used to re-read the row it had
// just upserted via a query that included ->whereNull('brand_id') — a column
// dropped from core.feature_flag_overrides by that same standalone
// strip-down commit (supabase/migrations/20260526000000_baseline_standalone_user.sql:575-590
// defines the real nine columns; no brand_id anywhere after it). Every real
// POST to this endpoint has 500'd on Postgres (SQLSTATE 42703, "column
// brand_id does not exist") since that commit landed.
//
// The suite never caught it because tests/Feature/FeatureFlags/FeatureFlagTestCase.php
// carried its OWN hand-copied, drifted SQLite schema that DID declare
// brand_id (fixed alongside this test — see tests/Feature/Database/FixtureSchemaParityTest.php,
// the guard that now stops a fixture like that from silently drifting again).
// SQLite has no real catalog to validate column names against the way
// Postgres does, so a fixture that invents a column just... works, forever,
// with no error. Only a real Postgres can catch this class of bug, which is
// why this test lives here rather than in the SQLite-backed Feature suite.
//
// The fix (this bundle, Item A) makes FeatureFlagService::setOverride()
// RETURN the row it upserted instead of re-reading it, so there is no second
// query left to drift out of sync with the real schema. This test drives the
// fix end to end at the HTTP layer — real route, real middleware chain
// (supabase.jwt/staff/require.aal2/staff.admin stubbed or exercised as
// documented in the fix plan), real FormRequest validation, real service,
// real Resource — against an actual Postgres database.
//
// DDL below is transcribed from
// supabase/migrations/20260526000000_baseline_standalone_user.sql:575-601,
// with the professional_id -> user_id rename (20260527030000:35) and its FK
// rename (20260527050000:46) applied. feature_flag_overrides_pro_unique and
// feature_flag_overrides_scope_set were NOT renamed by that migration and are
// reproduced here verbatim.
//
// Runs via `composer test:pg` / `./vendor/bin/pest -c phpunit.pg.xml` against
// a real Postgres — see tests/PostgresTestCase.php for the skip-without-a-
// reachable-server guard and the disposable-database guard (refuses to run
// against anything but DB_DATABASE=partna_test unless PG_LANE_DISPOSABLE=1).

use App\Models\Core\Staff\PartnaStaff;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE SCHEMA IF NOT EXISTS core');
    // FK targets only — deliberately id-only. IF NOT EXISTS means whichever
    // tests/Postgres file runs first wins the definition, so declaring columns
    // this test never reads would be a claim the lane cannot honour.
    $pg->statement('CREATE TABLE IF NOT EXISTS core.users        (id uuid PRIMARY KEY DEFAULT gen_random_uuid())');
    $pg->statement('CREATE TABLE IF NOT EXISTS core.partna_staff (id uuid PRIMARY KEY DEFAULT gen_random_uuid())');
    $pg->statement('CREATE TABLE IF NOT EXISTS core.feature_flags (
        key text NOT NULL, description text NOT NULL DEFAULT \'\',
        default_enabled boolean NOT NULL DEFAULT false, rollout_percent smallint NOT NULL DEFAULT 0,
        deleted_at timestamptz NULL,
        created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT feature_flags_pkey PRIMARY KEY (key)
    )');
    // THE NINE-COLUMN TRUTH. No brand_id. Adding one here would defeat the test.
    $pg->statement('CREATE TABLE IF NOT EXISTS core.feature_flag_overrides (
        id uuid DEFAULT gen_random_uuid() NOT NULL,
        flag_key   text        NOT NULL,
        user_id    uuid        NULL,
        enabled    boolean     NOT NULL,
        reason     text        NULL,
        expires_at timestamptz NULL,
        created_by uuid        NULL,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT feature_flag_overrides_pkey PRIMARY KEY (id),
        CONSTRAINT feature_flag_overrides_flag_key_fkey   FOREIGN KEY (flag_key)   REFERENCES core.feature_flags(key) ON DELETE CASCADE,
        CONSTRAINT feature_flag_overrides_user_id_fkey    FOREIGN KEY (user_id)    REFERENCES core.users(id)          ON DELETE CASCADE,
        CONSTRAINT feature_flag_overrides_created_by_fkey FOREIGN KEY (created_by) REFERENCES core.partna_staff(id)    ON DELETE SET NULL,
        CONSTRAINT feature_flag_overrides_scope_set CHECK (user_id IS NOT NULL)
    )');
    $pg->statement('CREATE UNIQUE INDEX IF NOT EXISTS feature_flag_overrides_pro_unique
        ON core.feature_flag_overrides (flag_key, user_id)');

    // Isolate this test from any prior run's residue without an unconditional
    // DROP (tables are shared, potentially non-disposable — see the guard in
    // PostgresTestCase).
    $pg->table('core.feature_flag_overrides')->delete();
    $pg->table('core.feature_flags')->delete();
    $pg->table('core.users')->delete();
    $pg->table('core.partna_staff')->delete();

    $this->flagKey = 'ff_pg_lane_'.Str::lower(Str::random(8));
    $pg->table('core.feature_flags')->insert(['key' => $this->flagKey, 'default_enabled' => false, 'rollout_percent' => 0]);

    $this->userId = (string) Str::uuid();
    $pg->table('core.users')->insert(['id' => $this->userId]);

    // Acting staff must be a real core.partna_staff row — setOverride() writes
    // its id into feature_flag_overrides.created_by, which FK-references it.
    $this->admin = (new PartnaStaff)->forceFill(['id' => (string) Str::uuid()]);
    $this->admin->role = PartnaStaff::ROLE_ADMIN; // staff.admin middleware checks isAdmin() in-memory, no DB round trip.
    $pg->table('core.partna_staff')->insert(['id' => $this->admin->id]);
});

it('POST /staff/feature-flags/{key}/overrides returns 201 against real Postgres', function () {
    // Pre-fix (controller re-reads via ->whereNull('brand_id')) this 500s with
    // SQLSTATE 42703 the instant the re-read query is compiled, even though the
    // upsert itself already committed.
    $response = actingAsStaff($this->admin)
        ->postJson("/api/staff/feature-flags/{$this->flagKey}/overrides", [
            'user_id' => $this->userId,
            'enabled' => true,
            'reason' => 'pg lane sentinel',
        ]);

    $response->assertStatus(201);
    $response->assertJsonPath('data.user_id', $this->userId);
    $response->assertJsonPath('data.enabled', true);
    expect($response->json('data.id'))->not->toBeEmpty();

    $row = DB::connection('pgsql')->table('core.feature_flag_overrides')
        ->where('flag_key', $this->flagKey)
        ->where('user_id', $this->userId)
        ->first();

    expect($row)->not->toBeNull();
    expect((bool) $row->enabled)->toBeTrue();
});

it('a repeat POST for the same flag+user updates in place rather than duplicating', function () {
    // Exercises updateOrCreate()'s UPDATE branch (and feature_flag_overrides_pro_unique)
    // — the branch that most needs proving after the option-(ii) refactor, since
    // it must return the DB-hydrated existing row, not a freshly-instantiated one.
    $first = actingAsStaff($this->admin)
        ->postJson("/api/staff/feature-flags/{$this->flagKey}/overrides", [
            'user_id' => $this->userId,
            'enabled' => true,
        ]);
    $first->assertStatus(201);
    $firstId = $first->json('data.id');

    $second = actingAsStaff($this->admin)
        ->postJson("/api/staff/feature-flags/{$this->flagKey}/overrides", [
            'user_id' => $this->userId,
            'enabled' => false,
        ]);

    $second->assertStatus(201);
    $second->assertJsonPath('data.enabled', false);
    expect($second->json('data.id'))->toBe($firstId, 'Repeat POST must update the existing row in place, not mint a new id.');

    $count = DB::connection('pgsql')->table('core.feature_flag_overrides')
        ->where('flag_key', $this->flagKey)
        ->where('user_id', $this->userId)
        ->count();

    expect($count)->toBe(1);
});

it('a query naming a column absent from core.feature_flag_overrides raises 42703 (negative control)', function () {
    // Proves the red that tests 1-2 would show pre-fix is meaningful: a query
    // against a genuinely nonexistent column on this real table raises SQLSTATE
    // 42703 rather than silently matching nothing (the SQLite failure mode that
    // let #FFLAG-1 ship). Mirrors the negative-control idiom in
    // tests/Postgres/ItemSlugAllocatorSavepointTest.php.
    $caught = null;

    try {
        DB::connection('pgsql')->table('core.feature_flag_overrides')->whereNull('brand_id')->first();
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull('Expected querying a nonexistent brand_id column to raise a QueryException.');
    expect($caught->getCode())->toBe('42703');
});
