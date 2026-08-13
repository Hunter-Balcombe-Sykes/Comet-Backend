<?php

use App\Site\Pools\PoolRegistry;

// Slice 6 §4 — the reviews pool. Reviews are third-party words about the
// owner, not the owner's own content, so the pool's rules differ from every
// other pool's in three ways that all trace back to the same fact: the person
// who wrote the review never consented and holds no account.

it('owns the review kind', function () {
    expect(PoolRegistry::kinds('reviews'))->toBe(['review'])
        ->and(PoolRegistry::poolForKind('review'))->toBe('reviews');
});

// A "latest review" tag would present a vendor-curated sample of five as a
// chronology of the business's feedback.
it('does not carry the Latest tag', function () {
    expect(PoolRegistry::carriesLatestTag('reviews'))->toBeFalse();
});

// The default shape's latest_per_auto_source emits ONE item per source, which
// for a five-review sample means one review shown and four hidden — the same
// pathology media (slice 1a) and events (slice 2) each hit.
it('uses its own section shape, not the rolling-latest default', function () {
    $shape = PoolRegistry::sectionShape('reviews');

    expect(collect($shape['rule'])->pluck('op')->all())->not->toContain('latest_per_auto_source')
        ->and(collect($shape['rule'])->pluck('op')->all())->toContain('kind_is');
});

it('allows exclusion but refuses pinning', function () {
    expect(PoolRegistry::allowsPin('reviews'))->toBeFalse()
        ->and(PoolRegistry::allowsPin('watch'))->toBeTrue();
});

// Hand-authoring an item of kind `review` is fabricating a testimonial
// attributed to a customer.
it('forbids manual adds', function () {
    expect(PoolRegistry::allowsManualAdd('reviews'))->toBeFalse()
        ->and(PoolRegistry::allowsManualAdd('watch'))->toBeTrue();
});
