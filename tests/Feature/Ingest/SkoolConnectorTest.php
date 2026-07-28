<?php

use App\Ingest\Connectors\SkoolConnector;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Runtime\EffectRefused;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

// Tier C: the connector's real pull() against recorded responses.

/** A minimal Io that answers from a fixed url => response map. */
function skoolIo(array $responses): Io
{
    return new class($responses) implements Io
    {
        public function __construct(private array $responses) {}

        public function get(string $url, array $headers = []): array
        {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (! SkoolConnector::manifest()->mayContact($host)) {
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

function skoolPull(): Pull
{
    return new Pull(
        identifier: 'https://www.skool.com/max-business-school',
        stream: SkoolConnector::manifest()->stream('community'),
    );
}

function skoolCommunityHtml(string $title): string
{
    return '<html><head>'
        .'<meta property="og:title" content="'.$title.'"/>'
        .'<meta property="og:image" content="https://assets.skool.com/f/1234/avatar.jpg"/>'
        .'<meta property="og:description" content="Learn business with 100k founders."/>'
        .'</head><body></body></html>';
}

it('reads the community card from the about page as one exhaustive channel record', function () {
    $io = skoolIo([
        'https://www.skool.com/max-business-school/about' => ['status' => 200, 'body' => skoolCommunityHtml('Max Business School'), 'headers' => []],
    ]);

    $messages = iterator_to_array((new SkoolConnector)->pull(skoolPull(), $io));
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered));

    expect($records)->toHaveCount(1)
        ->and($records[0]->key)->toBe('max-business-school')
        ->and($records[0]->doc['name'])->toBe('Max Business School')
        ->and($records[0]->doc['handle'])->toBe('max-business-school')
        ->and($records[0]->doc['avatar'])->toBe('https://assets.skool.com/f/1234/avatar.jpg')
        ->and($covered[0]->coverage->toArray()['type'])->toBe('exhaustive');
});

it('falls back to the community root when the about page is walled', function () {
    $io = skoolIo([
        'https://www.skool.com/max-business-school/about' => ['status' => 404, 'body' => '', 'headers' => []],
        'https://www.skool.com/max-business-school' => ['status' => 200, 'body' => skoolCommunityHtml('Max Business School'), 'headers' => []],
    ]);

    $records = array_values(array_filter(
        iterator_to_array((new SkoolConnector)->pull(skoolPull(), $io)),
        fn ($m) => $m instanceof Record,
    ));

    expect($records)->toHaveCount(1);
});

it('treats signup-wall og titles as chrome, never as the community', function () {
    $io = skoolIo([
        'https://www.skool.com/max-business-school/about' => ['status' => 200, 'body' => skoolCommunityHtml('Skool: Sign Up'), 'headers' => []],
        'https://www.skool.com/max-business-school' => ['status' => 200, 'body' => skoolCommunityHtml('Skool'), 'headers' => []],
    ]);

    $messages = iterator_to_array((new SkoolConnector)->pull(skoolPull(), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Unavailable::class);
});

it('reports an unreachable community as unavailable with the last status', function () {
    $io = skoolIo([]);

    $messages = iterator_to_array((new SkoolConnector)->pull(skoolPull(), $io));

    expect($messages[0])->toBeInstanceOf(Unavailable::class)
        ->and($messages[0]->status)->toBe(404);
});

it('uses an identity profile that can never delete', function () {
    $spec = SkoolConnector::manifest()->stream('community');

    expect($spec->profile)->toBe(SourceProfile::Identity)
        ->and($spec->mayDelete())->toBeFalse();
});
