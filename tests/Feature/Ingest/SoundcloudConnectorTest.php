<?php

use App\Ingest\Connectors\SoundcloudConnector;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Runtime\EffectRefused;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

// Tier C: the connector's real pull() against recorded oEmbed responses.

/** A minimal Io that answers from a fixed url => response map. */
function soundcloudIo(array $responses): Io
{
    return new class($responses) implements Io
    {
        public function __construct(private array $responses) {}

        public function get(string $url, array $headers = []): array
        {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (! SoundcloudConnector::manifest()->mayContact($host)) {
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

function soundcloudPull(string $entityUrl = 'https://soundcloud.com/forss'): Pull
{
    return new Pull(
        identifier: $entityUrl,
        stream: SoundcloudConnector::manifest()->stream('listen'),
    );
}

function soundcloudOembedUrl(string $entityUrl): string
{
    return 'https://soundcloud.com/oembed?'.http_build_query(['format' => 'json', 'url' => $entityUrl]);
}

it('resolves an oembed into one exhaustive channel record with the parsed player src', function () {
    // Real SoundCloud oEmbed shape: no iframe_url — the player URL hides in `html`.
    $body = json_encode([
        'version' => 1.0,
        'type' => 'rich',
        'provider_name' => 'SoundCloud',
        'title' => 'Forss',
        'author_name' => 'Forss',
        'thumbnail_url' => 'https://i1.sndcdn.com/avatars-000001-t500x500.jpg',
        'html' => '<iframe width="100%" height="400" scrolling="no" frameborder="no" src="https://w.soundcloud.com/player/?visual=true&amp;url=https%3A%2F%2Fapi.soundcloud.com%2Fusers%2F2&amp;show_artwork=true"></iframe>',
    ]);

    $io = soundcloudIo([soundcloudOembedUrl('https://soundcloud.com/forss') => ['status' => 200, 'body' => $body, 'headers' => []]]);
    $messages = iterator_to_array((new SoundcloudConnector)->pull(soundcloudPull(), $io));

    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered));

    expect($records)->toHaveCount(1)
        ->and($records[0]->key)->toBe('forss')
        ->and($records[0]->doc['title'])->toBe('Forss')
        // Entities decoded out of the html snippet.
        ->and($records[0]->doc['embed_url'])->toBe('https://w.soundcloud.com/player/?visual=true&url=https%3A%2F%2Fapi.soundcloud.com%2Fusers%2F2&show_artwork=true')
        ->and($covered[0]->coverage->toArray()['type'])->toBe('exhaustive');
});

it('falls back to the deterministic widget url when the oembed html carries no iframe', function () {
    $body = json_encode(['title' => 'Forss', 'html' => '<div>no iframe today</div>']);

    $io = soundcloudIo([soundcloudOembedUrl('https://soundcloud.com/forss') => ['status' => 200, 'body' => $body, 'headers' => []]]);
    $records = array_values(array_filter(
        iterator_to_array((new SoundcloudConnector)->pull(soundcloudPull(), $io)),
        fn ($m) => $m instanceof Record,
    ));

    expect($records[0]->doc['embed_url'])
        ->toBe('https://w.soundcloud.com/player/?url='.rawurlencode('https://soundcloud.com/forss').'&visual=true');
});

it('degrades a titleless 200 to unavailable exactly like a non-200', function () {
    $io = soundcloudIo([soundcloudOembedUrl('https://soundcloud.com/forss') => ['status' => 200, 'body' => '{"html":"<iframe src=\"x\"/>"}', 'headers' => []]]);
    $messages = iterator_to_array((new SoundcloudConnector)->pull(soundcloudPull(), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Unavailable::class);

    $io = soundcloudIo([soundcloudOembedUrl('https://soundcloud.com/forss') => ['status' => 403, 'body' => '', 'headers' => []]]);
    $messages = iterator_to_array((new SoundcloudConnector)->pull(soundcloudPull(), $io));

    expect($messages[0])->toBeInstanceOf(Unavailable::class)
        ->and($messages[0]->status)->toBe(403);
});

it('keys a track or set by its full entity path', function () {
    $entity = 'https://soundcloud.com/forss/sets/soulhack';
    $body = json_encode(['title' => 'Soulhack', 'html' => '<iframe src="https://w.soundcloud.com/player/?url=x"></iframe>']);

    $io = soundcloudIo([soundcloudOembedUrl($entity) => ['status' => 200, 'body' => $body, 'headers' => []]]);
    $records = array_values(array_filter(
        iterator_to_array((new SoundcloudConnector)->pull(soundcloudPull($entity), $io)),
        fn ($m) => $m instanceof Record,
    ));

    expect($records[0]->key)->toBe('forss/sets/soulhack');
});

it('uses an identity profile that can never delete', function () {
    $spec = SoundcloudConnector::manifest()->stream('listen');

    expect($spec->profile)->toBe(SourceProfile::Identity)
        ->and($spec->orderField)->toBeNull()
        ->and($spec->mayDelete())->toBeFalse();
});
