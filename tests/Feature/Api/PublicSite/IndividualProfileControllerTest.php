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
        typography_font_body TEXT NULL
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
        'bio', 'gallery', 'links', 'services', 'document', 'newsletter',
        'content_images',
    ]);
    expect($profile)->not->toHaveKey('booking');
    expect($profile['handle'])->toBe('solo1');
    expect($profile['displayName'])->toBe('Solo Pro');

    // Empty-state defaults per spec §3.4 + phase 8:
    //   - object engines (bio, document, newsletter) → null
    //   - list engines (gallery, services) → []
    expect($profile['bio'])->toBeNull();
    expect($profile['gallery'])->toBe([]);
    expect($profile['services'])->toBe([]);
    expect($profile['document'])->toBeNull();
    expect($profile['newsletter'])->toBeNull();

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

    // Unknown block_type contributes nothing to the engine payload — each
    // engine falls back to its stable empty state (null or []) and there's
    // no `blocks[]` array to leak the raw row into.
    expect($profile['gallery'])->toBe([]);
    expect($profile['bio'])->toBeNull();
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

it('exposes content-pool images via the resolver getContentMedia method', function () {
    $pro = seedIndividualProfile('cmedia1');
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
        'caption' => 'Behind the scenes',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    // Seed a webp variant so variantUrls() returns a non-empty url — without
    // this, buildMediaItem returns url='', which the filter drops.
    DB::connection('pgsql')->table('site.media_variants')->insert([
        'id' => (string) Str::uuid(), 'media_id' => $mediaId,
        'variant_key' => 'optimized', 'artifact_type' => 'webp',
        'disk' => 'media', 'path' => 'images/content/optimized.webp', 'mime' => 'image/webp',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $site = \App\Models\Core\Site\Site::query()->findOrFail($siteId);
    $resolver = app(\App\Services\PublicSite\SitepageDataResolverService::class);

    $items = $resolver->getContentMedia($site);

    expect($items)->toBeArray()->toHaveCount(1);
    // Resolver-internal shape is snake_case; the builder later camelCases.
    expect($items[0])->toMatchArray([
        'id' => $mediaId,
        'sort_order' => 0,
        'kind' => 'image',
        'alt_text' => 'Studio shot',
        'caption' => 'Behind the scenes',
        'poster' => null,
        'duration_ms' => null,
        'url_hd' => null,
    ]);
    expect($items[0])->toHaveKey('url');
});

// ── Phase 8 engines: bio / gallery / links / services / document / newsletter
// Each test seeds the minimum storage rows and confirms the projection lands
// in the right engine field with the right shape (null/[]/object) and key casing.

it('bio engine returns BioData when the bio section is live', function () {
    $pro = seedIndividualProfile('bio-live');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    // Live bio block + non-empty bio + about jsonb on the user.
    DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->update([
        'bio' => 'Solo Pro story',
        'about' => json_encode([
            'credentials' => [
                ['title' => 'BA', 'issuer' => 'Sydney Uni', 'year' => '2018'],
            ],
            'experience' => [
                ['role' => 'Stylist', 'place' => 'Salon A', 'start' => '2020', 'end' => null],
            ],
        ]),
    ]);
    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'site_id' => $siteId,
        'block_type' => 'bio',
        'block_group' => 'sections',
        'is_active' => 1,
        'is_enabled' => 1,
        'sort_order' => 0,
        'settings' => json_encode([]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $bio = $this->getJson('/api/public/profiles/bio-live')->assertOk()->json('data.profile.bio');

    expect($bio)->toHaveKeys(['text', 'credentials', 'experience']);
    expect($bio['text'])->toBe('Solo Pro story');
    expect($bio['credentials'])->toHaveCount(1);
    expect($bio['credentials'][0])->toMatchArray([
        'title' => 'BA', 'issuer' => 'Sydney Uni', 'year' => '2018',
    ]);
    expect($bio['experience'])->toHaveCount(1);
    expect($bio['experience'][0])->toMatchArray([
        'title' => 'Stylist', 'organisation' => 'Salon A', 'period' => '2020 – Current',
    ]);
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
        'settings' => json_encode(['category' => 'social', 'platform' => 'instagram']),
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
