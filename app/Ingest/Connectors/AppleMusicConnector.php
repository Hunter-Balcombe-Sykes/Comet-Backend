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
 * Apple Music discography via the keyless iTunes Lookup API (GET
 * itunes.apple.com/lookup?id={artistId}&entity=album&limit=200) — the same
 * unauthenticated endpoint AppleSearch::fetchAlbums() already calls for the
 * connect-time "latest release" widget, generalised from a 25-row preview to
 * a 200-row catalogue pull.
 *
 * `results[0]` is always the artist itself (`wrapperType` = `artist`), never
 * an album. This mirrors AppleSearch's own positionless filter
 * (`wrapperType === 'collection'`) rather than skipping a fixed index, since
 * that filter is what is actually proven against the live API in this
 * codebase.
 *
 * The `limit` parameter caps the TOTAL row count of `results`, wrapper
 * included — confirmed by AppleSearch::fetchEpisodes()'s own `$limit + 1`
 * ("+1 because the lookup also returns the podcast collection itself"),
 * which only makes sense if the wrapper eats one of the requested slots. So a
 * `resultCount` at (or past) the requested 200 means at least one older
 * album may be hiding beyond the cap: the only honest claim is a prefix down
 * to the oldest releaseDate actually seen, never exhaustive — getting this
 * wrong at the boundary would let a real 200th-and-older album get folded
 * away as "deleted" (C5), the same class of mistake VimeoConnector's 20-video
 * cap already guards against.
 *
 * NOTE (flagged per instructions): the exact "wrapper consumes one limit
 * slot" mechanics are inferred from AppleSearch's own comment, not from a
 * fresh live capture taken for this connector — it is grounded in existing,
 * already-shipped code rather than an independent guess, but has not been
 * re-verified here.
 */
class AppleMusicConnector implements Connector
{
    private const LOOKUP_LIMIT = 200;

    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('apple_music'),
            identifierKind: 'artist_id',
            hosts: ['itunes.apple.com', '*.mzstatic.com'],
            streams: [
                'listen' => new StreamSpec(
                    name: 'listen',
                    target: 'release',
                    profile: SourceProfile::Catalogue,
                    // An album with no id/name is not a release; landing it
                    // would poison every projection downstream.
                    requires: ['collectionId', 'collectionName'],
                    // Artwork URLs can carry a query string on the mzstatic
                    // CDN; stripping it keeps an unchanged album from looking
                    // like a content change every run.
                    volatile: ['artworkUrl100?query'],
                    orderField: 'releaseDate',
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
        $response = $io->get('https://itunes.apple.com/lookup?'.http_build_query([
            'id' => $artistId,
            'entity' => 'album',
            'limit' => self::LOOKUP_LIMIT,
        ]));

        if ($response['status'] !== 200 || $response['body'] === '') {
            // A failed fetch is UNAVAILABLE, never "the artist has no
            // albums" — emitting nothing here would let absence-folding
            // conclude the whole catalogue was deleted (C5).
            yield new Unavailable("lookup returned {$response['status']}", $response['status']);

            return;
        }

        $decoded = json_decode($response['body'], true);
        if (! is_array($decoded) || ! is_array($decoded['results'] ?? null)) {
            // A 200 that isn't the expected resultCount/results shape (an
            // HTML error/interstitial page, most likely) is a shape break,
            // not an empty catalogue.
            yield new Unavailable('lookup did not decode to the expected resultCount/results shape', $response['status']);

            return;
        }

        $results = $decoded['results'];
        $resultCount = is_numeric($decoded['resultCount'] ?? null) ? (int) $decoded['resultCount'] : count($results);

        if ($resultCount === 0) {
            // Zero results means the artist id itself no longer resolves —
            // indistinguishable from "no albums" at this endpoint, and both
            // must be UNAVAILABLE, never an empty catalogue.
            yield new Unavailable('lookup returned resultCount=0', $response['status']);

            return;
        }

        $items = [];
        foreach ($results as $result) {
            if (! is_array($result) || ($result['wrapperType'] ?? null) !== 'collection') {
                continue;
            }
            $item = $this->mapAlbum($result);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        if ($items === []) {
            yield new Note('empty_catalogue', 'No albums parsed from the iTunes lookup response');

            return;
        }

        foreach ($items as $item) {
            yield new Record('listen', $item['collectionId'], $item);
        }

        $dates = array_filter(array_column($items, 'releaseDate'));
        yield new Covered('listen', $resultCount >= self::LOOKUP_LIMIT
            ? Coverage::prefix($dates === [] ? null : min($dates), count($items))
            : Coverage::exhaustive());
    }

    /** @param  array<string, mixed>  $result
     * @return array<string, mixed>|null */
    private function mapAlbum(array $result): ?array
    {
        $collectionId = $result['collectionId'] ?? null;
        $collectionName = is_string($result['collectionName'] ?? null) ? trim($result['collectionName']) : '';

        if ($collectionId === null || $collectionId === '' || $collectionName === '') {
            return null;
        }

        return [
            'collectionId' => (string) $collectionId,
            'collectionName' => $collectionName,
            'artistName' => is_string($result['artistName'] ?? null) ? $result['artistName'] : null,
            'releaseDate' => is_string($result['releaseDate'] ?? null) ? $result['releaseDate'] : null,
            'artworkUrl100' => is_string($result['artworkUrl100'] ?? null) ? $result['artworkUrl100'] : null,
            'collectionViewUrl' => is_string($result['collectionViewUrl'] ?? null) ? $result['collectionViewUrl'] : null,
            'trackCount' => is_numeric($result['trackCount'] ?? null) ? (int) $result['trackCount'] : null,
            'primaryGenreName' => is_string($result['primaryGenreName'] ?? null) ? $result['primaryGenreName'] : null,
        ];
    }
}
