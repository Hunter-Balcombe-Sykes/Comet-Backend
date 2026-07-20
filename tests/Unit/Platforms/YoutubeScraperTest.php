<?php

// LIFE-25 / LIFE-26: resolveChannelId() and fetchUploadsFeed() used to return
// null on every failure branch with zero logging — a genuine upstream outage
// (blocked scrape, YouTube page-layout change, 5xx) was indistinguishable from
// a channel with no uploads. Pins: each GENUINE failure path now logs a
// warning with a discriminating `reason`, while the 304-not-modified
// short-circuit (a normal outcome on every healthy poll) stays silent.

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\ConditionalContext;
use App\Services\Platforms\YoutubeScraper;
use App\Services\Platforms\YoutubeThumbnailResolver;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

// Log::spy()/config() need the Laravel test framework bootstrapped (mirrors
// tests/Unit/Platforms/InstagramScraperTest.php).
uses(TestCase::class)->in(__FILE__);

afterEach(function () {
    Mockery::close();
});

/** A scraper wired to a mocked SafeUrlFetcher; the thumbnail resolver is never
 * reached by any of the failure paths under test, so it's left un-stubbed —
 * an unexpected call fails loudly via Mockery. */
function youtubeScraperWith(SafeUrlFetcher $fetcher): YoutubeScraper
{
    return new YoutubeScraper($fetcher, Mockery::mock(YoutubeThumbnailResolver::class));
}

// ── resolveChannelId() (private, exercised via channelIdFrom()) — LIFE-25 ──

it('logs a discriminating reason when the channel-page fetch fails at transport level', function () {
    Log::spy();
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->once()->andReturn(null);

    $result = youtubeScraperWith($fetcher)->channelIdFrom('somehandle');

    expect($result)->toBeNull();
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $ctx) => $message === 'youtube.channel_resolve_failed'
            && $ctx['handle'] === 'somehandle'
            && $ctx['reason'] === 'fetch_failed');
});

it('logs a discriminating reason when the channel page responds non-200', function () {
    Log::spy();
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->once()->andReturn([
        'status' => 503, 'body' => '', 'finalUrl' => 'https://www.youtube.com/@somehandle', 'contentType' => 'text/html',
    ]);

    $result = youtubeScraperWith($fetcher)->channelIdFrom('somehandle');

    expect($result)->toBeNull();
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $ctx) => $message === 'youtube.channel_resolve_failed'
            && $ctx['handle'] === 'somehandle'
            && $ctx['reason'] === 'non_200:503');
});

it('logs a discriminating reason when a 200 channel page matches none of the id patterns', function () {
    Log::spy();
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->once()->andReturn([
        'status' => 200, 'body' => '<html>a page with no channel id in it</html>',
        'finalUrl' => 'https://www.youtube.com/@somehandle', 'contentType' => 'text/html',
    ]);

    $result = youtubeScraperWith($fetcher)->channelIdFrom('somehandle');

    expect($result)->toBeNull();
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $ctx) => $message === 'youtube.channel_resolve_failed'
            && $ctx['handle'] === 'somehandle'
            && $ctx['reason'] === 'no_channel_id_match');
});

// ── fetchUploadsFeed() — LIFE-26 ────────────────────────────────────────────

it('logs a discriminating reason when the uploads-feed fetch fails at transport level', function () {
    Log::spy();
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->once()->andReturn(null);

    $result = youtubeScraperWith($fetcher)->fetchUploadsFeed('UCabcdefghijklmnopqrstuv');

    expect($result)->toBeNull();
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $ctx) => $message === 'youtube.uploads_feed_failed'
            && $ctx['channelId'] === 'UCabcdefghijklmnopqrstuv'
            && $ctx['reason'] === 'fetch_null');
});

it('logs a discriminating reason when the uploads feed responds non-200', function () {
    Log::spy();
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->once()->andReturn([
        'status' => 500, 'body' => '', 'finalUrl' => 'https://www.youtube.com/feeds/videos.xml', 'contentType' => 'application/xml',
        'etag' => null, 'lastModified' => null,
    ]);

    $result = youtubeScraperWith($fetcher)->fetchUploadsFeed('UCabcdefghijklmnopqrstuv');

    expect($result)->toBeNull();
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $ctx) => $message === 'youtube.uploads_feed_failed'
            && $ctx['channelId'] === 'UCabcdefghijklmnopqrstuv'
            && $ctx['reason'] === 'non_200:500');
});

it('stays silent on a 304 Not Modified — a normal outcome on every healthy poll, not a failure', function () {
    Log::spy();
    config(['partna.refresh.conditional.enabled' => true]);
    // Attributes only — never persisted. ConditionalContext::for() just reads
    // refresh_etag/refresh_last_modified off the model, no DB round-trip needed.
    $connection = new IntegrationConnection;
    $connection->refresh_etag = '"v1"';
    $connection->refresh_last_modified = null;
    $cond = ConditionalContext::for($connection);

    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->once()->andReturn([
        'status' => 304, 'body' => '', 'finalUrl' => 'https://www.youtube.com/feeds/videos.xml', 'contentType' => 'application/xml',
        'etag' => '"v1"', 'lastModified' => null,
    ]);

    $result = youtubeScraperWith($fetcher)->fetchUploadsFeed('UCabcdefghijklmnopqrstuv', 15, $cond);

    expect($result)->toBeNull();
    expect($cond->notModified)->toBeTrue();
    Log::shouldNotHaveReceived('warning');
});
