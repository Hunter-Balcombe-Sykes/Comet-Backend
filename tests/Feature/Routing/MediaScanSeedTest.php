<?php

use App\Ingest\Projection\ProjectionWriter;
use App\Models\Core\Site\IntegrationConnection;
use App\Routing\Importers\LinkInBioImporter;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\LinkRouter;
use App\Services\Platforms\MediaPageReader;
use App\Services\Platforms\MediaParentSuggester;
use App\Services\Platforms\MediaSeeder;
use App\Services\Platforms\RouteContext;
use App\Services\Platforms\WebsiteLinkHarvester;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// T6 (2026-08-20): scan lanes seed MEDIA ITEMS — a bio's video/track/episode
// links become real watch/listen pool items (library-only, never auto-pinned),
// mirroring how events joined on 2026-08-19. The gates the plan pins:
// channel link connects, item links become items (not cards), re-scan is
// idempotent, and a removed item STAYS removed (and never re-cards).

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupNotificationsTable();
    setupRoutingTables();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
});

function mediaBioFake(): void
{
    $anchors = implode('', array_map(fn ($u) => '<a href="'.$u.'">x</a>', [
        'https://www.youtube.com/channel/UCCY6AIHHvrmZW5J8IAjkAA1',
        'https://www.youtube.com/watch?v=video0001',
        'https://youtu.be/video0002',
        'https://www.youtube.com/shorts/video0003',
    ]));

    Http::fake([
        'linktr.ee/*' => Http::response($anchors, 200),
        // One oEmbed answer per video id, so three distinct items land.
        'youtube.com/oembed*video0001*' => Http::response(json_encode(['title' => 'Video One', 'thumbnail_url' => 'https://i.ytimg.com/1.jpg']), 200, ['Content-Type' => 'application/json']),
        'youtube.com/oembed*video0002*' => Http::response(json_encode(['title' => 'Video Two', 'thumbnail_url' => 'https://i.ytimg.com/2.jpg']), 200, ['Content-Type' => 'application/json']),
        'youtube.com/oembed*video0003*' => Http::response(json_encode(['title' => 'Video Three', 'thumbnail_url' => 'https://i.ytimg.com/3.jpg']), 200, ['Content-Type' => 'application/json']),
        '*' => Http::response('', 404),
    ]);
}

it('turns a bio with a channel link and three video links into one connection and three REAL video items', function () {
    Queue::fake();
    $pro = createTenant('media-scan');
    mediaBioFake();

    $result = app(LinkInBioImporter::class)->import($pro, 'https://linktr.ee/mediascan', 'bio_harvest');

    expect($result['connected'])->toBe(1)
        ->and($result['items'])->toBe(3)
        ->and($result['noted'])->toBe(0)
        ->and($result['probed'])->toBe(0);

    $items = DB::connection('pgsql')->table('content.items')
        ->where('user_id', $pro->id)->where('kind', 'video')->get();
    expect($items)->toHaveCount(3)
        ->and($items->pluck('headline_cache')->sort()->values()->all())
        ->toBe(['Video One', 'Video Three', 'Video Two']);

    // Canonical folding: the youtu.be short link landed in watch?v= form.
    expect(DB::table('content.f_link')->pluck('url')->all())
        ->toContain('https://www.youtube.com/watch?v=video0002');

    // Library only — a scan never pins (owner, 2026-08-20).
    expect(DB::connection('pgsql')->table('site.section_items')
        ->whereIn('item_id', $items->pluck('id'))->count())->toBe(0);

    // The origin tag, so the sheet can say "found in your bio link".
    expect(DB::table('content.item_tags')->where('tag_type', 'origin')->where('tag', 'bio_harvest')->count())->toBe(3);
});

it('re-scans idempotently and never resurrects a removed item — no duplicate, no card', function () {
    Queue::fake();
    $pro = createTenant('media-rescan');
    mediaBioFake();

    app(LinkInBioImporter::class)->import($pro, 'https://linktr.ee/mediascan', 'bio_harvest');

    // The owner removes one video.
    $removedId = DB::connection('pgsql')->table('content.items')
        ->where('user_id', $pro->id)->where('headline_cache', 'Video Two')->value('id');
    DB::connection('pgsql')->table('content.items')->where('id', $removedId)->update(['removed_at' => now()]);

    // Cooldown guard: a second import run for the same user needs its own
    // run slot — clear the first run row so the re-scan isn't refused.
    DB::table('routing.import_runs')->delete();

    $again = app(LinkInBioImporter::class)->import($pro, 'https://linktr.ee/mediascan', 'bio_harvest');

    // Still three "handled" items in the tally, and the run detail SAYS one
    // of them was a deliberate no-write (suppression read, not absorbed) —
    // still exactly three item rows, the removed one still removed, and NO
    // link card was written for it.
    expect($again['items'])->toBe(3)
        ->and($again['tombstoned'])->toBe(1);
    expect(DB::connection('pgsql')->table('content.items')->where('user_id', $pro->id)->where('kind', 'video')->count())->toBe(3)
        ->and(DB::connection('pgsql')->table('content.items')->where('id', $removedId)->value('removed_at'))->not->toBeNull()
        ->and(DB::connection('pgsql')->table('content.items')->where('user_id', $pro->id)->where('kind', 'link')->count())->toBe(0);
});

it('never resurrects a removed scanned EVENT either (F4)', function () {
    Queue::fake();
    $pro = createTenant('event-rescan');
    $anchors = '<a href="https://lu.ma/warehouse-rave-f4">x</a>';
    $eventHtml = '<html><head><script type="application/ld+json">'.json_encode([
        '@context' => 'https://schema.org', '@type' => 'MusicEvent', 'name' => 'Warehouse Rave',
        'url' => 'https://lu.ma/warehouse-rave-f4', 'startDate' => '2099-03-01T20:00:00+11:00',
    ]).'</script></head><body></body></html>';
    Http::fake([
        'linktr.ee/*' => Http::response($anchors, 200),
        'lu.ma/*' => Http::response($eventHtml, 200),
        '*' => Http::response('', 404),
    ]);

    app(LinkInBioImporter::class)->import($pro, 'https://linktr.ee/eventrescan', 'bio_harvest');
    $eventId = DB::connection('pgsql')->table('content.items')
        ->where('user_id', $pro->id)->where('kind', 'event')->value('id');
    expect($eventId)->not->toBeNull();

    DB::connection('pgsql')->table('content.items')->where('id', $eventId)->update(['removed_at' => now()]);
    DB::table('routing.import_runs')->delete();

    app(LinkInBioImporter::class)->import($pro, 'https://linktr.ee/eventrescan', 'bio_harvest');

    expect(DB::connection('pgsql')->table('content.items')->where('id', $eventId)->value('removed_at'))->not->toBeNull()
        ->and(DB::connection('pgsql')->table('content.items')->where('user_id', $pro->id)->where('kind', 'event')->count())->toBe(1)
        ->and(DB::connection('pgsql')->table('content.items')->where('user_id', $pro->id)->where('kind', 'link')->count())->toBe(0);
});

it('classifies media ITEM urls as content-item — kind and canonical riding along', function () {
    $harvester = new WebsiteLinkHarvester(app(SafeUrlFetcher::class));

    expect($harvester->classify('https://youtu.be/dQw4w9WgXcQ'))->toMatchArray([
        'category' => 'content-item', 'platform' => 'youtube', 'kind' => 'video',
        'canonical' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    ]);
    expect($harvester->classify('https://open.spotify.com/episode/512ojhOuo1ktJprKbVcKyQ'))->toMatchArray([
        'category' => 'content-item', 'kind' => 'episode',
    ]);
    // ACCOUNT shapes stay accounts — the item arm must not swallow them.
    expect($harvester->classify('https://open.spotify.com/artist/4gzpq5DPGxSnKTe4SA8HAU')['category'] ?? null)
        ->not->toBe('content-item');
});

it('suggests the item\'s PARENT account in the routing inbox — never auto-connects it (T9b)', function () {
    Queue::fake();
    $pro = createTenant('t9b-scan');
    // A bio with ONE video link; its oEmbed names the channel.
    Http::fake([
        'linktr.ee/*' => Http::response('<a href="https://youtu.be/dQw4w9WgXcQ">watch</a>', 200),
        'youtube.com/oembed*' => Http::response(json_encode([
            'title' => 'The Video', 'thumbnail_url' => null,
            'author_url' => 'https://www.youtube.com/channel/UCparentparentparentpar1',
        ]), 200, ['Content-Type' => 'application/json']),
        '*' => Http::response('', 404),
    ]);

    app(LinkInBioImporter::class)->import($pro, 'https://linktr.ee/t9b', 'bio_harvest');

    // The item landed AND the channel is a QUESTION in the inbox — proposed,
    // never applied.
    expect(DB::connection('pgsql')->table('content.items')->where('user_id', $pro->id)->where('kind', 'video')->count())->toBe(1);
    $intent = DB::table('routing.source_intents')
        ->where('user_id', $pro->id)->where('surface_key', 'youtube.channel')->first();
    expect($intent)->not->toBeNull()
        ->and($intent->state)->toBe('proposed')
        ->and($intent->identifier)->toBe('UCparentparentparentpar1');
    expect(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(0);
});

it('never re-suggests a parent the owner DISCONNECTED (T9b tombstone, policy-owned)', function () {
    Queue::fake();
    $pro = createTenant('t9b-tomb');
    Http::fake([
        'linktr.ee/*' => Http::response('<a href="https://youtu.be/dQw4w9WgXcQ">watch</a>', 200),
        'youtube.com/oembed*' => Http::response(json_encode([
            'title' => 'The Video', 'thumbnail_url' => null,
            'author_url' => 'https://www.youtube.com/channel/UCparentparentparentpar1',
        ]), 200, ['Content-Type' => 'application/json']),
        '*' => Http::response('', 404),
    ]);
    DB::table('routing.item_tombstones')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'source_ref' => 'youtube.channel:UCparentparentparentpar1',
        'scope' => 'this_source',
        'reason' => 'owner disconnected the channel',
        'created_at' => now(),
    ]);

    app(LinkInBioImporter::class)->import($pro, 'https://linktr.ee/t9btomb', 'bio_harvest');

    // Item still lands; the disconnected parent stays silent.
    expect(DB::connection('pgsql')->table('content.items')->where('user_id', $pro->id)->where('kind', 'video')->count())->toBe(1)
        ->and(DB::table('routing.source_intents')
            ->where('user_id', $pro->id)->where('surface_key', 'youtube.channel')
            ->whereIn('state', ['proposed', 'blocked'])->count())->toBe(0);
});

it('routes a platform ITEM even after that platform\'s ACCOUNT consumed the slot — order-independent (F2)', function () {
    // The critic reproduced the asymmetry: [artist, track] lost the track to
    // a card while [track, artist] landed both. The slot rule is about
    // CONNECTIONS; items bypass it on BOTH the check and the set.
    Queue::fake();
    $pro = createTenant('f2-order');
    Http::fake([
        'open.spotify.com/oembed*' => Http::response(json_encode(['title' => 'The Track', 'thumbnail_url' => null]), 200, ['Content-Type' => 'application/json']),
        '*' => Http::response('', 404),
    ]);

    $ctx = new RouteContext;
    $router = app(LinkRouter::class);
    // Account first — consumes the spotify slot…
    $router->route($pro, 'https://open.spotify.com/artist/4gzpq5DPGxSnKTe4SA8HAU', $ctx);
    // …and the ITEM from the same platform must still become an item.
    $result = $router->route($pro, 'https://open.spotify.com/track/3n3Ppam7vgaVa1iaRUc9Lp', $ctx);

    expect($result->handled)->toBeTrue()
        ->and(DB::connection('pgsql')->table('content.items')
            ->where('user_id', $pro->id)->where('kind', 'track')->count())->toBe(1);
});

it('caps one run at MAX_ITEMS_PER_RUN seeds', function () {
    $pro = createTenant('media-cap');

    $reader = Mockery::mock(MediaPageReader::class);
    // The seeder runs the pure grammar first (existing-item dedupe rides it)
    // and only then the page read.
    $reader->shouldReceive('classifyItem')->andReturnUsing(fn (string $url) => [
        'platform' => 'youtube', 'kind' => 'video', 'canonical' => $url,
    ]);
    $reader->shouldReceive('read')->andReturnUsing(fn (string $url) => [
        'platform' => 'youtube', 'kind' => 'video',
        'canonical' => $url, 'title' => 'T '.$url, 'thumbnail' => null,
    ]);

    $seeder = new MediaSeeder($reader, app(ProjectionWriter::class), app(MediaParentSuggester::class));
    $written = 0;
    for ($i = 0; $i < MediaSeeder::MAX_ITEMS_PER_RUN + 5; $i++) {
        if ($seeder->seedItem($pro, "https://www.youtube.com/watch?v=cap{$i}") !== null) {
            $written++;
        }
    }

    expect($written)->toBe(MediaSeeder::MAX_ITEMS_PER_RUN);
});

it('derives a Spotify track\'s artist from the embed page and suggests it (FI-4)', function () {
    // Spotify's oEmbed carries NO author_url — the sammy.pdf baseline seeded
    // the track but never suggested the artist. The public embed page names
    // the artist; MediaPageReader now reads it when oEmbed came back blank.
    Queue::fake();
    $pro = createTenant('fi4-spotify');
    Http::fake([
        'linktr.ee/*' => Http::response('<a href="https://open.spotify.com/track/5WOkoJzd6nDzKJXlVgVU5q">listen</a>', 200),
        'open.spotify.com/oembed*' => Http::response(json_encode([
            'title' => 'Open Your Eyes (And Dance)', 'thumbnail_url' => 'https://i.scdn.co/t.jpg',
        ]), 200, ['Content-Type' => 'application/json']),
        'open.spotify.com/embed/track/*' => Http::response('{"artists":[{"uri":"artist/4WoNQlu21ftnkouDsSUtmS","name":"Sam Akhurst"}]}', 200),
        '*' => Http::response('', 404),
    ]);

    app(LinkInBioImporter::class)->import($pro, 'https://linktr.ee/fi4spotify', 'bio_harvest');

    expect(DB::connection('pgsql')->table('content.items')
        ->where('user_id', $pro->id)->where('kind', 'track')->count())->toBe(1);

    $intent = DB::table('routing.source_intents')
        ->where('user_id', $pro->id)->where('surface_key', 'spotify.player')->first();
    expect($intent)->not->toBeNull()
        ->and($intent->state)->toBe('proposed')
        ->and($intent->canonical_url)->toContain('artist/4WoNQlu21ftnkouDsSUtmS');
    expect(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(0);
});

it('claims locale-less Apple Music album/song/artist URLs on the right arm (L-2)', function () {
    $reader = app(MediaPageReader::class);

    // Item grammar — locale present AND absent.
    expect($reader->classifyItem('https://music.apple.com/au/album/x/111?i=222'))->toMatchArray(['platform' => 'apple-music', 'kind' => 'track'])
        ->and($reader->classifyItem('https://music.apple.com/album/x/111?i=222'))->toMatchArray(['platform' => 'apple-music', 'kind' => 'track'])
        ->and($reader->classifyItem('https://music.apple.com/album/x/111'))->toMatchArray(['platform' => 'apple-music', 'kind' => 'release'])
        ->and($reader->classifyItem('https://music.apple.com/song/y/333'))->toMatchArray(['platform' => 'apple-music', 'kind' => 'track']);

    // Account arm — locale optional there too, and items always win over it.
    expect($reader->accountPlatformLabel('https://music.apple.com/artist/sam-akhurst/1810969283'))->toBe('Apple Music')
        ->and($reader->accountPlatformLabel('https://music.apple.com/au/artist/sam-akhurst/1810969283'))->toBe('Apple Music')
        ->and($reader->accountPlatformLabel('https://music.apple.com/album/x/111?i=222'))->toBeNull();
});

it('never cards a link whose item the pool already holds, even when the page read fails (T1.5g round 2)', function () {
    // Live shape: the bio lane seeded the Spotify track; seconds later the
    // linktree lane routed the SAME track and its oEmbed re-read failed
    // transiently — the null sent the caller to its card write, duplicating
    // an item the pool already held as a "Spotify – Web Player" card. The
    // existing-item check must run BEFORE the page read.
    Queue::fake();
    $pro = createTenant('dedupe-before-read');

    // First seed: reads fine, item lands.
    Http::fake([
        'youtube.com/oembed*' => Http::response(json_encode(['title' => 'The Video', 'thumbnail_url' => null]), 200, ['Content-Type' => 'application/json']),
        '*' => Http::response('', 404),
    ]);
    $seeder = app(MediaSeeder::class);
    expect($seeder->seedItem($pro, 'https://www.youtube.com/watch?v=dedupe001', 'bio_harvest'))->not->toBeNull();

    // Second lane, same URL, oEmbed now DOWN: still handled, never null.
    Http::fake(['*' => Http::response('', 500)]);
    expect(app(MediaSeeder::class)->seedItem($pro, 'https://www.youtube.com/watch?v=dedupe001', 'link_in_bio'))
        ->toBe('https://www.youtube.com/watch?v=dedupe001');

    expect(DB::connection('pgsql')->table('content.items')->where('user_id', $pro->id)->count())->toBe(1);
});
