<?php

// LIFE-15 regression test (plan 2026-07-28, Unit C): SourceReconciler::
// upsertIntent() used to be a bare pre-read SELECT -> conditional INSERT with
// no catch anywhere in the call chain. Two concurrent reconcile() calls for
// the SAME (user, surface, identifier) triple that both see "no live intent"
// would both attempt the INSERT; the loser hit idx_source_intents_live (the
// real partial unique index — see supabase/migrations/
// 20260727120000_routing_schema.sql:81-83) and raised an unhandled
// UniqueConstraintViolationException — a 500 / failed job.
//
// The fix (SourceReconciler::advanceLiveIntent() + insertOrIgnore in
// upsertIntent()) is deliberately NOT "insert, catch the 23505" — on
// Postgres, combined with the LIFE-16 transaction wrapping the whole
// reconcile() call, a caught 23505 leaves the transaction ABORTED (SQLSTATE
// 25P02) for every later statement (the exact trap
// tests/Postgres/ItemSlugAllocatorSavepointTest.php exists to guard, and
// App\Services\Site\ItemSlugAllocator::allocateSlug() already fixed the same
// way). insertOrIgnore compiles to `ON CONFLICT DO NOTHING`, so the loser's
// INSERT comes back as 0 rows affected — no exception, nothing to catch,
// nothing to poison — and falls through to advance the winner's row instead.
//
// This only runs against Postgres: the race is a real application-level
// pre-read/INSERT gap, invisible under the SQLite mirror (no independently-
// committing second connection), and idx_source_intents_live is a REAL
// partial unique index, which SQLite approximates but does not enforce with
// Postgres's exact conflict-resolution semantics.
//
// Deterministic injection, modelled on
// tests/Postgres/EffectLedgerConcurrencyTest.php: a DB::listen hook fires the
// instant the reconciler's OWN first statement touching source_intents for
// this identifier executes (by then that statement's own result is already
// fixed — Laravel fires the query-executed event after execution), and on a
// SECOND, independently-resolved Postgres connection, commits a competing
// live intent for the exact same triple. The caller's own insertOrIgnore
// then races straight into it.

use App\Models\Core\User\User;
use App\Routing\Iri;
use App\Routing\Placement;
use App\Routing\RoutingContext;
use App\Routing\SourceReconciler;
use App\Routing\Verdict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE SCHEMA IF NOT EXISTS core');
    $pg->statement('CREATE SCHEMA IF NOT EXISTS routing');
    $pg->statement('CREATE SCHEMA IF NOT EXISTS site');

    // user_id is a bare uuid with no core.users table and no FK. Building a
    // local core.users here would be invisible to SchemaDriftGuardTest, which
    // only introspects the shared setup*Table() helpers — NoLocalCanonicalTableDdlTest
    // exists to stop exactly that. Nothing under test touches the FK: this
    // proves the idx_source_intents_live race, not referential integrity.
    $pg->statement('DROP TABLE IF EXISTS routing.source_intents CASCADE');

    // Verbatim shape from supabase/migrations/20260727120000_routing_schema.sql:51-83.
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
            CHECK (state IN (\'proposed\', \'applied\', \'blocked\', \'dismissed\', \'superseded\')),
        block_reason text
            CHECK (block_reason IS NULL OR block_reason IN (
                \'gate\', \'capability\', \'conflict\', \'cap_reached\', \'needs_confirmation\',
                \'tombstoned\', \'unservable\', \'invalid_identifier\', \'duplicate\'
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

    $pg->statement('CREATE UNIQUE INDEX idx_source_intents_live
        ON routing.source_intents (user_id, surface_key, identifier)
        WHERE (state IN (\'proposed\', \'applied\', \'blocked\'))');

    // A Choose reaches site.platform_connections now, so this file must
    // provision it. 12c73ccee (M-8) widened SourceReconciler's identity lookup
    // from `Verdict::Place` to `[Place, Choose]` — an aliased Choose folds into
    // a row we already hold instead of re-proposing the user's own account —
    // and did not touch this file. The old invariant ("a non-Place verdict
    // touches site.platform_connections nowhere") is what let this test skip
    // the table entirely; it stopped being true, and because the PG lane runs
    // ONLY in CI, the 42703 surfaced there rather than on anyone's laptop.
    //
    // Empty and never written: matchExisting() returns null on no rows, so the
    // Choose stays a Choose and the race under test is unchanged. Declared
    // faithfully rather than minimally, for the reason SourceReconcilerAtomicityTest
    // records after paying for it twice — the next column the reader picks up
    // should not cost another red CI cycle.
    $pg->statement('DROP TABLE IF EXISTS site.platform_connections CASCADE');
    $pg->statement('CREATE TABLE site.platform_connections (
        id uuid PRIMARY KEY,
        user_id uuid NOT NULL,
        surface_key text NOT NULL,
        routing_class text NOT NULL,
        resource_id text NOT NULL,
        canonical_key text,
        payload jsonb NOT NULL DEFAULT \'{}\'::jsonb,
        is_active boolean NOT NULL DEFAULT true,
        is_primary boolean NOT NULL DEFAULT false,
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
        owner_scope text,
        created_at timestamptz,
        updated_at timestamptz,
        deleted_at timestamptz
    )');
    $pg->statement("ALTER TABLE site.platform_connections ADD COLUMN IF NOT EXISTS visibility text NOT NULL DEFAULT 'visible'");

    $this->userId = (string) Str::uuid();
});

afterAll(function () {
    $pg = DB::connection('pgsql');
    $pg->statement('DROP TABLE IF EXISTS routing.source_intents CASCADE');
    $pg->statement('DROP TABLE IF EXISTS site.platform_connections CASCADE');
});

it('advances the winner instead of raising 23505 when a competing intent commits between the pre-read and the insert', function () {
    // A genuinely SEPARATE Postgres connection to the same database.
    config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);

    $userId = $this->userId;
    $identifier = 'race-artist-'.Str::random(8);
    $winnerId = (string) Str::uuid();

    // Choose (not Place): incumbentFor()/capReached()/applyIntent() are all
    // skipped for a non-Place verdict, so this stays isolated to
    // upsertIntent(). The connections table IS provisioned (see beforeEach) —
    // since M-8 a Choose consults ConnectionIdentity::matchExisting() — but it
    // is empty, so the lookup returns null and the verdict stays Choose.
    $verdict = Verdict::Choose;

    $user = new User;
    $user->id = $userId;

    $placement = new Placement(
        verdict: $verdict,
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

    $context = RoutingContext::forUser($user, 'bio_harvest');

    $injected = false;
    DB::listen(function ($query) use (&$injected, $identifier, $winnerId, $userId) {
        if ($injected) {
            return; // fires exactly once.
        }
        if (! str_contains($query->sql, 'source_intents')) {
            return;
        }
        if (! in_array($identifier, $query->bindings, true)) {
            return;
        }

        $injected = true;

        // A concurrent worker that already committed a live intent for the
        // EXACT same triple, on an independently resolved connection — this
        // fires right after the caller's own first statement (advanceLiveIntent's
        // conditional UPDATE) already found nothing to update, so the
        // caller's own insertOrIgnore below races straight into it.
        $now = now();
        DB::connection('pgsql_second')->table('routing.source_intents')->insert([
            'id' => $winnerId,
            'user_id' => $userId,
            'surface_key' => 'bandcamp.artist',
            'routing_class' => 'content',
            'identifier' => $identifier,
            'canonical_url' => 'https://winner-committed-first.bandcamp.com',
            'state' => 'proposed',
            'block_reason' => null,
            'conflicting_connection_id' => null,
            'origin' => 'bio_harvest',
            'import_run_id' => null,
            'catalog_digest' => 'winner-digest',
            'first_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    });

    $reconciler = app(SourceReconciler::class);

    // Property 1: does NOT throw (pre-fix: UniqueConstraintViolationException).
    $result = $reconciler->reconcile($placement, $context, $iri);

    // Property 2: exactly ONE live row for the triple.
    $live = DB::connection('pgsql')->table('routing.source_intents')
        ->where('user_id', $userId)
        ->where('surface_key', 'bandcamp.artist')
        ->where('identifier', $identifier)
        ->whereIn('state', ['proposed', 'applied', 'blocked'])
        ->get();
    expect($live)->toHaveCount(1);

    // Property 3: the returned intent_id IS the winner's persisted id — not a
    // phantom uuid the loser minted for itself and returned unpersisted.
    expect($result['intent_id'])->toBe($winnerId)
        ->and($live[0]->id)->toBe($winnerId);

    // Property 4: the winner's row carries THIS call's state/canonical_url —
    // the loser advanced the winner's row rather than silently no-opping and
    // leaving the winner's original values in place.
    expect($live[0]->state)->toBe('proposed') // Verdict::Choose->intentState()
        ->and($live[0]->canonical_url)->toBe($iri->canonical)
        ->and($live[0]->canonical_url)->not->toBe('https://winner-committed-first.bandcamp.com');

    // Property 5: the connection is still usable afterwards — the 25P02
    // non-poisoning proof. A caught 23505 inside the LIFE-16 transaction
    // would have aborted every later statement on this connection.
    expect(DB::connection('pgsql')->select('select 1 as one')[0]->one)->toBe(1);

    DB::purge('pgsql_second');
});
