<?php

use App\Ingest\Connectors\TwitchConnector;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Message\Bookmark;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Deferred;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Runtime\EffectRefused;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

// Tier C: the connector's real pull() against recorded responses.

/** A minimal Io answering GETs from a url map and POSTs from a token map. */
function twitchIo(array $gets, array $posts = []): Io
{
    return new class($gets, $posts) implements Io
    {
        public array $getUrls = [];

        public function __construct(private array $gets, private array $posts) {}

        public function get(string $url, array $headers = []): array
        {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (! TwitchConnector::manifest()->mayContact($host)) {
                throw new EffectRefused("off-manifest host {$host}");
            }
            $this->getUrls[] = $url;

            return $this->gets[$url] ?? ['status' => 404, 'body' => '', 'headers' => []];
        }

        public function post(string $url, array $body = [], array $headers = []): array
        {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (! TwitchConnector::manifest()->mayContact($host)) {
                throw new EffectRefused("off-manifest host {$host}");
            }

            return $this->posts[$url] ?? ['status' => 404, 'body' => '', 'headers' => []];
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

function twitchPull(string $stream, array $cursor = []): Pull
{
    return new Pull(
        identifier: 'somestreamer',
        stream: TwitchConnector::manifest()->stream($stream),
        cursor: $cursor,
    );
}

function twitchHelixCredentials(): void
{
    config()->set('services.twitch.client_id', 'test-client-id');
    config()->set('services.twitch.client_secret', 'test-secret');
    config()->set('services.twitch.token_url', 'https://id.twitch.tv/oauth2/token');
}

function twitchTokenResponse(): array
{
    return ['https://id.twitch.tv/oauth2/token' => [
        'status' => 200,
        'body' => json_encode(['access_token' => 'app-token', 'expires_in' => 5011271, 'token_type' => 'bearer']),
        'headers' => [],
    ]];
}

function twitchChannelHtml(): string
{
    return '<html><head>'
        .'<meta property="og:title" content="SomeStreamer - Twitch"/>'
        .'<meta property="og:image" content="https://static-cdn.jtvnw.net/jtv_user_pictures/avatar-profile_image-300x300.png"/>'
        .'<meta property="og:description" content="Speedruns most evenings."/>'
        .'</head><body></body></html>';
}

/** A realistic Helix Get Videos item. */
function twitchHelixVideo(string $id, string $title, string $published): array
{
    return [
        'id' => $id,
        'stream_id' => null,
        'user_id' => '141981764',
        'user_login' => 'somestreamer',
        'user_name' => 'SomeStreamer',
        'title' => $title,
        'description' => '',
        'created_at' => $published,
        'published_at' => $published,
        'url' => "https://www.twitch.tv/videos/{$id}",
        'thumbnail_url' => "https://static-cdn.jtvnw.net/cf_vods/{$id}/thumb/thumb0-%{width}x%{height}.jpg",
        'viewable' => 'public',
        'view_count' => 1863062,
        'language' => 'en',
        'type' => 'archive',
        'duration' => '3h8m33s',
    ];
}

// ── channel stream ──────────────────────────────────────────────────────────

it('scrapes the channel og card into one exhaustive channel record', function () {
    $io = twitchIo(['https://www.twitch.tv/somestreamer' => ['status' => 200, 'body' => twitchChannelHtml(), 'headers' => []]]);

    $messages = iterator_to_array((new TwitchConnector)->pull(twitchPull('channel'), $io));
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered));

    expect($records)->toHaveCount(1)
        ->and($records[0]->key)->toBe('somestreamer')
        // The " - Twitch" suffix is chrome, not the name.
        ->and($records[0]->doc['name'])->toBe('SomeStreamer')
        ->and($records[0]->doc['handle'])->toBe('somestreamer')
        ->and($records[0]->doc['description'])->toBe('Speedruns most evenings.')
        ->and($records[0]->doc['embed'])->toBe(['provider' => 'twitch', 'key' => 'somestreamer'])
        ->and($covered[0]->coverage->toArray()['type'])->toBe('exhaustive');
});

it('reports a failed or og-less channel page as unavailable', function () {
    $io = twitchIo(['https://www.twitch.tv/somestreamer' => ['status' => 503, 'body' => '', 'headers' => []]]);
    $messages = iterator_to_array((new TwitchConnector)->pull(twitchPull('channel'), $io));
    expect($messages[0])->toBeInstanceOf(Unavailable::class);

    $io = twitchIo(['https://www.twitch.tv/somestreamer' => ['status' => 200, 'body' => '<html>consent wall</html>', 'headers' => []]]);
    $messages = iterator_to_array((new TwitchConnector)->pull(twitchPull('channel'), $io));
    expect($messages[0])->toBeInstanceOf(Unavailable::class);
});

// ── vods stream ─────────────────────────────────────────────────────────────

it('resolves login→id, pulls archives, and bookmarks the resolved user id', function () {
    twitchHelixCredentials();

    $io = twitchIo([
        'https://api.twitch.tv/helix/users?login=somestreamer' => [
            'status' => 200, 'body' => json_encode(['data' => [['id' => '141981764', 'login' => 'somestreamer']]]), 'headers' => [],
        ],
        'https://api.twitch.tv/helix/videos?user_id=141981764&first=30&type=archive' => [
            'status' => 200,
            'body' => json_encode(['data' => [
                twitchHelixVideo('335921245', 'Twitch Rivals finals', '2026-07-14T22:04:28Z'),
                twitchHelixVideo('335920000', 'Casual Tuesday run', '2026-07-10T19:00:00Z'),
            ], 'pagination' => []]),
            'headers' => [],
        ],
    ], twitchTokenResponse());

    $messages = iterator_to_array((new TwitchConnector)->pull(twitchPull('vods'), $io));
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered))[0];
    $bookmarks = array_values(array_filter($messages, fn ($m) => $m instanceof Bookmark));

    expect($records)->toHaveCount(2)
        ->and($records[0]->key)->toBe('335921245')
        ->and($records[0]->doc['title'])->toBe('Twitch Rivals finals')
        ->and($records[0]->doc['duration_seconds'])->toBe(3 * 3600 + 8 * 60 + 33)
        // The %{width}x%{height} template is resolved before landing.
        ->and($records[0]->doc['thumbnail'])->toContain('640x360')
        // A short page (<30) is the whole public archive.
        ->and($covered->coverage->toArray()['type'])->toBe('exhaustive')
        ->and($bookmarks)->toHaveCount(1)
        ->and($bookmarks[0]->cursor)->toBe(['login' => 'somestreamer', 'user_id' => '141981764']);
});

it('skips the users hop when the cursor already carries this login', function () {
    twitchHelixCredentials();

    $io = twitchIo([
        'https://api.twitch.tv/helix/videos?user_id=141981764&first=30&type=archive' => [
            'status' => 200, 'body' => json_encode(['data' => [twitchHelixVideo('1', 'One', '2026-07-01T00:00:00Z')]]), 'headers' => [],
        ],
    ], twitchTokenResponse());

    $messages = iterator_to_array((new TwitchConnector)->pull(
        twitchPull('vods', ['login' => 'somestreamer', 'user_id' => '141981764']),
        $io,
    ));

    expect(array_filter($messages, fn ($m) => $m instanceof Record))->toHaveCount(1)
        // No re-resolution, no new bookmark.
        ->and(array_filter($messages, fn ($m) => $m instanceof Bookmark))->toBeEmpty()
        ->and($io->getUrls)->not->toContain('https://api.twitch.tv/helix/users?login=somestreamer');
});

it('degrades to unavailable when helix credentials are not configured', function () {
    config()->set('services.twitch.client_id', '');
    config()->set('services.twitch.client_secret', '');

    $messages = iterator_to_array((new TwitchConnector)->pull(twitchPull('vods'), twitchIo([])));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Unavailable::class);
});

it('defers rather than fails on a helix rate limit', function () {
    twitchHelixCredentials();

    $io = twitchIo([
        'https://api.twitch.tv/helix/videos?user_id=141981764&first=30&type=archive' => ['status' => 429, 'body' => '', 'headers' => []],
    ], twitchTokenResponse());

    $messages = iterator_to_array((new TwitchConnector)->pull(
        twitchPull('vods', ['login' => 'somestreamer', 'user_id' => '141981764']),
        $io,
    ));

    expect($messages[0])->toBeInstanceOf(Deferred::class)
        ->and($messages[0]->retryAfterSeconds)->toBe(120);
});

it('notes an empty archive without any coverage claim, so nothing tombstones', function () {
    twitchHelixCredentials();

    $io = twitchIo([
        'https://api.twitch.tv/helix/videos?user_id=141981764&first=30&type=archive' => [
            'status' => 200, 'body' => json_encode(['data' => []]), 'headers' => [],
        ],
    ], twitchTokenResponse());

    $messages = iterator_to_array((new TwitchConnector)->pull(
        twitchPull('vods', ['login' => 'somestreamer', 'user_id' => '141981764']),
        $io,
    ));

    expect($messages[0])->toBeInstanceOf(Note::class)
        ->and(array_filter($messages, fn ($m) => $m instanceof Covered))->toBeEmpty();
});

it('claims only a prefix when helix returns a full page of thirty', function () {
    twitchHelixCredentials();

    $videos = [];
    for ($i = 30; $i >= 1; $i--) {
        $videos[] = twitchHelixVideo((string) (1000 + $i), "VOD {$i}", sprintf('2026-06-%02dT00:00:00Z', $i));
    }

    $io = twitchIo([
        'https://api.twitch.tv/helix/videos?user_id=141981764&first=30&type=archive' => [
            'status' => 200, 'body' => json_encode(['data' => $videos]), 'headers' => [],
        ],
    ], twitchTokenResponse());

    $messages = iterator_to_array((new TwitchConnector)->pull(
        twitchPull('vods', ['login' => 'somestreamer', 'user_id' => '141981764']),
        $io,
    ));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered))[0];

    expect($covered->coverage->toArray())->toMatchArray(['type' => 'prefix', 'from' => '2026-06-01T00:00:00Z']);
});

it('declares vods as a feed ordered by published and channel as identity', function () {
    $vods = TwitchConnector::manifest()->stream('vods');
    $channel = TwitchConnector::manifest()->stream('channel');

    expect($vods->profile)->toBe(SourceProfile::Feed)
        ->and($vods->orderField)->toBe('published')
        ->and($vods->mayDelete())->toBeTrue()
        ->and($channel->profile)->toBe(SourceProfile::Identity)
        ->and($channel->mayDelete())->toBeFalse();
});
