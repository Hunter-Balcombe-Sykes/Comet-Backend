<?php

// The merge fold (spec 2026-08-26 §5.2): when mergeInto() hard-deletes the
// loser, its collection facets must be carried onto the survivor FIRST.
//
// THE MECHANISM. Every facet table foreign-keys content.items(id) ON DELETE
// CASCADE, so the delete takes the loser's whole facet footprint. On the
// CONNECTOR lane that is only a cache invalidation — ReprojectSourcesJob
// replays the projection and rewrites them under the kept id from
// ingest.record_versions. A manual coord has no landed records: writeManualItem()
// wrote its facets once from an HTTP payload persisted nowhere, so there is
// nothing to replay and the cascade is terminal.
//
// WHY THIS LANE. SQLite does not enforce the cascade this is entirely about.
//
// DDL: same local-DDL convention as ProjectionWriterMergeAnchorTest.php, copied
// from its beforeEach — see FacetOriginScopeTest.php's header for the two
// deliberate additions (source_item_id on the four collection tables, and the
// mirror_* columns on content.media_assets).
//
// Identifiers are mff_-prefixed and helpers mff-prefixed so neither collides
// with a sibling PG-lane file's (CrossFileTestHelperGuardTest).

use App\Exceptions\Ingest\MergeFoldMediaDroppedException;
use App\Ingest\Projection\ProjectionWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

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
        CONSTRAINT mff_source_items_coord_unique UNIQUE (source_id, coord)
    )');
    $pg->statement('CREATE INDEX idx_mff_source_items_item ON content.source_items (item_id)');

    $pg->statement('CREATE TABLE content.identity_keys (
        id bigserial PRIMARY KEY,
        source_item_id uuid NOT NULL REFERENCES content.source_items(id) ON DELETE CASCADE,
        key_class text NOT NULL,
        key_value text NOT NULL,
        tier text NOT NULL CHECK (tier IN (\'joining\',\'corroborating\',\'evidential\')),
        created_at timestamptz NOT NULL DEFAULT now()
    )');
    $pg->statement('CREATE INDEX idx_mff_identity_keys_source_item ON content.identity_keys (source_item_id)');

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

    // mergeInto() -> moveLinks().
    $pg->statement('CREATE TABLE content.item_links (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        platform text NOT NULL,
        url text NOT NULL,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT mff_item_links_unique UNIQUE (item_id, platform)
    )');

    // mergeInto() -> moveSlugs().
    $pg->statement('CREATE TABLE content.item_slugs (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        slug text NOT NULL,
        is_current boolean NOT NULL DEFAULT true,
        retired_at timestamptz,
        created_at timestamptz NOT NULL DEFAULT now()
    )');
    $pg->statement('CREATE UNIQUE INDEX idx_mff_item_slugs_unique ON content.item_slugs (user_id, slug)');

    $pg->statement('CREATE TABLE content.identity_candidates (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
        left_item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        right_item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        score integer NOT NULL,
        evidence jsonb NOT NULL DEFAULT \'{}\'::jsonb,
        dismissed_at timestamptz,
        created_at timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT mff_identity_candidates_pair UNIQUE (user_id, left_item_id, right_item_id)
    )');

    $pg->statement('CREATE TABLE content.manual_overrides (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        facet text NOT NULL,
        column_name text NOT NULL,
        value jsonb,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT mff_manual_overrides_unique UNIQUE (item_id, facet, column_name)
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
        mirror_attempts integer NOT NULL DEFAULT 0,
        mirror_last_attempt_at timestamptz,
        mirror_last_reason text,
        created_at timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT mff_media_assets_fingerprint_unique UNIQUE (user_id, fingerprint)
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

    $pg->statement('CREATE TABLE content.item_tags (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        source_id uuid NOT NULL REFERENCES content.sources(id) ON DELETE CASCADE,
        source_item_id uuid REFERENCES content.source_items(id) ON DELETE CASCADE,
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
    $pg->statement('CREATE UNIQUE INDEX idx_mff_collections_natural_key
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

/** A user + its site. See FacetOriginScopeTest::fosTenant() for why not createTenant(). */
function mffTenant(): string
{
    $pg = DB::connection('pgsql');

    $userId = (string) Str::uuid();
    $pg->table('core.users')->insert(['id' => $userId]);
    $pg->table('site.sites')->insert(['id' => (string) Str::uuid(), 'user_id' => $userId]);

    return $userId;
}

/** The shape Projector::project() returns for a hand-added link with photos. */
function mffRelease(string $headline, string $url, array $media = []): array
{
    return [
        'kind' => 'release',
        'headline' => $headline,
        'facets' => ['f_link' => ['url' => $url]],
        'media' => $media,
    ];
}

/** One photo entry. */
function mffPhoto(string $url, string $role = 'cover'): array
{
    return ['role' => $role, 'url' => $url];
}

/**
 * Two hand-added items the owner has ruled the same, ready for a merge.
 *
 * The merge DIRECTION is pinned, not left to chance: both sides are manual, so
 * preferOwnerAnchored() falls through to oldest-binding-wins, and two anchors
 * written in the same test would otherwise share a now() timestamp and break the
 * tie on the coord string — which is a uuid. Back-dating A's anchor makes A the
 * survivor and B the loser every run.
 *
 * @return array{0: string, 1: string, 2: string, 3: string, 4: string} [userId, coordA, coordB, itemA, itemB]
 */
function mffRuledPair(array $mediaA, array $mediaB): array
{
    $userId = mffTenant();
    $writer = app(ProjectionWriter::class);

    $coordA = 'manual:'.Str::uuid();
    $coordB = 'manual:'.Str::uuid();
    $itemA = $writer->writeManualItem($userId, $coordA, mffRelease('A', 'https://e.test/a', $mediaA));
    $itemB = $writer->writeManualItem($userId, $coordB, mffRelease('B', 'https://e.test/b', $mediaB));

    expect($itemA)->not->toBe($itemB);

    DB::table('content.item_anchors')->where('user_id', $userId)->where('coord', $coordA)
        ->update(['bound_at' => now()->subHour()]);

    // A human ruling beats every key, in both directions (C8) — union() is
    // symmetric, so the coord order here does not matter.
    DB::table('content.identity_decisions')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $userId, 'verdict' => 'same',
        'left_coord' => $coordA, 'right_coord' => $coordB,
        'decided_at' => now(), 'decided_by' => 'owner',
    ]);

    return [$userId, $coordA, $coordB, $itemA, $itemB];
}

/**
 * Re-home one item's media onto a CONNECTOR source, coord and all.
 *
 * mffRuledPair() builds BOTH sides with writeManualItem(), so every fixture in
 * this file is manual by default — and the merge-media cap now EXEMPTS manual
 * origins (dropping one is terminal; a connector row comes back on the next
 * reprojection). A cap test built on the default fixture would therefore drop
 * nothing and pass for the wrong reason. This makes the loser look like what
 * the cap is actually for.
 *
 * The coord is left LIVE: mergeInto() repoints the loser's source_items onto
 * the survivor before the fold, which is exactly the state the exemption's
 * source_item_id lookup has to survive.
 */
function mffConnectorOrigin(string $userId, string $itemId): string
{
    $sourceId = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $sourceId, 'user_id' => $userId, 'kind' => 'connection',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $sourceItemId = (string) Str::uuid();
    DB::table('content.source_items')->insert([
        'id' => $sourceItemId, 'source_id' => $sourceId,
        'coord' => 'connector:'.Str::uuid(), 'item_id' => $itemId, 'kind' => 'release',
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    DB::table('content.item_media')->where('item_id', $itemId)->update([
        'source_id' => $sourceId,
        'source_item_id' => $sourceItemId,
    ]);

    return $sourceItemId;
}

it('carries the loser media onto the survivor instead of cascading it away', function () {
    [$userId, $coordA, , $itemA, $itemB] = mffRuledPair(
        [mffPhoto('https://cdn.test/a.jpg')],
        [mffPhoto('https://cdn.test/b.jpg')],
    );

    // Any resolve applies the ruling and merges.
    $kept = app(ProjectionWriter::class)->resolveIdentityFor($userId, 'release', [$coordA])[$coordA];

    expect($kept)->toBe($itemA)
        ->and(DB::table('content.items')->where('id', $itemB)->exists())->toBeFalse()
        ->and(DB::table('content.item_media')->where('item_id', $kept)->count())->toBe(2);
});

it('does not duplicate a photo both sides share', function () {
    [$userId, $coordA, , $itemA] = mffRuledPair(
        [mffPhoto('https://cdn.test/same.jpg')],
        [mffPhoto('https://cdn.test/same.jpg')],
    );

    $kept = app(ProjectionWriter::class)->resolveIdentityFor($userId, 'release', [$coordA])[$coordA];

    // Image FILES are already deduped by content.media_assets' UNIQUE
    // (user_id, fingerprint), so a second row is a second REFERENCE to one
    // asset — it would render the same photo twice.
    expect($kept)->toBe($itemA)
        ->and(DB::table('content.item_media')->where('item_id', $kept)->count())->toBe(1);
});

it('leaves a curated loser its own media, because it is not deleted', function () {
    [$userId, $coordA, , $itemA, $itemB] = mffRuledPair(
        [mffPhoto('https://cdn.test/a.jpg')],
        [mffPhoto('https://cdn.test/b.jpg')],
    );

    // Curation spares the loser from the delete — mergeInto()'s $hasCuration.
    // content.manual_overrides has NO user_id column (it is reached through
    // item_id), and `value` is jsonb — a bare string is a 22P02.
    DB::table('content.manual_overrides')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $itemB,
        'facet' => 'f_text', 'column_name' => 'headline',
        'value' => json_encode('Owner title'),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    app(ProjectionWriter::class)->resolveIdentityFor($userId, 'release', [$coordA]);

    // The spared item is still rendered wherever it is pinned, so stripping its
    // media would be a FRESH data-loss bug on exactly the items the owner cared
    // about most. Nothing is at risk on this branch anyway: the row is not
    // deleted, so nothing cascades.
    expect(DB::table('content.items')->where('id', $itemB)->exists())->toBeTrue()
        ->and(DB::table('content.item_media')->where('item_id', $itemB)->count())->toBe(1)
        ->and(DB::table('content.item_media')->where('item_id', $itemA)->count())->toBe(1);
});

it('caps what a fold adds without ever removing what the survivor already had', function () {
    config(['partna.content.merge_media_cap' => 2]);
    Log::spy();
    // The cap reports as well as logs now; keep the real handler out of a test
    // that is spying on the logger.
    Exceptions::fake();

    [$userId, $coordA, , $itemA, $itemB] = mffRuledPair(
        [
            mffPhoto('https://cdn.test/a1.jpg', 'gallery'),
            mffPhoto('https://cdn.test/a2.jpg', 'gallery'),
            mffPhoto('https://cdn.test/a3.jpg', 'gallery'),
        ],
        [
            mffPhoto('https://cdn.test/b1.jpg', 'gallery'),
            mffPhoto('https://cdn.test/b2.jpg', 'gallery'),
        ],
    );

    // RE-FIXTURED 2026-08-28 (#W1-OBS-2). The cap now exempts manual origins,
    // and mffRuledPair() makes both sides manual — so without this line the
    // fold drops nothing and the assertions below pass while testing nothing.
    // The cap's whole remaining job is connector rows, so the loser is one.
    mffConnectorOrigin($userId, $itemB);

    $kept = app(ProjectionWriter::class)->resolveIdentityFor($userId, 'release', [$coordA])[$coordA];

    // The survivor was ALREADY over the cap with 3. The cap must drop only
    // INCOMING rows — trimming the combined set to 2 would destroy live data to
    // enforce a guard that exists to prevent growth.
    $urls = DB::table('content.item_media as im')
        ->join('content.media_assets as ma', 'ma.id', '=', 'im.asset_id')
        ->where('im.item_id', $kept)->pluck('ma.source_url');

    expect($kept)->toBe($itemA)
        ->and($urls)->toHaveCount(3)
        ->and($urls->filter(fn ($u) => str_contains((string) $u, '/b'))->all())->toBe([]);

    // A silent cap is a defect in this codebase.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []) => $message === 'content.merge_fold.media_capped'
            && $context['item_id'] === $kept
            && $context['dropped'] === 2)
        ->once();
});

it('never stamps a moved row with an origin from another source', function () {
    [$userId, $coordA, , $itemA, $itemB] = mffRuledPair(
        [mffPhoto('https://cdn.test/a.jpg')],
        [mffPhoto('https://cdn.test/b.jpg')],
    );

    // A SECOND source on the loser, whose coord is already retired. Retirement
    // is soft, so its item_media row outlives it — and a pre-backfill row like
    // this one carries no origin.
    $connectorSourceId = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $connectorSourceId, 'user_id' => $userId, 'kind' => 'connection',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $connectorSourceId,
        'coord' => 'gone:'.Str::uuid(), 'item_id' => $itemB, 'kind' => 'release',
        'first_seen_at' => now(), 'last_seen_at' => now(), 'removed_at' => now(),
    ]);
    DB::table('content.item_media')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $itemB,
        'source_id' => $connectorSourceId, 'source_item_id' => null,
        'role' => 'poster', 'position' => 0, 'created_at' => now(),
    ]);

    app(ProjectionWriter::class)->resolveIdentityFor($userId, 'release', [$coordA]);

    // mergeInto() repoints the loser's source_items onto the survivor BEFORE the
    // fold, so by fold time the survivor's live source items span both sources'
    // worth of coords and "any live origin of the survivor" is the wrong answer.
    //
    // Stamping this row with a MANUAL-source origin would make it undeletable
    // forever: replaceCollections() deletes `source_id = ours AND
    // (source_item_id IS NULL OR IN ours-origins)`, and an origin belonging to
    // another source satisfies neither branch. That is the data-DUPLICATION
    // failure the IS NULL half exists to prevent, reintroduced from the other
    // end. The survivor has no LIVE source item on the connector source, so the
    // only safe stamp is none — NULL keeps meaning "replaced as today".
    $orphans = DB::table('content.item_media as im')
        ->join('content.source_items as si', 'si.id', '=', 'im.source_item_id')
        ->whereColumn('si.source_id', '!=', 'im.source_id')
        ->count();

    expect($orphans)->toBe(0)
        ->and(DB::table('content.item_media')->where('item_id', $itemA)->where('role', 'poster')->value('source_item_id'))
        ->toBeNull();
});

it('renumbers moved media so it sorts after the survivor own, per role', function () {
    [$userId, $coordA, , $itemA] = mffRuledPair(
        [mffPhoto('https://cdn.test/a.jpg')],
        [mffPhoto('https://cdn.test/b.jpg')],
    );

    $kept = app(ProjectionWriter::class)->resolveIdentityFor($userId, 'release', [$coordA])[$coordA];
    expect($kept)->toBe($itemA);

    // Both sides wrote their only photo at position 0 (replaceCollections()
    // assigns positions from the projection's list index). Moving one across
    // without renumbering leaves the survivor holding two role='cover' rows both
    // at 0 — legal, because idx (item_id, role, position) is NOT unique, and
    // therefore silent.
    $rows = DB::table('content.item_media as im')
        ->join('content.media_assets as ma', 'ma.id', '=', 'im.asset_id')
        ->where('im.item_id', $kept)
        ->orderBy('im.position')
        ->get(['im.role', 'im.position', 'ma.source_url']);

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('position')->all())->toBe([0, 1])
        // The survivor's own keeps its place; the incomer lands after it.
        ->and((string) $rows->firstWhere('position', 0)->source_url)->toBe('https://cdn.test/a.jpg')
        ->and((string) $rows->firstWhere('position', 1)->source_url)->toBe('https://cdn.test/b.jpg');
});

it('renumbers per role and keeps the moved rows in their original order', function () {
    // Positions come from the projection's FLAT list index, not per role, so each
    // side writes cover@0, gallery@1, gallery@2.
    [$userId, $coordA, , $itemA] = mffRuledPair(
        [
            mffPhoto('https://cdn.test/a-cover.jpg'),
            mffPhoto('https://cdn.test/a-g1.jpg', 'gallery'),
            mffPhoto('https://cdn.test/a-g2.jpg', 'gallery'),
        ],
        [
            mffPhoto('https://cdn.test/b-cover.jpg'),
            mffPhoto('https://cdn.test/b-g1.jpg', 'gallery'),
            mffPhoto('https://cdn.test/b-g2.jpg', 'gallery'),
        ],
    );

    $kept = app(ProjectionWriter::class)->resolveIdentityFor($userId, 'release', [$coordA])[$coordA];
    expect($kept)->toBe($itemA);

    $rows = DB::table('content.item_media as im')
        ->join('content.media_assets as ma', 'ma.id', '=', 'im.asset_id')
        ->where('im.item_id', $kept)
        ->orderBy('im.position')
        ->get(['im.role', 'im.position', 'ma.source_url']);

    expect($rows)->toHaveCount(6);

    // THE INVARIANT: no two rows of the same role share a position. That is what
    // PoolResolver::itemPayloads() needs — it orders content.item_media by
    // position alone, and cover() breaks ties by arrival order, so a collision
    // makes which photo renders planner-dependent.
    $perRole = $rows->groupBy('role')->map(fn ($g) => $g->pluck('position')->all());
    foreach ($perRole as $role => $positions) {
        expect($positions)->toBe(array_values(array_unique($positions)), "role {$role} has a duplicate position");
    }

    // Renumbering is an OFFSET, so the incomer's own order survives intact.
    $moved = $rows->filter(fn ($r) => str_contains((string) $r->source_url, '/b-'))->sortBy('position')->values();
    expect($moved->pluck('source_url')->map(fn ($u) => (string) $u)->all())
        ->toBe(['https://cdn.test/b-cover.jpg', 'https://cdn.test/b-g1.jpg', 'https://cdn.test/b-g2.jpg']);

    // And every moved row sits after the survivor's last row of its own role.
    foreach (['cover', 'gallery'] as $role) {
        $own = $rows->filter(fn ($r) => $r->role === $role && ! str_contains((string) $r->source_url, '/b-'))->max('position');
        $inc = $rows->filter(fn ($r) => $r->role === $role && str_contains((string) $r->source_url, '/b-'))->min('position');
        expect($inc)->toBeGreaterThan($own, "moved {$role} did not land after the survivor's own");
    }
});

// ── #W1-OBS-2: the cap may only drop what a reprojection can put back ────────
//
// The cap was added to bound what ONE fold may ADD, and it does that. What it
// could not tell apart was the two lanes underneath it. A dropped CONNECTOR
// row is a cache invalidation — the next reprojection rewrites it under the
// kept id from ingest.record_versions, through the UNCAPPED replaceCollections()
// path. A dropped MANUAL row is gone: writeManualItem() wrote it once from an
// HTTP payload persisted nowhere, and mergeInto() then hard-deletes the loser.
// So the cap now reads the origin's source kind and exempts manual.

it('never drops a manual-origin photo the cap would otherwise eat', function () {
    // The survivor is ALREADY at double the cap, so every incoming row is one
    // the old code dropped on sight.
    config(['partna.content.merge_media_cap' => 1]);
    Exceptions::fake();

    [$userId, $coordA, , $itemA] = mffRuledPair(
        [
            mffPhoto('https://cdn.test/a1.jpg', 'gallery'),
            mffPhoto('https://cdn.test/a2.jpg', 'gallery'),
        ],
        [
            mffPhoto('https://cdn.test/b1.jpg', 'gallery'),
            mffPhoto('https://cdn.test/b2.jpg', 'gallery'),
        ],
    );

    $kept = app(ProjectionWriter::class)->resolveIdentityFor($userId, 'release', [$coordA])[$coordA];

    $urls = DB::table('content.item_media as im')
        ->join('content.media_assets as ma', 'ma.id', '=', 'im.asset_id')
        ->where('im.item_id', $kept)->pluck('ma.source_url');

    // Both hand-added photos survive. Nothing can put them back: the loser is
    // hard-deleted a few lines later in mergeInto().
    expect($kept)->toBe($itemA)
        ->and($urls)->toHaveCount(4)
        ->and($urls->filter(fn ($u) => str_contains((string) $u, '/b'))->all())->toHaveCount(2);

    // And nothing reported, because nothing was capped. Asserted on the
    // exception rather than on Log::spy: a shouldNotHaveReceived('warning',
    // [...]) with the wrong arity matches nothing and passes vacuously, and
    // report() fires on exactly the same `$mediaDropped > 0` branch as the log
    // line does.
    Exceptions::assertNotReported(MergeFoldMediaDroppedException::class);
});

it('still caps a null-origin row, which is unattributable', function () {
    // A pre-backfill row carries no source_item_id at all, so there is no
    // source to read a kind off. Guessing "manual" would relax the bound on
    // exactly the rows we know least about; today's behaviour is kept.
    config(['partna.content.merge_media_cap' => 1]);
    Exceptions::fake();

    [$userId, $coordA, , $itemA, $itemB] = mffRuledPair(
        [mffPhoto('https://cdn.test/a1.jpg', 'gallery')],
        [mffPhoto('https://cdn.test/b1.jpg', 'gallery')],
    );

    DB::table('content.item_media')->where('item_id', $itemB)->update(['source_item_id' => null]);

    $kept = app(ProjectionWriter::class)->resolveIdentityFor($userId, 'release', [$coordA])[$coordA];

    $urls = DB::table('content.item_media as im')
        ->join('content.media_assets as ma', 'ma.id', '=', 'im.asset_id')
        ->where('im.item_id', $kept)->pluck('ma.source_url');

    expect($kept)->toBe($itemA)
        ->and($urls)->toHaveCount(1)
        ->and($urls->filter(fn ($u) => str_contains((string) $u, '/b'))->all())->toBe([]);
});

it('reports a capped fold, because Log::warning does not reach Nightwatch', function () {
    config(['partna.content.merge_media_cap' => 1]);
    Exceptions::fake();

    [$userId, $coordA, , $itemA, $itemB] = mffRuledPair(
        [mffPhoto('https://cdn.test/a1.jpg', 'gallery')],
        [
            mffPhoto('https://cdn.test/b1.jpg', 'gallery'),
            mffPhoto('https://cdn.test/b2.jpg', 'gallery'),
        ],
    );

    mffConnectorOrigin($userId, $itemB);

    $kept = app(ProjectionWriter::class)->resolveIdentityFor($userId, 'release', [$coordA])[$coordA];

    // The drops are recoverable — that is why they are exempt from nothing and
    // reported rather than thrown. But a SUSTAINED run of them means the cap is
    // mis-set, and nobody greps a log line for that.
    expect($kept)->toBe($itemA);
    Exceptions::assertReported(fn (MergeFoldMediaDroppedException $e) => $e->userId === $userId
        && $e->itemId === $kept
        && $e->dropped === 2);
});
