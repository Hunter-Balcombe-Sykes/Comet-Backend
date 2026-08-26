<?php

use App\Http\Controllers\Api\Content\ItemController;
use App\Http\Controllers\Api\Content\ItemLinkController;
use App\Http\Controllers\Api\Content\PoolController;
use App\Http\Controllers\Api\Content\PoolItemCreateController;
use App\Models\Core\Site\Site;
use App\Services\Content\ManualServiceWriter;
use App\Services\Http\SafeUrlFetcher;
use App\Services\PublicSite\IndividualProfilePayloadBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

// The pool lane end to end (platforms-as-sources, 2026-08-05): the section
// IS the selection store, the rule IS the auto half, and the owner's
// semantics are pinned here — a removed rolling-latest stays removed until
// something NEWER lands, a pinned item survives newer arrivals, and the
// Latest tag follows the newest release across the whole selection.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    // Pool mutations dispatch the sitepage edge purge — a no-op here.
    Queue::fake();
    // T3 (2026-08-20): the hand-add lane refuses URLs the grammar doesn't
    // claim, so these tests paste CLAIMED shapes (vimeo.com/123456789, a
    // real-length Spotify id) — and the reader's fetch is stubbed dead so
    // what's under test stays the card path's projection machinery.
    app()->instance(SafeUrlFetcher::class, Mockery::mock(SafeUrlFetcher::class, function ($m) {
        $m->shouldReceive('tryFetch')->andReturnNull()->byDefault();
        $m->shouldIgnoreMissing();
    }));
});

// poolTenant/poolConnection/poolSource/poolItem/poolGet/poolHeadlines now live
// in tests/Helpers/PoolTestHelpers.php — four other suites call them, and a
// helper declared here is undefined in any --parallel worker that wasn't
// assigned this file.

// ── The auto half ───────────────────────────────────────────────────────────

it('auto-selects exactly the newest item per auto source, rolling', function () {
    [$pro] = poolTenant();
    $source = poolSource($pro->id, poolConnection($pro->id));

    $old = poolItem($pro->id, $source, 'video', 'Old upload', now()->subDays(9)->toDateTimeString());
    $new = poolItem($pro->id, $source, 'video', 'New upload', now()->subDays(2)->toDateTimeString());

    $data = poolGet($pro);

    expect(poolHeadlines($data))->toBe(['New upload']);
    expect($data['selection'][0]['origin'])->toBe('auto');
    expect($data['latestItemId'])->toBe($new);
    // The library still offers both.
    expect(poolHeadlines($data, 'library'))->toContain('Old upload', 'New upload');

    // A newer arrival ROLLS the auto pick with no write anywhere.
    poolItem($pro->id, $source, 'video', 'Newest upload', now()->toDateTimeString());
    expect(poolHeadlines(poolGet($pro)))->toBe(['Newest upload']);
    expect($old)->toBeString();
});

it('still auto-selects the newest item when another tenant holds the same video at the same timestamp (F1 tie-break precedence)', function () {
    // Overnight 2026-08-18 F1: the tie-break in latest_per_auto_source was
    // `A > B OR (A = B AND id > id)` without outer parentheses, so the OR
    // escaped the same-source / not-removed / kind filters. Any row in the
    // table with an equal timestamp and a greater id — another user's copy
    // of the same YouTube video — made every candidate lose and the pool
    // auto-selected nothing. Two tenants, same publish instant, ids ordered
    // so the OTHER tenant's copy sorts higher.
    [$pro] = poolTenant();
    [$other] = poolTenant();
    $when = now()->subDay()->toDateTimeString();

    $mine = poolItem($pro->id, poolSource($pro->id, poolConnection($pro->id)), 'video', 'Shared video', $when);
    $theirs = poolItem($other->id, poolSource($other->id, poolConnection($other->id)), 'video', 'Shared video', $when);
    // Force the other tenant's item id to sort AFTER mine regardless of uuid luck.
    DB::table('content.items')->where('id', $theirs)->update(['id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff']);
    DB::table('content.source_items')->where('item_id', $theirs)->update(['item_id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff']);
    DB::table('content.f_published')->where('item_id', $theirs)->update(['item_id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff']);

    $data = poolGet($pro);
    expect(poolHeadlines($data))->toBe(['Shared video']);
    expect($data['latestItemId'])->toBe($mine);
});

it('orders recency pools by published date, not by the projector touch time (F13)', function () {
    config(['partna.pools.auto_latest_n' => 10]);
    [$pro] = poolTenant();
    $source = poolSource($pro->id, poolConnection($pro->id, 'instagram.profile'));
    // Inserted out of date order; all rows share last_seen_at = now().
    $mid = poolItem($pro->id, $source, 'media', 'Mid', '2026-08-02T00:00:00Z');
    $new = poolItem($pro->id, $source, 'media', 'New', '2026-08-03T00:00:00Z');
    $old = poolItem($pro->id, $source, 'media', 'Old', '2026-08-01T00:00:00Z');

    $data = poolGet($pro, 'media');
    expect(array_column($data['selection'], 'id'))->toBe([$new, $mid, $old]);
});

it('hides a removed connection\'s items from the auto half, the pins and the library — disconnect = hide, never delete (W2)', function () {
    [$pro] = poolTenant();
    $connectionId = poolConnection($pro->id);
    $source = poolSource($pro->id, $connectionId);
    $vid = poolItem($pro->id, $source, 'video', 'Kept video', now()->subDay()->toDateTimeString());
    // Pin it too, so both halves are exercised.
    $pin = Request::create("/api/content/pools/watch/selection/{$vid}", 'POST');
    $pin->attributes->set('professional', $pro);
    app(PoolController::class)->select($pin, 'watch', $vid);

    expect(poolHeadlines(poolGet($pro)))->toBe(['Kept video']);

    DB::table('site.platform_connections')->where('id', $connectionId)->update(['deleted_at' => now()]);

    $data = poolGet($pro);
    expect($data['selection'])->toBe([])
        ->and($data['library'])->toBe([])
        // History is kept: the item row and its pin survive for a reconnect.
        ->and(DB::table('content.items')->where('id', $vid)->whereNull('removed_at')->exists())->toBeTrue()
        ->and(DB::table('site.section_items')->where('item_id', $vid)->exists())->toBeTrue();

    DB::table('site.platform_connections')->where('id', $connectionId)->update(['deleted_at' => null]);
    expect(poolHeadlines(poolGet($pro)))->toBe(['Kept video']);
});

it('auto-selects nothing from a source whose auto_sync_latest is off', function () {
    [$pro] = poolTenant();
    $offSource = poolSource($pro->id, poolConnection($pro->id, 'youtube.channel', ['auto_sync_latest' => false]));
    $onSource = poolSource($pro->id, poolConnection($pro->id, 'vimeo.channel'));

    poolItem($pro->id, $offSource, 'video', 'Muted platform video', now()->toDateTimeString());
    $shown = poolItem($pro->id, $onSource, 'video', 'Loud platform video', now()->subDay()->toDateTimeString());

    $data = poolGet($pro);

    expect(poolHeadlines($data))->toBe(['Loud platform video']);
    expect($data['latestItemId'])->toBe($shown);
});

it('keeps a removed rolling-latest removed until something newer lands', function () {
    [$pro] = poolTenant();
    $source = poolSource($pro->id, poolConnection($pro->id));

    poolItem($pro->id, $source, 'video', 'Old upload', now()->subDays(9)->toDateTimeString());
    $latest = poolItem($pro->id, $source, 'video', 'Latest upload', now()->subDay()->toDateTimeString());

    $request = Request::create("/api/content/pools/watch/selection/{$latest}", 'DELETE');
    $request->attributes->set('professional', $pro);
    $data = app(PoolController::class)->deselect($request, 'watch', $latest)->getData(true);

    // The OLD item must not bubble up — nothing auto-joins now (owner).
    expect($data['selection'])->toBe([]);

    // Until something newer arrives.
    poolItem($pro->id, $source, 'video', 'Fresh upload', now()->toDateTimeString());
    expect(poolHeadlines(poolGet($pro)))->toBe(['Fresh upload']);
});

// ── Hand-picks ──────────────────────────────────────────────────────────────

it('pins survive newer arrivals and order before the auto pick', function () {
    [$pro, $siteId] = poolTenant();
    poolOrderMode($siteId, 'watch', 'manual');
    $pro = $pro->fresh(['site']);
    $source = poolSource($pro->id, poolConnection($pro->id));

    $keeper = poolItem($pro->id, $source, 'video', 'Keeper', now()->subDays(20)->toDateTimeString());
    poolItem($pro->id, $source, 'video', 'Current latest', now()->subDay()->toDateTimeString());

    $request = Request::create("/api/content/pools/watch/selection/{$keeper}", 'POST');
    $request->attributes->set('professional', $pro);
    $data = app(PoolController::class)->select($request, 'watch', $keeper)->getData(true);

    expect(poolHeadlines($data))->toBe(['Keeper', 'Current latest']);
    expect($data['selection'][0]['origin'])->toBe('manual');
    expect($data['selection'][1]['origin'])->toBe('auto');

    // The Latest tag reads release recency, not pin order.
    expect($data['latestItemId'])->not->toBe($keeper);
});

it('reorder pins every listed item in the given order', function () {
    [$pro, $siteId] = poolTenant();
    poolOrderMode($siteId, 'watch', 'manual');
    $pro = $pro->fresh(['site']);
    $source = poolSource($pro->id, poolConnection($pro->id));

    $a = poolItem($pro->id, $source, 'video', 'A', now()->subDays(3)->toDateTimeString());
    $b = poolItem($pro->id, $source, 'video', 'B', now()->subDays(2)->toDateTimeString());
    $c = poolItem($pro->id, $source, 'video', 'C', now()->subDay()->toDateTimeString());

    $request = Request::create('/api/content/pools/watch/order', 'PUT', ['itemIds' => [$b, $c, $a]]);
    $request->attributes->set('professional', $pro);
    $data = app(PoolController::class)->reorder($request, 'watch')->getData(true);

    expect(poolHeadlines($data))->toBe(['B', 'C', 'A']);
    expect(array_column($data['selection'], 'origin'))->toBe(['manual', 'manual', 'manual']);
});

it('rejects a reorder naming an item outside the pool', function () {
    [$pro] = poolTenant();
    $source = poolSource($pro->id, poolConnection($pro->id));
    $video = poolItem($pro->id, $source, 'video', 'Mine', now()->toDateTimeString());
    $track = poolItem($pro->id, $source, 'track', 'Wrong pool', now()->toDateTimeString());

    $request = Request::create('/api/content/pools/watch/order', 'PUT', ['itemIds' => [$video, $track]]);
    $request->attributes->set('professional', $pro);

    expect(app(PoolController::class)->reorder($request, 'watch')->getStatusCode())->toBe(422);
});

// SEM-7: a pin the client's itemIds list omits is NOT deleted by reorder()'s
// delete/insert pair (that only touches listed ids), so it used to keep its
// OLD sort_key and interleave with the fresh 1..N sequence — the public
// sitepage then rendered an order nobody chose. Asserting on the RESOLVED
// order the endpoint returns (poolHeadlines), not the raw sort_key values,
// because that resolved order is what a visitor actually sees and is exactly
// what a repeat of the original bug would corrupt.
it('reorder renumbers a pin the client omitted to sit after the listed items, not interleaved', function () {
    [$pro, $siteId] = poolTenant();
    poolOrderMode($siteId, 'watch', 'manual');
    $pro = $pro->fresh(['site']);
    $source = poolSource($pro->id, poolConnection($pro->id));

    $a = poolItem($pro->id, $source, 'video', 'A', now()->subDays(3)->toDateTimeString());
    $b = poolItem($pro->id, $source, 'video', 'B', now()->subDays(2)->toDateTimeString());
    $c = poolItem($pro->id, $source, 'video', 'C', now()->subDay()->toDateTimeString());

    // Seed all three as pins in A, B, C order.
    $seed = Request::create('/api/content/pools/watch/order', 'PUT', ['itemIds' => [$a, $b, $c]]);
    $seed->attributes->set('professional', $pro);
    app(PoolController::class)->reorder($seed, 'watch');

    // The drag commit only lists A and C — B is the stranded survivor.
    $request = Request::create('/api/content/pools/watch/order', 'PUT', ['itemIds' => [$a, $c]]);
    $request->attributes->set('professional', $pro);
    $data = app(PoolController::class)->reorder($request, 'watch')->getData(true);

    $headlines = poolHeadlines($data);

    // (i) B is not interleaved between A and C — it lands after both, at the
    // last position.
    expect(array_search('B', $headlines, true))->toBe(2);
    // (ii) the A→C relative order is exactly what was sent.
    expect(array_slice($headlines, 0, 2))->toBe(['A', 'C']);
});

it('reorder keeps omitted survivors in their prior relative order, occupying consecutive positions after the listed ones', function () {
    [$pro, $siteId] = poolTenant();
    poolOrderMode($siteId, 'watch', 'manual');
    $pro = $pro->fresh(['site']);
    $source = poolSource($pro->id, poolConnection($pro->id));

    $a = poolItem($pro->id, $source, 'video', 'A', now()->subDays(4)->toDateTimeString());
    $b = poolItem($pro->id, $source, 'video', 'B', now()->subDays(3)->toDateTimeString());
    $c = poolItem($pro->id, $source, 'video', 'C', now()->subDays(2)->toDateTimeString());
    $d = poolItem($pro->id, $source, 'video', 'D', now()->subDay()->toDateTimeString());

    // Seed all four as pins in A, B, C, D order.
    $seed = Request::create('/api/content/pools/watch/order', 'PUT', ['itemIds' => [$a, $b, $c, $d]]);
    $seed->attributes->set('professional', $pro);
    app(PoolController::class)->reorder($seed, 'watch');

    // The drag commit lists only A — B, C, D all survive as omitted pins.
    $request = Request::create('/api/content/pools/watch/order', 'PUT', ['itemIds' => [$a]]);
    $request->attributes->set('professional', $pro);
    $data = app(PoolController::class)->reorder($request, 'watch')->getData(true);

    $headlines = poolHeadlines($data);

    // (iii) no row was dropped or duplicated by the renumbering — checked
    // BEFORE the order assertion below so a count-only regression fails here
    // rather than being masked by an out-of-bounds slice comparison.
    expect($headlines)->toHaveCount(4);
    // Survivors keep their own relative order (B, C, D) and sit in
    // consecutive positions right after the one listed item — no gap, no
    // reordering among themselves.
    expect(array_slice($headlines, 1))->toBe(['B', 'C', 'D']);
});

// `sort_key` is nullable with no default (20260727150000). No app write path
// today produces a NULL on a pinned row — ManualPoolWriter::pin() type-hints
// a non-nullable float, and every other writer (SectionItemController,
// PoolItemCreateController, ManualEventWriter, LinkPoolWriter) falls back to
// nextSortKey() via `??`/`??=` — but the column itself allows it, and SQLite
// (tests) sorts NULL FIRST on ASC while Postgres sorts NULL LAST, so an
// ordering bug here would only ever surface in production. C's sort_key is
// set to NULL directly via DB write (poolPin()-style, bypassing the app
// layer) to exercise the query's NULL-handling regardless of reachability.
it('reorder still renumbers a survivor whose sort_key is NULL, deterministically, instead of skipping or stranding it', function () {
    [$pro, $siteId] = poolTenant();
    poolOrderMode($siteId, 'watch', 'manual');
    $pro = $pro->fresh(['site']);
    $source = poolSource($pro->id, poolConnection($pro->id));

    $a = poolItem($pro->id, $source, 'video', 'A', now()->subDays(4)->toDateTimeString());
    $b = poolItem($pro->id, $source, 'video', 'B', now()->subDays(3)->toDateTimeString());
    $c = poolItem($pro->id, $source, 'video', 'C', now()->subDays(2)->toDateTimeString());
    $d = poolItem($pro->id, $source, 'video', 'D', now()->subDay()->toDateTimeString());

    // Seed all four as pins in A, B, C, D order (sort_key 1..4).
    $seed = Request::create('/api/content/pools/watch/order', 'PUT', ['itemIds' => [$a, $b, $c, $d]]);
    $seed->attributes->set('professional', $pro);
    app(PoolController::class)->reorder($seed, 'watch');

    // Blank C's sort_key — a state no app write path can reach, but the
    // column allows it.
    DB::connection('pgsql')->table('site.section_items')->where('item_id', $c)->update(['sort_key' => null]);

    // The drag commit lists only A — B, C, D all survive; C's sort_key is
    // NULL and B/D's are the ordinary floats 2 and 4.
    $request = Request::create('/api/content/pools/watch/order', 'PUT', ['itemIds' => [$a]]);
    $request->attributes->set('professional', $pro);
    $data = app(PoolController::class)->reorder($request, 'watch')->getData(true);

    $headlines = poolHeadlines($data);

    // C is neither dropped nor stranded — all four items are still present.
    expect($headlines)->toHaveCount(4);
    // C (the NULL) sorts after every non-NULL survivor on BOTH dialects, so
    // it lands last among the survivors — B and D (both non-NULL) keep their
    // relative order ahead of it. A NULL-unsafe `orderBy('sort_key')` would
    // instead put C FIRST on SQLite (this suite's driver), producing
    // [A, C, B, D].
    expect(array_slice($headlines, 1))->toBe(['B', 'D', 'C']);
});

// ── The library delete ──────────────────────────────────────────────────────

it('removing a pinned item keeps it off the site — an exclusion, not a bare un-pin', function () {
    [$pro, $siteId] = poolTenant();
    poolOrderMode($siteId, 'watch', 'manual');
    $pro = $pro->fresh(['site']);
    $source = poolSource($pro->id, poolConnection($pro->id));

    $a = poolItem($pro->id, $source, 'video', 'A', now()->subDays(2)->toDateTimeString());
    $b = poolItem($pro->id, $source, 'video', 'B', now()->subDay()->toDateTimeString());

    // A drag commit pins every listed row — the state every remove after a
    // reorder starts from (owner, 2026-08-23: "why do I keep seeing this").
    $order = Request::create('/api/content/pools/watch/order', 'PUT', ['itemIds' => [$b, $a]]);
    $order->attributes->set('professional', $pro);
    app(PoolController::class)->reorder($order, 'watch');

    $remove = Request::create("/api/content/pools/watch/selection/{$b}", 'DELETE');
    $remove->attributes->set('professional', $pro);
    $data = app(PoolController::class)->deselect($remove, 'watch', $b)->getData(true);

    // Gone from the selection in the SAME response the dashboard reads —
    // not re-emitted by the kind_is rule the instant its pin disappeared.
    expect(poolHeadlines($data))->toBe(['A']);
    expect(DB::connection('pgsql')->table('site.section_items')
        ->where('item_id', $b)->value('state'))->toBe('excluded');

    // And it still comes back when the owner re-adds it.
    $readd = Request::create("/api/content/pools/watch/selection/{$b}", 'POST');
    $readd->attributes->set('professional', $pro);
    $back = app(PoolController::class)->select($readd, 'watch', $b)->getData(true);
    expect(poolHeadlines($back))->toContain('B');
});

it('removes an item from selection and library via removed_at', function () {
    [$pro] = poolTenant();
    $source = poolSource($pro->id, poolConnection($pro->id));
    $item = poolItem($pro->id, $source, 'video', 'Doomed', now()->toDateTimeString());

    $request = Request::create("/api/content/items/{$item}", 'DELETE');
    $request->attributes->set('professional', $pro);
    // The writer is method-injected since slice 4 — destroy() routes through
    // markRemoved() so this path and the service ones share one removal seam
    // (and therefore one slug-freeing step). Resolved explicitly here because
    // this test calls the controller directly rather than over HTTP.
    $remover = app(ManualServiceWriter::class);
    expect(app(ItemController::class)->destroy($request, $item, $remover)->getStatusCode())->toBe(200);

    $data = poolGet($pro);
    expect($data['selection'])->toBe([]);
    expect($data['library'])->toBe([]);
    expect(DB::table('content.items')->where('id', $item)->whereNotNull('removed_at')->exists())->toBeTrue();
});

// ── Ownership ───────────────────────────────────────────────────────────────

it('404s selection writes naming a foreign item', function () {
    [$pro] = poolTenant();
    [$other] = poolTenant();
    $foreign = poolItem($other->id, poolSource($other->id, poolConnection($other->id)), 'video', 'Not yours', now()->toDateTimeString());

    $request = Request::create("/api/content/pools/watch/selection/{$foreign}", 'POST');
    $request->attributes->set('professional', $pro);

    expect(fn () => app(PoolController::class)->select($request, 'watch', $foreign))
        ->toThrow(HttpException::class);
});

// ── Per-item platform links ─────────────────────────────────────────────────

it('saves, serves and deletes a hand-saved platform link', function () {
    [$pro] = poolTenant();
    $source = poolSource($pro->id, poolConnection($pro->id, 'apple_music.artist'));
    $release = poolItem($pro->id, $source, 'release', 'Night Bus', now()->toDateTimeString());

    $put = Request::create("/api/content/items/{$release}/links/spotify", 'PUT', [
        'url' => 'https://open.spotify.com/album/abc123',
    ]);
    $put->attributes->set('professional', $pro);
    expect(app(ItemLinkController::class)->upsert($put, $release, 'spotify')->getStatusCode())->toBe(200);

    $data = poolGet($pro, 'listen');
    $links = $data['selection'][0]['links'];
    $spotify = collect($links)->firstWhere('platform', 'spotify');
    expect($spotify['url'])->toBe('https://open.spotify.com/album/abc123');
    expect($spotify['source'])->toBe('manual');

    $del = Request::create("/api/content/items/{$release}/links/spotify", 'DELETE');
    $del->attributes->set('professional', $pro);
    expect(app(ItemLinkController::class)->destroy($del, $release, 'spotify')->getStatusCode())->toBe(200);
});

it('refuses off-roster platforms, wrong domains, and synced platforms', function () {
    [$pro] = poolTenant();
    $connection = poolConnection($pro->id);
    $source = poolSource($pro->id, $connection);
    $video = poolItem($pro->id, $source, 'video', 'Clip', now()->toDateTimeString());
    DB::table('content.f_link')->insert([
        'item_id' => $video, 'source_id' => $source,
        'url' => 'https://youtube.com/watch?v=x', 'updated_at' => now(),
    ]);

    $make = function (string $platform, string $url) use ($video, $pro) {
        $request = Request::create("/api/content/items/{$video}/links/{$platform}", 'PUT', ['url' => $url]);
        $request->attributes->set('professional', $pro);

        return app(ItemLinkController::class)->upsert($request, $video, $platform)->getStatusCode();
    };

    // spotify is Listen's roster, not Watch's.
    expect($make('spotify', 'https://open.spotify.com/track/3n3Ppam7vgaVa1iaRUc9Lp'))->toBe(422);
    // vimeo is on Watch's roster but the URL is not a vimeo address.
    expect($make('vimeo', 'https://example.com/video/1'))->toBe(422);
    // youtube already SYNCS this item — its link follows the sync.
    expect($make('youtube', 'https://youtube.com/watch?v=y'))->toBe(422);
    // vimeo with a real vimeo URL is the accepted alternate.
    expect($make('vimeo', 'https://vimeo.com/12345'))->toBe(200);
});

// ── Payload fidelity ────────────────────────────────────────────────────────

it('serves render-ready payloads: override headline, synced link, platform', function () {
    [$pro] = poolTenant();
    $connection = poolConnection($pro->id);
    $source = poolSource($pro->id, $connection);
    $video = poolItem($pro->id, $source, 'video', 'Synced title', now()->toDateTimeString());

    DB::table('content.f_link')->insert([
        'item_id' => $video, 'source_id' => $source,
        'url' => 'https://youtube.com/watch?v=abc', 'updated_at' => now(),
    ]);
    DB::table('content.manual_overrides')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $video,
        'facet' => 'f_text', 'column_name' => 'headline',
        'value' => json_encode('My better title'),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $item = poolGet($pro)['selection'][0];

    expect($item['headline'])->toBe('My better title');
    expect($item['headlineEdited'])->toBeTrue();
    expect($item['url'])->toBe('https://youtube.com/watch?v=abc');
    expect($item['platform'])->toBe('youtube');
});

it('spells a brand-keyed connection platform as the slug on the wire — uber_eats → uber-eats in platform, links[] and sources[] (F28)', function () {
    // Brand connects store the catalog brand key (`uber_eats`) in
    // platform_connections.platform; the roster, ItemLinkRules and the
    // dashboard glyph map all use `uber-eats`. Both spellings leaked onto one
    // menus wire (session 3), so ingest-lane dishes drew no glyph.
    [$pro] = poolTenant();
    $connection = poolConnection($pro->id, 'uber_eats.order');
    // `platform` is generated from surface_key ('uber_eats.order' → 'uber_eats').
    DB::table('site.platform_connections')->where('id', $connection)->update(['payload' => json_encode(['url' => 'https://www.ubereats.com/au/store/souva-king/RV0ChXJAXiaEjATmAdjQeg', 'name' => 'def.uber.com'])]);
    $source = poolSource($pro->id, $connection);
    $dish = poolItem($pro->id, $source, 'menu_item', 'Halloumi Wrap', now()->toDateTimeString());
    DB::table('content.f_link')->insert(['item_id' => $dish, 'source_id' => $source, 'url' => 'https://www.ubereats.com/au/store/souva-king/RV0ChXJAXiaEjATmAdjQeg', 'updated_at' => now()]);

    $item = collect(poolGet($pro, 'menus')['library'])->firstWhere('id', $dish);
    expect($item['platform'])->toBe('uber-eats')
        ->and(array_column($item['links'], 'platform'))->toBe(['uber-eats'])
        ->and($item['sources'][0]['platform'])->toBe('uber-eats')
        // …and a bare host under payload.name is not a display name.
        ->and($item['sources'][0]['accountName'])->toBe('Souva King');
});

// ── The public wire ─────────────────────────────────────────────────────────

it('serves the pool selection on the public payload with the Latest tag', function () {
    setupMediaTables();
    setupBlocksTable();
    setupServicesTable();
    setupDesignKitsTable();

    [$pro] = poolTenant();
    $source = poolSource($pro->id, poolConnection($pro->id));
    poolItem($pro->id, $source, 'video', 'Older upload', now()->subDays(5)->toDateTimeString());
    $latest = poolItem($pro->id, $source, 'video', 'Latest upload', now()->toDateTimeString());

    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();
    $payload = app(IndividualProfilePayloadBuilder::class)
        ->build($pro->fresh(), $site);

    // The resource casts pools to an object so an empty map serializes {}.
    $watch = $payload['profile']['pools']->watch ?? null;
    expect($watch)->not->toBeNull();
    expect(array_column($watch['items'], 'headline'))->toBe(['Latest upload']);
    expect($watch['latestItemId'])->toBe($latest);
    // Dashboard-only flags stay off the public wire.
    expect($watch['items'][0])->not->toHaveKey('selected');
    expect($watch['items'][0]['origin'])->toBe('auto');
});

it('breaks a full timestamp tie to exactly one auto item per source', function () {
    [$pro] = poolTenant();
    $source = poolSource($pro->id, poolConnection($pro->id));

    // A bulk first-ingest: same first_seen_at, NO published facet — the live
    // smoke found a 15-way tie where every item won. Exactly one must.
    $stamp = now()->subDays(3);
    foreach (['One', 'Two', 'Three'] as $headline) {
        $id = (string) Str::uuid();
        DB::connection('pgsql')->table('content.items')->insert([
            'id' => $id, 'user_id' => $pro->id, 'kind' => 'video',
            'headline_cache' => $headline, 'facets_cache' => '[]',
            'first_seen_at' => $stamp, 'last_seen_at' => $stamp,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('pgsql')->table('content.source_items')->insert([
            'id' => (string) Str::uuid(), 'source_id' => $source,
            'coord' => 'x:'.Str::random(8), 'item_id' => $id, 'kind' => 'video',
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    expect(poolGet($pro)['selection'])->toHaveCount(1);
});

it('never lets an undated item outrank a dated one — on the auto arm, the recency order and the Latest tag (X5)', function () {
    // Overnight X5: an Apple song with no releaseDate ("Runway Houses City
    // Clouds (2020 Mix)") carried first_seen_at = connect time and beat every
    // dated release of the same source on COALESCE(published, first_seen), so
    // it took the auto slot and the Latest tag off last month's single.
    config(['partna.pools.auto_latest_n' => 10]);
    [$pro] = poolTenant();
    $source = poolSource($pro->id, poolConnection($pro->id));

    $dated = poolItem($pro->id, $source, 'video', 'Dated last month', now()->subDays(30)->toDateTimeString());
    $undated = poolItem($pro->id, $source, 'video', 'Undated, seen today', now()->toDateTimeString());
    DB::table('content.f_published')->where('item_id', $undated)->delete();
    DB::table('content.items')->where('id', $undated)->update(['first_seen_at' => now()]);

    $data = poolGet($pro);
    expect(poolHeadlines($data))->toBe(['Dated last month']);
    expect($data['latestItemId'])->toBe($dated);

    // Same rule for a media pool's N-newest window: dated rows sort first,
    // undated ones after (by first-seen among themselves).
    $media = poolSource($pro->id, poolConnection($pro->id, 'instagram.profile'));
    $seenFirst = poolItem($pro->id, $media, 'media', 'Undated A', now()->toDateTimeString());
    $seenLater = poolItem($pro->id, $media, 'media', 'Undated B', now()->toDateTimeString());
    $datedMedia = poolItem($pro->id, $media, 'media', 'Dated', now()->subYear()->toDateTimeString());
    DB::table('content.f_published')->whereIn('item_id', [$seenFirst, $seenLater])->delete();
    DB::table('content.items')->where('id', $seenFirst)->update(['first_seen_at' => now()->subHour()]);
    DB::table('content.items')->where('id', $seenLater)->update(['first_seen_at' => now()]);

    expect(array_column(poolGet($pro, 'media')['selection'], 'id'))->toBe([$datedMedia, $seenLater, $seenFirst]);
});

it('hand-adds an item by link: manual source, pinned, titled', function () {
    [$pro] = poolTenant();

    $request = Request::create('/api/content/pools/watch/items', 'POST', [
        'url' => 'https://vimeo.com/123456789', 'title' => 'Our showreel',
    ]);
    $request->attributes->set('professional', $pro);
    $data = app(PoolItemCreateController::class)
        ->store($request, 'watch')->getData(true);

    expect(array_column($data['selection'], 'headline'))->toBe(['Our showreel']);
    expect($data['selection'][0]['origin'])->toBe('manual');
    expect($data['selection'][0]['url'])->toBe('https://vimeo.com/123456789');
    expect(DB::connection('pgsql')->table('content.sources')
        ->where('user_id', $pro->id)->where('kind', 'manual')->count())->toBe(1);

    // A second add reuses the one manual source.
    $again = Request::create('/api/content/pools/watch/items', 'POST', [
        'url' => 'https://youtu.be/dQw4w9WgXcQ',
    ]);
    $again->attributes->set('professional', $pro);
    app(PoolItemCreateController::class)->store($again, 'watch');
    expect(DB::connection('pgsql')->table('content.sources')
        ->where('user_id', $pro->id)->where('kind', 'manual')->count())->toBe(1);
});

it('hand-adds an item that a later connector run enriches instead of stranding', function () {
    // The defect this slice replaces: the old hand-rolled writer wrote no
    // identity keys and no anchor, so resolveItems() — which unions every
    // live source item for (user, kind) across ALL sources — saw a keyless
    // singleton, minted a blank content.items row for it, and repointed the
    // hand-added source item onto that blank. The owner kept seeing their
    // item only because the pin references it by id.
    [$pro] = poolTenant();

    actingAsUser($pro)
        ->postJson(route('content.pools.items.store', ['pool' => 'watch']), [
            'url' => 'https://vimeo.com/123456789', 'title' => 'Our showreel',
        ])
        ->assertCreated();

    $sourceId = DB::connection('pgsql')->table('content.sources')
        ->where('user_id', $pro->id)->where('kind', 'manual')->value('id');
    $sourceItem = DB::connection('pgsql')->table('content.source_items')->where('source_id', $sourceId)->first();

    expect(DB::connection('pgsql')->table('content.identity_keys')
        ->where('source_item_id', $sourceItem->id)->orderBy('key_class')->pluck('key_class')->all())
        ->toBe(['canonical_url', 'platform_object', 'title_loose', 'title_only'])
        ->and(DB::connection('pgsql')->table('content.item_anchors')
            ->where('user_id', $pro->id)->where('coord', $sourceItem->coord)->value('item_id'))
        ->toBe($sourceItem->item_id)
        // One coord per url, so a repeat POST cannot poison it (Task 4).
        ->and($sourceItem->coord)->toBe('manual:'.sha1('https://vimeo.com/123456789'));

    // Exactly one item, and it is the one the source row points at — no blank
    // duplicate, nothing stranded.
    $items = DB::connection('pgsql')->table('content.items')->where('user_id', $pro->id)->get();
    expect($items)->toHaveCount(1)
        ->and($items[0]->id)->toBe($sourceItem->item_id)
        ->and($items[0]->headline_cache)->toBe('Our showreel');
});

it('re-adding the same url upserts one coord rather than poisoning it', function () {
    // Two coords on one url would poison that url for the whole resolution
    // run (Task 4). The deterministic coord makes the second POST an upsert.
    [$pro] = poolTenant();
    $payload = ['url' => 'https://vimeo.com/123456789', 'title' => 'Our showreel'];
    $route = route('content.pools.items.store', ['pool' => 'watch']);

    actingAsUser($pro)->postJson($route, $payload)->assertCreated();

    // The second call must not 500 on section_items_unique, and must not add
    // a second item, a second source item, or a second pin.
    $response = actingAsUser($pro)->postJson($route, $payload)->assertCreated();

    // ApiController::success() returns the payload unwrapped — there is no
    // `data` envelope on this endpoint.
    expect($response->json('selection'))->toHaveCount(1)
        ->and(DB::connection('pgsql')->table('content.items')->where('user_id', $pro->id)->count())->toBe(1)
        ->and(DB::connection('pgsql')->table('content.source_items')->count())->toBe(1);
});

// ── Code-review regressions, slice 0b ──────────────────────────────────────
// All four were introduced by the deterministic coord and the owner-preference
// merge, and all four were confirmed by probe before being fixed.

it('re-adding a url the owner previously deleted brings the item back', function () {
    // HIGH. The coord is deterministic, so a re-add resolves to the SAME item.
    // upsertSourceItem() clears source_items.removed_at but the user-level
    // delete lives on items.removed_at, which PoolResolver filters on — so the
    // re-add returned 201 with an empty selection and no route back.
    [$pro] = poolTenant();
    $route = route('content.pools.items.store', ['pool' => 'watch']);
    $payload = ['url' => 'https://vimeo.com/123456789', 'title' => 'Our showreel'];

    actingAsUser($pro)->postJson($route, $payload)->assertCreated();
    $itemId = DB::connection('pgsql')->table('content.items')->where('user_id', $pro->id)->value('id');

    DB::connection('pgsql')->table('content.items')->where('id', $itemId)->update(['removed_at' => now()]);

    $response = actingAsUser($pro)->postJson($route, $payload)->assertCreated();

    expect(DB::connection('pgsql')->table('content.items')->where('id', $itemId)->value('removed_at'))->toBeNull()
        ->and($response->json('selection'))->toHaveCount(1);
});

it('re-adding an excluded item pins it rather than leaving it excluded', function () {
    // HIGH. section_items holds pinned AND excluded under one UNIQUE
    // (section_id, item_id), so an exists() guard matched the excluded row and
    // skipped the pin — a hand-add that silently did nothing.
    [$pro] = poolTenant();
    $route = route('content.pools.items.store', ['pool' => 'watch']);
    $payload = ['url' => 'https://vimeo.com/123456789', 'title' => 'Our showreel'];

    actingAsUser($pro)->postJson($route, $payload)->assertCreated();
    $itemId = DB::connection('pgsql')->table('content.items')->where('user_id', $pro->id)->value('id');
    DB::connection('pgsql')->table('site.section_items')->where('item_id', $itemId)->update(['state' => 'excluded']);

    $response = actingAsUser($pro)->postJson($route, $payload)->assertCreated();

    expect(DB::connection('pgsql')->table('site.section_items')->where('item_id', $itemId)->value('state'))
        ->toBe('pinned')
        ->and($response->json('selection'))->toHaveCount(1);
});

it('a title-less re-add keeps the stored headline instead of the url host', function () {
    // The manual source is priority 200, so the host fallback would beat every
    // connector headline and rename the item to "vimeo.com" product-wide.
    [$pro] = poolTenant();
    $route = route('content.pools.items.store', ['pool' => 'watch']);

    actingAsUser($pro)->postJson($route, ['url' => 'https://vimeo.com/123456789', 'title' => 'Our showreel'])
        ->assertCreated();
    actingAsUser($pro)->postJson($route, ['url' => 'https://vimeo.com/123456789'])->assertCreated();

    expect(DB::connection('pgsql')->table('content.items')->where('user_id', $pro->id)->value('headline_cache'))
        ->toBe('Our showreel');
});

it('refuses a kind change on a url already in the library', function () {
    // Applying it would desynchronise source_items.kind from the anchored
    // items.kind and strand the item from every future resolve of its own
    // kind. Folding kind into the coord is the other way to stay consistent,
    // but that mints two coords for one url and poisons it.
    [$pro] = poolTenant();
    $route = route('content.pools.items.store', ['pool' => 'listen']);

    actingAsUser($pro)->postJson($route, ['url' => 'https://open.spotify.com/track/3n3Ppam7vgaVa1iaRUc9Lp', 'kind' => 'track'])
        ->assertCreated();

    actingAsUser($pro)->postJson($route, ['url' => 'https://open.spotify.com/track/3n3Ppam7vgaVa1iaRUc9Lp', 'kind' => 'release'])
        ->assertStatus(422);

    expect(DB::connection('pgsql')->table('content.source_items')->value('kind'))->toBe('track');
});

// ── Listen restructure (2026-08-18): newest per FORMAT, per switch ─────────

it('listen auto-selects a source\'s newest release AND its newest track, each behind its own switch, and never lets a track ride the release arm', function () {
    [$pro] = poolTenant();
    $apple = poolSource($pro->id, poolConnection($pro->id, 'apple_music.artist'));
    $spotify = poolSource($pro->id, poolConnection($pro->id, 'spotify.artist'));

    poolItem($pro->id, $apple, 'release', 'Old album', now()->subYears(2)->toDateTimeString());
    poolItem($pro->id, $apple, 'release', 'New single', now()->subDays(3)->toDateTimeString());
    poolItem($pro->id, $apple, 'track', 'Old song', now()->subYears(2)->toDateTimeString());
    poolItem($pro->id, $apple, 'track', 'New song', now()->subDays(3)->toDateTimeString());
    // Spotify emits tracks only; with the arms split it must NOT publish every
    // track because "zero newer releases" holds vacuously for a track-only source.
    poolItem($pro->id, $spotify, 'track', 'Spotify older', now()->subDays(9)->toDateTimeString());
    poolItem($pro->id, $spotify, 'track', 'Spotify newest', now()->subDays(1)->toDateTimeString());

    expect(poolHeadlines(poolGet($pro, 'listen')))->toEqualCanonicalizing(['New single', 'New song', 'Spotify newest']);

    // The release switch off keeps the songs; the track switch off keeps the release.
    DB::table('site.platform_connections')->where('user_id', $pro->id)->where('surface_key', 'apple_music.artist')
        ->update(['display_settings' => json_encode(['auto_sync_latest' => false])]);
    expect(poolHeadlines(poolGet($pro, 'listen')))->toEqualCanonicalizing(['New song', 'Spotify newest']);

    DB::table('site.platform_connections')->where('user_id', $pro->id)->where('surface_key', 'apple_music.artist')
        ->update(['display_settings' => json_encode(['auto_sync_latest_track' => false])]);
    expect(poolHeadlines(poolGet($pro, 'listen')))->toEqualCanonicalizing(['New single', 'Spotify newest']);
});

// ── Manual-lane provenance on the wire (gsnwilliams, 2026-08-18) ────────────

it('lists a manual-lane item\'s own link once, with its platform glyph, as `own` — not twice and not "synced"', function () {
    // The event sheet showed the same eventbrite URL twice: once from f_link
    // (manual source → NULL platform → blank glyph, host-as-title) and once
    // synthesised from the offer url by host (eventbrite), both badged
    // "Synced" — the dedupe keyed on platform, a NULL platform was never
    // marked seen, and 'synced' was hard-coded for every source row.
    [$pro] = poolTenant();
    $manual = poolSource($pro->id, null);
    $url = 'https://www.eventbrite.com.au/e/hobart-mens-hair-workshop-tickets-1993984195405';
    $event = poolItem($pro->id, $manual, 'video', 'Hobart Mens Hair Workshop', now()->toDateTimeString());
    DB::table('content.f_link')->insert(['item_id' => $event, 'source_id' => $manual, 'url' => $url, 'updated_at' => now()]);
    DB::table('content.offers')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $event, 'source_id' => $manual,
        'amount_minor' => 16014, 'currency' => 'AUD', 'qualifier' => 'from', 'url' => $url,
        'updated_at' => now(),
    ]);

    $item = collect(poolGet($pro, 'watch')['library'])->firstWhere('id', $event);

    expect($item['links'])->toBe([
        ['platform' => 'eventbrite', 'url' => $url, 'source' => 'own'],
    ]);
});

it('says where a manual-lane item came from: no origin tag = added by hand, an origin tag = discovered', function () {
    [$pro] = poolTenant();
    $manual = poolSource($pro->id, null);
    $byHand = poolItem($pro->id, $manual, 'video', 'By hand', now()->toDateTimeString());
    $found = poolItem($pro->id, $manual, 'video', 'Found in bio', now()->subMinute()->toDateTimeString());
    DB::table('content.item_tags')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $found, 'source_id' => $manual,
        'tag' => 'link_in_bio', 'tag_type' => 'origin',
    ]);

    $library = collect(poolGet($pro, 'watch')['library']);

    expect($library->firstWhere('id', $byHand)['sources'][0])->toMatchArray(['kind' => 'manual', 'origin' => null])
        ->and($library->firstWhere('id', $found)['sources'][0])->toMatchArray(['kind' => 'manual', 'origin' => 'link_in_bio']);
});
