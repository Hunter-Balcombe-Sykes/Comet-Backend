<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Cache\ScrapeCreatorsBudget;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\ConditionalContext;
use App\Services\Platforms\YoutubeScraper;
use App\Services\Platforms\YoutubeThumbnailResolver;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;

// Item 8 G3 (2026-09-01): YouTube is the TIERED lane — unlike Instagram the
// vendor is NOT primary. The free RSS feed answers every healthy poll with
// zero vendor calls, and ScrapeCreators' /v1/youtube/channel-videos (HYPHEN
// route) exists only to rescue the RSS ladder's terminal failures — the live
// AWS-egress 404/500 class that used to drop real channels. Pinned against a
// RECORDED payload (tests/fixtures/recorded/scrapecreators-youtube-channel-
// videos.json — a slimmed real answer for UCVeCg7M6VPx45EAjbkIeRTw from the
// 2026-09-01 trial). Two properties, every test serves one:
//
//  1. A vendor rescue is indistinguishable from an RSS answer — same keys,
//     same construction — plus lengthSeconds as pure additive enrichment.
//  2. Every other vendor outcome returns exactly what the caller was about
//     to receive anyway (null) — the lane can only rescue, never degrade,
//     and never spends a credit on a path that works today.

const SC_YT_CHANNEL = 'UCVeCg7M6VPx45EAjbkIeRTw';

function scYtFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-youtube-channel-videos.json')),
        true
    );
}

/** A scraper on a mocked RSS fetcher; the thumbnail resolver mock is strict —
 * any call on the vendor path (which carries its own thumbnails) would fail. */
function scYtScraper(MockInterface $fetcher, ?MockInterface $thumbnails = null): YoutubeScraper
{
    return new YoutubeScraper($fetcher, $thumbnails ?? Mockery::mock(YoutubeThumbnailResolver::class));
}

/** tryFetch → null: the ladder's fetch_null exit, which never retries. */
function scYtDeadFeedFetcher(): MockInterface
{
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->once()->andReturnNull();

    return $fetcher;
}

beforeEach(function () {
    config()->set('services.scrapecreators.key', 'test-key');
    config()->set('partna.limits.scrapecreators.global_daily_cap', 100);
    config()->set('partna.limits.scrapecreators.sources.youtube', 100);
});

it('maps the recorded vendor payload into the exact RSS shape when the feed dies', function () {
    Http::fake(['api.scrapecreators.com/*' => Http::response(scYtFixture())]);

    $feed = scYtScraper(scYtDeadFeedFetcher())->fetchUploadsFeed(SC_YT_CHANNEL);

    expect($feed)->not->toBeNull()
        ->and($feed['title'])->toBe('Fitness Cartel')
        ->and($feed['videos'])->toHaveCount(4);

    $first = $feed['videos'][0];
    expect($first['videoId'])->toBe('2h8oHlpTDy0')
        ->and($first['name'])->toBe('Fitness Cartel Group Fitness')
        ->and($first['link'])->toBe('https://www.youtube.com/watch?v=2h8oHlpTDy0')
        // publishDate, never publishedTime — the latter is synthesized at
        // scrape time (identical across every recorded item).
        ->and($first['date'])->toBe('2026-08-16T22:52:31-07:00')
        ->and($first['thumbnail'])->toStartWith('https://i.ytimg.com/vi/2h8oHlpTDy0/')
        ->and($first['lengthSeconds'])->toBe(21)
        ->and($first['description'])->not->toBe('');

    // The RSS six + lengthSeconds, nothing else: credits_* and the rest of the
    // vendor body can never ride into a persisted payload.
    expect(array_keys($first))->toBe(['videoId', 'name', 'description', 'link', 'date', 'thumbnail', 'lengthSeconds']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/youtube/channel-videos')
        && $request['channelId'] === SC_YT_CHANNEL
        && $request['sort'] === 'latest');
});

it('never calls the vendor when the RSS feed answers — the free lane stays primary', function () {
    Http::fake();
    $rss = <<<'XML'
        <?xml version="1.0"?>
        <feed xmlns:yt="http://www.youtube.com/xml/schemas/2015" xmlns:media="http://search.yahoo.com/mrss/" xmlns="http://www.w3.org/2005/Atom">
         <title>Fitness Cartel</title>
         <author><name>Fitness Cartel</name></author>
         <entry>
          <yt:videoId>rssVid00001</yt:videoId>
          <title>RSS Video</title>
          <media:description>from the feed</media:description>
          <published>2026-08-30T00:00:00+00:00</published>
         </entry>
        </feed>
        XML;

    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->once()->andReturn([
        'status' => 200, 'body' => $rss, 'finalUrl' => 'https://www.youtube.com/feeds/videos.xml',
        'contentType' => 'application/xml', 'etag' => null, 'lastModified' => null,
    ]);
    $thumbnails = Mockery::mock(YoutubeThumbnailResolver::class);
    $thumbnails->shouldReceive('bestForMany')->once()->with(['rssVid00001'])
        ->andReturn(['rssVid00001' => 'https://i.ytimg.com/vi/rssVid00001/hqdefault.jpg']);

    $feed = scYtScraper($fetcher, $thumbnails)->fetchUploadsFeed(SC_YT_CHANNEL);

    expect($feed['title'])->toBe('Fitness Cartel')
        ->and($feed['videos'][0]['videoId'])->toBe('rssVid00001')
        // Byte-identical RSS result: exactly the six RSS keys, no enrichment.
        ->and(array_keys($feed['videos'][0]))->toBe(['videoId', 'name', 'description', 'link', 'date', 'thumbnail']);
    Http::assertNothingSent();
});

it('rescues the AWS-egress 404 class after the retry ladder exhausts', function () {
    Http::fake(['api.scrapecreators.com/*' => Http::response(scYtFixture())]);
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    // The full ladder: the first request plus four spaced retries, all 404.
    $fetcher->shouldReceive('tryFetch')->times(5)->andReturn([
        'status' => 404, 'body' => '', 'finalUrl' => 'https://www.youtube.com/feeds/videos.xml',
        'contentType' => 'application/xml', 'etag' => null, 'lastModified' => null,
    ]);

    $feed = scYtScraper($fetcher)->fetchUploadsFeed(SC_YT_CHANNEL);

    expect($feed)->not->toBeNull()
        ->and($feed['videos'])->toHaveCount(4)
        ->and($feed['videos'][0]['videoId'])->toBe('2h8oHlpTDy0');
});

it('falls through unchanged on a vendor 5xx, releasing the budget slot', function () {
    config()->set('partna.limits.scrapecreators.sources.youtube', 1);
    Http::fake(['api.scrapecreators.com/*' => Http::response('upstream sad', 502)]);

    $feed = scYtScraper(scYtDeadFeedFetcher())->fetchUploadsFeed(SC_YT_CHANNEL);

    expect($feed)->toBeNull();
    // Transport-level null released the day's only slot.
    expect(app(ScrapeCreatorsBudget::class)->tryClaim('youtube'))->toBeTrue();
});

it('falls through on a success-shaped husk and keeps the billed slot spent', function () {
    config()->set('partna.limits.scrapecreators.sources.youtube', 1);
    // The NotFound quirk: success:true, a credit charged, nothing usable.
    Http::fake(['api.scrapecreators.com/*' => Http::response([
        'success' => true, 'credits_remaining' => 9999, 'credits_charged' => 1,
        'videos' => [], 'channels' => [], 'playlists' => [], 'shorts' => [], 'shelves' => [], 'lives' => [],
    ])]);

    $feed = scYtScraper(scYtDeadFeedFetcher())->fetchUploadsFeed(SC_YT_CHANNEL);

    expect($feed)->toBeNull();
    // The call was billed upstream — the slot stays spent.
    expect(app(ScrapeCreatorsBudget::class)->tryClaim('youtube'))->toBeFalse();
});

it('skips the vendor lane entirely when no key is configured', function () {
    config()->set('services.scrapecreators.key', null);
    Http::fake();

    expect(scYtScraper(scYtDeadFeedFetcher())->fetchUploadsFeed(SC_YT_CHANNEL))->toBeNull();
    Http::assertNothingSent();
});

it('skips the vendor lane when its budget is exhausted', function () {
    config()->set('partna.limits.scrapecreators.sources.youtube', 0);
    Http::fake();

    expect(scYtScraper(scYtDeadFeedFetcher())->fetchUploadsFeed(SC_YT_CHANNEL))->toBeNull();
    Http::assertNothingSent();
});

it('never spends a vendor credit on a healthy 304 poll', function () {
    Http::fake();
    config(['partna.refresh.conditional.enabled' => true]);
    $connection = new IntegrationConnection;
    $connection->refresh_etag = '"v1"';
    $connection->refresh_last_modified = null;
    $cond = ConditionalContext::for($connection);

    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->once()->andReturn([
        'status' => 304, 'body' => '', 'finalUrl' => 'https://www.youtube.com/feeds/videos.xml',
        'contentType' => 'application/xml', 'etag' => '"v1"', 'lastModified' => null,
    ]);

    $result = scYtScraper($fetcher)->fetchUploadsFeed(SC_YT_CHANNEL, 15, $cond);

    expect($result)->toBeNull()
        ->and($cond->notModified)->toBeTrue();
    Http::assertNothingSent();
});
