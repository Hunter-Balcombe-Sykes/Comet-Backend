<?php

/**
 * Unit tests for StorePricePointFactor — the refiner that reads a store's median
 * product price into a luxury/mid/casual recipe, with anti-flicker hysteresis.
 * Drives detect() directly against an in-memory IdentityEvidence (unsaved shop
 * brand + product rows). The consecutive-cross streak lives in Cache, which the
 * test driver keeps in-array, so hysteresis is exercised end-to-end without a DB.
 */

use App\Models\Core\Site\DesignKitContribution;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\Site\ShopProduct;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Design\Presets\Factors\StorePricePointFactor;
use App\Services\Design\Presets\IdentityEvidence;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(fn () => Cache::flush());

/**
 * IdentityEvidence over one shop connection whose brand holds $prices (AUD),
 * plus an optional prior "space_regular" contribution to anchor hysteresis.
 *
 * @param  list<float|string>  $prices
 */
function spEvidence(array $prices, ?string $priorSpace = null, string $currency = 'AUD'): IdentityEvidence
{
    $products = array_map(
        fn ($price) => (new ShopProduct)->forceFill(['data' => ['price' => $price, 'currency' => $currency]]),
        $prices,
    );
    $brand = (new ShopBrand)->forceFill(['brand_id' => 'b1', 'currency' => $currency]);
    $brand->setRelation('products', new Collection($products));

    $connection = new IntegrationConnection(['user_id' => 'u1', 'platform' => 'shop', 'payload' => []]);
    $connection->setRelation('shopBrands', new Collection([$brand]));

    $prior = new Collection;
    if ($priorSpace !== null) {
        $prior = (new Collection([
            (new DesignKitContribution)->forceFill([
                'source' => StorePricePointFactor::SOURCE,
                'target_var' => 'space_regular',
                'value' => $priorSpace,
            ]),
        ]))->groupBy('source');
    }

    return new IdentityEvidence(
        (new User)->forceFill(['id' => 'u1']),
        (new Site)->forceFill(['id' => 's1']),
        new Collection([$connection]),
        $prior,
    );
}

it('abstains under four products', function () {
    expect((new StorePricePointFactor)->detect(spEvidence([200, 300, 400])))->toBe([]);
});

it('classifies a luxury median (>= A$150) to the airy recipe', function () {
    $out = (new StorePricePointFactor)->detect(spEvidence([160, 180, 220, 500]));

    expect($out['space_regular'])->toBe('1.15rem')
        ->and($out['border_thickness'])->toBe('0.5px')
        ->and($out['motion_pace'])->toBe('slow')
        ->and($out['effect_surface'])->toBe('outline'); // hairline boutique restraint
});

it('classifies a casual median (<= A$40) to the compact recipe', function () {
    $out = (new StorePricePointFactor)->detect(spEvidence([15, 20, 25, 35]));

    expect($out['space_regular'])->toBe('0.8rem')
        ->and($out['border_radius'])->toBe('0.85rem')
        ->and($out['weight_regular'])->toBe('500')
        ->and($out['effect_surface'])->toBe('solid'); // filled, friendly, accessible
});

it('classifies a mid median to the neutral recipe', function () {
    $out = (new StorePricePointFactor)->detect(spEvidence([60, 80, 90, 110]));

    expect($out['space_regular'])->toBe('0.95rem')
        ->and($out['motion_pace'])->toBe('normal')
        ->and($out)->not->toHaveKey('effect_surface'); // mid stays neutral
});

it('normalises currency before banding (a US$120 median is luxury in AUD)', function () {
    // 120 USD * 1.55 ≈ 186 AUD -> luxury, despite the raw number being < 150.
    $out = (new StorePricePointFactor)->detect(spEvidence([110, 120, 125, 130], currency: 'USD'));

    expect($out['space_regular'])->toBe('1.15rem');
});

// ── Hysteresis ─────────────────────────────────────────────────────────────

it('commits the raw band immediately when there is no prior band (first resolve)', function () {
    // No prior contribution -> nothing to flicker from -> luxury lands at once.
    $out = (new StorePricePointFactor)->detect(spEvidence([160, 180, 220, 260]));
    expect($out['space_regular'])->toBe('1.15rem');
});

it('holds the prior band when a drift crosses the boundary by less than 15%', function () {
    // Prior = casual (0.8rem). New median 44 is just over CASUAL_MAX (40) into
    // mid, but only by 10% — inside the dead-band, so the band must HOLD casual.
    $out = (new StorePricePointFactor)->detect(spEvidence([40, 42, 45, 49], priorSpace: '0.8rem'));

    expect($out['space_regular'])->toBe('0.8rem'); // still casual, no flicker
});

it('requires two consecutive decisive crosses before flipping the band', function () {
    $factor = new StorePricePointFactor;

    // Prior = casual. A decisive move up into mid: median ~ 90 (well past
    // CASUAL_MAX*1.15 = 46). First resolve: crossed once -> still holds casual.
    $first = $factor->detect(spEvidence([80, 85, 95, 100], priorSpace: '0.8rem'));
    expect($first['space_regular'])->toBe('0.8rem'); // held — one cross isn't enough

    // Second consecutive decisive cross -> commit the flip to mid.
    $second = $factor->detect(spEvidence([80, 85, 95, 100], priorSpace: '0.8rem'));
    expect($second['space_regular'])->toBe('0.95rem'); // flipped to mid
});

it('resets the streak when a drift falls back inside the prior band', function () {
    $factor = new StorePricePointFactor;

    // One decisive cross toward mid (streak = 1, still holding casual).
    $factor->detect(spEvidence([80, 85, 95, 100], priorSpace: '0.8rem'));

    // Then the catalog drops back to casual — streak must reset, band stays casual.
    $back = $factor->detect(spEvidence([15, 20, 25, 30], priorSpace: '0.8rem'));
    expect($back['space_regular'])->toBe('0.8rem');

    // A fresh decisive cross now needs TWO more reads, proving the reset:
    $one = $factor->detect(spEvidence([80, 85, 95, 100], priorSpace: '0.8rem'));
    expect($one['space_regular'])->toBe('0.8rem'); // held again — streak restarted
});

it('is registered in band D (refiners) as an auto factor', function () {
    $factor = new StorePricePointFactor;
    expect($factor->priority())->toBe(58)
        ->and($factor->mode()->value)->toBe('auto')
        ->and($factor->key())->toBe('store:price-point');
});
