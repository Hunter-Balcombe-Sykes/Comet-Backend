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

// ── normalizeHandle() — the identity persisted as payload.handle ────────────

// Regression: normalizeHandle() matched only /@name, /c/name and /user/name, so
// a pasted /channel/UC… URL (YouTube's own "share channel" form) fell through
// verbatim and became the stored handle. resolveChannelId() then requested
// youtube.com/@https%3A%2F%2F… → 404 → "Could not find that YouTube channel".
// Whatever this returns is persisted and replayed by YoutubeFetch every 12h, so
// each form must reduce to something that ROUND-TRIPS through fetchRecentVideos().
it('reduces every channel-addressing URL form to a stable identity', function (string $input, string $expected) {
    $scraper = youtubeScraperWith(Mockery::mock(SafeUrlFetcher::class));

    expect($scraper->normalizeHandle($input))->toBe($expected);
})->with([
    'bare handle' => ['mrbeast', 'mrbeast'],
    '@handle' => ['@MrBeast', 'MrBeast'],
    'handle URL' => ['https://www.youtube.com/@MrBeast', 'MrBeast'],
    'handle URL, no scheme' => ['youtube.com/@MrBeast', 'MrBeast'],
    'handle URL with subpath' => ['https://www.youtube.com/@MrBeast/videos', 'MrBeast'],
    'handle URL with tracking query' => ['https://youtube.com/@mrbeast?si=abc123', 'mrbeast'],
    'mobile host' => ['https://m.youtube.com/@MrBeast', 'MrBeast'],
    '/c/ vanity' => ['https://www.youtube.com/c/MrBeast6000', 'MrBeast6000'],
    '/user/ vanity' => ['https://www.youtube.com/user/MrBeast6000', 'MrBeast6000'],
    // The two that regressed to a verbatim URL:
    '/channel/ id URL' => ['https://www.youtube.com/channel/UCX6OQ3DkcsbYNE6H8uQQuVA', 'UCX6OQ3DkcsbYNE6H8uQQuVA'],
    '/channel/ id URL with subpath' => ['https://www.youtube.com/channel/UCX6OQ3DkcsbYNE6H8uQQuVA/videos', 'UCX6OQ3DkcsbYNE6H8uQQuVA'],
    'legacy bare vanity' => ['https://www.youtube.com/MrBeast', 'MrBeast'],
    // A raw id must survive untouched — it is what a /channel/ connect persists,
    // so the 12h refresh feeds it straight back in.
    'raw channel id' => ['UCX6OQ3DkcsbYNE6H8uQQuVA', 'UCX6OQ3DkcsbYNE6H8uQQuVA'],
]);

// A video link addresses a video, not a channel. Reducing it to a token would
// send "LgbyEFILLJI" to the @handle resolver and produce a misleading
// "channel not found"; '' makes YoutubeConnect fail with the descriptor's
// "Enter your YouTube channel." instead.
it('refuses to read a video link as a channel', function (string $input) {
    $scraper = youtubeScraperWith(Mockery::mock(SafeUrlFetcher::class));

    expect($scraper->normalizeHandle($input))->toBe('');
})->with([
    'youtu.be short link' => ['https://youtu.be/LgbyEFILLJI'],
    'watch URL' => ['https://www.youtube.com/watch?v=LgbyEFILLJI'],
    'shorts URL' => ['https://www.youtube.com/shorts/LgbyEFILLJI'],
    'live URL' => ['https://www.youtube.com/live/LgbyEFILLJI'],
]);

// F6 follow-up, 2026-08-31. The bare-vanity branch matches ANY first path
// segment, so YouTube's own pages parsed as channels. Harmless while
// socialUsername() answered '' for youtube regardless; once F6 delegated the
// WRITE side to this parser each of these became a STORED identity pointing at
// nobody — the commit made the class worse than it found it. Same class the
// instagram arm's $reserved list closes.
it('refuses to read one of YouTube\'s own pages as a vanity channel', function (string $input) {
    $scraper = youtubeScraperWith(Mockery::mock(SafeUrlFetcher::class));

    expect($scraper->normalizeHandle($input))->toBe('');
})->with([
    'about' => ['https://www.youtube.com/about'],
    'the /t/ legal hub' => ['https://www.youtube.com/t/terms'],
    'creators' => ['https://www.youtube.com/creators'],
    'gaming' => ['https://www.youtube.com/gaming'],
    'premium' => ['https://www.youtube.com/premium'],
    // NON_CHANNEL_PATH only catches these WITH a trailing slash.
    'bare shorts' => ['https://www.youtube.com/shorts'],
    // The list is consulted case-insensitively — a capitalised paste is the
    // same page.
    'capitalised' => ['https://www.youtube.com/About'],
]);

// A reserved word is only evidence of a YouTube page where YouTube's own
// routing claims the path. @about is a handle a real person can own, so the
// guard above must not reach the prefixed forms.
it('still resolves a prefixed handle that happens to spell a reserved word', function () {
    $scraper = youtubeScraperWith(Mockery::mock(SafeUrlFetcher::class));

    expect($scraper->normalizeHandle('https://www.youtube.com/@about'))->toBe('about')
        ->and($scraper->normalizeHandle('https://www.youtube.com/c/gaming'))->toBe('gaming');
});

// F6 follow-up, 2026-08-31. The handle class was [A-Za-z0-9._-], which stopped
// dead at the first non-ASCII byte and returned the prefix it had already
// eaten: "@José" resolved to 'Jos'. F6's own governing rule is that no identity
// is safe and a WRONG one is not — 'Jos' resolves to somebody else's channel or
// to nothing, silently, and the write side now persists it.
it('resolves a non-ASCII handle whole instead of truncating it to a different channel', function () {
    $scraper = youtubeScraperWith(Mockery::mock(SafeUrlFetcher::class));

    expect($scraper->normalizeHandle('https://www.youtube.com/@José'))->toBe('José');
});

it('answers nothing rather than a truncated prefix when the handle is percent-encoded', function () {
    // The same handle as above off a share sheet that encoded it. '%' cannot
    // join the identity, and eating the 'Jos' in front of it is the exact
    // truncation the widened class exists to stop — so this one has no answer,
    // which is the safe half of the rule.
    $scraper = youtubeScraperWith(Mockery::mock(SafeUrlFetcher::class));

    expect($scraper->normalizeHandle('https://www.youtube.com/@Jos%C3%A9'))->toBe('');
});

// Punctuation that ENDS a link (a comma or bracket trailing a URL in scraped
// bio text) is not an identity character, so it must not trip the guard above.
it('keeps the whole handle when a link is followed by sentence punctuation', function () {
    $scraper = youtubeScraperWith(Mockery::mock(SafeUrlFetcher::class));

    expect($scraper->normalizeHandle('https://www.youtube.com/@MrBeast,'))->toBe('MrBeast')
        ->and($scraper->normalizeHandle('https://www.youtube.com/@MrBeast)'))->toBe('MrBeast');
});

// normalizeHandle's last resort is PlatformInput::token(), which for an
// unrecognised youtube URL is the WHOLE URL. Both callers already discard a
// slash-bearing answer; returning '' makes every path agree, and stops
// YoutubeConnect::resolve() spending a live channel-page fetch to learn it.
it('does not hand back a URL as though it were a handle', function () {
    $scraper = youtubeScraperWith(Mockery::mock(SafeUrlFetcher::class));

    expect($scraper->normalizeHandle('https://youtube.com/'))->toBe('')
        ->and($scraper->normalizeHandle('https://youtube.com/@'))->toBe('');
});

// The seam that caused this: fetchRecentVideos() called the private,
// handle-only resolver directly, bypassing channelIdFrom()'s format tolerance
// (which YoutubeMusicConnect was already using). A raw id must short-circuit
// to the feed with NO channel-page fetch at all.
it('resolves a raw channel id straight to the feed, without a channel-page fetch', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')
        ->once()
        ->withArgs(fn (string $url) => $url === 'https://www.youtube.com/feeds/videos.xml?channel_id=UCX6OQ3DkcsbYNE6H8uQQuVA')
        ->andReturn(null);

    youtubeScraperWith($fetcher)->fetchRecentVideos('UCX6OQ3DkcsbYNE6H8uQQuVA');
});

// ── fetchUploadsFeed() — LIFE-26 ────────────────────────────────────────────

// Regression: the feed was requested as ?playlist_id=UU<suffix> (the uploads
// playlist) until YouTube stopped serving that feed entirely — 404/500 for
// every channel — which surfaced to users as "Could not find that YouTube
// channel or its latest video." on a channel that resolved perfectly well.
// Pins the query parameter, since only the URL distinguishes the two feeds.
it('requests the channel feed, not the retired uploads-playlist feed', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')
        ->once()
        ->withArgs(fn (string $url) => $url === 'https://www.youtube.com/feeds/videos.xml?channel_id=UCabcdefghijklmnopqrstuv')
        ->andReturn(null);

    youtubeScraperWith($fetcher)->fetchUploadsFeed('UCabcdefghijklmnopqrstuv');
});

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

it('logs a discriminating reason when the uploads feed responds non-200 on every attempt', function () {
    Log::spy();
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    // M-13: an explicit non-200 gets FOUR re-requests before giving up
    // (measured live: ~every second identical request failed 404/500).
    $fetcher->shouldReceive('tryFetch')->times(5)->andReturn([
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

// M-13 (B7 live): the feed 404'd for a channel whose id had JUST been
// resolved from its live channel page — and the identical request served 200
// on the other test account a minute later. On the auto-route path a
// terminal miss here permanently drops the channel (F26 removes the row and
// nobody is watching a modal to retry), so a transient non-200 gets exactly
// one re-request. Transport-level null does NOT retry — SafeUrlFetcher's
// null covers SSRF/DNS/timeout, where an immediate second attempt is noise.
it('recovers the uploads feed when a transient 404 clears on the single retry', function () {
    Log::spy();
    $rss = '<feed><author><name>Flowers Vasette</name></author><title>Flowers Vasette</title>'
        .'<entry><yt:videoId>abc123def45</yt:videoId><title>Bouquet</title>'
        .'<media:description>d</media:description><published>2026-08-01T00:00:00+00:00</published></entry></feed>';
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->twice()->andReturn(
        ['status' => 404, 'body' => '', 'finalUrl' => 'https://www.youtube.com/feeds/videos.xml', 'contentType' => 'application/xml', 'etag' => null, 'lastModified' => null],
        ['status' => 200, 'body' => $rss, 'finalUrl' => 'https://www.youtube.com/feeds/videos.xml', 'contentType' => 'application/xml', 'etag' => null, 'lastModified' => null],
    );
    $thumbnails = Mockery::mock(YoutubeThumbnailResolver::class);
    $thumbnails->shouldReceive('bestForMany')->andReturn(['abc123def45' => 'https://i.ytimg.com/vi/abc123def45/hqdefault.jpg']);

    $result = (new YoutubeScraper($fetcher, $thumbnails))->fetchUploadsFeed('UCabcdefghijklmnopqrstuv');

    expect($result)->not->toBeNull();
    expect($result['title'])->toBe('Flowers Vasette');
    expect($result['videos'][0]['videoId'])->toBe('abc123def45');
    Log::shouldNotHaveReceived('warning');
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

// ── fetchChannelProfile() — the channel's own id AND avatar off one page fetch ──

it('reads the channel id and avatar off the channel page', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->once()->andReturn([
        'status' => 200,
        'body' => '{"externalId":"UCocwhL8eTz6tfV9_crX_nJQ","avatar":{"thumbnails":[{"url":"https://yt3.googleusercontent.com/abc=s88-c-k","width":88},{"url":"https://yt3.googleusercontent.com/abc=s900-c-k","width":900}]}}',
        'finalUrl' => 'https://www.youtube.com/@dvlpmnt',
        'contentType' => 'text/html',
    ]);

    $profile = youtubeScraperWith($fetcher)->fetchChannelProfile('dvlpmnt');

    expect($profile)->toBe([
        'id' => 'UCocwhL8eTz6tfV9_crX_nJQ',
        'avatar' => 'https://yt3.googleusercontent.com/abc=s900-c-k',
    ]);
});

it('returns a null avatar when the page has an id but no avatar block', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->once()->andReturn([
        'status' => 200,
        'body' => '{"externalId":"UCocwhL8eTz6tfV9_crX_nJQ"}',
        'finalUrl' => 'https://www.youtube.com/@dvlpmnt',
        'contentType' => 'text/html',
    ]);

    expect(youtubeScraperWith($fetcher)->fetchChannelProfile('dvlpmnt'))
        ->toBe(['id' => 'UCocwhL8eTz6tfV9_crX_nJQ', 'avatar' => null]);
});

it('returns null from fetchChannelProfile when the channel page 404s', function () {
    Log::spy();
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->once()->andReturn([
        'status' => 404, 'body' => '', 'finalUrl' => 'https://www.youtube.com/@nope', 'contentType' => 'text/html',
    ]);

    expect(youtubeScraperWith($fetcher)->fetchChannelProfile('nope'))->toBeNull();
    Log::shouldHaveReceived('warning')->once();
});
