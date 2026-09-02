<?php

/**
 * Pure unit tests for ProfileDesignPresets — user profile fields in, sparse
 * design_kits overlay out. No DB, no container: forUser() reads model
 * properties only, so unsaved User instances are enough.
 */

use App\Models\Core\User\User;
use App\Services\Design\ProfileDesignPresets;
use App\Services\Design\SectorStylePresets;

it('returns the bucket base for a slug with no refinement', function () {
    $user = new User;
    $user->sector = 'restaurant'; // food_drink bucket, no slug refinement

    $bucket = SectorStylePresets::forBucket(SectorStylePresets::FOOD_DRINK);
    $out = ProfileDesignPresets::forUser($user);

    // The bucket's own axes survive; the register (feminine here) sets the
    // corners and the fallback accent over them (owner, 2026-09-02).
    expect($out['typography_font_family'])->toBe($bucket['typography_font_family'])
        ->and($out['corners'])->toBe('default')
        ->and($out['color_accent'])->toBe(SectorStylePresets::FEMININE_ACCENT_BLUE);
});

it('merges the slug refinement over the bucket base', function () {
    $user = new User;
    $user->sector = 'spa'; // beauty bucket + spa refinement

    $out = ProfileDesignPresets::forUser($user);

    expect($out['spacing'])->toBe('spacious')           // bucket look survives
        ->and($out['corners'])->toBe('default')             // the feminine register: curved
        ->and($out['color_accent'])->toBe(SectorStylePresets::FEMININE_ACCENT_PINK)
        ->and($out['typography_uppercase'])->toBeFalse()
        // The bucket authors NO font since the full-look rewrite (2026-08-27
        // — helvetica IS the package default, and re-emitting a default is
        // noise per the sparsity rule), so the key is absent and the
        // package default applies downstream.
        ->and($out)->not->toHaveKey('typography_font_family');
});

it('a masculine slug reads square, NB Architekt and neon whatever its bucket authored', function () {
    $user = new User;
    $user->sector = 'barber';

    $out = ProfileDesignPresets::forUser($user);

    expect($out['corners'])->toBe('sharp')
        ->and($out['typography_font_family'])->toBe('nb-architekt')
        ->and($out['typography_uppercase'])->toBeTrue()
        ->and($out['color_accent'])->toBe(SectorStylePresets::MASCULINE_ACCENT);
});

it('styles a google-sourced sector too — fields, not sources', function () {
    $user = new User;
    $user->sector = 'barber';
    $user->sector_source = 'google';

    expect(ProfileDesignPresets::forUser($user))->not->toBe([]);
});

it('returns [] for a null user', function () {
    expect(ProfileDesignPresets::forUser(null))->toBe([]);
});

it('returns [] for a blank sector', function () {
    $user = new User;
    $user->sector = '  ';

    expect(ProfileDesignPresets::forUser($user))->toBe([]);
});

it('returns [] for a slug with no taxonomy bucket', function () {
    $user = new User;
    $user->sector = 'not-a-real-sector-slug';

    expect(ProfileDesignPresets::forUser($user))->toBe([]);
});

it('both tiers pass the targetable-column allowlist untouched', function () {
    $targetable = (new ReflectionClass(ProfileDesignPresets::class))->getConstant('TARGETABLE');
    $overlays = array_map(SectorStylePresets::forBucket(...), SectorStylePresets::buckets());
    foreach (SectorStylePresets::refinedSlugs() as $slug) {
        $overlays[] = SectorStylePresets::forSlug($slug);
    }
    foreach ($overlays as $overlay) {
        expect(array_intersect_key($overlay, array_flip($targetable)))->toBe($overlay);
    }
});
