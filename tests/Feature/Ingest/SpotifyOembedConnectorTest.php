<?php

use App\Ingest\Connectors\SpotifyOembedConnector;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Runtime\EffectRefused;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

// Tier C: the connector's real pull() against recorded responses. No network,
// no DB — a connector is a pure function from (Pull, Io) to Messages.

/** A minimal Io that answers from a fixed url => response map. */
function spotifyIo(array $responses): Io
{
    return new class($responses) implements Io
    {
        public function __construct(private array $responses) {}

        public function get(string $url, array $headers = []): array
        {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (! SpotifyOembedConnector::manifest()->mayContact($host)) {
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

function spotifyPull(string $identifier = 'https://open.spotify.com/artist/invented00000artistid'): Pull
{
    return new Pull(
        identifier: $identifier,
        stream: SpotifyOembedConnector::manifest()->stream('listen'),
    );
}

function spotifyOembedUrl(string $entityUrl): string
{
    return 'https://open.spotify.com/oembed?'.http_build_query(['url' => $entityUrl]);
}

it('declares only the hosts it actually needs', function () {
    $manifest = SpotifyOembedConnector::manifest();

    expect($manifest->mayContact('open.spotify.com'))->toBeTrue()
        ->and($manifest->mayContact('i.scdn.co'))->toBeTrue()
        ->and($manifest->mayContact('evil.com'))->toBeFalse()
        ->and($manifest->mayContact('spotify.com.evil.com'))->toBeFalse();
});

it('yields exactly one record for the embed plus an exhaustive coverage claim', function () {
    $entityUrl = 'https://open.spotify.com/artist/invented00000artistid';
    $io = spotifyIo([spotifyOembedUrl($entityUrl) => [
        'status' => 200,
        'body' => json_encode([
            'title' => 'Invented Artist',
            'thumbnail_url' => 'https://i.scdn.co/image/invented',
            'html' => '<iframe src="https://open.spotify.com/embed/artist/invented00000artistid"></iframe>',
            'provider_name' => 'Spotify',
        ]),
        'headers' => [],
    ]]);

    $messages = iterator_to_array((new SpotifyOembedConnector)->pull(spotifyPull($entityUrl), $io));

    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered));

    expect($records)->toHaveCount(1)
        ->and($records[0]->key)->toBe('artist/invented00000artistid')
        ->and($records[0]->doc['title'])->toBe('Invented Artist')
        ->and($records[0]->doc['provider_name'])->toBe('Spotify')
        ->and($records[0]->doc['html'])->toContain('embed/artist/invented00000artistid')
        // The property the whole stream design rests on: one entity, fully
        // seen, is exhaustive by construction — never a partial list.
        ->and($covered)->toHaveCount(1)
        ->and($covered[0]->coverage->toArray()['type'])->toBe('exhaustive');
});

it('reports a non-200 oembed response as unavailable, never as a missing embed', function () {
    $entityUrl = 'https://open.spotify.com/track/invented0000000trackid';
    $io = spotifyIo([spotifyOembedUrl($entityUrl) => ['status' => 404, 'body' => '', 'headers' => []]]);

    $messages = iterator_to_array((new SpotifyOembedConnector)->pull(spotifyPull($entityUrl), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Unavailable::class)
        ->and($messages[0]->status)->toBe(404);
});

it('degrades a shape-changed oembed body to unavailable instead of a titleless record', function () {
    // A single-entity stream has no "empty" case the way a list does — a 200
    // with no usable title means the endpoint's shape moved (the Feb-2026
    // risk named in the class docblock), so this must read exactly like a
    // fetch failure, not like Bandcamp's "parsed cleanly, found nothing".
    $entityUrl = 'https://open.spotify.com/playlist/invented000playlistid';
    $io = spotifyIo([spotifyOembedUrl($entityUrl) => [
        'status' => 200,
        'body' => json_encode(['error' => 'unrecognized entity shape']),
        'headers' => [],
    ]]);

    $messages = iterator_to_array((new SpotifyOembedConnector)->pull(spotifyPull($entityUrl), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Unavailable::class)
        ->and(array_filter($messages, fn ($m) => $m instanceof Covered))->toBeEmpty();
});

it('refuses to fetch a host outside its own manifest', function () {
    // The oembed call always targets open.spotify.com itself — the entity
    // url only ever rides along as a query value, never as the request host
    // — so the refusal is exercised at the Io/manifest boundary directly,
    // the same mechanism the connector has no other way around.
    $io = spotifyIo([]);

    expect(fn () => $io->get('https://evil.com/oembed?url=https://evil.com/not-spotify'))
        ->toThrow(EffectRefused::class);
});

it('uses an Identity profile with no order field, so this stream can never delete', function () {
    $spec = SpotifyOembedConnector::manifest()->stream('listen');

    expect($spec->profile)->toBe(SourceProfile::Identity)
        ->and($spec->orderField)->toBeNull()
        ->and($spec->mayDelete())->toBeFalse();
});
