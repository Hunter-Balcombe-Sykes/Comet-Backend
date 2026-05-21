<?php

use App\Jobs\Shopify\SyncShopifyBrandDesignJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

// End-to-end coverage for SyncShopifyBrandDesignJob — the job that runs after
// every Shopify shop/update webhook, manual resync, and onboarding install
// step. The previous breakage (a missing class file) made it past CI because
// nothing exercised the dispatch path; these tests close that gap.
//
// We dispatchSync() to bypass the queue layer (so ShouldBeUnique / unique
// locks aren't relevant here) and run the handler in-process against fake
// Shopify endpoints + a fake media disk.

beforeEach(function () {
    $conn = DB::connection('pgsql');
    foreach (['core', 'site', 'brand'] as $schema) {
        try {
            $conn->statement("ATTACH DATABASE ':memory:' AS {$schema}");
        } catch (\Throwable) {
        }
    }

    setupSitesTable();
    setupMediaTables();

    $conn->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        provider TEXT NOT NULL,
        external_account_id TEXT,
        access_token TEXT,
        refresh_token TEXT,
        storefront_token TEXT,
        expires_at TEXT,
        catalog_latest_time TEXT,
        last_catalog_sync_at TEXT,
        last_catalog_sync_error TEXT,
        provider_metadata TEXT,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');

    // brand_profiles table needed because the job dual-writes slogan +
    // short_description from the Storefront Brand object onto BrandProfile
    // per PARTNA-SIGNUP-OVERHAUL-PLAN.md §8.5.
    $conn->statement('CREATE TABLE IF NOT EXISTS brand.brand_profiles (
        id TEXT PRIMARY KEY,
        professional_id TEXT UNIQUE,
        slogan TEXT,
        short_description TEXT,
        locale TEXT,
        shopify_plan TEXT,
        money_format TEXT,
        setup_complete INTEGER DEFAULT 0,
        signup_code TEXT,
        signup_code_active INTEGER NOT NULL DEFAULT 1,
        signup_code_rotated_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    // The job resolves its disk via mediaDiskName(), which defaults to 'media'
    // when sidest.media_disk is unset and the default disk isn't s3. Fake
    // 'media' so the logo-mirror writes land somewhere we can assert on.
    Storage::fake('media');
});

function seedBrandDesignJobFixtures(array $existingDesign = []): ProfessionalIntegration
{
    $proId = 'pro-bdjob-1';
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => 'site-bdjob-1',
        'professional_id' => $proId,
        'subdomain' => 'bdjob',
        'settings' => json_encode(['design' => $existingDesign]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $integration = new ProfessionalIntegration([
        'professional_id' => $proId,
        'provider' => 'shopify',
        'external_account_id' => 'bdjob.myshopify.com',
        'access_token' => 'shpat_test_token',
        'storefront_token' => 'shpat_test_storefront_token',
        'provider_metadata' => ['shop_domain' => 'bdjob.myshopify.com'],
    ]);
    $integration->id = 'int-bdjob-1';
    $integration->save();

    return $integration;
}

// Single Http::fake() call covering every endpoint the job hits:
//   1. Storefront API graphql.json — brand query (shop.brand only exists here).
//   2. Admin API graphql.json — themes query + metafieldsSet mutation.
//   3. Admin API assets.json (REST) — settings_data.json blob.
//   4. cdn.shopify.com/* — logo bytes.
function fakeBrandDesignJobShopify(?array $brandColors = null): void
{
    $colors = $brandColors ?? [
        'primary' => [['background' => '#ababab', 'foreground' => '#121212']],
        'secondary' => [['background' => '#cd00ef', 'foreground' => '#ffffff']],
    ];

    Http::fake([
        // Storefront API — shop.brand lives here.
        'bdjob.myshopify.com/api/*/graphql.json' => function ($request) use ($colors) {
            $query = $request->data()['query'] ?? '';

            if (str_contains($query, 'shop {')) {
                return Http::response([
                    'data' => [
                        'shop' => [
                            'id' => 'gid://shopify/Shop/777',
                            'brand' => [
                                'slogan' => 'Job test slogan',
                                'logo' => ['image' => ['url' => 'https://cdn.shopify.com/s/files/full.png']],
                                'squareLogo' => ['image' => ['url' => 'https://cdn.shopify.com/s/files/square.png']],
                                'colors' => $colors,
                            ],
                        ],
                    ],
                ]);
            }

            return Http::response(['errors' => [['message' => 'unexpected query']]], 500);
        },
        // Admin API — themes query, metafieldsSet mutation.
        'bdjob.myshopify.com/admin/api/*/graphql.json' => function ($request) {
            $query = $request->data()['query'] ?? '';

            if (str_contains($query, 'metafieldsSet')) {
                return Http::response([
                    'data' => [
                        'metafieldsSet' => [
                            'metafields' => [['id' => 'gid://shopify/Metafield/1', 'key' => 'brand_design']],
                            'userErrors' => [],
                        ],
                    ],
                ]);
            }
            if (str_contains($query, 'themes(')) {
                return Http::response([
                    'data' => [
                        'themes' => [
                            'nodes' => [
                                ['id' => 'gid://shopify/OnlineStoreTheme/77', 'name' => 'Dawn', 'role' => 'MAIN'],
                            ],
                        ],
                    ],
                ]);
            }

            return Http::response(['errors' => [['message' => 'unexpected query']]], 500);
        },
        'bdjob.myshopify.com/admin/api/*/themes/*/assets.json*' => Http::response([
            'asset' => [
                'key' => 'config/settings_data.json',
                'value' => json_encode(['current' => ['buttons_radius' => 8]]),
            ],
        ]),
        // Minimal valid 1×1 PNG — finfo::buffer() requires a complete PNG structure
        // (signature + IHDR chunk), not just the magic-byte prefix.
        'cdn.shopify.com/*' => Http::response(
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAAAAAA6fptVAAAACklEQVQI12NgAAAAAgAB4iG8MwAAAABJRU5ErkJggg=='),
            200,
            ['Content-Type' => 'image/png']
        ),
    ]);
}

it('writes brand design enums into site.settings.design and logos into site_media end-to-end', function () {
    $integration = seedBrandDesignJobFixtures();
    fakeBrandDesignJobShopify();

    SyncShopifyBrandDesignJob::dispatchSync($integration->id);

    $site = Site::query()->where('professional_id', 'pro-bdjob-1')->first();
    $design = $site->settings['design'] ?? [];

    // Design tokens live on settings.design — those aren't media.
    // Post-consolidation the importer stores only accent + theme_mode (background/text
    // are derived from theme_mode at render time; border is no longer stored).
    expect($design['colors']['accent'])->toBe('#cd00ef');        // secondary[0].background
    expect(array_key_exists('background', $design['colors'] ?? []))->toBeFalse();
    expect(array_key_exists('text', $design['colors'] ?? []))->toBeFalse();
    expect(array_key_exists('border', $design['colors'] ?? []))->toBeFalse();
    expect($design['theme_mode'])->toBe('dark');                 // inferred from primary #ababab (luminance < 0.5)
    expect($design['corner_radius'])->toBe('default');           // buttons_radius=8 → default bucket
    expect($design['slogan'])->toBe('Job test slogan');

    // Logos now live in site.site_media as pool=design / purpose=logo_full|logo_square.
    // The job no longer writes settings.design.logo — that key shouldn't exist.
    expect(array_key_exists('logo', $design))->toBeFalse();

    $logoRows = SiteMedia::query()
        ->where('site_id', $site->id)
        ->where('pool', SiteMedia::POOL_DESIGN)
        ->whereIn('purpose', [SiteMedia::PURPOSE_LOGO_FULL, SiteMedia::PURPOSE_LOGO_SQUARE])
        ->whereNull('deleted_at')
        ->get();

    expect($logoRows)->toHaveCount(2);
    expect($logoRows->pluck('purpose')->sort()->values()->all())
        ->toBe([SiteMedia::PURPOSE_LOGO_FULL, SiteMedia::PURPOSE_LOGO_SQUARE]);
});

it('preserves an existing user accent colour when Shopify has no accent', function () {
    // Pre-seed a user-edited accent. Shopify will return only the primary
    // colour pair and an empty secondary array — the job's leave-if-absent
    // merge must keep the user's accent intact.
    $integration = seedBrandDesignJobFixtures([
        'colors' => [
            'accent' => '#user-set-purple',
        ],
    ]);

    fakeBrandDesignJobShopify([
        'primary' => [['background' => '#ababab', 'foreground' => '#121212']],
        'secondary' => [],
    ]);

    SyncShopifyBrandDesignJob::dispatchSync($integration->id);

    $site = Site::query()->where('professional_id', 'pro-bdjob-1')->first();
    $design = $site->settings['design'];

    expect($design['theme_mode'])->toBe('dark');                      // overwritten by Shopify (inferred from #ababab)
    expect($design['colors']['accent'])->toBe('#user-set-purple');    // preserved (Shopify had nothing in secondary)
});

it('is a no-op when the integration row does not exist', function () {
    Http::preventStrayRequests();

    // No rows in core.professional_integrations and no HTTP fakes — the job
    // should query, find nothing, and return cleanly.
    SyncShopifyBrandDesignJob::dispatchSync('does-not-exist');

    expect(true)->toBeTrue();
});

it('throws when the Storefront API is down so the job backoff() retries', function () {
    // Master Pattern 17 / DB-D#SCALE-1: prior behaviour was to swallow Storefront
    // failures and mark the integration synced anyway — installing a no-logo brand.
    // Now the exception propagates so the queue's backoff() chain retries instead.
    $integration = seedBrandDesignJobFixtures();

    Http::fake([
        'bdjob.myshopify.com/*' => Http::response(['errors' => [['message' => 'boom']]], 500),
    ]);

    expect(fn () => SyncShopifyBrandDesignJob::dispatchSync($integration->id))
        ->toThrow(\App\Exceptions\Shopify\ShopifyTransportException::class);

    // Integration was NOT marked 'synced' — the next retry gets another chance
    // to import the real brand data instead of locking in an empty record.
    $integration->refresh();
    expect($integration->provider_metadata['brand_design_state'] ?? null)->not->toBe('synced');
});
