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

    expect(ProfileDesignPresets::forUser($user))
        ->toBe(SectorStylePresets::forBucket(SectorStylePresets::FOOD_DRINK));
});

it('merges the slug refinement over the bucket base', function () {
    $user = new User;
    $user->sector = 'spa'; // beauty bucket + spa refinement

    $expected = array_merge(
        SectorStylePresets::forBucket(SectorStylePresets::BEAUTY_PERSONAL_CARE),
        SectorStylePresets::forSlug('spa'),
    );
    $out = ProfileDesignPresets::forUser($user);

    expect($out)->toBe($expected)
        ->and($out['color_accent'])->toBe('#0f766e')      // spa teal beats bucket rose
        ->and($out['typography_font_family'])->toBe('helvetica-now'); // bucket font survives
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
