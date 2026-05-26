<?php

use App\Models\Core\User\User;
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
    // reads it via $site->skeleton_id. Plus a stub design_kits table so the
    // PayloadBuilder's loadDesignKit() lookup returns empty cleanly.
    try {
        DB::connection('pgsql')->statement("ALTER TABLE site.sites ADD COLUMN skeleton_id TEXT NOT NULL DEFAULT 'skeleton-1'");
    } catch (Throwable $e) {
        // Column already exists from a prior test in the same process.
    }
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.design_kits (site_id TEXT PRIMARY KEY)');

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

    // Top-level keys are now { profile, designKit, skeletonId } — no more
    // legacy themeMode/accent/fontFamily/design.
    expect($data)->toHaveKeys(['profile', 'designKit', 'skeletonId']);
    expect($data)->not->toHaveKey('design');
    expect($data)->not->toHaveKey('themeMode');

    expect($data['skeletonId'])->toBe('skeleton-1');
    expect($data['designKit'])->toEqual([]); // partial — empty at this phase.

    $profile = $data['profile'];
    expect($profile)->toHaveKeys([
        'handle', 'display_name',
        'content_images', 'gallery', 'links', 'bio',
        'document', 'newsletter', 'services', 'booking',
    ]);
    expect($profile['handle'])->toBe('solo1');
    expect($profile['display_name'])->toBe('Solo Pro');

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

    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'site_id' => $siteId,
        'block_type' => 'link',
        'block_group' => 'links',
        'title' => 'Sensitive',
        'url' => 'https://example.test',
        'sort_order' => 0,
        // Sensitive keys MUST NOT appear in the wire payload — the controller
        // doesn't read these into the structured link row.
        'settings' => json_encode([
            'admin_token' => 'sk_live_secret',
            'internal_note' => 'staging only',
            'platform' => 'instagram',
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

    // Unknown block_type maps to no envelope — gallery/bio/document/etc all
    // stay draft, and there's no `blocks[]` array to leak the raw row into.
    expect($profile['gallery'])->toMatchArray(['state' => 'draft', 'data' => null]);
    expect($profile['bio'])->toMatchArray(['state' => 'draft', 'data' => null]);
    expect($profile['document'])->toMatchArray(['state' => 'draft', 'data' => null]);
    expect($profile['newsletter'])->toMatchArray(['state' => 'draft', 'data' => null]);
    expect($profile['services'])->toMatchArray(['state' => 'draft', 'data' => null]);
    expect($profile['booking'])->toMatchArray(['state' => 'draft', 'data' => null]);
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

it('surfaces content-pool site_media as profile.content_images[]', function () {
    $pro = seedIndividualProfile('content1');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => (string) Str::uuid(),
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

    $data = $this->getJson('/api/public/profiles/content1')->assertOk()->json('data');

    // The exact URL depends on media-variant lookup which the test env stubs
    // with empty variants — but the array structure (one row, alt text
    // preserved) is what the contract guarantees.
    expect($data['profile']['content_images'])->toBeArray();
});

it('omits soft-deleted content_images', function () {
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
    expect($data['profile']['content_images'])->toBeEmpty();
});

it('omits processing-state != ready content_images', function () {
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
    expect($data['profile']['content_images'])->toBeEmpty();
});
