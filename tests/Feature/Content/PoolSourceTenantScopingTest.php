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

// ── #FU-2: the connection_id hop ─────────────────────────────────────────────
// content.sources.connection_id is the SECOND FK hop out of the pinned sources,
// and it is NULLABLE (20260727140000 L30) — every kind='manual' source carries
// NULL. So the tenancy predicate has to live in the ON clause of each left join:
// a bare `where` on a left-joined column is an INNER join by another name, and
// would drop the entire manual lane off the public wire. T1 below is the
// regression that catches exactly that; the rest pin the leak.

it('still publishes a manual-lane item with no connection at all', function () {
    // THE null-safety regression. If any of the four ON clauses is ever
    // rewritten as a bare `where`, this fails: the manual source's
    // connection_id is NULL, so `pc.user_id = ?` in the WHERE drops the row.
    [$pro, $siteId] = poolTenant();
    $manualSource = poolSource($pro->id, null);
    $itemId = poolItem($pro->id, $manualSource, 'service', 'Hand-typed Haircut', now()->toDateTimeString());
    poolPin($siteId, 'services', $itemId);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'services');

    $row = collect($resolved['selection'])->firstWhere('id', $itemId);
    expect($row)->not->toBeNull();
    expect($row['headline'])->toBe('Hand-typed Haircut');
    // The item sheet's Sources list is what the $sourceRows join feeds, and a
    // collapsed join empties it silently rather than erroring.
    expect(collect($row['sources'] ?? [])->pluck('kind')->all())->toContain('manual');
});

it('keeps manual-lane items in every pool the wire carries', function () {
    // Breadth: one pool passing does not stand in for nine. Three different
    // kind families, three different payload branches. A plain foreach, not a
    // Pest dataset() — a dataset closure runs before the app boots, so the
    // fixture helpers are not available there.
    [$pro, $siteId] = poolTenant();
    // idx_content_sources_manual allows exactly ONE manual source per user, so
    // all three items hang off the same row.
    $manualSource = poolSource($pro->id, null);

    $expected = [];
    foreach ([['services', 'service'], ['media', 'media'], ['custom_links', 'link']] as [$pool, $kind]) {
        $itemId = poolItem($pro->id, $manualSource, $kind, 'Manual '.$kind, now()->toDateTimeString());
        poolPin($siteId, $pool, $itemId);
        $expected[$pool] = $itemId;
    }

    foreach ($expected as $pool => $itemId) {
        $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), $pool);
        expect(collect($resolved['selection'])->pluck('id')->all())->toContain($itemId);
        expect(collect($resolved['library'])->pluck('id')->all())->toContain($itemId);
    }
});

/** A second source_item row for an existing item, landed by $sourceId. */
function extraSourceItem(string $itemId, string $sourceId, string $kind = 'review'): void
{
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId,
        'coord' => $kind.':'.Str::random(8), 'item_id' => $itemId, 'kind' => $kind,
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
}

it('does not let another owner connection un-suppress reviews this owner hid', function () {
    // The mislink here is on the CONNECTION hop, not the source hop: the source
    // row is A's own (cs.user_id = A, so the #W1-SEC-10 predicate passes) and
    // only its connection_id points at B. Before #FU-2 the join read B's
    // `reviews => true`, that row voted "does not hide", and one such vote
    // defeats the every() that suppression needs.
    [$proA, $siteAId] = poolBusinessTenant();
    [$proB] = poolBusinessTenant();

    $connB = poolConnection($proB->id, 'google_business.listing', ['reviews' => true]);
    $connA = poolConnection($proA->id, 'google_business.listing', ['reviews' => false]);

    $ownSource = poolSource($proA->id, $connA);
    $mislinked = poolSource($proA->id, $connB);

    $itemA = crossTenantReview($proA->id, $ownSource, 'Owner A switched this off');
    extraSourceItem($itemA, $mislinked);

    $siteA = Site::query()->findOrFail($siteAId);
    $resolved = app(PoolResolver::class)->resolve($siteA, 'reviews');

    expect(collect($resolved['selection'])->firstWhere('id', $itemA))->toBeNull();
    expect(json_encode($resolved, JSON_UNESCAPED_SLASHES))->not->toContain('Owner A switched this off');
    expect(app(PoolResolver::class)->hasSelection($siteA, 'reviews'))->toBeFalse();
});

it('drops an unresolved connection from the suppression vote without dropping the item', function () {
    // The two behaviours the second predicate has to keep APART, side by side in
    // ONE resolve() with identical toggles — only the connection's OWNER differs.
    //
    //   $mislinkedItem: one of its sources is A's, pointing at B's connection,
    //                   whose toggle says HIDE. That connection cannot express
    //                   A's intent, so it casts NO vote — and the item, which is
    //                   A's, still PUBLISHES. Dropping it from the PAYLOAD would
    //                   be the wrong fix, and this is what catches that.
    //   $ownItem:       same toggle on A's OWN connection. Still suppressed —
    //                   which is what makes the assertion above non-vacuous
    //                   (the toggle is demonstrably able to suppress).
    //
    // #FU-2 residual 2 (2026-08-31) is why $mislinkedItem now carries a SECOND,
    // manual source. LiveSourceScope::apply() pins its connection hop too, so an
    // item whose ONLY source points at a connection this owner does not own is
    // no longer live and leaves the pool entirely — deliberately, fail-closed,
    // and consistently with the library read, which had already dropped it. That
    // is a liveness verdict, not a vote, and it would mask what this case is
    // about. The manual source makes the item live on its own account so the
    // vote is once again the only thing under test; it votes "does not hide"
    // exactly as it did before.
    [$proA, $siteAId] = poolBusinessTenant();
    [$proB] = poolBusinessTenant();

    $connB = poolConnection($proB->id, 'google_business.listing', ['reviews' => false]);
    $connA = poolConnection($proA->id, 'google_business.listing', ['reviews' => false]);

    $mislinkedItem = crossTenantReview($proA->id, poolSource($proA->id, $connB), 'Owner A wants this published');
    extraSourceItem($mislinkedItem, poolSource($proA->id, null));
    $ownItem = crossTenantReview($proA->id, poolSource($proA->id, $connA), 'Owner A switched this one off');

    $siteA = Site::query()->findOrFail($siteAId);
    $resolved = app(PoolResolver::class)->resolve($siteA, 'reviews');
    $encoded = json_encode($resolved, JSON_UNESCAPED_SLASHES);

    // (a) PUBLISHES — the payload is untouched by the vote change.
    expect(collect($resolved['selection'])->pluck('id')->all())->toContain($mislinkedItem);
    expect($encoded)->toContain('Owner A wants this published');
    expect(app(PoolResolver::class)->hasSelection($siteA, 'reviews'))->toBeTrue();

    // (b) NO VOTE — B's `reviews => false` did not suppress it, while the very
    // same toggle on A's own connection did suppress the other item.
    expect(collect($resolved['selection'])->pluck('id')->all())->not->toContain($ownItem);
    expect($encoded)->not->toContain('Owner A switched this one off');
});

it('never publishes another owner connection account name or fallback url as this item source', function () {
    // $sourceRows is the worst hop: pc.id keys $payloadByConnection (->
    // ConnectionDisplayName) and $sourcePlatforms (-> the connection's OWN url as
    // this item's fallback link). The item is kept alive by a manual source, so
    // it stays on the wire and only the foreign source row is rejected —
    // otherwise the negated assertions below would pass on an empty payload.
    [$proA, $siteAId] = poolTenant();
    [$proB] = poolTenant();

    $connB = poolConnection($proB->id, 'youtube.channel');
    DB::table('site.platform_connections')->where('id', $connB)->update([
        'payload' => json_encode(['url' => 'https://owner-b-channel.example.test', 'display_name' => 'Owner B Channel']),
    ]);

    $manual = poolSource($proA->id, null);
    $itemA = poolItem($proA->id, $manual, 'video', 'A video of A', '2026-08-01T00:00:00Z');
    extraSourceItem($itemA, poolSource($proA->id, $connB), 'video');
    poolPin($siteAId, 'watch', $itemA);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteAId), 'watch');
    // UNESCAPED_SLASHES, deliberately: the default encoder writes `https:\/\/`,
    // so a URL assertion against it never matches and is vacuous.
    $encoded = json_encode($resolved, JSON_UNESCAPED_SLASHES);

    expect($encoded)->not->toContain('owner-b-channel.example.test');
    expect($encoded)->not->toContain('Owner B Channel');
    // The item itself survives — the manual source keeps it live.
    $row = collect($resolved['selection'])->firstWhere('id', $itemA);
    expect($row)->not->toBeNull();
    expect(collect($row['sources'])->pluck('kind')->all())->toBe(['manual']);
});

it('still publishes this owner own connection account name and fallback url', function () {
    // The counterweight: without it the test above would pass on a "fix" that
    // simply deleted the fallback-link feature.
    [$pro, $siteId] = poolTenant();

    $conn = poolConnection($pro->id, 'youtube.channel');
    DB::table('site.platform_connections')->where('id', $conn)->update([
        'payload' => json_encode(['url' => 'https://my-own-channel.example.test', 'display_name' => 'My Own Channel']),
    ]);

    $manual = poolSource($pro->id, null);
    $itemId = poolItem($pro->id, $manual, 'video', 'A video of mine', '2026-08-01T00:00:00Z');
    extraSourceItem($itemId, poolSource($pro->id, $conn), 'video');
    poolPin($siteId, 'watch', $itemId);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'watch');
    $encoded = json_encode($resolved, JSON_UNESCAPED_SLASHES);

    expect($encoded)->toContain('my-own-channel.example.test');
    expect($encoded)->toContain('My Own Channel');
    expect(collect(collect($resolved['selection'])->firstWhere('id', $itemId)['sources'])->pluck('kind')->all())
        ->toContain('connection');
});

it('never badges an item with another owner ingest sync cadence', function () {
    // A's OWN connection, correctly linked. The mislink is on the INGEST row:
    // B's ingest.sources row names A's connection_id. Nothing the $sourceRows
    // join does touches this — ingest.sources.connection_id is a separate FK
    // with a separate writer, which is why this pair is not defence-in-depth.
    [$proA, $siteAId] = poolTenant();
    [$proB] = poolTenant();

    $connA = poolConnection($proA->id, 'youtube.channel');
    $sourceA = poolSource($proA->id, $connA);
    $itemA = poolItem($proA->id, $sourceA, 'video', 'A video', '2026-08-01T00:00:00Z');
    poolPin($siteAId, 'watch', $itemA);

    DB::table('ingest.sources')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $proB->id, 'connection_id' => $connA,
        'source_key' => 'youtube', 'surface_key' => 'youtube.channel', 'identifier' => 'x',
        'last_run_at' => '2031-01-01 00:00:00', 'auto_sync' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteAId), 'watch');
    $connSource = collect(collect($resolved['selection'])->firstWhere('id', $itemA)['sources'])
        ->firstWhere('kind', 'connection');

    expect($connSource)->not->toBeNull();
    expect($connSource['lastSyncedAt'])->toBeNull();
    expect($connSource['autoSync'])->toBeFalse();
});

it('still badges an item with this owner own ingest sync cadence', function () {
    [$pro, $siteId] = poolTenant();

    $conn = poolConnection($pro->id, 'youtube.channel');
    $source = poolSource($pro->id, $conn);
    $itemId = poolItem($pro->id, $source, 'video', 'A video', '2026-08-01T00:00:00Z');
    poolPin($siteId, 'watch', $itemId);

    DB::table('ingest.sources')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $pro->id, 'connection_id' => $conn,
        'source_key' => 'youtube', 'surface_key' => 'youtube.channel', 'identifier' => 'x',
        'last_run_at' => '2031-01-01 00:00:00', 'auto_sync' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'watch');
    $connSource = collect(collect($resolved['selection'])->firstWhere('id', $itemId)['sources'])
        ->firstWhere('kind', 'connection');

    expect($connSource['lastSyncedAt'])->toBe('2031-01-01T00:00:00+00:00');
    expect($connSource['autoSync'])->toBeTrue();
});

it('does not let another owner staff selection open the person scope gate', function () {
    // The employee-scope gate is what publishes a venue review carrying no name
    // evidence at all, so a foreign row must never be able to open it. The hop
    // it travels moved on 2026-09-01: the selection is now recorded on
    // content.source_items at ingest time rather than read live off
    // ingest.sources, which retired the cs.connection_id -> ing.connection_id
    // hop entirely. What remains is one join and one pin — content.source_items
    // carries no user_id of its own, so cs.user_id is the ONLY thing standing
    // between a mislinked source_id and a co-worker's venue review on an
    // individual's page.
    //
    // Not vacuous: the item is a live candidate through A's OWN source row,
    // which records no selection. The employee stamp sits only on the row that
    // hangs off B's content source, and that is the row the gate must refuse.
    [$proA, $siteAId] = poolTenant();      // partna, so person-scoping is ON
    [$proB] = poolTenant();

    $sourceA = poolSource($proA->id, poolConnection($proA->id, 'fresha.book'));
    $itemA = crossTenantReview($proA->id, $sourceA, 'Great work by someone else entirely');

    $sourceB = poolSource($proB->id, poolConnection($proB->id, 'fresha.book'));
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceB,
        'coord' => 'review:'.Str::random(8), 'item_id' => $itemA, 'kind' => 'review',
        'ingest_selection_ref' => 'staff-12345',
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteAId), 'reviews');

    expect(collect($resolved['selection'])->firstWhere('id', $itemA))->toBeNull();
    expect(json_encode($resolved, JSON_UNESCAPED_SLASHES))->not->toContain('Great work by someone else entirely');
});

it('still opens the person scope gate on this owner own staff selection', function () {
    // The counterweight, and it also proves the test above fails for the RIGHT
    // reason: person-scoping IS on for a partna account (otherwise
    // reviewsOutsidePersonScope early-returns and both halves are vacuous), so
    // the ONLY thing publishing this venue review is the employee-scoped gate.
    //
    // The identical stamp as above, on a row that hangs off THIS owner's
    // content source. One join, one pin, two answers.
    [$pro, $siteId] = poolTenant();

    $conn = poolConnection($pro->id, 'fresha.book');
    $sourceId = poolSource($pro->id, $conn);
    $itemId = crossTenantReview($pro->id, $sourceId, 'Great work by a nameless stranger');

    DB::table('content.source_items')
        ->where('source_id', $sourceId)
        ->update(['ingest_selection_ref' => 'staff-12345']);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'reviews');

    expect(collect($resolved['selection'])->pluck('id')->all())->toContain($itemId);
});

it('excludes a venue review with no gate at all from a partna page', function () {
    // The control for the pair above: with no selection_ref anywhere, the same
    // fixture is excluded. Without this, "still opens the gate" could be passing
    // because person-scoping never ran.
    [$pro, $siteId] = poolTenant();

    $conn = poolConnection($pro->id, 'fresha.book');
    $itemId = crossTenantReview($pro->id, poolSource($pro->id, $conn), 'Great work by a nameless stranger');

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'reviews');

    expect(collect($resolved['selection'])->firstWhere('id', $itemId))->toBeNull();
});

it('never publishes a stats badge through another owner connection', function () {
    // statsFor()'s stats_conn hop is liveness-only, so what an unpinned hop
    // bought was a FOREIGN live verdict keeping a disconnected listing's star
    // rating publishing. Fail-closed after the pin: NULL reads as "not live".
    [$proA, $siteAId] = poolBusinessTenant();
    [$proB] = poolBusinessTenant();

    $connB = poolConnection($proB->id, 'google_business.listing', ['reviews' => true]);
    $mislinked = poolSource($proA->id, $connB);
    crossTenantReview($proA->id, $mislinked, 'A genuine review of A');

    DB::table('content.source_stats')->insert([
        'source_id' => $mislinked, 'rating_avg' => 4.9, 'rating_count' => 812,
        'summary_text' => 'Kept alive through a foreign connection', 'updated_at' => now(),
    ]);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteAId), 'reviews');

    expect($resolved['stats'])->toBeNull();
    expect(json_encode($resolved, JSON_UNESCAPED_SLASHES))->not->toContain('Kept alive through a foreign connection');
});

it('never publishes a source link kept live by another owner connection', function () {
    // $sourceLinksQuery selects platform_connections.platform AND the f_link
    // url, gated by constrainToLiveSource. With the hop unpinned, B's live
    // connection was what made A's link "live". The manual source keeps the item
    // itself on the wire, so this is not an empty-payload pass.
    [$proA, $siteAId] = poolTenant();
    [$proB] = poolTenant();

    $connB = poolConnection($proB->id, 'youtube.channel');
    $manual = poolSource($proA->id, null);
    $mislinked = poolSource($proA->id, $connB);

    $itemA = poolItem($proA->id, $manual, 'video', 'A video of A', '2026-08-01T00:00:00Z');
    poolPin($siteAId, 'watch', $itemA);
    DB::table('content.f_link')->insert([
        'item_id' => $itemA, 'source_id' => $mislinked,
        'url' => 'https://kept-live-by-owner-b.example.test/watch', 'updated_at' => now(),
    ]);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteAId), 'watch');
    $encoded = json_encode($resolved, JSON_UNESCAPED_SLASHES);

    expect($encoded)->not->toContain('kept-live-by-owner-b.example.test');
    expect(collect($resolved['selection'])->firstWhere('id', $itemA))->not->toBeNull();
});

it('still publishes a source link kept live by this owner own connection', function () {
    [$pro, $siteId] = poolTenant();

    $conn = poolConnection($pro->id, 'youtube.channel');
    $manual = poolSource($pro->id, null);
    $own = poolSource($pro->id, $conn);

    $itemId = poolItem($pro->id, $manual, 'video', 'A video of mine', '2026-08-01T00:00:00Z');
    poolPin($siteId, 'watch', $itemId);
    DB::table('content.f_link')->insert([
        'item_id' => $itemId, 'source_id' => $own,
        'url' => 'https://kept-live-by-its-owner.example.test/watch', 'updated_at' => now(),
    ]);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'watch');

    expect(json_encode($resolved, JSON_UNESCAPED_SLASHES))->toContain('kept-live-by-its-owner.example.test');
});

it('drops an item whose only source rides another owner connection out of the pool (FU-2 residual 2)', function () {
    // The liveness half of the same hop, at the resolve() level. LiveSourceScope
    // decides "does this item still have a live source"; a connection belonging
    // to somebody else cannot answer that for this owner, so it resolves to NULL
    // and the item fails closed. Before #FU-2 the two halves of resolve()
    // DISAGREED about this exact row — the library read excluded it while
    // SectionCandidates' rule half still published it.
    [$proA, $siteAId] = poolTenant();
    [$proB] = poolTenant();

    $connB = poolConnection($proB->id, 'youtube.channel');
    $mislinkedSource = poolSource($proA->id, $connB);
    $item = poolItem($proA->id, $mislinkedSource, 'video', 'A video of mine', '2026-08-01T00:00:00Z');

    $siteA = Site::query()->findOrFail($siteAId);
    $resolved = app(PoolResolver::class)->resolve($siteA, 'watch');

    expect(collect($resolved['library'])->pluck('id')->all())->not->toContain($item);
    expect(collect($resolved['selection'])->pluck('id')->all())->not->toContain($item);

    // The control: the same item, given a source of the owner's own, comes
    // straight back. Without this the assertions above could hold because the
    // fixture never produced a pool item at all.
    extraSourceItem($item, poolSource($proA->id, null));
    $resolved = app(PoolResolver::class)->resolve($siteA, 'watch');

    expect(collect($resolved['library'])->pluck('id')->all())->toContain($item);
});
