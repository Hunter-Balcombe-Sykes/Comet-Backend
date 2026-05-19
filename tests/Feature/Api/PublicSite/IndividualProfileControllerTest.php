<?php

use App\Models\Core\Professional\Professional;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupProfessionalsTable();
    setupSitesTable();
    setupBlocksTable();
    Cache::flush();
    // Disable throttling so the test isn't tied to RateLimiter internals.
    Config::set('partna.throttle.enabled', false);
});

function seedIndividualProfile(string $handle, string $accountType = 'individual', array $design = []): Professional
{
    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId,
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => 'Solo Pro',
        'bio' => 'Hello world',
        'professional_type' => $accountType === 'brand' ? 'brand' : 'affiliate',
        'account_type' => $accountType,
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

    return Professional::query()->findOrFail($proId);
}

it('returns 200 with handle/display_name/bio/location/design/blocks for an individual', function () {
    $pro = seedIndividualProfile('solo1', 'individual', ['theme' => 'midnight']);

    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $pro->id,
        'block_type' => 'link',
        'sort_order' => 1,
        'settings' => json_encode(['url' => 'https://example.test']),
        'is_active' => 1,
        'is_enabled' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $res = $this->getJson('/api/public/profiles/solo1')->assertOk();

    $data = $res->json('data');
    expect($data)
        ->toHaveKeys(['handle', 'display_name', 'bio', 'location', 'design', 'blocks'])
        ->and($data['handle'])->toBe('solo1')
        ->and($data['display_name'])->toBe('Solo Pro')
        ->and($data['bio'])->toBe('Hello world')
        ->and($data['location'])->toEqual(['city' => 'Sydney', 'state' => 'NSW', 'country' => 'AU'])
        ->and($data['design'])->toEqual(['theme' => 'midnight'])
        ->and($data['blocks'])->toHaveCount(1)
        ->and($data['blocks'][0]['block_type'])->toBe('link');
});

it('excludes brand-only and commerce fields (audit TEST-4)', function () {
    seedIndividualProfile('solo2');
    $data = $this->getJson('/api/public/profiles/solo2')->assertOk()->json('data');

    foreach (['placeholders', 'fallback_gallery', 'brand_logo', 'brand_slogan', 'products', 'cart', 'commission', 'orders'] as $forbidden) {
        expect($data)->not->toHaveKey($forbidden);
    }
});

it('returns 404 when the handle does not exist', function () {
    $this->getJson('/api/public/profiles/missing')->assertNotFound();
});

it('returns 404 (not 403) when the handle belongs to a brand', function () {
    seedIndividualProfile('brand1', 'brand');
    $this->getJson('/api/public/profiles/brand1')->assertNotFound();
});

it('returns 404 (not 403) when the handle belongs to a partner', function () {
    seedIndividualProfile('partner1', 'partner');
    $this->getJson('/api/public/profiles/partner1')->assertNotFound();
});

it('is case-insensitive on the handle path param', function () {
    seedIndividualProfile('mixedcase');
    $this->getJson('/api/public/profiles/MIXEDCASE')->assertOk();
});
