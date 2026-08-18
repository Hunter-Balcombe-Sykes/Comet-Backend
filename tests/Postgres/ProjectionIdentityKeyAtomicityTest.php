<?php

// #CACHE-3: ProjectionWriter::projectStream() now wraps upsertSourceItem() +
// writeIdentityKeys() in ONE DB::transaction per record (ProjectionWriter.php,
// around line 134). This file proves why that transaction has to exist and
// why it has to cover BOTH calls, not just the second one.
//
// Before the fix there were two windows in which a live, COMMITTED
// content.source_items row was visible carrying ZERO content.identity_keys:
//
//   1. Between the DELETE and the INSERT inside writeIdentityKeys() — every
//      pass unconditionally deletes a source item's key rows and reinserts
//      the current set, even when nothing changed.
//   2. For a first-sight record, between the content.source_items INSERT and
//      that record's first identity-key INSERT — two separate statements,
//      each committing on its own without the wrapping transaction.
//
// Window 2 is the damaging one. resolveItems() (~line 391) reads
// content.identity_keys scoped by user_id + kind, NOT stream_id — the
// cross-source union IS the identity system's whole point, and it is exactly
// what makes window 2 reachable: projecting a second stream for the same
// user, or a concurrent `ingest:project` (which bypasses SourceScheduler's
// claim entirely), reads these very rows mid-rewrite. A keyless source item
// in that read resolves as an unrelated singleton, so createItem() mints a
// spurious content.items row and anchors the coord to it — a duplicate the
// user sees immediately, and one that mergeInto()'s curation check can keep
// alive forever if they curate the loser before the next pass folds it back.
//
// Both tests below use deterministic race injection, not pcntl_fork. Fork is
// the right tool in tests/Postgres/EffectLedgerConcurrencyTest.php only
// because that regression (a real 23505 on a PRIMARY KEY) had no cheaper
// injection seam — the race is IN the constraint check itself. Here the seam
// already exists: Laravel's query-executed event fires AFTER a query's result
// is fixed, so a DB::listen matching one specific statement runs exactly
// inside the gap this fix closes, with zero timing dependence and no forked
// processes to reconnect or synchronise.
//
// The competing read runs on a second, independently resolved Postgres
// connection (config key pgsql_ikprobe, a clone of pgsql's config) — never
// the same connection the writer is using. One PDO handle cannot be both
// mid-transaction inside projectStream() and simultaneously issue a read that
// proves what a DIFFERENT session sees; that is the entire mechanism under
// test. MVCC is what makes the assertions discriminate pre-fix from
// post-fix: with the transaction in place, the probe connection's snapshot
// simply cannot see the writer's uncommitted DELETE or INSERT, so it reads
// the last COMMITTED state — pre-delete key rows in test 1, no second source
// item at all in test 2. Without the transaction, each statement commits on
// its own the instant it runs, and the probe would see exactly the broken
// intermediate state (a keyless row, or a second visible source item) that
// this fix exists to prevent.
//
// DDL below is a renamed duplicate of tests/Postgres/ProjectionWriterBatchingTest.php's
// (see that file's own header for why local DDL is required and sanctioned
// here, and why nothing may be hoisted into tests/Support/). Every pwbt_
// identifier is renamed to pika_ and every helper is renamed to ika*() so the
// two files' global Pest function/identifier symbol tables cannot collide.

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

    // ── ingest.* — flat (not hash-partitioned); see ProjectionWriterBatchingTest's header. ──
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
    $pg->statement('CREATE UNIQUE INDEX idx_pika_rv_content ON ingest.record_versions (stream_id, key, doc_hash)');
    $pg->statement('CREATE INDEX idx_pika_rv_current ON ingest.record_versions (stream_id, key) WHERE is_current');

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
        CONSTRAINT pika_source_items_coord_unique UNIQUE (source_id, coord)
    )');
    $pg->statement('CREATE INDEX idx_pika_source_items_item ON content.source_items (item_id)');

    $pg->statement('CREATE TABLE content.identity_keys (
        id bigserial PRIMARY KEY,
        source_item_id uuid NOT NULL REFERENCES content.source_items(id) ON DELETE CASCADE,
        key_class text NOT NULL,
        key_value text NOT NULL,
        tier text NOT NULL CHECK (tier IN (\'joining\',\'corroborating\',\'evidential\')),
        created_at timestamptz NOT NULL DEFAULT now()
    )');
    $pg->statement('CREATE INDEX idx_pika_identity_keys_source_item ON content.identity_keys (source_item_id)');

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
        CONSTRAINT pika_identity_candidates_pair UNIQUE (user_id, left_item_id, right_item_id)
    )');

    $pg->statement('CREATE TABLE content.manual_overrides (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        facet text NOT NULL,
        column_name text NOT NULL,
        value jsonb,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT pika_manual_overrides_unique UNIQUE (item_id, facet, column_name)
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
        mirror_eligible boolean,
        mirror_attempts integer NOT NULL DEFAULT 0,
        mirror_last_attempt_at timestamptz,
        mirror_last_reason text,
        created_at timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT pika_media_assets_fingerprint_unique UNIQUE (user_id, fingerprint)
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
    $pg->statement('CREATE INDEX idx_pika_item_media_item ON content.item_media (item_id, role, position)');

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
    $pg->statement('CREATE INDEX idx_pika_offers_item ON content.offers (item_id)');

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
    $pg->statement('CREATE INDEX idx_pika_item_variants_item ON content.item_variants (item_id)');

    $pg->statement('CREATE TABLE content.item_tags (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        source_id uuid NOT NULL REFERENCES content.sources(id) ON DELETE CASCADE,
        tag text NOT NULL,
        tag_type text
    )');
    $pg->statement('CREATE INDEX idx_pika_item_tags_item ON content.item_tags (item_id)');

    $pg->statement('CREATE TABLE content.f_action (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        source_id uuid NOT NULL REFERENCES content.sources(id) ON DELETE CASCADE,
        intent text NOT NULL,
        label text,
        url text NOT NULL,
        position integer NOT NULL DEFAULT 0
    )');
    $pg->statement('CREATE INDEX idx_pika_f_action_item ON content.f_action (item_id)');

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
    $pg->statement('CREATE UNIQUE INDEX idx_pika_collections_natural_key
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
function ikaFixture(string $resourceIdSuffix): array
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

/** Content-addressed landing — see ProjectionWriterBatchingTest::pwbtLand()'s docblock for why this shape matters (A -> B -> A reverts). Not exercised by either test below, but land() must still route through it so record_state/record_versions carry the same invariants projectStream() itself relies on. */
function ikaLand(string $streamId, string $key, array $doc, ?string $firstSeenAt = null): void
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

/** See ProjectionWriterBatchingTest::pwbtDoc()'s docblock — art_url's default matters there (media path), not here. */
function ikaDoc(string $title, string $url): array
{
    return ['title' => $title, 'url' => $url, 'artist' => 'Some Artist', 'release_date' => '2025-05-05', 'art_url' => $url.'/art.jpg', 'type' => 'album'];
}

it('keeps a source item\'s identity keys visible to a concurrent reader across the DELETE/INSERT replace-set — the writeIdentityKeys() half of #CACHE-3', function () {
    // A genuinely separate Postgres connection to the same database — never
    // the connection the writer itself is using, per the file header.
    config(['database.connections.pgsql_ikprobe' => config('database.connections.pgsql')]);

    [, , $source, $streamId] = ikaFixture('10');
    ikaLand($streamId, 'race-key', ikaDoc('Race Release', 'https://ika1.bandcamp.com/album/race-key'));

    $writer = app(ProjectionWriter::class);
    $writer->projectStream($source, $streamId, 'releases');

    $sourceItemId = (string) DB::connection('pgsql')->table('content.source_items')->where('record_key', 'race-key')->value('id');
    expect($sourceItemId)->not->toBe('');

    // Proves the setup before touching the injection: this Bandcamp release
    // carries a url, a title and an artist, so the deriver emits the platform
    // object, the canonical url, and the three title-derived classes. Pinned
    // as the CLASS SET rather than a bare count — a count says nothing about
    // WHICH keys landed, and this fixture's whole job is to prove the replace
    // set is non-trivial before the injection below examines it.
    $classes = DB::connection('pgsql')->table('content.identity_keys')
        ->where('source_item_id', $sourceItemId)->orderBy('key_class')->pluck('key_class')->all();
    expect($classes)->toBe(['canonical_url', 'platform_object', 'title_loose', 'title_only', 'title_release']);

    $fired = false;
    $observed = null;
    DB::listen(function ($query) use (&$fired, &$observed, $sourceItemId) {
        if ($fired) {
            return; // fire-once: only the FIRST matching statement is the one under test.
        }

        $sql = strtolower($query->sql);
        if (! str_contains($sql, 'delete from') || ! str_contains($sql, 'identity_keys')) {
            return;
        }
        if (! in_array($sourceItemId, $query->bindings, true)) {
            return; // not this record's delete
        }

        $fired = true;

        // The event fires AFTER the DELETE has executed but BEFORE the
        // transaction that contains it commits. A second, independently
        // resolved connection's snapshot cannot see an uncommitted DELETE —
        // MVCC hands it the pre-delete row versions instead. Reading via the
        // probe connection, not the writer's own, is what makes this a real
        // proof of visibility rather than a same-session re-read.
        $observed = (int) DB::connection('pgsql_ikprobe')->table('content.identity_keys')
            ->where('source_item_id', $sourceItemId)->count();
    });

    // Pass 2: every live, untombstoned record is reprocessed on every run
    // (projectStream() has no unchanged-record skip), so this run drives the
    // SAME source item through writeIdentityKeys()'s unconditional
    // DELETE-then-INSERT — the exact statement pair the transaction wraps.
    $writer->projectStream($source, $streamId, 'releases');

    // Without this assertion, a future refactor that stops firing (e.g. one
    // that batches the delete differently, or renames the table) would leave
    // $observed at its initial null and the assertion below would pass
    // vacuously — proving nothing.
    expect($fired)->toBeTrue();

    // The invariant: post-fix, a concurrent reader NEVER observes zero keys
    // for a source item mid-rewrite, because the delete has not committed yet
    // — it still sees the whole pre-delete replace set.
    expect($observed)->toBe(count($classes));

    DB::purge('pgsql_ikprobe');
});

it('never lets a concurrent reader see a first-sight source item before its identity keys land — the upsertSourceItem() granularity proof for #CACHE-3', function () {
    // Same probe-connection idiom as the test above, re-registered because
    // Pest boots a fresh Application (and so a fresh config()) per test.
    config(['database.connections.pgsql_ikprobe' => config('database.connections.pgsql')]);

    [, $connectionId, $source, $streamId] = ikaFixture('11');

    $writer = app(ProjectionWriter::class);

    // Record 1: land + project now, so it is fully committed and keyed BEFORE
    // the injection below — a stable baseline the probe's count(a) has to
    // land on.
    ikaLand($streamId, 'first-key', ikaDoc('First Release', 'https://ika2.bandcamp.com/album/first-key'));
    $writer->projectStream($source, $streamId, 'releases');

    $contentSourceId = (string) DB::connection('pgsql')->table('content.sources')->where('connection_id', $connectionId)->value('id');
    expect($contentSourceId)->not->toBe('');
    expect(DB::connection('pgsql')->table('content.source_items')->where('source_id', $contentSourceId)->count())->toBe(1);

    // Record 2: lands now, so the NEXT projectStream() call sees it as
    // first-sight — upsertSourceItem() takes the INSERT branch, not the
    // UPDATE branch record 1 already took.
    ikaLand($streamId, 'second-key', ikaDoc('Second Release', 'https://ika2.bandcamp.com/album/second-key'));

    $fired = false;
    $observedTotal = null;
    $observedKeyless = null;
    DB::listen(function ($query) use (&$fired, &$observedTotal, &$observedKeyless, $contentSourceId) {
        if ($fired) {
            return; // fire-once
        }

        $sql = strtolower($query->sql);
        // Record 1 already exists this run, so it takes the UPDATE branch —
        // the only INSERT into content.source_items during this call is
        // record 2's first-sight row. No further binding filter is needed.
        if (! str_contains($sql, 'insert into') || ! str_contains($sql, 'source_items') || str_contains($sql, 'identity_keys')) {
            return;
        }

        $fired = true;

        // Read on the SEPARATE probe connection: with the fix, record 2's
        // source_items row is still uncommitted (it lives inside the same
        // transaction as its own writeIdentityKeys() call, which hasn't run
        // yet at this exact instant) — MVCC hides it from the probe entirely.
        // Pre-fix, upsertSourceItem() committed on its own the moment this
        // statement's enclosing (much narrower, or absent) transaction
        // closed, so the probe would already see TWO source items for this
        // content source, one of them keyless.
        $observedTotal = (int) DB::connection('pgsql_ikprobe')->table('content.source_items')
            ->where('source_id', $contentSourceId)->count();

        $observedKeyless = (int) DB::connection('pgsql_ikprobe')->table('content.source_items as si')
            ->where('si.source_id', $contentSourceId)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('content.identity_keys as ik')->whereColumn('ik.source_item_id', 'si.id');
            })
            ->count();
    });

    $writer->projectStream($source, $streamId, 'releases');

    // Same rationale as the companion test: without this, the two count
    // assertions below would pass vacuously if the listener silently stopped
    // matching (both variables would stay at their initial null/int(0)-ish
    // state for the wrong reason).
    expect($fired)->toBeTrue();

    // (b) is the actual invariant under test: at no point does a concurrent
    // reader see a source item with zero identity keys.
    expect($observedKeyless)->toBe(0);

    // (a) is what stops (b) passing vacuously against an empty or
    // not-yet-populated table — it proves the probe's snapshot really did
    // include record 1 (the committed baseline) and genuinely excluded
    // record 2's still-uncommitted row, rather than seeing neither.
    expect($observedTotal)->toBe(1);

    DB::purge('pgsql_ikprobe');
});
