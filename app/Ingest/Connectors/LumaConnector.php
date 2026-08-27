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
 * T27b (owner, 2026-08-28): Luma calendars via the public page's
 * __NEXT_DATA__ — props.pageProps.initialData.data.events[].event carries
 * {api_id, name, url (slug), start_at/end_at (UTC Z), cover_url,
 * geo_address_info{city}}. Shape verified live against lu.ma/sf 2026-08-28.
 * Emits the SAME schema.org event doc shape Eventbrite/Humanitix land, so
 * SchemaOrgEventProjector serves all three — one shape, one projector.
 */
class LumaConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('luma'),
            identifierKind: 'slug',
            hosts: ['lu.ma', '*.lu.ma'],
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
            yield new Unavailable('empty luma slug');

            return;
        }

        $response = $io->get('https://lu.ma/'.str_replace('%2F', '/', rawurlencode($slug)));
        if ($response['status'] !== 200 || $response['body'] === '') {
            yield new Unavailable("luma page returned {$response['status']}", $response['status']);

            return;
        }

        if (! preg_match('~<script id="__NEXT_DATA__"[^>]*>(.+?)</script>~s', $response['body'], $m)) {
            yield new Unavailable('luma page carried no __NEXT_DATA__ — structure may have changed', $response['status']);

            return;
        }

        $decoded = json_decode($m[1], true);
        $rows = is_array($decoded) ? data_get($decoded, 'props.pageProps.initialData.data.events') : null;
        if (! is_array($rows)) {
            yield new Unavailable('luma __NEXT_DATA__ carried no initialData.data.events — structure may have changed', $response['status']);

            return;
        }

        $items = [];
        foreach ($rows as $row) {
            $item = is_array($row) ? $this->mapEvent($row) : null;
            if ($item !== null) {
                $items[] = $item;
            }
        }

        if ($items === []) {
            yield new Note('empty_feed', 'No events parsed from the Luma page');

            return;
        }

        foreach ($items as $item) {
            yield new Record('events', $item['api_id'], $item);
        }

        // The page serves the calendar's UPCOMING window — a bounded feed,
        // never the whole history. Prefix, keyed to the earliest start.
        $dates = array_filter(array_column($items, 'start_date'));
        yield new Covered('events', Coverage::prefix($dates === [] ? null : min($dates), count($items)));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function mapEvent(array $row): ?array
    {
        $event = is_array($row['event'] ?? null) ? $row['event'] : $row;
        $apiId = is_string($event['api_id'] ?? null) ? trim($event['api_id']) : '';
        $name = is_string($event['name'] ?? null) ? trim($event['name']) : '';
        $slug = is_string($event['url'] ?? null) ? trim($event['url'], '/ ') : '';
        if ($apiId === '' || $name === '' || $slug === '') {
            return null;
        }

        return array_filter([
            'api_id' => $apiId,
            'name' => $name,
            'url' => 'https://lu.ma/'.$slug,
            'start_date' => is_string($event['start_at'] ?? null) ? $event['start_at'] : null,
            'end_date' => is_string($event['end_at'] ?? null) ? $event['end_at'] : null,
            'locality' => is_string(data_get($event, 'geo_address_info.city')) ? data_get($event, 'geo_address_info.city') : null,
            'image' => is_string($event['cover_url'] ?? null) ? $event['cover_url'] : null,
        ], static fn ($v) => $v !== null);
    }
}
