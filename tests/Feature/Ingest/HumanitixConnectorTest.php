<?php

use App\Ingest\Connectors\HumanitixConnector;
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
function humanitixIo(array $responses): Io
{
    return new class($responses) implements Io
    {
        public function __construct(private array $responses) {}

        public function get(string $url, array $headers = []): array
        {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (! HumanitixConnector::manifest()->mayContact($host)) {
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

function humanitixPull(): Pull
{
    return new Pull(
        identifier: 'https://events.humanitix.com/host/run-club-melbourne',
        stream: HumanitixConnector::manifest()->stream('events'),
    );
}

/**
 * A realistic Humanitix Event node — the leading AggregateOffer carries only
 * priceCurrency, with each tier's price on later Offer entries (the shape
 * that forced lowestOffer() to scan the raw list).
 */
function humanitixEventNode(string $name, string $url): array
{
    return [
        '@context' => 'https://schema.org',
        '@type' => 'Event',
        'name' => $name,
        'url' => $url,
        'startDate' => '2026-08-15T07:00:00+10:00',
        'endDate' => '2026-08-15T09:00:00+10:00',
        'location' => [
            '@type' => 'Place',
            'name' => 'Tan Track ',
            'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Melbourne'],
        ],
        'offers' => [
            ['@type' => 'AggregateOffer', 'priceCurrency' => 'AUD', 'availability' => 'https://schema.org/InStock'],
            ['@type' => 'Offer', 'name' => 'General', 'price' => 15],
            ['@type' => 'Offer', 'name' => 'Early bird', 'price' => 10],
        ],
        'image' => ['https://humanitix.imgix.net/banner.jpg'],
    ];
}

function humanitixHtml(array $nodes, array $links = []): string
{
    $ld = $nodes === [] ? '' : '<script type="application/ld+json">'.json_encode($nodes).'</script>';
    $anchors = implode('', array_map(fn ($u) => '<a href="'.$u.'">e</a>', $links));

    return '<html><head><meta property="og:title" content="Run Club Melbourne | Humanitix"/>'.$ld.'</head><body>'.$anchors.'</body></html>';
}

it('uses embedded host-page JSON-LD without fetching any detail page', function () {
    $io = humanitixIo([
        'https://events.humanitix.com/host/run-club-melbourne' => [
            'status' => 200,
            'body' => humanitixHtml([
                humanitixEventNode('Dawn Run', 'https://events.humanitix.com/dawn-run-august'),
                humanitixEventNode('Dusk Run', 'https://events.humanitix.com/dusk-run-august'),
            ]),
            'headers' => [],
        ],
    ]);

    $messages = iterator_to_array((new HumanitixConnector)->pull(humanitixPull(), $io));
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered));

    expect($records)->toHaveCount(2)
        ->and($records[0]->key)->toBe('dawn-run-august')
        ->and($records[0]->doc['name'])->toBe('Dawn Run')
        ->and($records[0]->doc['venue'])->toBe('Tan Track')
        ->and($records[0]->doc['locality'])->toBe('Melbourne')
        // The min must be taken across ALL offer entries, not the first.
        ->and($records[0]->doc['price_min'])->toBe(10.0)
        ->and($records[0]->doc['currency'])->toBe('AUD')
        ->and($records[0]->doc['image'])->toBe('https://humanitix.imgix.net/banner.jpg')
        ->and($covered[0]->coverage->toArray()['type'])->toBe('exhaustive');
});

it('falls back to harvesting event links and skips product chrome slugs', function () {
    $io = humanitixIo([
        'https://events.humanitix.com/host/run-club-melbourne' => [
            'status' => 200,
            'body' => humanitixHtml([], ['/dawn-run-august', '/search', '/help', 'https://events.humanitix.com/dusk-run-august']),
            'headers' => [],
        ],
        'https://events.humanitix.com/dawn-run-august' => [
            'status' => 200,
            'body' => humanitixHtml([humanitixEventNode('Dawn Run', 'https://events.humanitix.com/dawn-run-august')]),
            'headers' => [],
        ],
        'https://events.humanitix.com/dusk-run-august' => [
            'status' => 200,
            'body' => humanitixHtml([humanitixEventNode('Dusk Run', 'https://events.humanitix.com/dusk-run-august')]),
            'headers' => [],
        ],
    ]);

    $messages = iterator_to_array((new HumanitixConnector)->pull(humanitixPull(), $io));
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered))[0];

    expect(array_map(fn ($r) => $r->key, $records))->toBe(['dawn-run-august', 'dusk-run-august'])
        ->and($covered->coverage->toArray()['type'])->toBe('exhaustive');
});

it('degrades coverage to unknown when a harvested detail page fails', function () {
    $io = humanitixIo([
        'https://events.humanitix.com/host/run-club-melbourne' => [
            'status' => 200,
            'body' => humanitixHtml([], ['/dawn-run-august', '/dusk-run-august']),
            'headers' => [],
        ],
        'https://events.humanitix.com/dawn-run-august' => [
            'status' => 200,
            'body' => humanitixHtml([humanitixEventNode('Dawn Run', 'https://events.humanitix.com/dawn-run-august')]),
            'headers' => [],
        ],
        // dusk-run-august 404s.
    ]);

    $messages = iterator_to_array((new HumanitixConnector)->pull(humanitixPull(), $io));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered))[0];

    expect($covered->coverage->toArray()['type'])->toBe('unknown');
});

it('reports a failed host fetch as unavailable rather than as no events', function () {
    $io = humanitixIo(['https://events.humanitix.com/host/run-club-melbourne' => ['status' => 500, 'body' => '', 'headers' => []]]);

    $messages = iterator_to_array((new HumanitixConnector)->pull(humanitixPull(), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Unavailable::class);
});

it('emits a note and no coverage for a host page with no events at all', function () {
    $io = humanitixIo(['https://events.humanitix.com/host/run-club-melbourne' => ['status' => 200, 'body' => humanitixHtml([]), 'headers' => []]]);

    $messages = iterator_to_array((new HumanitixConnector)->pull(humanitixPull(), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Note::class)
        ->and(array_filter($messages, fn ($m) => $m instanceof Covered))->toBeEmpty();
});

it('uses a calendar profile with start_date ordering', function () {
    $spec = HumanitixConnector::manifest()->stream('events');

    expect($spec->profile)->toBe(SourceProfile::Calendar)
        ->and($spec->orderField)->toBe('start_date')
        ->and($spec->mayDelete())->toBeTrue();
});
