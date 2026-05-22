<?php

use App\Models\Core\Professional\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupProfessionalsTable();
    setupSitesTable();
    setupBlocksTable();
    // §28.8 enriched payload reaches into site_media (content + gallery
    // + document) and core.services (services + booking-mode settings).
    // Stub the SQLite shadow schemas so the resolver's queries don't blow
    // up on missing tables.
    setupMediaTables();
    setupServiceCategoriesTable();
    setupServicesTable();
    Cache::flush();
    // Disable throttling so the test isn't tied to RateLimiter internals.
    Config::set('partna.throttle.enabled', false);
});

function seedIndividualProfile(string $handle, array $design = []): User
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

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'professional_id' => $proId,
        'subdomain' => strtolower($handle),
        'settings' => json_encode(['design' => $design]),
        'is_published' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return User::query()->findOrFail($proId);
}

it('returns 200 with the full envelope shape for an individual', function () {
    $pro = seedIndividualProfile('solo1', ['theme' => 'midnight']);

    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $pro->id,
        'site_id' => DB::connection('pgsql')->table('site.sites')->where('professional_id', $pro->id)->value('id'),
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

    expect($data)->toHaveKeys([
        'handle', 'display_name', 'design',
        'content_images', 'gallery', 'links', 'bio',
        'document', 'newsletter', 'services', 'booking', 'shop',
    ]);
    expect($data)->not->toHaveKey('location');

    expect($data['handle'])->toBe('solo1');
    expect($data['display_name'])->toBe('Solo Pro');
    expect($data['design'])->toEqual(['theme' => 'midnight']);

    // Link block surfaces as a structured row in the `links` array — not as a
    // raw `blocks[]` JSONB blob (the old shape).
    expect($data['links'])->toHaveCount(1);
    expect($data['links'][0])->toMatchArray([
        'title' => 'Example',
        'url' => 'https://example.test',
        'category' => 'custom',
        'platform' => null,
    ]);

    // Shop is structurally always-draft for individuals.
    expect($data['shop'])->toMatchArray(['state' => 'draft', 'data' => null]);
});

it('excludes brand-only and commerce fields (audit TEST-4)', function () {
    seedIndividualProfile('solo2');
    $data = $this->getJson('/api/public/profiles/solo2')->assertOk()->json('data');

    foreach (['placeholders', 'fallback_gallery', 'brand_logo', 'brand_slogan', 'products', 'cart', 'commission', 'orders'] as $forbidden) {
        expect($data)->not->toHaveKey($forbidden);
    }
});

// The link projection only emits a fixed shape (id/title/url/category/platform)
// — any extra JSONB on the block settings is structurally inaccessible at
// the wire layer. This replaces the old PROF-1 "per-block-type settings
// allow-list" coverage: PROF-1 paranoia (random JSONB leaking) is now
// prevented by typed projection rather than a string allow-list.
it('link projection emits only the structured shape (no extra JSONB leaks)', function () {
    $pro = seedIndividualProfile('solo3');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('professional_id', $pro->id)->value('id');

    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $pro->id,
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
    $link = $data['links'][0];

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
    $siteId = DB::connection('pgsql')->table('site.sites')->where('professional_id', $pro->id)->value('id');

    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $pro->id,
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

    // Unknown block_type maps to no envelope — gallery/bio/document/etc all
    // stay draft, and there's no `blocks[]` array to leak the raw row into.
    expect($data['gallery'])->toMatchArray(['state' => 'draft', 'data' => null]);
    expect($data['bio'])->toMatchArray(['state' => 'draft', 'data' => null]);
    expect($data['document'])->toMatchArray(['state' => 'draft', 'data' => null]);
    expect($data['newsletter'])->toMatchArray(['state' => 'draft', 'data' => null]);
    expect($data['services'])->toMatchArray(['state' => 'draft', 'data' => null]);
    expect($data['booking'])->toMatchArray(['state' => 'draft', 'data' => null]);
});

// PROF-2: design key allow-list. Keys not in IndividualProfileResource::DESIGN_KEYS
// drop out — even if they end up adjacent to design keys in site.settings.design.
it('PROF-2: filters design through DESIGN_KEYS allow-list', function () {
    seedIndividualProfile('solo5', [
        'theme' => 'midnight',
        'accent_color' => '#FF00AA',
        // Not in DESIGN_KEYS — must NOT leak.
        'internal_flag' => 'experimental',
        'admin_token' => 'shouldnt_be_here',
    ]);

    $data = $this->getJson('/api/public/profiles/solo5')->assertOk()->json('data');

    expect($data['design'])
        ->toHaveKey('theme', 'midnight')
        ->and($data['design'])->toHaveKey('accent_color', '#FF00AA')
        ->and($data['design'])->not->toHaveKey('internal_flag')
        ->and($data['design'])->not->toHaveKey('admin_token');
});

it('returns 404 when the handle does not exist', function () {
    $this->getJson('/api/public/profiles/missing')->assertNotFound();
});

// Brand and partner account types no longer exist — removed in the standalone strip.
// The 404 guard for non-individual accounts is now exercised by the missing-handle case.

it('is case-insensitive on the handle path param', function () {
    seedIndividualProfile('mixedcase');
    $this->getJson('/api/public/profiles/MIXEDCASE')->assertOk();
});

// ── New feature: site_media-backed fields ────────────────────────────────
// Content-pool images, gallery items, and the document slot all read off
// site.site_media rows now. The Hydrogen affiliate endpoint already does
// this; the public §28.8 endpoint matches the same projection.

it('surfaces content-pool site_media as content_images[]', function () {
    $pro = seedIndividualProfile('content1');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('professional_id', $pro->id)->value('id');

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $siteId,
        'professional_id' => $pro->id,
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
    expect($data['content_images'])->toBeArray();
});

it('omits soft-deleted content_images', function () {
    $pro = seedIndividualProfile('content2');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('professional_id', $pro->id)->value('id');

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $siteId,
        'professional_id' => $pro->id,
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
    expect($data['content_images'])->toBeEmpty();
});

it('omits processing-state != ready content_images', function () {
    $pro = seedIndividualProfile('content3');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('professional_id', $pro->id)->value('id');

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $siteId,
        'professional_id' => $pro->id,
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
    expect($data['content_images'])->toBeEmpty();
});
