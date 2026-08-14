<?php

use App\Services\Content\KindRegistry;
use App\Site\Pools\ItemLinkRules;
use App\Site\Pools\PoolRegistry;

// Convergence Phase 3 (W5) — the CUSTOM LINKS pool. Fed by the 23
// `partna.custom_link` connections only; the other 18 pseudo-platform
// connections (order links, storefronts, reservations) keep their own lanes
// until Phase 6. Unlike reviews, these ARE the owner's own content — a link
// they typed — so the curation flags go the other way.

it('owns the link kind', function () {
    expect(PoolRegistry::kinds('custom_links'))->toBe(['link'])
        ->and(PoolRegistry::poolForKind('link'))->toBe('custom_links');
});

it('hangs off its own page', function () {
    expect(PoolRegistry::PAGE_KEYS['custom_links'])->toBe('links')
        ->and(PoolRegistry::PAGE_LABELS['custom_links'])->toBe('Links')
        ->and(PoolRegistry::sectionKey('custom_links'))->toBe('pool:custom_links')
        ->and(PoolRegistry::poolForSectionKey('pool:custom_links'))->toBe('custom_links');
});

// A link list is an arrangement, not a feed: the owner decides the order and
// nothing about a link is "new". Tagging whichever link was typed last as
// Latest would label an arbitrary row.
it('does not carry the Latest tag', function () {
    expect(PoolRegistry::carriesLatestTag('custom_links'))->toBeFalse();
});

// The default shape's latest_per_auto_source emits ONE item per source. Every
// custom link lands on the user's single MANUAL source, so the default would
// publish one link and hide every other — the same pathology media (1a),
// events (2) and reviews (6) each hit, and worse here because the whole pool
// shares one source.
it('uses a bare kind_is shape, not the rolling-latest default', function () {
    $shape = PoolRegistry::sectionShape('custom_links');

    expect($shape['rule'])->toBe([['op' => 'kind_is', 'values' => ['link']]])
        ->and($shape['order_by'])->toBe('recency');
});

// The mirror image of reviews: a custom link is the owner's own, so both
// controls stay open. Asserted against `reviews` rather than in isolation so
// the two halves of the rule are read together.
it('allows pinning and hand-authored links', function () {
    expect(PoolRegistry::allowsPin('custom_links'))->toBeTrue()
        ->and(PoolRegistry::allowsManualAdd('custom_links'))->toBeTrue()
        ->and(PoolRegistry::allowsPin('reviews'))->toBeFalse();
});

// Aggregates describe a connected PLACE (the Google star average). A link has
// no source to aggregate.
it('carries no source stats', function () {
    expect(PoolRegistry::carriesSourceStats('custom_links'))->toBeFalse();
});

// Owner ruling 2026-08-14: link items do NOT carry item_links. A `link` item
// IS a URL — an alternate-platform link on it would be a second URL competing
// with the only field the item has. Enforced by absence: rosterFor() returns
// [] for an unrostered pool, so allowsPlatform() refuses every platform.
it('accepts no hand-saved per-item platform links', function () {
    expect(ItemLinkRules::rosterFor('custom_links'))->toBe([])
        ->and(ItemLinkRules::allowsPlatform('custom_links', 'youtube'))->toBeFalse();
});

// Invariant #2 — a kind is not adopted until something reads it. The kind has
// to exist in the application registry for SectionRuleRules to accept the
// section rule this pool provisions.
it('names a kind the application registry knows', function () {
    expect(KindRegistry::has('link'))->toBeTrue()
        ->and(KindRegistry::describe('link')['pinnable'])->toBeTrue();
});
