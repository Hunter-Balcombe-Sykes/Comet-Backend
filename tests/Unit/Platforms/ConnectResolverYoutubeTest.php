<?php

use App\Services\Http\FetchBudget;
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

/**
 * FetchBudget on a clock the test drives, so nothing here depends on how long
 * real work takes.
 *
 * The previous version of this test set a 100ms budget and slept 150ms inside
 * the Http::fake. That asserted three real fetches finish within 100ms of REAL
 * time — true on a quiet laptop, false on a loaded CI runner, where the budget
 * expires before the thumbnail phase even starts and ZERO probes fire instead
 * of one. Advancing the clock explicitly keeps the property under test (the
 * budget reaches the thumbnail pool) and drops the property that was never
 * intended (the machine is fast).
 */
function ytFakeBudget(): FetchBudget
{
    return new class extends FetchBudget
    {
        public float $clock = 0.0;

        public function advance(float $seconds): void
        {
            $this->clock += $seconds;
        }

        protected function nowSeconds(): float
        {
            return $this->clock;
        }
    };
}

it('lets the budget reach the thumbnail-probe pool, not just the channel-page/RSS fetches', function () {
    // Against the FIRST cut of this unit's fix (budget wired into
    // SafeUrlFetcher only), YoutubeThumbnailResolver::pooledHead() had no
    // FetchBudget dependency at all and used a raw, unbounded Http::pool() —
    // so all 3 thumbnail probes below would fire regardless of the budget,
    // and the assertion on i.ytimg.com request count would fail (3, not 1).
    config()->set('partna.http_fetch.connect_budget_seconds', 0.1);

    // Force one video id per pooledHead() round, so the budget can expire
    // strictly BETWEEN rounds rather than needing to beat one big batch.
    config()->set('partna.refresh.host_limits.youtube_thumbnails.pool_concurrency', 1);

    // Shared instance, exactly as the scoped container binding provides — the
    // whole point of the fix is that ConnectResolver and
    // YoutubeThumbnailResolver see the SAME budget.
    $budget = ytFakeBudget();
    app()->instance(FetchBudget::class, $budget);

    Http::fake(function ($request) use ($budget) {
        $url = $request->url();

        if (str_contains($url, 'i.ytimg.com')) {
            // The first probe "costs" more than the whole budget. Rounds 2 and
            // 3 must therefore never be sent. Deterministic: the clock only
            // moves because this line moves it.
            $budget->advance(0.15);

            return Http::response('', 200);
        }

        if (str_contains($url, '/feeds/videos.xml')) {
            return Http::response(ytRssFeedBody(['vid1XXXXXXX', 'vid2XXXXXXX', 'vid3XXXXXXX']), 200);
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

it('fires every thumbnail probe when the budget is never exhausted', function () {
    // The control the old test could not safely have: with the clock frozen,
    // "one probe" above is provably the budget cutting the loop short, not the
    // pool quietly stopping for some other reason. Same wiring, clock never
    // advanced, all three probes go out.
    config()->set('partna.http_fetch.connect_budget_seconds', 0.1);
    config()->set('partna.refresh.host_limits.youtube_thumbnails.pool_concurrency', 1);

    app()->instance(FetchBudget::class, ytFakeBudget());

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'i.ytimg.com')) {
            return Http::response('', 200);
        }
        if (str_contains($url, '/feeds/videos.xml')) {
            return Http::response(ytRssFeedBody(['vid1XXXXXXX', 'vid2XXXXXXX', 'vid3XXXXXXX']), 200);
        }
        if (str_contains($url, 'youtube.com/@')) {
            return Http::response(ytChannelPageBody(), 200);
        }

        return Http::response('', 404);
    });

    $descriptor = PlatformDescriptor::make('youtube')->label('YouTube')
        ->connect(app(YoutubeConnect::class), 'Enter your YouTube channel.');

    $result = app(ConnectResolver::class)->resolve($descriptor, 'somehandle')->result;

    expect($result->failed())->toBeFalse()
        ->and(Http::recorded(fn ($request) => str_contains($request->url(), 'i.ytimg.com'))->count())->toBe(3);
});
