<?php

use App\Ingest\Connectors\EventbriteConnector;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Runtime\EffectRefused;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

// Tier C: the connector's real pull() against recorded responses. No network,
// no database.

/** A minimal Io that answers from a fixed url => response map. */
function eventbriteIo(array $responses): Io
{
    return new class($responses) implements Io
    {
        public array $requested = [];

        public function __construct(private array $responses) {}

        public function get(string $url, array $headers = []): array
        {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (! EventbriteConnector::manifest()->mayContact($host)) {
                throw new EffectRefused("off-manifest host {$host}");
            }
            $this->requested[] = $url;

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

function eventbritePull(array $config = []): Pull
{
    return new Pull(
        identifier: 'https://www.eventbrite.com.au/o/laneway-collective-1234567890',
        stream: EventbriteConnector::manifest()->stream('events'),
        config: $config,
    );
}

/** A realistic event-detail page: schema.org MusicEvent JSON-LD. */
function eventbriteEventHtml(string $name, string $url, ?string $start = '2026-09-20T19:00:00+10:00', array $overrides = []): string
{
    $node = array_merge([
        '@context' => 'http://schema.org',
        '@type' => 'MusicEvent',
        'name' => $name,
        'url' => $url,
        'startDate' => $start,
        'endDate' => $start === null ? null : '2026-09-20T23:00:00+10:00',
        'location' => [
            '@type' => 'Place',
            'name' => 'The Corner Hotel',
            'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Richmond', 'addressRegion' => 'VIC'],
        ],
        'offers' => [[
            '@type' => 'AggregateOffer',
            'lowPrice' => '25.00',
            'highPrice' => '45.00',
            'priceCurrency' => 'AUD',
            'availability' => 'http://schema.org/InStock',
        ]],
        'image' => 'https://img.evbuc.com/https%3A%2F%2Fcdn.evbuc.com%2Fimages%2F1%2Foriginal.jpg?w=1000&auto=format',
        'description' => '<p>A big night of</p><p>live music.</p>',
    ], $overrides);

    return '<html><head><script type="application/ld+json">'.json_encode($node).'</script></head><body></body></html>';
}

function eventbriteOrgHtml(array $eventUrls): string
{
    $links = implode('', array_map(fn ($u) => '<a href="'.$u.'">event</a>', $eventUrls));

    return '<html><head><meta property="og:title" content="Laneway Collective Events | Eventbrite"/></head><body>'.$links.'</body></html>';
}

it('declares only enumerated eventbrite hosts, never an open glob', function () {
    $manifest = EventbriteConnector::manifest();

    expect($manifest->mayContact('www.eventbrite.com'))->toBeTrue()
        ->and($manifest->mayContact('www.eventbrite.com.au'))->toBeTrue()
        ->and($manifest->mayContact('eventbrite.co.uk'))->toBeTrue()
        // The §17 spoofable-host shape must stay closed here.
        ->and($manifest->mayContact('eventbrite.evil.com'))->toBeFalse()
        ->and($manifest->mayContact('evil.com'))->toBeFalse();
});

it('yields a record per organiser event plus an exhaustive coverage claim', function () {
    $one = 'https://www.eventbrite.com.au/e/spring-show-tickets-111';
    $two = 'https://www.eventbrite.com.au/e/winter-show-tickets-222';

    $io = eventbriteIo([
        'https://www.eventbrite.com.au/o/laneway-collective-1234567890' => ['status' => 200, 'body' => eventbriteOrgHtml([$one, $two]), 'headers' => []],
        $one => ['status' => 200, 'body' => eventbriteEventHtml('Spring Show', $one), 'headers' => []],
        $two => ['status' => 200, 'body' => eventbriteEventHtml('Winter Show', $two, '2026-11-01T18:00:00+11:00'), 'headers' => []],
    ]);

    $messages = iterator_to_array((new EventbriteConnector)->pull(eventbritePull(), $io));
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered));

    expect($records)->toHaveCount(2)
        ->and($records[0]->key)->toBe('e/spring-show-tickets-111')
        ->and($records[0]->doc['name'])->toBe('Spring Show')
        // Regional hosts normalize to .com so record keys and links are stable.
        ->and($records[0]->doc['url'])->toBe('https://www.eventbrite.com/e/spring-show-tickets-111')
        ->and($records[0]->doc['venue'])->toBe('The Corner Hotel')
        ->and($records[0]->doc['locality'])->toBe('Richmond')
        ->and($records[0]->doc['price_min'])->toBe(25.0)
        ->and($records[0]->doc['currency'])->toBe('AUD')
        ->and($records[0]->doc['availability'])->toBe('available')
        // Block boundaries become spaces, tags are stripped.
        ->and($records[0]->doc['description'])->toBe('A big night of live music.')
        ->and($covered)->toHaveCount(1)
        ->and($covered[0]->coverage->toArray()['type'])->toBe('exhaustive');
});

it('reports a failed organiser fetch as unavailable rather than as no events', function () {
    $io = eventbriteIo(['https://www.eventbrite.com.au/o/laneway-collective-1234567890' => ['status' => 503, 'body' => '', 'headers' => []]]);

    $messages = iterator_to_array((new EventbriteConnector)->pull(eventbritePull(), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Unavailable::class)
        ->and($messages[0]->status)->toBe(503);
});

it('emits no coverage when the organiser page lists nothing, so absence can never delete', function () {
    $io = eventbriteIo(['https://www.eventbrite.com.au/o/laneway-collective-1234567890' => ['status' => 200, 'body' => eventbriteOrgHtml([]), 'headers' => []]]);

    $messages = iterator_to_array((new EventbriteConnector)->pull(eventbritePull(), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Note::class)
        ->and(array_filter($messages, fn ($m) => $m instanceof Covered))->toBeEmpty();
});

it('degrades coverage to unknown when a detail page fails, so the unseen event survives', function () {
    $one = 'https://www.eventbrite.com.au/e/spring-show-tickets-111';
    $two = 'https://www.eventbrite.com.au/e/winter-show-tickets-222';

    $io = eventbriteIo([
        'https://www.eventbrite.com.au/o/laneway-collective-1234567890' => ['status' => 200, 'body' => eventbriteOrgHtml([$one, $two]), 'headers' => []],
        $one => ['status' => 200, 'body' => eventbriteEventHtml('Spring Show', $one), 'headers' => []],
        // $two 404s.
    ]);

    $messages = iterator_to_array((new EventbriteConnector)->pull(eventbritePull(), $io));
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered))[0];

    expect($records)->toHaveCount(1)
        ->and($covered->coverage->toArray()['type'])->toBe('unknown');
});

it('claims only unknown coverage when the run is scope-limited', function () {
    $urls = array_map(fn ($i) => "https://www.eventbrite.com.au/e/show-{$i}-tickets-{$i}{$i}{$i}", range(1, 3));

    $responses = ['https://www.eventbrite.com.au/o/laneway-collective-1234567890' => ['status' => 200, 'body' => eventbriteOrgHtml($urls), 'headers' => []]];
    foreach ($urls as $i => $url) {
        $responses[$url] = ['status' => 200, 'body' => eventbriteEventHtml('Show '.($i + 1), $url), 'headers' => []];
    }

    $messages = iterator_to_array((new EventbriteConnector)->pull(eventbritePull(['scope' => 'latest_n', 'scope_n' => 2]), $io = eventbriteIo($responses)));
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered))[0];

    expect($records)->toHaveCount(2)
        ->and($covered->coverage->toArray()['type'])->toBe('unknown');
});

it('refuses to fetch a host outside its own manifest', function () {
    $pull = new Pull(
        identifier: 'https://evil.com/o/fake-organiser-1',
        stream: EventbriteConnector::manifest()->stream('events'),
    );

    expect(fn () => iterator_to_array((new EventbriteConnector)->pull($pull, eventbriteIo([]))))
        ->toThrow(EffectRefused::class);
});

it('uses a calendar profile whose absences may mean cancellation only via start_date', function () {
    $spec = EventbriteConnector::manifest()->stream('events');

    expect($spec->profile)->toBe(SourceProfile::Calendar)
        ->and($spec->orderField)->toBe('start_date')
        ->and($spec->mayDelete())->toBeTrue();
});
