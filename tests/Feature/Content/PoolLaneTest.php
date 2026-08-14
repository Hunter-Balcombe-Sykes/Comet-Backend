<?php

use App\Http\Controllers\Api\Content\ItemController;
use App\Http\Controllers\Api\Content\ItemLinkController;
use App\Http\Controllers\Api\Content\PoolController;
use App\Http\Controllers\Api\Content\PoolItemCreateController;
use App\Models\Core\Site\Site;
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
    [$pro] = poolTenant();
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
    [$pro] = poolTenant();
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

// ── The library delete ──────────────────────────────────────────────────────

it('removes an item from selection and library via removed_at', function () {
    [$pro] = poolTenant();
    $source = poolSource($pro->id, poolConnection($pro->id));
    $item = poolItem($pro->id, $source, 'video', 'Doomed', now()->toDateTimeString());

    $request = Request::create("/api/content/items/{$item}", 'DELETE');
    $request->attributes->set('professional', $pro);
    expect(app(ItemController::class)->destroy($request, $item)->getStatusCode())->toBe(200);

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
    expect($make('spotify', 'https://open.spotify.com/track/x'))->toBe(422);
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

// ── The public wire ─────────────────────────────────────────────────────────

it('serves the pool selection on the public payload with the Latest tag', function () {
    setupMediaTables();
    setupContentSelectionTable();
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
            'headline_cache' => $headline, 'facets_cache' => '[]', 'eligible_cache' => '[]',
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

it('hand-adds an item by link: manual source, pinned, titled', function () {
    [$pro] = poolTenant();

    $request = Request::create('/api/content/pools/watch/items', 'POST', [
        'url' => 'https://vimeo.com/999', 'title' => 'Our showreel',
    ]);
    $request->attributes->set('professional', $pro);
    $data = app(PoolItemCreateController::class)
        ->store($request, 'watch')->getData(true);

    expect(array_column($data['selection'], 'headline'))->toBe(['Our showreel']);
    expect($data['selection'][0]['origin'])->toBe('manual');
    expect($data['selection'][0]['url'])->toBe('https://vimeo.com/999');
    expect(DB::connection('pgsql')->table('content.sources')
        ->where('user_id', $pro->id)->where('kind', 'manual')->count())->toBe(1);

    // A second add reuses the one manual source.
    $again = Request::create('/api/content/pools/watch/items', 'POST', [
        'url' => 'https://youtu.be/abc',
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
            'url' => 'https://vimeo.com/999', 'title' => 'Our showreel',
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
        ->and($sourceItem->coord)->toBe('manual:'.sha1('https://vimeo.com/999'));

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
    $payload = ['url' => 'https://vimeo.com/999', 'title' => 'Our showreel'];
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
    $payload = ['url' => 'https://vimeo.com/999', 'title' => 'Our showreel'];

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
    $payload = ['url' => 'https://vimeo.com/999', 'title' => 'Our showreel'];

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

    actingAsUser($pro)->postJson($route, ['url' => 'https://vimeo.com/999', 'title' => 'Our showreel'])
        ->assertCreated();
    actingAsUser($pro)->postJson($route, ['url' => 'https://vimeo.com/999'])->assertCreated();

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

    actingAsUser($pro)->postJson($route, ['url' => 'https://open.spotify.com/track/x', 'kind' => 'track'])
        ->assertCreated();

    actingAsUser($pro)->postJson($route, ['url' => 'https://open.spotify.com/track/x', 'kind' => 'release'])
        ->assertStatus(422);

    expect(DB::connection('pgsql')->table('content.source_items')->value('kind'))->toBe('track');
});
