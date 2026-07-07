<?php

/**
 * Unit tests for MusicGenreFactor (band 34, OneShot) — the genre-lexicon style
 * nudge. Two responsibilities under test: (1) that it ABSTAINS today because
 * IdentityEvidence::musicGenre() returns null unless a music-platform payload
 * carries a genre key — which no production payload does yet (spec §2 row 3),
 * proving the factor is a provable no-op until a future payload enrichment
 * adds genre, and (2) the genre-family → look mapping that will light up once
 * a genre is present, exercised via an in-memory music connection carrying a
 * genre key. 'spotify' is used as the music-category platform slug (verified
 * against PlatformRegistry below).
 */

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Design\Presets\Factors\MusicGenreFactor;
use App\Services\Design\Presets\IdentityEvidence;
use App\Services\Design\Presets\PresetTargetableColumns;
use App\Services\Platforms\Registry\PlatformCategory;
use App\Services\Platforms\Registry\PlatformRegistry;
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

/** IdentityEvidence over one 'spotify' connection carrying $payload (or none), plus an unrelated Instagram connection. */
function mgEvidence(?array $spotifyPayload, bool $withInstagram = false): IdentityEvidence
{
    $connections = new Collection;
    if ($withInstagram) {
        $connections->push(new IntegrationConnection([
            'user_id' => 'u1', 'platform' => 'instagram', 'payload' => ['username' => 'someone'],
        ]));
    }
    if ($spotifyPayload !== null) {
        $connections->push(new IntegrationConnection([
            'user_id' => 'u1', 'platform' => 'spotify', 'payload' => $spotifyPayload,
        ]));
    }

    return new IdentityEvidence(
        (new User)->forceFill(['id' => 'u1']),
        (new Site)->forceFill(['id' => 's1']),
        $connections,
        new Collection,
    );
}

it('confirms spotify is registered under the Music platform category', function () {
    // musicGenre() only probes connections whose platform resolves to
    // PlatformCategory::Music — if this ever drifted, every mapping case below
    // would silently degrade to an abstain (the connection would be skipped)
    // rather than fail loudly, so pin it explicitly.
    $descriptor = app(PlatformRegistry::class)->get('spotify');

    expect($descriptor)->not->toBeNull()
        ->and($descriptor->getCategory())->toBe(PlatformCategory::Music);
});

it('abstains when there is no music connection', function () {
    expect((new MusicGenreFactor)->detect(mgEvidence(null, withInstagram: true)))->toBe([]);
});

it('abstains when the music payload carries no genre', function () {
    // The real-world case today: oEmbed payloads carry {name, embedUrl, ...}
    // but no genre key at all.
    expect((new MusicGenreFactor)->detect(mgEvidence([
        'name' => 'Artist', 'embedUrl' => 'x',
    ])))->toBe([]);
});

it('maps an electronic genre to a bold dark look', function () {
    $out = (new MusicGenreFactor)->detect(mgEvidence(['genre' => 'Techno']));

    expect($out['color_bg'])->toBe('#151515')
        ->and($out['border_radius'])->toBe('0.25rem')
        ->and($out['weight_regular'])->toBe('600')
        ->and($out['effect_style'])->toBe('bold');
});

it('maps an acoustic genre to a warm soft look', function () {
    $out = (new MusicGenreFactor)->detect(mgEvidence(['genre' => 'Acoustic Folk']));

    expect($out['border_radius'])->toBe('1.5rem')
        ->and($out['typography_font_family'])->toBe('eb-garamond')
        ->and($out['effect_image_treatment'])->toBe('warm');
});

it('maps classical/jazz to an editorial look', function () {
    $out = (new MusicGenreFactor)->detect(mgEvidence(['genre' => 'Jazz']));

    expect($out['effect_style'])->toBe('editorial')
        ->and($out['typography_font_family'])->toBe('young-serif');
});

it('maps pop/indie to a neutral-bright look', function () {
    $out = (new MusicGenreFactor)->detect(mgEvidence(['genre' => 'Indie Pop']));

    expect($out['color_bg'])->toBe('#fafafa')
        ->and($out['effect_style'])->toBe('sharp');
});

it('abstains for an unrecognised genre', function () {
    expect((new MusicGenreFactor)->detect(mgEvidence(['genre' => 'Polka'])))->toBe([]);
});

it('is a one-shot factor in band 34', function () {
    $factor = new MusicGenreFactor;
    expect($factor->priority())->toBe(34)
        ->and($factor->mode()->value)->toBe('one_shot')
        ->and($factor->key())->toBe('music-genre:style');
});

// ── Safety contract ──────────────────────────────────────────────────────────

it('only ever emits whitelisted design-kit columns', function () {
    $out = (new MusicGenreFactor)->detect(mgEvidence(['genre' => 'Techno']));

    expect($out)->not->toBe([]);
    foreach (array_keys($out) as $column) {
        expect(PresetTargetableColumns::isValid($column))->toBeTrue();
    }
});
