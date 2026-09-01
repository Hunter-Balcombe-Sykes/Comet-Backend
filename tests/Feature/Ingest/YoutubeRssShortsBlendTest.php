<?php

use App\Ingest\Connectors\YoutubeRssConnector;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Record;
use App\Ingest\Runtime\EffectRefused;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;
use App\Services\Cache\ScrapeCreatorsBudget;
use Illuminate\Support\Facades\Http;
use Tests\Support\Fixtures\Recorded;

// Item 11c (2026-09-01): shorts join youtube's existing `watch` stream. The
// RSS lane is the backbone and must be untouchable — every vendor failure mode
// leaves it byte-for-byte as it was — while a vendor answer blends the Shorts
// shelf in as more feed-shaped rows (videoId→id, deduped against the feed,
// coverage never extended past what the feed itself saw). Pinned against the
// RECORDED payload (scrapecreators-youtube-channel-shorts.json, MrBeast,
// 2026-09-01 capture): 5 shorts, newest 2026-08-23, oldest 2026-07-10.
//
// The RSS leg rides the connector's Io (stubbed per test); fetchShorts() and
// the thumbnail probe ride the Http facade, so both are faked there. Budget
// discipline itself is fetchShorts()'s contract (its own test file) — here we
// prove only that the CONNECTOR spends and blends, or skips and survives.

const YT_BLEND_CHANNEL = 'UCX6OQ3DkcsbYNE6H8uQQuVA';

/** A minimal Io answering the RSS leg from a fixed url => response map. */
function ytBlendIo(array $responses): Io
{
    return new class($responses) implements Io
    {
        public function __construct(private array $responses) {}

        public function get(string $url, array $headers = []): array
        {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (! YoutubeRssConnector::manifest()->mayContact($host)) {
                throw new EffectRefused("off-manifest host {$host}");
            }

            return $this->responses[$url] ?? ['status' => 404, 'body' => '', 'headers' => []];
        }

        public function post(string $url, array $body = [], array $headers = []): array
        {
            return ['status' => 405, 'body' => '', 'headers' => []];
        }

        public function getMany(array $urls, array $headers = []): array
        {
            return array_map(fn ($u) => $this->get($u), array_combine($urls, $urls));
        }

        public function effect(string $kind, string $name, array $input): array
        {
            return ['status' => 'ok', 'cached' => false, 'data' => null];
        }
    };
}

function ytBlendPull(array $config = []): Pull
{
    return new Pull(
        identifier: YT_BLEND_CHANNEL,
        stream: YoutubeRssConnector::manifest()->stream('watch'),
        config: $config,
    );
}

function ytBlendFeedUrl(): string
{
    return 'https://www.youtube.com/feeds/videos.xml?'.http_build_query(['channel_id' => YT_BLEND_CHANNEL]);
}

/** @param list<array{id: string, title: string, published: string}> $entries */
function ytBlendFeedXml(array $entries): string
{
    $entryXml = '';
    foreach ($entries as $entry) {
        $entryXml .= '<entry>'
            .'<id>yt:video:'.$entry['id'].'</id>'
            .'<yt:videoId>'.$entry['id'].'</yt:videoId>'
            .'<title>'.htmlspecialchars($entry['title'], ENT_XML1).'</title>'
            .'<link rel="alternate" href="https://www.youtube.com/watch?v='.$entry['id'].'"/>'
            .'<author><name>Invented Channel</name></author>'
            .'<published>'.$entry['published'].'</published>'
            .'</entry>';
    }

    return '<?xml version="1.0" encoding="UTF-8"?>'
        .'<feed xmlns:yt="http://www.youtube.com/xml/schemas/2015" xmlns="http://www.w3.org/2005/Atom">'
        .'<yt:channelId>'.YT_BLEND_CHANNEL.'</yt:channelId>'
        .'<title>Invented Channel</title>'
        .$entryXml
        .'</feed>';
}

/**
 * The standing two-entry feed: one plain upload plus the newest recorded
 * short — the feed carries its OWN copy of a short, which is exactly the
 * duplicate the blend must fold away.
 */
function ytBlendFeedResponses(): array
{
    return [ytBlendFeedUrl() => [
        'status' => 200,
        'body' => ytBlendFeedXml([
            ['id' => 'regularVid1', 'title' => 'A Regular Upload', 'published' => '2026-08-25T00:00:00+00:00'],
            ['id' => '5mU6SRS2Bxo', 'title' => 'World’s Largest Tennis Match', 'published' => '2026-08-23T16:00:04+00:00'],
        ]),
        'headers' => [],
    ]];
}

beforeEach(function () {
    config()->set('services.scrapecreators.key', 'test-key');
    config()->set('partna.limits.scrapecreators.global_daily_cap', 100);
    config()->set('partna.limits.scrapecreators.sources.youtube_shorts', 100);
});

it('blends deduped shorts after the feed rows, re-spoken in the feed vocabulary', function () {
    Http::fake([
        'api.scrapecreators.com/*' => Http::response(Recorded::json('scrapecreators-youtube-channel-shorts.json')),
        'i.ytimg.com/*' => Http::response('', 404),
    ]);

    $messages = iterator_to_array(app(YoutubeRssConnector::class)->pull(ytBlendPull(), ytBlendIo(ytBlendFeedResponses())));
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));

    // 2 feed rows + 4 shorts: the recorded 5mU6SRS2Bxo is already in the feed
    // and the FEED copy wins (probed thumbnail, feed-format date).
    expect($records)->toHaveCount(6)
        ->and(array_map(fn (Record $r) => $r->key, $records))
        ->toBe(['regularVid1', '5mU6SRS2Bxo', 'LiH-P4rSkLI', 'f7y2XikE7sY', 'Df5Y-2ndQyU', 'egvLKQe6I4I']);

    $short = $records[2]->doc;
    expect($short['id'])->toBe('LiH-P4rSkLI')
        ->and($short['title'])->toBe('Can You Pass This Classroom Quiz?')
        ->and($short['url'])->toBe('https://www.youtube.com/watch?v=LiH-P4rSkLI')
        // The vendor's '2026-08-11T09:00:06-07:00' re-expressed on the feed's
        // clock — one stream, one date format, or strcmp domination lies.
        ->and($short['published'])->toBe('2026-08-11T16:00:06+00:00')
        ->and($short['thumbnail'])->toBe('https://img.youtube.com/vi/LiH-P4rSkLI/maxresdefault.jpg')
        // Borrowed from the feed page — the shelf rows carry no author.
        ->and($short['channel_title'])->toBe('Invented Channel');

    // The feed's six keys and NOTHING else — engagement counts and credits_*
    // died in the normalizer, and the blend adds no vocabulary of its own.
    expect(array_keys($short))->toBe(['id', 'title', 'url', 'published', 'thumbnail', 'channel_title']);

    // The resolved UC… id rides the vendor call — never the raw identifier.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/youtube/channel/shorts')
        && $request['channelId'] === YT_BLEND_CHANNEL);
});

it('keeps the coverage claim at the feed boundary, never extended by older shorts', function () {
    Http::fake([
        'api.scrapecreators.com/*' => Http::response(Recorded::json('scrapecreators-youtube-channel-shorts.json')),
        'i.ytimg.com/*' => Http::response('', 404),
    ]);

    $messages = iterator_to_array(app(YoutubeRssConnector::class)->pull(ytBlendPull(), ytBlendIo(ytBlendFeedResponses())));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered));

    // Blended shorts reach back to 2026-07-10, but the shelf saw no regular
    // uploads in that gap — a `from` below the feed's own oldest entry would
    // let absence delete live videos (C5).
    expect($covered)->toHaveCount(1)
        ->and($covered[0]->coverage->toArray())
        ->toBe(['type' => 'prefix', 'from' => '2026-08-23T16:00:04+00:00', 'count' => 2]);
});

it('leaves the RSS lane untouched on a vendor 5xx, with the budget slot released', function () {
    config()->set('partna.limits.scrapecreators.sources.youtube_shorts', 1);
    Http::fake([
        'api.scrapecreators.com/*' => Http::response('upstream sad', 502),
        'i.ytimg.com/*' => Http::response('', 404),
    ]);

    $messages = iterator_to_array(app(YoutubeRssConnector::class)->pull(ytBlendPull(), ytBlendIo(ytBlendFeedResponses())));
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered));

    expect($records)->toHaveCount(2)
        ->and($records[0]->key)->toBe('regularVid1')
        ->and($covered)->toHaveCount(1);
    // Transport-null released the day's only slot (fetchShorts's contract).
    expect(app(ScrapeCreatorsBudget::class)->tryClaim('youtube_shorts'))->toBeTrue();
});

it('blends nothing from a billed husk and keeps the slot spent', function () {
    config()->set('partna.limits.scrapecreators.sources.youtube_shorts', 1);
    Http::fake([
        // The NotFound quirk: success:true, a credit charged, nothing usable.
        'api.scrapecreators.com/*' => Http::response([
            'success' => true, 'credits_remaining' => 9999, 'credits_charged' => 1,
            'videos' => [], 'channels' => [], 'playlists' => [], 'shorts' => [], 'shelves' => [], 'lives' => [],
        ]),
        'i.ytimg.com/*' => Http::response('', 404),
    ]);

    $messages = iterator_to_array(app(YoutubeRssConnector::class)->pull(ytBlendPull(), ytBlendIo(ytBlendFeedResponses())));

    expect(array_filter($messages, fn ($m) => $m instanceof Record))->toHaveCount(2);
    expect(app(ScrapeCreatorsBudget::class)->tryClaim('youtube_shorts'))->toBeFalse();
});

it('never touches the vendor without a key, and the RSS lane stands alone', function () {
    config()->set('services.scrapecreators.key', null);
    Http::fake(['i.ytimg.com/*' => Http::response('', 404)]);

    $messages = iterator_to_array(app(YoutubeRssConnector::class)->pull(ytBlendPull(), ytBlendIo(ytBlendFeedResponses())));

    expect(array_filter($messages, fn ($m) => $m instanceof Record))->toHaveCount(2);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.scrapecreators.com'));
});

it('skips the vendor call outright when the feed alone fills a latest_n scope', function () {
    config()->set('partna.limits.scrapecreators.sources.youtube_shorts', 1);
    Http::fake(['i.ytimg.com/*' => Http::response('', 404)]);

    $pull = ytBlendPull(['scope' => 'latest_n', 'scope_n' => 2]);
    $messages = iterator_to_array(app(YoutubeRssConnector::class)->pull($pull, ytBlendIo(ytBlendFeedResponses())));

    expect(array_filter($messages, fn ($m) => $m instanceof Record))->toHaveCount(2);
    // No credit spent on rows the scope would have discarded anyway.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.scrapecreators.com'));
    expect(app(ScrapeCreatorsBudget::class)->tryClaim('youtube_shorts'))->toBeTrue();
});

it('fills only the remainder of a latest_n scope with the newest unseen shorts', function () {
    Http::fake([
        'api.scrapecreators.com/*' => Http::response(Recorded::json('scrapecreators-youtube-channel-shorts.json')),
        'i.ytimg.com/*' => Http::response('', 404),
    ]);

    $pull = ytBlendPull(['scope' => 'latest_n', 'scope_n' => 3]);
    $messages = iterator_to_array(app(YoutubeRssConnector::class)->pull($pull, ytBlendIo(ytBlendFeedResponses())));
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));

    // Two feed rows leave room for exactly one short — the newest that the
    // feed did not already carry (5mU6SRS2Bxo deduped away).
    expect(array_map(fn (Record $r) => $r->key, $records))
        ->toBe(['regularVid1', '5mU6SRS2Bxo', 'LiH-P4rSkLI']);
});
