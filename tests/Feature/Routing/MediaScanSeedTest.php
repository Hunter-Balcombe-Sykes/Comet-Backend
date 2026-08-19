<?php

use App\Ingest\Projection\ProjectionWriter;
use App\Routing\Importers\LinkInBioImporter;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\MediaPageReader;
use App\Services\Platforms\MediaSeeder;
use App\Services\Platforms\WebsiteLinkHarvester;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

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

    // Still three "handled" items in the tally (the tombstoned one is
    // handled-without-write), still exactly three item rows, the removed one
    // still removed, and NO link card was written for it.
    expect($again['items'])->toBe(3);
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

it('caps one run at MAX_ITEMS_PER_RUN seeds', function () {
    $pro = createTenant('media-cap');

    $reader = Mockery::mock(MediaPageReader::class);
    $reader->shouldReceive('read')->andReturnUsing(fn (string $url) => [
        'platform' => 'youtube', 'kind' => 'video',
        'canonical' => $url, 'title' => 'T '.$url, 'thumbnail' => null,
    ]);

    $seeder = new MediaSeeder($reader, app(ProjectionWriter::class));
    $written = 0;
    for ($i = 0; $i < MediaSeeder::MAX_ITEMS_PER_RUN + 5; $i++) {
        if ($seeder->seedItem($pro, "https://www.youtube.com/watch?v=cap{$i}") !== null) {
            $written++;
        }
    }

    expect($written)->toBe(MediaSeeder::MAX_ITEMS_PER_RUN);
});
