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
 * Apple Podcasts episode list via the same keyless iTunes Lookup API
 * AppleMusicConnector uses (GET
 * itunes.apple.com/lookup?id={podcastId}&entity=podcastEpisode&limit=200) —
 * the endpoint AppleSearch::fetchEpisodes() already calls for the
 * connect-time "latest episode" widget, generalised to a 200-row catalogue
 * pull.
 *
 * `results[0]` is always the show itself (`wrapperType` = `track`, `kind` =
 * `podcast`), never an episode; real episodes carry `kind` =
 * `podcast-episode`. Selecting on `kind` (as this task specifies) rather than
 * skipping index 0 mirrors AppleSearch's own positionless filter
 * (`wrapperType === 'podcastEpisode'` there — the two fields co-occur on
 * every episode row) and naturally excludes the show wrapper without
 * assuming it is always first.
 *
 * Same 200-row cap semantics as AppleMusicConnector (see that class's
 * docblock for the "`limit` counts the wrapper row too" evidence, from
 * AppleSearch::fetchEpisodes()'s own `$limit + 1` comment): a `resultCount`
 * at the requested cap means a prefix, never exhaustive.
 */
class ApplePodcastsConnector implements Connector
{
    private const LOOKUP_LIMIT = 200;

    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('apple_podcasts'),
            identifierKind: 'podcast_id',
            hosts: ['itunes.apple.com', '*.mzstatic.com'],
            streams: [
                'listen' => new StreamSpec(
                    name: 'listen',
                    target: 'episode',
                    profile: SourceProfile::Feed,
                    // An episode with no id/name is not renderable; landing
                    // it would poison every projection downstream.
                    requires: ['trackId', 'trackName'],
                    // Artwork URLs can carry a query string on the mzstatic
                    // CDN; stripping it keeps an unchanged episode from
                    // looking like a content change every run.
                    volatile: ['artworkUrl600?query'],
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
        $podcastId = trim($pull->identifier);
        $response = $io->get('https://itunes.apple.com/lookup?'.http_build_query([
            'id' => $podcastId,
            'entity' => 'podcastEpisode',
            'limit' => self::LOOKUP_LIMIT,
        ]));

        if ($response['status'] !== 200 || $response['body'] === '') {
            // A failed fetch is UNAVAILABLE, never "the show has no
            // episodes" — emitting nothing here would let absence-folding
            // conclude the whole back catalogue was deleted (C5).
            yield new Unavailable("lookup returned {$response['status']}", $response['status']);

            return;
        }

        $decoded = json_decode($response['body'], true);
        if (! is_array($decoded) || ! is_array($decoded['results'] ?? null)) {
            // A 200 that isn't the expected resultCount/results shape (an
            // HTML error/interstitial page, most likely) is a shape break,
            // not an empty feed.
            yield new Unavailable('lookup did not decode to the expected resultCount/results shape', $response['status']);

            return;
        }

        $results = $decoded['results'];
        $resultCount = is_numeric($decoded['resultCount'] ?? null) ? (int) $decoded['resultCount'] : count($results);

        if ($resultCount === 0) {
            // Zero results means the podcast id itself no longer resolves —
            // indistinguishable from "no episodes" at this endpoint, and
            // both must be UNAVAILABLE, never an empty feed.
            yield new Unavailable('lookup returned resultCount=0', $response['status']);

            return;
        }

        $items = [];
        foreach ($results as $result) {
            if (! is_array($result) || ($result['kind'] ?? null) !== 'podcast-episode') {
                continue;
            }
            $item = $this->mapEpisode($result);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        if ($items === []) {
            yield new Note('empty_feed', 'No episodes parsed from the iTunes lookup response');

            return;
        }

        foreach ($items as $item) {
            yield new Record('listen', $item['trackId'], $item);
        }

        $dates = array_filter(array_column($items, 'releaseDate'));
        yield new Covered('listen', $resultCount >= self::LOOKUP_LIMIT
            ? Coverage::prefix($dates === [] ? null : min($dates), count($items))
            : Coverage::exhaustive());
    }

    /** @param  array<string, mixed>  $result
     * @return array<string, mixed>|null */
    private function mapEpisode(array $result): ?array
    {
        $trackId = $result['trackId'] ?? null;
        $trackName = is_string($result['trackName'] ?? null) ? trim($result['trackName']) : '';

        if ($trackId === null || $trackId === '' || $trackName === '') {
            return null;
        }

        return [
            'trackId' => (string) $trackId,
            'trackName' => $trackName,
            'collectionName' => is_string($result['collectionName'] ?? null) ? $result['collectionName'] : null,
            'releaseDate' => is_string($result['releaseDate'] ?? null) ? $result['releaseDate'] : null,
            'artworkUrl600' => is_string($result['artworkUrl600'] ?? null) ? $result['artworkUrl600'] : null,
            'trackViewUrl' => is_string($result['trackViewUrl'] ?? null) ? $result['trackViewUrl'] : null,
            'description' => is_string($result['description'] ?? null)
                ? $result['description']
                : (is_string($result['shortDescription'] ?? null) ? $result['shortDescription'] : null),
        ];
    }
}
