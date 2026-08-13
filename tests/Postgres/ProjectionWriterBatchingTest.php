<?php

// SCALE-6/7/8 (unit-10-projectionwriter plan) — the real-Postgres proof the
// SQLite lane cannot provide:
//   1. the set-based batch queries (subquery whereIn, chunked exists/distinct,
//      whereIn-grouped updates) are valid Postgres and use the existing
//      indexes at N=200, not just SQLite-legal syntax;
//   2. the jsonb ROUND-TRIP PARITY TRAP: Postgres re-serialises jsonb with its
//      own spacing on every read, so a naive string compare of facets_cache
//      would defeat the diff-gated write's skip on every run under Postgres
//      while still passing under SQLite (CLAUDE.md's "tests run SQLite, prod
//      is Postgres" hazard, concretely) — this asserts the steady-state
//      (second, unchanged) run issues ZERO narrow content.items UPDATEs;
//   3. lazy(500) paging by real timestamptz first_seen_at, with the rs.key
//      tiebreak, does not skip or duplicate rows when many share a
//      first_seen_at (a plain PHP array can't reproduce LIMIT/OFFSET drift
//      the way a real paged query can);
//   4. an A -> B -> A revert case (Unit 2's DINT-16 raised projection
//      frequency for exactly this shape — "menus revert constantly") ends
//      with headline_cache/facets_cache/f_* identical to the A-state, not
//      just "some" state — the subtly-wrong-diff-gate failure mode the plan
//      flags as the hardest to notice.
//
// BLOCKER, resolved the documented way: ProjectionWriter reads
// site.platform_connections (accountRef()) and site.sites (bumpSite()), so
// this file needs local DDL for canonical tenant tables and trips
// tests/Feature/Architecture/NoLocalCanonicalTableDdlTest.php. Baseline
// regenerated via NO_LOCAL_DDL_BASELINE=1 php artisan test
// --filter=NoLocalCanonicalTableDdlTest — see scripts/launch-check/
// no-local-canonical-ddl-baseline.json. The shared setup*Table() helpers in
// tests/Pest.php emit SQLite-flavoured DDL (see tests/PostgresTestCase.php's
// own docblock), so they cannot be reused here; ingest.record_versions is
// intentionally NOT hash-partitioned like real prod (Unit 2's concern, not
// this unit's) — a single flat table is sufficient to exercise ordering and
// the rs.key tiebreak under real Postgres.

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
        resource_id text
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
    $pg->statement('CREATE UNIQUE INDEX idx_pwbt_rv_content ON ingest.record_versions (stream_id, key, doc_hash)');
    $pg->statement('CREATE INDEX idx_pwbt_rv_current ON ingest.record_versions (stream_id, key) WHERE is_current');

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
        CONSTRAINT pwbt_source_items_coord_unique UNIQUE (source_id, coord)
    )');
    $pg->statement('CREATE INDEX idx_pwbt_source_items_item ON content.source_items (item_id)');

    $pg->statement('CREATE TABLE content.identity_keys (
        id bigserial PRIMARY KEY,
        source_item_id uuid NOT NULL REFERENCES content.source_items(id) ON DELETE CASCADE,
        key_class text NOT NULL,
        key_value text NOT NULL,
        tier text NOT NULL CHECK (tier IN (\'joining\',\'corroborating\',\'evidential\')),
        created_at timestamptz NOT NULL DEFAULT now()
    )');
    $pg->statement('CREATE INDEX idx_pwbt_identity_keys_source_item ON content.identity_keys (source_item_id)');

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
        CONSTRAINT pwbt_identity_candidates_pair UNIQUE (user_id, left_item_id, right_item_id)
    )');

    $pg->statement('CREATE TABLE content.manual_overrides (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        facet text NOT NULL,
        column_name text NOT NULL,
        value jsonb,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT pwbt_manual_overrides_unique UNIQUE (item_id, facet, column_name)
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
        'f_catalog' => 'release_type text, track_number integer, disc_number integer, isrc text, gtin text, sku text, handle text, vendor text, variant_ref text',
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
        created_at timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT pwbt_media_assets_fingerprint_unique UNIQUE (user_id, fingerprint)
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
    $pg->statement('CREATE INDEX idx_pwbt_item_media_item ON content.item_media (item_id, role, position)');

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
    $pg->statement('CREATE INDEX idx_pwbt_offers_item ON content.offers (item_id)');

    // Shape tracks supabase/migrations/20260727140000_content_schema.sql:404 —
    // ProjectionWriter writes and deletes this table (label is NOT NULL there)
    // — plus image_url from 20260813100003 (slice 5a Task 8 fix round 2, D1).
    $pg->statement('CREATE TABLE content.item_variants (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        source_id uuid NOT NULL REFERENCES content.sources(id) ON DELETE CASCADE,
        label text NOT NULL,
        sku text,
        image_url text,
        position integer NOT NULL DEFAULT 0
    )');
    $pg->statement('CREATE INDEX idx_pwbt_item_variants_item ON content.item_variants (item_id)');

    $pg->statement('CREATE TABLE content.item_tags (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        source_id uuid NOT NULL REFERENCES content.sources(id) ON DELETE CASCADE,
        tag text NOT NULL,
        tag_type text
    )');
    $pg->statement('CREATE INDEX idx_pwbt_item_tags_item ON content.item_tags (item_id)');

    $pg->statement('CREATE TABLE content.f_action (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        source_id uuid NOT NULL REFERENCES content.sources(id) ON DELETE CASCADE,
        intent text NOT NULL,
        label text,
        url text NOT NULL,
        position integer NOT NULL DEFAULT 0
    )');
    $pg->statement('CREATE INDEX idx_pwbt_f_action_item ON content.f_action (item_id)');

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
    $pg->statement('CREATE UNIQUE INDEX idx_pwbt_collections_natural_key
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

/** A user + a bandcamp platform connection with a known, fixed resource_id, its site, and a "releases" stream. */
function pwbtFixture(string $resourceIdSuffix): array
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

    return [$userId, $resourceId, $source, $streamId];
}

/**
 * Content-addressed landing (mirrors Lander::land()'s post-DINT-16 shape,
 * simplified): a doc identical to one already landed for this key does NOT
 * insert a new row (the unique index on stream_id/key/doc_hash forbids it) —
 * it demotes every other version and re-promotes the existing one, which is
 * exactly what makes an A -> B -> A revert exercise a REAL row transition
 * instead of erroring on a duplicate insert.
 */
function pwbtLand(string $streamId, string $key, array $doc, ?string $firstSeenAt = null): void
{
    $pg = DB::connection('pgsql');
    $firstSeenAt ??= now()->toDateTimeString();
    $docHash = sha1(json_encode($doc));

    $existingId = $pg->table('ingest.record_versions')
        ->where('stream_id', $streamId)->where('key', $key)->where('doc_hash', $docHash)
        ->value('id');

    $pg->table('ingest.record_versions')->where('stream_id', $streamId)->where('key', $key)->update(['is_current' => false]);

    if ($existingId !== null) {
        $pg->table('ingest.record_versions')->where('id', $existingId)->update(['is_current' => true]);
        $id = $existingId;
    } else {
        $id = $pg->table('ingest.record_versions')->insertGetId([
            'stream_id' => $streamId, 'key' => $key, 'doc_hash' => $docHash,
            'doc' => json_encode($doc), 'first_seen_at' => $firstSeenAt, 'is_current' => true,
        ]);
    }

    $pg->table('ingest.record_state')->updateOrInsert(
        ['stream_id' => $streamId, 'key' => $key],
        ['current_version_id' => $id, 'last_seen_at' => now()]
    );
}

/**
 * `art_url` is populated by DEFAULT, and that default is load-bearing.
 *
 * BandcampReleaseProjector.php:44 emits an EMPTY `media` array when art_url is
 * null, so with it null nothing in this file writes a single content.item_media
 * or content.media_assets row — and the whole SCALE-17 media path (merged in
 * 3f24fdf7) goes unexercised on real Postgres, where all its interesting
 * behaviour lives:
 *   - insertOrIgnore compiles to ON CONFLICT DO NOTHING here vs INSERT OR
 *     IGNORE on SQLite, against UNIQUE (user_id, fingerprint);
 *   - the multi-row content.item_media insert and the chunked
 *     `item_id IN (...) AND source_id = ?` DELETE run at real bind counts;
 *   - that DELETE-then-INSERT sits inside DB::transaction, so its lock
 *     behaviour is only observable against a real server.
 * The file previously hardcoded null on all four tests and so reported
 * coverage it did not have.
 *
 * The default derives one distinct image per URL. Pass $artUrl explicitly to
 * make several items share ONE image, which is what exercises the fingerprint
 * dedupe rather than the mint path.
 */
function pwbtDoc(string $title, string $url, ?string $artUrl = null): array
{
    $artUrl ??= $url.'/art.jpg';

    return ['title' => $title, 'url' => $url, 'artist' => 'Some Artist', 'release_date' => '2025-05-05', 'art_url' => $artUrl, 'type' => 'album'];
}

it('runs the batched SCALE-6/7/8 queries as valid Postgres against real indexes at N=200', function () {
    [$userId, , $source, $streamId] = pwbtFixture('1');

    for ($i = 0; $i < 200; $i++) {
        pwbtLand($streamId, "item-{$i}", pwbtDoc("Item {$i}", "https://n200.bandcamp.com/album/item-{$i}"));
    }

    $result = app(ProjectionWriter::class)->projectStream($source, $streamId, 'releases');

    expect($result['status'])->toBe('ok')
        ->and($result['projected'])->toBe(200)
        ->and($result['items'])->toBe(200)
        ->and(DB::connection('pgsql')->table('content.items')->where('user_id', $userId)->count())->toBe(200)
        ->and(DB::connection('pgsql')->table('content.f_text')->count())->toBe(200);

    // ── the SCALE-17 media path, at scale ─────────────────────────────────
    $pg = DB::connection('pgsql');

    // Each doc carries its own art_url, so every item mints its own asset:
    // 200 covers, 200 distinct assets, none orphaned.
    expect($pg->table('content.item_media')->count())->toBe(200)
        ->and($pg->table('content.media_assets')->where('user_id', $userId)->count())->toBe(200)
        ->and($pg->table('content.item_media')->whereNull('asset_id')->count())->toBe(0)
        ->and($pg->table('content.item_media')->where('role', 'cover')->count())->toBe(200)
        ->and($pg->table('content.item_media')->distinct()->count('asset_id'))->toBe(200);

    // position is assigned from the per-ITEM list index (ProjectionWriter.php
    // :661-664), so a lone cover is always 0. A counter running across the
    // BATCH instead — the regression that comment exists to warn about — would
    // make this 199, and nothing else in the suite would notice.
    expect((int) $pg->table('content.item_media')->max('position'))->toBe(0);

    // Re-project: this is the only place the widened
    // `DELETE ... WHERE item_id IN (chunk) AND source_id = ?` runs with rows
    // actually present to delete. If the delete under-matched, media would
    // double to 400; if it over-matched, assets would be orphaned.
    app(ProjectionWriter::class)->projectStream($source, $streamId, 'releases');

    expect($pg->table('content.item_media')->count())->toBe(200)
        ->and($pg->table('content.item_media')->whereNull('asset_id')->count())->toBe(0)
        // Assets are content-addressed by fingerprint, so a re-run must reuse
        // them, not mint a second set — that is ON CONFLICT DO NOTHING doing
        // its job on Postgres.
        ->and($pg->table('content.media_assets')->where('user_id', $userId)->count())->toBe(200);
});

it('mints ONE media_asset for 200 items sharing an image — insertOrIgnore as ON CONFLICT DO NOTHING', function () {
    [$userId, , $source, $streamId] = pwbtFixture('5');

    // Every release reuses the same cover art. resolveMediaAssets() dedupes by
    // fingerprint in PHP first, then leans on UNIQUE (user_id, fingerprint) —
    // which is the constraint whose violation-handling differs by driver, and
    // therefore the thing a SQLite lane cannot prove.
    $sharedArt = 'https://shared.bandcamp.com/art/one.jpg';
    for ($i = 0; $i < 200; $i++) {
        pwbtLand($streamId, "shared-{$i}", pwbtDoc("Shared {$i}", "https://shared.bandcamp.com/album/item-{$i}", $sharedArt));
    }

    $result = app(ProjectionWriter::class)->projectStream($source, $streamId, 'releases');

    $pg = DB::connection('pgsql');
    $assetIds = $pg->table('content.item_media')->distinct()->pluck('asset_id');

    expect($result['items'])->toBe(200)
        ->and($pg->table('content.item_media')->count())->toBe(200)
        ->and($pg->table('content.media_assets')->where('user_id', $userId)->count())->toBe(1)
        ->and($assetIds)->toHaveCount(1)
        ->and($assetIds->first())->not->toBeNull();

    // The minimised source_url is stored alongside the fingerprint and the two
    // must derive from the same string (#PRIV-5) — assert the row we actually
    // landed, not just that a row exists.
    expect($pg->table('content.media_assets')->where('user_id', $userId)->value('source_url'))->toBe($sharedArt);
});

it('issues zero narrow content.items updates on a steady-state (second, unchanged) run — the jsonb parity trap', function () {
    [, , $source, $streamId] = pwbtFixture('2');

    for ($i = 0; $i < 30; $i++) {
        pwbtLand($streamId, "item-{$i}", pwbtDoc("Steady {$i}", "https://parity.bandcamp.com/album/item-{$i}"));
    }

    $writer = app(ProjectionWriter::class);
    $writer->projectStream($source, $streamId, 'releases'); // establish

    $narrowUpdates = 0;
    DB::listen(function ($query) use (&$narrowUpdates) {
        // The narrow, diff-gated UPDATE names headline_cache explicitly; the
        // unconditional whole-batch last_seen_at bump does not — only the
        // former should disappear on an unchanged re-run.
        if (str_contains($query->sql, 'update "content"."items"') && str_contains($query->sql, 'headline_cache')) {
            $narrowUpdates++;
        }
    });

    $writer->projectStream($source, $streamId, 'releases'); // steady state, nothing changed

    // If facets_cache were compared as a raw string against Postgres's
    // re-serialised jsonb, this would ALSO be 0 for the wrong reason (every
    // run "changes" and every run also silently no-ops some other way) —
    // the real proof is the companion revert test below, which forces a
    // real content difference and confirms the gate actually discriminates.
    expect($narrowUpdates)->toBe(0);
});

it('pages 600 records sharing a first_seen_at under lazy(500) without skipping or duplicating (the rs.key tiebreak)', function () {
    [$userId, , $source, $streamId] = pwbtFixture('3');

    // Many records land in the SAME instant — a real, common shape (one
    // ingest run stamps first_seen_at at insert time for every new key).
    // Without the rs.key tiebreak, LIMIT/OFFSET paging over ORDER BY
    // first_seen_at alone is free to reorder rows across that boundary on
    // each page fetch, skipping some and repeating others.
    $sharedTimestamp = now()->toDateTimeString();
    for ($i = 0; $i < 550; $i++) {
        pwbtLand($streamId, "same-ts-{$i}", pwbtDoc("Same TS {$i}", "https://p600.bandcamp.com/album/same-ts-{$i}"), $sharedTimestamp);
    }
    // A second batch with a later timestamp, straddling a page boundary.
    for ($i = 0; $i < 50; $i++) {
        pwbtLand($streamId, "later-{$i}", pwbtDoc("Later {$i}", "https://p600.bandcamp.com/album/later-{$i}"), now()->addMinute()->toDateTimeString());
    }

    $result = app(ProjectionWriter::class)->projectStream($source, $streamId, 'releases');

    expect($result['projected'])->toBe(600)
        ->and($result['items'])->toBe(600);

    // Every one of the 600 distinct record_keys must have exactly one
    // content.source_items row with a non-null item_id — none skipped,
    // none double-projected into a stray extra row.
    $sourceItemCount = DB::connection('pgsql')->table('content.source_items as si')
        ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
        ->where('cs.user_id', $userId)
        ->count();
    $distinctItemCount = DB::connection('pgsql')->table('content.source_items as si')
        ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
        ->where('cs.user_id', $userId)
        ->distinct()
        ->count('si.item_id');

    expect($sourceItemCount)->toBe(600)
        ->and($distinctItemCount)->toBe(600); // every doc has a distinct URL, so 600 distinct items
});

it('an A -> B -> A revert ends with headline_cache/facets_cache/f_* identical to the A-state', function () {
    [, , $source, $streamId] = pwbtFixture('4');

    $writer = app(ProjectionWriter::class);
    $url = 'https://revert.bandcamp.com/album/one';

    pwbtLand($streamId, 'revert-key', pwbtDoc('State A', $url));
    $writer->projectStream($source, $streamId, 'releases');

    $itemId = DB::connection('pgsql')->table('content.items')->value('id');
    $stateA = [
        'headline_cache' => DB::connection('pgsql')->table('content.items')->where('id', $itemId)->value('headline_cache'),
        'facets_cache' => json_decode((string) DB::connection('pgsql')->table('content.items')->where('id', $itemId)->value('facets_cache'), true),
        'f_link_url' => DB::connection('pgsql')->table('content.f_link')->where('item_id', $itemId)->value('url'),
        'f_text_headline' => DB::connection('pgsql')->table('content.f_text')->where('item_id', $itemId)->value('headline'),
        'f_authored_creator' => DB::connection('pgsql')->table('content.f_authored')->where('item_id', $itemId)->value('creator'),
    ];

    // B: the doc changes (a price/caption edit) — same record_key, same URL,
    // new content_hash, so a NEW record_versions row lands and is_current
    // moves onto it (mirrors Lander::land()'s post-DINT-16 behaviour).
    pwbtLand($streamId, 'revert-key', pwbtDoc('State B', $url));
    $writer->projectStream($source, $streamId, 'releases');
    expect(DB::connection('pgsql')->table('content.items')->where('id', $itemId)->value('headline_cache'))->toBe('State B');

    // A again: DINT-16 means this now reports changed=1 and re-projects
    // (previously it silently reported changed=0 and never got here at all).
    pwbtLand($streamId, 'revert-key', pwbtDoc('State A', $url));
    $writer->projectStream($source, $streamId, 'releases');

    $stateAfterRevert = [
        'headline_cache' => DB::connection('pgsql')->table('content.items')->where('id', $itemId)->value('headline_cache'),
        'facets_cache' => json_decode((string) DB::connection('pgsql')->table('content.items')->where('id', $itemId)->value('facets_cache'), true),
        'f_link_url' => DB::connection('pgsql')->table('content.f_link')->where('item_id', $itemId)->value('url'),
        'f_text_headline' => DB::connection('pgsql')->table('content.f_text')->where('item_id', $itemId)->value('headline'),
        'f_authored_creator' => DB::connection('pgsql')->table('content.f_authored')->where('item_id', $itemId)->value('creator'),
    ];

    expect($stateAfterRevert)->toBe($stateA);
});
