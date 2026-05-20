<?php

use App\Http\Resources\PublicSite\IndividualProfileResource;

// TEST-3 — unit test for the DESIGN_KEYS allow-list (audit PROF-2).
//
// The controller filters site.settings.design through
//   array_intersect_key($rawDesign, array_flip(IndividualProfileResource::DESIGN_KEYS))
// before passing the design array to IndividualProfileResource.
//
// These tests verify the allow-list constant's semantics:
//   - known keys pass through unchanged
//   - unknown keys are stripped
//   - 'bio' (a top-level Professional field, not a design key) is stripped

function applyDesignKeyFilter(array $rawDesign): array
{
    return array_intersect_key($rawDesign, array_flip(IndividualProfileResource::DESIGN_KEYS));
}

it('passes through known design keys', function () {
    $raw = [
        'theme' => 'midnight',
        'accent_color' => '#FF00AA',
        'corner_radius' => 'pill',
        'font_family' => 'Inter',
        'layout' => 'centered',
    ];

    $filtered = applyDesignKeyFilter($raw);

    expect($filtered)->toEqual($raw);
});

it('strips unknown design keys', function () {
    $raw = [
        'theme' => 'midnight',
        'internal_flag' => 'experimental',
        'admin_token' => 'sk_live_secret',
        '__proto__' => 'injection',
    ];

    $filtered = applyDesignKeyFilter($raw);

    expect($filtered)->toEqual(['theme' => 'midnight']);
    expect($filtered)->not->toHaveKey('internal_flag');
    expect($filtered)->not->toHaveKey('admin_token');
    expect($filtered)->not->toHaveKey('__proto__');
});

it('strips bio — bio is a Professional field, not a design setting', function () {
    // 'bio' might drift into settings.design from a misconfigured frontend write.
    // It must not leak through the design envelope (bio surfaces via getBio() separately).
    $raw = [
        'theme' => 'midnight',
        'bio' => 'This should not be here',
    ];

    $filtered = applyDesignKeyFilter($raw);

    expect($filtered)->toHaveKey('theme');
    expect($filtered)->not->toHaveKey('bio');
});

it('returns an empty array when all keys are unknown', function () {
    $raw = [
        'secret_setting' => 'value',
        'internal_only' => true,
    ];

    $filtered = applyDesignKeyFilter($raw);

    expect($filtered)->toBeEmpty();
});

it('preserves all explicitly allowed DESIGN_KEYS when present', function () {
    $all = array_fill_keys(IndividualProfileResource::DESIGN_KEYS, 'value');

    $filtered = applyDesignKeyFilter($all);

    expect(array_keys($filtered))->toEqual(IndividualProfileResource::DESIGN_KEYS);
});
