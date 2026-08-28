<?php

namespace App\Ingest\Connectors;

use App\Ingest\Landing\Coverage;
use App\Ingest\Manifest\CostClass;
use App\Ingest\Manifest\Manifest;
use App\Ingest\Manifest\SourceKey;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Manifest\StreamSpec;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Message;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Runtime\Connector;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

/**
 * DICE artist pages (wave 2, 2026-08-28). Unlike the rest of the events
 * batch, DICE answers a plain server-side GET with a complete
 * schema.org/PerformingGroup block whose `event` array is already the exact
 * Event shape Eventbrite/Humanitix/Luma/RA land — so this connector maps
 * almost nothing and SchemaOrgEventProjector serves it unchanged.
 *
 * Shape verified live from the dev server 2026-08-28 against
 * dice.fm/artist/grouper-ebvw: {@type PerformingGroup, url, name, image,
 * event: [{@type Event, url, name, startDate, endDate, location{name,
 * address{addressLocality}}, image[], offers{lowPrice, priceCurrency}}]}.
 *
 * Bandsintown and Songkick were the other two candidates and both refuse a
 * server-side fetch outright (403/406, and Bandsintown's app_id API loophole
 * is closed) — DICE is the one artist-events source of the three that can be
 * read for free, which is why it is the only one with a connector.
 */
class DiceConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('dice'),
            identifierKind: 'slug',
            hosts: ['dice.fm', '*.dice.fm'],
            streams: [
                'events' => new StreamSpec(
                    name: 'events',
                    target: 'event',
                    profile: SourceProfile::Calendar,
                    requires: ['name', 'url'],
                    // DICE's imgix covers carry rect/crop params that vary per
                    // render; the event URL is the identity.
                    volatile: ['image?query'],
                    orderField: 'start_date',
                ),
            ],
            cost: CostClass::Free,
            defaultIntervalSeconds: 43200,
        );
    }

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable
    {
        $slug = trim($pull->identifier, "/ \t");
        if ($slug === '' || preg_match('#^[A-Za-z0-9-]{1,80}$#', $slug) !== 1) {
            yield new Unavailable('dice identifier is not an artist slug');

            return;
        }

        // A browser-shaped UA: dice.fm answers a plain GET, but the default
        // client string draws its bot wall (the same lesson the RA connector
        // recorded).
        $response = $io->get('https://dice.fm/artist/'.$slug, [
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml',
        ]);

        if ($response['status'] !== 200 || $response['body'] === '') {
            yield new Unavailable("dice page returned {$response['status']}", $response['status']);

            return;
        }

        $events = $this->performingGroupEvents($response['body']);
        if ($events === null) {
            yield new Unavailable('dice page carried no PerformingGroup event list — structure may have changed', $response['status']);

            return;
        }

        $items = [];
        foreach ($events as $event) {
            $item = is_array($event) ? $this->mapEvent($event) : null;
            if ($item !== null) {
                $items[] = $item;
            }
        }

        if ($items === []) {
            // An artist between tours is a real, empty state — a Note, no
            // coverage, so nothing already landed is tombstoned.
            yield new Note('empty_feed', 'No events on the DICE artist page');

            return;
        }

        foreach ($items as $item) {
            yield new Record('events', $item['event_id'], $item);
        }

        // The page lists the artist's UPCOMING dates only — a bounded window,
        // never their history. Prefix, keyed to the earliest start.
        $dates = array_filter(array_column($items, 'start_date'));
        yield new Covered('events', Coverage::prefix($dates === [] ? null : min($dates), count($items)));
    }

    /**
     * The PerformingGroup node's `event` list, or null when the page carries
     * no such block.
     *
     * @return list<mixed>|null
     */
    private function performingGroupEvents(string $body): ?array
    {
        if (! preg_match_all('~<script type="application/ld\+json"[^>]*>(.*?)</script>~s', $body, $m)) {
            return null;
        }

        foreach ($m[1] as $json) {
            $decoded = json_decode($json, true);
            if (! is_array($decoded)) {
                continue;
            }
            $nodes = is_array($decoded['@graph'] ?? null) ? $decoded['@graph'] : [$decoded];
            foreach ($nodes as $node) {
                if (is_array($node) && ($node['@type'] ?? null) === 'PerformingGroup' && is_array($node['event'] ?? null)) {
                    return array_values($node['event']);
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>|null
     */
    private function mapEvent(array $event): ?array
    {
        $url = is_string($event['url'] ?? null) ? trim($event['url']) : '';
        $name = is_string($event['name'] ?? null) ? trim($event['name']) : '';
        if ($url === '' || $name === '') {
            return null;
        }

        // The event id off the canonical URL — stable, unlike the title (a
        // festival renames its edition) and unlike the imgix cover.
        $eventId = preg_match('#/event/([A-Za-z0-9]+)#', $url, $mm) === 1 ? $mm[1] : sha1($url);

        $image = $event['image'] ?? null;
        $image = is_array($image) ? ($image[0] ?? null) : $image;

        $low = data_get($event, 'offers.lowPrice');

        return array_filter([
            'event_id' => $eventId,
            'name' => $name,
            'url' => $url,
            'start_date' => is_string($event['startDate'] ?? null) ? $event['startDate'] : null,
            'end_date' => is_string($event['endDate'] ?? null) ? $event['endDate'] : null,
            'venue' => is_string(data_get($event, 'location.name')) ? data_get($event, 'location.name') : null,
            'locality' => is_string(data_get($event, 'location.address.addressLocality'))
                ? data_get($event, 'location.address.addressLocality')
                : null,
            'image' => is_string($image) ? $image : null,
            'price_min' => is_numeric($low) ? (float) $low : null,
            'currency' => is_string(data_get($event, 'offers.priceCurrency')) ? data_get($event, 'offers.priceCurrency') : null,
        ], static fn ($v) => $v !== null);
    }
}
