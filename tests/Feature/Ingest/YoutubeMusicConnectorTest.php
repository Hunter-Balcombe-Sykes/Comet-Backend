<?php

use App\Ingest\Connectors\YoutubeMusicConnector;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Runtime\EffectRefused;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

// Tier C: the connector's real pull() against recorded responses.

/** A minimal Io that answers from a fixed url => response map. */
function youtubeMusicIo(array $responses): Io
{
    return new class($responses) implements Io
    {
        public function __construct(private array $responses) {}

        public function get(string $url, array $headers = []): array
        {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (! YoutubeMusicConnector::manifest()->mayContact($host)) {
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

const YTM_CONNECTOR_CHANNEL = 'UCabcdefghijklmnopqrstuv';

function youtubeMusicPull(array $config = []): Pull
{
    return new Pull(
        identifier: YTM_CONNECTOR_CHANNEL,
        stream: YoutubeMusicConnector::manifest()->stream('releases'),
        config: $config,
    );
}

function youtubeMusicFeedUrl(): string
{
    return 'https://www.youtube.com/feeds/videos.xml?channel_id='.YTM_CONNECTOR_CHANNEL;
}

/** @param list<array{id: string, title: string, published: string}> $entries */
function youtubeMusicFeedXml(array $entries, string $author = 'Some Artist - Topic'): string
{
    $items = '';
    foreach ($entries as $entry) {
        $items .= <<<XML
        <entry>
            <yt:videoId>{$entry['id']}</yt:videoId>
            <title>{$entry['title']}</title>
            <link rel="alternate" href="https://www.youtube.com/watch?v={$entry['id']}"/>
            <author><name>{$author}</name></author>
            <published>{$entry['published']}</published>
            <media:group>
                <media:thumbnail url="https://i4.ytimg.com/vi/{$entry['id']}/hqdefault.jpg" width="480" height="360"/>
            </media:group>
        </entry>
        XML;
    }

    return '<?xml version="1.0" encoding="UTF-8"?>'
        .'<feed xmlns:yt="http://www.youtube.com/xml/schemas/2015" xmlns:media="http://search.yahoo.com/mrss/" xmlns="http://www.w3.org/2005/Atom">'
        .'<title>'.$author.'</title>'.$items.'</feed>';
}

it('reshapes topic-channel uploads into music links with the artist suffix stripped', function () {
    $io = youtubeMusicIo([youtubeMusicFeedUrl() => [
        'status' => 200,
        'body' => youtubeMusicFeedXml([
            ['id' => 'dQw4w9WgXcQ', 'title' => 'New Single', 'published' => '2026-07-01T00:00:00+00:00'],
            ['id' => 'abc123def45', 'title' => 'Older Single', 'published' => '2026-05-01T00:00:00+00:00'],
        ]),
        'headers' => [],
    ]]);

    $messages = iterator_to_array((new YoutubeMusicConnector)->pull(youtubeMusicPull(), $io));
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered))[0];

    expect($records)->toHaveCount(2)
        ->and($records[0]->key)->toBe('dQw4w9WgXcQ')
        ->and($records[0]->doc['url'])->toBe('https://music.youtube.com/watch?v=dQw4w9WgXcQ')
        // "- Topic" is channel plumbing, not the artist's name.
        ->and($records[0]->doc['artist'])->toBe('Some Artist')
        ->and($records[0]->doc['published'])->toBe('2026-07-01T00:00:00+00:00')
        // The feed caps at 15 — only ever a prefix.
        ->and($covered->coverage->toArray())->toMatchArray(['type' => 'prefix', 'from' => '2026-05-01T00:00:00+00:00']);
});

it('notes an empty feed with no coverage claim instead of failing the stream', function () {
    // The audit doc's named guard: legacy threw Unavailable here, suppressing
    // healthy channels; an empty well-formed feed must land nothing, claim
    // nothing, and stay ok.
    $io = youtubeMusicIo([youtubeMusicFeedUrl() => ['status' => 200, 'body' => youtubeMusicFeedXml([]), 'headers' => []]]);

    $messages = iterator_to_array((new YoutubeMusicConnector)->pull(youtubeMusicPull(), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Note::class)
        ->and($messages[0]->code)->toBe('empty_feed')
        ->and(array_filter($messages, fn ($m) => $m instanceof Covered))->toBeEmpty();
});

it('treats a failed fetch and unparseable xml as unavailable, never emptiness', function () {
    $io = youtubeMusicIo([youtubeMusicFeedUrl() => ['status' => 503, 'body' => '', 'headers' => []]]);
    expect(iterator_to_array((new YoutubeMusicConnector)->pull(youtubeMusicPull(), $io))[0])->toBeInstanceOf(Unavailable::class);

    $io = youtubeMusicIo([youtubeMusicFeedUrl() => ['status' => 200, 'body' => 'error: definitely not xml <', 'headers' => []]]);
    expect(iterator_to_array((new YoutubeMusicConnector)->pull(youtubeMusicPull(), $io))[0])->toBeInstanceOf(Unavailable::class);
});

it('refuses a non-UC identifier without touching the network', function () {
    $pull = new Pull(identifier: 'some-artist', stream: YoutubeMusicConnector::manifest()->stream('releases'));

    $messages = iterator_to_array((new YoutubeMusicConnector)->pull($pull, youtubeMusicIo([])));

    expect($messages[0])->toBeInstanceOf(Unavailable::class);
});

it('slices to the scope limit and narrows the prefix accordingly', function () {
    $io = youtubeMusicIo([youtubeMusicFeedUrl() => [
        'status' => 200,
        'body' => youtubeMusicFeedXml([
            ['id' => 'aaaaaaaaaaa', 'title' => 'A', 'published' => '2026-07-03T00:00:00+00:00'],
            ['id' => 'bbbbbbbbbbb', 'title' => 'B', 'published' => '2026-07-02T00:00:00+00:00'],
            ['id' => 'ccccccccccc', 'title' => 'C', 'published' => '2026-07-01T00:00:00+00:00'],
        ]),
        'headers' => [],
    ]]);

    $messages = iterator_to_array((new YoutubeMusicConnector)->pull(youtubeMusicPull(['scope' => 'latest_n', 'scope_n' => 2]), $io));
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered))[0];

    expect($records)->toHaveCount(2)
        ->and($covered->coverage->toArray())->toMatchArray(['type' => 'prefix', 'from' => '2026-07-02T00:00:00+00:00']);
});

it('is a feed ordered by published', function () {
    $spec = YoutubeMusicConnector::manifest()->stream('releases');

    expect($spec->profile)->toBe(SourceProfile::Feed)
        ->and($spec->orderField)->toBe('published')
        ->and($spec->mayDelete())->toBeTrue();
});
