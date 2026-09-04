<?php

// Guards mergeInto()'s anchor-repair step (plan 2026-08-25 §A.4, added alongside the
// identity-scope narrowing this task ships).
//
// THE MECHANISM. content.item_anchors.item_id REFERENCES content.items(id) ON DELETE CASCADE.
// mergeInto() sets superseded_by on the loser's anchors but never touches their item_id column,
// repoints content.source_items with an UNSCOPED update (WHERE item_id = $discardedItemId, every
// live coord on that item, not just the current group's), then hard-deletes the discarded item —
// which cascades away every anchor whose item_id (the FK column, untouched by the superseded_by
// update) still names it. That includes anchors for coords the unscoped source_items repoint
// just moved onto the kept item but which are NOT part of the group bindGroup() is currently
// processing.
//
// Before 2026-08-25 this was invisible: resolveItemsLocked() re-derived every live source item
// of the (user, kind) on every pass, so any coord whose anchor was cascade-deleted got a fresh
// one re-minted by bindGroup()'s own insertOrIgnore before the same transaction committed. With
// the resolve narrowed to IdentityScope's connected component, a coord OUTSIDE the merging
// group's component keeps a valid, correctly-repointed item_id but loses its anchor permanently
// — until mergeInto() repairs it directly. Without that repair, the coord's next independent
// touch reads an empty anchor set for itself, bindGroup() mints a BRAND NEW item, and the
// closing per-target UPDATE moves the coord off its correctly-merged item onto that fresh
// duplicate — the exact defect this test proves closed.
//
// DDL: same local-DDL convention as ProjectionWriterIdentityRaceTest.php and
// ProjectionWriterBatchingTest.php (see either file's header) — content.item_links,
// content.item_slugs and site.section_items are required because writeManualItem()'s resolve
// reaches mergeInto(), which reaches moveLinks()/moveSlugs()/the curation check; without them
// the lane fails 42P01. Identifiers here are pma_-prefixed so this file's constraint names never
// collide with a sibling PG-lane file's, even though sequential beforeEach() drops make that
// moot in practice.

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
    $pg->statement("ALTER TABLE site.platform_connections ADD COLUMN IF NOT EXISTS visibility text NOT NULL DEFAULT 'visible'");

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
        CONSTRAINT pma_source_items_coord_unique UNIQUE (source_id, coord)
    )');
    $pg->statement('CREATE INDEX idx_pma_source_items_item ON content.source_items (item_id)');

    $pg->statement('CREATE TABLE content.identity_keys (
        id bigserial PRIMARY KEY,
        source_item_id uuid NOT NULL REFERENCES content.source_items(id) ON DELETE CASCADE,
        key_class text NOT NULL,
        key_value text NOT NULL,
        tier text NOT NULL CHECK (tier IN (\'joining\',\'corroborating\',\'evidential\')),
        created_at timestamptz NOT NULL DEFAULT now()
    )');
    $pg->statement('CREATE INDEX idx_pma_identity_keys_source_item ON content.identity_keys (source_item_id)');

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
        CONSTRAINT pma_item_links_unique UNIQUE (item_id, platform)
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
    $pg->statement('CREATE UNIQUE INDEX idx_pma_item_slugs_unique ON content.item_slugs (user_id, slug)');

    $pg->statement('CREATE TABLE content.identity_candidates (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
        left_item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        right_item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        score integer NOT NULL,
        evidence jsonb NOT NULL DEFAULT \'{}\'::jsonb,
        dismissed_at timestamptz,
        created_at timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT pma_identity_candidates_pair UNIQUE (user_id, left_item_id, right_item_id)
    )');

    $pg->statement('CREATE TABLE content.manual_overrides (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        item_id uuid NOT NULL REFERENCES content.items(id) ON DELETE CASCADE,
        facet text NOT NULL,
        column_name text NOT NULL,
        value jsonb,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT pma_manual_overrides_unique UNIQUE (item_id, facet, column_name)
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
        CONSTRAINT pma_media_assets_fingerprint_unique UNIQUE (user_id, fingerprint)
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
    $pg->statement('CREATE UNIQUE INDEX idx_pma_collections_natural_key
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

/** The shape Projector::project() returns for a plain link add — a URL derived from the coord so distinct coords never collide on identity by accident. */
function pmaLinkProjection(string $coord): array
{
    return [
        'kind' => 'link',
        'headline' => 'Merge Anchor Test Link',
        'facets' => ['f_link' => ['url' => 'https://example.test/pma-'.sha1($coord)]],
    ];
}

it('re-anchors a coord an out-of-component merge repoints, so a later touch does not mint a duplicate item', function () {
    config(['partna.content.identity_scope' => true]);

    $pg = DB::connection('pgsql');

    $userId = (string) Str::uuid();
    $pg->table('core.users')->insert(['id' => $userId]);
    $pg->table('site.sites')->insert(['id' => (string) Str::uuid(), 'user_id' => $userId]);

    $manualSourceId = app(ProjectionWriter::class)->ensureManualSource($userId);

    // Two items. Q shares R's fate below (both bound to $doomedItemId) — the ordinary residue
    // of an earlier merge, same shape as ProjectionWriterIdentityRaceTest's collation fixture.
    $keptItemId = (string) Str::uuid();
    $doomedItemId = (string) Str::uuid();
    foreach ([[$keptItemId, 3], [$doomedItemId, 2]] as [$itemId, $hoursAgo]) {
        $pg->table('content.items')->insert([
            'id' => $itemId, 'user_id' => $userId, 'kind' => 'link',
            'first_seen_at' => now()->subHours($hoursAgo), 'last_seen_at' => now()->subHours($hoursAgo),
            'created_at' => now()->subHours($hoursAgo), 'updated_at' => now()->subHours($hoursAgo),
        ]);
    }

    // P and Q carry no identity keys at all — the 'same' decision below is their only link, and
    // it is what IdentityScope seeds from. R ALSO carries no identity keys and NO decision names
    // it: nothing connects R to P or Q, so it sits OUTSIDE the component the merge below
    // resolves — the coord this test is about. first_seen_at fixes DisjointSet::groups()'
    // emission order so {P,Q} is bound before {R} would be, matching the real defect's ordering.
    $coords = [
        ['pma:p', $keptItemId, 3],
        ['pma:q', $doomedItemId, 2],
        ['pma:r', $doomedItemId, 1],
    ];
    foreach ($coords as [$coord, $itemId, $hoursAgo]) {
        $pg->table('content.source_items')->insert([
            'id' => (string) Str::uuid(), 'source_id' => $manualSourceId, 'coord' => $coord,
            'item_id' => $itemId, 'kind' => 'link', 'projector_version' => 0,
            'first_seen_at' => now()->subHours($hoursAgo), 'last_seen_at' => now()->subHours($hoursAgo),
        ]);
        $pg->table('content.item_anchors')->insert([
            'coord' => $coord, 'user_id' => $userId, 'item_id' => $itemId,
            'bound_at' => now()->subHours($hoursAgo),
        ]);
    }

    // The owner unites P and Q. That merge discards $doomedItemId and hard-deletes it — which
    // cascades content.item_anchors for EVERY anchor still naming it, including R's, even though
    // R is not part of {P,Q}'s resolved component.
    $pg->table('content.identity_decisions')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $userId, 'verdict' => 'same',
        'left_coord' => 'pma:p', 'right_coord' => 'pma:q', 'decided_at' => now(),
    ]);

    // Any manual write drives a resolveItemsLocked() pass. With narrowing ON, the pass's
    // component is {trigger} ∪ {p, q} (seeded by the live 'same' ruling) — NOT r.
    $trigger = 'manual:'.sha1('pma-trigger-'.Str::random(8));
    app(ProjectionWriter::class)->writeManualItem($userId, $trigger, pmaLinkProjection($trigger));

    expect($pg->table('content.items')->where('id', $doomedItemId)->exists())->toBeFalse(
        'the merge did not happen, so this test proves nothing.',
    );

    // The defect's first symptom: R's source_items row must have been repointed onto the kept
    // item by mergeInto()'s unscoped update...
    $rItemId = $pg->table('content.source_items')->where('coord', 'pma:r')->value('item_id');
    expect((string) $rItemId)->toBe($keptItemId, 'R was not repointed onto the kept item at all — a different failure than the one under test.');

    // ...and every coord OUTSIDE the {P,Q} group that now points at the kept item — here, just
    // R — must have a matching content.item_anchors row. P and Q are deliberately excluded from
    // this check: Q is the {P,Q} group's own merge loser, and bindGroup() itself leaves a
    // resolved group's own losing coord anchor-less by design (it was already read into that
    // call's $anchors, so it never enters $missing) — ProjectionWriterIdentityRaceTest's
    // collation test asserts on exactly that ("picks the same merge survivor..."), and
    // mergeInto()'s repair deliberately excludes the current group's own coords so it cannot
    // silently give both sides of a merged pair an anchor and defeat that assertion. R is not in
    // ANY group this pass considered at all — nothing else will ever re-mint its anchor, which is
    // the actual defect this test guards.
    $keptCoords = $pg->table('content.source_items')->where('item_id', $keptItemId)
        ->whereNotIn('coord', ['pma:p', 'pma:q'])->pluck('coord');
    $anchoredCoords = $pg->table('content.item_anchors')->where('user_id', $userId)
        ->where('item_id', $keptItemId)->pluck('coord');
    // in_array()/toBeTrue() rather than toContain(): Pest's toContain() is variadic, so a second
    // argument is another expected value, not a failure message.
    expect(in_array('pma:r', $keptCoords->all(), true))->toBeTrue(
        'R was not repointed onto the kept item — fixture is broken, not the defect under test.',
    );
    foreach ($keptCoords as $coord) {
        expect($anchoredCoords->contains($coord))->toBeTrue(
            "coord {$coord} points at the kept item but has no matching item_anchors row — it will mint a duplicate on its next independent touch.",
        );
    }

    // The follow-on consequence, which is the actual defect: touch R again, independently of P
    // and Q (its own re-save, no shared identity, no decision). Without the repair, R's anchor
    // set reads empty, bindGroup() mints a BRAND NEW item, and the closing per-target UPDATE
    // moves R off the correctly-merged kept item onto that fresh duplicate.
    $secondItemId = app(ProjectionWriter::class)->writeManualItem($userId, 'pma:r', pmaLinkProjection('pma:r'));

    expect($secondItemId)->toBe($keptItemId, 'touching R again minted a NEW item instead of re-confirming the kept one — the anchor-orphan defect.');

    expect($pg->table('content.items')->where('user_id', $userId)->whereNotIn('id', [$keptItemId])->count())
        ->toBe(1, 'exactly one OTHER item should exist — the trigger coord\'s own item — not a second duplicate for R.');
});

it('does not run the anchor repair when narrowing is off — the flag is a byte-for-byte rollback', function () {
    // Explicit, not relying on config/partna.php's default: this test is ABOUT the off state.
    config(['partna.content.identity_scope' => false]);

    $pg = DB::connection('pgsql');

    $userId = (string) Str::uuid();
    $pg->table('core.users')->insert(['id' => $userId]);
    $pg->table('site.sites')->insert(['id' => (string) Str::uuid(), 'user_id' => $userId]);

    $manualSourceId = app(ProjectionWriter::class)->ensureManualSource($userId);

    // Identical fixture shape to the ON-narrowing test above: P/Q merge via a 'same' decision,
    // R shares nothing with either and sits on the doomed item too.
    $keptItemId = (string) Str::uuid();
    $doomedItemId = (string) Str::uuid();
    foreach ([[$keptItemId, 3], [$doomedItemId, 2]] as [$itemId, $hoursAgo]) {
        $pg->table('content.items')->insert([
            'id' => $itemId, 'user_id' => $userId, 'kind' => 'link',
            'first_seen_at' => now()->subHours($hoursAgo), 'last_seen_at' => now()->subHours($hoursAgo),
            'created_at' => now()->subHours($hoursAgo), 'updated_at' => now()->subHours($hoursAgo),
        ]);
    }

    $coords = [
        ['pma:off-p', $keptItemId, 3],
        ['pma:off-q', $doomedItemId, 2],
        ['pma:off-r', $doomedItemId, 1],
    ];
    foreach ($coords as [$coord, $itemId, $hoursAgo]) {
        $pg->table('content.source_items')->insert([
            'id' => (string) Str::uuid(), 'source_id' => $manualSourceId, 'coord' => $coord,
            'item_id' => $itemId, 'kind' => 'link', 'projector_version' => 0,
            'first_seen_at' => now()->subHours($hoursAgo), 'last_seen_at' => now()->subHours($hoursAgo),
        ]);
        $pg->table('content.item_anchors')->insert([
            'coord' => $coord, 'user_id' => $userId, 'item_id' => $itemId,
            'bound_at' => now()->subHours($hoursAgo),
        ]);
    }

    $pg->table('content.identity_decisions')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $userId, 'verdict' => 'same',
        'left_coord' => 'pma:off-p', 'right_coord' => 'pma:off-q', 'decided_at' => now(),
    ]);

    $trigger = 'manual:'.sha1('pma-off-trigger-'.Str::random(8));
    app(ProjectionWriter::class)->writeManualItem($userId, $trigger, pmaLinkProjection($trigger));

    expect($pg->table('content.items')->where('id', $doomedItemId)->exists())->toBeFalse(
        'the merge did not happen, so this test proves nothing.',
    );

    // With narrowing OFF, resolveItemsLocked() resolves the WHOLE (user, kind) set on every call,
    // exactly as it did before 2026-08-25 — so R is NOT left outside this pass at all: it is its
    // own singleton group (no shared key, no decision), read and rebound in the SAME transaction
    // as {P,Q}'s merge, by bindGroup()'s OWN pre-existing insertOrIgnore logic, not by the new
    // mergeInto() repair (which is gated off and never runs). That is precisely what "the repair
    // did not run" looks like from the outside: R does NOT silently inherit the kept item the way
    // it does with narrowing on — it gets rebound onto a BRAND NEW item, the same churn the
    // pre-branch code always produced for an identity-isolated coord caught up in someone else's
    // merge. This is the behaviour PARTNA_CONTENT_IDENTITY_SCOPE=false exists to restore
    // byte-for-byte; asserting "R equals kept" here would mean the repair leaked into the
    // supposedly-off path.
    $rItemId = (string) $pg->table('content.source_items')->where('coord', 'pma:off-r')->value('item_id');
    expect($rItemId)->not->toBe($keptItemId,
        'R ended up on the kept item with narrowing OFF — the mergeInto() repair ran when it should have been gated off.');
    expect($rItemId)->not->toBe($doomedItemId,
        'R is still pointing at the hard-deleted item — the whole-kind resolve did not rebind it, so this fixture is not exercising what it claims to.');

    // R DOES get an anchor — via bindGroup()'s own pre-existing per-group insertOrIgnore for its
    // freshly-minted item, the mechanism that has always run, not the new repair. Confirms this
    // is the OLD self-healing path, not a silent no-op.
    expect($pg->table('content.item_anchors')->where('user_id', $userId)->where('coord', 'pma:off-r')->exists())
        ->toBeTrue('R has no anchor at all — neither the old whole-kind self-heal nor the new repair ran.');
});
