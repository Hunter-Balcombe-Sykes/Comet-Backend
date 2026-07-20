<?php

use App\Services\Platforms\ConnectResolver;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Strategies\Connect\YoutubeConnect;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// Unit 11 W1 (post-review) — the end-to-end regression the synthetic
// ConnectResolverTest.php strategies could not catch: driving ConnectResolver
// through the REAL YoutubeConnect → YoutubeScraper → YoutubeThumbnailResolver
// chain proves the shared FetchBudget reaches all three legs of a YouTube
// connect (channel page, RSS feed, AND the i.ytimg.com thumbnail-probe pool),
// not just the two that happen to route through SafeUrlFetcher.
//
// A 24-char channel id (UC + 22 chars) matches YoutubeScraper's
// "externalId":"UC…" extraction regex.
const YT_CHANNEL_ID = 'UCabcdefghijklmnopqrstuv';

function ytChannelPageBody(): string
{
    return '<html><script>var x={"externalId":"'.YT_CHANNEL_ID.'"};</script></html>';
}

function ytRssFeedBody(array $videoIds): string
{
    $entries = '';
    foreach ($videoIds as $i => $id) {
        $entries .= '<entry>'
            .'<yt:videoId>'.$id.'</yt:videoId>'
            .'<title>Video '.$i.'</title>'
            .'<published>2026-0'.($i + 1).'-01T00:00:00+00:00</published>'
            .'</entry>';
    }

    return '<feed><author><name>Test Channel</name></author>'.$entries.'</feed>';
}

it('lets the wall-clock budget reach the thumbnail-probe pool, not just the channel-page/RSS fetches', function () {
    // Against the FIRST cut of this unit's fix (budget wired into
    // SafeUrlFetcher only), YoutubeThumbnailResolver::pooledHead() had no
    // FetchBudget dependency at all and used a raw, unbounded Http::pool() —
    // so all 3 thumbnail probes below would fire regardless of the budget,
    // and the assertion on i.ytimg.com request count would fail (3, not 1).
    config()->set('partna.http_fetch.connect_budget_seconds', 0.1);

    // Force one video id per pooledHead() round, so the budget can expire
    // strictly BETWEEN rounds rather than needing to beat one big batch.
    config()->set('partna.refresh.host_limits.youtube_thumbnails.pool_concurrency', 1);

    $videoIds = ['vid1', 'vid2', 'vid3'];

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'i.ytimg.com')) {
            // Slower than the 100ms budget above — only the FIRST probe (the
            // only one that fires before round 2's budget check trips) pays
            // this cost; rounds 2 and 3 must never be sent at all.
            usleep(150_000);

            return Http::response('', 200);
        }

        if (str_contains($url, '/feeds/videos.xml')) {
            return Http::response(ytRssFeedBody(['vid1', 'vid2', 'vid3']), 200);
        }

        if (str_contains($url, 'youtube.com/@')) {
            return Http::response(ytChannelPageBody(), 200);
        }

        return Http::response('', 404);
    });

    $descriptor = PlatformDescriptor::make('youtube')->label('YouTube')
        ->connect(app(YoutubeConnect::class), 'Enter your YouTube channel.');

    // Unit 11 W6 — resolve() now returns a ConnectOutcome; this descriptor is
    // built fresh here (not fetched off the registry) and never calls
    // ->deferredConnect(), so it always takes the resolve() (sync) branch
    // regardless of config('partna.connect.deferred').
    $outcome = app(ConnectResolver::class)->resolve($descriptor, 'somehandle');
    expect($outcome->deferred)->toBeFalse();
    $result = $outcome->result;

    // The connect still succeeds — budget exhaustion degrades the SKIPPED
    // videos' thumbnails to hqdefault (YoutubeThumbnailResolver's existing
    // "never throws, a failed probe is a fallback" contract), it doesn't fail
    // the whole connect.
    expect($result->failed())->toBeFalse();

    // The channel page + RSS feed both went out (2 requests) — proving the
    // budget didn't kill the connect before it reached the thumbnail phase —
    // and exactly ONE of the three thumbnail probes fired, proving the
    // budget cut the pooledHead() loop short on round 2.
    expect(Http::recorded(fn ($request) => str_contains($request->url(), 'i.ytimg.com'))->count())->toBe(1);
    Http::assertSentCount(3); // channel page + RSS feed + 1 thumbnail probe
});
