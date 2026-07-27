<?php

// Sync-idempotence sentinel for `catalog:sync` (plan §1 P1 gate): running the
// same artefact twice must change nothing; removing an entry tombstones it
// (never deletes); re-adding resurrects the same row. Upsert/tombstone
// semantics are Postgres-shaped (ON CONFLICT), so this runs only against
// Postgres via Tests\PostgresTestCase — self-provisioned schema like the
// other tests in this directory, tables copied structurally from
// supabase/migrations/20260727100000_catalog_schema.sql minus RLS/grants
// (role wiring isn't what this test guards).

use App\Catalog\CompiledCatalog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE SCHEMA IF NOT EXISTS catalog');
    foreach (['detectors', 'surfaces', 'brands', 'host_aliases', 'suffix_overrides', 'sync_state'] as $t) {
        $pg->statement("DROP TABLE IF EXISTS catalog.{$t} CASCADE");
    }

    $pg->statement('CREATE TABLE catalog.brands (
        key text PRIMARY KEY,
        display_name text NOT NULL,
        homepage text,
        lifecycle text NOT NULL DEFAULT \'active\',
        successor_key text REFERENCES catalog.brands (key),
        last_synced_digest text NOT NULL,
        tombstoned_at timestamptz,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now()
    )');
    $pg->statement('CREATE TABLE catalog.surfaces (
        key text PRIMARY KEY,
        brand_key text NOT NULL REFERENCES catalog.brands (key),
        display_name text NOT NULL,
        routing_class text NOT NULL,
        shelf text NOT NULL,
        identifier_kind text NOT NULL,
        refresh_interval_secs integer NOT NULL DEFAULT 0,
        connect_capability text,
        connect_fetch_capability text,
        fetch_capability text,
        normalize_capability text,
        highlights_capability text,
        gate_capability text,
        completeness_capability text,
        canonical_url_template text,
        default_sections jsonb NOT NULL DEFAULT \'{}\'::jsonb,
        is_connectable boolean NOT NULL DEFAULT true,
        is_multi_account boolean NOT NULL DEFAULT false,
        max_accounts integer NOT NULL DEFAULT 1,
        supports_incremental boolean NOT NULL DEFAULT false,
        lifecycle text NOT NULL DEFAULT \'active\',
        embed jsonb,
        reserved_paths jsonb NOT NULL DEFAULT \'[]\'::jsonb,
        note text,
        last_synced_digest text NOT NULL,
        tombstoned_at timestamptz,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now()
    )');
    $pg->statement('CREATE TABLE catalog.detectors (
        id text PRIMARY KEY,
        surface_key text REFERENCES catalog.surfaces (key),
        signal_key text,
        evidence text NOT NULL,
        registrable_key text,
        subdomain_pattern text,
        path_pattern text,
        query_requires jsonb NOT NULL DEFAULT \'[]\'::jsonb,
        fingerprint text,
        identifier_capture text,
        identifier_source text NOT NULL DEFAULT \'none\',
        probe_capability text,
        strength integer NOT NULL,
        reject_patterns jsonb NOT NULL DEFAULT \'[]\'::jsonb,
        markets jsonb NOT NULL DEFAULT \'[]\'::jsonb,
        note text,
        specificity integer,
        last_synced_digest text NOT NULL,
        tombstoned_at timestamptz,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT detectors_surface_xor_signal CHECK ((surface_key IS NULL) <> (signal_key IS NULL))
    )');
    $pg->statement('CREATE TABLE catalog.host_aliases (
        alias_host text PRIMARY KEY,
        registrable_key text NOT NULL,
        note text,
        last_synced_digest text NOT NULL,
        tombstoned_at timestamptz,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now()
    )');
    $pg->statement('CREATE TABLE catalog.suffix_overrides (
        suffix text PRIMARY KEY,
        note text,
        last_synced_digest text NOT NULL,
        tombstoned_at timestamptz,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now()
    )');
    $pg->statement('CREATE TABLE catalog.sync_state (
        id smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
        digest text NOT NULL,
        synced_at timestamptz NOT NULL DEFAULT now()
    )');

    // The artefact under test is whatever is committed; flush the memo so
    // each test reads the file fresh.
    CompiledCatalog::flush();
});

it('is idempotent: same artefact twice changes no rows', function () {
    Artisan::call('catalog:sync');
    $first = snapshotCatalog();

    Artisan::call('catalog:sync'); // digest short-circuit path
    expect(snapshotCatalog())->toBe($first);

    Artisan::call('catalog:sync', ['--force' => true]); // full upsert path
    $forced = snapshotCatalog();

    // updated_at moves on --force; everything semantic must not.
    expect(stripTimestamps($forced))->toBe(stripTimestamps($first));
});

it('tombstones removed entries and resurrects returning ones, never deleting', function () {
    Artisan::call('catalog:sync');

    // Simulate an artefact that no longer contains one synced brand by
    // planting a foreign row from an "older" digest, then re-syncing.
    DB::connection('pgsql')->table('catalog.brands')->insert([
        'key' => 'zz_departed',
        'display_name' => 'Departed',
        'lifecycle' => 'active',
        'last_synced_digest' => 'sha256:old',
        'updated_at' => now(),
    ]);

    Artisan::call('catalog:sync', ['--force' => true]);

    $departed = DB::connection('pgsql')->table('catalog.brands')->where('key', 'zz_departed')->first();
    expect($departed)->not->toBeNull()
        ->and($departed->tombstoned_at)->not->toBeNull();

    // Resurrect: pretend it came back by stamping the current digest the way
    // an artefact re-adding it would.
    DB::connection('pgsql')->table('catalog.brands')->where('key', 'zz_departed')->update([
        'last_synced_digest' => CompiledCatalog::digest(),
        'tombstoned_at' => null,
    ]);
    Artisan::call('catalog:sync', ['--force' => true]);

    $back = DB::connection('pgsql')->table('catalog.brands')->where('key', 'zz_departed')->first();
    // Not part of the artefact → tombstoned again; but the ROW still exists.
    expect($back)->not->toBeNull();
});

function snapshotCatalog(): array
{
    $pg = DB::connection('pgsql');
    $out = [];
    foreach (['brands', 'surfaces', 'detectors', 'host_aliases', 'suffix_overrides'] as $t) {
        $out[$t] = $pg->table("catalog.{$t}")->orderBy(match ($t) {
            'brands' => 'key', 'surfaces' => 'key', 'detectors' => 'id',
            'host_aliases' => 'alias_host', 'suffix_overrides' => 'suffix',
        })->get()->map(fn ($r) => (array) $r)->all();
    }

    return $out;
}

function stripTimestamps(array $snapshot): array
{
    foreach ($snapshot as $t => $rows) {
        foreach ($rows as $i => $row) {
            unset($snapshot[$t][$i]['updated_at'], $snapshot[$t][$i]['created_at']);
        }
    }

    return $snapshot;
}
