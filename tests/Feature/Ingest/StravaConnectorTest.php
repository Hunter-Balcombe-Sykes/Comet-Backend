<?php

use App\Ingest\Connectors\StravaConnector;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Runtime\EffectRefused;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

// Tier C: the connector's real pull() against recorded responses.

/** A minimal Io that answers from a fixed url => response map. */
function stravaIo(array $responses): Io
{
    return new class($responses) implements Io
    {
        public function __construct(private array $responses) {}

        public function get(string $url, array $headers = []): array
        {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (! StravaConnector::manifest()->mayContact($host)) {
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

function stravaPull(): Pull
{
    return new Pull(
        identifier: 'https://www.strava.com/clubs/melbourne-midday-milers',
        stream: StravaConnector::manifest()->stream('club'),
    );
}

function stravaClubHtml(string $title, string $image, string $membersBlurb = '1,204 members'): string
{
    return '<html><head>'
        .'<meta property="og:title" content="'.$title.'"/>'
        .'<meta property="og:image" content="'.$image.'"/>'
        .'<meta property="og:description" content="Lunchtime runs around the Tan."/>'
        .'</head><body><span>'.$membersBlurb.'</span></body></html>';
}

it('parses the club card, splitting location from name and reading members', function () {
    $io = stravaIo([
        'https://www.strava.com/clubs/melbourne-midday-milers' => [
            'status' => 200,
            'body' => stravaClubHtml('Melbourne, Victoria | Midday Milers', 'https://example.com/avatar.jpg'),
            'headers' => [],
        ],
    ]);

    $messages = iterator_to_array((new StravaConnector)->pull(stravaPull(), $io));
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered));

    expect($records)->toHaveCount(1)
        ->and($records[0]->key)->toBe('melbourne-midday-milers')
        ->and($records[0]->doc['name'])->toBe('Midday Milers')
        ->and($records[0]->doc['location'])->toBe('Melbourne, Victoria')
        ->and($records[0]->doc['followers'])->toBe(1204)
        ->and($covered[0]->coverage->toArray()['type'])->toBe('exhaustive');
});

it('probes for and prefers the original avatar rendition over the 124px large', function () {
    $large = 'https://dgalywyr863hv.cloudfront.net/pictures/clubs/999/large.jpg';
    $original = 'https://dgalywyr863hv.cloudfront.net/pictures/clubs/999/original.jpg';

    $io = stravaIo([
        'https://www.strava.com/clubs/melbourne-midday-milers' => [
            'status' => 200, 'body' => stravaClubHtml('Midday Milers', $large), 'headers' => [],
        ],
        $original => ['status' => 200, 'body' => 'binary', 'headers' => []],
    ]);

    $records = array_values(array_filter(
        iterator_to_array((new StravaConnector)->pull(stravaPull(), $io)),
        fn ($m) => $m instanceof Record,
    ));

    expect($records[0]->doc['avatar'])->toBe($original);
});

it('keeps the large avatar when the original probe misses', function () {
    $large = 'https://dgalywyr863hv.cloudfront.net/pictures/clubs/999/large.jpg';

    $io = stravaIo([
        'https://www.strava.com/clubs/melbourne-midday-milers' => [
            'status' => 200, 'body' => stravaClubHtml('Midday Milers', $large), 'headers' => [],
        ],
        // original.jpg 404s.
    ]);

    $records = array_values(array_filter(
        iterator_to_array((new StravaConnector)->pull(stravaPull(), $io)),
        fn ($m) => $m instanceof Record,
    ));

    expect($records[0]->doc['avatar'])->toBe($large);
});

it('reports a failed or og-less club page as unavailable', function () {
    $io = stravaIo(['https://www.strava.com/clubs/melbourne-midday-milers' => ['status' => 500, 'body' => '', 'headers' => []]]);
    expect(iterator_to_array((new StravaConnector)->pull(stravaPull(), $io))[0])->toBeInstanceOf(Unavailable::class);

    $io = stravaIo(['https://www.strava.com/clubs/melbourne-midday-milers' => ['status' => 200, 'body' => '<html>wall</html>', 'headers' => []]]);
    expect(iterator_to_array((new StravaConnector)->pull(stravaPull(), $io))[0])->toBeInstanceOf(Unavailable::class);
});

it('uses an identity profile that can never delete', function () {
    $spec = StravaConnector::manifest()->stream('club');

    expect($spec->profile)->toBe(SourceProfile::Identity)
        ->and($spec->mayDelete())->toBeFalse();
});
