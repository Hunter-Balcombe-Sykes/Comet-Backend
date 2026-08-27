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
 * Deezer artist top tracks via the public keyless JSON API (wave 2,
 * 2026-08-28) — GET api.deezer.com/artist/{id}/top?limit=50. Shape verified
 * live from the dev server 2026-08-28: {data: [{id, title, link, duration,
 * album{title, cover_xl}, contributors[{name}]}]}; errors arrive as a 200
 * with an {error:{...}} body, so the error key is checked explicitly. Free,
 * the Mixcloud lane shape; lands `track` through the shared
 * MusicTrackProjector contract.
 *
 * "Top" is a vendor-curated set, not a dated feed — Catalogue profile with
 * a null orderField (the reviews precedent), and the preview mp3s are
 * signed-and-expiring so they are deliberately not landed.
 */
class DeezerTracksConnector implements Connector
{
    private const PAGE_LIMIT = 50;

    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('deezer'),
            identifierKind: 'id',
            hosts: ['api.deezer.com'],
            streams: [
                'tracks' => new StreamSpec(
                    name: 'tracks',
                    target: 'track',
                    profile: SourceProfile::Catalogue,
                    requires: ['title', 'url'],
                    volatile: [],
                    orderField: null,
                ),
            ],
            cost: CostClass::Free,
            defaultIntervalSeconds: 43200,
        );
    }

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable
    {
        $artistId = trim($pull->identifier);
        if (preg_match('/^\d{1,15}$/', $artistId) !== 1) {
            yield new Unavailable('deezer identifier is not a numeric artist id');

            return;
        }

        $response = $io->get('https://api.deezer.com/artist/'.$artistId.'/top?limit='.self::PAGE_LIMIT);

        if ($response['status'] !== 200 || $response['body'] === '') {
            yield new Unavailable("deezer api returned {$response['status']}", $response['status']);

            return;
        }

        $decoded = json_decode($response['body'], true);
        if (! is_array($decoded) || isset($decoded['error'])) {
            // Deezer answers a missing artist with 200 + {error:{…}} — an
            // Unavailable, never an emptied catalogue.
            yield new Unavailable('deezer api answered an error body', $response['status']);

            return;
        }

        $items = [];
        foreach ((array) ($decoded['data'] ?? []) as $row) {
            $item = is_array($row) ? $this->mapTrack($row) : null;
            if ($item !== null) {
                $items[] = $item;
            }
        }

        if ($items === []) {
            yield new Note('empty_feed', 'No tracks parsed from the Deezer response');

            return;
        }

        foreach ($items as $item) {
            yield new Record('tracks', $item['id'], $item);
        }

        yield new Covered('tracks', Coverage::exhaustive());
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function mapTrack(array $row): ?array
    {
        $id = is_numeric($row['id'] ?? null) ? (string) $row['id'] : '';
        $title = is_string($row['title'] ?? null) ? trim($row['title']) : '';
        $url = is_string($row['link'] ?? null) ? trim($row['link']) : '';
        if ($id === '' || $title === '' || $url === '') {
            return null;
        }

        $seconds = is_numeric($row['duration'] ?? null) ? (int) $row['duration'] : null;
        $artist = data_get($row, 'contributors.0.name') ?? data_get($row, 'artist.name');
        $artwork = data_get($row, 'album.cover_xl') ?? data_get($row, 'album.cover_big');
        $album = data_get($row, 'album.title');

        return array_filter([
            'id' => $id,
            'title' => $title,
            'url' => $url,
            'duration_seconds' => $seconds !== null && $seconds > 0 ? $seconds : null,
            'artist' => is_string($artist) ? $artist : null,
            'artwork' => is_string($artwork) ? $artwork : null,
            'album' => is_string($album) ? $album : null,
        ], static fn ($v) => $v !== null);
    }
}
