<?php

// #W1-LIFE-2: ProjectionWriter::ensureContentSource() (:379-403 pre-fix) did a bare
// SELECT ... WHERE connection_id -> unconditional insert(), no conflict handling,
// against idx_content_sources_connection — a PARTIAL unique index on (connection_id)
// WHERE connection_id IS NOT NULL (supabase/migrations/20260727140000_content_schema.sql:40-41).
// Two concurrent callers projecting streams for the same connection_id (reachable:
// `ingest:project` bypasses SourceScheduler's per-source claim entirely — see the
// method's own comment) can both miss the fast-path SELECT and both attempt an
// insert; the loser used to hit an uncaught 23505 straight out of RunExecutor's
// try/catch, downgrading the whole run to degraded and paging via a
// severity='critical' ingest.anomalies row.
//
// The fix is the file's own established idiom 30 lines below in
// ensureManualSource(): insertOrIgnore + re-read + a loud RuntimeException if the
// re-read comes back null. THE TRAP this test exists to catch: returning the
// locally-minted uuid instead of the re-read value is the naive half-fix — the
// loser's insert is silently suppressed by the index, but every content.source_items
// row the run then writes carries a source_id with no backing content.sources row.
//
// DDL: a renamed clone of ProjectionWriterBatchingTest.php's beforeEach (see that
// file's own header for why local DDL is required and sanctioned here, and why
// nothing may be hoisted into tests/Support/). Every pwbt_-prefixed
// constraint/index identifier there is renamed pwcs_ here so the two files' global
// Pest identifier symbol tables cannot collide (the lane loads every file into one
// process). ONE deviation from a verbatim clone: the missing
// pwcs_content_sources_connection unique index is ADDED on content.sources
// (connection_id) WHERE connection_id IS NOT NULL — none of the lane's existing
// content.sources DDL clones carry a unique index on this column at all, so without
// it the race below has nothing to lose on and the test would be vacuous. Index
// names are not compared by PostgresLaneDdlDriftTest (tables/columns only), so this
// passes that gate.
//
// MECHANISM: deterministic DB::listen injection (the pattern from
// ProjectionWriterManualCoordRaceTest.php's first test), not pcntl_fork. When the
// fast-path `select ... from content.sources where connection_id = ?` fires, a
// genuinely separate pgsql_second connection commits a winning content.sources row
// for the SAME connection_id before this caller's own insertOrIgnore runs.
//
// DRIVEN THROUGH THE PUBLIC SEAM: projectStream($source, $streamId, 'releases')
// with one landed ingest.record_versions/record_state row and the registered
// bandcamp/releases projector — never reflects into the private method.
use App\Ingest\Projection\ProjectionWriter;
use Illuminate\Database\UniqueConstraintViolationException;
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
        'content.source_stats', 'content.item_slugs',
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

    // deleted_at + is_active: disconnect = HIDE (overnight 2026-08-18 ruling).
    // ProjectionWriter::…$liveSource (:604) excludes a source whose connection the
    // owner removed from the identity vote, and LiveSourceScope adds is_active on
    // the pool side — both read columns this hand-written stand-in never had, so
    // the whole query is SQLSTATE 42703 before anything under test is evaluated.
    // Same drift trap the site.sites comment below records: this DDL is
    // hand-written and does not follow the writer.
    $pg->statement('CREATE TABLE site.platform_connections (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
        resource_id text,
        is_active boolean NOT NULL DEFAULT true,
        deleted_at timestamptz NULL
    )');

    // subdomain + updated_at: projectStream() fires all three cache lanes via
    // SiteCacheLanes::bust(), which touches updated_at (the origin payload
    // cache key) and reads subdomain to decide on the edge purge. Omitting
    // either is SQLSTATE 42703 here and silently fine on the SQLite lane —
    // this stand-in DDL is hand-written and drifts from the writer.
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

    // ── ingest.* — flat (not hash-partitioned; see file header). ───────────
    $pg->statement('CREATE TABLE ingest.sources (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL,
        connection_id uuid,
        source_key text NOT NULL,
        surface_key text NOT NULL DEFAULT \'releases\',
        identifier text NOT NULL DEFAULT \'test\'
    )');

    $pg->statement('CREATE TABLE ingest.streams (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        source_id uuid NOT NULL REFERENCES ingest.sources(id) ON DELETE CASCADE,
        stream_name text NOT NULL
    )');

    $pg->statement('CREATE TABLE ingest.record_versions (
        id bigserial PRIMARY KEY,
        stream_id uuid NOT NULL,
        key text NOT NULL,
        doc_hash text NOT NULL,
        doc jsonb NOT NULL,
        first_seen_at timestamptz NOT NULL DEFAULT now(),
        is_current boolean NOT NULL DEFAULT true
    )');
    $pg->statement('CREATE UNIQUE INDEX idx_pwcs_rv_content ON ingest.record_versions (stream_id, key, doc_hash)');
    $pg->statement('CREATE INDEX idx_pwcs_rv_current ON ingest.record_versions (stream_id, key) WHERE is_current');

    $pg->statement('CREATE TABLE ingest.record_state (
        stream_id uuid NOT NULL,
        key text NOT NULL,
        current_version_id bigint,
        last_seen_at timestamptz NOT NULL DEFAULT now(),
        tombstoned_at timestamptz,
        PRIMARY KEY (stream_id, key)
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

    // THE INDEX UNDER TEST — idx_content_sources_connection
    // (20260727140000_content_schema.sql:40-41), PARTIAL on connection_id
    // IS NOT NULL. None of the lane's other content.sources DDL clones carry
    // a unique index at all; without this the race below is vacuous.
    $pg->statement('CREATE UNIQUE INDEX pwcs_content_sources_connection
        ON content.sources (connection_id) WHERE connection_id IS NOT NULL');

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

    // refreshItemCaches() -> ContentItemSlugAllocator::currentSlugs() reads this
    // for EVERY touched-item batch regardless of kind (#SCALE-9/#API-7 batched
    // the read out of the per-item loop, unconditionally) — every run through
    // this file trips SQLSTATE 42P01 without it. Shape per
    // supabase/migrations/20260727140000_content_schema.sql:466 plus
    // 20260731210000's retired_at and 20260812040000's one-current-per-item
    // unique index; this stand-in DDL had drifted from the writer before this
    // fix (pre-existing gap, unrelated to #SCALE-8 — the whole file failed on
    // it at HEAD, verified before making any change here).
    $pg->statement('CREATE TABLE content.item_slugs (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        slug text NOT NULL,
        is_current boolean NOT NULL DEFAULT true,
        created_at timestamptz NOT NULL DEFAULT now(),
        retired_at timestamptz
    )');
    $pg->statement('CREATE UNIQUE INDEX idx_pwcs_item_slugs_unique ON content.item_slugs (user_id, slug)');
    $pg->statement('CREATE UNIQUE INDEX idx_pwcs_item_slugs_one_current ON content.item_slugs (item_id) WHERE is_current');

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
        CONSTRAINT pwcs_source_items_coord_unique UNIQUE (source_id, coord)
    )');
    $pg->statement('CREATE INDEX idx_pwcs_source_items_item ON content.source_items (item_id)');

    $pg->statement('CREATE TABLE content.identity_keys (
        id bigserial PRIMARY KEY,
        source_item_id uuid NOT NULL REFERENCES content.source_items(id) ON DELETE CASCADE,
        key_class text NOT NULL,
        key_value text NOT NULL,
        tier text NOT NULL CHECK (tier IN (\'joining\',\'corroborating\',\'evidential\')),
        created_at timestamptz NOT NULL DEFAULT now()
    )');
    $pg->statement('CREATE INDEX idx_pwcs_identity_keys_source_item ON content.identity_keys (source_item_id)');

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
        CONSTRAINT pwcs_identity_candidates_pair UNIQUE (user_id, left_item_id, right_item_id)
    )');

    $pg->statement('CREATE TABLE content.manual_overrides (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        facet text NOT NULL,
        column_name text NOT NULL,
        value jsonb,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT pwcs_manual_overrides_unique UNIQUE (item_id, facet, column_name)
    )');

    $singletons = [
        'f_text' => 'headline text, body text, summary text',
        'f_link' => 'url text NOT NULL, canonical_url text',
        'f_duration' => 'seconds integer',
        'f_published' => 'published_from timestamptz, published_to timestamptz, verbatim text, precision text',
        // zone_confidence carries the production CHECK verbatim (Nightwatch
        // #370): the stand-in previously declared it bare, so the real-Postgres
        // lane could not catch a projector inventing a fourth enum value.
        'f_occurrence' => "starts_at_local timestamp, ends_at_local timestamp, timezone text, zone_confidence text CHECK (zone_confidence IS NULL OR zone_confidence IN ('explicit', 'inferred', 'assumed', 'offset_only')), starts_at_utc timestamptz, is_all_day boolean NOT NULL DEFAULT false",
        'f_embed' => 'provider text NOT NULL, embed_key text NOT NULL, variant text',
        'f_playable' => 'stream_url text, preview_url text, is_explicit boolean',
        'f_authored' => 'creator text, creator_url text, collaborators jsonb',
        // handle/vendor/variant_ref: supabase/migrations/20260813100002_f_catalog_product_fields.sql.
        'f_catalog' => 'release_type text, track_number integer, disc_number integer, isrc text, gtin text, sku text, handle text, vendor text, variant_ref text, collection_title text',
        'f_place' => 'venue_name text, address text, locality text, region text, country_code text, latitude double precision, longitude double precision',
        'f_rated' => 'rating double precision, rating_max double precision, ratings_count integer',
        // author_uri: supabase/migrations/20260813110000_f_review_author_uri.sql.
        'f_review' => 'author_name text, author_photo_url text, author_uri text, rating double precision, text text, reviewed_at timestamptz',
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

    // Slice 6 (supabase/migrations/20260813110001_create_content_source_stats.sql):
    // source-level aggregates, keyed by source_id alone with no item_id, so it is
    // not a singleton facet and does not fit the loop above.
    $pg->statement('CREATE TABLE content.source_stats (
        source_id uuid PRIMARY KEY REFERENCES content.sources(id) ON DELETE CASCADE,
        rating_avg double precision,
        rating_count integer,
        summary_text text,
        updated_at timestamptz NOT NULL DEFAULT now()
    )');

    // Shape tracks supabase/migrations/20260727140000_content_schema.sql:353 plus
    // 20260812090000's site_media_id. site_media_id carries no FK here on purpose —
    // ProjectionWriter only ever writes NULL into it, and the ON DELETE SET NULL
    // behaviour is MediaAssetSiteMediaFkTest's subject, not this file's.
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
        mirror_attempts integer NOT NULL DEFAULT 0,
        mirror_last_attempt_at timestamptz,
        mirror_last_reason text,
        created_at timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT pwcs_media_assets_fingerprint_unique UNIQUE (user_id, fingerprint)
    )');

    $pg->statement('CREATE TABLE content.item_media (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        source_id uuid NOT NULL REFERENCES content.sources(id) ON DELETE CASCADE,
        source_item_id uuid REFERENCES content.source_items(id) ON DELETE CASCADE,
        asset_id uuid REFERENCES content.media_assets(id) ON DELETE SET NULL,
        role text NOT NULL CHECK (role IN (\'cover\',\'gallery\',\'poster\',\'avatar\',\'logo\')),
        position integer NOT NULL DEFAULT 0,
        alt_text text,
        created_at timestamptz NOT NULL DEFAULT now()
    )');
    $pg->statement('CREATE INDEX idx_pwcs_item_media_item ON content.item_media (item_id, role, position)');

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
    $pg->statement('CREATE INDEX idx_pwcs_offers_item ON content.offers (item_id)');

    // Shape tracks supabase/migrations/20260727140000_content_schema.sql:404 —
    // ProjectionWriter writes and deletes this table (label is NOT NULL there)
    // — plus image_url from 20260813100003 (slice 5a Task 8 fix round 2, D1).
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
    $pg->statement('CREATE INDEX idx_pwcs_item_variants_item ON content.item_variants (item_id)');

    $pg->statement('CREATE TABLE content.item_tags (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        source_id uuid NOT NULL REFERENCES content.sources(id) ON DELETE CASCADE,
        source_item_id uuid REFERENCES content.source_items(id) ON DELETE CASCADE,
        tag text NOT NULL,
        tag_type text
    )');
    $pg->statement('CREATE INDEX idx_pwcs_item_tags_item ON content.item_tags (item_id)');

    $pg->statement('CREATE TABLE content.f_action (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        source_id uuid NOT NULL REFERENCES content.sources(id) ON DELETE CASCADE,
        intent text NOT NULL,
        label text,
        url text NOT NULL,
        position integer NOT NULL DEFAULT 0
    )');
    $pg->statement('CREATE INDEX idx_pwcs_f_action_item ON content.f_action (item_id)');

    // Slice 3b Task 5: replaceCollections() now DELETEs content.collection_items
    // per chunk alongside the other four collection tables, unconditionally —
    // the replace semantics require it even when the batch names no
    // collections. Without this DDL every test in this file dies on
    // SQLSTATE 42P01 rather than on an assertion (the hand-written-stand-in
    // drift CLAUDE.md flags for this lane). Shape tracks
    // 20260727140000_content_schema.sql:444/458 plus
    // 20260813090000_slice3b_collections_keys_and_selection_ref.sql.
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
    $pg->statement('CREATE UNIQUE INDEX idx_pwcs_collections_natural_key
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
        'content.identity_decisions', 'content.identity_keys', 'content.source_items', 'content.source_stats',
        'content.item_slugs',
        'content.f_file',
        'content.f_channel', 'content.f_review', 'content.f_rated', 'content.f_place', 'content.f_catalog',
        'content.f_authored', 'content.f_playable', 'content.f_embed', 'content.f_occurrence', 'content.f_published',
        'content.f_duration', 'content.f_link', 'content.f_text', 'content.items', 'content.sources',
        'ingest.record_state', 'ingest.record_versions', 'ingest.streams', 'ingest.sources',
        'site.site_build_state', 'site.sites', 'site.platform_connections', 'core.users',
    ] as $t) {
        $pg->statement("DROP TABLE IF EXISTS {$t} CASCADE");
    }
});

/** A user + a bandcamp platform connection with a known resource_id, its site, and a "releases" stream. */
function pwcsFixture(string $resourceIdSuffix): array
{
    $pg = DB::connection('pgsql');
    $userId = (string) Str::uuid();
    $pg->table('core.users')->insert(['id' => $userId]);

    $resourceId = 'acct-'.str_pad($resourceIdSuffix, 16, '0', STR_PAD_LEFT);
    $connectionId = (string) Str::uuid();
    $pg->table('site.platform_connections')->insert(['id' => $connectionId, 'user_id' => $userId, 'resource_id' => $resourceId]);

    $siteId = (string) Str::uuid();
    $pg->table('site.sites')->insert(['id' => $siteId, 'user_id' => $userId]);

    $ingestSourceId = (string) Str::uuid();
    $pg->table('ingest.sources')->insert(['id' => $ingestSourceId, 'user_id' => $userId, 'connection_id' => $connectionId, 'source_key' => 'bandcamp']);

    $streamId = (string) Str::uuid();
    $pg->table('ingest.streams')->insert(['id' => $streamId, 'source_id' => $ingestSourceId, 'stream_name' => 'releases']);

    $source = ['id' => $ingestSourceId, 'source_key' => 'bandcamp', 'connection_id' => $connectionId, 'user_id' => $userId, 'identifier' => 'test'];

    return [$userId, $connectionId, $source, $streamId];
}

/** Content-addressed landing — see ProjectionWriterBatchingTest.php's pwbtLand() for the full rationale. */
function pwcsLand(string $streamId, string $key, array $doc): void
{
    $pg = DB::connection('pgsql');
    $docHash = sha1(json_encode($doc));

    $id = $pg->table('ingest.record_versions')->insertGetId([
        'stream_id' => $streamId, 'key' => $key, 'doc_hash' => $docHash,
        'doc' => json_encode($doc), 'first_seen_at' => now(), 'is_current' => true,
    ]);

    $pg->table('ingest.record_state')->updateOrInsert(
        ['stream_id' => $streamId, 'key' => $key],
        ['current_version_id' => $id, 'last_seen_at' => now()]
    );
}

/** BandcampReleaseProjector.php:44 emits empty `media` when art_url is null — see pwbtDoc()'s docblock. */
function pwcsDoc(string $title, string $url): array
{
    return ['title' => $title, 'url' => $url, 'artist' => 'Some Artist', 'release_date' => '2025-05-05', 'art_url' => $url.'/art.jpg', 'type' => 'album'];
}

it('re-reads and propagates the WINNER\'s content.sources row instead of the local uuid when two runs race ensureContentSource() for the same connection_id', function () {
    config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);

    [$userId, $connectionId, $source, $streamId] = pwcsFixture('1');
    pwcsLand($streamId, 'item-1', pwcsDoc('Race Item', 'https://pwcs-race.bandcamp.com/album/item-1'));

    $winnerSourceId = (string) Str::uuid();

    $fired = false;
    DB::listen(function ($query) use (&$fired, $connectionId, $winnerSourceId, $userId) {
        if ($fired) {
            return; // fire-once: only the FIRST matching statement (the fast-path pre-read) is under test.
        }

        $sql = strtolower($query->sql);
        // "sources" (unlike "source_items"/"source_stats") never appears as a
        // substring of any other table this file provisions.
        if (! str_contains($sql, 'sources') || ! str_contains($sql, 'select') || str_contains($sql, 'insert')) {
            return;
        }
        if (! in_array($connectionId, $query->bindings, true)) {
            return; // not this record's pre-read
        }

        $fired = true;

        // A genuinely separate, independently-resolved Postgres connection commits a
        // fully materialised winner for the SAME connection_id before this caller's own
        // insertOrIgnore runs — mirrors ProjectionWriterManualCoordRaceTest.php's
        // deterministic injection.
        DB::connection('pgsql_second')->table('content.sources')->insert([
            'id' => $winnerSourceId,
            'user_id' => $userId,
            'kind' => 'connection',
            'connection_id' => $connectionId,
            'label' => 'bandcamp',
            'priority' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $result = null;
    $thrown = null;
    try {
        $result = app(ProjectionWriter::class)->projectStream($source, $streamId, 'releases');
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($fired)->toBeTrue('the injection never fired — the assertions below would be vacuous');

    // No UniqueConstraintViolationException — or anything else — escapes projectStream().
    expect($thrown)->toBeNull('projectStream() threw: '.($thrown?->getMessage() ?? ''))
        ->and($thrown)->not->toBeInstanceOf(UniqueConstraintViolationException::class);

    $pg = DB::connection('pgsql');

    // THE PINNED REFUSAL REASON: exactly one content.sources row for this
    // connection_id, and it is the WINNER's row — the loser's insert was
    // suppressed by pwcs_content_sources_connection and re-read the winner,
    // rather than throwing or returning its own locally-minted uuid.
    $sourceRows = $pg->table('content.sources')->where('connection_id', $connectionId)->get();
    expect($sourceRows)->toHaveCount(1);
    expect((string) $sourceRows->first()->id)->toBe($winnerSourceId);

    // THIS IS THE ASSERTION THAT FAILS ON THE NAIVE insertOrIgnore-but-return-$id
    // HALF-FIX: every content.source_items row this run wrote carries
    // source_id = $winnerSourceId, i.e. the re-read id actually propagated through
    // the rest of projectStream() rather than the discarded local mint.
    // The fixture lands exactly one record, so this table holds exactly this
    // run's own writes — no coord-format assumption needed.
    $sourceItemRows = $pg->table('content.source_items')->get();
    expect($sourceItemRows)->toHaveCount(1);
    expect((string) $sourceItemRows->first()->source_id)->toBe($winnerSourceId);

    expect($result['status'] ?? null)->toBe('ok');

    // Non-poisoning proof (LIFE-16 shape): projectStream() opens a transaction per
    // record immediately after ensureContentSource() — a swallowed 23505/23503 in
    // there would leave the connection ABORTED (SQLSTATE 25P02) for every later
    // statement.
    expect(DB::connection('pgsql')->select('select 1 as one')[0]->one)->toBe(1);

    DB::purge('pgsql_second');
});
