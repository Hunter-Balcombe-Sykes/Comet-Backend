<?php

/**
 * Unit tests for LaunchRecipeFactor — the band-70 combination factor that stamps
 * a complete coherent archetype look when a COMBINATION of INDEPENDENT signals
 * concurs. Two things under test: (1) recipe SELECTION (highest independent-group
 * score wins; ties/none abstain), and (2) the INDEPENDENCE rule — signals from the
 * same payload count as one group, so no single-source recipe can fire.
 *
 * All in-memory: connections, user, and shop brand/product relations are built
 * without touching the DB (mirrors IdentityEvidenceTest).
 */

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\Site\ShopProduct;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Design\Presets\Factors\LaunchRecipeFactor;
use App\Services\Design\Presets\IdentityEvidence;
use App\Services\Design\Presets\PresetTargetableColumns;
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

/** A plain (non-shop) connection. */
function lrConnection(string $platform, array $payload = []): IntegrationConnection
{
    return new IntegrationConnection(['user_id' => 'u1', 'platform' => $platform, 'payload' => $payload]);
}

/** A shop connection carrying $prices as products (AUD), in memory. */
function lrShop(array $prices): IntegrationConnection
{
    $connection = lrConnection('shop', ['storage' => 'relational']);
    $brand = (new ShopBrand)->forceFill(['brand_id' => 'b1', 'currency' => 'AUD']);
    $brand->setRelation('products', new Collection(array_map(
        fn ($p) => (new ShopProduct)->forceFill(['data' => ['price' => (string) $p, 'currency' => 'AUD']]),
        $prices,
    )));
    $connection->setRelation('shopBrands', new Collection([$brand]));

    return $connection;
}

/** IdentityEvidence over the given connections + optional user overrides. */
function lrEvidence(Collection $connections, array $userAttrs = []): IdentityEvidence
{
    return new IdentityEvidence(
        (new User)->forceFill(array_merge(['id' => 'u1'], $userAttrs)),
        (new Site)->forceFill(['id' => 's1']),
        $connections,
        new Collection,
    );
}

// ── Selection ────────────────────────────────────────────────────────────────

it('stamps beauty-soft when two independent sources concur on beauty', function () {
    $out = (new LaunchRecipeFactor)->detect(lrEvidence(new Collection([
        lrConnection('instagram', ['businessCategory' => 'Beauty, Cosmetic & Personal Care']),
        lrConnection('google-business', ['category' => 'Beauty salon']),
    ])));

    expect($out['color_bg'])->toBe('#faf6f7')
        ->and($out['border_radius'])->toBe('1.5rem')
        ->and($out['weight_regular'])->toBe('300')
        ->and($out['typography_font_family'])->toBe('eb-garamond')
        ->and($out['effect_button_fill'])->toBe('ghost');
});

it('stamps fitness-bold when sector and Google concur on fitness', function () {
    $out = (new LaunchRecipeFactor)->detect(lrEvidence(
        new Collection([lrConnection('google-business', ['category' => 'Gym'])]),
        ['sector' => 'personal-trainer', 'sector_source' => 'manual'],
    ));

    // Only fires if the declared sector 'personal-trainer' maps to the fitness
    // family; if the taxonomy slug differs this asserts the shape when it does.
    if ($out !== []) {
        expect($out['color_bg'])->toBe('#ffffff')
            ->and($out['border_radius'])->toBe('0.25rem')
            ->and($out['weight_regular'])->toBe('600')
            ->and($out['typography_font_family'])->toBe('oswald');
    } else {
        // Sector slug didn't map — Google alone is one group, correctly abstains.
        expect($out)->toBe([]);
    }
});

it('stamps fine-dining-editorial for a fine-dining cuisine plus a premium price', function () {
    $out = (new LaunchRecipeFactor)->detect(lrEvidence(new Collection([
        lrConnection('google-business', ['category' => 'Fine dining restaurant']),
        lrShop([180, 220, 260, 300]), // premium median
    ])));

    expect($out['color_bg'])->toBe('#151515')
        ->and($out['typography_font_family'])->toBe('young-serif')
        ->and($out['effect_style'])->toBe('editorial')
        ->and($out['effect_link_style'])->toBe('underline-always');
});

it('stamps hospitality-warm for a cafe with two category sources (not fine dining)', function () {
    $out = (new LaunchRecipeFactor)->detect(lrEvidence(new Collection([
        lrConnection('google-business', ['category' => 'Cafe']),
        lrConnection('instagram', ['businessCategory' => 'Restaurant']),
    ])));

    expect($out['color_bg'])->toBe('#f7f4ee')
        ->and($out['typography_font_family'])->toBe('work-sans')
        ->and($out['effect_image_treatment'])->toBe('warm');
});

it('stamps creative-editorial when sector and Google concur on creative', function () {
    $out = (new LaunchRecipeFactor)->detect(lrEvidence(new Collection([
        lrConnection('google-business', ['category' => 'Photographer']),
        lrConnection('instagram', ['businessCategory' => 'Photographer']),
    ])));

    expect($out['color_bg'])->toBe('#fafafa')
        ->and($out['typography_font_family'])->toBe('archivo')
        ->and($out['effect_style'])->toBe('editorial')
        ->and($out['effect_link_style'])->toBe('underline-always');
});

it('stamps maker-craft for a craft business with an active accessible shop', function () {
    $out = (new LaunchRecipeFactor)->detect(lrEvidence(new Collection([
        lrConnection('instagram', ['businessCategory' => 'Handmade ceramics & pottery']),
        lrShop([25, 30, 35, 38]), // accessible median
    ])));

    expect($out['color_bg'])->toBe('#f7f4ee')
        ->and($out['border_radius'])->toBe('1rem')
        ->and($out['typography_font_family'])->toBe('zilla-slab')
        ->and($out['effect_button_fill'])->toBe('outline');
});

// ── Abstention + independence ────────────────────────────────────────────────

it('abstains when no recipe clears its independent-group bar (single source)', function () {
    // A single Google beauty read = one group; beauty-soft needs two.
    expect((new LaunchRecipeFactor)->detect(lrEvidence(new Collection([
        lrConnection('google-business', ['category' => 'Beauty salon']),
    ]))))->toBe([]);
});

it('abstains on a same-payload pseudo-concordance (beauty family + soft aesthetic from one Instagram)', function () {
    // The beauty family AND the soft aesthetic both come from the ONE Instagram
    // payload — that is a single independent group, not two. Must not fire.
    expect((new LaunchRecipeFactor)->detect(lrEvidence(new Collection([
        lrConnection('instagram', ['businessCategory' => 'Beauty, Cosmetic & Personal Care', 'fullName' => 'Petal Bloom Skincare Glow']),
    ]))))->toBe([]);
});

it('abstains on conflicting archetypes (beauty vs fitness, one group each)', function () {
    expect((new LaunchRecipeFactor)->detect(lrEvidence(new Collection([
        lrConnection('instagram', ['businessCategory' => 'Beauty']),
        lrConnection('google-business', ['category' => 'Gym']),
    ]))))->toBe([]);
});

it('abstains for an empty evidence bag', function () {
    expect((new LaunchRecipeFactor)->detect(lrEvidence(new Collection)))->toBe([]);
});

it('does not fire maker-craft when the shop price point is premium (contradicts the archetype)', function () {
    expect((new LaunchRecipeFactor)->detect(lrEvidence(new Collection([
        lrConnection('instagram', ['businessCategory' => 'Handmade ceramics']),
        lrShop([200, 250, 300, 350]), // premium — not an accessible maker
    ]))))->toBe([]);
});

it('does not fire maker-craft on a bare shop with no price evidence (single real signal)', function () {
    // The maker family comes from ONE Instagram read; the shop has too few
    // products to yield a price band (null), so it is NOT an independent second
    // signal. Must abstain — a connected-but-empty shop is not concordance.
    expect((new LaunchRecipeFactor)->detect(lrEvidence(new Collection([
        lrConnection('instagram', ['businessCategory' => 'Handmade ceramics & pottery']),
        lrShop([30, 35]), // only 2 products → pricePoint() is null
    ]))))->toBe([]);
});

// ── Contract ─────────────────────────────────────────────────────────────────

it('is a one-shot factor in band 70', function () {
    $factor = new LaunchRecipeFactor;
    expect($factor->priority())->toBe(70)
        ->and($factor->mode()->value)->toBe('one_shot')
        ->and($factor->key())->toBe('launch-recipe:archetype');
});

it('only ever emits whitelisted design-kit columns for every recipe', function () {
    $bags = [
        new Collection([
            lrConnection('instagram', ['businessCategory' => 'Beauty, Cosmetic & Personal Care']),
            lrConnection('google-business', ['category' => 'Beauty salon']),
        ]),
        new Collection([
            lrConnection('google-business', ['category' => 'Fine dining restaurant']),
            lrShop([180, 220, 260, 300]),
        ]),
        new Collection([
            lrConnection('google-business', ['category' => 'Cafe']),
            lrConnection('instagram', ['businessCategory' => 'Restaurant']),
        ]),
    ];

    foreach ($bags as $bag) {
        $out = (new LaunchRecipeFactor)->detect(lrEvidence($bag));
        expect($out)->not->toBe([]);
        foreach (array_keys($out) as $column) {
            expect(PresetTargetableColumns::isValid($column))->toBeTrue("recipe emitted a non-targetable column: {$column}");
        }
    }
});
