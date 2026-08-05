<?php

use App\Http\Controllers\Api\Content\ItemController;
use App\Http\Controllers\Api\Content\ItemLinkController;
use App\Http\Controllers\Api\Content\PoolController;
use App\Models\Core\Site\Site;
use App\Services\PublicSite\IndividualProfilePayloadBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
});

function poolTenant(): array
{
    $pro = createTenant('pool-'.Str::lower(Str::random(6)));
    $siteId = (string) DB::table('site.sites')->where('user_id', $pro->id)->value('id');

    return [$pro, $siteId];
}

function poolConnection(string $userId, string $surfaceKey = 'youtube.channel', ?array $displaySettings = null): string
{
    $id = (string) Str::uuid();
    DB::table('site.platform_connections')->insert([
        'id' => $id, 'user_id' => $userId, 'surface_key' => $surfaceKey,
        'routing_class' => 'content', 'resource_id' => 'acct-'.Str::random(6),
        'payload' => '{}', 'is_active' => 1,
        'display_settings' => $displaySettings === null ? null : json_encode($displaySettings),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function poolSource(string $userId, ?string $connectionId): string
{
    $id = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $id, 'user_id' => $userId, 'kind' => $connectionId === null ? 'manual' : 'connection',
        'connection_id' => $connectionId, 'priority' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function poolItem(string $userId, string $sourceId, string $kind, string $headline, string $publishedAt): string
{
    $id = (string) Str::uuid();
    DB::table('content.items')->insert([
        'id' => $id, 'user_id' => $userId, 'kind' => $kind,
        'headline_cache' => $headline, 'facets_cache' => '[]', 'eligible_cache' => '[]',
        'first_seen_at' => now()->subDays(30), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId,
        'coord' => 'x:'.Str::random(8), 'item_id' => $id, 'kind' => $kind,
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    DB::table('content.f_published')->insert([
        'item_id' => $id, 'source_id' => $sourceId,
        'published_from' => $publishedAt, 'updated_at' => now(),
    ]);

    return $id;
}

function poolGet(object $pro, string $pool = 'watch'): array
{
    $request = Request::create("/api/content/pools/{$pool}", 'GET');
    $request->attributes->set('professional', $pro);

    return app(PoolController::class)->show($request, $pool)->getData(true);
}

function poolHeadlines(array $payload, string $key = 'selection'): array
{
    return array_column($payload[$key] ?? [], 'headline');
}

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
