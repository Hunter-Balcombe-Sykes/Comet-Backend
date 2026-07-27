<?php

use App\Ingest\Connectors\SubstackConnector;
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
function substackIo(array $responses): Io
{
    return new class($responses) implements Io
    {
        public function __construct(private array $responses) {}

        public function get(string $url, array $headers = []): array
        {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (! SubstackConnector::manifest()->mayContact($host)) {
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

function substackPull(string $publication = 'invented'): Pull
{
    return new Pull(
        identifier: $publication,
        stream: SubstackConnector::manifest()->stream('posts'),
    );
}

function substackFeedUrl(string $publication = 'invented'): string
{
    return "https://{$publication}.substack.com/feed";
}

/** @param  list<array{id:string,title:string,url:string,pubDate:string}>  $entries */
function substackFeedXml(array $entries): string
{
    $itemXml = '';
    foreach ($entries as $entry) {
        $itemXml .= '<item>'
            .'<title>'.htmlspecialchars($entry['title'], ENT_XML1).'</title>'
            .'<link>'.$entry['url'].'</link>'
            .'<guid isPermaLink="false">'.$entry['id'].'</guid>'
            .'<pubDate>'.$entry['pubDate'].'</pubDate>'
            .'<description>An invented description</description>'
            .'</item>';
    }

    return '<?xml version="1.0" encoding="UTF-8"?>'
        .'<rss version="2.0"><channel>'
        .'<title>Invented Newsletter</title>'
        .'<link>https://invented.substack.com</link>'
        .'<description>An invented newsletter</description>'
        .$itemXml
        .'</channel></rss>';
}

it('declares only the hosts it actually needs', function () {
    $manifest = SubstackConnector::manifest();

    expect($manifest->mayContact('invented.substack.com'))->toBeTrue()
        ->and($manifest->mayContact('substack.com'))->toBeTrue()
        ->and($manifest->mayContact('evil.com'))->toBeFalse()
        ->and($manifest->mayContact('substack.com.evil.com'))->toBeFalse();
});

it('yields a record per post plus a prefix coverage claim, never exhaustive', function () {
    $io = substackIo([substackFeedUrl() => [
        'status' => 200,
        'body' => substackFeedXml([
            ['id' => 'https://invented.substack.com/p/first-post', 'title' => 'First Post', 'url' => 'https://invented.substack.com/p/first-post', 'pubDate' => 'Wed, 01 Jan 2025 00:00:00 GMT'],
            ['id' => 'https://invented.substack.com/p/second-post', 'title' => 'Second Post', 'url' => 'https://invented.substack.com/p/second-post', 'pubDate' => 'Sun, 02 Feb 2025 00:00:00 GMT'],
        ]),
        'headers' => [],
    ]]);

    $messages = iterator_to_array((new SubstackConnector)->pull(substackPull(), $io));

    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered));

    expect($records)->toHaveCount(2)
        ->and($records[0]->key)->toBe('https://invented.substack.com/p/first-post')
        ->and($records[0]->doc['title'])->toBe('First Post')
        ->and($records[0]->doc['url'])->toBe('https://invented.substack.com/p/first-post')
        // The feed serves only the latest ~20 posts — the honest claim is
        // only ever a prefix down to the oldest post actually seen, never
        // exhaustive.
        ->and($covered)->toHaveCount(1)
        ->and($covered[0]->coverage->toArray()['type'])->toBe('prefix')
        ->and($covered[0]->coverage->toArray()['from'])->toBe('2025-01-01T00:00:00Z');
});

it('reports a failed fetch as unavailable rather than as a publication with no posts', function () {
    $io = substackIo([substackFeedUrl() => ['status' => 503, 'body' => '', 'headers' => []]]);

    $messages = iterator_to_array((new SubstackConnector)->pull(substackPull(), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Unavailable::class)
        ->and($messages[0]->status)->toBe(503);
});

it('reports unparseable xml as unavailable, distinct from a genuinely empty feed', function () {
    $io = substackIo([substackFeedUrl() => [
        'status' => 200,
        'body' => '<not-xml this is not well formed <<<',
        'headers' => [],
    ]]);

    $messages = iterator_to_array((new SubstackConnector)->pull(substackPull(), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Unavailable::class)
        ->and(array_filter($messages, fn ($m) => $m instanceof Covered))->toBeEmpty();
});

it('emits no coverage for a well-formed but empty feed, so absence can never delete', function () {
    $io = substackIo([substackFeedUrl() => [
        'status' => 200,
        'body' => substackFeedXml([]),
        'headers' => [],
    ]]);

    $messages = iterator_to_array((new SubstackConnector)->pull(substackPull(), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Note::class)
        ->and(array_filter($messages, fn ($m) => $m instanceof Covered))->toBeEmpty();
});

it('refuses to fetch a host outside its own manifest', function () {
    // The publication rides as a subdomain of a host suffix the connector
    // itself owns (.substack.com) — a hostile identifier cannot produce a
    // foreign host, so the refusal is exercised directly at the Io/manifest
    // boundary, the same mechanism the connector has no way around.
    $io = substackIo([]);

    expect(fn () => $io->get('https://evil.com/feed'))
        ->toThrow(EffectRefused::class);
});

it('uses a Feed profile with an order field, so its absences can mean deletion', function () {
    $spec = SubstackConnector::manifest()->stream('posts');

    expect($spec->profile)->toBe(SourceProfile::Feed)
        ->and($spec->orderField)->toBe('published')
        ->and($spec->mayDelete())->toBeTrue();
});
