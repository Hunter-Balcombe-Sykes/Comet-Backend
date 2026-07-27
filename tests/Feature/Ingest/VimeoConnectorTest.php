<?php

use App\Ingest\Connectors\VimeoConnector;
use App\Ingest\Manifest\SourceProfile;
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
function vimeoIo(array $responses): Io
{
    return new class($responses) implements Io
    {
        public function __construct(private array $responses) {}

        public function get(string $url, array $headers = []): array
        {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (! VimeoConnector::manifest()->mayContact($host)) {
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

function vimeoPull(array $config = []): Pull
{
    return new Pull(
        identifier: 'someartist',
        stream: VimeoConnector::manifest()->stream('watch'),
        config: $config,
    );
}

it('declares only the hosts it actually needs', function () {
    $manifest = VimeoConnector::manifest();

    expect($manifest->mayContact('vimeo.com'))->toBeTrue()
        ->and($manifest->mayContact('i.vimeocdn.com'))->toBeTrue()
        ->and($manifest->mayContact('evil.com'))->toBeFalse()
        ->and($manifest->mayContact('vimeo.com.evil.com'))->toBeFalse();
});

it('yields a record per video plus a prefix coverage claim, never exhaustive', function () {
    // The property the whole spec rests on: this endpoint caps at 20 and
    // returns newest-first, so it can NEVER honestly claim exhaustive —
    // getting this wrong would delete a user's back catalogue (C5).
    $io = vimeoIo(['https://vimeo.com/api/v2/someartist/videos.json' => [
        'status' => 200,
        'body' => json_encode([
            ['id' => 111, 'title' => 'First Video', 'url' => 'https://vimeo.com/111', 'upload_date' => '2024-03-01 00:00:00', 'thumbnail_large' => 'https://i.vimeocdn.com/video/111_640.jpg?r=pad'],
            ['id' => 222, 'title' => 'Second Video', 'url' => 'https://vimeo.com/222', 'upload_date' => '2025-02-02 00:00:00', 'thumbnail_large' => 'https://i.vimeocdn.com/video/222_640.jpg?r=pad'],
        ]),
        'headers' => [],
    ]]);

    $messages = iterator_to_array((new VimeoConnector)->pull(vimeoPull(), $io));

    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered));

    expect($records)->toHaveCount(2)
        ->and($records[0]->key)->toBe('111')
        ->and($records[0]->doc['title'])->toBe('First Video')
        ->and($records[0]->doc['url'])->toBe('https://vimeo.com/111')
        ->and($covered)->toHaveCount(1)
        ->and($covered[0]->coverage->toArray()['type'])->toBe('prefix')
        ->and($covered[0]->coverage->toArray()['from'])->toBe('2024-03-01 00:00:00');
});

it('reports a failed fetch as unavailable rather than as a video-less profile', function () {
    $io = vimeoIo(['https://vimeo.com/api/v2/someartist/videos.json' => ['status' => 503, 'body' => '', 'headers' => []]]);

    $messages = iterator_to_array((new VimeoConnector)->pull(vimeoPull(), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Unavailable::class)
        ->and($messages[0]->status)->toBe(503);
});

it('reports an unparseable body as unavailable rather than as an empty catalogue', function () {
    $io = vimeoIo(['https://vimeo.com/api/v2/someartist/videos.json' => [
        'status' => 200,
        'body' => '<html>not json</html>',
        'headers' => [],
    ]]);

    $messages = iterator_to_array((new VimeoConnector)->pull(vimeoPull(), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Unavailable::class);
});

it('emits no coverage when it parses nothing, so absence can never delete', function () {
    $io = vimeoIo(['https://vimeo.com/api/v2/someartist/videos.json' => ['status' => 200, 'body' => '[]', 'headers' => []]]);

    $messages = iterator_to_array((new VimeoConnector)->pull(vimeoPull(), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Note::class)
        ->and(array_filter($messages, fn ($m) => $m instanceof Covered))->toBeEmpty();
});

it('refuses to fetch a host outside its own manifest', function () {
    // Unlike Bandcamp's origin-templated URL, Vimeo's api path is appended to
    // a host the connector itself hardcodes — so the refusal is exercised at
    // the Io/manifest boundary directly, the same mechanism the connector has
    // no way around (it has no other path to the network at all).
    $io = vimeoIo([]);

    expect(fn () => $io->get('https://evil.com/api/v2/someartist/videos.json'))
        ->toThrow(EffectRefused::class);
});

it('uses a Feed profile with an order field, so its absences can mean deletion', function () {
    $spec = VimeoConnector::manifest()->stream('watch');

    expect($spec->profile)->toBe(SourceProfile::Feed)
        ->and($spec->orderField)->toBe('upload_date')
        ->and($spec->mayDelete())->toBeTrue();
});
