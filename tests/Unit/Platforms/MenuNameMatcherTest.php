<?php

use App\Services\Platforms\MenuNameMatcher;

// backend-fixes item 2 — one fixture per confirmed miss from the live audit
// (ollies website-scan vs Uber Eats, 2026-08-26). Certain/high rows must
// match; medium rows must FLAG, never auto-merge; the same-source pair must
// do neither.

$matcher = new MenuNameMatcher;

// ── Pass 2: variantKey ───────────────────────────────────────────────────────

it('matches a name that differs only by a parenthetical suffix (certain row)', function () use ($matcher) {
    expect($matcher->variantKey('Orthodox Drip Coffee Bags (7 Sachets)'))
        ->toBe($matcher->variantKey('Orthodox Drip Coffee Bags'));
});

it('unwraps a generic wrapper to the parenthetical identity (high row)', function () use ($matcher) {
    // "Cold Brew Bags." is product-form vocabulary; "(italo Concentrate 1.2l)"
    // is the identity — and units compare case-insensitively.
    expect($matcher->variantKey('Cold Brew Bags. (italo Concentrate 1.2l)'))->toBe('italo concentrate 1.2l');
});

it('keeps a distinctive outer name instead of unwrapping (no false unwrap)', function () use ($matcher) {
    expect($matcher->variantKey('Cookie (anzac).'))->toBe('cookie');
});

it('normalizes trailing punctuation and units', function () use ($matcher) {
    expect($matcher->variantKey('Bourdain Roll.'))->toBe('bourdain roll')
        ->and($matcher->variantKey('Cold Brew 1.2L'))->toBe($matcher->variantKey('cold brew 1.2l'));
});

// ── Pass 3: brandLineMatch ───────────────────────────────────────────────────

it('merges retail vs marketplace naming of one brand line (high rows)', function () use ($matcher) {
    expect($matcher->brandLineMatch('Orthodox House Espresso Blend', 'Orthodox Whole Beans'))->toBe('merge')
        ->and($matcher->brandLineMatch('Italo Disco Italian Espresso Blend', 'Italo Disco Whole Beans'))->toBe('merge');
});

it('flags but never merges across a size disagreement (medium row)', function () use ($matcher) {
    // "Wide Awake Cold Brew Concentrate 2L" vs "Cold Brew Bags. (wide Awake)":
    // brand line matches, but 2L exists on one side only — flag, not merge.
    expect($matcher->brandLineMatch('Wide Awake Cold Brew Concentrate 2L', 'Cold Brew Bags. (wide Awake)'))->toBe('flag');
});

it('refuses to match different products that merely share a brand', function () use ($matcher) {
    // A distinctive non-generic remainder token means a DIFFERENT product.
    expect($matcher->brandLineMatch('Orthodox Drip Coffee Bags', 'Orthodox Chocolate Bar'))->toBeNull();
});

it('refuses to match disjoint brands entirely', function () use ($matcher) {
    expect($matcher->brandLineMatch('Orthodox Whole Beans', 'Wide Awake Whole Beans'))->toBeNull()
        ->and($matcher->brandLineMatch('Matcha', 'Almighty'))->toBeNull();
});

it('treats a shorter brand prefix as the same line (italo ⊂ italo disco)', function () use ($matcher) {
    expect($matcher->brandLineMatch('Italo Disco Espresso Concentrate 1.2L', 'Cold Brew Bags. (italo Concentrate 1.2l)'))->toBe('merge');
});

it('yields NO key for names whose identity would be only a size or generic words (collision guard)', function () use ($matcher) {
    // Gate-critic regression (2026-08-27): these pairs collided on "12 pack" /
    // "500ml" and would have fused unrelated dishes.
    expect($matcher->variantKey('Hot Coffee (12 Pack)'))->toBe('')
        ->and($matcher->variantKey('Iced Coffee (12 Pack)'))->toBe('')
        ->and($matcher->variantKey('Cold Brew (500ml)'))->toBe('')
        ->and($matcher->variantKey('Filter Coffee (500ml)'))->toBe('')
        // A distinctive parenthetical still unwraps.
        ->and($matcher->variantKey('Cold Brew Bags. (wide Awake)'))->toBe('wide awake');
});
