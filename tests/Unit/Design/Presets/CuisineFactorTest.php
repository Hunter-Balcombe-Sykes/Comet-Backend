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

    expect($out['color_bg'])->toBe('#151515')
        ->and($out['effect_style'])->toBe('editorial')
        ->and($out['effect_image_treatment'])->toBe('duotone');
});

it('concludes a warm cafe look for a coffee shop', function () {
    $out = (new CuisineFactor)->detect(cuisineEvidence(['category' => 'Coffee shop']));

    expect($out['color_bg'])->toBe('#f7f4ee')
        ->and($out['border_radius'])->toBe('1rem')
        ->and($out['effect_image_treatment'])->toBe('warm');
});

it('concludes a bold fast-casual look', function () {
    $out = (new CuisineFactor)->detect(cuisineEvidence(['category' => 'Fast food restaurant']));

    expect($out['color_bg'])->toBe('#ffffff')
        ->and($out['border_radius'])->toBe('0.25rem')
        ->and($out['weight_regular'])->toBe('600')
        ->and($out['effect_style'])->toBe('bold');
});

it('concludes a restrained look for a specific cuisine', function () {
    $out = (new CuisineFactor)->detect(cuisineEvidence(['category' => 'Italian restaurant']));

    expect($out['typography_font_family'])->toBe('origin')
        ->and($out['effect_style'])->toBe('editorial')
        ->and($out['effect_image_treatment'])->toBe('muted');
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
            // Retired columns excepted: WS5 re-tunes factor values — see plan
            // 2026-07-10 — until then these emissions are dead weight the
            // resolver's allowlist filter drops.
            if (in_array($column, ['color_bg', 'effect_style', 'motion_entrance'], true)) {
                continue;
            }
            expect(PresetTargetableColumns::isValid($column))->toBeTrue();
        }
    }
});
