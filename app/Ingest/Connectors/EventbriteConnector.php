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
use App\Ingest\Support\Html;
use App\Ingest\Support\SchemaOrgEvent;

/**
 * Eventbrite organiser pages (/o/<slug>-<id>), keyless — the official API is
 * own-account-only, so the JSON-LD scrape stays (plan §11). The organiser
 * page lists its event-detail links; each event page carries a schema.org
 * graph with name, dates (local offset), Place, AggregateOffer and image.
 *
 * Hosts are the ENUMERATED real Eventbrite TLDs: an open-ended glob would
 * re-open the spoofable-host hole §17 closed (`eventbrite.<attacker>` passing
 * as Eventbrite), so every regional host is spelled out.
 *
 * Coverage is exhaustive only when every harvested event link was fetched
 * and parsed; a truncated batch or any failed detail fetch degrades to
 * unknown so absence-folding can never delete an event we simply did not
 * look at (C5). Past events falling off the organiser page are a display
 * question (Calendar profile), not a deletion question.
 */
class EventbriteConnector implements Connector
{
    private const TLDS = [
        'com', 'com.au', 'co.uk', 'co.nz', 'ca', 'de', 'fr', 'es', 'it', 'nl',
        'pt', 'ie', 'at', 'ch', 'dk', 'fi', 'se', 'be', 'sg', 'hk',
        'com.br', 'com.mx', 'com.ar', 'com.pe', 'cl',
    ];

    /** Detail pages fetched per run — bounds one run's fan-out. */
    private const MAX_EVENT_PAGES = 20;

    public static function manifest(): Manifest
    {
        $hosts = [];
        foreach (self::TLDS as $tld) {
            $hosts[] = "eventbrite.{$tld}";
            $hosts[] = "www.eventbrite.{$tld}";
        }

        return new Manifest(
            source: SourceKey::of('eventbrite'),
            identifierKind: 'url',
            hosts: $hosts,
            streams: [
                'events' => new StreamSpec(
                    name: 'events',
                    target: 'event',
                    profile: SourceProfile::Calendar,
                    // An event we cannot name or link to is not renderable.
                    requires: ['name', 'url'],
                    // Eventbrite image URLs rotate signed CDN params.
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
        $orgUrl = rtrim(trim($pull->identifier), '/');
        $page = $io->get($orgUrl);

        if ($page['status'] !== 200 || $page['body'] === '') {
            // A failed fetch is UNAVAILABLE, never "the organiser has no
            // events" — emitting nothing would let absence-folding conclude
            // every event was cancelled (C5).
            yield new Unavailable("organiser page returned {$page['status']}", $page['status']);

            return;
        }

        $tlds = '(?:'.implode('|', array_map(fn (string $t) => preg_quote($t, '~'), self::TLDS)).')';
        preg_match_all('~https://www\.eventbrite\.'.$tlds.'/e/[a-z0-9-]+~i', $page['body'], $m);
        $eventUrls = array_values(array_unique($m[0]));

        if ($eventUrls === []) {
            // Parsed cleanly but found nothing: a genuinely empty organiser or
            // a layout change. Either way not evidence of deletion — no
            // Coverage, so nothing can be tombstoned.
            yield new Note('no_events', 'No event links found on the organiser page');

            return;
        }

        $limit = $pull->scopeLimit() ?? self::MAX_EVENT_PAGES;
        $truncated = count($eventUrls) > $limit;
        $batch = array_slice($eventUrls, 0, $limit);

        $responses = $io->getMany($batch);

        $items = [];
        $failed = false;
        foreach ($batch as $url) {
            $response = $responses[$url] ?? null;
            if ($response === null || $response['status'] !== 200) {
                $failed = true;

                continue;
            }
            $item = $this->parseEventPage($response['body'], $url);
            if ($item === null) {
                // A detail page with no Event node is usually an expired
                // listing, not a break — but it still means we did not SEE
                // that event, so exhaustiveness is off the table.
                $failed = true;

                continue;
            }
            $items[] = $item;
        }

        if ($items === []) {
            yield new Note('no_events_parsed', 'No event pages yielded a schema.org Event node');

            return;
        }

        foreach ($items as $item) {
            yield new Record('events', $item['key'], $item['doc']);
        }

        // Only a run that saw EVERY listed event may let absence mean
        // cancellation; anything partial claims nothing.
        yield new Covered('events', (! $truncated && ! $failed)
            ? Coverage::exhaustive()
            : Coverage::unknown());
    }

    /** @return array{key: string, doc: array<string, mixed>}|null */
    private function parseEventPage(string $html, string $url): ?array
    {
        $eventNode = null;
        foreach (Html::jsonLdNodes($html) as $node) {
            // The event node is the one with a startDate — Eventbrite types
            // events as Festival/MusicEvent/etc. subtypes, so the field is a
            // steadier discriminator than @type.
            if (isset($node['startDate'])) {
                $eventNode = $node;
                break;
            }
        }
        if ($eventNode === null) {
            return null;
        }

        $doc = SchemaOrgEvent::map($eventNode, $this->normalizeLink($url));
        if ($doc === null) {
            return null;
        }

        $doc['url'] = $this->normalizeLink((string) $doc['url']);

        // The /e/<slug-id> path is Eventbrite's stable identifier — titles
        // and images change, this does not (host normalized to .com so a
        // regional-host rescrape lands on the same key).
        $path = trim((string) parse_url($doc['url'], PHP_URL_PATH), '/');

        return ['key' => $path !== '' ? $path : $doc['url'], 'doc' => $doc];
    }

    /**
     * Rewrite regional Eventbrite hosts to www.eventbrite.com — Eventbrite
     * 301s back to the regional URL server-side, and a single host keeps
     * record keys stable and iOS Universal-Link interception out of shares.
     */
    private function normalizeLink(string $url): string
    {
        $tlds = '(?:'.implode('|', array_map(fn (string $t) => preg_quote($t, '~'), self::TLDS)).')';

        return preg_replace('~^(https?://)(?:www\.)?eventbrite\.'.$tlds.'(/.*)$~i', '$1www.eventbrite.com$2', $url) ?? $url;
    }
}
