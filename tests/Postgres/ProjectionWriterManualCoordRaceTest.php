<?php

// #PGR-7: ProjectionWriter::writeManualItem() (ProjectionWriter.php:386) is the spine
// PoolItemCreateController routes every hand-add through — a double-clicked "Add", or two tabs
// adding the same URL, is two concurrent callers with the SAME deterministic coord
// ('manual:'.sha1(strtolower(trim($url))), PoolItemCreateController.php:110).
//
// Test A proves the FIRST race, already anticipated and handled: upsertSourceItem()'s
// pre-read-SELECT -> insertOrIgnore -> re-read (:452-501) loses cleanly to a competing commit on
// content.source_items via the real pmcr_source_items_coord_unique constraint, with no 23505
// leaking out and no poisoned transaction (the same shape as
// tests/Postgres/SourceIntentUpsertRaceTest.php, deterministic DB::listen injection).
//
// Test B proves a SECOND race the planner found by reading the code, not by symptom: the race
// does not end at upsertSourceItem(). resolveItems() (:410) runs OUTSIDE the transaction, and
// bindGroup() (:718-760) — reached through it — does a BARE DB::table('content.item_anchors')
// ->insert() at :737-743, no insertOrIgnore, no catch. content.item_anchors' PK is
// (user_id, coord) (supabase/migrations/20260727140000_content_schema.sql:130-137). Two
// concurrent writeManualItem() calls for the SAME (user, coord) can both read an empty anchors
// table in bindGroup(), both fall through to createItem(), and both attempt to INSERT the same
// (user_id, coord) anchor row — an uncaught 23505, plus an orphaned content.items row from
// whichever caller loses. This is a REAL fork, not deterministic injection: the gap is between
// two statements in two DIFFERENT, un-transacted calls, which a single-process DB::listen cannot
// straddle.
//
// DDL: a renamed clone of tests/Postgres/ProjectionIdentityKeyAtomicityTest.php's beforeEach
// (itself a renamed clone of ProjectionWriterBatchingTest.php's — see that file's own header for
// why local DDL is required and sanctioned here, and why nothing may be hoisted into
// tests/Support/). Every pika_ identifier there is renamed pmcr_ here so the two files' global
// Pest identifier symbol tables cannot collide. Three deviations from a verbatim clone:
//   1. site.platform_connections carries deleted_at (same fix as #PGR the sibling files needed —
//      resolveItems()'s $liveSource closure references lpc.deleted_at unconditionally).
//   2. ingest.* tables are DROPPED (sweep) but never CREATED — writeManualItem() never touches
//      ingest.*, only content.* and site.*.
//   3. Every pika_-prefixed constraint/index name is pmcr_-prefixed instead.

use App\Ingest\Projection\ProjectionWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');

    foreach ([
        'content.collection_items', 'content.collections',
        'content.f_action', 'content.item_tags', 'content.item_variants', 'content.offers', 'content.item_media',
        'content.media_assets', 'content.manual_overrides', 'content.identity_candidates', 'content.item_merges',
        'content.item_anchors', 'content.identity_decisions', 'content.identity_keys', 'content.source_items',
        'content.f_file', 'content.f_channel', 'content.f_review', 'content.f_rated', 'content.f_place',
        'content.f_catalog', 'content.f_authored', 'content.f_playable', 'content.f_embed', 'content.f_occurrence',
        'content.f_published', 'content.f_duration', 'content.f_link', 'content.f_text', 'content.items',
        'content.sources', 'ingest.record_state', 'ingest.record_versions', 'ingest.streams', 'ingest.sources',
        'site.site_build_state', 'site.sites', 'site.platform_connections', 'core.users',
    ] as $t) {
        $pg->statement("DROP TABLE IF EXISTS {$t} CASCADE");
    }

    $pg->statement('CREATE SCHEMA IF NOT EXISTS core');
    $pg->statement('CREATE SCHEMA IF NOT EXISTS site');
    $pg->statement('CREATE SCHEMA IF NOT EXISTS content');
    $pg->statement('CREATE SCHEMA IF NOT EXISTS ingest');

    $pg->statement('CREATE TABLE core.users (id uuid PRIMARY KEY DEFAULT gen_random_uuid())');

    $pg->statement('CREATE TABLE site.platform_connections (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
        resource_id text,
        deleted_at timestamptz
    )');
    $pg->statement("ALTER TABLE site.platform_connections ADD COLUMN IF NOT EXISTS visibility text NOT NULL DEFAULT 'visible'");

    $pg->statement("CREATE TABLE site.sites (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
        subdomain text NOT NULL DEFAULT '',
        is_published boolean NOT NULL DEFAULT false,
        updated_at timestamptz
    )");

    $pg->statement('CREATE TABLE site.site_build_state (
        site_id uuid PRIMARY KEY REFERENCES site.sites(id) ON DELETE CASCADE,
        content_revision bigint NOT NULL DEFAULT 0,
        built_revision bigint NOT NULL DEFAULT 0,
        building_since timestamptz,
        popularity_floor_at timestamptz,
        last_built_at timestamptz,
        updated_at timestamptz NOT NULL DEFAULT now()
    )');

    // ── content.* — real shape, per supabase/migrations/20260727140000. ────
    $pg->statement('CREATE TABLE content.sources (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
        kind text NOT NULL CHECK (kind IN (\'connection\',\'manual\',\'import\')),
        connection_id uuid REFERENCES site.platform_connections(id) ON DELETE CASCADE,
        import_run_id uuid,
        label text,
        priority integer NOT NULL DEFAULT 100,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now()
    )');

    $pg->statement('CREATE TABLE content.items (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
        kind text NOT NULL,
        headline_cache text,
        facets_cache jsonb NOT NULL DEFAULT \'[]\'::jsonb,
        removed_at timestamptz,
        review_flag text,
        first_seen_at timestamptz NOT NULL DEFAULT now(),
        last_seen_at timestamptz NOT NULL DEFAULT now(),
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now()
    )');

    $pg->statement('CREATE TABLE content.source_items (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        source_id uuid NOT NULL REFERENCES content.sources(id) ON DELETE CASCADE,
        coord text NOT NULL,
        stream_id uuid,
        record_key text,
        item_id uuid REFERENCES content.items(id) ON DELETE SET NULL,
        kind text NOT NULL,
        projector_version integer NOT NULL DEFAULT 1,
        ingest_selection_ref text,
        first_seen_at timestamptz NOT NULL DEFAULT now(),
        last_seen_at timestamptz NOT NULL DEFAULT now(),
        removed_at timestamptz,
        CONSTRAINT pmcr_source_items_coord_unique UNIQUE (source_id, coord)
    )');
    $pg->statement('CREATE INDEX idx_pmcr_source_items_item ON content.source_items (item_id)');

    $pg->statement('CREATE TABLE content.identity_keys (
        id bigserial PRIMARY KEY,
        source_item_id uuid NOT NULL REFERENCES content.source_items(id) ON DELETE CASCADE,
        key_class text NOT NULL,
        key_value text NOT NULL,
        tier text NOT NULL CHECK (tier IN (\'joining\',\'corroborating\',\'evidential\')),
        created_at timestamptz NOT NULL DEFAULT now()
    )');
    $pg->statement('CREATE INDEX idx_pmcr_identity_keys_source_item ON content.identity_keys (source_item_id)');

    $pg->statement('CREATE TABLE content.identity_decisions (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
        verdict text NOT NULL CHECK (verdict IN (\'same\',\'different\')),
        left_coord text NOT NULL,
        right_coord text NOT NULL,
        decided_at timestamptz NOT NULL DEFAULT now(),
        decided_by text
    )');

    $pg->statement('CREATE TABLE content.item_anchors (
        coord text NOT NULL,
        user_id uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        bound_at timestamptz NOT NULL DEFAULT now(),
        superseded_by uuid REFERENCES content.items(id),
        PRIMARY KEY (user_id, coord)
    )');

    $pg->statement('CREATE TABLE content.item_merges (
        id bigserial PRIMARY KEY,
        user_id uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
        kept_item_id uuid,
        discarded_item_id uuid,
        reason text NOT NULL,
        detail jsonb NOT NULL DEFAULT \'{}\'::jsonb,
        merged_at timestamptz NOT NULL DEFAULT now()
    )');

    $pg->statement('CREATE TABLE content.identity_candidates (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
        left_item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        right_item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        score integer NOT NULL,
        evidence jsonb NOT NULL DEFAULT \'{}\'::jsonb,
        dismissed_at timestamptz,
        created_at timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT pmcr_identity_candidates_pair UNIQUE (user_id, left_item_id, right_item_id)
    )');

    $pg->statement('CREATE TABLE content.manual_overrides (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        facet text NOT NULL,
        column_name text NOT NULL,
        value jsonb,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT pmcr_manual_overrides_unique UNIQUE (item_id, facet, column_name)
    )');

    $singletons = [
        'f_text' => 'headline text, body text, summary text',
        'f_link' => 'url text NOT NULL, canonical_url text',
        'f_duration' => 'seconds integer',
        'f_published' => 'published_from timestamptz, published_to timestamptz, verbatim text, precision text',
        'f_occurrence' => "starts_at_local timestamp, ends_at_local timestamp, timezone text, zone_confidence text CHECK (zone_confidence IS NULL OR zone_confidence IN ('explicit', 'inferred', 'assumed', 'offset_only')), starts_at_utc timestamptz, is_all_day boolean NOT NULL DEFAULT false",
        'f_embed' => 'provider text NOT NULL, embed_key text NOT NULL, variant text',
        'f_playable' => 'stream_url text, preview_url text, is_explicit boolean',
        'f_authored' => 'creator text, creator_url text, collaborators jsonb',
        'f_catalog' => 'release_type text, track_number integer, disc_number integer, isrc text, gtin text, sku text, handle text, vendor text, variant_ref text, collection_title text',
        'f_place' => 'venue_name text, address text, locality text, region text, country_code text, latitude double precision, longitude double precision',
        'f_rated' => 'rating double precision, rating_max double precision, ratings_count integer',
        'f_review' => 'author_name text, author_photo_url text, rating double precision, text text, reviewed_at timestamptz',
        'f_channel' => 'handle text, followers integer, avatar_url text, is_live boolean, verified boolean',
        'f_file' => 'file_url text, mime_type text, size_bytes bigint, page_count integer',
    ];
    foreach ($singletons as $facet => $columns) {
        $pg->statement("CREATE TABLE content.{$facet} (
            item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
            source_id uuid NOT NULL REFERENCES content.sources(id) ON DELETE CASCADE,
            {$columns},
            updated_at timestamptz NOT NULL DEFAULT now(),
            PRIMARY KEY (item_id, source_id)
        )");
    }

    $pg->statement('CREATE TABLE content.media_assets (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
        fingerprint text NOT NULL,
        source_url text,
        storage_path text,
        site_media_id uuid,
        mime_type text,
        width integer,
        height integer,
        dims_confidence text CHECK (dims_confidence IS NULL OR dims_confidence IN (\'measured\', \'declared\', \'guessed\')),
        palette jsonb,
        variant_family text CHECK (variant_family IS NULL OR variant_family IN (\'google\', \'shopify\', \'ytimg\', \'native\', \'proxy\')),
        blurhash text,
        attribution jsonb,
        mirror_eligible boolean,
        created_at timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT pmcr_media_assets_fingerprint_unique UNIQUE (user_id, fingerprint)
    )');

    $pg->statement('CREATE TABLE content.item_media (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        source_id uuid NOT NULL REFERENCES content.sources(id) ON DELETE CASCADE,
        source_item_id uuid REFERENCES content.source_items(id) ON DELETE CASCADE,
        asset_id uuid REFERENCES content.media_assets(id) ON DELETE SET NULL,
        role text NOT NULL CHECK (role IN (\'cover\',\'gallery\',\'poster\',\'avatar\',\'logo\',\'video\')),  -- \'video\': 20260819001100_item_media_role_video.sql:26
        position integer NOT NULL DEFAULT 0,
        alt_text text,
        created_at timestamptz NOT NULL DEFAULT now()
    )');
    $pg->statement('CREATE INDEX idx_pmcr_item_media_item ON content.item_media (item_id, role, position)');

    $pg->statement('CREATE TABLE content.offers (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        source_id uuid NOT NULL REFERENCES content.sources(id) ON DELETE CASCADE,
        source_item_id uuid REFERENCES content.source_items(id) ON DELETE CASCADE,
        channel text,
        variant_label text,
        amount_minor bigint,
        currency text,
        qualifier text NOT NULL DEFAULT \'exact\',
        amount_max_minor bigint,
        url text,
        availability text,
        updated_at timestamptz NOT NULL DEFAULT now()
    )');
    $pg->statement('CREATE INDEX idx_pmcr_offers_item ON content.offers (item_id)');

    $pg->statement('CREATE TABLE content.item_variants (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        source_id uuid NOT NULL REFERENCES content.sources(id) ON DELETE CASCADE,
        source_item_id uuid REFERENCES content.source_items(id) ON DELETE CASCADE,
        label text NOT NULL,
        sku text,
        image_url text,
        position integer NOT NULL DEFAULT 0
    )');
    $pg->statement('CREATE INDEX idx_pmcr_item_variants_item ON content.item_variants (item_id)');

    $pg->statement('CREATE TABLE content.item_tags (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        source_id uuid NOT NULL REFERENCES content.sources(id) ON DELETE CASCADE,
        source_item_id uuid REFERENCES content.source_items(id) ON DELETE CASCADE,
        tag text NOT NULL,
        tag_type text
    )');
    $pg->statement('CREATE INDEX idx_pmcr_item_tags_item ON content.item_tags (item_id)');

    $pg->statement('CREATE TABLE content.f_action (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        source_id uuid NOT NULL REFERENCES content.sources(id) ON DELETE CASCADE,
        intent text NOT NULL,
        label text,
        url text NOT NULL,
        position integer NOT NULL DEFAULT 0
    )');
    $pg->statement('CREATE INDEX idx_pmcr_f_action_item ON content.f_action (item_id)');

    $pg->statement('CREATE TABLE content.collections (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
        parent_id uuid REFERENCES content.collections(id) ON DELETE CASCADE,
        label text NOT NULL,
        kind text,
        external_ref text,
        removed_at timestamptz,
        position integer NOT NULL DEFAULT 0,
        is_user_created boolean NOT NULL DEFAULT false,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now()
    )');
    $pg->statement('CREATE UNIQUE INDEX idx_pmcr_collections_natural_key
        ON content.collections (user_id, kind, external_ref)');

    $pg->statement('CREATE TABLE content.collection_items (
        collection_id uuid NOT NULL REFERENCES content.collections(id) ON DELETE CASCADE,
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        source_id uuid REFERENCES content.sources(id) ON DELETE CASCADE,
        position integer NOT NULL DEFAULT 0,
        PRIMARY KEY (collection_id, item_id)
    )');
});

afterAll(function () {
    $pg = DB::connection('pgsql');
    foreach ([
        'content.collection_items', 'content.collections',
        'content.f_action', 'content.item_tags', 'content.item_variants', 'content.offers', 'content.item_media',
        'content.media_assets',
        'content.manual_overrides', 'content.identity_candidates', 'content.item_merges', 'content.item_anchors',
        'content.identity_decisions', 'content.identity_keys', 'content.source_items', 'content.f_file',
        'content.f_channel', 'content.f_review', 'content.f_rated', 'content.f_place', 'content.f_catalog',
        'content.f_authored', 'content.f_playable', 'content.f_embed', 'content.f_occurrence', 'content.f_published',
        'content.f_duration', 'content.f_link', 'content.f_text', 'content.items', 'content.sources',
        'ingest.record_state', 'ingest.record_versions', 'ingest.streams', 'ingest.sources',
        'site.site_build_state', 'site.sites', 'site.platform_connections', 'core.users',
    ] as $t) {
        $pg->statement("DROP TABLE IF EXISTS {$t} CASCADE");
    }
});

/** A user and (optionally) its site — writeManualItem() only needs core.users and its own manual source. */
function pmcrFixture(): array
{
    $pg = DB::connection('pgsql');
    $userId = (string) Str::uuid();
    $pg->table('core.users')->insert(['id' => $userId]);

    $siteId = (string) Str::uuid();
    $pg->table('site.sites')->insert(['id' => $siteId, 'user_id' => $userId]);

    return [$userId, $siteId];
}

/** Same shape PoolItemCreateController::store() builds for a plain link add (PoolItemCreateController.php:158-167). */
function pmcrLinkProjection(string $url, string $headline): array
{
    return [
        'kind' => 'link',
        'headline' => $headline,
        'facets' => ['f_link' => ['url' => $url]],
    ];
}

it('advances the winner instead of raising 23505 on content.source_items when a competing source item commits between the pre-read and the insert', function () {
    config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);

    [$userId] = pmcrFixture();
    $writer = app(ProjectionWriter::class);
    $contentSourceId = $writer->ensureManualSource($userId);

    $url = 'https://example.test/race-'.Str::random(8);
    $coord = 'manual:'.sha1(strtolower(trim($url)));
    $projection = pmcrLinkProjection($url, 'Race Link');

    $winnerItemId = (string) Str::uuid();
    $winnerSourceItemId = (string) Str::uuid();

    $fired = false;
    DB::listen(function ($query) use (&$fired, $coord, $contentSourceId, $winnerItemId, $winnerSourceItemId, $userId) {
        if ($fired) {
            return; // fire-once: only the FIRST matching statement is the one under test.
        }

        $sql = strtolower($query->sql);
        if (! str_contains($sql, 'source_items') || ! str_contains($sql, 'select') || str_contains($sql, 'insert')) {
            return;
        }
        if (! in_array($coord, $query->bindings, true)) {
            return; // not this record's pre-read
        }

        $fired = true;

        // A genuinely separate, independently-resolved Postgres connection commits a fully
        // materialised winner for the SAME (source_id, coord) before this caller's own
        // insertOrIgnore runs — mirrors SourceIntentUpsertRaceTest.php's deterministic injection.
        DB::connection('pgsql_second')->transaction(function () use ($coord, $contentSourceId, $winnerItemId, $winnerSourceItemId, $userId) {
            $second = DB::connection('pgsql_second');

            $second->table('content.items')->insert([
                'id' => $winnerItemId,
                'user_id' => $userId,
                'kind' => 'link',
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $second->table('content.source_items')->insert([
                'id' => $winnerSourceItemId,
                'source_id' => $contentSourceId,
                'coord' => $coord,
                'item_id' => $winnerItemId,
                'kind' => 'link',
                'projector_version' => 0,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);

            // Identity keys deliberately omitted: the loser's writeIdentityKeys() call rewrites
            // this row's key set unconditionally on the way through, and a singleton group
            // resolves off the item_anchors row below regardless of what keys are present.
            $second->table('content.item_anchors')->insert([
                'coord' => $coord,
                'user_id' => $userId,
                'item_id' => $winnerItemId,
                'bound_at' => now(),
            ]);
        });
    });

    $returnedItemId = null;
    $thrown = null;
    try {
        $returnedItemId = $writer->writeManualItem($userId, $coord, $projection);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($fired)->toBeTrue('the injection never fired — the assertions below would be vacuous');
    expect($thrown)->toBeNull('writeManualItem() threw: '.($thrown?->getMessage() ?? ''));

    $sourceItemRows = DB::connection('pgsql')->table('content.source_items')
        ->where('source_id', $contentSourceId)->where('coord', $coord)->get();
    expect($sourceItemRows)->toHaveCount(1);
    expect((string) $sourceItemRows->first()->id)->toBe($winnerSourceItemId);

    expect($returnedItemId)->toBe($winnerItemId);

    expect(DB::connection('pgsql')->table('content.items')->where('user_id', $userId)->count())->toBe(1);

    $anchorRows = DB::connection('pgsql')->table('content.item_anchors')
        ->where('user_id', $userId)->where('coord', $coord)->get();
    expect($anchorRows)->toHaveCount(1);
    expect((string) $anchorRows->first()->item_id)->toBe($winnerItemId);

    // The non-poisoning proof (LIFE-16 shape): upsertSourceItem() runs inside DB::transaction(),
    // and a swallowed 23505 there would leave that transaction ABORTED (SQLSTATE 25P02) for
    // every later statement on the connection — this would fail if that had happened.
    expect(DB::connection('pgsql')->select('select 1 as one')[0]->one)->toBe(1);

    DB::purge('pgsql_second');
});

it('does not raise an uncaught 23505 on content.item_anchors when two real forked processes writeManualItem() the same coord concurrently', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl_fork is not available in this runtime');
    }

    [$userId] = pmcrFixture();
    $writer = app(ProjectionWriter::class);
    // Pre-create the manual source in the parent so both children race on
    // upsertSourceItem()/bindGroup() only, not on ensureManualSource()'s own
    // idx_content_sources_manual partial-unique race (a different, already-handled path).
    $writer->ensureManualSource($userId);

    $url = 'https://example.test/fork-race-'.Str::random(8);
    $coord = 'manual:'.sha1(strtolower(trim($url)));
    $projection = pmcrLinkProjection($url, 'Fork Race Link');

    DB::connection('pgsql')->statement('DROP TABLE IF EXISTS core.pmcr_fork_probe');
    DB::connection('pgsql')->statement('CREATE TABLE core.pmcr_fork_probe (
        id        bigserial PRIMARY KEY,
        child_idx integer NOT NULL,
        outcome   text NOT NULL,
        item_id   text
    )');

    $childCount = 2;
    $startAt = microtime(true) + 0.25; // shared release gate, not a guess

    $pids = [];
    for ($i = 0; $i < $childCount; $i++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('pcntl_fork failed');
        }

        if ($pid === 0) {
            // A forked PDO socket shared with the parent corrupts in a way that mimics the bug
            // under test — drop it before doing anything else.
            DB::purge('pgsql');
            DB::reconnect('pgsql');

            // DB_APPLY_TIMEOUTS_IN_TESTS only applies to the boot-time PDO of the DEFAULT
            // connection (DatabaseServiceProvider.php:38-48) — a forked child's fresh PDO has
            // none. Without this, a genuine deadlock here would hang the lane forever instead of
            // failing in seconds.
            DB::connection('pgsql')->statement('SET lock_timeout = 5000');
            DB::connection('pgsql')->statement('SET statement_timeout = 15000');

            usleep((int) max(0, ($startAt - microtime(true)) * 1_000_000));

            $childWriter = app(ProjectionWriter::class);

            try {
                $itemId = $childWriter->writeManualItem($userId, $coord, $projection);
                $outcome = 'ok';
            } catch (Throwable $e) {
                // A sentinel value, not silence: a crashed/uncaught child must not read as a
                // clean loser. get_class + SQLSTATE (when present) so a deadlock or timeout is
                // distinguishable from a genuine 23505 leak.
                $sqlstate = method_exists($e, 'getCode') ? (string) $e->getCode() : '';
                $outcome = 'THROWN:'.get_class($e).':'.$sqlstate.':'.substr($e->getMessage(), 0, 200);
                $itemId = null;
            }

            try {
                DB::connection('pgsql')->table('core.pmcr_fork_probe')->insert([
                    'child_idx' => $i,
                    'outcome' => $outcome,
                    'item_id' => $itemId,
                ]);
            } catch (Throwable $e) {
                // Even the probe insert can fail if the connection's transaction is poisoned —
                // fall back to a sentinel row via a fresh connection so the parent never mistakes
                // "no row" for "clean run".
                DB::purge('pgsql');
                DB::reconnect('pgsql');
                DB::connection('pgsql')->table('core.pmcr_fork_probe')->insert([
                    'child_idx' => $i,
                    'outcome' => 'PROBE_INSERT_FAILED:'.get_class($e).':'.substr($e->getMessage(), 0, 200),
                    'item_id' => null,
                ]);
            }

            exit(0);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $probes = DB::connection('pgsql')->table('core.pmcr_fork_probe')->orderBy('child_idx')->get();
    expect($probes)->toHaveCount($childCount, 'A child died before recording its outcome');

    $thrown = $probes->filter(fn ($p) => str_starts_with($p->outcome, 'THROWN:') || str_starts_with($p->outcome, 'PROBE_INSERT_FAILED:'));
    $outcomes = $probes->pluck('outcome')->implode(' | ');

    expect($thrown)->toHaveCount(0, "At least one child raised instead of resolving cleanly. Outcomes: {$outcomes}");

    // Both children must agree on which item id the coord resolved to — the re-read-the-
    // PERSISTED-anchor fix, not the reuse-the-precomputed-$winner shortcut, is what this proves.
    $itemIds = $probes->pluck('item_id')->unique();
    expect($itemIds)->toHaveCount(1, "Children resolved to different item ids: {$outcomes}");

    expect(DB::connection('pgsql')->table('content.items')->where('user_id', $userId)->count())->toBe(1);
    expect(DB::connection('pgsql')->table('content.item_anchors')->where('user_id', $userId)->where('coord', $coord)->count())->toBe(1);

    DB::connection('pgsql')->statement('DROP TABLE IF EXISTS core.pmcr_fork_probe');
});
