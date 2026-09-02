<?php

// #W1-LIFE-3 / #W2-LIFE-2 / #W2-LIFE-3 — the half of the fix the SQLite lane
// CANNOT prove.
//
// SourceReconciler::applyIntent() runs inside reconcile()'s LIFE-16
// DB::transaction. On Postgres a raised 23505 ABORTS the whole transaction:
// every later statement fails 25P02 ("current transaction is aborted"), so the
// prescribed "catch the UniqueConstraintViolationException and re-read" is
// wrong WHERE IT IS RAISED — this repo has shipped that mistake three times
// (see ProjectionWriter.php's note, and SourceReconciler's own comment on
// upsertIntent's insertOrIgnore). The fix therefore wraps each write in a
// nested DB::connection('pgsql')->transaction(), which emits SAVEPOINT /
// RELEASE, and catches OUTSIDE it — by which point Laravel has already rolled
// back to the savepoint and the outer transaction is healthy again. Exactly
// SiteProvisioningService::attemptSubdomain()'s idiom.
//
// SQLite does not abort a transaction on a failed statement, so a green
// `composer test` says NOTHING about any of that: the savepoint's entire
// reason for existing is invisible there. It is visible here.
//
// Second thing only this lane can show: idx_platform_connections_primary_per_class
// is absent from the SQLite stand-in (tests/Pest.php mirrors only _canonical
// and _unique_active), so the is_primary arm of the fix is unprovable in the
// fast lane.
//
// Both unique indexes are declared VERBATIM from their migrations
// (20260727110005, 20260727110008) — a paraphrased index would let this file
// pass against a constraint production does not have.
//
// Injection idiom: a DB::listen hook fires right after the reconciler's own
// statement (Laravel fires query-executed AFTER execution, so that statement's
// result is already fixed), and a SECOND, independently-resolved Postgres
// connection commits the competing row — same shape as
// tests/Postgres/SourceIntentUpsertRaceTest.php.

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

    // user_id is a bare uuid with no core.users table and no FK — same choice,
    // and the same reason, as the sibling files here: a local core.users would
    // be invisible to SchemaDriftGuardTest. Nothing under test is referential.
    $pg->statement('DROP TABLE IF EXISTS routing.source_intents CASCADE');
    $pg->statement('DROP TABLE IF EXISTS routing.item_tombstones CASCADE');
    $pg->statement('DROP TABLE IF EXISTS site.platform_connections CASCADE');

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
                \'gate\', \'capability\', \'conflict\', \'cap_reached\', \'below_threshold\',
                \'tombstoned\', \'unservable\', \'invalid_identifier\', \'duplicate\'
            )),
        conflicting_connection_id uuid,
        connection_id uuid,
        confidence smallint CHECK (confidence IS NULL OR (confidence BETWEEN 0 AND 100)),
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

    $pg->statement('CREATE TABLE routing.item_tombstones (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL,
        source_ref text NOT NULL,
        scope text NOT NULL DEFAULT \'this_source\',
        reason text,
        created_at timestamptz NOT NULL DEFAULT now()
    )');

    // Faithful (NOT poisoned — contrast SourceReconcilerAtomicityTest): every
    // column site.platform_connections really has, so the next column the
    // writer picks up does not cost another red CI cycle. Declared here rather
    // than via a shared setup*Table() helper because those emit SQLite-flavoured
    // DDL; tests/Postgres/ is excluded from NoLocalCanonicalTableDdlTest by
    // path, held honest by the uses(PostgresTestCase::class)->in(__FILE__) above.
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
        created_at timestamptz,
        updated_at timestamptz,
        deleted_at timestamptz
    )');
    $pg->statement("ALTER TABLE site.platform_connections ADD COLUMN IF NOT EXISTS visibility text NOT NULL DEFAULT 'visible'");

    // VERBATIM from supabase/migrations/20260727110005_connections_idx_unique_active.sql
    // (CONCURRENTLY dropped — it cannot run inside this lane's DDL batch, and
    // the resulting index is identical).
    $pg->statement('CREATE UNIQUE INDEX "idx_platform_connections_unique_active"
        ON "site"."platform_connections" ("user_id", "surface_key", "resource_id")
        WHERE ("deleted_at" IS NULL)');

    // VERBATIM from supabase/migrations/20260727110008_connections_idx_primary_per_class.sql.
    // THE index the SQLite stand-in does not have.
    $pg->statement('CREATE UNIQUE INDEX "idx_platform_connections_primary_per_class"
        ON "site"."platform_connections" ("user_id", "routing_class")
        WHERE ("is_primary" AND "deleted_at" IS NULL)');

    // Keeps IntegrationConnectionObserver's downstream schema (ingest.sources,
    // workplaces, content_selections, …) out of scope — same choice, and the
    // same reason, as SourceReconcilerAtomicityTest. HasUuids still assigns the
    // key: Model::performInsert() calls setUniqueIds() directly, not via an
    // event, so flushing listeners does not cost us the id.
    IntegrationConnection::flushEventListeners();

    config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);

    $this->userId = (string) Str::uuid();
});

afterEach(function () {
    DB::purge('pgsql_second');
});

afterAll(function () {
    $pg = DB::connection('pgsql');
    foreach (['routing.source_intents', 'routing.item_tombstones', 'site.platform_connections'] as $t) {
        $pg->statement("DROP TABLE IF EXISTS {$t} CASCADE");
    }
});

/** @return array<string, mixed> */
function srcRacePgRow(string $id, string $userId, string $surfaceKey, string $routingClass, string $resourceId, bool $primary = false): array
{
    return [
        'id' => $id,
        'user_id' => $userId,
        'surface_key' => $surfaceKey,
        'routing_class' => $routingClass,
        'resource_id' => $resourceId,
        'payload' => json_encode(['source' => 'winner']),
        'is_active' => true,
        'is_primary' => $primary,
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

function srcRacePgIri(string $host, string $path): Iri
{
    return new Iri(
        raw: "https://{$host}{$path}",
        canonical: "https://{$host}{$path}",
        scheme: 'https',
        host: $host,
        registrableKey: implode('.', array_slice(explode('.', $host), -2)),
        subdomain: null,
        path: $path,
        query: [],
        port: null,
    );
}

function srcRacePgUser(string $id): User
{
    $user = new User;
    $user->id = $id;

    return $user;
}

// ── Control arm: name the refusal ─────────────────────────────────────────

it('names idx_platform_connections_unique_active when a duplicate (user, surface, resource) is inserted', function () {
    // The refusal REASON, pinned by SQLSTATE and by index name. Every
    // assertion below is about how the reconciler HANDLES this exact error; if
    // the index ever stopped being the thing that raises it, those assertions
    // would still pass while covering nothing.
    $userId = $this->userId;
    $identifier = 'pgctl-'.Str::random(8);

    DB::connection('pgsql')->table('site.platform_connections')
        ->insert(srcRacePgRow((string) Str::uuid(), $userId, 'bandcamp.artist', 'content', $identifier));

    $thrown = null;
    try {
        DB::connection('pgsql')->table('site.platform_connections')
            ->insert(srcRacePgRow((string) Str::uuid(), $userId, 'bandcamp.artist', 'content', $identifier));
    } catch (QueryException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->getCode())->toBe('23505')
        ->and($thrown->getMessage())->toContain('idx_platform_connections_unique_active');
});

it('names idx_platform_connections_primary_per_class when a second connection in a class claims is_primary', function () {
    $userId = $this->userId;

    DB::connection('pgsql')->table('site.platform_connections')
        ->insert(srcRacePgRow((string) Str::uuid(), $userId, 'fresha.book', 'booking', 'first', true));

    $thrown = null;
    try {
        DB::connection('pgsql')->table('site.platform_connections')
            ->insert(srcRacePgRow((string) Str::uuid(), $userId, 'square.book', 'booking', 'second', true));
    } catch (QueryException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->getCode())->toBe('23505')
        ->and($thrown->getMessage())->toContain('idx_platform_connections_primary_per_class');
});

// ── The assertion the SQLite lane cannot make ─────────────────────────────

it('does not let the loser\'s 23505 poison the LIFE-16 transaction — the intent commits, applied, pointing at the winner', function () {
    $userId = $this->userId;
    $identifier = 'pgrace'.Str::lower(Str::random(8));
    $winnerId = (string) Str::uuid();

    // The winner has to land strictly BETWEEN applyIntent()'s re-read and its
    // INSERT: any earlier and the re-read finds it and takes the (already
    // working) "row exists" arm. upsertIntent() runs immediately before
    // applyIntent(), so the first platform_connections SELECT carrying this
    // identifier AFTER a source_intents statement is that re-read.
    $seenIntent = false;
    $injected = false;
    DB::listen(function ($query) use (&$seenIntent, &$injected, $identifier, $winnerId, $userId) {
        if ($injected) {
            return; // fires exactly once.
        }
        if (str_contains($query->sql, 'source_intents')) {
            $seenIntent = true;

            return;
        }
        if (! $seenIntent || ! str_contains($query->sql, 'platform_connections')) {
            return;
        }
        if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            return;
        }
        if (! in_array($identifier, $query->bindings, true)) {
            return;
        }

        $injected = true;

        // Independently resolved connection, so this COMMITS while the
        // reconciler's own transaction is still open — the thing SQLite has no
        // way to stage.
        DB::connection('pgsql_second')->table('site.platform_connections')
            ->insert(srcRacePgRow($winnerId, $userId, 'bandcamp.artist', 'content', $identifier));
    });

    // bandcamp.artist: routing_class 'content', not exclusive-auto, so this
    // isolates the unique_active arm with no lock in the picture.
    $result = app(SourceReconciler::class)->reconcile(
        new Placement(Verdict::Place, 'bandcamp.artist', $identifier, null),
        RoutingContext::forUser(srcRacePgUser($userId), 'bio_harvest'),
        srcRacePgIri("{$identifier}.bandcamp.com", ''),
    );

    expect($injected)->toBeTrue('the race was never injected — every assertion below is vacuous');

    // 1. Resolved to the WINNER, not a 500 and not a phantom uuid.
    expect($result['connection_id'])->toBe($winnerId)
        ->and($result['verdict'])->toBe('place');

    // 2. THE 25P02 PROOF. Pre-fix (or with the catch left inside the
    //    savepoint), the settle-UPDATE right after applyIntent() would have
    //    failed 25P02 and taken the whole transaction — including the intent
    //    write — down with it. A committed `applied` row carrying the winner's
    //    id is only reachable if the outer transaction survived the violation.
    $intent = DB::connection('pgsql')->table('routing.source_intents')->where('user_id', $userId)->first();
    expect($intent)->not->toBeNull()
        ->and($intent->state)->toBe('applied')
        ->and($intent->connection_id)->toBe($winnerId)
        ->and($intent->resolved_at)->not->toBeNull();

    // 3. Exactly one connection row — the loser inserted nothing.
    $rows = DB::connection('pgsql')->table('site.platform_connections')->where('user_id', $userId)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe($winnerId);

    // 4. The connection is still usable — no aborted transaction left behind.
    expect(DB::connection('pgsql')->select('select 1 as one')[0]->one)->toBe(1);
});

it('resolves a lost is_primary race to "someone else owns the CTA" instead of failing the whole reconcile', function () {
    $userId = $this->userId;
    $identifier = 'pgprimary'.Str::lower(Str::random(8));
    $rivalId = (string) Str::uuid();

    // DIRECT request ('paste'): it bypasses both the incumbent check and the
    // exclusive-slot lock by design, which is exactly the window where this
    // race is still reachable after the lock landed.
    //
    // The rival lands right after applyIntent()'s `hasPrimary` EXISTS probe —
    // the only `exists` query against platform_connections in this path — so
    // the reconciler has already decided "nobody holds the CTA" when the rival
    // takes it.
    $injected = false;
    DB::listen(function ($query) use (&$injected, $rivalId, $userId) {
        if ($injected || ! str_contains($query->sql, 'platform_connections')) {
            return;
        }
        if (! str_contains(strtolower($query->sql), 'exists')) {
            return;
        }

        $injected = true;

        DB::connection('pgsql_second')->table('site.platform_connections')
            ->insert(srcRacePgRow($rivalId, $userId, 'square.book', 'booking', 'rival-store', true));
    });

    $result = app(SourceReconciler::class)->reconcile(
        new Placement(Verdict::Place, 'fresha.book', $identifier, null),
        RoutingContext::forUser(srcRacePgUser($userId), 'paste'),
        srcRacePgIri('www.fresha.com', '/a/'.$identifier),
    );

    expect($injected)->toBeTrue('the race was never injected — every assertion below is vacuous');

    // The reconcile SUCCEEDS. Pre-fix the uncaught 23505 on
    // idx_platform_connections_primary_per_class aborted the transaction, so
    // there was no connection and no intent at all — a 500 / failed job.
    expect($result['connection_id'])->not->toBeNull()
        ->and($result['connection_id'])->not->toBe($rivalId)
        ->and($result['verdict'])->toBe('place');

    $intent = DB::connection('pgsql')->table('routing.source_intents')->where('user_id', $userId)->first();
    expect($intent)->not->toBeNull()
        ->and($intent->state)->toBe('applied')
        ->and($intent->connection_id)->toBe($result['connection_id']);

    // The CTA belongs to whoever won it — exactly one primary in the class,
    // and it is the rival, not us.
    $primaries = DB::connection('pgsql')->table('site.platform_connections')
        ->where('user_id', $userId)->where('routing_class', 'booking')->where('is_primary', true)->get();
    expect($primaries)->toHaveCount(1)
        ->and($primaries[0]->id)->toBe($rivalId);

    $ours = DB::connection('pgsql')->table('site.platform_connections')->where('id', $result['connection_id'])->first();
    expect($ours->is_primary)->toBeFalse();

    expect(DB::connection('pgsql')->select('select 1 as one')[0]->one)->toBe(1);
});
