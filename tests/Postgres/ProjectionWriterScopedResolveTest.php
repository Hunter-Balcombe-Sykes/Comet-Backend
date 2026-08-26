<?php

// The executable form of plan 2026-08-25 (projectionwriter-identity-scope) §A.1's proof: resolving
// only the touched coords' connected component must produce the SAME coord => item grouping as
// resolving the user's whole (user, kind) catalogue. If this ever goes red on the mapping
// comparison, that is the proof breaking — a real defect in IdentityScope/the closure rule, not an
// assertion to relax. See §A.1 in the plan for the four-point proof this test exercises end to end.
//
// PG lane, not Feature: resolveItemsLocked() runs under pg_advisory_xact_lock(hashtext(...)),
// which SQLite has neither of, so the Feature lane cannot reach the code path under test at all —
// a green `composer test` says nothing here.
//
// ONE fixture (pwsrScenario()) is built to stress all FOUR shapes §A.1 names as load-bearing:
//   1. a singleton that shares no signature with anything ("Totally Unique Solo Song"),
//   2. a cross-source pair joined by a JOINING key (a shared Isrc, Spotify <-> Apple),
//   3. a same-source duplicate title that POISONS a corroborating key (Apple lists "Wandering Star
//      Song" twice, so neither copy may merge with Spotify's one copy of the same title), and
//   4. a chain reachable only by a transitive, multi-hop walk (Spotify -isrc-> Apple -title->
//      SoundCloud — two DIFFERENT signatures, so a one-hop closure would strand the third coord).
//
// Only ONE stream (Spotify's) is ever projected by ProjectionWriter in this file. The Apple and
// SoundCloud sides of shapes 2-4 are pre-existing catalogue state, seeded directly into
// content.source_items/content.identity_keys/content.item_anchors (pwsrMintExisting()) rather than
// landed and projected — CLAUDE.md's "never hand-type an upstream payload" is about faking a
// vendor's wire shape; this is the identity system's OWN storage shape, exactly the way
// ProjectionWriterIdentityRaceTest's pgirScenario() builds its pre-existing row.
//
// DDL: local, hand-written, real-Postgres shape (see ProjectionWriterBatchingTest.php's header for
// why this lane cannot reuse tests/Pest.php's SQLite-flavoured setup*Table() helpers). Content.*
// mirrors ProjectionWriterIdentityRaceTest.php's set (including item_links/item_slugs/section_items
// — every merge in this file's fixture reaches mergeInto(), which touches all three) plus the
// ingest.* tables ProjectionWriterBatchingTest.php provisions for projectStream() itself to read
// from. Every local identifier is pwsr_-prefixed so this file's Pest globals and SQL identifiers
// cannot collide with either sibling file's.

use App\Content\Identity\Decision;
use App\Content\Identity\IdentityKey;
use App\Content\Identity\IdentityScope;
use App\Content\Identity\KeyClass;
use App\Content\Identity\SourceItem;
use App\Ingest\Projection\ProjectionWriter;
use Illuminate\Support\Facades\DB;
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
        'ingest.record_state', 'ingest.record_versions', 'ingest.streams', 'ingest.sources',
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

    // mergeInto()'s curation check. section_id carries no FK here — nothing in this file creates a
    // section, and the EXISTS() under test filters on item_id alone.
    $pg->statement("CREATE TABLE site.section_items (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        section_id uuid,
        item_id uuid NOT NULL,
        state text NOT NULL DEFAULT 'pinned' CHECK (state IN ('pinned', 'excluded')),
        sort_key double precision,
        created_at timestamptz NOT NULL DEFAULT now()
    )");

    // ── ingest.* — flat (not hash-partitioned; matches ProjectionWriterBatchingTest.php). ────────
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
    $pg->statement('CREATE UNIQUE INDEX idx_pwsr_rv_content ON ingest.record_versions (stream_id, key, doc_hash)');
    $pg->statement('CREATE INDEX idx_pwsr_rv_current ON ingest.record_versions (stream_id, key) WHERE is_current');

    $pg->statement('CREATE TABLE ingest.record_state (
        stream_id uuid NOT NULL,
        key text NOT NULL,
        current_version_id bigint,
        last_seen_at timestamptz NOT NULL DEFAULT now(),
        tombstoned_at timestamptz,
        PRIMARY KEY (stream_id, key)
    )');

    // ── content.* — real shape, per supabase/migrations/20260727140000. ────────────────────────
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
        first_seen_at timestamptz NOT NULL DEFAULT now(),
        last_seen_at timestamptz NOT NULL DEFAULT now(),
        removed_at timestamptz,
        CONSTRAINT pwsr_source_items_coord_unique UNIQUE (source_id, coord)
    )');
    $pg->statement('CREATE INDEX idx_pwsr_source_items_item ON content.source_items (item_id)');

    $pg->statement('CREATE TABLE content.identity_keys (
        id bigserial PRIMARY KEY,
        source_item_id uuid NOT NULL REFERENCES content.source_items(id) ON DELETE CASCADE,
        key_class text NOT NULL,
        key_value text NOT NULL,
        tier text NOT NULL CHECK (tier IN (\'joining\',\'corroborating\',\'evidential\')),
        created_at timestamptz NOT NULL DEFAULT now()
    )');
    $pg->statement('CREATE INDEX idx_pwsr_identity_keys_source_item ON content.identity_keys (source_item_id)');

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

    // mergeInto() -> moveLinks(). Every merge in this file's fixture reaches mergeInto().
    $pg->statement('CREATE TABLE content.item_links (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        platform text NOT NULL,
        url text NOT NULL,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT pwsr_item_links_unique UNIQUE (item_id, platform)
    )');

    // mergeInto() -> moveSlugs(); also minted by ContentItemSlugAllocator::ensureCurrent()
    // (refreshItemCaches(), best-effort).
    $pg->statement('CREATE TABLE content.item_slugs (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        slug text NOT NULL,
        is_current boolean NOT NULL DEFAULT true,
        retired_at timestamptz,
        created_at timestamptz NOT NULL DEFAULT now()
    )');
    $pg->statement('CREATE UNIQUE INDEX idx_pwsr_item_slugs_unique ON content.item_slugs (user_id, slug)');

    $pg->statement('CREATE TABLE content.identity_candidates (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
        left_item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        right_item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        score integer NOT NULL,
        evidence jsonb NOT NULL DEFAULT \'{}\'::jsonb,
        dismissed_at timestamptz,
        created_at timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT pwsr_identity_candidates_pair UNIQUE (user_id, left_item_id, right_item_id)
    )');

    $pg->statement('CREATE TABLE content.manual_overrides (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        facet text NOT NULL,
        column_name text NOT NULL,
        value jsonb,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT pwsr_manual_overrides_unique UNIQUE (item_id, facet, column_name)
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
        CONSTRAINT pwsr_media_assets_fingerprint_unique UNIQUE (user_id, fingerprint)
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
    $pg->statement('CREATE UNIQUE INDEX idx_pwsr_collections_natural_key
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
        'ingest.record_state', 'ingest.record_versions', 'ingest.streams', 'ingest.sources',
        'site.site_build_state', 'site.sites', 'site.platform_connections', 'core.users',
    ] as $t) {
        $pg->statement("DROP TABLE IF EXISTS {$t} CASCADE");
    }
});

// ── fixed identity-key values and coords, shared across the fixture and its assertions ─────────

const PWSR_ISRC_PAIR = 'USRC00000123';

const PWSR_ISRC_CHAIN = 'USRC00000999';

const PWSR_WANDERING_TITLE = 'Wandering Star Song';

const PWSR_CHAIN_MIDDLE_TITLE = 'Chain Middle Beta';

/** Matches accountRef()'s acct-[0-9a-f]{16} passthrough, so the Spotify coord is deterministic. */
const PWSR_SPOTIFY_ACCT = 'acct-1111111111111111';

const PWSR_APPLE_ISRC_PAIR_COORD = 'apple_music:acct-existing:isrcpair-b';

const PWSR_APPLE_WANDERING_A_COORD = 'apple_music:acct-existing:wandering-a';

const PWSR_APPLE_WANDERING_B_COORD = 'apple_music:acct-existing:wandering-b';

const PWSR_APPLE_CHAIN_B_COORD = 'apple_music:acct-existing:chain-b';

const PWSR_SOUNDCLOUD_CHAIN_C_COORD = 'soundcloud:acct-existing:chain-c';

// Guard-review addition: a coord genuinely UNREACHABLE from every touched/seed
// coord above — no shared signature, no 'same' ruling naming it, and (via
// pwsrMintExisting()) an item_id that is NOT NULL, so seed source 3 (unbound
// rows, §A.4) does not sweep it in either. Without this coord the fixture's
// touched component IS the whole live kind (all 9 other coords are 1-2 hops
// from a touched Spotify coord), so "scoped == whole-kind" compared the same
// computation to itself. With it, the two passes are genuinely different
// computations, and the differential test below asserts the isolated coord is
// excluded from IdentityScope::component()'s output directly, not merely
// inferred from the final mapping (which is identical either way regardless
// of component membership, since bindGroup() is a no-op for an
// already-anchored singleton).
const PWSR_APPLE_ISOLATED_COORD = 'apple_music:acct-existing:isolated';

const PWSR_ISOLATED_TITLE = 'Completely Isolated Preexisting Track';

/** The coord projectStream() will build for one of the Spotify docs below. */
function pwsrSpotifyCoord(string $key): string
{
    return 'spotify:'.PWSR_SPOTIFY_ACCT.':'.$key;
}

/** @return array<string, array<string, mixed>> record key => doc, MusicTrackProjector's shape (title/url required). */
function pwsrSpotifyDocs(): array
{
    return [
        // Shape 1: a singleton — shares no signature with anything else in the fixture.
        'singleton' => ['title' => 'Totally Unique Solo Song', 'url' => 'https://spotify.example.test/singleton'],
        // Shape 2: half of a cross-source pair, joined to an Apple coord by a shared Isrc.
        'isrcpair' => ['title' => 'Isrc Paired Track', 'url' => 'https://spotify.example.test/isrcpair', 'isrc' => PWSR_ISRC_PAIR],
        // Shape 3: shares a title with TWO Apple coords, which poisons that title signature.
        'wandering' => ['title' => PWSR_WANDERING_TITLE, 'url' => 'https://spotify.example.test/wandering'],
        // Shape 4: the head of a 3-hop chain (isrc -> Apple, Apple's title -> SoundCloud).
        'chaina' => ['title' => 'Chain Head Alpha Track', 'url' => 'https://spotify.example.test/chaina', 'isrc' => PWSR_ISRC_CHAIN],
    ];
}

function pwsrLand(string $streamId, string $key, array $doc): void
{
    $pg = DB::connection('pgsql');
    $pg->table('ingest.record_versions')->insert([
        'stream_id' => $streamId, 'key' => $key, 'doc_hash' => sha1(json_encode($doc)),
        'doc' => json_encode($doc), 'first_seen_at' => now(), 'is_current' => true,
    ]);
    $versionId = $pg->table('ingest.record_versions')->where('stream_id', $streamId)->where('key', $key)->value('id');
    $pg->table('ingest.record_state')->insert([
        'stream_id' => $streamId, 'key' => $key, 'current_version_id' => $versionId, 'last_seen_at' => now(),
    ]);
}

/**
 * One pre-existing, already-resolved-and-anchored source item — the steady-state shape a real
 * catalogue is in before a new run touches it. Anchoring it (rather than leaving item_id NULL) is
 * deliberate: an unbound coord is IdentityScope's third seed source (plan §A.4) and would be swept
 * into the component regardless of the closure walk, which would make the differential test pass
 * for the wrong reason — see the "unbound rows sweep everything in" trap in the module docblock.
 *
 * @param  list<array{0: KeyClass, 1: string}>  $keys  [class, RAW value] pairs
 */
function pwsrMintExisting(string $userId, string $sourceId, string $coord, string $boundAt, array $keys): void
{
    $pg = DB::connection('pgsql');

    $itemId = (string) Str::uuid();
    $pg->table('content.items')->insert([
        'id' => $itemId, 'user_id' => $userId, 'kind' => 'track',
        'first_seen_at' => $boundAt, 'last_seen_at' => $boundAt,
        'created_at' => $boundAt, 'updated_at' => $boundAt,
    ]);

    $sourceItemId = (string) Str::uuid();
    $pg->table('content.source_items')->insert([
        'id' => $sourceItemId, 'source_id' => $sourceId, 'coord' => $coord,
        'item_id' => $itemId, 'kind' => 'track', 'projector_version' => 1,
        'first_seen_at' => $boundAt, 'last_seen_at' => $boundAt,
    ]);

    foreach ($keys as [$class, $rawValue]) {
        $pg->table('content.identity_keys')->insert([
            'source_item_id' => $sourceItemId,
            'key_class' => $class->value,
            'key_value' => $class->canonicalise($rawValue),
            'tier' => $class->tier()->value,
            'created_at' => $boundAt,
        ]);
    }

    $pg->table('content.item_anchors')->insert([
        'coord' => $coord, 'user_id' => $userId, 'item_id' => $itemId, 'bound_at' => $boundAt,
    ]);
}

/**
 * The Apple + SoundCloud side of shapes 2-4, rebuildable on demand so the differential test can
 * wipe and re-seed identical starting state for its second (scoped) pass.
 */
function pwsrSeedPreexisting(string $userId): void
{
    $pg = DB::connection('pgsql');

    $appleConnId = (string) Str::uuid();
    $pg->table('site.platform_connections')->insert(['id' => $appleConnId, 'user_id' => $userId, 'resource_id' => 'acct-2222222222222222']);
    $appleSourceId = (string) Str::uuid();
    $pg->table('content.sources')->insert([
        'id' => $appleSourceId, 'user_id' => $userId, 'kind' => 'connection',
        'connection_id' => $appleConnId, 'label' => 'apple_music', 'priority' => 100,
    ]);

    $scConnId = (string) Str::uuid();
    $pg->table('site.platform_connections')->insert(['id' => $scConnId, 'user_id' => $userId, 'resource_id' => 'acct-3333333333333333']);
    $scSourceId = (string) Str::uuid();
    $pg->table('content.sources')->insert([
        'id' => $scSourceId, 'user_id' => $userId, 'kind' => 'connection',
        'connection_id' => $scConnId, 'label' => 'soundcloud', 'priority' => 100,
    ]);

    $now = now();

    // Shape 2's Apple half: shares PWSR_ISRC_PAIR (joining) with Spotify's 'isrcpair' record.
    pwsrMintExisting($userId, $appleSourceId, PWSR_APPLE_ISRC_PAIR_COORD, $now->copy()->subHours(4)->toDateTimeString(), [
        [KeyClass::Isrc, PWSR_ISRC_PAIR],
        [KeyClass::TitleOnly, 'Different Title On Apple'],
    ]);

    // Shape 3: TWO Apple coords carry the SAME title as Spotify's 'wandering' record. Same source +
    // same kind + same signature is exactly Resolver::poisonedKeys()'s trigger — see the dedicated
    // poisoned-key test below for the assertion this earns.
    pwsrMintExisting($userId, $appleSourceId, PWSR_APPLE_WANDERING_A_COORD, $now->copy()->subHours(4)->toDateTimeString(), [
        [KeyClass::TitleOnly, PWSR_WANDERING_TITLE],
    ]);
    pwsrMintExisting($userId, $appleSourceId, PWSR_APPLE_WANDERING_B_COORD, $now->copy()->subHours(4)->toDateTimeString(), [
        [KeyClass::TitleOnly, PWSR_WANDERING_TITLE],
    ]);

    // Shape 4, hop 1: shares PWSR_ISRC_CHAIN with Spotify's 'chaina'. Bound BEFORE the SoundCloud
    // coord below, so it is the oldest anchor in the eventual 3-way group and survives the merge.
    pwsrMintExisting($userId, $appleSourceId, PWSR_APPLE_CHAIN_B_COORD, $now->copy()->subHours(3)->toDateTimeString(), [
        [KeyClass::Isrc, PWSR_ISRC_CHAIN],
        [KeyClass::TitleOnly, PWSR_CHAIN_MIDDLE_TITLE],
    ]);

    // Shape 4, hop 2: shares PWSR_CHAIN_MIDDLE_TITLE with the Apple coord above — a DIFFERENT
    // signature than the isrc that joins Spotify to Apple, and not shared with Spotify directly.
    // Reachable only by continuing the walk past the first hop.
    pwsrMintExisting($userId, $scSourceId, PWSR_SOUNDCLOUD_CHAIN_C_COORD, $now->copy()->subHours(2)->toDateTimeString(), [
        [KeyClass::TitleOnly, PWSR_CHAIN_MIDDLE_TITLE],
    ]);

    // Strict-subset guard (review addition): a coord with no signature and no
    // 'same' ruling connecting it to anything above, so it must stay OUT of
    // the touched Spotify component while still being a live, already-bound
    // ("already-anchored" — see pwsrMintExisting()'s own docblock) coord the
    // whole-kind pass would also see. See the differential test's direct
    // IdentityScope::component() call below for the assertion this exists
    // to make possible.
    pwsrMintExisting($userId, $appleSourceId, PWSR_APPLE_ISOLATED_COORD, $now->copy()->subHours(5)->toDateTimeString(), [
        [KeyClass::TitleOnly, PWSR_ISOLATED_TITLE],
    ]);
}

/**
 * A user + a Spotify connection/ingest source/stream with the four docs above landed, plus the
 * pre-existing Apple/SoundCloud state.
 *
 * @return array{0: string, 1: array<string, mixed>, 2: string, 3: string} [userId, source, streamId, streamName]
 */
function pwsrScenario(): array
{
    $pg = DB::connection('pgsql');

    $userId = (string) Str::uuid();
    $pg->table('core.users')->insert(['id' => $userId]);
    $pg->table('site.sites')->insert(['id' => (string) Str::uuid(), 'user_id' => $userId]);

    $connId = (string) Str::uuid();
    $pg->table('site.platform_connections')->insert(['id' => $connId, 'user_id' => $userId, 'resource_id' => PWSR_SPOTIFY_ACCT]);

    $ingestSourceId = (string) Str::uuid();
    $pg->table('ingest.sources')->insert(['id' => $ingestSourceId, 'user_id' => $userId, 'connection_id' => $connId, 'source_key' => 'spotify']);

    $streamId = (string) Str::uuid();
    $pg->table('ingest.streams')->insert(['id' => $streamId, 'source_id' => $ingestSourceId, 'stream_name' => 'tracks']);

    foreach (pwsrSpotifyDocs() as $key => $doc) {
        pwsrLand($streamId, $key, $doc);
    }

    pwsrSeedPreexisting($userId);

    $source = ['id' => $ingestSourceId, 'source_key' => 'spotify', 'connection_id' => $connId, 'user_id' => $userId, 'identifier' => 'test'];

    return [$userId, $source, $streamId, 'tracks'];
}

/**
 * Wipe this user's whole content-layer state (but NOT the ingest.* records, which the second pass
 * re-reads identically, and not site.platform_connections, which is what keeps accountRef() —
 * and so the Spotify coords — identical across both passes) and rebuild the pre-existing side of
 * the fixture from scratch, so the second (scoped) pass starts from the exact same state the first
 * (whole-kind) pass did.
 */
function pwsrResetProjection(string $userId): void
{
    $pg = DB::connection('pgsql');

    // Cascades content.source_items -> content.identity_keys.
    $pg->table('content.sources')->where('user_id', $userId)->delete();
    // Cascades content.item_anchors, every f_* facet, item_slugs, item_links, item_media, etc.
    $pg->table('content.items')->where('user_id', $userId)->delete();
    // Not FK'd to items/coords — explicit.
    $pg->table('content.identity_decisions')->where('user_id', $userId)->delete();
    $pg->table('content.item_merges')->where('user_id', $userId)->delete();

    pwsrSeedPreexisting($userId);
}

/** coord => item id, across every live source item of this user (any source, any kind). */
function pwsrItemIdByCoord(string $userId): array
{
    return DB::connection('pgsql')->table('content.source_items as si')
        ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
        ->where('cs.user_id', $userId)
        ->whereNull('si.removed_at')
        ->pluck('si.item_id', 'si.coord')
        ->map(fn ($id) => (string) $id)
        ->all();
}

/**
 * Rebuilds the exact SourceItem/Decision inputs resolveItemsLocked() would
 * pass to IdentityScope::component() for this (user, kind) — same joins,
 * same columns — so the differential test can call component() directly and
 * assert what it excludes, rather than inferring exclusion from the final
 * coord => item mapping (which cannot tell "excluded from the component" apart
 * from "included but bindGroup() was a no-op for an already-anchored
 * singleton either way").
 *
 * @return array{0: list<SourceItem>, 1: list<Decision>}
 */
function pwsrResolverInputs(string $userId, string $kind): array
{
    $pg = DB::connection('pgsql');

    $rows = $pg->table('content.source_items as si')
        ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
        ->where('cs.user_id', $userId)
        ->where('si.kind', $kind)
        ->whereNull('si.removed_at')
        ->get(['si.id', 'si.coord', 'si.source_id', 'si.kind', 'si.first_seen_at']);

    $keysBySourceItem = $pg->table('content.identity_keys')
        ->whereIn('source_item_id', $rows->pluck('id'))
        ->get(['source_item_id', 'key_class', 'key_value'])
        ->groupBy('source_item_id');

    $items = $rows->map(function (object $row) use ($keysBySourceItem) {
        $keys = [];
        foreach ($keysBySourceItem->get($row->id, collect()) as $key) {
            $class = KeyClass::tryFrom((string) $key->key_class);
            if ($class !== null) {
                $keys[] = new IdentityKey($class, (string) $key->key_value);
            }
        }

        return new SourceItem(
            coord: (string) $row->coord,
            sourceId: (string) $row->source_id,
            kind: (string) $row->kind,
            keys: $keys,
            firstSeenAt: $row->first_seen_at === null ? null : (string) $row->first_seen_at,
        );
    })->all();

    $decisions = $pg->table('content.identity_decisions')
        ->where('user_id', $userId)
        ->get(['left_coord', 'right_coord', 'verdict'])
        ->map(fn (object $d) => new Decision((string) $d->left_coord, (string) $d->right_coord, (string) $d->verdict))
        ->all();

    return [$items, $decisions];
}

/**
 * Item ids are freshly minted UUIDs on every pass, so the only comparable thing is the SHAPE: which
 * coords ended up sharing an item. Returns a sorted list of sorted coord groups.
 *
 * @param  array<string, string>  $mapping  coord => item id
 * @return list<list<string>>
 */
function pwsrGroupShape(array $mapping): array
{
    $byItem = [];
    foreach ($mapping as $coord => $itemId) {
        $byItem[$itemId][] = $coord;
    }

    $groups = array_values($byItem);
    foreach ($groups as &$group) {
        sort($group, SORT_STRING);
    }
    unset($group);

    usort($groups, fn (array $a, array $b) => $a[0] <=> $b[0]);

    return $groups;
}

/**
 * The minimal, standalone fixture for Guard 2 end to end (plan §A.1): Spotify has ONE copy of a
 * title, Apple has TWO. NOT a transitivity case — the poisoning sibling shares Apple's title
 * signature with the touched Spotify coord directly, so it is ONE hop away. What is under test is
 * whether the closure indexed that signature at all (it must, per Guard 2's unfiltered breadth) so
 * that Resolver::poisonedKeys() — which is ALSO unfiltered — can see both Apple copies and refuse
 * to let the pair merge.
 *
 * @return array{0: string, 1: array<string, mixed>, 2: string, 3: string} [userId, source, streamId, streamName]
 */
function pwsrPoisonedTitleFixture(): array
{
    $pg = DB::connection('pgsql');

    $userId = (string) Str::uuid();
    $pg->table('core.users')->insert(['id' => $userId]);
    $pg->table('site.sites')->insert(['id' => (string) Str::uuid(), 'user_id' => $userId]);

    $connId = (string) Str::uuid();
    $pg->table('site.platform_connections')->insert(['id' => $connId, 'user_id' => $userId, 'resource_id' => PWSR_SPOTIFY_ACCT]);
    $ingestSourceId = (string) Str::uuid();
    $pg->table('ingest.sources')->insert(['id' => $ingestSourceId, 'user_id' => $userId, 'connection_id' => $connId, 'source_key' => 'spotify']);
    $streamId = (string) Str::uuid();
    $pg->table('ingest.streams')->insert(['id' => $streamId, 'source_id' => $ingestSourceId, 'stream_name' => 'tracks']);

    pwsrLand($streamId, 'wandering', ['title' => PWSR_WANDERING_TITLE, 'url' => 'https://spotify.example.test/wandering-solo']);

    $appleConnId = (string) Str::uuid();
    $pg->table('site.platform_connections')->insert(['id' => $appleConnId, 'user_id' => $userId, 'resource_id' => 'acct-4444444444444444']);
    $appleSourceId = (string) Str::uuid();
    $pg->table('content.sources')->insert([
        'id' => $appleSourceId, 'user_id' => $userId, 'kind' => 'connection',
        'connection_id' => $appleConnId, 'label' => 'apple_music', 'priority' => 100,
    ]);

    $boundAt = now()->subHours(2)->toDateTimeString();
    // Same source, same kind, same title signature TWICE — Resolver::poisonedKeys()'s trigger.
    pwsrMintExisting($userId, $appleSourceId, PWSR_APPLE_WANDERING_A_COORD, $boundAt, [[KeyClass::TitleOnly, PWSR_WANDERING_TITLE]]);
    pwsrMintExisting($userId, $appleSourceId, PWSR_APPLE_WANDERING_B_COORD, $boundAt, [[KeyClass::TitleOnly, PWSR_WANDERING_TITLE]]);

    $source = ['id' => $ingestSourceId, 'source_key' => 'spotify', 'connection_id' => $connId, 'user_id' => $userId, 'identifier' => 'test'];

    return [$userId, $source, $streamId, 'tracks'];
}

it('produces the same item mapping scoped as it does whole-kind', function () {
    [$userId, $source, $streamId, $streamName] = pwsrScenario();

    config(['partna.content.identity_scope' => false]);
    $whole = app(ProjectionWriter::class)->projectStream($source, $streamId, $streamName);
    $wholeMapping = pwsrItemIdByCoord($userId);

    pwsrResetProjection($userId);

    config(['partna.content.identity_scope' => true]);
    $scoped = app(ProjectionWriter::class)->projectStream($source, $streamId, $streamName);
    $scopedMapping = pwsrItemIdByCoord($userId);

    // The exact expected partition, independent of which config produced it: singleton alone;
    // the isrc pair merged; all three "Wandering Star Song" coords kept apart (poisoned); the
    // 3-hop chain merged into one group; the isolated coord alone. If the whole-kind pass does
    // not already match this, the fixture (not the narrowing) is wrong — assert that BEFORE
    // comparing the two passes, so a failure here is never misread as narrowing breaking the
    // proof.
    $expectedShape = pwsrGroupShape([
        pwsrSpotifyCoord('singleton') => 'g1',
        pwsrSpotifyCoord('isrcpair') => 'g2',
        PWSR_APPLE_ISRC_PAIR_COORD => 'g2',
        pwsrSpotifyCoord('wandering') => 'g3',
        PWSR_APPLE_WANDERING_A_COORD => 'g4',
        PWSR_APPLE_WANDERING_B_COORD => 'g5',
        pwsrSpotifyCoord('chaina') => 'g6',
        PWSR_APPLE_CHAIN_B_COORD => 'g6',
        PWSR_SOUNDCLOUD_CHAIN_C_COORD => 'g6',
        PWSR_APPLE_ISOLATED_COORD => 'g7',
    ]);
    expect(pwsrGroupShape($wholeMapping))->toBe($expectedShape, 'the fixture itself does not produce the shape this test assumes — fix the fixture, not the comparison below');

    // §A.1's invariant: the SAME shape scoped as whole-kind. 'projected' is
    // unaffected by scoping either way — it counts records this run actually
    // read off the stream, before identity resolution runs at all.
    expect(pwsrGroupShape($scopedMapping))->toBe(pwsrGroupShape($wholeMapping))
        ->and($scoped['projected'])->toBe($whole['projected']);

    // 'items' is NOT expected to match, and now that the fixture has a coord
    // genuinely outside the touched component (PWSR_APPLE_ISOLATED_COORD),
    // it legitimately does not: it counts distinct items across whatever
    // resolveItems() actually resolved THIS run — the component under
    // scoping, the whole live kind otherwise. Whole-kind touches 7 groups
    // (g1-g7); scoped touches only the 6 the touched component reaches
    // (g1-g6), leaving the isolated coord's g7 alone. Asserting equality here
    // would be asserting away the very difference the narrowing exists to
    // produce.
    expect($whole['items'])->toBe(7)
        ->and($scoped['items'])->toBe(6);

    // The above proves the two passes agree on the FINAL mapping — true for the isolated coord
    // regardless of component membership, since bindGroup() is a no-op for an already-anchored
    // singleton either pass touches it or not. That alone would let a component that wrongly
    // includes everything (i.e. "scoped == whole-kind" by construction, not by the closure rule)
    // pass silently. Verify the closure itself instead: rebuild resolveItemsLocked()'s own inputs
    // from the DB state this run left behind and call IdentityScope::component() directly with
    // the same four touched Spotify coords the scoped pass above used.
    [$items, $decisions] = pwsrResolverInputs($userId, 'track');
    $touched = [
        pwsrSpotifyCoord('singleton'),
        pwsrSpotifyCoord('isrcpair'),
        pwsrSpotifyCoord('wandering'),
        pwsrSpotifyCoord('chaina'),
    ];
    $component = app(IdentityScope::class)->component($items, $decisions, $touched);

    // 10 live coords total (4 touched + 5 pre-existing connected + 1 isolated); the isolated
    // coord must be the ONE excluded — a strict subset, not "scoped == whole-kind by definition."
    expect($component['capped'])->toBeFalse()
        ->and($component['coords'])->not->toContain(PWSR_APPLE_ISOLATED_COORD)
        ->and($component['coords'])->toHaveCount(9)
        // And it is still there in BOTH final mappings, unaffected either way — present, just
        // never a candidate for this run's resolve.
        ->and($wholeMapping)->toHaveKey(PWSR_APPLE_ISOLATED_COORD)
        ->and($scopedMapping)->toHaveKey(PWSR_APPLE_ISOLATED_COORD);
});

it('does not merge a pair whose corroborating key a same-source duplicate poisons', function () {
    [$userId, $source, $streamId, $streamName] = pwsrPoisonedTitleFixture();

    config(['partna.content.identity_scope' => true]);
    app(ProjectionWriter::class)->projectStream($source, $streamId, $streamName);

    $mapping = pwsrItemIdByCoord($userId);
    $spotifyWandering = pwsrSpotifyCoord('wandering');

    expect($mapping[$spotifyWandering])->not->toBe($mapping[PWSR_APPLE_WANDERING_A_COORD])
        ->and($mapping[$spotifyWandering])->not->toBe($mapping[PWSR_APPLE_WANDERING_B_COORD])
        // And the two Apple copies do not merge with EACH OTHER either — they share exactly the
        // same poisoned signature, so nothing ever unions them.
        ->and($mapping[PWSR_APPLE_WANDERING_A_COORD])->not->toBe($mapping[PWSR_APPLE_WANDERING_B_COORD]);
});

it('falls back to a whole-kind resolve and logs when the cap bites', function () {
    [$userId, $source, $streamId, $streamName] = pwsrScenario();

    Log::spy();
    config(['partna.content.identity_scope' => true, 'partna.content.identity_scope_max' => 1]);

    app(ProjectionWriter::class)->projectStream($source, $streamId, $streamName);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => str_contains($message, 'identity scope cap')
            && $context['user_id'] === $userId
            && isset($context['kind'], $context['resolving_count']));
});
