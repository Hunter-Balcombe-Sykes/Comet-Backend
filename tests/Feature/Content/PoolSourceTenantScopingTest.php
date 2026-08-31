<?php

use App\Models\Core\Site\Site;
use App\Site\Pools\PoolResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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
    // The media case resolves a storage_path through MediaUrlResolver.
    Storage::fake('media');
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

// ── Round 2: the two joins the first pass missed ─────────────────────────────

it('never publishes another tenant media asset url or storage path', function () {
    // content.item_media carries no user_id, so asset_id is a SECOND FK hop out
    // of the owner-scoped id list. What leaks is not a label: source_url and
    // storage_path go straight to MediaUrlResolver and onto the public wire.
    [$proA, $siteAId] = poolTenant();
    [$proB] = poolTenant();

    $sourceA = poolSource($proA->id, null);
    // kind 'media', not 'video': `frames` (the gallery-role leak) only ships on
    // the media and product kinds, so on a video the second assertion below
    // would pass without ever reaching the payload.
    $itemA = poolItem($proA->id, $sourceA, 'media', 'A photo of A', '2026-08-01T00:00:00Z');
    poolPin($siteAId, 'media', $itemA);

    // TWO of B's assets, mislinked onto A's item. Two because MediaUrlResolver
    // prefers storage_path over source_url on a single asset, so one row would
    // make whichever assertion lost that precedence vacuous.
    $assetStored = (string) Str::uuid();
    $assetLinked = (string) Str::uuid();
    DB::table('content.media_assets')->insert([
        ['id' => $assetStored, 'user_id' => $proB->id, 'fingerprint' => 'fp-'.Str::random(8),
            'source_url' => null, 'storage_path' => 'media/owner-b/private.jpg',
            'mime_type' => 'image/jpeg', 'width' => 800, 'height' => 600, 'created_at' => now()],
        ['id' => $assetLinked, 'user_id' => $proB->id, 'fingerprint' => 'fp-'.Str::random(8),
            'source_url' => 'https://cdn.example.test/owner-b-private-photo.jpg',
            'storage_path' => null,
            'mime_type' => 'image/jpeg', 'width' => 800, 'height' => 600, 'created_at' => now()],
    ]);
    DB::table('content.item_media')->insert([
        ['id' => (string) Str::uuid(), 'item_id' => $itemA, 'source_id' => $sourceA,
            'asset_id' => $assetStored, 'role' => 'cover', 'position' => 0,
            'alt_text' => null, 'created_at' => now()],
        ['id' => (string) Str::uuid(), 'item_id' => $itemA, 'source_id' => $sourceA,
            'asset_id' => $assetLinked, 'role' => 'gallery', 'position' => 1,
            'alt_text' => null, 'created_at' => now()],
    ]);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteAId), 'media');
    // UNESCAPED_SLASHES, deliberately: json_encode writes `media\/owner-b`, so a
    // path assertion against the default encoding never matches and is vacuous.
    $encoded = json_encode($resolved, JSON_UNESCAPED_SLASHES);

    expect($encoded)->not->toContain('media/owner-b/private.jpg');
    expect($encoded)->not->toContain('owner-b-private-photo.jpg');
    expect(collect($resolved['library'])->firstWhere('id', $itemA)['thumbnail'])->toBeNull();
    expect(collect($resolved['library'])->firstWhere('id', $itemA)['frames'])->toBe([]);
});

it('still publishes this owner own media asset', function () {
    // The counterweight: scoping must not blank every thumbnail on the site.
    [$pro, $siteId] = poolTenant();

    $source = poolSource($pro->id, null);
    $itemId = poolItem($pro->id, $source, 'media', 'A photo', '2026-08-01T00:00:00Z');
    poolPin($siteId, 'media', $itemId);

    $assetId = (string) Str::uuid();
    DB::table('content.media_assets')->insert([
        'id' => $assetId, 'user_id' => $pro->id, 'fingerprint' => 'fp-'.Str::random(8),
        'source_url' => 'https://cdn.example.test/owner-own-photo.jpg',
        'storage_path' => null, 'mime_type' => 'image/jpeg',
        'width' => 800, 'height' => 600, 'created_at' => now(),
    ]);
    DB::table('content.item_media')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $source,
        'asset_id' => $assetId, 'role' => 'cover', 'position' => 0,
        'alt_text' => null, 'created_at' => now(),
    ]);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'media');

    expect(collect($resolved['library'])->firstWhere('id', $itemId)['thumbnail'])
        ->toBe('https://cdn.example.test/owner-own-photo.jpg');
});

it('never publishes another tenant storefront checkout url or discount code', function () {
    // content.storefronts is a 1:1 sidecar keyed by collection_id (its PRIMARY
    // KEY), and its user_id is DENORMALISED off the collection — so it can
    // disagree with the collection's owner, which is the whole reason it is
    // pinned separately. What that publishes is a checkout URL and a DISCOUNT
    // CODE, both of which the store treats as the owner's own.
    [$proA, $siteAId] = poolTenant();
    [$proB] = poolTenant();

    $storeA = shopStore($proA->id, ['label' => 'A Store']);
    // The drift: A's collection carrying B's storefront row.
    DB::table('content.storefronts')->where('collection_id', $storeA)->update([
        'user_id' => $proB->id,
        'url' => 'https://owner-b-checkout.example.test',
        'discount_code' => 'OWNERB40',
    ]);

    $itemA = shopProduct($proA->id, $storeA, 'A Jacket');
    poolPin($siteAId, 'shop', $itemA);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteAId), 'shop');
    $encoded = json_encode($resolved);

    expect($encoded)->not->toContain('owner-b-checkout.example.test');
    expect($encoded)->not->toContain('OWNERB40');

    // The LEFT join must survive: the collection itself is A's, so the group
    // header still exists — only the foreign sidecar's fields are gone. A
    // `where` here instead of an ON clause would have dropped the group too.
    expect(array_keys($resolved['collections']))->toContain($storeA);
    expect($resolved['collections'][$storeA]['url'])->toBeNull();
    expect($resolved['collections'][$storeA]['discountCode'])->toBeNull();
});

it('still publishes this owner own storefront url and discount code', function () {
    [$pro, $siteId] = poolTenant();

    $store = shopStore($pro->id, [
        'label' => 'My Store',
        'url' => 'https://my-checkout.example.test',
        'discount_code' => 'MINE10',
    ]);
    $itemId = shopProduct($pro->id, $store, 'My Jacket');
    poolPin($siteId, 'shop', $itemId);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'shop');

    expect($resolved['collections'][$store]['url'])->toBe('https://my-checkout.example.test');
    expect($resolved['collections'][$store]['discountCode'])->toBe('MINE10');
});

it('still groups a menu category that has no storefront sidecar at all', function () {
    // The regression the ON clause exists to avoid, pinned directly: a
    // `where('s.user_id', …)` converts the left join to an inner one, and every
    // sidecar-less collection — every menu and service category — stops
    // grouping. Nothing about tenancy fails here; the join shape does.
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
    expect($resolved['collections'][$collectionId]['provider'])->toBeNull();
});
