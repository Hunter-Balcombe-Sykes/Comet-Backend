<?php

use App\Ingest\Connectors\PinterestConnector;
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
function pinterestIo(array $responses): Io
{
    return new class($responses) implements Io
    {
        public function __construct(private array $responses) {}

        public function get(string $url, array $headers = []): array
        {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (! PinterestConnector::manifest()->mayContact($host)) {
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

function pinterestPull(string $username = 'inventedartist'): Pull
{
    return new Pull(
        identifier: $username,
        stream: PinterestConnector::manifest()->stream('media'),
    );
}

function pinterestProfileUrl(string $username = 'inventedartist'): string
{
    return "https://www.pinterest.com/{$username}/";
}

/** One pin-shaped node, in the size-keyed `images` dict shape this connector's extraction looks for. */
function pinterestPinNode(string $id, string $imageUrl, ?string $title = null): array
{
    $node = ['id' => $id, 'images' => ['orig' => ['url' => $imageUrl]]];
    if ($title !== null) {
        $node['grid_title'] = $title;
    }

    return $node;
}

/**
 * A minimal embedded page-state fixture: pin nodes nested a few levels deep
 * inside an arbitrary resource-cache shape, wrapped in the same
 * <script type="application/json"> tag Pinterest's real profile pages use —
 * exercising the same recursive search a real page's deeper nesting would.
 */
function pinterestStateHtml(array $pinNodes): string
{
    $state = [
        'props' => [
            'initialReduxState' => [
                'resources' => [
                    'data' => $pinNodes,
                ],
            ],
        ],
    ];

    return '<html><head><script type="application/json">'.json_encode($state).'</script></head><body></body></html>';
}

it('declares only the hosts it actually needs', function () {
    $manifest = PinterestConnector::manifest();

    expect($manifest->mayContact('www.pinterest.com'))->toBeTrue()
        ->and($manifest->mayContact('pinterest.com'))->toBeTrue()
        ->and($manifest->mayContact('i.pinimg.com'))->toBeTrue()
        ->and($manifest->mayContact('evil.com'))->toBeFalse()
        ->and($manifest->mayContact('pinterest.com.evil.com'))->toBeFalse();
});

it('yields a record per pin with an unknown coverage claim, never exhaustive or a prefix', function () {
    $username = 'inventedartist';
    $io = pinterestIo([pinterestProfileUrl($username) => [
        'status' => 200,
        'body' => pinterestStateHtml([
            pinterestPinNode('1111', 'https://i.pinimg.com/originals/aa/1111.jpg', 'First Pin'),
            pinterestPinNode('2222', 'https://i.pinimg.com/originals/bb/2222.jpg?ts=99', 'Second Pin'),
        ]),
        'headers' => [],
    ]]);

    $messages = iterator_to_array((new PinterestConnector)->pull(pinterestPull($username), $io));

    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered));

    expect($records)->toHaveCount(2)
        ->and($records[0]->key)->toBe('1111')
        ->and($records[0]->doc['url'])->toBe('https://www.pinterest.com/pin/1111/')
        ->and($records[0]->doc['image_url'])->toBe('https://i.pinimg.com/originals/aa/1111.jpg')
        ->and($records[0]->doc['title'])->toBe('First Pin')
        // No reliable ordering key exists on this surface — Unknown is the
        // honest claim, never exhaustive or a prefix (see class docblock).
        ->and($covered)->toHaveCount(1)
        ->and($covered[0]->coverage->toArray()['type'])->toBe('unknown');
});

it('reports a failed fetch as unavailable rather than as a profile with no pins', function () {
    $username = 'invented404';
    $io = pinterestIo([pinterestProfileUrl($username) => ['status' => 404, 'body' => '', 'headers' => []]]);

    $messages = iterator_to_array((new PinterestConnector)->pull(pinterestPull($username), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Unavailable::class)
        ->and($messages[0]->status)->toBe(404);
});

it('reports a page with no embedded state at all as unavailable, never as an empty stream', function () {
    $username = 'brokenlayout';
    $io = pinterestIo([pinterestProfileUrl($username) => [
        'status' => 200,
        'body' => '<html><body>no json state here</body></html>',
        'headers' => [],
    ]]);

    $messages = iterator_to_array((new PinterestConnector)->pull(pinterestPull($username), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Unavailable::class)
        ->and(array_filter($messages, fn ($m) => $m instanceof Covered))->toBeEmpty();
});

it('reports a state blob with zero pin-shaped nodes as unavailable, never an empty stream — a layout change must not look like deletion', function () {
    // This is the property the whole connector's UNAVAILABLE-not-Note design
    // rests on: unlike Bandcamp/Vimeo/YoutubeRss/Substack/AppleMusic/
    // ApplePodcasts (which treat "parsed cleanly, found nothing" as a Note),
    // Pinterest's extraction is a best-effort structural guess, so "found
    // nothing" cannot be trusted to mean a genuinely pin-less profile.
    $username = 'onlyprofiledata';
    $io = pinterestIo([pinterestProfileUrl($username) => [
        'status' => 200,
        'body' => pinterestStateHtml([]),
        'headers' => [],
    ]]);

    $messages = iterator_to_array((new PinterestConnector)->pull(pinterestPull($username), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Unavailable::class)
        ->and(array_filter($messages, fn ($m) => $m instanceof Covered))->toBeEmpty();
});

it('refuses to fetch a host outside its own manifest', function () {
    $io = pinterestIo([]);

    expect(fn () => $io->get('https://evil.com/someusername/'))
        ->toThrow(EffectRefused::class);
});

it('uses a null order field, so this stream can NEVER delete', function () {
    $spec = PinterestConnector::manifest()->stream('media');

    expect($spec->profile)->toBe(SourceProfile::Feed)
        ->and($spec->orderField)->toBeNull()
        ->and($spec->mayDelete())->toBeFalse();
});
