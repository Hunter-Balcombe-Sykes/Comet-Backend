<?php

use App\Models\Core\Site\Site;
use App\Services\Accounts\AccountCapabilities;
use App\Services\PublicSite\IndividualProfilePayloadBuilder;
use App\Site\Pools\PoolRegistry;
use App\Site\Pools\PoolResolver;
use App\Site\Pools\PoolSectionProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    setupSectionsTables();
    setupMediaTables();
    // Person-scoping (2026-08-28) joins ingest.sources to spot vendor
    // employee-scoped review sources, so the mirror must exist.
    setupIngestTables();
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
    // Venue-level reviews shown WHOLESALE is business behaviour since the
    // person-scoping capability (2026-08-28): a partna account keeps only
    // reviews attributable to them. These fixtures model the venue case.
    [$pro, $siteId] = poolBusinessTenant();
    $connectionId = poolConnection($pro->id, 'google_business.listing', $displaySettings);
    $sourceId = poolSource($pro->id, $connectionId);

    $itemId = (string) Str::uuid();
    DB::table('content.items')->insert([
        'id' => $itemId, 'user_id' => $pro->id, 'kind' => 'review',
        // Null by contract since slice 6 §2.3 — the reviewer's name lives in
        // f_review alone.
        'headline_cache' => null, 'facets_cache' => '["f_review"]',
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
            // #API-1: '+00:00' rather than the seeded 'Z'. Same instant, and now
            // the same rendering as publishedAt / firstSeenAt / startsAt and the
            // nested sources[] timestamps, all of which go through
            // Carbon::toIso8601String().
            //
            // The OLD assertion passed for the wrong reason: reviewed_at is
            // timestamptz, and on Postgres the driver returns
            // "2026-07-01 10:00:00+00" — space separator, colon-less offset,
            // and a rendering that shifts with the session TimeZone. It only
            // ever read back as 'Z' because SQLite returns the seeded string
            // verbatim. A green run here said nothing about the wire.
            'reviewedAt' => '2026-07-01T10:00:00+00:00',
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

// ── The owner's toggle, and the two write refusals (§4.4, §4.3) ─────────────

// DisplaySettingsFilter gates the LEGACY payload lane only; buildPools() never
// passes through it. Without this gate, an owner who switched reviews off has
// them republished by the pool — a regression against their express setting.
it('drops review items whose connection suppresses reviews', function () {
    [, $siteId, $itemId] = reviewPoolFixture(['rating' => 5.0], ['reviews' => false]);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'reviews');

    expect(collect($resolved['selection'])->firstWhere('id', $itemId))->toBeNull()
        ->and(collect($resolved['library'])->firstWhere('id', $itemId))->toBeNull();
});

// The toggle is keyed to the SOURCE, not the pool: a suppressed google-business
// connection must not take a second platform's reviews down with it.
it('keeps a review carried by a second, unsuppressed source', function () {
    [$pro, $siteId, $itemId] = reviewPoolFixture(['rating' => 5.0], ['reviews' => false]);

    $otherSource = poolSource($pro->id, poolConnection($pro->id, 'yelp.listing'));
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $otherSource,
        'coord' => 'review:'.Str::random(8), 'item_id' => $itemId, 'kind' => 'review',
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'reviews');

    expect(collect($resolved['selection'])->firstWhere('id', $itemId))->not->toBeNull();
});

// A toggle switched off on the reviews connection must not take that same
// connection's PHOTOS down with it — the gate is scoped to review items.
it('leaves non-review items from a reviews-suppressed connection alone', function () {
    [$pro, $siteId] = poolTenant();
    $connectionId = poolConnection($pro->id, 'google_business.listing', ['reviews' => false]);
    $itemId = poolItem($pro->id, poolSource($pro->id, $connectionId), 'media', 'A photo', now()->toDateTimeString());

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'media');

    expect(collect($resolved['selection'])->firstWhere('id', $itemId))->not->toBeNull();
});

it('refuses a manual add to the reviews pool', function () {
    [$pro] = poolTenant();

    actingAsUser($pro)
        ->postJson('/api/content/pools/reviews/items', ['url' => 'https://example.com/x'])
        ->assertStatus(422);

    expect(DB::table('content.items')->where('user_id', $pro->id)->count())->toBe(0);
});

// Slice 6 §4.5 — provisioning. There is no reviews backfill command and no
// per-pool branch in PoolSectionProvisioner: resolve() ensures the section on
// first read and buildPools() iterates every PoolRegistry::POOLS key, so an
// existing site with a google_business source grows `pool:reviews` the first
// time anything reads the pool. Pinned because the alternative branch (a
// provisioning command) was the one the plan expected; if a later change moves
// provisioning off the read path, existing sites silently get no section.
it('provisions the reviews section on first read, with the pool shape', function () {
    [, $siteId] = reviewPoolFixture(['rating' => 5.0]);
    $site = Site::query()->findOrFail($siteId);

    expect(DB::table('site.sections')->where('site_id', $siteId)->where('key', 'pool:reviews')->count())
        ->toBe(0);

    app(PoolResolver::class)->resolve($site, 'reviews');

    $section = DB::table('site.sections')
        ->where('site_id', $siteId)->where('key', 'pool:reviews')->first();

    expect($section)->not->toBeNull()
        ->and($section->mode)->toBe('mixed')
        ->and($section->order_by)->toBe('recency')
        ->and(json_decode((string) $section->rule, true))
        ->toBe(['all' => [['op' => 'kind_is', 'values' => ['review']]]])
        ->and(DB::table('site.pages')->where('site_id', $siteId)->where('key', 'reviews')->count())
        ->toBe(1);
});

it('refuses a pin on the reviews pool but allows an exclusion', function () {
    [$pro, $siteId, $itemId] = reviewPoolFixture(['rating' => 5.0]);

    $sectionId = (string) app(PoolSectionProvisioner::class)
        ->ensure(Site::query()->findOrFail($siteId), 'reviews')->id;

    actingAsUser($pro)
        ->putJson("/api/site/sections/{$sectionId}/items/{$itemId}", ['state' => 'pinned'])
        ->assertStatus(422);

    actingAsUser($pro)
        ->putJson("/api/site/sections/{$sectionId}/items/{$itemId}", ['state' => 'excluded'])
        ->assertOk();

    expect(DB::table('site.section_items')->where('item_id', $itemId)->value('state'))
        ->toBe('excluded');
});

// Slice 6 §5.4 retired `rating`, `reviewCount` and `reviewSummary` from
// PublicIntegrationConnectionResource on the promise that content.source_stats
// serves them instead. Retiring a key is easy to assert (not->toHaveKey); the
// ARRIVAL is the half that gets forgotten, so it is pinned here.
it('ships the place aggregates as pool stats', function () {
    [$pro, $siteId, $itemId] = reviewPoolFixture(['rating' => 5.0]);

    DB::table('content.source_stats')->insert([
        'source_id' => DB::table('content.sources')->where('user_id', $pro->id)->value('id'),
        'rating_avg' => 4.8, 'rating_count' => 127,
        'summary_text' => 'Customers praise the friendly staff.',
        'updated_at' => now(),
    ]);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'reviews');

    expect($resolved['stats'])->toBe([
        'ratingAvg' => 4.8,
        'ratingCount' => 127,
        'summaryText' => 'Customers praise the friendly staff.',
        // #LABEL-1: the aggregates arrive WITH their provenance, because a
        // number the consumer cannot attribute is a number it will attribute
        // to whatever surface it is folding onto.
        'scope' => 'listing',
        'platform' => 'google-business',
        'placeId' => null,
    ]);
});

// A place with reviews but no aggregates row must not publish a zero-star
// badge — the §5.2 accepted gap is "no stats", not "stats of nothing".
it('ships null stats when the source carries no aggregates', function () {
    [$pro, $siteId] = reviewPoolFixture(['rating' => 5.0]);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'reviews');

    expect($resolved['stats'])->toBeNull();
});

// The owner's toggle governs the badge as well as the cards. Serving a 4.8
// rating for an owner who switched reviews off would republish the thing they
// switched off, in summary form.
it('withholds stats when the owner suppresses reviews', function () {
    [$pro, $siteId] = reviewPoolFixture(['rating' => 5.0], ['reviews' => false]);

    DB::table('content.source_stats')->insert([
        'source_id' => DB::table('content.sources')->where('user_id', $pro->id)->value('id'),
        'rating_avg' => 4.8, 'rating_count' => 127, 'summary_text' => null,
        'updated_at' => now(),
    ]);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'reviews');

    expect($resolved['stats'])->toBeNull();
});

// Every other pool keeps a stats key so the wire shape does not change with
// pool — the same contract `collections` keeps.
it('ships null stats on a pool that has no aggregates lane', function () {
    [$pro, $siteId] = poolTenant();
    poolItem($pro->id, poolSource($pro->id, poolConnection($pro->id)), 'video', 'A video', now()->toDateTimeString());

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'watch');

    expect($resolved)->toHaveKey('stats')->and($resolved['stats'])->toBeNull();
});

// The one that actually closes the §5.4 promise. A resolver-level assertion
// would have passed all the way through the retirement landing with NOTHING
// reading content.source_stats on a public path — which is exactly what
// happened. This drives the real public payload builder.
it('publishes the aggregates on the public profile payload', function () {
    // Provisioned here rather than in beforeEach, following EventsPoolTest —
    // only the payload-builder cases walk blocks and the design kit.
    setupBlocksTable();
    setupServicesTable();
    setupDesignKitsTable();

    [$pro, $siteId, $itemId] = reviewPoolFixture(['rating' => 5.0]);

    DB::table('content.source_stats')->insert([
        'source_id' => DB::table('content.sources')->where('user_id', $pro->id)->value('id'),
        'rating_avg' => 4.83, 'rating_count' => 12719,
        'summary_text' => 'Punters rave about the razor fades.',
        'updated_at' => now(),
    ]);

    $payload = app(IndividualProfilePayloadBuilder::class)
        ->build($pro->fresh(), Site::query()->findOrFail($siteId));

    // The resource casts pools to an object so an empty map serializes {}.
    $reviews = $payload['profile']['pools']->reviews ?? null;

    expect($reviews)->not->toBeNull()
        ->and($reviews['stats'])->toBe([
            'ratingAvg' => 4.83,
            'ratingCount' => 12719,
            'summaryText' => 'Punters rave about the razor fades.',
            'scope' => 'listing',
            'platform' => 'google-business',
            'placeId' => null,
        ]);
});

// Absent, not null — the same contract `collections` keeps, so a pool with no
// aggregates does not ship an empty badge object for the renderer to guard.
it('omits the stats key entirely when there are no aggregates', function () {
    setupBlocksTable();
    setupServicesTable();
    setupDesignKitsTable();

    [$pro, $siteId] = reviewPoolFixture(['rating' => 5.0]);

    $payload = app(IndividualProfilePayloadBuilder::class)
        ->build($pro->fresh(), Site::query()->findOrFail($siteId));

    expect($payload['profile']['pools']->reviews)->not->toHaveKey('stats');
});

// hasSelection() decides whether a page is advertised in nav; resolve() decides
// what is behind it. They are documented as the same arithmetic, but only
// resolve() applied the owner's reviews toggle — so an owner who switched
// reviews off would get the page linked and an empty pool behind it, the exact
// pathology the surrounding comments warn about. Latent (reviews is not in
// SitepageDataResolverService's probe loop yet), pinned so it stays that way.
it('reports no selection when the owner suppresses reviews', function () {
    [$pro, $siteId] = reviewPoolFixture(['rating' => 5.0], ['reviews' => false]);

    $site = Site::query()->findOrFail($siteId);

    expect(app(PoolResolver::class)->hasSelection($site, 'reviews'))->toBeFalse()
        // The two must agree — that is the whole contract.
        ->and(app(PoolResolver::class)->resolve($site, 'reviews')['selection'])->toBe([]);
});

it('still reports a selection when reviews are not suppressed', function () {
    [$pro, $siteId] = reviewPoolFixture(['rating' => 5.0]);

    expect(app(PoolResolver::class)->hasSelection(Site::query()->findOrFail($siteId), 'reviews'))
        ->toBeTrue();
});

// The section-curation endpoint above is not the only way to pin. PoolController
// ::select() writes STATE_PINNED directly and is the route the dashboard uses,
// so gating only the other one left EXCLUDE_ONLY_POOLS bypassable. Deleting the
// guard in select() must turn this red on its own.
it('refuses a pin through the pool selection endpoint too', function () {
    [$pro, $siteId, $itemId] = reviewPoolFixture(['rating' => 5.0]);

    actingAsUser($pro)
        ->postJson("/api/content/pools/reviews/selection/{$itemId}")
        ->assertStatus(422);

    expect(DB::table('site.section_items')->where('item_id', $itemId)->count())->toBe(0);
});

// The third pin path. reorder()'s own contract is that dragging an item into
// an order pins it, so an exclusion-only pool has to refuse the whole verb.
it('refuses a reorder of the reviews pool', function () {
    [$pro, $siteId, $itemId] = reviewPoolFixture(['rating' => 5.0]);

    actingAsUser($pro)
        ->putJson('/api/content/pools/reviews/order', ['itemIds' => [$itemId]])
        ->assertStatus(422);

    expect(DB::table('site.section_items')->where('item_id', $itemId)->count())->toBe(0);
});

// Exclusion is the half of curation reviews DO get, and deselect() is how the
// dashboard reaches it — gating the whole controller would have removed it.
it('still allows deselection through the pool selection endpoint', function () {
    [$pro, $siteId, $itemId] = reviewPoolFixture(['rating' => 5.0]);

    actingAsUser($pro)
        ->deleteJson("/api/content/pools/reviews/selection/{$itemId}")
        ->assertOk();
});

// The refusal is about the POOL, not about pinning — every other pool's
// curation must keep working.
it('still allows a pin on another pool', function () {
    [$pro, $siteId] = poolTenant();
    $itemId = poolItem($pro->id, poolSource($pro->id, poolConnection($pro->id)), 'video', 'A video', now()->toDateTimeString());

    $sectionId = (string) app(PoolSectionProvisioner::class)
        ->ensure(Site::query()->findOrFail($siteId), 'watch')->id;

    actingAsUser($pro)
        ->putJson("/api/site/sections/{$sectionId}/items/{$itemId}", ['state' => 'pinned'])
        ->assertOk();
});

// ── Person-scoping (owner, 2026-08-28) ──────────────────────────────────────
// A partna account's reviews pool is scoped to the PERSON: venue-level review
// sources (a Google listing, a Booksy page, storewide Fresha) review the
// workplace, and an individual's page keeps only the reviews attributable to
// them — structured staff attribution, a name mention in the text, or a
// vendor employee-scoped source. Business accounts keep the venue behaviour
// (every fixture above flips its tenant to business for exactly that reason).

/**
 * A venue-level review on a PARTNA tenant whose display name is $displayName.
 *
 * @return array{object, string, string, string} [owner, siteId, itemId, connectionId]
 */
function partnaReviewFixture(string $displayName, array $review): array
{
    [$pro, $siteId] = poolTenant();
    DB::table('core.users')->where('id', $pro->id)->update([
        'display_name' => $displayName,
        'first_name' => explode(' ', $displayName)[0] ?: 'X',
    ]);
    AccountCapabilities::flushCache();

    $connectionId = poolConnection($pro->id, 'google_business.listing');
    $sourceId = poolSource($pro->id, $connectionId);

    $itemId = (string) Str::uuid();
    DB::table('content.items')->insert([
        'id' => $itemId, 'user_id' => $pro->id, 'kind' => 'review',
        'headline_cache' => null, 'facets_cache' => '["f_review"]',
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
        'rating' => 5.0, 'text' => null, 'reviewed_at' => null, 'staff_name' => null,
        ...$review,
        'updated_at' => now(),
    ]);

    return [$pro, $siteId, $itemId, $connectionId];
}

it('keeps a venue review that mentions the person by first name', function () {
    [, $siteId, $itemId] = partnaReviewFixture('Simon Doyle', [
        'text' => 'Simon gave me the best cut of my life.',
    ]);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'reviews');

    expect(collect($resolved['selection'])->firstWhere('id', $itemId))->not->toBeNull()
        ->and(app(PoolResolver::class)->hasSelection(Site::query()->findOrFail($siteId), 'reviews'))->toBeTrue();
});

it('keeps a venue review whose staff attribution names the person', function () {
    [, $siteId, $itemId] = partnaReviewFixture('Simon Doyle', [
        'text' => 'Great haircut, highly recommend.',
        'staff_name' => 'Simon',
    ]);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'reviews');

    expect(collect($resolved['selection'])->firstWhere('id', $itemId))->not->toBeNull();
});

it('drops a venue review that never mentions the person, from the pool AND the nav probe', function () {
    [, $siteId, $itemId] = partnaReviewFixture('Simon Doyle', [
        'text' => 'Jack is the one to go to. Amazing fade.',
        'staff_name' => 'Jack',
    ]);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'reviews');

    expect(collect($resolved['selection'])->firstWhere('id', $itemId))->toBeNull()
        ->and(app(PoolResolver::class)->hasSelection(Site::query()->findOrFail($siteId), 'reviews'))->toBeFalse();
});

it('does not let a short lead token attribute by text mention', function () {
    // "DJ Shadow" — the 2-letter lead is too weak to match text on; the full
    // name still matches.
    [, $siteId, $itemId] = partnaReviewFixture('Dj Shadow', [
        'text' => 'The dj was fine but the venue was cold.',
    ]);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'reviews');

    expect(collect($resolved['selection'])->firstWhere('id', $itemId))->toBeNull();
});

it('keeps every review from a vendor employee-scoped source regardless of names', function () {
    [, $siteId, $itemId, $connectionId] = partnaReviewFixture('Simon Doyle', [
        'text' => 'Best cut in Dublin.',
    ]);

    DB::table('ingest.sources')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => (string) DB::table('site.platform_connections')->where('id', $connectionId)->value('user_id'),
        'connection_id' => $connectionId, 'source_key' => 'fresha',
        'surface_key' => 'fresha.book', 'identifier' => 'simon-doyle-hair',
        'selection_ref' => '5182247', 'cost_units' => 1,
        'min_interval_secs' => 3600, 'max_interval_secs' => 604800,
        'auto_sync' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'reviews');

    expect(collect($resolved['selection'])->firstWhere('id', $itemId))->not->toBeNull();
});

it('fails closed when the partna account has no usable name', function () {
    [, $siteId, $itemId] = partnaReviewFixture('', ['text' => 'Wonderful experience.']);
    DB::table('core.users')->where('id', DB::table('site.sites')->where('id', $siteId)->value('user_id'))
        ->update(['display_name' => '', 'first_name' => '']);
    AccountCapabilities::flushCache();

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'reviews');

    expect(collect($resolved['selection'])->firstWhere('id', $itemId))->toBeNull();
});
