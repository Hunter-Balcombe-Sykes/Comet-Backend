<?php

use App\Models\Core\Site\Site;
use App\Site\Pools\PoolResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * #W1-SEC-10: PoolResolver scoped its item set to $site->user_id once, then
 * joined content.sources / content.collections filtered only by the ids that
 * scoping produced. content.source_items and content.collection_items carry no
 * user_id of their own, so ONE mislinked source_id or collection_id — a writer
 * bug, a hand-run SQL fix, an identity merge — put another account's row on
 * this page with nothing in the query to stop it.
 *
 * Each case below writes exactly that mislink and asserts the other tenant's
 * row does not govern this page. They are not exploits: the crossing has to
 * exist already. They pin that the query, not a convention, is what rejects it
 * — the posture the identity_candidates read (#SEC-5) already takes.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    setupSectionsTables();
    setupMediaTables();
    // reviewsOutsidePersonScope() joins ingest.sources.
    setupIngestTables();
    Queue::fake();
});

/** A review item owned by $userId, landed by $sourceId. */
function crossTenantReview(string $userId, string $sourceId, string $text): string
{
    $itemId = (string) Str::uuid();
    DB::table('content.items')->insert([
        'id' => $itemId, 'user_id' => $userId, 'kind' => 'review',
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
        'author_name' => 'A Customer', 'author_photo_url' => null, 'author_uri' => null,
        'rating' => 5.0, 'text' => $text, 'reviewed_at' => now()->subDay(),
        'updated_at' => now(),
    ]);

    return $itemId;
}

it('does not let another tenant connection un-suppress a review this owner hid', function () {
    // The harm this pins is a LEAK, not a blank page: suppression needs EVERY
    // source of the item to hide reviews, so one foreign row reading "shown"
    // was enough to republish what the owner switched off.
    [$proA, $siteAId] = poolBusinessTenant();
    [$proB] = poolBusinessTenant();

    $sourceA = poolSource($proA->id, poolConnection($proA->id, 'google_business.listing', ['reviews' => false]));
    $sourceB = poolSource($proB->id, poolConnection($proB->id, 'google_business.listing', ['reviews' => true]));

    $itemA = crossTenantReview($proA->id, $sourceA, 'Owner A hid this review');

    // The mislink: a second source_item row on A's item pointing at B's source.
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceB,
        'coord' => 'review:'.Str::random(8), 'item_id' => $itemA, 'kind' => 'review',
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    $siteA = Site::query()->findOrFail($siteAId);

    $resolved = app(PoolResolver::class)->resolve($siteA, 'reviews');

    expect(collect($resolved['selection'])->firstWhere('id', $itemA))->toBeNull();
    expect(json_encode($resolved))->not->toContain('Owner A hid this review');

    // hasSelection() shares the helper and must agree, or the page is
    // advertised in nav with an empty pool behind it.
    expect(app(PoolResolver::class)->hasSelection($siteA, 'reviews'))->toBeFalse();
});

it('still suppresses on this owner own connection toggle', function () {
    // The counterweight: scoping must not make the toggle unreachable.
    [$pro, $siteId] = poolBusinessTenant();
    $source = poolSource($pro->id, poolConnection($pro->id, 'google_business.listing', ['reviews' => false]));
    crossTenantReview($pro->id, $source, 'Hidden by its owner');

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'reviews');

    expect($resolved['selection'])->toBe([]);
});

it('still publishes a review whose own connection leaves reviews on', function () {
    [$pro, $siteId] = poolBusinessTenant();
    $source = poolSource($pro->id, poolConnection($pro->id, 'google_business.listing', ['reviews' => true]));
    $itemId = crossTenantReview($pro->id, $source, 'Shown by its owner');

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'reviews');

    expect(collect($resolved['selection'])->firstWhere('id', $itemId))->not->toBeNull();
});

it('never publishes another tenant star rating as this site stats badge', function () {
    [$proA, $siteAId] = poolBusinessTenant();
    [$proB] = poolBusinessTenant();

    $sourceA = poolSource($proA->id, poolConnection($proA->id, 'google_business.listing', ['reviews' => true]));
    $sourceB = poolSource($proB->id, poolConnection($proB->id, 'google_business.listing', ['reviews' => true]));

    $itemA = crossTenantReview($proA->id, $sourceA, 'A genuine review of A');

    // A's item also carries a source_item pointing at B's source, and it is
    // B — not A — who has the aggregate. statsFor() reaches sources THROUGH
    // source_items, so nothing but an explicit predicate rejects this.
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceB,
        'coord' => 'review:'.Str::random(8), 'item_id' => $itemA, 'kind' => 'review',
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    DB::table('content.source_stats')->insert([
        'source_id' => $sourceB, 'rating_avg' => 4.9, 'rating_count' => 812,
        'summary_text' => 'Owner B is wonderful', 'updated_at' => now(),
    ]);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteAId), 'reviews');

    expect($resolved['stats'])->toBeNull();
    expect(json_encode($resolved))->not->toContain('Owner B is wonderful');
});

it('still publishes the owner own stats badge', function () {
    [$pro, $siteId] = poolBusinessTenant();
    $source = poolSource($pro->id, poolConnection($pro->id, 'google_business.listing', ['reviews' => true]));
    crossTenantReview($pro->id, $source, 'A genuine review');

    DB::table('content.source_stats')->insert([
        'source_id' => $source, 'rating_avg' => 4.8, 'rating_count' => 127,
        'summary_text' => null, 'updated_at' => now(),
    ]);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'reviews');

    expect($resolved['stats']['ratingAvg'])->toBe(4.8);
    expect($resolved['stats']['ratingCount'])->toBe(127);
});

it('never groups this owner items under another tenant collection label', function () {
    [$proA, $siteAId] = poolTenant();
    [$proB] = poolTenant();

    $sourceA = poolSource($proA->id, poolConnection($proA->id, 'fresha.book'));
    $itemA = poolItem($proA->id, $sourceA, 'service', 'A Haircut', now()->toDateTimeString());
    poolPin($siteAId, 'services', $itemA);

    // A collection owned by B, with A's item mislinked into it.
    $collectionB = (string) Str::uuid();
    DB::table('content.collections')->insert([
        'id' => $collectionB, 'user_id' => $proB->id, 'parent_id' => null,
        'label' => 'Owner B Private Category', 'kind' => 'service_category',
        'external_ref' => 'b-cat', 'position' => 0, 'is_user_created' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.collection_items')->insert([
        'collection_id' => $collectionB, 'item_id' => $itemA,
        'source_id' => $sourceA, 'position' => 0,
    ]);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteAId), 'services');

    expect(array_keys($resolved['collections']))->not->toContain($collectionB);
    expect(json_encode($resolved))->not->toContain('Owner B Private Category');
});

it('still groups an item under its own owner collection', function () {
    [$pro, $siteId] = poolTenant();

    $source = poolSource($pro->id, poolConnection($pro->id, 'fresha.book'));
    $itemId = poolItem($pro->id, $source, 'service', 'A Haircut', now()->toDateTimeString());
    poolPin($siteId, 'services', $itemId);

    $collectionId = (string) Str::uuid();
    DB::table('content.collections')->insert([
        'id' => $collectionId, 'user_id' => $pro->id, 'parent_id' => null,
        'label' => 'Cuts', 'kind' => 'service_category', 'external_ref' => 'cuts',
        'position' => 0, 'is_user_created' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.collection_items')->insert([
        'collection_id' => $collectionId, 'item_id' => $itemId,
        'source_id' => $source, 'position' => 0,
    ]);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'services');

    expect(array_keys($resolved['collections']))->toContain($collectionId);
});
