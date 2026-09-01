<?php

use App\Ingest\Connectors\InstagramConnector;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Record;
use App\Ingest\Runtime\EffectRefused;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;
use App\Services\Cache\ScrapeCreatorsBudget;
use App\Services\Platforms\InstagramScraper;
use App\Services\Platforms\ScrapeCreators\InstagramHighlightsNormalizer;
use App\Services\Platforms\ScrapeCreators\InstagramReelsNormalizer;
use App\Services\Platforms\ScrapeCreators\InstagramTaggedPostsNormalizer;
use Illuminate\Support\Facades\Http;
use Tests\Support\Fixtures\Recorded;

// Item 11b (2026-09-01): Instagram depth — video history beyond the ≤12-post
// profile window, highlight covers, and tagged posts, blended into the media
// stream behind partna.limits.scrapecreators.instagram_depth_enabled. Pinned
// against RECORDED payloads (ryanfitzsimonshair captures, 2026-09-01). Three
// properties, every test serves one:
//
//  1. Depth rows arrive in the landed vocabulary the connector's own
//     mapPost() emits — the projector cannot tell a depth reel from a
//     window post — and third-party tagger identity never rides through.
//  2. Budget discipline on the shared 'instagram' source: claim before the
//     call, release on transport-null, keep the slot spent on a billed husk.
//  3. The blend is OFF by construction with the flag absent, honours the
//     videos-lead condition (reels only when the window has <5 playable
//     videos), dedupes against the window, and never widens Coverage.

function igDepthIo(array $profile): Io
{
    return new class(['status' => 'ok', 'cached' => false, 'data' => [$profile]]) implements Io
    {
        public function __construct(private array $effectResult) {}

        public function get(string $url, array $headers = []): array
        {
            throw new EffectRefused('instagram connector must not fetch over Io');
        }

        public function post(string $url, array $body = [], array $headers = []): array
        {
            throw new EffectRefused('instagram connector must not fetch over Io');
        }

        public function getMany(array $urls, array $headers = []): array
        {
            throw new EffectRefused('instagram connector must not fetch over Io');
        }

        public function effect(string $kind, string $name, array $input): array
        {
            return $this->effectResult;
        }
    };
}

function igDepthPull(array $config = []): Pull
{
    return new Pull(
        identifier: 'ryanfitzsimonshair',
        stream: InstagramConnector::manifest()->stream('media'),
        config: $config,
    );
}

/** An actor-shaped window: one image + one video that ALSO leads the recorded reels page. */
function igDepthProfile(array $overrides = []): array
{
    return array_replace([
        'username' => 'ryanfitzsimonshair',
        'id' => '38705054516',
        'latestPosts' => [
            [
                'shortCode' => 'Cwindow01',
                'type' => 'Image',
                'timestamp' => '2026-08-30T10:00:00Z',
                'display_url' => 'https://scontent.cdninstagram.com/v/win1.jpg?sig=a',
            ],
            [
                // The recorded reels page's newest reel, seen through the
                // window too — the dedupe must let the window copy win.
                'shortCode' => 'DQEIGH6k7w9',
                'type' => 'Video',
                'taken_at_timestamp' => 1761035177,
                'display_url' => 'https://scontent.cdninstagram.com/v/poster.jpg?sig=b',
                'video_url' => 'https://scontent.cdninstagram.com/v/clip.mp4?sig=c',
            ],
        ],
    ], $overrides);
}

function igDepthVendorFakes(): void
{
    Http::fake([
        'api.scrapecreators.com/v1/instagram/user/reels*' => Http::response(Recorded::json('scrapecreators-instagram-user-reels.json')),
        'api.scrapecreators.com/v1/instagram/user/highlights*' => Http::response(Recorded::json('scrapecreators-instagram-user-highlights.json')),
        'api.scrapecreators.com/v1/instagram/user/tagged-posts*' => Http::response(Recorded::json('scrapecreators-instagram-user-tagged-posts.json')),
    ]);
}

beforeEach(function () {
    config()->set('services.scrapecreators.key', 'test-key');
    config()->set('partna.limits.scrapecreators.global_daily_cap', 100);
    config()->set('partna.limits.scrapecreators.sources.instagram', 100);
    // The flag is deliberately NOT set here — each blend test states its own
    // stance, and the flag-absent test proves absence means OFF.
});

// ---------------------------------------------------------------- normalizers

it('normalizes the recorded reels page into landed-vocabulary video rows', function () {
    $rows = app(InstagramReelsNormalizer::class)->rows(Recorded::json('scrapecreators-instagram-user-reels.json'));

    expect($rows)->toHaveCount(6);

    $first = $rows[0];
    expect($first['shortcode'])->toBe('DQEIGH6k7w9')
        ->and($first['type'])->toBe('Video')
        ->and($first['url'])->toBe('https://www.instagram.com/reel/DQEIGH6k7w9/')
        // taken_at re-expressed on the stream's own clock (gmdate ISO), so
        // strcmp ordering against window rows compares one format.
        ->and($first['taken_at'])->toBe('2025-10-21T08:26:17Z')
        ->and($first['video_url'])->toContain('cdninstagram.com')
        ->and($first['display_url'])->toBeString()
        ->and($first['images'])->toBe([$first['display_url']])
        ->and($first['caption'])->toContain('average barbers');

    // Nothing beyond the landed vocabulary rides through.
    expect(array_diff(array_keys($first), ['shortcode', 'type', 'caption', 'taken_at', 'url', 'display_url', 'video_url', 'images']))->toBe([]);
});

it('normalizes the recorded highlights answer into dateless cover rows under a synthetic shortcode', function () {
    $rows = app(InstagramHighlightsNormalizer::class)->rows(Recorded::json('scrapecreators-instagram-user-highlights.json'));

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['shortcode'])->toBe('highlight-18069014636243848')
        ->and($rows[0]['type'])->toBe('Highlight')
        ->and($rows[0]['caption'])->toBe('Not haircuts')
        ->and($rows[0]['url'])->toBe('https://www.instagram.com/stories/highlights/18069014636243848/')
        ->and($rows[0]['display_url'])->toContain('cdninstagram.com')
        // Highlights carry no dates — a dateless row can never be dominated.
        ->and($rows[0])->not->toHaveKey('taken_at')
        ->and($rows[1]['caption'])->toBe('Haircuts');
});

it('normalizes the recorded tagged page into imagery rows that never carry the tagger identity', function () {
    $rows = app(InstagramTaggedPostsNormalizer::class)->rows(Recorded::json('scrapecreators-instagram-user-tagged-posts.json'));

    expect($rows)->toHaveCount(3);

    $first = $rows[0];
    expect($first['shortcode'])->toBe('Dcf6Fo5pODS')
        ->and($first['url'])->toBe('https://www.instagram.com/p/Dcf6Fo5pODS/')
        ->and($first['display_url'])->toContain('cdninstagram.com')
        ->and($first['caption'])->toContain('hair inspo')
        // The recorded media_type-2 post ships NO video_versions: tagged
        // videos land as poster imagery, so video-ness (f_playable) is
        // honestly absent.
        ->and($first['type'])->toBe('Video')
        ->and($first)->not->toHaveKey('video_url')
        ->and($first)->not->toHaveKey('taken_at');

    // A tagged carousel is ONE row carrying every readable child frame.
    $carousel = collect($rows)->firstWhere('type', 'Sidecar');
    expect($carousel)->not->toBeNull()
        ->and($carousel['images'])->toHaveCount(3);

    // The tagger (posts[].user — a third party) must not leak into any row.
    expect(json_encode($rows))->not->toContain('certifiedbarberboy');
});

it('reads every husk as a vendor miss, never as an empty surface', function () {
    $husk = ['success' => true, 'credits_charged' => 1];

    expect(app(InstagramReelsNormalizer::class)->rows($husk))->toBeNull()
        ->and(app(InstagramHighlightsNormalizer::class)->rows($husk))->toBeNull()
        ->and(app(InstagramTaggedPostsNormalizer::class)->rows($husk))->toBeNull();
});

// ------------------------------------------------------- scraper budget lane

it('fetches reels depth with the handle, spending one instagram slot', function () {
    config()->set('partna.limits.scrapecreators.sources.instagram', 1);
    igDepthVendorFakes();

    $rows = app(InstagramScraper::class)->fetchReelsDepth('ryanfitzsimonshair');

    expect($rows)->toHaveCount(6);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/instagram/user/reels')
        && $request['handle'] === 'ryanfitzsimonshair');
    // The billed answer keeps the slot spent.
    expect(app(ScrapeCreatorsBudget::class)->tryClaim('instagram'))->toBeFalse();
});

it('passes the numeric user id to the tagged endpoint', function () {
    igDepthVendorFakes();

    app(InstagramScraper::class)->fetchTaggedPosts('38705054516');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/instagram/user/tagged-posts')
        && $request['user_id'] === '38705054516');
});

it('returns null on a vendor 5xx, releasing the budget slot', function () {
    config()->set('partna.limits.scrapecreators.sources.instagram', 1);
    Http::fake(['api.scrapecreators.com/*' => Http::response('upstream sad', 502)]);

    expect(app(InstagramScraper::class)->fetchReelsDepth('ryanfitzsimonshair'))->toBeNull();
    expect(app(ScrapeCreatorsBudget::class)->tryClaim('instagram'))->toBeTrue();
});

it('returns null on a success-shaped husk and keeps the billed slot spent', function () {
    config()->set('partna.limits.scrapecreators.sources.instagram', 1);
    Http::fake(['api.scrapecreators.com/*' => Http::response(['success' => true, 'credits_charged' => 1])]);

    expect(app(InstagramScraper::class)->fetchHighlights('ryanfitzsimonshair'))->toBeNull();
    expect(app(ScrapeCreatorsBudget::class)->tryClaim('instagram'))->toBeFalse();
});

it('spends nothing with no key or no budget', function () {
    config()->set('services.scrapecreators.key', null);
    Http::fake();
    expect(app(InstagramScraper::class)->fetchReelsDepth('ryanfitzsimonshair'))->toBeNull();

    config()->set('services.scrapecreators.key', 'test-key');
    config()->set('partna.limits.scrapecreators.sources.instagram', 0);
    expect(app(InstagramScraper::class)->fetchReelsDepth('ryanfitzsimonshair'))->toBeNull();

    Http::assertNothingSent();
});

it('caps each depth surface at its configured limit', function () {
    config()->set('partna.limits.scrapecreators.instagram_depth.reels_limit', 2);
    igDepthVendorFakes();

    expect(app(InstagramScraper::class)->fetchReelsDepth('ryanfitzsimonshair'))->toHaveCount(2);
});

// --------------------------------------------------------- connector blend

it('blends nothing and calls nothing while the flag is ABSENT — the safe default', function () {
    igDepthVendorFakes();

    $messages = iterator_to_array((new InstagramConnector)->pull(igDepthPull(), igDepthIo(igDepthProfile())));
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));

    expect($records)->toHaveCount(2);
    Http::assertNothingSent();
});

it('fills a shallow video window from depth, deduped, without widening coverage', function () {
    config()->set('partna.limits.scrapecreators.instagram_depth_enabled', true);
    igDepthVendorFakes();

    $messages = iterator_to_array((new InstagramConnector)->pull(igDepthPull(), igDepthIo(igDepthProfile())));
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered))[0];

    // 2 window + 5 reels (6 recorded, minus the window dupe) + 2 highlights
    // + 3 tagged.
    expect($records)->toHaveCount(12);

    $keys = array_map(fn (Record $r) => $r->key, $records);
    // Window first, exactly once — the window copy of the shared reel wins.
    expect(array_slice($keys, 0, 2))->toBe(['Cwindow01', 'DQEIGH6k7w9'])
        ->and(array_count_values($keys)['DQEIGH6k7w9'])->toBe(1)
        ->and($keys)->toContain('DKbz5BVvKx3')
        ->and($keys)->toContain('highlight-18069014636243848')
        ->and($keys)->toContain('Dcf6Fo5pODS');

    // A blended reel is a full landed row: playable, dated, linkable.
    $reel = collect($records)->firstWhere('key', 'DKbz5BVvKx3');
    expect($reel->doc['video_url'])->toBeString()
        ->and($reel->doc['taken_at'])->toBe('2025-06-03T10:03:00Z');

    // Coverage claims the WINDOW alone — depth rows land below the prefix,
    // so a later flagless or budget-denied run can never tombstone them.
    expect($covered->coverage->toArray()['type'])->toBe('prefix')
        ->and($covered->coverage->toArray()['count'])->toBe(2);
});

it('skips the reels call when the window already serves five playable videos', function () {
    config()->set('partna.limits.scrapecreators.instagram_depth_enabled', true);
    igDepthVendorFakes();

    $posts = [];
    foreach (range(1, 5) as $i) {
        $posts[] = [
            'shortCode' => "Cvid{$i}",
            'type' => 'Video',
            'taken_at_timestamp' => 1761035177 + $i,
            'display_url' => "https://scontent.cdninstagram.com/v/p{$i}.jpg",
            'video_url' => "https://scontent.cdninstagram.com/v/v{$i}.mp4",
        ];
    }

    iterator_to_array((new InstagramConnector)->pull(igDepthPull(), igDepthIo(igDepthProfile(['latestPosts' => $posts]))));

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/instagram/user/reels'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/instagram/user/highlights'));
});

it('skips the tagged call when the profile carries no numeric user id', function () {
    config()->set('partna.limits.scrapecreators.instagram_depth_enabled', true);
    igDepthVendorFakes();

    iterator_to_array((new InstagramConnector)->pull(igDepthPull(), igDepthIo(igDepthProfile(['id' => null]))));

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/instagram/user/tagged-posts'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/instagram/user/highlights'));
});

it('spends no credit when a latest_n scope is already filled by the window', function () {
    config()->set('partna.limits.scrapecreators.instagram_depth_enabled', true);
    igDepthVendorFakes();

    $pull = igDepthPull(['scope' => 'latest_n', 'scope_n' => 2]);
    $messages = iterator_to_array((new InstagramConnector)->pull($pull, igDepthIo(igDepthProfile())));
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));

    expect($records)->toHaveCount(2);
    Http::assertNothingSent();
});
