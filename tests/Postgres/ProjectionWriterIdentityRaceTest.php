<?php

// #LIFE-1: ProjectionWriter::resolveItems() (:596) is read -> compute -> write with no lock and
// no transaction. bindGroup() and the final `source_items.item_id` UPDATE loop (:689-710) sit at
// opposite ends of that window, and mergeInto() (:901) HARD-DELETES the discarded item in
// between. A second caller that resolves the same (user, kind) inside the window either takes an
// uncaught FK violation on a deleted item id, or silently reverts the other caller's merge.
//
// #PGR-7 hardened the SAME-coord half of this collision (two tabs adding one URL, both minting an
// item, both binding one coord — content.item_anchors' PK settles it). What is left, and what
// this file proves, is the GROUP-level race: two callers whose RESOLUTIONS disagree because one
// of them read before a uniting fact existed.
//
// WHY REAL FORKS AND NOT DB::listen INJECTION. The sibling
// ProjectionWriterManualCoordRaceTest.php proves its first race by having a DB::listen handler
// commit the competing state on a second connection. That technique cannot prove this one: the
// interleaving writer has to BE another resolveItems() call, so once the advisory lock exists
// that call would block inside the synchronous listener and self-deadlock the test. Two real
// processes are the only honest harness. DB::listen is still used, but only inside child A and
// only to run `pg_sleep` — it widens A's read->write window deterministically and injects no
// semantics. The hook point (the item_anchors INSERT that binds A's own coord) survives both the
// #SCALE-4 read hoist and the #CACHE-5 insert batching, which is why it was chosen over the
// group loop or recordCandidates().
//
// WHY THE UNITING FACT IS AN identity_decisions ROW. resolveItems() reads
// content.identity_decisions per user and unions on verdict 'same' (Resolver::resolve() step 2)
// with no poisoning rule and no cross-source requirement. It is also a real dashboard action —
// "these two are the same thing" — landing while another tab is mid-add. Using a shared
// identity KEY instead would need the two coords on two different content.sources, because
// Resolver::poisonedKeys() drops any value one source contributes twice, and both manual coords
// of one user live on that user's single manual source.
//
// DDL: the beforeEach is a renamed clone of ProjectionWriterManualCoordRaceTest.php's (itself a
// clone of ProjectionWriterBatchingTest.php's — see that file's header for why local DDL is
// required and sanctioned, and why nothing may be hoisted into tests/Support/). Every pmcr_
// identifier is pgir_ here so the two files' global Pest symbol tables cannot collide. THREE
// TABLES ARE ADDED that the pmcr_ clone does not create — content.item_links, content.item_slugs
// and site.section_items. pmcr never merges, so it never reaches mergeInto()'s moveLinks() /
// moveSlugs() / curation check; every test here does, and without them the lane fails 42P01.

use App\Ingest\Projection\ProjectionWriter;
use App\Services\Site\AdvisoryLockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

/** How long child A pins the read->write window open. Two orders of magnitude over child B's work. */
const PGIR_HOLD_SECONDS = 1.5;

/** How long child B waits after the release gate before acting, so it lands inside A's window. */
const PGIR_B_DELAY_US = 300_000;

beforeEach(function () {
    $pg = DB::connection('pgsql');

    foreach ([
        'content.collection_items', 'content.collections',
        'content.f_action', 'content.item_tags', 'content.item_variants', 'content.offers', 'content.item_media',
        'content.media_assets', 'content.manual_overrides', 'content.identity_candidates', 'content.item_merges',
        'content.item_slugs', 'content.item_links',
        'content.item_anchors', 'content.identity_decisions', 'content.identity_keys', 'content.source_items',
        'content.f_file', 'content.f_channel', 'content.f_review', 'content.f_rated', 'content.f_place',
        'content.f_catalog', 'content.f_authored', 'content.f_playable', 'content.f_embed', 'content.f_occurrence',
        'content.f_published', 'content.f_duration', 'content.f_link', 'content.f_text', 'content.items',
        'content.sources', 'site.section_items',
        'site.site_build_state', 'site.sites', 'site.platform_connections', 'core.users',
    ] as $t) {
        $pg->statement("DROP TABLE IF EXISTS {$t} CASCADE");
    }

    $pg->statement('CREATE SCHEMA IF NOT EXISTS core');
    $pg->statement('CREATE SCHEMA IF NOT EXISTS site');
    $pg->statement('CREATE SCHEMA IF NOT EXISTS content');

    $pg->statement('CREATE TABLE core.users (id uuid PRIMARY KEY DEFAULT gen_random_uuid())');

    $pg->statement('CREATE TABLE site.platform_connections (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
        resource_id text,
        deleted_at timestamptz
    )');

    $pg->statement("CREATE TABLE site.sites (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
        subdomain text NOT NULL DEFAULT '',
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

    // mergeInto()'s curation check. section_id carries no FK to site.sections here: nothing in
    // this file creates a section, and the EXISTS() under test filters on item_id alone.
    $pg->statement("CREATE TABLE site.section_items (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        section_id uuid,
        item_id uuid NOT NULL,
        state text NOT NULL DEFAULT 'pinned' CHECK (state IN ('pinned', 'excluded')),
        sort_key double precision,
        created_at timestamptz NOT NULL DEFAULT now()
    )");

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
        eligible_cache jsonb NOT NULL DEFAULT \'[]\'::jsonb,
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
        first_seen_at timestamptz NOT NULL DEFAULT now(),
        last_seen_at timestamptz NOT NULL DEFAULT now(),
        removed_at timestamptz,
        CONSTRAINT pgir_source_items_coord_unique UNIQUE (source_id, coord)
    )');
    $pg->statement('CREATE INDEX idx_pgir_source_items_item ON content.source_items (item_id)');

    $pg->statement('CREATE TABLE content.identity_keys (
        id bigserial PRIMARY KEY,
        source_item_id uuid NOT NULL REFERENCES content.source_items(id) ON DELETE CASCADE,
        key_class text NOT NULL,
        key_value text NOT NULL,
        tier text NOT NULL CHECK (tier IN (\'joining\',\'corroborating\',\'evidential\')),
        created_at timestamptz NOT NULL DEFAULT now()
    )');
    $pg->statement('CREATE INDEX idx_pgir_identity_keys_source_item ON content.identity_keys (source_item_id)');

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

    // mergeInto() -> moveLinks(). Absent from the pmcr_ clone; required here.
    $pg->statement('CREATE TABLE content.item_links (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        platform text NOT NULL,
        url text NOT NULL,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT pgir_item_links_unique UNIQUE (item_id, platform)
    )');

    // mergeInto() -> moveSlugs(). retired_at arrived with 20260731090000 (271-PRIV-1).
    $pg->statement('CREATE TABLE content.item_slugs (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        slug text NOT NULL,
        is_current boolean NOT NULL DEFAULT true,
        retired_at timestamptz,
        created_at timestamptz NOT NULL DEFAULT now()
    )');
    $pg->statement('CREATE UNIQUE INDEX idx_pgir_item_slugs_unique ON content.item_slugs (user_id, slug)');

    $pg->statement('CREATE TABLE content.identity_candidates (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
        left_item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        right_item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        score integer NOT NULL,
        evidence jsonb NOT NULL DEFAULT \'{}\'::jsonb,
        dismissed_at timestamptz,
        created_at timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT pgir_identity_candidates_pair UNIQUE (user_id, left_item_id, right_item_id)
    )');

    $pg->statement('CREATE TABLE content.manual_overrides (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        facet text NOT NULL,
        column_name text NOT NULL,
        value jsonb,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT pgir_manual_overrides_unique UNIQUE (item_id, facet, column_name)
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
        created_at timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT pgir_media_assets_fingerprint_unique UNIQUE (user_id, fingerprint)
    )');

    $pg->statement('CREATE TABLE content.item_media (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        source_id uuid NOT NULL REFERENCES content.sources(id) ON DELETE CASCADE,
        asset_id uuid REFERENCES content.media_assets(id) ON DELETE SET NULL,
        role text NOT NULL CHECK (role IN (\'cover\',\'gallery\',\'poster\',\'avatar\',\'logo\')),
        position integer NOT NULL DEFAULT 0,
        alt_text text,
        created_at timestamptz NOT NULL DEFAULT now()
    )');

    $pg->statement('CREATE TABLE content.offers (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        source_id uuid NOT NULL REFERENCES content.sources(id) ON DELETE CASCADE,
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

    $pg->statement('CREATE TABLE content.item_variants (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        source_id uuid NOT NULL REFERENCES content.sources(id) ON DELETE CASCADE,
        label text NOT NULL,
        sku text,
        image_url text,
        position integer NOT NULL DEFAULT 0
    )');

    $pg->statement('CREATE TABLE content.item_tags (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        source_id uuid NOT NULL REFERENCES content.sources(id) ON DELETE CASCADE,
        tag text NOT NULL,
        tag_type text
    )');

    $pg->statement('CREATE TABLE content.f_action (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        source_id uuid NOT NULL REFERENCES content.sources(id) ON DELETE CASCADE,
        intent text NOT NULL,
        label text,
        url text NOT NULL,
        position integer NOT NULL DEFAULT 0
    )');

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
    $pg->statement('CREATE UNIQUE INDEX idx_pgir_collections_natural_key
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
        'core.pgir_fork_probe',
        'content.collection_items', 'content.collections',
        'content.f_action', 'content.item_tags', 'content.item_variants', 'content.offers', 'content.item_media',
        'content.media_assets', 'content.manual_overrides', 'content.identity_candidates', 'content.item_merges',
        'content.item_slugs', 'content.item_links',
        'content.item_anchors', 'content.identity_decisions', 'content.identity_keys', 'content.source_items',
        'content.f_file', 'content.f_channel', 'content.f_review', 'content.f_rated', 'content.f_place',
        'content.f_catalog', 'content.f_authored', 'content.f_playable', 'content.f_embed', 'content.f_occurrence',
        'content.f_published', 'content.f_duration', 'content.f_link', 'content.f_text', 'content.items',
        'content.sources', 'site.section_items',
        'site.site_build_state', 'site.sites', 'site.platform_connections', 'core.users',
    ] as $t) {
        $pg->statement("DROP TABLE IF EXISTS {$t} CASCADE");
    }
});

/**
 * The pre-race state.
 *
 * TWO coords, BOTH already anchored, so nothing in the race inserts an anchor. That is not
 * incidental — it is what makes the lock the only thing that can serialise the two callers.
 * content.item_anchors' PK is (user_id, coord), so if child A inserted an anchor inside its
 * open transaction, child B would block on THAT row lock and the whole file would go green with
 * the advisory lock deleted. It did, until an independent review caught it.
 *
 * The manual coord's source item deliberately carries item_id NULL against a live anchor. That
 * is exactly what an interrupted resolve leaves behind — the state this fix exists to stop being
 * created — and it is what gives child A a PENDING final UPDATE without minting anything.
 *
 * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
 *                                                                      [userId, connectorCoord, connectorItemId, manualCoord, manualItemId]
 */
function pgirScenario(): array
{
    $pg = DB::connection('pgsql');

    $userId = (string) Str::uuid();
    $pg->table('core.users')->insert(['id' => $userId]);
    $pg->table('site.sites')->insert(['id' => (string) Str::uuid(), 'user_id' => $userId]);

    $connectionId = (string) Str::uuid();
    $pg->table('site.platform_connections')->insert([
        'id' => $connectionId, 'user_id' => $userId, 'resource_id' => 'pgir', 'deleted_at' => null,
    ]);

    $connectorSourceId = (string) Str::uuid();
    $pg->table('content.sources')->insert([
        'id' => $connectorSourceId, 'user_id' => $userId, 'kind' => 'connection',
        'connection_id' => $connectionId, 'label' => 'pgir.connector',
    ]);

    // Bound a day earlier than the manual side, so oldest-binding-wins is deterministic.
    $connectorItemId = (string) Str::uuid();
    $pg->table('content.items')->insert([
        'id' => $connectorItemId, 'user_id' => $userId, 'kind' => 'link',
        'first_seen_at' => now()->subDay(), 'last_seen_at' => now()->subDay(),
        'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
    ]);

    $connectorCoord = 'pgir.connector:acct:link-1';
    $pg->table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $connectorSourceId, 'coord' => $connectorCoord,
        'item_id' => $connectorItemId, 'kind' => 'link', 'projector_version' => 1,
        'first_seen_at' => now()->subDay(), 'last_seen_at' => now()->subDay(),
    ]);
    $pg->table('content.item_anchors')->insert([
        'coord' => $connectorCoord, 'user_id' => $userId,
        'item_id' => $connectorItemId, 'bound_at' => now()->subDay(),
    ]);

    $manualSourceId = app(ProjectionWriter::class)->ensureManualSource($userId);

    $manualItemId = (string) Str::uuid();
    $pg->table('content.items')->insert([
        'id' => $manualItemId, 'user_id' => $userId, 'kind' => 'link',
        'first_seen_at' => now()->subHour(), 'last_seen_at' => now()->subHour(),
        'created_at' => now()->subHour(), 'updated_at' => now()->subHour(),
    ]);

    $manualCoord = 'manual:'.sha1('pgir-'.Str::random(8));
    $pg->table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $manualSourceId, 'coord' => $manualCoord,
        // NULL, against a live anchor — see the docblock.
        'item_id' => null, 'kind' => 'link', 'projector_version' => 0,
        'first_seen_at' => now()->subHour(), 'last_seen_at' => now()->subHour(),
    ]);
    $pg->table('content.item_anchors')->insert([
        'coord' => $manualCoord, 'user_id' => $userId,
        'item_id' => $manualItemId, 'bound_at' => now()->subHour(),
    ]);

    return [$userId, $connectorCoord, $connectorItemId, $manualCoord, $manualItemId];
}

/**
 * The shape PoolItemCreateController::store() builds for a plain link add. The URL is derived
 * from the coord so two owners' adds never share a canonical_url key by accident.
 */
function pgirLinkProjection(string $coord): array
{
    return [
        'kind' => 'link',
        'headline' => 'Pgir Link',
        'facets' => ['f_link' => ['url' => 'https://example.test/pgir-'.sha1($coord)]],
    ];
}

function pgirResetProbe(): void
{
    DB::connection('pgsql')->statement('DROP TABLE IF EXISTS core.pgir_fork_probe');
    DB::connection('pgsql')->statement('CREATE TABLE core.pgir_fork_probe (
        id         bigserial PRIMARY KEY,
        child_idx  integer NOT NULL,
        outcome    text NOT NULL,
        elapsed_ms integer NOT NULL DEFAULT 0,
        hooked     boolean NOT NULL DEFAULT false
    )');
}

function pgirRecordProbe(int $idx, string $outcome, int $elapsedMs, bool $hooked): void
{
    try {
        DB::connection('pgsql')->table('core.pgir_fork_probe')->insert([
            'child_idx' => $idx, 'outcome' => $outcome, 'elapsed_ms' => $elapsedMs, 'hooked' => $hooked,
        ]);
    } catch (Throwable $e) {
        // A poisoned connection must never read as a clean run — re-open and record the sentinel.
        DB::purge('pgsql');
        DB::reconnect('pgsql');
        DB::connection('pgsql')->table('core.pgir_fork_probe')->insert([
            'child_idx' => $idx,
            'outcome' => 'PROBE_INSERT_FAILED:'.get_class($e).':'.substr($e->getMessage(), 0, 200),
            'elapsed_ms' => $elapsedMs,
            'hooked' => $hooked,
        ]);
    }
}

/** A forked child's fresh PDO carries none of the parent's timeouts — a real deadlock must fail, not hang. */
function pgirChildConnection(): void
{
    DB::purge('pgsql');
    DB::reconnect('pgsql');
    DB::connection('pgsql')->statement('SET lock_timeout = 10000');
    DB::connection('pgsql')->statement('SET statement_timeout = 20000');
}

/**
 * Fork child A (whose read->write window is held open) and child B (the "these two are the same"
 * decision plus its own re-add, landing inside that window).
 *
 * Child A's hook fires on the FINAL source_items re-read — the last statement before the
 * per-target UPDATE loop — not on anything inside bindGroup(). That placement is what makes the
 * file detect BOTH failures the brief names: with no lock, child B commits its merge during the
 * sleep and child A's UPDATE lands on a hard-deleted item; and if the transaction were shortened
 * to end before this tail, the sleep itself would fall outside the lock and child B would get in
 * anyway. Hooking inside bindGroup() detects neither — it detects only the missing transaction.
 *
 * When $bUserId differs, child B is a DIFFERENT owner doing the same thing to their own pool —
 * the isolation case, which must NOT be serialised behind A.
 */
function pgirRunRace(
    string $userId,
    string $connectorCoord,
    string $manualCoord,
    ?string $bUserId = null,
    ?string $bConnectorCoord = null,
    ?string $bManualCoord = null,
): Collection {
    pgirResetProbe();

    $bUserId ??= $userId;
    $bConnectorCoord ??= $connectorCoord;
    $bManualCoord ??= $manualCoord;

    $startAt = microtime(true) + 0.25;

    $pids = [];
    for ($i = 0; $i < 2; $i++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('pcntl_fork failed');
        }

        if ($pid !== 0) {
            $pids[] = $pid;

            continue;
        }

        pgirChildConnection();
        usleep((int) max(0, ($startAt - microtime(true)) * 1_000_000));

        $hooked = false;
        $elapsedMs = 0;

        try {
            if ($i === 0) {
                // Timing only — commits nothing, changes no state.
                DB::listen(function ($query) use (&$hooked) {
                    if ($hooked) {
                        return; // fire-once: the pg_sleep below re-enters this listener.
                    }
                    $sql = strtolower($query->sql);
                    // resolveItems()' closing re-read: the only statement that selects item_id
                    // from an UNALIASED content.source_items by a subquery of ids.
                    if (! str_contains($sql, 'from "content"."source_items" where "id" in (select')) {
                        return;
                    }
                    if (! str_contains($sql, '"item_id"')) {
                        return;
                    }
                    $hooked = true;
                    DB::connection('pgsql')->select('select pg_sleep('.PGIR_HOLD_SECONDS.')');
                });

                app(ProjectionWriter::class)->writeManualItem($userId, $manualCoord, pgirLinkProjection($manualCoord));
                pgirRecordProbe($i, 'ok', 0, $hooked);
                exit(0);
            }

            // Child B: the owner says "these two are the same" in another tab, then that tab's
            // own write re-resolves the pool.
            usleep(PGIR_B_DELAY_US);

            DB::connection('pgsql')->table('content.identity_decisions')->insert([
                'id' => (string) Str::uuid(), 'user_id' => $bUserId, 'verdict' => 'same',
                'left_coord' => $bConnectorCoord, 'right_coord' => $bManualCoord, 'decided_at' => now(),
            ]);

            $began = microtime(true);
            app(ProjectionWriter::class)->writeManualItem($bUserId, $bManualCoord, pgirLinkProjection($bManualCoord));
            $elapsedMs = (int) round((microtime(true) - $began) * 1000);

            pgirRecordProbe($i, 'ok', $elapsedMs, false);
            exit(0);
        } catch (Throwable $e) {
            pgirRecordProbe(
                $i,
                'THROWN:'.get_class($e).':'.((string) $e->getCode()).':'.substr($e->getMessage(), 0, 240),
                $elapsedMs,
                $hooked,
            );
            exit(0);
        }
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    return DB::connection('pgsql')->table('core.pgir_fork_probe')->orderBy('child_idx')->get();
}

/** A dump of the whole identity state, so a failure names what actually happened. */
function pgirState(string $userId): string
{
    $pg = DB::connection('pgsql');
    $short = fn ($id) => $id === null ? 'null' : substr((string) $id, 0, 8);

    $out = [];
    foreach ($pg->table('content.items')->where('user_id', $userId)->orderBy('created_at')->get() as $i) {
        $out[] = 'item '.$short($i->id).' removed='.($i->removed_at ?? '-');
    }
    foreach ($pg->table('content.item_anchors')->where('user_id', $userId)->orderBy('bound_at')->get() as $a) {
        $out[] = 'anchor '.$a->coord.' -> '.$short($a->item_id).' superseded='.$short($a->superseded_by);
    }
    foreach ($pg->table('content.source_items as si')->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
        ->where('cs.user_id', $userId)->get(['si.coord', 'si.item_id', 'cs.kind as source_kind', 'si.removed_at']) as $r) {
        $out[] = 'source_item '.$r->coord.' ('.$r->source_kind.') -> '.$short($r->item_id).' removed='.($r->removed_at ?? '-');
    }
    foreach ($pg->table('content.item_merges')->where('user_id', $userId)->orderBy('merged_at')->get() as $m) {
        $out[] = 'merge kept='.$short($m->kept_item_id).' discarded='.$short($m->discarded_item_id);
    }

    return implode("\n  ", $out);
}

/** Every live source item of the kind agrees with the anchors about which item it belongs to. */
function pgirAssertConsistent(string $userId): void
{
    $pg = DB::connection('pgsql');
    $state = "\n  ".pgirState($userId);

    $liveItemIds = $pg->table('content.items')->where('user_id', $userId)->pluck('id')
        ->map(fn ($id) => (string) $id)->all();

    $sourceItems = $pg->table('content.source_items as si')
        ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
        ->where('cs.user_id', $userId)->whereNull('si.removed_at')
        ->get(['si.coord', 'si.item_id']);

    foreach ($sourceItems as $row) {
        expect($row->item_id)->not->toBeNull("source item {$row->coord} was left with no item.{$state}");
        // in_array()/toBeTrue() rather than toContain(): Pest's toContain() is VARIADIC, so a
        // second argument is another expected VALUE, not a failure message — passing one turns
        // the assertion into "contains the id AND contains the message string" and it fails for
        // the wrong reason with the right-looking text.
        expect(in_array((string) $row->item_id, $liveItemIds, true))->toBeTrue(
            "source item {$row->coord} points at item {$row->item_id}, which no longer exists.{$state}",
        );
    }

    foreach ($pg->table('content.item_anchors')->where('user_id', $userId)->get(['coord', 'item_id', 'superseded_by']) as $anchor) {
        $effective = (string) ($anchor->superseded_by ?? $anchor->item_id);
        $sourceItem = $sourceItems->firstWhere('coord', $anchor->coord);
        if ($sourceItem === null) {
            continue;
        }
        expect((string) $sourceItem->item_id)->toBe(
            $effective,
            "coord {$anchor->coord}: source item says {$sourceItem->item_id}, anchor says {$effective}.{$state}",
        );
    }
}

/** Both children finished on their own terms, and child A's window actually opened. */
function pgirAssertRanCleanly(Collection $probes): void
{
    expect($probes)->toHaveCount(2, 'a child died before recording its outcome');

    $outcomes = $probes->pluck('outcome')->implode(' | ');
    expect($probes->filter(fn ($p) => $p->outcome !== 'ok'))
        ->toHaveCount(0, "A child raised instead of resolving cleanly. Outcomes: {$outcomes}");

    expect($probes->firstWhere('child_idx', 0)?->hooked)->toBeTrue(
        'child A never reached the final source_items re-read — the window never opened and every assertion here is vacuous',
    );
}

it('does not lose the merge when a second resolveItems() commits inside the first one\'s read-to-write window', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl_fork is not available in this runtime');
    }

    [$userId, $connectorCoord, , $manualCoord] = pgirScenario();

    $probes = pgirRunRace($userId, $connectorCoord, $manualCoord);

    pgirAssertRanCleanly($probes);

    // The union the owner asked for happened, and it survived.
    expect(DB::connection('pgsql')->table('content.items')->where('user_id', $userId)->count())
        ->toBe(1, 'the two coords did not end up on one item — the merge was lost.'."\n  ".pgirState($userId));

    pgirAssertConsistent($userId);
});

it('makes the second caller wait for the first, rather than resolving against a snapshot the first is about to invalidate', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl_fork is not available in this runtime');
    }

    [$userId, $connectorCoord, , $manualCoord] = pgirScenario();

    $probes = pgirRunRace($userId, $connectorCoord, $manualCoord);

    expect($probes->firstWhere('child_idx', 0)?->hooked)->toBeTrue('child A never held the window open — the measurement below is meaningless');

    // Nothing in this scenario inserts an anchor (see pgirScenario()), so no unique-index row
    // lock can serialise the two children. If child B waited, the advisory lock is the only
    // thing that made it wait. Asserting on the WAIT rather than the outcome is deliberate: an
    // outcome-only assertion passes with no lock whenever the scheduler happens to order the
    // children favourably.
    $floorMs = (int) ((PGIR_HOLD_SECONDS * 1000) - (PGIR_B_DELAY_US / 1000) - 250);
    $elapsed = (int) ($probes->firstWhere('child_idx', 1)?->elapsed_ms ?? 0);

    expect($elapsed)->toBeGreaterThanOrEqual(
        $floorMs,
        "child B returned in {$elapsed}ms; it was not serialised behind child A (expected at least {$floorMs}ms)",
    );
});

it('does not serialise a different owner behind the lock', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl_fork is not available in this runtime');
    }

    // The lock key is identity:{user_id}:{kind}. Keyed any more coarsely — per kind, or a single
    // global key — every assertion in this file would still pass while one owner's dashboard
    // write blocked every other owner's. That regression has no other detector.
    [$userA, $connectorA, , $manualA] = pgirScenario();
    [$userB, $connectorB, , $manualB] = pgirScenario();

    $probes = pgirRunRace($userA, $connectorA, $manualA, bUserId: $userB, bConnectorCoord: $connectorB, bManualCoord: $manualB);

    pgirAssertRanCleanly($probes);

    $elapsed = (int) ($probes->firstWhere('child_idx', 1)?->elapsed_ms ?? 0);
    $ceilingMs = (int) ((PGIR_HOLD_SECONDS * 1000) - (PGIR_B_DELAY_US / 1000) - 250);

    expect($elapsed)->toBeLessThan(
        $ceilingMs,
        "a second owner's write took {$elapsed}ms — it was serialised behind an unrelated owner's lock",
    );

    pgirAssertConsistent($userA);
    pgirAssertConsistent($userB);
    expect(DB::connection('pgsql')->table('content.items')->where('user_id', $userB)->count())->toBe(1);
});

// ── #SCALE-4's staleness guard ────────────────────────────────────────────────────────────────
//
// The run-wide anchor prefetch is dropped the moment any group merges, because mergeInto()'s
// anchor UPDATE is keyed on the ITEM (item_id / superseded_by), not on the group's coords, and
// its hard DELETE cascades content.item_anchors. A later group reading the pre-merge snapshot
// therefore binds to an item that no longer exists.
//
// This needs no concurrency at all — one pass, two groups, the first merging and the second
// reading. It exists because the whole 20-line rationale on that guard had NO detector: deleting
// `$merged = true` from the losers loop left the entire PG lane and tests/Feature/{Ingest,Content}
// green.
it('re-reads a group\'s anchors after an earlier group in the same pass merged the item they point at', function () {
    $pg = DB::connection('pgsql');

    $userId = (string) Str::uuid();
    $pg->table('core.users')->insert(['id' => $userId]);
    $pg->table('site.sites')->insert(['id' => (string) Str::uuid(), 'user_id' => $userId]);

    $connectionId = (string) Str::uuid();
    $pg->table('site.platform_connections')->insert([
        'id' => $connectionId, 'user_id' => $userId, 'resource_id' => 'pgir', 'deleted_at' => null,
    ]);
    $sourceId = (string) Str::uuid();
    $pg->table('content.sources')->insert([
        'id' => $sourceId, 'user_id' => $userId, 'kind' => 'connection',
        'connection_id' => $connectionId, 'label' => 'pgir.connector',
    ]);

    // Two items. R shares Q's item — the ordinary residue of an earlier merge.
    $keptItemId = (string) Str::uuid();
    $doomedItemId = (string) Str::uuid();
    foreach ([[$keptItemId, 3], [$doomedItemId, 2]] as [$itemId, $hoursAgo]) {
        $pg->table('content.items')->insert([
            'id' => $itemId, 'user_id' => $userId, 'kind' => 'link',
            'first_seen_at' => now()->subHours($hoursAgo), 'last_seen_at' => now()->subHours($hoursAgo),
            'created_at' => now()->subHours($hoursAgo), 'updated_at' => now()->subHours($hoursAgo),
        ]);
    }

    // first_seen_at fixes the read order, and DisjointSet::groups() emits groups in the order
    // their first member was read — so {P,Q} is processed before {R}, which is the whole point.
    $coords = [
        ['pgir:p', $keptItemId, 3],
        ['pgir:q', $doomedItemId, 2],
        ['pgir:r', $doomedItemId, 1],
    ];
    foreach ($coords as [$coord, $itemId, $hoursAgo]) {
        $pg->table('content.source_items')->insert([
            'id' => (string) Str::uuid(), 'source_id' => $sourceId, 'coord' => $coord,
            'item_id' => $itemId, 'kind' => 'link', 'projector_version' => 1,
            'first_seen_at' => now()->subHours($hoursAgo), 'last_seen_at' => now()->subHours($hoursAgo),
        ]);
        $pg->table('content.item_anchors')->insert([
            'coord' => $coord, 'user_id' => $userId, 'item_id' => $itemId,
            'bound_at' => now()->subHours($hoursAgo),
        ]);
    }

    // The owner unites P and Q. That merge discards $doomedItemId and hard-deletes it — taking
    // R's anchor row with it, via item_anchors.item_id's ON DELETE CASCADE.
    $pg->table('content.identity_decisions')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $userId, 'verdict' => 'same',
        'left_coord' => 'pgir:p', 'right_coord' => 'pgir:q', 'decided_at' => now(),
    ]);

    // Any manual write drives one full resolveItems() pass over all four coords.
    $trigger = 'manual:'.sha1('pgir-stale-'.Str::random(8));
    app(ProjectionWriter::class)->writeManualItem($userId, $trigger, pgirLinkProjection($trigger));

    // Without the guard, group {R} binds to $doomedItemId off the stale snapshot and the closing
    // UPDATE raises 23503 against a row that no longer exists.
    expect($pg->table('content.items')->where('id', $doomedItemId)->exists())
        ->toBeFalse('the merge did not happen, so this test proves nothing.'."\n  ".pgirState($userId));

    pgirAssertConsistent($userId);
});

// ── what a lock timeout leaves behind ─────────────────────────────────────────────────────────
//
// writeManualItem() commits the source item and its identity keys BEFORE resolveItems() runs, so
// #LIFE-1 made a timeout reachable between two committed states. Uncompensated, that row is
// bound by the NEXT resolve to a freshly minted item that writeFacets() never populated — a
// blank card, in a pool the owner was told had failed to save.
//
// Slow by construction: the bound is IDENTITY_LOCK_TIMEOUT_MS and SET LOCAL overrides any
// session value, so the wait cannot be shortened from the test side. It is here because the
// alternative is an untested compensation on the content spine.
it('retires a source item it created when identity resolution times out, and leaves a pre-existing one alone', function () {
    config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);

    $pg = DB::connection('pgsql');
    $userId = (string) Str::uuid();
    $pg->table('core.users')->insert(['id' => $userId]);
    $pg->table('site.sites')->insert(['id' => (string) Str::uuid(), 'user_id' => $userId]);

    $writer = app(ProjectionWriter::class);

    // An item the owner already has, landed cleanly before any contention.
    $existingCoord = 'manual:'.sha1('pgir-existing-'.Str::random(8));
    $writer->writeManualItem($userId, $existingCoord, pgirLinkProjection($existingCoord));

    // A genuinely separate backend holds identity:{user}:link for the duration.
    $blocker = DB::connection('pgsql_second');
    $blocker->beginTransaction();
    $blocker->select('select pg_advisory_xact_lock(hashtext(?))', ["identity:{$userId}:link"]);

    try {
        $newCoord = 'manual:'.sha1('pgir-new-'.Str::random(8));

        $thrown = null;
        try {
            $writer->writeManualItem($userId, $newCoord, pgirLinkProjection($newCoord));
        } catch (Throwable $e) {
            $thrown = $e;
        }

        expect($thrown)->toBeInstanceOf(AdvisoryLockTimeoutException::class);
        expect($thrown->getHttpStatusCode())->toBe(423);
        // The body is rendered from getMessage() verbatim, so it must not carry the key.
        expect($thrown->getMessage())->not->toContain($userId);
        expect($thrown->getMessage())->not->toContain('identity:');

        $newRow = $pg->table('content.source_items')->where('coord', $newCoord)->first();
        expect($newRow)->not->toBeNull('the source item was never committed, so there is nothing to compensate');
        expect($newRow->removed_at)->not->toBeNull('a source item this call created was left live with no item — the next resolve binds it to a facet-less blank');
        expect($newRow->item_id)->toBeNull();

        // The owner's existing row must survive the identical failure untouched.
        $thrownAgain = null;
        try {
            $writer->writeManualItem($userId, $existingCoord, pgirLinkProjection($existingCoord));
        } catch (Throwable $e) {
            $thrownAgain = $e;
        }

        expect($thrownAgain)->toBeInstanceOf(AdvisoryLockTimeoutException::class);

        $existingRow = $pg->table('content.source_items')->where('coord', $existingCoord)->first();
        expect($existingRow->removed_at)->toBeNull('a re-add of an EXISTING coord retired the owner\'s real content');
    } finally {
        $blocker->rollBack();
        DB::purge('pgsql_second');
    }
})->group('slow');
