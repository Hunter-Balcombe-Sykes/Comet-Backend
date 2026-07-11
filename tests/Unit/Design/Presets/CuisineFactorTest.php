<?php

/**
 * Unit tests for CuisineFactor — the band-44 refiner that reads a food
 * business's cuisine hint (derived from the Google-Business category string
 * via CuisineLexicon) and nudges the character of the look. Three
 * responsibilities under test: (1) the three character-token overlays
 * (fine_dining / cafe / fast_casual), (2) the restrained fallback for a
 * specific named cuisine, and (3) abstention when there is no readable
 * cuisine signal.
 */

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Design\Presets\Factors\CuisineFactor;
use App\Services\Design\Presets\IdentityEvidence;
use App\Services\Design\Presets\PresetTargetableColumns;
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

/** IdentityEvidence over one google-business connection carrying $payload (or none). */
function cuisineEvidence(?array $googleBusinessPayload): IdentityEvidence
{
    $connections = new Collection;
    if ($googleBusinessPayload !== null) {
        $connections->push(new IntegrationConnection([
            'user_id' => 'u1', 'platform' => 'google-business', 'payload' => $googleBusinessPayload,
        ]));
    }

    return new IdentityEvidence(
        (new User)->forceFill(['id' => 'u1']),
        (new Site)->forceFill(['id' => 's1']),
        $connections,
        new Collection,
    );
}

it('concludes fine-dining editorial look for a fine-dining category', function () {
    $out = (new CuisineFactor)->detect(cuisineEvidence(['category' => 'Fine dining restaurant']));

    // Dark ground retired (theme_mode owns bg) — restraint reads through the
    // light weight + outline surface + flat shadows + duotone mood, plus the
    // square editorial shape refining the food bucket's soft corners.
    expect($out['weight_regular'])->toBe('300')
        ->and($out['border_radius'])->toBe('0')
        ->and($out['typography_font_family'])->toBe('young-serif')
        ->and($out['effect_surface'])->toBe('outline')
        ->and($out['effect_shadow_style'])->toBe('flat')
        ->and($out['effect_image_treatment'])->toBe('duotone')
        ->and($out)->not->toHaveKey('color_bg');
});

it('concludes a warm cafe look for a coffee shop', function () {
    $out = (new CuisineFactor)->detect(cuisineEvidence(['category' => 'Coffee shop']));

    expect($out['border_radius'])->toBe('0.85rem')
        ->and($out['effect_shadow_style'])->toBe('soft')
        ->and($out['effect_image_treatment'])->toBe('warm')
        ->and($out)->not->toHaveKey('color_bg');
});

it('concludes a bold fast-casual look', function () {
    $out = (new CuisineFactor)->detect(cuisineEvidence(['category' => 'Fast food restaurant']));

    expect($out['border_radius'])->toBe('0.25rem')
        ->and($out['weight_regular'])->toBe('600')
        ->and($out['effect_surface'])->toBe('solid')
        ->and($out['effect_shadow_style'])->toBe('hard');
});

it('concludes a restrained look for a specific cuisine', function () {
    $out = (new CuisineFactor)->detect(cuisineEvidence(['category' => 'Italian restaurant']));

    // Warm imagery, not muted — appetite rules food photography; the restraint
    // reads through the serif + slow pace + outline surface.
    expect($out['typography_font_family'])->toBe('sentient')
        ->and($out['effect_surface'])->toBe('outline')
        ->and($out['motion_pace'])->toBe('slow')
        ->and($out['effect_image_treatment'])->toBe('warm');
});

it('abstains for a generic restaurant with no cuisine lean', function () {
    expect((new CuisineFactor)->detect(cuisineEvidence(['category' => 'Restaurant'])))->toBe([]);
});

it('abstains for a non-food business', function () {
    expect((new CuisineFactor)->detect(cuisineEvidence(['category' => 'Barber shop'])))->toBe([]);
});

it('abstains when there is no google-business connection', function () {
    $connections = new Collection([
        new IntegrationConnection([
            'user_id' => 'u1', 'platform' => 'instagram', 'payload' => ['businessCategory' => 'Restaurant'],
        ]),
    ]);

    $evidence = new IdentityEvidence(
        (new User)->forceFill(['id' => 'u1']),
        (new Site)->forceFill(['id' => 's1']),
        $connections,
        new Collection,
    );

    expect((new CuisineFactor)->detect($evidence))->toBe([]);
});

it('abstains when the google-business payload has no category', function () {
    expect((new CuisineFactor)->detect(cuisineEvidence(['name' => 'X'])))->toBe([]);
});

it('is a one-shot factor in band 44', function () {
    $factor = new CuisineFactor;
    expect($factor->priority())->toBe(44)
        ->and($factor->mode()->value)->toBe('one_shot')
        ->and($factor->key())->toBe('cuisine:character');
});

it('only ever emits whitelisted design-kit columns', function () {
    $fineDining = (new CuisineFactor)->detect(cuisineEvidence(['category' => 'Fine dining restaurant']));
    $cafe = (new CuisineFactor)->detect(cuisineEvidence(['category' => 'Coffee shop']));

    foreach ([$fineDining, $cafe] as $out) {
        expect($out)->not->toBe([]);
        foreach (array_keys($out) as $column) {
            expect(PresetTargetableColumns::isValid($column))->toBeTrue("non-targetable column emitted: {$column}");
        }
    }
});
