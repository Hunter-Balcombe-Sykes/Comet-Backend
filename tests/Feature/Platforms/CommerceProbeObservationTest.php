<?php

use App\Jobs\Platforms\CommerceProbeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * R6 (2026-08-18 Instagram wave), second half.
 *
 * ShopProductSeeder now records the product lane and StoreBrandSeeder records
 * both store lanes, which leaves exactly one arm of CommerceProbeJob::probe()
 * reaching no seeder at all: the page that could not be fetched. That link
 * still lands on the user's site as a plain custom link, so "we looked at this
 * and could not read it" is a decision the ledger must carry — otherwise the
 * only links with no observation are the ones that failed silently, which is
 * the same inversion R6 named.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
    setupRoutingTables();
});

function probeObservationUser(): User
{
    $user = User::factory()->create();
    $site = new Site(['subdomain' => 'probe'.substr((string) $user->id, 0, 8), 'is_published' => true, 'settings' => []]);
    $site->user()->associate($user);
    $site->save();

    return $user->refresh();
}

it('records an unreachable probe as a note, not silence', function () {
    $user = probeObservationUser();
    Http::fake(['*' => Http::response('', 503)]);

    app()->call([new CommerceProbeJob((string) $user->id, 'https://unreachable.example.com/thing'), 'handle']);

    // Filtered to the DEEP url: the T4 origin fallback asks the store
    // question at the root first, and that miss records its own trace row.
    $observation = DB::table('routing.link_observations')
        ->where('user_id', $user->id)
        ->where('raw_url', 'https://unreachable.example.com/thing')
        ->first();

    expect($observation)->not->toBeNull();
    expect($observation->source)->toBe('commerce_probe');
    expect($observation->verdict)->toBe('note');
    expect($observation->block_reason)->toBe('probe_unreachable');
    expect($observation->confidence)->toBeNull();
});

it('connects a scanned product\'s STORE beside the product item (T7)', function () {
    // ShopProductSeeder seeded the product and STOPPED — a scanned Shopify
    // product link never connected its store, while the paste lane did
    // (ProductPageAdder → ConnectStoreFromProductJob). Same brain now.
    $user = probeObservationUser();
    Bus::fake();
    $productHtml = '<html><head><script type="application/ld+json">'.json_encode([
        '@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Bulwark Jacket',
        'sku' => 'BJ-1',
        'offers' => ['@type' => 'Offer', 'price' => '120.00', 'priceCurrency' => 'AUD', 'url' => 'https://example.com/products/bulwark-jacket'],
        'image' => 'https://example.com/img/jacket.jpg',
    ]).'</script></head><body></body></html>';
    Http::fake([
        'https://example.com/products/bulwark-jacket' => Http::response($productHtml, 200),
        'https://example.com/meta.json' => Http::response(['id' => 555001, 'name' => 'Example Store', 'currency' => 'AUD'], 200, ['Content-Type' => 'application/json']),
        '*' => Http::response('', 404),
    ]);

    app()->call([new CommerceProbeJob((string) $user->id, 'https://example.com/products/bulwark-jacket'), 'handle']);

    expect(DB::connection('pgsql')->table('content.items')->where('user_id', $user->id)->where('kind', 'product')->count())->toBe(1);

    $connection = IntegrationConnection::query()
        ->where('user_id', $user->id)->where('surface_key', 'shopify.store')->first();
    expect($connection)->not->toBeNull()
        ->and($connection->resource_id)->toBe('555001');
});

it('never reconnects a DISCONNECTED store from a scanned product (T7 tombstone, policy-owned)', function () {
    $user = probeObservationUser();
    Bus::fake();
    $productHtml = '<html><head><script type="application/ld+json">'.json_encode([
        '@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Bulwark Jacket', 'sku' => 'BJ-1',
        'offers' => ['@type' => 'Offer', 'price' => '120.00', 'priceCurrency' => 'AUD'],
    ]).'</script></head><body></body></html>';
    Http::fake([
        'https://example.com/products/bulwark-jacket' => Http::response($productHtml, 200),
        'https://example.com/meta.json' => Http::response(['id' => 555001, 'name' => 'Example Store', 'currency' => 'AUD'], 200, ['Content-Type' => 'application/json']),
        '*' => Http::response('', 404),
    ]);
    DB::table('routing.item_tombstones')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'source_ref' => 'shopify.store:555001',
        'scope' => 'this_source',
        'reason' => 'owner disconnected the store',
        'created_at' => now(),
    ]);

    app()->call([new CommerceProbeJob((string) $user->id, 'https://example.com/products/bulwark-jacket'), 'handle']);

    // Tombstone honoured via PlacementPolicy (never hand-rolled here): the
    // store stays disconnected. The product itself still lands — the item
    // tombstone and the connection tombstone are different removals.
    expect(IntegrationConnection::query()
        ->where('user_id', $user->id)->where('surface_key', 'shopify.store')->count())->toBe(0);
});

it('asks the ORIGIN the store question when the deep page is unreachable (T4 — the natalieanne repro)', function () {
    // The live failure (routing.link_observations, gsnwilliams, 2026-08-19):
    // the host's ONE probe was spent on a stale bio link that 404s for every
    // UA, so the homepage that identifies Shopify instantly was never asked
    // and the store was never suggested. The fallback asks the origin.
    // example.com, not a made-up subdomain: SafeUrlFetcher resolves DNS
    // before fetching (SSRF guard), and Http::fake can't intercept DNS.
    $user = probeObservationUser();
    Bus::fake();
    Http::fake([
        'https://example.com/meta.json' => Http::response(
            ['id' => 987654, 'name' => 'Natalie Example', 'currency' => 'AUD', 'myshopify_domain' => 'natalie-example.myshopify.com'],
            200,
            ['Content-Type' => 'application/json'],
        ),
        '*' => Http::response('', 404),
    ]);

    app()->call([new CommerceProbeJob((string) $user->id, 'https://example.com/pages/dead-education-page'), 'handle']);

    $connection = IntegrationConnection::query()
        ->where('user_id', $user->id)->first();
    expect($connection)->not->toBeNull()
        ->and($connection->surface_key)->toBe('shopify.store')
        ->and($connection->resource_id)->toBe('987654');
});
