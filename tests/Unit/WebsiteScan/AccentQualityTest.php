<?php

use App\Services\WebsiteScan\AccentQuality;

it('accepts a saturated mid-luminance hex', function () {
    expect(AccentQuality::qualifies('#105030'))->toBeTrue();
    expect(AccentQuality::qualifies('#e0491f'))->toBeTrue(); // food_drink preset accent
});

it('rejects near-white, near-black, and grey hexes', function () {
    expect(AccentQuality::qualifies('#fefefe'))->toBeFalse(); // near-white
    expect(AccentQuality::qualifies('#0a0a0a'))->toBeFalse(); // near-black
    expect(AccentQuality::qualifies('#808080'))->toBeFalse(); // grey: saturation 0
});

it('does not crash on pure black (int/float saturation guard regression)', function () {
    expect(fn () => AccentQuality::qualifies('#000000'))->not->toThrow(DivisionByZeroError::class);
    expect(AccentQuality::qualifies('#000000'))->toBeFalse();
});

it('normalizeHex accepts 6-digit hex with or without a leading #', function () {
    expect(AccentQuality::normalizeHex('#ff5500'))->toBe('#ff5500');
    expect(AccentQuality::normalizeHex('FF5500'))->toBe('#ff5500');
});

it('normalizeHex expands 3-digit shorthand', function () {
    expect(AccentQuality::normalizeHex('#f50'))->toBe('#ff5500');
});

it('normalizeHex returns null for an unparseable value', function () {
    expect(AccentQuality::normalizeHex('not-a-color'))->toBeNull();
    expect(AccentQuality::normalizeHex('#12'))->toBeNull();
});
