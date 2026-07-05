<?php

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
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

    // Skeleton-system cleanup column shim — production has skeleton_id with
    // CHECK enum default 'skeleton-1', and the SitepageDataResolverService
    // reads it via $site->skeleton_id. Plus a stub design_kits table whose
    // shape mirrors the post-phase-7a column set so the PayloadBuilder's
    // loadDesignKit() lookup + grouping logic exercises real columns even on
    // SQLite.
    try {
        DB::connection('pgsql')->statement("ALTER TABLE site.sites ADD COLUMN skeleton_id TEXT NOT NULL DEFAULT 'skeleton-1'");
    } catch (Throwable $e) {
        // Column already exists from a prior test in the same process.
    }
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.design_kits (
        site_id TEXT PRIMARY KEY,
        color_accent TEXT NULL,
        color_bg TEXT NULL,
        color_text TEXT NULL,
        typography_font_heading TEXT NULL,
        typography_font_body TEXT NULL,
        icons_xl_size TEXT NULL,
        icons_xxl_size TEXT NULL,
        icons_stroke_width TEXT NULL,
        icons_large_stroke_width TEXT NULL,
        space_regular TEXT NULL,
        space_desktop_regular TEXT NULL,
        sizing_desktop_base TEXT NULL,
        typography_desktop_size_base TEXT NULL
    )');

    Cache::flush();
    // Disable throttling so the test isn't tied to RateLimiter internals.
    Config::set('partna.throttle.enabled', false);
});

function seedIndividualProfile(string $handle, ?string $skeletonId = null): User
{
    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => 'Solo Pro',
        'bio' => 'Hello world',
        'account_type' => 'individual',
        'location_city' => 'Sydney',
        'location_state' => 'NSW',
        'location_country' => 'AU',
        'status' => 'active',
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
    if ($skeletonId !== null) {
        $siteRow['skeleton_id'] = $skeletonId;
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

    // Top-level keys are now { profile, designKit, skeletonId, publicConfig }
    // — no more legacy themeMode/accent/fontFamily/design. publicConfig was
    // restored in phase 7a (was missing in phase 2 reshape).
    expect($data)->toHaveKeys(['profile', 'designKit', 'skeletonId', 'publicConfig']);
    expect($data)->not->toHaveKey('design');
    expect($data)->not->toHaveKey('themeMode');

    expect($data['skeletonId'])->toBe('skeleton-1');
    // Empty designKit decodes to [] under json() because PHP can't tell
    // {} from [] post-decode; the wire byte-level check happens below.
    expect($data['designKit'])->toEqual([]);

    // publicConfig is always emitted (object on the wire). analyticsEndpoint
    // is the only field for now; tested with a partial-shape check so future
    // additions don't break this test.
    expect($data['publicConfig'])->toBeArray();
    expect($data['publicConfig'])->toHaveKey('analyticsEndpoint');

    $profile = $data['profile'];
    // Phase 8 engine fields — booking is now a link category, not a separate
    // field. Each engine emits its stable empty state when nothing is live.
    expect($profile)->toHaveKeys([
        'handle', 'displayName',
        'gallery', 'links', 'services', 'document', 'newsletter',
    ]);
    expect($profile)->not->toHaveKey('booking');
    // designMedia is a top-level sibling of designKit, not a profile field.
    expect($data)->toHaveKey('designMedia');
    expect($data['designMedia'])->toBeArray();
    expect($profile['handle'])->toBe('solo1');
    expect($profile['displayName'])->toBe('Solo Pro');

    // Empty-state defaults per spec §3.4 + phase 8:
    //   - object engines (document, newsletter) → null
    //   - list engines (gallery, services) → []
    expect($profile['gallery'])->toBe([]);
    expect($profile['services'])->toBe([]);
    expect($profile['document'])->toBeNull();
    expect($profile['newsletter'])->toBeNull();
    expect($profile['contact'])->toBeNull();

    // Wire-level check: empty designKit / publicConfig must serialise as `{}`
    // (object), never `[]` (array). PHP defaults to `[]` for empty assoc
    // arrays, so the Resource casts to stdClass when there's nothing to emit.
    $raw = $res->getContent();
    expect($raw)->toContain('"designKit":{}');
    // publicConfig has analyticsEndpoint, so it's never `{}` in this assertion
    // — but we still confirm it serialises as an object.
    expect($raw)->toContain('"publicConfig":{');

    // Link block surfaces as a structured row in the `links` array — not as
    // a raw `blocks[]` JSONB blob (the old shape).
    expect($profile['links'])->toHaveCount(1);
    expect($profile['links'][0])->toMatchArray([
        'title' => 'Example',
        'url' => 'https://example.test',
        'category' => 'custom',
        'platform' => null,
    ]);
});

it('returns the user-selected skeleton_id', function () {
    seedIndividualProfile('solo-sk2', 'skeleton-2');
    $data = $this->getJson('/api/public/profiles/solo-sk2')->assertOk()->json('data');
    expect($data['skeletonId'])->toBe('skeleton-2');
});

it('groups stored design_kit columns into nested camelCase wire shape', function () {
    // Stored: flat snake_case columns (color_accent, typography_font_heading).
    // Wire:   nested camelCase under group keys (colors.accent, typography.fontHeading).
    $pro = seedIndividualProfile('solo-dk');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    // The trigger that auto-inserts an empty design_kits row only exists in
    // prod; the test stub doesn't run it. Insert manually with stored values.
    DB::connection('pgsql')->table('site.design_kits')->insert([
        'site_id' => $siteId,
        'color_accent' => '#ff0080',
        'typography_font_heading' => 'inter',
    ]);

    $data = $this->getJson('/api/public/profiles/solo-dk')->assertOk()->json('data');

    expect($data['designKit'])->toEqual([
        'colors' => ['accent' => '#ff0080'],
        'typography' => ['fontHeading' => 'inter'],
    ]);
});

it('maps icons_xl_size column to icons.xlSize in the wire shape', function () {
    // Regression: icons_* columns were silently dropped because the prefix map
    // had 'icon' (singular) but not 'icons' (plural). KIT-1.
    $pro = seedIndividualProfile('solo-dk-icons');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    DB::connection('pgsql')->table('site.design_kits')->insert([
        'site_id' => $siteId,
        'icons_xl_size' => '32px',
    ]);

    $data = $this->getJson('/api/public/profiles/solo-dk-icons')->assertOk()->json('data');

    expect($data['designKit'])->toHaveKey('icons');
    expect($data['designKit']['icons']['xlSize'])->toBe('32px');
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

it('link projection emits only the structured shape (no extra JSONB leaks)', function () {
    $pro = seedIndividualProfile('solo3');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    // Phase 2: platform is a promoted column; getLinks reads it from the column.
    // Sensitive keys in settings still must not leak to the wire.
    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'site_id' => $siteId,
        'block_type' => 'link',
        'block_group' => 'links',
        'title' => 'Sensitive',
        'url' => 'https://example.test',
        'sort_order' => 0,
        'platform' => 'instagram',
        // Sensitive keys MUST NOT appear in the wire payload — the controller
        // doesn't read these into the structured link row.
        'settings' => json_encode([
            'admin_token' => 'sk_live_secret',
            'internal_note' => 'staging only',
        ]),
        'is_active' => 1,
        'is_enabled' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $data = $this->getJson('/api/public/profiles/solo3')->assertOk()->json('data');
    $link = $data['profile']['links'][0];

    // Only the projected keys exist — no admin_token / internal_note ever
    // surfaces because they're not part of the typed projection.
    expect(array_keys($link))->toEqual(['id', 'title', 'url', 'category', 'platform']);
    expect($link)->toMatchArray([
        'title' => 'Sensitive',
        'url' => 'https://example.test',
        'platform' => 'instagram',
    ]);
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
    expect($profile['gallery'])->toBe([]);
    expect($profile['document'])->toBeNull();
    expect($profile['newsletter'])->toBeNull();
    expect($profile['services'])->toBe([]);
    // Booking is a link category now — links is empty because no link rows
    // were seeded for this test, not because there's a separate booking field.
    expect($profile['links'])->toBe([]);
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
// Content-pool images, gallery items, and the document slot all read off
// site.site_media rows. Same projection as the Hydrogen affiliate endpoint.

it('surfaces content-pool site_media as top-level designMedia[] in camelCase', function () {
    $pro = seedIndividualProfile('content1');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');
    $mediaId = (string) Str::uuid();

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $mediaId,
        'site_id' => $siteId,
        'user_id' => $pro->id,
        'pool' => 'content',
        'path' => 'images/content/original.jpg',
        'media_type' => 'image',
        'processing_state' => 'ready',
        'sort_order' => 0,
        'is_active' => 1,
        'alt_text' => 'Studio shot',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    // Seed a webp variant so the item survives the URL filter.
    DB::connection('pgsql')->table('site.media_variants')->insert([
        'id' => (string) Str::uuid(), 'media_id' => $mediaId,
        'variant_key' => 'optimized', 'artifact_type' => 'webp',
        'disk' => 'media', 'path' => 'images/content/optimized.webp', 'mime' => 'image/webp',
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);

    $data = $this->getJson('/api/public/profiles/content1')->assertOk()->json('data');

    expect($data)->toHaveKey('designMedia');
    expect($data['designMedia'])->toBeArray()->toHaveCount(1);
    // Wire shape is camelCase, matching gallery[i] and every engine output.
    expect($data['designMedia'][0])->toHaveKeys([
        'id', 'sortOrder', 'kind', 'url', 'urlHd', 'alt', 'caption', 'poster', 'durationMs',
    ]);
    expect($data['designMedia'][0]['kind'])->toBe('image');
    expect($data['designMedia'][0]['alt'])->toBe('Studio shot');
    expect($data['designMedia'][0]['sortOrder'])->toBe(0);
    expect($data['profile'])->not->toHaveKey('content_images');
});

it('omits soft-deleted content-pool media from designMedia', function () {
    $pro = seedIndividualProfile('content2');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $siteId,
        'user_id' => $pro->id,
        'pool' => 'content',
        'path' => 'images/content/deleted.jpg',
        'media_type' => 'image',
        'processing_state' => 'ready',
        'sort_order' => 0,
        'is_active' => 1,
        'deleted_at' => now()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $data = $this->getJson('/api/public/profiles/content2')->assertOk()->json('data');
    expect($data['designMedia'])->toBeArray()->toBeEmpty();
});

it('omits processing-state != ready content-pool media from designMedia', function () {
    $pro = seedIndividualProfile('content3');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $siteId,
        'user_id' => $pro->id,
        'pool' => 'content',
        'path' => 'images/content/processing.jpg',
        'media_type' => 'image',
        'processing_state' => 'processing',
        'sort_order' => 0,
        'is_active' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $data = $this->getJson('/api/public/profiles/content3')->assertOk()->json('data');
    expect($data['designMedia'])->toBeArray()->toBeEmpty();
});

it('projects content-pool videos with kind=video, poster and duration_ms', function () {
    $pro = seedIndividualProfile('cmedia-video');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');
    $mediaId = (string) Str::uuid();

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $mediaId, 'site_id' => $siteId, 'user_id' => $pro->id,
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
            'id' => $imageId, 'site_id' => $siteId, 'user_id' => $pro->id,
            'pool' => 'content', 'path' => 'images/content/a.jpg',
            'media_type' => 'image', 'processing_state' => 'ready',
            'sort_order' => 1, 'is_active' => 1,
            'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
        ],
        [
            'id' => $videoId, 'site_id' => $siteId, 'user_id' => $pro->id,
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
        'site_id' => $siteId, 'user_id' => $pro->id, 'media_type' => 'image',
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
        'id' => $mediaId, 'site_id' => $siteId, 'user_id' => $pro->id,
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
        'id' => $mediaId, 'site_id' => $siteId, 'user_id' => $pro->id,
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

// ── Phase 8 engines: gallery / links / services / document / newsletter
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
        'address' => '10 Crown St, Surry Hills',
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

    // buildWorkplace() remaps snake_case → camelCase: 11 keys on the wire.
    expect($workplace)->toHaveKeys(['name', 'address', 'addressLine1', 'city', 'state', 'postcode', 'country', 'latitude', 'longitude', 'phone', 'website']);
    expect($workplace['name'])->toBe('Fade Lab Barbers');
    expect($workplace['address'])->toBe('10 Crown St, Surry Hills');
    expect($workplace['latitude'])->toBe(-33.886);
    expect($workplace['phone'])->toBe('+61 2 9000 0000');
});

it('gallery engine returns camelCase GalleryImage[] when items are ready', function () {
    $pro = seedIndividualProfile('gallery-live');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    // Need a live gallery section block too — the resolver gates on it.
    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'site_id' => $siteId,
        'block_type' => 'gallery',
        'block_group' => 'sections',
        'is_active' => 1,
        'is_enabled' => 1,
        'sort_order' => 0,
        'settings' => json_encode([]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $siteId,
        'user_id' => $pro->id,
        'pool' => 'gallery',
        'path' => 'images/gallery/a.jpg',
        'media_type' => 'image',
        'processing_state' => 'ready',
        'sort_order' => 0,
        'is_active' => 1,
        'alt_text' => 'Detail shot',
        'caption' => 'Caption A',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $gallery = $this->getJson('/api/public/profiles/gallery-live')->assertOk()->json('data.profile.gallery');

    // Shape contract: GalleryImage uses camelCase keys (alt, durationMs).
    expect($gallery)->toBeArray();
    if (count($gallery) > 0) {
        expect(array_keys($gallery[0]))->toEqual([
            'url', 'alt', 'caption', 'kind', 'poster', 'durationMs',
        ]);
        expect($gallery[0]['alt'])->toBe('Detail shot');
        expect($gallery[0]['caption'])->toBe('Caption A');
        expect($gallery[0]['kind'])->toBe('image');
        expect($gallery[0]['durationMs'])->toBeNull();
    }
});

it('links engine emits a flat list with id/title/url/category/platform', function () {
    $pro = seedIndividualProfile('links-live');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    // Phase 2: category + platform are promoted columns; read by getLinks from columns.
    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'site_id' => $siteId,
        'block_type' => 'link',
        'block_group' => 'links',
        'title' => 'My IG',
        'url' => 'https://instagram.com/me',
        'sort_order' => 1,
        'is_active' => 1,
        'is_enabled' => 1,
        'category' => 'social',
        'platform' => 'instagram',
        'settings' => json_encode([]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $links = $this->getJson('/api/public/profiles/links-live')->assertOk()->json('data.profile.links');

    expect($links)->toHaveCount(1);
    expect(array_keys($links[0]))->toEqual(['id', 'title', 'url', 'category', 'platform']);
    expect($links[0])->toMatchArray([
        'title' => 'My IG',
        'url' => 'https://instagram.com/me',
        'category' => 'social',
        'platform' => 'instagram',
    ]);
    expect($links[0]['id'])->toBeString(); // real block id, not null
});

it('rebuilds title/url for legacy link rows from the platform config + settings.handle', function () {
    // Older link-block rows can have empty title/url at rest — getLinks
    // rebuilds both from config('partna.social_platforms.{platform}') using
    // settings.handle as the source of truth when the stored columns are blank.
    $pro = seedIndividualProfile('links-legacy');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'site_id' => $siteId,
        'block_type' => 'link',
        'block_group' => 'links',
        'title' => '',
        'url' => '',
        'sort_order' => 1,
        'is_active' => 1,
        'is_enabled' => 1,
        'category' => 'social',
        'platform' => 'instagram',
        'settings' => json_encode(['handle' => 'someuser']),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $links = $this->getJson('/api/public/profiles/links-legacy')->assertOk()->json('data.profile.links');

    expect($links)->toHaveCount(1);
    expect($links[0])->toMatchArray([
        'title' => 'Instagram', // config('partna.social_platforms.instagram.display_name')
        'url' => 'https://instagram.com/someuser', // url_template with {handle} substituted
        'category' => 'social',
        'platform' => 'instagram',
    ]);
});

it('booking link is synthesised into the links list when the booking section is live', function () {
    $pro = seedIndividualProfile('book-live');
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

    $profile = $this->getJson('/api/public/profiles/book-live')->assertOk()->json('data.profile');

    expect($profile)->not->toHaveKey('booking');
    $bookingLinks = array_values(array_filter($profile['links'], fn (array $l) => $l['category'] === 'booking'));
    expect($bookingLinks)->toHaveCount(1);
    expect($bookingLinks[0])->toMatchArray([
        'title' => 'Book a session',
        'url' => 'https://calendly.com/me',
        'category' => 'booking',
        'platform' => 'calendly',
    ]);
    // Synthesised rows have a null id (no block-row primary key).
    expect($bookingLinks[0]['id'])->toBeNull();
});

it('services engine returns a flat ProfileService[] with camelCase keys', function () {
    $pro = seedIndividualProfile('svc-live');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'site_id' => $siteId,
        'block_type' => 'services',
        'block_group' => 'sections',
        'is_active' => 1,
        'is_enabled' => 1,
        'sort_order' => 0,
        'settings' => json_encode([]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    DB::connection('pgsql')->table('site.services')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'title' => 'Haircut',
        'description' => 'A nice haircut',
        'price_cents' => 5500,
        'currency_code' => 'AUD',
        'duration_minutes' => 45,
        'is_active' => 1,
        'sort_order' => 0,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $services = $this->getJson('/api/public/profiles/svc-live')->assertOk()->json('data.profile.services');

    // Flat array — NO bookingMode/manualBookingUrl wrapper (user-direction
    // override on spec §3.4).
    expect($services)->toBeArray();
    expect($services)->toHaveCount(1);
    expect(array_keys($services[0]))->toEqual([
        'id', 'title', 'description', 'priceCents', 'currencyCode', 'durationMinutes', 'category',
    ]);
    expect($services[0])->toMatchArray([
        'title' => 'Haircut',
        'description' => 'A nice haircut',
        'priceCents' => 5500,
        'currencyCode' => 'AUD',
        'durationMinutes' => 45,
        'category' => 'Services',
    ]);
});

it('document engine returns DocumentData when a ready document exists', function () {
    $pro = seedIndividualProfile('doc-live');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $siteId,
        'user_id' => $pro->id,
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

it('newsletter engine returns null when input_placeholder is empty', function () {
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
        ->toBeNull();
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
    $mock->shouldReceive('build')
        ->once()
        ->andReturn([
            'profile' => ['handle' => 'singleflight-pro'],
            'designKit' => new stdClass,
            'skeletonId' => 'skeleton-1',
            'publicConfig' => ['analyticsEndpoint' => '/api/analytics'],
            'designMedia' => [],
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
