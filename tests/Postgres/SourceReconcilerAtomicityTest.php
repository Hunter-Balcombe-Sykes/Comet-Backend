<?php

// LIFE-16 regression test (plan 2026-07-28, Unit C): SourceReconciler::
// reconcile() used to write the intent (state='applied'), THEN create the
// connection, THEN settle the intent's connection_id — three independent
// autocommit statements. A throw partway through (a QueryException from
// applyIntent()'s IntegrationConnection::save(), or the model's saving()
// validator) left `routing.source_intents` holding a row with
// state='applied' and connection_id IS NULL forever — a dangling intent no
// caller could ever resolve.
//
// The fix wraps the intent write + applyIntent() + the settle-UPDATE in one
// DB::transaction (see SourceReconciler::reconcile()). This test proves the
// atomicity directly: a deliberately poisoned site.platform_connections
// table makes applyIntent()'s INSERT fail with a REAL Postgres error (23514,
// not a mock), and asserts the transaction takes the intent write down with
// it — zero rows, not a dangling `applied` one.
//
// Self-provisioned schema (routing.source_intents with the real
// idx_source_intents_live, routing.item_tombstones, and the poisoned
// site.platform_connections), same idiom as the other tests in this
// directory. Runs only against Postgres: SQLite does not abort a whole
// transaction on a single failed statement, so it cannot reproduce what this
// test exists to guard against.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Routing\Iri;
use App\Routing\Placement;
use App\Routing\RoutingContext;
use App\Routing\SourceReconciler;
use App\Routing\Verdict;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE SCHEMA IF NOT EXISTS core');
    $pg->statement('CREATE SCHEMA IF NOT EXISTS routing');
    $pg->statement('CREATE SCHEMA IF NOT EXISTS site');

    // user_id is a bare uuid with no core.users table and no FK: a local
    // core.users would be invisible to SchemaDriftGuardTest, which only
    // introspects the shared setup*Table() helpers. Nothing here tests
    // referential integrity — this proves the reconcile transaction rolls back.

    $pg->statement('DROP TABLE IF EXISTS routing.source_intents CASCADE');
    $pg->statement('DROP TABLE IF EXISTS routing.item_tombstones CASCADE');
    $pg->statement('DROP TABLE IF EXISTS site.platform_connections CASCADE');

    // Shape from supabase/migrations/20260727120000_routing_schema.sql:51-83,
    // then the migrations that widened it — the CHECKs and the live-slot index
    // predicate below come from 20260903220001_source_intents_verifying_state.sql
    // (:59-61 state, :72-78 block_reason), 20260902190000_source_intents_band.sql
    // (:11-12) and 20260904235902_source_intents_idx_live.sql (:15-17). It said
    // "verbatim" against the FIRST file alone until 2026-09-04, which is how
    // three drifts survived: no 'verifying' state, no 'not_found'/
    // 'sibling_branch' block reasons (SourceReconciler.php:185 writes the
    // latter), and a live-slot predicate one state short.
    $pg->statement('CREATE TABLE routing.source_intents (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL,
        surface_key text NOT NULL,
        routing_class text NOT NULL,
        identifier text NOT NULL,
        identifier_label text,
        identifier_icon text,
        canonical_url text,
        state text NOT NULL DEFAULT \'proposed\'
            CHECK (state IN (\'proposed\', \'verifying\', \'applied\', \'blocked\', \'dismissed\', \'superseded\')),
        block_reason text
            CHECK (block_reason IS NULL OR block_reason IN (
                \'gate\', \'capability\', \'conflict\', \'cap_reached\', \'needs_confirmation\',
                \'tombstoned\', \'unservable\', \'invalid_identifier\', \'duplicate\',
                \'not_found\', \'sibling_branch\'
            )),
        conflicting_connection_id uuid,
        connection_id uuid,
        origin text NOT NULL
            CHECK (origin IN (\'paste\', \'website_import\', \'link_in_bio\', \'bio_harvest\', \'google_business\', \'staff\', \'reproject\', \'commerce_probe\')),
        import_run_id uuid,
        detector_id text,
        catalog_digest text,
        first_seen_at timestamptz NOT NULL DEFAULT now(),
        resolved_at timestamptz,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now()
    )');
    $pg->statement('ALTER TABLE routing.source_intents ADD COLUMN IF NOT EXISTS band text');
    // SourceReconciler writes band on every insert these tests perform, so the
    // stand-in must carry its CHECK or a bad value passes here and fails live.
    $pg->statement('ALTER TABLE routing.source_intents ADD CONSTRAINT source_intents_band_check
        CHECK (band IS NULL OR band IN (\'auto\', \'suggest\'))');

    $pg->statement('CREATE UNIQUE INDEX idx_source_intents_live
        ON routing.source_intents (user_id, surface_key, identifier)
        WHERE (state IN (\'proposed\', \'verifying\', \'applied\', \'blocked\'))');

    $pg->statement('CREATE TABLE routing.item_tombstones (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL,
        source_ref text NOT NULL,
        scope text NOT NULL DEFAULT \'this_source\',
        reason text,
        created_at timestamptz NOT NULL DEFAULT now()
    )');

    // DELIBERATELY POISONED: exactly the columns capReached()/incumbentFor()
    // read (user_id, surface_key, resource_id, routing_class, deleted_at)
    // plus the columns applyIntent() writes (user_id, surface_key,
    // routing_class, resource_id, payload, is_active, last_refresh_status,
    // created_by_catalog_digest), with a CHECK that lets every SELECT succeed
    // (id IS NULL is true on an empty table -> count() = 0, cap never
    // reached) but rejects every INSERT (HasUuids always sets a real id
    // before insert -> id IS NULL is false -> 23514).
    // ALLOWLISTED in scripts/launch-check/no-local-canonical-ddl-baseline.json.
    // This is the guard's "genuinely needs a bespoke table" case, not an evasion:
    // the whole point of this table is that it REJECTS every INSERT
    // (CONSTRAINT reject_all) so applyIntent() fails mid-transaction and the
    // rollback can be observed. No shared setup*Table() helper can express a
    // table designed to fail — a faithful platform_connections would make this
    // test prove nothing.
    $pg->statement('CREATE TABLE site.platform_connections (
        id uuid,
        user_id uuid NOT NULL,
        surface_key text NOT NULL,
        routing_class text NOT NULL,
        resource_id text NOT NULL,
        -- #R4: ConnectionIdentity::matchExisting() reads canonical_key (the
        -- FOUND-14 identity column) on every reconcile, so omitting it here
        -- makes this file fail with 42703 before the CHECK under test can
        -- fire — a green SQLite run says nothing about it.
        canonical_key text,
        payload jsonb NOT NULL DEFAULT \'{}\'::jsonb,
        is_active boolean NOT NULL DEFAULT true,
        is_primary boolean NOT NULL DEFAULT false,
        -- Same trap as canonical_key above, and it bit twice (last_refresh_error,
        -- then consecutive_failures) one CI round at a time. So this block now
        -- mirrors EVERY column site.platform_connections really has
        -- (20260726000000_baseline_pilot.sql:1845 + the surface_key/routing_class
        -- identity additions above), not just the ones the reconcile writes today:
        -- a reject-all table costs nothing to over-declare, and the next column
        -- the writer picks up should not cost another red CI cycle.
        platform text,
        sort_order integer DEFAULT 0,
        last_visited_at timestamptz,
        last_refreshed_at timestamptz,
        last_refresh_status text,
        last_refresh_error text,
        consecutive_failures integer DEFAULT 0,
        apify_status text,
        place_id text,
        refresh_etag text,
        refresh_last_modified text,
        resource_kind text,
        display_settings jsonb,
        created_by_catalog_digest text,
        created_at timestamptz,
        updated_at timestamptz,
        deleted_at timestamptz,
        CONSTRAINT reject_all CHECK (id IS NULL)
    )');
    $pg->statement("ALTER TABLE site.platform_connections ADD COLUMN IF NOT EXISTS visibility text NOT NULL DEFAULT 'visible'");
    // owner_scope landed 2026-09-03 (c8ddc01a7) and SourceReconciler::applyIntent
    // assigns it on EVERY apply, but no stand-in gained the column — so this lane
    // went red with 42703 before the reject_all CHECK under test could fire, which
    // is exactly the trap the block comment above already warns about twice. The
    // static guard cannot see it: the write is Eloquent attribute assignment, which
    // emits no literal column string for PostgresLaneReadCoverageTest to find.
    $pg->statement('ALTER TABLE site.platform_connections ADD COLUMN IF NOT EXISTS owner_scope text');

    $this->userId = (string) Str::uuid();
});

afterAll(function () {
    $pg = DB::connection('pgsql');
    foreach (['routing.source_intents', 'routing.item_tombstones', 'site.platform_connections'] as $t) {
        $pg->statement("DROP TABLE IF EXISTS {$t} CASCADE");
    }
});

it('leaves ZERO source_intents rows when applyIntent() fails mid-transaction, instead of a dangling applied intent', function () {
    $userId = $this->userId;
    $identifier = 'poisoned-artist-'.Str::random(8);

    $user = new User;
    $user->id = $userId;

    // bandcamp.artist: max_accounts=5 (capReached() reads the poisoned table
    // and finds 0 rows, so it's never reached), not exclusive-auto (skips
    // incumbentFor() entirely) — Verdict::Place survives untouched into the
    // transaction, so applyIntent() is definitely reached.
    $placement = new Placement(
        verdict: Verdict::Place,
        surfaceKey: 'bandcamp.artist',
        identifier: $identifier,
        blockReason: null,
    );

    $iri = new Iri(
        raw: "https://{$identifier}.bandcamp.com",
        canonical: "https://{$identifier}.bandcamp.com",
        scheme: 'https',
        host: "{$identifier}.bandcamp.com",
        registrableKey: 'bandcamp.com',
        subdomain: $identifier,
        path: '',
        query: [],
        port: null,
    );

    $context = RoutingContext::forUser($user, 'paste');

    $reconciler = app(SourceReconciler::class);

    $thrown = null;
    try {
        $reconciler->reconcile($placement, $context, $iri);
    } catch (QueryException $e) {
        $thrown = $e;
    }

    // The call throws (today, pre-fix: it silently leaves state='applied',
    // connection_id IS NULL).
    expect($thrown)->not->toBeNull()
        ->and($thrown->getCode())->toBe('23514');

    // The whole transaction rolled back — including upsertIntent()'s own
    // write, which committed successfully in isolation before applyIntent()
    // ever ran.
    expect(DB::connection('pgsql')->table('routing.source_intents')->where('user_id', $userId)->count())->toBe(0);

    // POSITIVE CONTROL (mandatory — a fix that rolled everything back ALWAYS
    // would pass every assertion above without actually fixing the happy
    // path). Un-poison the table and re-run the exact same call: it must now
    // succeed end to end.
    DB::connection('pgsql')->statement('ALTER TABLE site.platform_connections DROP CONSTRAINT reject_all');

    // Keeps the observer's downstream schema (ingest.sources, workplaces,
    // content_selections, …) out of scope for this test — it exists purely
    // to prove applyIntent()'s connection write now survives, not to
    // provision every table IntegrationConnectionObserver::saved() reaches.
    IntegrationConnection::flushEventListeners();

    $result = $reconciler->reconcile($placement, $context, $iri);

    expect($result['verdict'])->toBe('place')
        ->and($result['connection_id'])->not->toBeNull();

    $intent = DB::connection('pgsql')->table('routing.source_intents')->where('user_id', $userId)->first();
    expect($intent)->not->toBeNull()
        ->and($intent->state)->toBe('applied')
        ->and($intent->connection_id)->toBe($result['connection_id']);

    $connection = DB::connection('pgsql')->table('site.platform_connections')->where('user_id', $userId)->first();
    expect($connection)->not->toBeNull()
        ->and($connection->id)->toBe($result['connection_id']);
});
