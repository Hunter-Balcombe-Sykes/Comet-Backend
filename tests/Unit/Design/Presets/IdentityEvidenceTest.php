<?php

/**
 * Unit tests for IdentityEvidence assembly — the v2 evidence bag the resolver
 * builds once per resolve. Everything here uses UNSAVED, in-memory models
 * (forceFill / setRelation) so the assembly is exercised without any DB: the
 * point is that IdentityEvidence reads only what it's handed, computing its
 * typed views (platform slugs, store products, IG payload, prior contributions)
 * purely from the passed-in collections.
 */

use App\Models\Core\Site\DesignKitContribution;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\Site\ShopProduct;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Design\Presets\IdentityEvidence;
use Illuminate\Support\Collection;
use Tests\TestCase;

// Boots the app container (not the DB): nothing here touches a table.
uses(TestCase::class)->in(__FILE__);

/** Unsaved connection — never persisted. */
function evConnection(string $platform, array $payload = []): IntegrationConnection
{
    return new IntegrationConnection(['user_id' => 'u1', 'platform' => $platform, 'payload' => $payload]);
}

/**
 * Unsaved shop connection with brand + product rows pre-set in memory (FOUND-25
 * relational shape), so reading brands/products issues no query.
 *
 * @param  list<array{price: mixed, currency?: ?string}>  $products
 */
function evShopConnection(array $products, ?string $brandCurrency = 'AUD'): IntegrationConnection
{
    $connection = evConnection('shop', ['storage' => 'relational']);

    $productModels = array_map(
        fn (array $p) => (new ShopProduct)->forceFill([
            'data' => ['price' => $p['price'], 'currency' => $p['currency'] ?? null],
        ]),
        $products,
    );

    $brand = (new ShopBrand)->forceFill(['brand_id' => 'b1', 'currency' => $brandCurrency]);
    $brand->setRelation('products', new Collection($productModels));

    $connection->setRelation('shopBrands', new Collection([$brand]));

    return $connection;
}

function evEvidence(Collection $connections, Collection $prior = new Collection): IdentityEvidence
{
    return new IdentityEvidence(
        (new User)->forceFill(['id' => 'u1']),
        (new Site)->forceFill(['id' => 's1']),
        $connections,
        $prior,
    );
}

it('assembles the distinct set of active platform slugs', function () {
    $evidence = evEvidence(new Collection([
        evConnection('instagram'),
        evConnection('google-business'),
        evConnection('instagram'), // duplicate collapses
        evConnection('shop'),
    ]));

    expect($evidence->platformSlugs())
        ->toEqualCanonicalizing(['instagram', 'google-business', 'shop']);
});

it('returns an empty slug set when there are no connections', function () {
    expect(evEvidence(new Collection)->platformSlugs())->toBe([]);
});

it('collects store products with prices normalised to floats', function () {
    $evidence = evEvidence(new Collection([
        evShopConnection([
            ['price' => '19.99', 'currency' => 'AUD'],
            ['price' => '250.00', 'currency' => 'AUD'],
        ]),
    ]));

    $products = $evidence->storeProducts();
    expect($products)->toHaveCount(2)
        ->and($products[0]['price'])->toBe(19.99)
        ->and($products[1]['price'])->toBe(250.0)
        ->and($products[0]['currency'])->toBe('AUD');
});

it('parses messy price strings and drops unparseable / non-positive ones', function () {
    $evidence = evEvidence(new Collection([
        evShopConnection([
            ['price' => '$1,299.00', 'currency' => 'USD'], // symbol + thousands
            ['price' => '0', 'currency' => 'USD'],          // zero -> dropped
            ['price' => 'Sold out', 'currency' => 'USD'],   // non-numeric -> dropped
            ['price' => 49.5, 'currency' => 'USD'],         // numeric float ok
        ]),
    ]));

    $prices = array_map(fn ($p) => $p['price'], $evidence->storeProducts());
    expect($prices)->toBe([1299.0, 49.5]);
});

it('falls back to the brand currency when a product omits its own', function () {
    $evidence = evEvidence(new Collection([
        evShopConnection([['price' => '10.00', 'currency' => null]], brandCurrency: 'NZD'),
    ]));

    expect($evidence->storeProducts()[0]['currency'])->toBe('NZD');
});

it('ignores non-shop connections when collecting store products', function () {
    $evidence = evEvidence(new Collection([
        evConnection('instagram', ['businessCategory' => 'Beauty']),
        evConnection('google-business', ['category' => 'Restaurant']),
    ]));

    expect($evidence->storeProducts())->toBe([]);
});

it('exposes the first active Instagram payload as a raw array', function () {
    $evidence = evEvidence(new Collection([
        evConnection('google-business', ['category' => 'Gym']),
        evConnection('instagram', ['fullName' => 'Glow Beauty', 'businessCategory' => 'Beauty, Cosmetic & Personal Care']),
    ]));

    expect($evidence->instagramPayload())
        ->toBe(['fullName' => 'Glow Beauty', 'businessCategory' => 'Beauty, Cosmetic & Personal Care']);
});

it('returns null for the Instagram payload when no Instagram is connected', function () {
    expect(evEvidence(new Collection([evConnection('shop')]))->instagramPayload())->toBeNull();
});

it('reads a prior contribution value for a source + column, null when absent', function () {
    $prior = (new Collection([
        (new DesignKitContribution)->forceFill(['source' => 'store:price-point', 'target_var' => 'space_regular', 'value' => '1.15rem']),
        (new DesignKitContribution)->forceFill(['source' => 'store:price-point', 'target_var' => 'motion_pace', 'value' => 'slow']),
    ]))->groupBy('source');

    $evidence = evEvidence(new Collection, $prior);

    expect($evidence->priorContributionValue('store:price-point', 'space_regular'))->toBe('1.15rem')
        ->and($evidence->priorContributionValue('store:price-point', 'border_radius'))->toBeNull()
        ->and($evidence->priorContributionValue('unknown:source', 'space_regular'))->toBeNull();
});
