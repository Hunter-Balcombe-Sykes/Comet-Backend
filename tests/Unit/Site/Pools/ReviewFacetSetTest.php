<?php

use App\Site\Pools\ReviewFacetSet;

// The two questions the reviews person-scope asks of one review's f_review
// rows, and the asymmetry between them (see the class docblock): structured
// attribution is read over EVERY row because it is a claim about the review;
// prose is read over the PUBLISHED row alone because it is a claim about the
// words on the card, and only one vendor's wording is ever on the card.
//
// publishedTextNames() is the last tier of the gate in
// PoolResolver::reviewsOutsidePersonScope(): a review with no staff attribution
// and no employee-scoped source reaches it, and a `true` here is what puts that
// review on a named person's page. Its empty case was unpinned — the whole
// suite passed with the null branch inverted, which admits a review that has NO
// FACET ROW AT ALL on the grounds that nothing in its (nonexistent) prose
// contradicts the claim. ReviewFacets::for() hands out exactly that empty set
// for any item without an f_review row, so the branch is reachable in one hop
// from the live gate, and it fails OPEN.

/** One content.f_review row, in the shape ReviewFacets::forItems() selects. */
function rfsRow(?string $text, ?string $staffName = null): object
{
    return (object) ['text' => $text, 'staff_name' => $staffName];
}

/** The owner's name forms, as PoolResolver::personNameTokens() builds them. */
function rfsNames(): array
{
    return ['full' => ['Raff McGuiness'], 'first' => ['Raff']];
}

// ── published(): the row a visitor actually reads ──────────────────────────

it('publishes the LAST row, because that is the one ReviewFacets ordered to render', function () {
    $set = ReviewFacetSet::of([rfsRow('Raff was wonderful.'), rfsRow('Great service today, thanks!')]);

    expect($set->published()->text)->toBe('Great service today, thanks!');
});

it('has nothing to publish when the review carries no facet row', function () {
    expect(ReviewFacetSet::of([])->published())->toBeNull();
});

// ── publishedTextNames(): prose, over the published row only ───────────────

it('finds the owner named in the prose that will be on the card', function () {
    expect(ReviewFacetSet::of([rfsRow('Raff was wonderful.')])->publishedTextNames(rfsNames()))->toBeTrue();
});

it('reads the published row and not the one it replaced', function () {
    // The incident this class was extracted for: "Raff was wonderful" on the
    // older Google copy admitted an item the newer Fresha copy then rendered as
    // "Great service today, thanks!" — a venue review on a named person's page,
    // admitted by a sentence nobody could see.
    $set = ReviewFacetSet::of([rfsRow('Raff was wonderful.'), rfsRow('Great service today, thanks!')]);

    expect($set->publishedTextNames(rfsNames()))->toBeFalse();
});

it('names nobody when there is no row to read', function () {
    // THE unpinned branch. An item with no f_review row cannot claim anyone:
    // there is no card, no prose, and no evidence — and this is the tier that
    // admits a review on prose alone, so "no evidence" must answer no. Inverting
    // the null half of this line publishes every review the earlier tiers could
    // not identify, which is the fail-open direction the whole scope exists to
    // close.
    expect(ReviewFacetSet::of([])->publishedTextNames(rfsNames()))->toBeFalse();
});

it('names nobody when the account has no usable name on file', function () {
    // A null name set is PersonNameMatch refusing the account's columns as
    // names at all (the broken-oven case). Suppress, never admit.
    expect(ReviewFacetSet::of([rfsRow('Raff was wonderful.')])->publishedTextNames(null))->toBeFalse();
});

// ── staffNames(): structured attribution, over every row ───────────────────

it('collects the vendor attribution from every row, not just the published one', function () {
    // Google carries no staff_name, Fresha carries "Ciel". An attribution naming
    // a colleague has to veto from whichever row happens to hold it.
    $set = ReviewFacetSet::of([rfsRow('Lovely cut.', null), rfsRow('Great service today, thanks!', 'Ciel')]);

    expect($set->staffNames())->toBe(['Ciel']);
});

it('treats a blank staff_name as no attribution rather than an empty one', function () {
    expect(ReviewFacetSet::of([rfsRow('Lovely cut.', '   ')])->staffNames())->toBe([]);
});
