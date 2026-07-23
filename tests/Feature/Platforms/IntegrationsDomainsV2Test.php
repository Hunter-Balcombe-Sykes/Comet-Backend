<?php

use App\Jobs\Platforms\RefreshConnectionJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    config([
        'services.cloudflare.zone_id' => 'zone-1',
        'services.cloudflare.saas_api_token' => 'cf-token',
        'services.cloudflare.saas_cname_target' => 'cname.partna.au',
    ]);
});

function dv2User(string $h): User
{
    return User::create([
        'handle' => $h, 'handle_lc' => strtolower($h), 'display_name' => ucfirst($h),
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

/** @return array{0: User, 1: Site} */
function dv2UserWithSite(string $h): array
{
    $user = dv2User($h);
    $site = new Site(['subdomain' => $h]);
    $site->id = (string) Str::uuid();
    $site->user_id = $user->id;
    $site->saveQuietly();

    return [$user, $site];
}

// A single Product page (JSON-LD). example.com is used as the host so the
// SSRF guard's live DNS resolve passes before Http::fake answers.
const DV2_PRODUCT_JSONLD = '<html><head><script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"Cool Tee","image":"https://cdn.example/tee.jpg","offers":{"@type":"Offer","price":"25.00","priceCurrency":"AUD","availability":"https://schema.org/InStock"}}</script></head><body></body></html>';

// ── Individual products (#6/#7) ──────────────────────────────────────────────

it('adds an individual product by URL into the reserved bucket', function () {
    $user = dv2User('prodadd');
    Http::fake(['*' => Http::response(DV2_PRODUCT_JSONLD, 200)]);

    actingAsUser($user)->postJson('/api/platforms/shop/products', ['url' => 'https://example.com/product/tee'])
        ->assertOk()
        ->assertJsonPath('id', 'individual')
        ->assertJsonPath('individual', true)
        ->assertJsonPath('products.0.title', 'Cool Tee')
        ->assertJsonPath('products.0.price', '25.00')
        ->assertJsonPath('products.0.currency', 'AUD');

    actingAsUser($user)->getJson('/api/platforms/shop/brands')
        ->assertOk()
        ->assertJsonPath('brands.0.individual', true);
});

it('rejects an individual product link with no product markup', function () {
    $user = dv2User('prodbad');
    Http::fake(['*' => Http::response('<html><head></head><body>nothing here</body></html>', 200)]);

    actingAsUser($user)->postJson('/api/platforms/shop/products', ['url' => 'https://example.com/blank'])
        ->assertStatus(422);
});

it('removes an individual product and drops the empty bucket', function () {
    $user = dv2User('prodrm');
    Http::fake(['*' => Http::response(DV2_PRODUCT_JSONLD, 200)]);

    $pid = actingAsUser($user)->postJson('/api/platforms/shop/products', ['url' => 'https://example.com/product/tee'])
        ->assertOk()->json('products.0.productId');

    actingAsUser($user)->deleteJson("/api/platforms/shop/products/{$pid}")
        ->assertOk()
        ->assertJsonPath('brands', []);
});

// ── Manual refresh (#13) ─────────────────────────────────────────────────────

it('rejects refreshing a platform that has nothing to pull', function () {
    actingAsUser(dv2User('refx'))->postJson('/api/platforms/tiktok/refresh')->assertStatus(422);
});

it('404s when nothing is connected to refresh', function () {
    actingAsUser(dv2User('refn'))->postJson('/api/platforms/pinterest/refresh')->assertStatus(404);
});

it('refreshes a connected platform then cools down', function () {
    Queue::fake();

    $user = dv2User('refp');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'pinterest', 'resource_id' => 'pinterest',
        'payload' => ['username' => 'someone'], 'is_active' => true,
    ]);

    actingAsUser($user)->postJson('/api/platforms/pinterest/refresh')
        ->assertStatus(202)
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('refreshed', 1)
        ->assertJsonStructure(['statusUrl']);

    Queue::assertPushed(RefreshConnectionJob::class, function (RefreshConnectionJob $job) use ($connection) {
        return $job->connectionId === $connection->id
            && $job->platform === 'pinterest'
            && $job->manual === true;
    });

    // Immediate second hit is rate-limited by the per-user+platform cooldown.
    actingAsUser($user)->postJson('/api/platforms/pinterest/refresh')
        ->assertStatus(429);
});

// ── Custom domain primary (#5) ───────────────────────────────────────────────

it('refuses to promote a domain that is not yet active', function () {
    [$user, $site] = dv2UserWithSite('domp');
    $site->custom_domain = 'bookwith.me';
    $site->custom_domain_status = 'pending';
    $site->saveQuietly();

    actingAsUser($user)->postJson('/api/site/custom-domain/primary', ['primary' => true])
        ->assertStatus(422);
    expect((bool) $site->fresh()->custom_domain_primary)->toBeFalse();
});

it('promotes an active custom domain to primary and swaps back to default', function () {
    [$user, $site] = dv2UserWithSite('domp2');
    $site->custom_domain = 'bookwith.me';
    $site->custom_domain_status = 'active';
    $site->saveQuietly();

    actingAsUser($user)->postJson('/api/site/custom-domain/primary', ['primary' => true])
        ->assertOk()->assertJsonPath('primary', true);
    expect((bool) $site->fresh()->custom_domain_primary)->toBeTrue();

    actingAsUser($user)->postJson('/api/site/custom-domain/primary', ['primary' => false])
        ->assertOk()->assertJsonPath('primary', false);
    expect((bool) $site->fresh()->custom_domain_primary)->toBeFalse();
});

it('clears primary when the custom domain is removed', function () {
    [$user, $site] = dv2UserWithSite('dompr');
    $site->custom_domain = 'bookwith.me';
    $site->custom_domain_cf_id = 'ch_1';
    $site->custom_domain_status = 'active';
    $site->custom_domain_primary = true;
    $site->saveQuietly();

    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true], 200)]);
    Queue::fake();

    actingAsUser($user)->deleteJson('/api/site/custom-domain')
        ->assertOk()->assertJsonPath('primary', false);
    expect((bool) $site->fresh()->custom_domain_primary)->toBeFalse();
});
