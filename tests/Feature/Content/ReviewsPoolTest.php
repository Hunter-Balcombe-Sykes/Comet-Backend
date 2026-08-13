<?php

use App\Models\Core\Site\Site;
use App\Site\Pools\PoolRegistry;
use App\Site\Pools\PoolResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    setupSectionsTables();
    setupMediaTables();
    Queue::fake();
});

/**
 * One review item, sourced from a google-business connection.
 *
 * Reuses PoolLaneTest's poolTenant/poolConnection/poolSource rather than
 * minting a parallel fixture lane — the same cross-file reuse PoolWireShapeTest
 * already relies on.
 *
 * @return array{object, string, string, string} [owner, siteId, itemId, connectionId]
 */
function reviewPoolFixture(array $review = [], ?array $displaySettings = null): array
{
    [$pro, $siteId] = poolTenant();
    $connectionId = poolConnection($pro->id, 'google_business.listing', $displaySettings);
    $sourceId = poolSource($pro->id, $connectionId);

    $itemId = (string) Str::uuid();
    DB::table('content.items')->insert([
        'id' => $itemId, 'user_id' => $pro->id, 'kind' => 'review',
        // Null by contract since slice 6 §2.3 — the reviewer's name lives in
        // f_review alone.
        'headline_cache' => null, 'facets_cache' => '["f_review"]', 'eligible_cache' => '[]',
        'first_seen_at' => now()->subDay(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId,
        'coord' => 'review:'.Str::random(8), 'item_id' => $itemId, 'kind' => 'review',
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    DB::table('content.f_review')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId,
        'author_name' => null, 'author_photo_url' => null, 'author_uri' => null,
        'rating' => null, 'text' => null, 'reviewed_at' => null,
        ...$review,
        'updated_at' => now(),
    ]);

    return [$pro, $siteId, $itemId, $connectionId];
}

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

// ── The wire (§4.2) ─────────────────────────────────────────────────────────

// Attribution comes from f_review — the ONE copy redaction, the prune command
// and the DSAR omission all reach. Sourcing it from the headline is the §2.2
// defect this slice exists to close, so the null headline is asserted here too.
it('ships rating, text and attribution on a review item', function () {
    [, $siteId, $itemId] = reviewPoolFixture([
        'author_name' => 'A Real Person',
        'author_photo_url' => 'https://lh3.googleusercontent.com/a/abc',
        'author_uri' => 'https://maps.google.com/contrib/123',
        'rating' => 5.0,
        'text' => 'Excellent service.',
        'reviewed_at' => '2026-07-01T10:00:00Z',
    ]);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'reviews');
    $item = collect($resolved['selection'])->firstWhere('id', $itemId);

    expect($item)->not->toBeNull()
        ->and($item['review'])->toMatchArray([
            'rating' => 5.0,
            'text' => 'Excellent service.',
            'authorName' => 'A Real Person',
            'authorPhotoUrl' => 'https://lh3.googleusercontent.com/a/abc',
            'authorUri' => 'https://maps.google.com/contrib/123',
            'reviewedAt' => '2026-07-01T10:00:00Z',
        ])
        ->and($item['headline'])->toBeNull();
});

// An unclaimed owner's record lands post-redaction, so the card must render
// the rating and the words with no attribution rather than fail to build.
it('ships a redacted review with rating and text but no attribution', function () {
    [, $siteId, $itemId] = reviewPoolFixture(['rating' => 3.0, 'text' => 'Fine.']);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'reviews');
    $item = collect($resolved['selection'])->firstWhere('id', $itemId);

    expect($item['review'])->toMatchArray([
        'rating' => 3.0,
        'text' => 'Fine.',
        'authorName' => null,
        'authorPhotoUrl' => null,
        'authorUri' => null,
    ]);
});

// Wire shape must not change with kind — the contract startsAt/venue/price
// and frames already keep.
it('ships a null review block on non-review kinds', function () {
    [$pro, $siteId] = poolTenant();
    $itemId = poolItem($pro->id, poolSource($pro->id, poolConnection($pro->id)), 'video', 'A video', now()->toDateTimeString());

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'watch');
    $item = collect($resolved['selection'])->firstWhere('id', $itemId);

    expect($item)->toHaveKey('review')
        ->and($item['review'])->toBeNull();
});
