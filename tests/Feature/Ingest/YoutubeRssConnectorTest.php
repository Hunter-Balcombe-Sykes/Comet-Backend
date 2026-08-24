<?php

use App\Ingest\Connectors\YoutubeRssConnector;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Message\Bookmark;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Runtime\EffectRefused;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

// Tier C: the connector's real pull() against recorded responses. No network,
// no DB — a connector is a pure function from (Pull, Io) to Messages.

/** A minimal Io that answers from a fixed url => response map. */
function youtubeIo(array $responses): Io
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

function youtubePull(string $channelId = 'UCinvented00000000000000'): Pull
{
    return new Pull(
        identifier: $channelId,
        stream: YoutubeRssConnector::manifest()->stream('watch'),
    );
}

function youtubeFeedUrl(string $channelId): string
{
    return 'https://www.youtube.com/feeds/videos.xml?'.http_build_query(['channel_id' => $channelId]);
}

/** @param  list<array{id:string,title:string,published:string}>  $entries */
function youtubeFeedXml(array $entries, string $channelId = 'UCinvented00000000000000'): string
{
    $entryXml = '';
    foreach ($entries as $entry) {
        $entryXml .= '<entry>'
            .'<id>yt:video:'.$entry['id'].'</id>'
            .'<yt:videoId>'.$entry['id'].'</yt:videoId>'
            .'<yt:channelId>'.$channelId.'</yt:channelId>'
            .'<title>'.htmlspecialchars($entry['title'], ENT_XML1).'</title>'
            .'<link rel="alternate" href="https://www.youtube.com/watch?v='.$entry['id'].'"/>'
            .'<author><name>Invented Channel</name><uri>https://www.youtube.com/channel/'.$channelId.'</uri></author>'
            .'<published>'.$entry['published'].'</published>'
            .'<updated>'.$entry['published'].'</updated>'
            .'<media:group>'
            .'<media:title>'.htmlspecialchars($entry['title'], ENT_XML1).'</media:title>'
            .'<media:thumbnail url="https://i.ytimg.com/vi/'.$entry['id'].'/hqdefault.jpg" width="480" height="360"/>'
            .'<media:description>An invented description</media:description>'
            .'</media:group>'
            .'</entry>';
    }

    return '<?xml version="1.0" encoding="UTF-8"?>'
        .'<feed xmlns:yt="http://www.youtube.com/xml/schemas/2015" xmlns:media="http://search.yahoo.com/mrss/" xmlns="http://www.w3.org/2005/Atom">'
        .'<link rel="self" href="'.youtubeFeedUrl($channelId).'"/>'
        .'<id>yt:channel:'.$channelId.'</id>'
        .'<yt:channelId>'.$channelId.'</yt:channelId>'
        .'<title>Invented Channel</title>'
        .'<link rel="alternate" href="https://www.youtube.com/channel/'.$channelId.'"/>'
        .'<author><name>Invented Channel</name><uri>https://www.youtube.com/channel/'.$channelId.'</uri></author>'
        .'<published>2020-01-01T00:00:00+00:00</published>'
        .$entryXml
        .'</feed>';
}

it('declares only the hosts it actually needs', function () {
    $manifest = YoutubeRssConnector::manifest();

    expect($manifest->mayContact('www.youtube.com'))->toBeTrue()
        ->and($manifest->mayContact('youtube.com'))->toBeTrue()
        ->and($manifest->mayContact('i.ytimg.com'))->toBeTrue()
        ->and($manifest->mayContact('evil.com'))->toBeFalse()
        ->and($manifest->mayContact('youtube.com.evil.com'))->toBeFalse();
});

it('yields a record per entry plus a prefix coverage claim, never exhaustive', function () {
    $channelId = 'UCinvented00000000000000';
    $io = youtubeIo([youtubeFeedUrl($channelId) => [
        'status' => 200,
        'body' => youtubeFeedXml([
            ['id' => 'videoOne', 'title' => 'First Video', 'published' => '2025-01-01T00:00:00+00:00'],
            ['id' => 'videoTwo', 'title' => 'Second Video', 'published' => '2025-02-02T00:00:00+00:00'],
        ], $channelId),
        'headers' => [],
    ]]);

    $messages = iterator_to_array(app(YoutubeRssConnector::class)->pull(youtubePull($channelId), $io));

    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered));

    expect($records)->toHaveCount(2)
        ->and($records[0]->key)->toBe('videoOne')
        ->and($records[0]->doc['title'])->toBe('First Video')
        ->and($records[0]->doc['url'])->toBe('https://www.youtube.com/watch?v=videoOne')
        ->and($records[1]->doc['thumbnail'])->toBe('https://i.ytimg.com/vi/videoTwo/hqdefault.jpg')
        // The feed caps at the latest 15 — the honest claim is only ever a
        // prefix down to the oldest entry actually seen, never exhaustive.
        ->and($covered)->toHaveCount(1)
        ->and($covered[0]->coverage->toArray()['type'])->toBe('prefix')
        ->and($covered[0]->coverage->toArray()['from'])->toBe('2025-01-01T00:00:00+00:00');
});

it('reports a failed fetch as unavailable rather than as a channel with no uploads', function () {
    $channelId = 'UCinvented00000000000000';
    $io = youtubeIo([youtubeFeedUrl($channelId) => ['status' => 503, 'body' => '', 'headers' => []]]);

    $messages = iterator_to_array(app(YoutubeRssConnector::class)->pull(youtubePull($channelId), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Unavailable::class)
        ->and($messages[0]->status)->toBe(503);
});

it('reports unparseable xml as unavailable, distinct from a genuinely empty feed', function () {
    $channelId = 'UCinvented00000000000000';
    $io = youtubeIo([youtubeFeedUrl($channelId) => [
        'status' => 200,
        'body' => '<not-xml this is not well formed <<<',
        'headers' => [],
    ]]);

    $messages = iterator_to_array(app(YoutubeRssConnector::class)->pull(youtubePull($channelId), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Unavailable::class);
});

it('emits no coverage for a well-formed but empty feed, so absence can never delete', function () {
    $channelId = 'UCinvented00000000000000';
    $io = youtubeIo([youtubeFeedUrl($channelId) => [
        'status' => 200,
        'body' => youtubeFeedXml([], $channelId),
        'headers' => [],
    ]]);

    $messages = iterator_to_array(app(YoutubeRssConnector::class)->pull(youtubePull($channelId), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Note::class)
        ->and(array_filter($messages, fn ($m) => $m instanceof Covered))->toBeEmpty();
});

it('refuses to fetch a host outside its own manifest', function () {
    // The channel_id rides as a query value, not as the request host, so a
    // hostile identifier alone can't redirect the fetch — the guard is
    // exercised directly at the Io/manifest boundary, as it would be for any
    // caller that somehow assembled an off-manifest URL.
    $io = youtubeIo([]);

    expect(fn () => $io->get('https://evil.com/feeds/videos.xml?channel_id=whatever'))
        ->toThrow(EffectRefused::class);
});

it('uses a Feed profile with an order field, so its absences can mean deletion', function () {
    $spec = YoutubeRssConnector::manifest()->stream('watch');

    expect($spec->profile)->toBe(SourceProfile::Feed)
        ->and($spec->orderField)->toBe('published')
        ->and($spec->mayDelete())->toBeTrue();
});

// ── Handle resolution (legacy connections store only the handle) ────────────

it('resolves a bare handle to the channel id via the channel page and caches it in a bookmark', function () {
    $channelId = 'UCresolved0000000000000A';
    $io = youtubeIo([
        'https://www.youtube.com/@mkbhd' => [
            'status' => 200,
            'body' => '<html><script>var x = {"channelId":"'.$channelId.'","junk":1};</script></html>',
            'headers' => [],
        ],
        youtubeFeedUrl($channelId) => [
            'status' => 200,
            'body' => youtubeFeedXml([
                ['id' => 'clipOne', 'title' => 'Resolved Video', 'published' => '2025-03-03T00:00:00+00:00'],
            ], $channelId),
            'headers' => [],
        ],
    ]);

    $messages = iterator_to_array(app(YoutubeRssConnector::class)->pull(youtubePull('mkbhd'), $io));

    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $bookmarks = array_values(array_filter($messages, fn ($m) => $m instanceof Bookmark));

    expect($records)->toHaveCount(1)
        ->and($records[0]->doc['title'])->toBe('Resolved Video')
        ->and($bookmarks)->toHaveCount(1)
        ->and($bookmarks[0]->cursor)->toBe(['channel_id' => $channelId]);
});

it('resolves the handle to the page\'s OWN channel (externalId), not the first related channelId in the markup (F3)', function () {
    // Overnight 2026-08-18 F3: real channel pages list featured/related
    // channels' "channelId" BEFORE the page's own; the connector took the
    // first match and pulled a stranger's uploads. externalId is the page's
    // own id and must win.
    $related = 'UCrelated00000000000000R';
    $own = 'UCown0000000000000000000';
    $io = youtubeIo([
        'https://www.youtube.com/@mkbhd' => [
            'status' => 200,
            'body' => '<html><script>{"channelId":"'.$related.'"}…{"externalId":"'.$own.'","channelId":"'.$own.'"}</script></html>',
            'headers' => [],
        ],
        youtubeFeedUrl($own) => [
            'status' => 200,
            'body' => youtubeFeedXml([
                ['id' => 'ownVid', 'title' => 'Own Video', 'published' => '2025-03-03T00:00:00+00:00'],
            ], $own),
            'headers' => [],
        ],
    ]);

    $messages = iterator_to_array(app(YoutubeRssConnector::class)->pull(youtubePull('mkbhd'), $io));
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $bookmarks = array_values(array_filter($messages, fn ($m) => $m instanceof Bookmark));

    expect($records)->toHaveCount(1)
        ->and($records[0]->doc['title'])->toBe('Own Video')
        ->and($bookmarks[0]->cursor)->toBe(['channel_id' => $own]);
});

it('reuses a cursor-cached channel id without re-fetching the channel page', function () {
    $channelId = 'UCcached000000000000000B';
    // No @handle page in the map: touching it would 404 and the run would
    // degrade — passing proves the cursor short-circuited the resolution.
    $io = youtubeIo([
        youtubeFeedUrl($channelId) => [
            'status' => 200,
            'body' => youtubeFeedXml([
                ['id' => 'cachedClip', 'title' => 'Cached', 'published' => '2025-04-04T00:00:00+00:00'],
            ], $channelId),
            'headers' => [],
        ],
    ]);

    $pull = new Pull(
        identifier: 'mkbhd',
        stream: YoutubeRssConnector::manifest()->stream('watch'),
        cursor: ['channel_id' => $channelId],
    );

    $messages = iterator_to_array(app(YoutubeRssConnector::class)->pull($pull, $io));

    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $bookmarks = array_values(array_filter($messages, fn ($m) => $m instanceof Bookmark));

    expect($records)->toHaveCount(1)
        // Nothing was freshly resolved, so no bookmark churn.
        ->and($bookmarks)->toBeEmpty();
});

it('degrades to unavailable when a handle cannot be resolved, never to an empty feed', function () {
    $io = youtubeIo([
        'https://www.youtube.com/@ghost' => ['status' => 200, 'body' => '<html>no id here</html>', 'headers' => []],
    ]);

    $messages = iterator_to_array(app(YoutubeRssConnector::class)->pull(youtubePull('ghost'), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Unavailable::class);
});
