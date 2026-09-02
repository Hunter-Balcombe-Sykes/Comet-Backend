<?php

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Content\LinkPoolWriter;
use App\Services\PublicSite\IndividualProfilePayloadBuilder;
use App\Services\PublicSite\SitepageDataResolverService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupBlocksTable();
    // §28.8 enriched payload reaches into site_media (content + gallery
    // + document) and core.services (services + booking-mode settings).
    // Stub the SQLite shadow schemas so the resolver's queries don't blow
    // up on missing tables.
    setupMediaTables();
    setupServiceCategoriesTable();
    setupServicesTable();
    // Slice 3a §3.4: the services engine now reads content.* (owner-authored
    // services live there post-backfill) — every test in this file exercises
    // the resolver, so the content/ingest schema must exist even for tests
    // that never touch a service.
    setupIngestTables();
    setupContentTables();

    // Architecture column shim — production has architecture_id with a CHECK
    // enum default 'staple', and the SitepageDataResolverService reads it via
    // $site->architecture_id. Plus a stub design_kits table whose shape mirrors
    // the post-phase-7a column set so the PayloadBuilder's loadDesignKit()
    // lookup + grouping logic exercises real columns even on SQLite.
    try {
        DB::connection('pgsql')->statement("ALTER TABLE site.sites ADD COLUMN architecture_id TEXT NOT NULL DEFAULT 'staple'");
    } catch (Throwable $e) {
        // Column already exists from a prior test in the same process.
    }
    // typography_font_heading/typography_font_body were DROPPED by
    // 20260603000001_drop_orphan_design_kit_typography_cols.sql — not present
    // in production. typography_font_family (added 20260527090000, never
    // dropped) stands in for exercising the typography prefix-group path.
    //
    // This table is a FIXTURE FOR groupKitColumns(), not a mirror of
    // production. It deliberately carries columns the live schema does not
    // (sizing_desktop_base, typography_desktop_size_base, and since 2026-08-09
    // space_regular / space_desktop_regular) because the mapping function is
    // what is under test and each one is the only specimen of a branch:
    // two-token prefix, two-token-beats-single-token, and single-token.
    // tests/Pest.php::setupDesignKitsTable() is the mirror that must track the
    // real schema; this one must track the mapper's branches.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.design_kits (
        site_id TEXT PRIMARY KEY,
        color_accent TEXT NULL,
        typography_font_family TEXT NULL,
        typography_uppercase INTEGER NULL,
        text_size TEXT NULL,
        spacing TEXT NULL,
        corners TEXT NULL,
        space_regular TEXT NULL,
        space_desktop_regular TEXT NULL,
        sizing_desktop_base TEXT NULL,
        typography_desktop_size_base TEXT NULL
    )');

    Cache::flush();
    // Disable throttling so the test isn't tied to RateLimiter internals.
    Config::set('partna.throttle.enabled', false);
});

function seedIndividualProfile(string $handle, ?string $architectureId = null, string $status = 'active'): User
{
    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => 'Solo Pro',
        'first_name' => 'Solo Pro',
        // bio dropped from core.users by 20260705120002_drop_dead_profile_columns_tables
        // — this insert wrote a value nothing reads back (caught by FixtureSchemaParityTest).
        'account_type' => 'partna',
        'location_city' => 'Sydney',
        'location_state' => 'NSW',
        'location_country' => 'AU',
        'status' => $status,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $siteRow = [
        'id' => $siteId,
        'user_id' => $proId,
        'subdomain' => strtolower($handle),
        'settings' => json_encode([]),
        'is_published' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ];
    if ($architectureId !== null) {
        $siteRow['architecture_id'] = $architectureId;
    }
    DB::connection('pgsql')->table('site.sites')->insert($siteRow);

    return User::query()->findOrFail($proId);
}

it('returns 200 with the skeleton-system envelope shape for an individual', function () {
    $pro = seedIndividualProfile('solo1');

    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'site_id' => DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id'),
        'block_type' => 'link',
        'block_group' => 'links',
        'title' => 'Example',
        'url' => 'https://example.test',
        'sort_order' => 1,
        'settings' => json_encode(['url' => 'https://example.test']),
        'is_active' => 1,
        'is_enabled' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $res = $this->getJson('/api/public/profiles/solo1')->assertOk();
    $data = $res->json('data');

    // Top-level keys are now { profile, designKit, architectureId,
    // publicConfig } — architectureId is the canonical key; skeletonId was a
    // transition alias kept until apps/pages reads architectureId. No more legacy
    // themeMode/accent/fontFamily/design.
    expect($data)->toHaveKeys(['profile', 'designKit', 'architectureId', 'publicConfig']);
    expect($data)->not->toHaveKey('skeletonId');
    expect($data)->not->toHaveKey('design');
    expect($data)->not->toHaveKey('themeMode');

    expect($data['architectureId'])->toBe('scroll');
    // Transition alias — must mirror architectureId until apps/pages migrates.
    // Empty designKit decodes to [] under json() because PHP can't tell
    // {} from [] post-decode; the wire byte-level check happens below.
    expect($data['designKit'])->toEqual([]);

    // publicConfig is always emitted (object on the wire); partial-shape check
    // so future additions don't break this test. analyticsEndpoint left the
    // wire 2026-09-01 — it advertised <app.url>/api/analytics, a route that has
    // never been registered (the real ones are /api/public/analytics/*), and no
    // consumer ever read it: the pages app beacons same-origin to /t/*.
    expect($data['publicConfig'])->toBeArray();
    expect($data['publicConfig'])->toHaveKey('shopLinkMode');
    expect($data['publicConfig'])->not->toHaveKey('analyticsEndpoint');

    $profile = $data['profile'];
    // Phase 8 engine fields — booking is now a link category, not a separate
    // field. Each engine emits its stable empty state when nothing is live.
    expect($profile)->toHaveKeys([
        'handle', 'displayName',
        'pools', 'document', 'newsletter',
    ]);
    // links / services (the pre-pool engine lists) left the wire 2026-08-19.
    expect($profile)->not->toHaveKey('links');
    expect($profile)->not->toHaveKey('services');
    expect($profile)->not->toHaveKey('booking');
    // Slice 7 unit E: gallery / curatedGallery / designMedia / siteImages left
    // the wire outright — the media pool is the curation lane.
    expect(array_key_exists('gallery', $profile))->toBeFalse();
    expect(array_key_exists('curatedGallery', $profile))->toBeFalse();
    expect(array_key_exists('designMedia', $data))->toBeFalse();
    expect(array_key_exists('siteImages', $data))->toBeFalse();
    expect($profile['handle'])->toBe('solo1');
    expect($profile['displayName'])->toBe('Solo Pro');

    // Empty-state defaults per spec §3.4 + phase 8:
    //   - object engines (document, newsletter) → null
    //   - the pools object → {} (the list engines left the wire 2026-08-19)
    expect((array) $profile['pools'])->toBe([]);
    expect($profile['document'])->toBeNull();
    expect($profile['newsletter'])->toBeNull();
    expect($profile['contact'])->toBeNull();

    // Wire-level check: empty designKit / publicConfig must serialise as `{}`
    // (object), never `[]` (array). PHP defaults to `[]` for empty assoc
    // arrays, so the Resource casts to stdClass when there's nothing to emit.
    $raw = $res->getContent();
    expect($raw)->toContain('"designKit":{}');
    // publicConfig always carries shopLinkMode, so it's never `{}` in this
    // assertion — but we still confirm it serialises as an object.
    expect($raw)->toContain('"publicConfig":{');

    // Legacy link BLOCKS no longer reach the wire at all (2026-08-23): not as
    // `blocks[]`, not as `profile.links` (left 2026-08-19), and not as an
    // action — the unified list draws items from the content pools only.
    expect($raw)->not->toContain('"blocks":');
    expect($data['actions'])->toBe(['mode' => 'smart', 'entries' => []]);
    expect(array_keys($data))->not->toContain('rankedActions', 'ordering');
});

it('flags publicConfig.claim.unclaimed for a published pre-account (unclaimed) profile', function () {
    // Unclaimed pre-account sites render publicly once published (PublicSiteResolver
    // whereIn(['active', 'unclaimed'])) — the pages app otherwise has no signal to
    // distinguish this from a claimed profile (2026-07-22 signup-flows handoff).
    seedIndividualProfile('unclaimed1', null, 'unclaimed');
    $data = $this->getJson('/api/public/profiles/unclaimed1')->assertOk()->json('data');

    expect($data['publicConfig'])->toHaveKey('claim');
    expect($data['publicConfig']['claim'])->toBe(['unclaimed' => true]);
});

it('omits publicConfig.claim entirely for a claimed (active) profile', function () {
    seedIndividualProfile('claimed1');
    $data = $this->getJson('/api/public/profiles/claimed1')->assertOk()->json('data');

    expect($data['publicConfig'])->not->toHaveKey('claim');
});

it('returns the user-selected architecture_id', function () {
    seedIndividualProfile('solo-sk2', 'staple');
    $data = $this->getJson('/api/public/profiles/solo-sk2')->assertOk()->json('data');
    expect($data['architectureId'])->toBe('staple');
});

it('groups stored design_kit columns into nested camelCase wire shape', function () {
    // Stored: flat snake_case columns (color_accent, typography_font_family).
    // Wire:   nested camelCase under group keys (colors.accent, typography.fontFamily).
    $pro = seedIndividualProfile('solo-dk');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    // The trigger that auto-inserts an empty design_kits row only exists in
    // prod; the test stub doesn't run it. Insert manually with stored values.
    DB::connection('pgsql')->table('site.design_kits')->insert([
        'site_id' => $siteId,
        'color_accent' => '#ff0080',
        'typography_font_family' => 'inter',
    ]);

    $data = $this->getJson('/api/public/profiles/solo-dk')->assertOk()->json('data');

    expect($data['designKit'])->toEqual([
        'colors' => ['accent' => '#ff0080'],
        'typography' => ['fontFamily' => 'inter'],
    ]);
});

// (The theme.nightShiftAuto wire-shape test retired 2026-08-27 with its
// columns — plan 02. Its branch — single-token prefix with a multi-token
// camelCased remainder — stays covered by typography_font_family →
// typography.fontFamily above.)

it('maps typography_uppercase as a BOOLEAN into typography.uppercase (plan 02 step 3)', function () {
    // Booleans must survive the wire as booleans — the pages-side zod
    // schema types typography.uppercase as z.boolean(), so a "1"/"0"
    // string here would be stripped client-side and silently render the
    // package default.
    $pro = seedIndividualProfile('solo-dk-upper');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    DB::connection('pgsql')->table('site.design_kits')->insert([
        'site_id' => $siteId,
        'typography_uppercase' => 0,
    ]);

    $data = $this->getJson('/api/public/profiles/solo-dk-upper')->assertOk()->json('data');

    expect($data['designKit']['typography']['uppercase'])->toBeFalse();
});

it('maps the selection columns into the selections group', function () {
    // The exact_columns path (2026-08-09). Two of these —`spacing` and
    // `corners` — carry NO underscore, so the prefix split cannot produce a
    // group/rest pair for them at all: before exact_columns existed they were
    // dropped from the payload silently, with no error anywhere. This test is
    // the only thing standing between that and a shipped regression.
    $pro = seedIndividualProfile('solo-dk-selections');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    DB::connection('pgsql')->table('site.design_kits')->insert([
        'site_id' => $siteId,
        'text_size' => 'large',
        'spacing' => 'spacious',
        'corners' => 'rounded',
    ]);

    $data = $this->getJson('/api/public/profiles/solo-dk-selections')->assertOk()->json('data');

    expect($data['designKit']['selections'])->toBe([
        'textSize' => 'large',
        'spacing' => 'spacious',
        'corners' => 'rounded',
    ]);
    // An exact match must win over the prefix maps: `text_size` would
    // otherwise have landed in text.size.
    expect($data['designKit'])->not->toHaveKey('text');
});

it('groups two-token responsive prefix columns into the correct nested group', function () {
    // Stored: space_desktop_regular — a two-token prefix column.
    // Wire:   designKit.spaceDesktop.regular (NOT designKit.space.desktop_regular).
    $pro = seedIndividualProfile('solo-dk-responsive');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    DB::connection('pgsql')->table('site.design_kits')->insert([
        'site_id' => $siteId,
        'space_desktop_regular' => '2rem',
        'sizing_desktop_base' => '16px',
        'typography_desktop_size_base' => '1rem',
    ]);

    $data = $this->getJson('/api/public/profiles/solo-dk-responsive')->assertOk()->json('data');

    expect($data['designKit']['spaceDesktop']['regular'])->toBe('2rem');
    expect($data['designKit']['sizingDesktop']['base'])->toBe('16px');
    expect($data['designKit']['typographyDesktop']['sizeBase'])->toBe('1rem');
    // Verify two-token columns don't also leak into their single-token siblings.
    expect($data['designKit'])->not->toHaveKey('space');
    expect($data['designKit'])->not->toHaveKey('sizing');
});

it('two-token prefix wins over single-token when they share an initial token', function () {
    // `space_desktop_regular` shares "space" with the single-token prefix.
    // The two-token loop runs first, so it must claim the column before the
    // single-token loop can incorrectly route it into designKit.space.
    $pro = seedIndividualProfile('solo-dk-priority');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    DB::connection('pgsql')->table('site.design_kits')->insert([
        'site_id' => $siteId,
        'space_regular' => '1rem',
        'space_desktop_regular' => '2rem',
    ]);

    $data = $this->getJson('/api/public/profiles/solo-dk-priority')->assertOk()->json('data');

    // Single-token path: space_regular → space.regular
    expect($data['designKit']['space']['regular'])->toBe('1rem');
    // Two-token path: space_desktop_regular → spaceDesktop.regular (not space.desktopRegular)
    expect($data['designKit']['spaceDesktop']['regular'])->toBe('2rem');
    expect($data['designKit']['space'])->not->toHaveKey('desktopRegular');
});

it('excludes brand-only and commerce fields', function () {
    seedIndividualProfile('solo2');
    $data = $this->getJson('/api/public/profiles/solo2')->assertOk()->json('data');

    foreach (['placeholders', 'fallback_gallery', 'brand_logo', 'brand_slogan', 'products', 'cart', 'commission', 'orders', 'shop'] as $forbidden) {
        expect($data)->not->toHaveKey($forbidden);
        expect($data['profile'])->not->toHaveKey($forbidden);
    }
});

it('action projection emits only the structured entry shape (no extra JSONB leaks)', function () {
    $pro = seedIndividualProfile('solo3');
    // A pool link (content.items kind=link) is the ONLY way an owner-authored
    // url reaches the public payload now — as an `item:` action entry.
    app(LinkPoolWriter::class)->add($pro->fresh(), 'https://example.test', 'Example', enrich: false);

    $res = $this->getJson('/api/public/profiles/solo3')->assertOk();
    $action = collect($res->json('data.actions.entries'))->first(fn (array $a) => $a['url'] === 'https://example.test');

    // Only the entry's projected keys exist — nothing internal ever surfaces
    // because the wire is a typed projection, never a row dump.
    expect($action)->not->toBeNull();
    expect(array_keys($action))->toEqual(['position', 'id', 'kind', 'label', 'url', 'thumb', 'locked', 'ref']);
    expect($action['kind'])->toBe('item');
});

it('emits no legacy engine keys: profile.links / profile.services / popularity are gone (2026-08-19)', function () {
    seedIndividualProfile('nolegacy');

    $data = $this->getJson('/api/public/profiles/nolegacy')->assertOk()->json('data');

    // The pre-pool engine lists and the flat popularity map left the wire —
    // pools.custom_links / pools.services carry the content, per-item
    // popularityRank carries the rank, and the sitepage never read the old keys.
    expect($data['profile'])->not->toHaveKey('links');
    expect($data['profile'])->not->toHaveKey('services');
    expect($data)->not->toHaveKey('popularity');
    expect($data['profile'])->toHaveKey('pools');
});

it('unknown block_type does not appear in the structured response', function () {
    $pro = seedIndividualProfile('solo4');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'site_id' => $siteId,
        'block_type' => 'experimental_unknown_type',
        'block_group' => 'sections',
        'sort_order' => 0,
        'settings' => json_encode(['public_thing' => 'visible', 'secret_thing' => 'hidden']),
        'is_active' => 1,
        'is_enabled' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $data = $this->getJson('/api/public/profiles/solo4')->assertOk()->json('data');
    $profile = $data['profile'];

    // Unknown block_type contributes nothing to the engine payload — each
    // engine falls back to its stable empty state (null or []) and there's
    // no `blocks[]` array to leak the raw row into.
    expect($profile['document'])->toBeNull();
    expect($profile['newsletter'])->toBeNull();
    // The pre-pool links / services lists left the wire 2026-08-19; the pools
    // object is the content lane, and it is empty here because nothing was
    // seeded — not because a raw row leaked into it.
    expect($profile)->not->toHaveKey('services');
    expect($profile)->not->toHaveKey('links');
    expect((array) $profile['pools'])->toBe([]);
    expect($profile)->not->toHaveKey('booking');
});

it('returns 404 when the handle does not exist', function () {
    $this->getJson('/api/public/profiles/missing')->assertNotFound();
});

it('is case-insensitive on the handle path param', function () {
    seedIndividualProfile('mixedcase');
    $this->getJson('/api/public/profiles/MIXEDCASE')->assertOk();
});

// ── site_media-backed fields ─────────────────────────────────────────────
// The content-media library projection (getContentMedia) and the document slot
// read off site.site_media rows. Its `designMedia` wire projection, and the
// `gallery` engine, were deleted by slice 7 unit E — the media pool is the
// public curation lane now, so what remains here is the resolver-level shape.

it('projects content-pool videos with kind=video, poster and duration_ms', function () {
    $pro = seedIndividualProfile('cmedia-video');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');
    $mediaId = (string) Str::uuid();

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $mediaId, 'site_id' => $siteId,
        'pool' => 'content', 'path' => 'videos/content/original.mp4',
        'media_type' => 'video', 'processing_state' => 'ready',
        'sort_order' => 0, 'is_active' => 1,
        'alt_text' => 'Intro reel', 'duration_ms' => 12500,
        'poster_path' => 'videos/content/poster.jpg',
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);
    foreach ([
        ['variant_key' => 'optimized', 'artifact_type' => 'mp4',    'mime' => 'video/mp4',  'path' => 'videos/content/opt.mp4'],
        ['variant_key' => 'maximized', 'artifact_type' => 'mp4',    'mime' => 'video/mp4',  'path' => 'videos/content/max.mp4'],
        ['variant_key' => 'poster',    'artifact_type' => 'poster',  'mime' => 'image/jpeg', 'path' => 'videos/content/poster.jpg'],
    ] as $row) {
        DB::connection('pgsql')->table('site.media_variants')->insert([
            'id' => (string) Str::uuid(), 'media_id' => $mediaId,
            'variant_key' => $row['variant_key'], 'artifact_type' => $row['artifact_type'],
            'disk' => 'media', 'path' => $row['path'], 'mime' => $row['mime'],
            'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
        ]);
    }

    $site = Site::query()->findOrFail($siteId);
    $items = app(SitepageDataResolverService::class)->getContentMedia($site);

    expect($items)->toHaveCount(1);
    expect($items[0]['kind'])->toBe('video');
    expect($items[0]['duration_ms'])->toBe(12500);
    expect($items[0]['poster'])->toBeString()->not->toBeEmpty();
    expect($items[0]['url'])->toBeString()->not->toBeEmpty();
    expect($items[0]['url_hd'])->toBeString()->not->toBeEmpty();
});

it('interleaves content-pool images and videos by sort_order', function () {
    $pro = seedIndividualProfile('cmedia-mixed');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');
    $imageId = (string) Str::uuid();
    $videoId = (string) Str::uuid();

    // Image at sort_order=1, video at sort_order=0 — video must come first.
    DB::connection('pgsql')->table('site.site_media')->insert([
        [
            'id' => $imageId, 'site_id' => $siteId,
            'pool' => 'content', 'path' => 'images/content/a.jpg',
            'media_type' => 'image', 'processing_state' => 'ready',
            'sort_order' => 1, 'is_active' => 1,
            'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
        ],
        [
            'id' => $videoId, 'site_id' => $siteId,
            'pool' => 'content', 'path' => 'videos/content/a.mp4',
            'media_type' => 'video', 'processing_state' => 'ready',
            'sort_order' => 0, 'is_active' => 1,
            'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
        ],
    ]);
    // Image needs a webp variant to survive the URL filter.
    DB::connection('pgsql')->table('site.media_variants')->insert([
        'id' => (string) Str::uuid(), 'media_id' => $imageId,
        'variant_key' => 'optimized', 'artifact_type' => 'webp',
        'disk' => 'media', 'path' => 'images/content/a-opt.webp', 'mime' => 'image/webp',
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);
    // Video needs an mp4 variant.
    DB::connection('pgsql')->table('site.media_variants')->insert([
        'id' => (string) Str::uuid(), 'media_id' => $videoId,
        'variant_key' => 'optimized', 'artifact_type' => 'mp4',
        'disk' => 'media', 'path' => 'videos/content/opt.mp4', 'mime' => 'video/mp4',
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);

    $site = Site::query()->findOrFail($siteId);
    $items = app(SitepageDataResolverService::class)->getContentMedia($site);

    expect($items)->toHaveCount(2);
    expect($items[0]['id'])->toBe($videoId);    // sort_order=0
    expect($items[0]['kind'])->toBe('video');
    expect($items[1]['id'])->toBe($imageId);    // sort_order=1
    expect($items[1]['kind'])->toBe('image');
});

it('excludes content-pool media that is not ready / not active / soft-deleted / wrong pool', function () {
    $pro = seedIndividualProfile('cmedia-filter');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    $base = [
        'site_id' => $siteId, 'media_type' => 'image',
        'sort_order' => 0, 'deleted_at' => null,
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ];
    DB::connection('pgsql')->table('site.site_media')->insert([
        // Excluded: processing_state != ready
        array_merge($base, ['id' => (string) Str::uuid(), 'pool' => 'content', 'path' => 'a.jpg', 'processing_state' => 'processing', 'is_active' => 1]),
        // Excluded: is_active = false
        array_merge($base, ['id' => (string) Str::uuid(), 'pool' => 'content', 'path' => 'b.jpg', 'processing_state' => 'ready', 'is_active' => 0]),
        // Excluded: soft-deleted
        array_merge($base, ['id' => (string) Str::uuid(), 'pool' => 'content', 'path' => 'c.jpg', 'processing_state' => 'ready', 'is_active' => 1, 'deleted_at' => now()->toDateTimeString()]),
        // Excluded: gallery pool (wrong pool)
        array_merge($base, ['id' => (string) Str::uuid(), 'pool' => 'gallery', 'path' => 'd.jpg', 'processing_state' => 'ready', 'is_active' => 1]),
    ]);

    $site = Site::query()->findOrFail($siteId);
    $items = app(SitepageDataResolverService::class)->getContentMedia($site);

    expect($items)->toBeArray()->toBeEmpty();
});

it('returns empty when the site is null', function () {
    $items = app(SitepageDataResolverService::class)->getContentMedia(null);
    expect($items)->toBeArray()->toBeEmpty();
});

it('handles content-pool video with no poster artifact (poster=null)', function () {
    $pro = seedIndividualProfile('cmedia-no-poster');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');
    $mediaId = (string) Str::uuid();

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $mediaId, 'site_id' => $siteId,
        'pool' => 'content', 'media_type' => 'video',
        'processing_state' => 'ready', 'sort_order' => 0, 'is_active' => 1,
        'path' => 'videos/np.mp4', 'duration_ms' => 4200,
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);
    DB::connection('pgsql')->table('site.media_variants')->insert([
        'id' => (string) Str::uuid(), 'media_id' => $mediaId,
        'variant_key' => 'optimized', 'artifact_type' => 'mp4',
        'disk' => 'media', 'path' => 'videos/np-opt.mp4', 'mime' => 'video/mp4',
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);

    $site = Site::query()->findOrFail($siteId);
    $items = app(SitepageDataResolverService::class)->getContentMedia($site);

    expect($items)->toHaveCount(1);
    expect($items[0]['kind'])->toBe('video');
    expect($items[0]['poster'])->toBeNull();
    expect($items[0]['url'])->toBeString()->not->toBeEmpty();
});

it('returns url_hd=null for content-pool video with only optimized variant', function () {
    $pro = seedIndividualProfile('cmedia-opt-only');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');
    $mediaId = (string) Str::uuid();

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $mediaId, 'site_id' => $siteId,
        'pool' => 'content', 'media_type' => 'video',
        'processing_state' => 'ready', 'sort_order' => 0, 'is_active' => 1,
        'path' => 'videos/oo.mp4', 'duration_ms' => 3000,
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);
    DB::connection('pgsql')->table('site.media_variants')->insert([
        'id' => (string) Str::uuid(), 'media_id' => $mediaId,
        'variant_key' => 'optimized', 'artifact_type' => 'mp4',
        'disk' => 'media', 'path' => 'videos/oo-opt.mp4', 'mime' => 'video/mp4',
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);

    $site = Site::query()->findOrFail($siteId);
    $items = app(SitepageDataResolverService::class)->getContentMedia($site);

    expect($items)->toHaveCount(1);
    expect($items[0]['url'])->toBeString()->not->toBeEmpty();
    expect($items[0]['url_hd'])->toBeNull();
});

// ── Phase 8 engines: links / services / document / newsletter
// Each test seeds the minimum storage rows and confirms the projection lands
// in the right engine field with the right shape (null/[]/object) and key casing.

it('emits publicContact as its own top-level key gated by the public_contact section', function () {
    $pro = seedIndividualProfile('pubcontact-live');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    // Opt-in public contact details live on core.users; the payload only
    // surfaces them when the dedicated `public_contact` section block is live —
    // no longer nested under the bio engine.
    $pro->update(['public_contact_email' => 'hi@example.com', 'public_contact_number' => null]);

    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'site_id' => $siteId,
        'block_type' => 'public_contact',
        'block_group' => 'sections',
        'is_active' => 1,
        'is_enabled' => 1,
        'sort_order' => 0,
        'settings' => json_encode([]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $res = $this->getJson('/api/public/profiles/pubcontact-live')->assertOk();

    $res->assertJsonPath('data.profile.publicContact.email', 'hi@example.com');
    $res->assertJsonPath('data.profile.publicContact.phone', null);
});

it('workplace engine returns WorkplaceData when the workplace section is live', function () {
    // FOUND-4: workplace data now lives in site.workplaces (promoted from settings JSONB).
    $pro = seedIndividualProfile('workplace-live');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    DB::connection('pgsql')->table('site.workplaces')->insert([
        'site_id' => $siteId,
        'name' => 'Fade Lab Barbers',
        'address_line1' => '10 Crown St',
        'city' => 'Surry Hills',
        'state' => 'NSW',
        'country' => 'AU',
        'latitude' => -33.886,
        'longitude' => 151.209,
        'phone' => '+61 2 9000 0000',
        'website' => 'https://fadelab.example',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'site_id' => $siteId,
        'block_type' => 'workplace',
        'block_group' => 'sections',
        'is_active' => 1,
        'is_enabled' => 1,
        'sort_order' => 0,
        'settings' => json_encode([]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $workplace = $this->getJson('/api/public/profiles/workplace-live')->assertOk()->json('data.profile.workplace');

    // buildWorkplace() remaps snake_case → camelCase: 10 keys on the wire.
    expect($workplace)->toHaveKeys(['name', 'addressLine1', 'city', 'state', 'postcode', 'country', 'latitude', 'longitude', 'phone', 'website']);
    expect($workplace['name'])->toBe('Fade Lab Barbers');
    expect($workplace['addressLine1'])->toBe('10 Crown St');
    expect($workplace['latitude'])->toBe(-33.886);
    expect($workplace['phone'])->toBe('+61 2 9000 0000');
});

it('document engine returns DocumentData when a ready document exists', function () {
    $pro = seedIndividualProfile('doc-live');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $siteId,
        'pool' => 'documents',
        'path' => 'docs/rate-card.pdf',
        'media_type' => 'document',
        'processing_state' => 'ready',
        'sort_order' => 0,
        'is_active' => 1,
        'alt_text' => 'Rate card',
        'caption' => '2026 prices',
        'original_mime' => 'application/pdf',
        'original_size_bytes' => 102400,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $doc = $this->getJson('/api/public/profiles/doc-live')->assertOk()->json('data.profile.document');

    expect($doc)->toHaveKeys(['id', 'title', 'caption', 'downloadUrl', 'mime', 'sizeBytes']);
    expect($doc)->toMatchArray([
        'title' => 'Rate card',
        'caption' => '2026 prices',
        'mime' => 'application/pdf',
        'sizeBytes' => 102400,
    ]);
    expect($doc['downloadUrl'])->toContain('/api/public/documents/');
});

it('newsletter engine returns NewsletterData with the authored inputPlaceholder', function () {
    $pro = seedIndividualProfile('nl-live');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'site_id' => $siteId,
        'block_type' => 'newsletter',
        'block_group' => 'sections',
        'is_active' => 1,
        'is_enabled' => 1,
        'sort_order' => 0,
        'settings' => json_encode(['input_placeholder' => 'Your email']),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $newsletter = $this->getJson('/api/public/profiles/nl-live')->assertOk()->json('data.profile.newsletter');

    expect($newsletter)->toEqual(['inputPlaceholder' => 'Your email']);
});

it('newsletter engine publishes a live section with an EMPTY inputPlaceholder (2026-08-19 — the sitepage supplies the fallback copy)', function () {
    $pro = seedIndividualProfile('nl-empty');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'site_id' => $siteId,
        'block_type' => 'newsletter',
        'block_group' => 'sections',
        'is_active' => 1,
        'is_enabled' => 1,
        'sort_order' => 0,
        'settings' => json_encode([]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    expect($this->getJson('/api/public/profiles/nl-empty')->assertOk()->json('data.profile.newsletter'))
        ->toEqual(['inputPlaceholder' => '']);
});

it('contact engine returns ContactData with merged subjectOptions + headline/description when live', function () {
    $pro = seedIndividualProfile('contact-live');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'site_id' => $siteId,
        'block_type' => 'contact',
        'block_group' => 'sections',
        'is_active' => 1,
        'is_enabled' => 1,
        'sort_order' => 0,
        'settings' => json_encode([
            'headline' => 'Reach out',
            'description' => 'I reply within a day.',
            'subject_options' => ['Workshop', 'Other'],
            // Private owner settings — MUST NOT surface in the public payload.
            'notification_email' => 'owner@example.test',
            'notification_channels' => ['email', 'in_app'],
        ]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $contact = $this->getJson('/api/public/profiles/contact-live')->assertOk()->json('data.profile.contact');

    expect($contact)->toHaveKeys(['subjectOptions', 'headline', 'description']);
    expect($contact['headline'])->toBe('Reach out');
    expect($contact['description'])->toBe('I reply within a day.');
    // Merged list: platform defaults first, then the custom additions, deduped.
    // 'Other' appears in both defaults and custom — deduped to a single entry.
    $defaults = config('partna.contact_subject_defaults');
    expect($contact['subjectOptions'])->toBe(array_values(array_unique(array_merge($defaults, ['Workshop', 'Other']))));
    expect($contact['subjectOptions'])->toContain('Workshop');
    // Private owner settings never leak.
    expect($contact)->not->toHaveKey('notification_email');
    expect($contact)->not->toHaveKey('notification_channels');
});

it('contact engine returns null when the contact block is not live', function () {
    $pro = seedIndividualProfile('contact-draft');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    // Block exists but is_active = false → drops to draft, same gate newsletter uses.
    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'site_id' => $siteId,
        'block_type' => 'contact',
        'block_group' => 'sections',
        'is_active' => 0,
        'is_enabled' => 1,
        'sort_order' => 0,
        'settings' => json_encode(['headline' => 'Hidden']),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    expect($this->getJson('/api/public/profiles/contact-draft')->assertOk()->json('data.profile.contact'))
        ->toBeNull();
});

it('contact engine omits headline/description and exposes only platform-default subjects when unset', function () {
    $pro = seedIndividualProfile('contact-bare');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'site_id' => $siteId,
        'block_type' => 'contact',
        'block_group' => 'sections',
        'is_active' => 1,
        'is_enabled' => 1,
        'sort_order' => 0,
        'settings' => json_encode([]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $contact = $this->getJson('/api/public/profiles/contact-bare')->assertOk()->json('data.profile.contact');

    expect($contact)->toHaveKey('subjectOptions');
    expect($contact)->not->toHaveKey('headline');
    expect($contact)->not->toHaveKey('description');
    expect($contact['subjectOptions'])->toBe(array_values(config('partna.contact_subject_defaults')));
});

// ---------------------------------------------------------------------------
// Single-flight & race-condition cache tests
// ---------------------------------------------------------------------------

it('single-flights concurrent requests so only one payload is built', function () {
    // Seed a real user + site so the resolve cache miss path runs a normal DB lookup.
    $pro = seedIndividualProfile('singleflight-pro');

    // Mock the builder — build() must be called exactly once across both requests.
    // The second request hits the warm payload cache (fast path in CacheLockService)
    // and never invokes the callback, so the builder is never reached again.
    $mock = $this->mock(IndividualProfilePayloadBuilder::class);
    $mock->shouldReceive('cacheTtl')->andReturn(300);
    // CCH-5: the controller asks whether the build it just cached was degraded.
    $mock->shouldReceive('lastBuildDegraded')->andReturn(false);
    $mock->shouldReceive('build')
        ->once()
        ->andReturn([
            'profile' => ['handle' => 'singleflight-pro'],
            'designKit' => new stdClass,
            'architectureId' => 'staple',
            'publicConfig' => ['shopLinkMode' => 'checkout'],
        ]);

    // First request — resolve cache miss → DB lookup; payload cache miss → builder called once.
    $res1 = $this->getJson('/api/public/profiles/singleflight-pro')->assertOk();

    // Second request — resolve cache hit (30s TTL); payload cache hit → builder NOT called again.
    $res2 = $this->getJson('/api/public/profiles/singleflight-pro')->assertOk();

    // Both responses carry identical payloads.
    expect($res1->json())->toEqual($res2->json());

    // Mockery verifies ->once() at teardown via Mockery::close().
});

it('handles a race where the site is deleted between resolve and payload cache reads', function () {
    $deletedProId = (string) Str::uuid();

    // Pre-fill the resolve cache to simulate a stale entry pointing at a pro
    // that no longer exists in the DB. rememberLocked writes BOTH a primary key
    // and a longer-lived :stale twin, so seed both — the controller's fast path
    // reads the primary directly, then the payload callback finds no User row
    // and returns null. (CCH-1)
    $resolved = [
        'pro_id' => $deletedProId,
        'site_id' => null,
        'updated_at_ts' => 0,
    ];
    Cache::put('handle.resolve:deleted-race-pro', $resolved, 60);
    Cache::put('handle.resolve:deleted-race-pro:stale', $resolved, 600);

    // The payload cache for the matching key is cold, so the callback runs.
    // User::find($deletedProId) returns null → payload is null → controller
    // evicts the stale resolve entry and returns 404.
    $this->getJson('/api/public/profiles/deleted-race-pro')->assertNotFound();

    // BOTH the primary and the :stale twin must be evicted — clearing only the
    // primary lets the SWR fast path resurrect the stale pointer and loop the
    // 404 for the full stale TTL. (CCH-1)
    expect(Cache::get('handle.resolve:deleted-race-pro'))->toBeNull();
    expect(Cache::get('handle.resolve:deleted-race-pro:stale'))->toBeNull();
});

// ── #API-1: link/booking href scheme gate ────────────────────────────────
// A link block or booking-section settings.booking_url written outside the
// Form Request path (a seeder/import writer, or a row that predates the
// write-side validator) must not surface a non-http(s) scheme as a public
// href. UrlSafety::safeHref() gates SitepageDataResolverService::getLinks()
// and ::getBooking() — these prove the gate at the actual public wire.

it('NEGATIVE: a pool link with a javascript: url never becomes an action', function () {
    $pro = seedIndividualProfile('links-xss');
    $itemId = app(LinkPoolWriter::class)->add($pro->fresh(), 'https://placeholder.example/x', 'Malicious', enrich: false);
    // Forge the stored url past the writer's own validation — the emit-path
    // gate (UrlSafety in ActionCandidates) is the defence under test.
    DB::connection('pgsql')->table('content.f_link')->where('item_id', $itemId)->update(['url' => 'javascript:alert(1)']);

    $entries = collect($this->getJson('/api/public/profiles/links-xss')->assertOk()->json('data.actions.entries'));
    expect($entries->firstWhere('id', 'item:'.$itemId))->toBeNull();
    foreach ($entries as $e) {
        expect(str_starts_with($e['url'], '/') || str_starts_with($e['url'], 'https://') || str_starts_with($e['url'], 'http://'))->toBeTrue();
    }
});

it('POSITIVE CONTROL: a pool link with an https url survives verbatim', function () {
    $pro = seedIndividualProfile('links-safe');
    $itemId = app(LinkPoolWriter::class)->add($pro->fresh(), 'https://example.com/x', 'Safe', enrich: false);

    $entry = collect($this->getJson('/api/public/profiles/links-safe')->assertOk()->json('data.actions.entries'))->firstWhere('id', 'item:'.$itemId);
    expect($entry)->not->toBeNull();
    expect($entry['url'])->toBe('https://example.com/x');
});

it('does not synthesise a booking link when settings.booking_url has a non-http(s) scheme', function () {
    $pro = seedIndividualProfile('book-xss');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'site_id' => $siteId,
        'block_type' => 'booking',
        'block_group' => 'sections',
        'is_active' => 1,
        'is_enabled' => 1,
        'sort_order' => 0,
        'settings' => json_encode([
            'booking_url' => 'javascript:alert(1)',
            'platform' => 'calendly',
            'title' => 'Book a session',
        ]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // `profile.links` left the wire 2026-08-19; the synthesised booking row is
    // asserted at the resolver, which is what the actions layer consumes.
    $site = Site::query()->find($siteId);
    $resolver = app(SitepageDataResolverService::class);
    $booking = $resolver->getBooking($site, $resolver->loadSections($site));

    // sectionEnvelope() keeps state=live (the row exists, is active/enabled)
    // but the closure returns null once the url is gated — getLinks() only
    // synthesises the booking row when data is an array, so no row appears.
    expect($booking['data'])->toBeNull();
    $bookingLinks = array_values(array_filter($resolver->getLinks($site, $booking), fn (array $l) => $l['category'] === 'booking'));
    expect($bookingLinks)->toBe([]);
});

it('POSITIVE CONTROL: a booking_url with an https scheme is still synthesised', function () {
    $pro = seedIndividualProfile('book-safe');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'site_id' => $siteId,
        'block_type' => 'booking',
        'block_group' => 'sections',
        'is_active' => 1,
        'is_enabled' => 1,
        'sort_order' => 0,
        'settings' => json_encode([
            'booking_url' => 'https://calendly.com/me',
            'platform' => 'calendly',
            'title' => 'Book a session',
        ]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $site = Site::query()->find($siteId);
    $resolver = app(SitepageDataResolverService::class);
    $booking = $resolver->getBooking($site, $resolver->loadSections($site));

    $bookingLinks = array_values(array_filter($resolver->getLinks($site, $booking), fn (array $l) => $l['category'] === 'booking'));
    expect($bookingLinks)->toHaveCount(1);
    expect($bookingLinks[0]['url'])->toBe('https://calendly.com/me');
});
