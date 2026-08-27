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
 * T27b (owner, 2026-08-28): Mixcloud shows via the public keyless JSON API —
 * GET api.mixcloud.com/{username}/cloudcasts/?limit=100. Shape verified live
 * against NTSRadio 2026-08-28: {data: [{key, url, name, created_time,
 * audio_length, pictures{extra_large|large}, user{name, username}}],
 * paging{next}}. Free, same lane shape as ApplePodcastsConnector; lands the
 * `track` kind through the shared MusicTrackProjector contract so the listen
 * pool treats a DJ's shows exactly like any other track list.
 */
class MixcloudConnector implements Connector
{
    private const PAGE_LIMIT = 100;

    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('mixcloud'),
            identifierKind: 'username',
            hosts: ['api.mixcloud.com'],
            streams: [
                'tracks' => new StreamSpec(
                    name: 'tracks',
                    target: 'track',
                    profile: SourceProfile::Feed,
                    requires: ['title', 'url'],
                    // Thumbnail CDN urls can rotate query params; the key
                    // fields identify the show.
                    volatile: [],
                    orderField: 'published',
                ),
            ],
            cost: CostClass::Free,
            defaultIntervalSeconds: 43200,
        );
    }

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable
    {
        $username = trim($pull->identifier, "/ \t");
        if ($username === '') {
            yield new Unavailable('empty mixcloud username');

            return;
        }

        $response = $io->get('https://api.mixcloud.com/'.rawurlencode($username).'/cloudcasts/?limit='.self::PAGE_LIMIT);

        if ($response['status'] !== 200 || $response['body'] === '') {
            // A failed fetch is UNAVAILABLE, never "no shows" — absence
            // folding must not read an outage as a deleted catalogue (C5).
            yield new Unavailable("mixcloud api returned {$response['status']}", $response['status']);

            return;
        }

        $decoded = json_decode($response['body'], true);
        $rows = is_array($decoded) ? ($decoded['data'] ?? null) : null;
        if (! is_array($rows)) {
            yield new Unavailable('mixcloud api did not decode to the expected data[] shape', $response['status']);

            return;
        }

        $items = [];
        foreach ($rows as $row) {
            $item = is_array($row) ? $this->mapCloudcast($row) : null;
            if ($item !== null) {
                $items[] = $item;
            }
        }

        if ($items === []) {
            yield new Note('empty_feed', 'No cloudcasts parsed from the Mixcloud response');

            return;
        }

        foreach ($items as $item) {
            yield new Record('tracks', $item['id'], $item);
        }

        $hasMore = is_string(data_get($decoded, 'paging.next'));
        $dates = array_filter(array_column($items, 'published'));
        yield new Covered('tracks', $hasMore
            ? Coverage::prefix($dates === [] ? null : min($dates), count($items))
            : Coverage::exhaustive());
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function mapCloudcast(array $row): ?array
    {
        $key = is_string($row['key'] ?? null) ? trim($row['key']) : '';
        $name = is_string($row['name'] ?? null) ? trim($row['name']) : '';
        $url = is_string($row['url'] ?? null) ? trim($row['url']) : '';
        if ($key === '' || $name === '' || $url === '') {
            return null;
        }

        $seconds = is_numeric($row['audio_length'] ?? null) ? (int) $row['audio_length'] : null;

        return array_filter([
            'id' => $key,
            'title' => $name,
            'url' => $url,
            'published' => is_string($row['created_time'] ?? null) ? $row['created_time'] : null,
            'duration_seconds' => $seconds !== null && $seconds > 0 ? $seconds : null,
            'artist' => is_string(data_get($row, 'user.name')) ? data_get($row, 'user.name') : null,
            'artwork' => is_string(data_get($row, 'pictures.extra_large'))
                ? data_get($row, 'pictures.extra_large')
                : (is_string(data_get($row, 'pictures.large')) ? data_get($row, 'pictures.large') : null),
        ], static fn ($v) => $v !== null);
    }
}
