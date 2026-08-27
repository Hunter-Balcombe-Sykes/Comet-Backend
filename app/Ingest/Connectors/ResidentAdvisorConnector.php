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
 * T27b (owner, 2026-08-28): Resident Advisor artist events via ra.co's own
 * public GraphQL — the HTML pages 403 behind bot protection but the GraphQL
 * endpoint answers a plain POST (verified live from the dev server
 * 2026-08-28: benbohmer → artist 53404 → his real tour list). Query shape
 * discovered against the live schema:
 * artist(slug){events(type: LATEST, limit)} → {id, title, contentUrl,
 * startTime, venue{name, area{name}}, flyerFront}. Lands the shared
 * schema.org event doc, projected by SchemaOrgEventProjector like
 * Eventbrite/Humanitix/Luma.
 */
class ResidentAdvisorConnector implements Connector
{
    private const EVENT_LIMIT = 40;

    private const QUERY = <<<'GQL'
    query PartnaArtistEvents($slug: String!, $limit: Int!) {
      artist(slug: $slug) {
        id
        name
        events(type: LATEST, limit: $limit) {
          id
          title
          contentUrl
          startTime
          endTime
          venue { name area { name } }
          flyerFront
        }
      }
    }
    GQL;

    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('resident_advisor'),
            identifierKind: 'slug',
            hosts: ['ra.co'],
            streams: [
                'events' => new StreamSpec(
                    name: 'events',
                    target: 'event',
                    profile: SourceProfile::Calendar,
                    requires: ['name', 'url'],
                    volatile: [],
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
        if ($slug === '') {
            yield new Unavailable('empty resident advisor artist slug');

            return;
        }

        $response = $io->post('https://ra.co/graphql', [
            'operationName' => 'PartnaArtistEvents',
            'variables' => ['slug' => $slug, 'limit' => self::EVENT_LIMIT],
            'query' => self::QUERY,
        ], [
            'Content-Type' => 'application/json',
            // The endpoint answers plain requests but expects browser-shaped
            // headers; verified working from the dev server 2026-08-28.
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
            'Referer' => 'https://ra.co/',
        ]);

        if ($response['status'] !== 200 || $response['body'] === '') {
            yield new Unavailable("ra.co graphql returned {$response['status']}", $response['status']);

            return;
        }

        $decoded = json_decode($response['body'], true);
        if (! is_array($decoded) || isset($decoded['errors'])) {
            yield new Unavailable('ra.co graphql rejected the query — the schema may have rotated', $response['status']);

            return;
        }

        $artist = data_get($decoded, 'data.artist');
        if (! is_array($artist)) {
            // A null artist means the slug no longer resolves — indistinct
            // from a deleted profile; UNAVAILABLE, never an empty calendar.
            yield new Unavailable('ra.co artist slug did not resolve', $response['status']);

            return;
        }

        $rows = is_array($artist['events'] ?? null) ? $artist['events'] : [];
        $items = [];
        foreach ($rows as $row) {
            $item = is_array($row) ? $this->mapEvent($row) : null;
            if ($item !== null) {
                $items[] = $item;
            }
        }

        if ($items === []) {
            yield new Note('empty_feed', 'Artist has no listed events');

            return;
        }

        foreach ($items as $item) {
            yield new Record('events', $item['ra_id'], $item);
        }

        // LATEST is a bounded window, never the archive — prefix always.
        $dates = array_filter(array_column($items, 'start_date'));
        yield new Covered('events', Coverage::prefix($dates === [] ? null : min($dates), count($items)));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function mapEvent(array $row): ?array
    {
        $id = is_scalar($row['id'] ?? null) ? trim((string) $row['id']) : '';
        $title = is_string($row['title'] ?? null) ? trim($row['title']) : '';
        $path = is_string($row['contentUrl'] ?? null) ? trim($row['contentUrl']) : '';
        if ($id === '' || $title === '' || $path === '') {
            return null;
        }

        return array_filter([
            'ra_id' => $id,
            'name' => $title,
            'url' => 'https://ra.co'.(str_starts_with($path, '/') ? $path : '/'.$path),
            // Naive local timestamps (no offset on this schema) — verbatim,
            // same treatment the projector gives an offset-less string.
            'start_date' => is_string($row['startTime'] ?? null) ? $row['startTime'] : null,
            'end_date' => is_string($row['endTime'] ?? null) ? $row['endTime'] : null,
            'venue' => is_string(data_get($row, 'venue.name')) ? data_get($row, 'venue.name') : null,
            'locality' => is_string(data_get($row, 'venue.area.name')) ? data_get($row, 'venue.area.name') : null,
            'image' => is_string($row['flyerFront'] ?? null) ? $row['flyerFront'] : null,
        ], static fn ($v) => $v !== null);
    }
}
