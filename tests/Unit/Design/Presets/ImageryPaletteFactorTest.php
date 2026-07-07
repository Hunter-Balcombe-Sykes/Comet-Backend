<?php

/**
 * Unit tests for ImageryPaletteFactor (band 22, Auto) — the gallery-palette
 * image-treatment reading. Two responsibilities under test: (1) that it
 * ABSTAINS today because IdentityEvidence::mediaPalette() always returns []
 * (no colour metadata stored on media — spec §2 row 2 + §5), proving the
 * factor is a provable no-op until the pixel-extraction job lands, and (2) the
 * PRIVATE treatmentFor() mapping that will light up once a palette exists,
 * plus the SAFETY CONTRACT that it never emits the accent column (that stays
 * OwnMediaAccent's, band 20).
 */

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Design\Presets\Factors\ImageryPaletteFactor;
use App\Services\Design\Presets\IdentityEvidence;
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

/** IdentityEvidence over an arbitrary set of active connections (or none). */
function ipEvidence(array $connections = []): IdentityEvidence
{
    $collection = new Collection($connections);

    return new IdentityEvidence(
        (new User)->forceFill(['id' => 'u1']),
        (new Site)->forceFill(['id' => 's1']),
        $collection,
        new Collection,
    );
}

it('abstains today because no colour metadata is stored on media', function () {
    $out = (new ImageryPaletteFactor)->detect(ipEvidence([
        new IntegrationConnection([
            'user_id' => 'u1', 'platform' => 'instagram', 'payload' => ['username' => 'someone'],
        ]),
    ]));

    expect($out)->toBe([]);
});

it('abstains with no connections at all', function () {
    expect((new ImageryPaletteFactor)->detect(ipEvidence([])))->toBe([]);
});

it('is an auto factor in band 22', function () {
    $factor = new ImageryPaletteFactor;
    expect($factor->priority())->toBe(22)
        ->and($factor->mode()->value)->toBe('auto')
        ->and($factor->key())->toBe('imagery-palette:treatment');
});

// ── Private treatmentFor() mapping (spec's future-lights-up contract) ───────
// Exercised via reflection since mediaPalette() always returns [] today; this
// proves the mapping logic is correct so it needs no change when the
// pixel-extraction job populates a real palette.

function ipTreatmentFor(array $palette): ?string
{
    $method = new ReflectionMethod(ImageryPaletteFactor::class, 'treatmentFor');
    $method->setAccessible(true);

    return $method->invoke(new ImageryPaletteFactor, $palette);
}

it('maps a warm-dominant palette to a warm treatment', function () {
    expect(ipTreatmentFor(['warm' => true]))->toBe('warm');
});

it('maps low saturation to a muted treatment', function () {
    expect(ipTreatmentFor(['saturation' => 0.15]))->toBe('muted');
});

it('maps very low saturation to a mono treatment', function () {
    expect(ipTreatmentFor(['saturation' => 0.05]))->toBe('mono');
});

it('maps high saturation to no treatment (vibrant imagery speaks for itself)', function () {
    expect(ipTreatmentFor(['saturation' => 0.80]))->toBe('none');
});

it('returns null for an empty or ambiguous palette', function () {
    expect(ipTreatmentFor([]))->toBeNull()
        ->and(ipTreatmentFor(['saturation' => 0.40]))->toBeNull();
});

// ── Safety contract ──────────────────────────────────────────────────────────

it('never emits the accent column: no such symbol exists in the factor CODE', function () {
    // detect() only ever sets effect_image_treatment and (for a warm treatment)
    // color_bg — the accent column is exclusively OwnMediaAccent's (band 20).
    // Proven structurally: the string 'color_accent' never appears in the
    // factor's source at all, so no code path could ever emit it.
    $source = file_get_contents(
        (new ReflectionClass(ImageryPaletteFactor::class))->getFileName(),
    );

    expect(str_contains($source, 'color_accent'))->toBeFalse();
});
