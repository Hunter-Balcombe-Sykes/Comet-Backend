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
 * Spotify podcast episodes via the ScrapeCreators vendor lane (Item 11f,
 * 2026-09-01) — the Pinterest pattern beside apple_podcasts: the billed
 * effect is ('vendor', 'spotify_podcasts') on SpotifyPodcastsVendorDriver,
 * because there is no Apify actor behind it. CostClass::Actor keeps this off
 * the scheduler by construction (auto_sync=false at provisioning), and
 * eagerOnConnect is the ONE trigger — exactly the InstagramConnector
 * contract. The daily ceiling is ScrapeCreatorsBudget's 'spotify_podcasts'
 * source cap, claimed per call inside the driver. `hosts` is empty because
 * nothing here fetches Spotify over HTTP.
 *
 * One stream off the vendor rows:
 *   - `listen` (Feed → episode): the show's newest episodes, in the EXACT
 *     vocabulary ApplePodcastsConnector lands (trackId/trackName/
 *     collectionName/releaseDate/artworkUrl600/trackViewUrl/description) —
 *     SpotifyEpisodesNormalizer synthesizes it, and the listen pool reuses
 *     ApplePodcastsEpisodeProjector: one projector, two sources, no new pool
 *     semantics. No volatile entries, unlike apple's mzstatic art: i.scdn.co
 *     covers are unsigned and trackViewUrl is derived from the id (never the
 *     per-request ?si= share link), so an unchanged episode hashes unchanged.
 *
 * The identifier is the connection's own show URL, and only a /show/ URL
 * runs: the spotify.player surface's other account kinds (artist/playlist/
 * user) have no episodes, so a non-show identifier says so for free instead
 * of paying a vendor call for a husk (the SpotifyTracksConnector releases
 * guard, same reasoning).
 */
class SpotifyPodcastsConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('spotify_podcasts'),
            identifierKind: 'url',
            hosts: [],
            streams: [
                'listen' => new StreamSpec(
                    name: 'listen',
                    target: 'episode',
                    profile: SourceProfile::Feed,
                    // An episode with no id/name is not renderable; landing
                    // it would poison every projection downstream (the
                    // apple_podcasts rule, verbatim).
                    requires: ['trackId', 'trackName'],
                    volatile: [],
                    orderField: 'releaseDate',
                ),
            ],
            cost: CostClass::Actor,
            // Weekly, like the other billed feeds: shows release on episode
            // cadence and every run is a billed vendor call.
            defaultIntervalSeconds: 604800,
            // Owner ruling R8 (overnight 2026-08-18): paid sources get ONE
            // eager run at connect so the listen pool fills on day one.
            eagerOnConnect: true,
        );
    }

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable
    {
        // SpotifyConnect's intl-tolerant show grammar. Checked before the
        // billed effect — a non-show identifier is a free Note, never a paid
        // husk.
        if (! preg_match('~open\.spotify\.com/(?:intl-[a-z]{2}(?:-[a-z]{2})?/)?show/([A-Za-z0-9]+)~i', trim($pull->identifier), $m)) {
            yield new Note('not_a_show', 'Episodes are only listed for a show connection');

            return;
        }

        $effect = $io->effect('vendor', 'spotify_podcasts', ['show_id' => $m[1]]);

        if (($effect['status'] ?? null) !== 'ok') {
            // A refused/abandoned/failed ledger verdict is the budget doing
            // its job, not a crash — same fold as an unreachable vendor.
            yield new Unavailable("spotify_podcasts vendor effect returned status '{$effect['status']}'");

            return;
        }

        $items = [];
        foreach ((array) $effect['data'] as $row) {
            $item = is_array($row) ? $this->mapEpisode($row) : null;
            if ($item !== null) {
                $items[] = $item;
            }
        }

        if ($items === []) {
            yield new Note('no_episodes', 'No episodes parsed from the vendor result');

            return;
        }

        // The vendor answers newest-first, but order by releaseDate anyway so
        // a re-ordered vendor page cannot re-shuffle the feed (the Instagram
        // pinned-post rule); dateless rows sink to the bottom.
        usort($items, static fn (array $a, array $b) => strcmp((string) ($b['releaseDate'] ?? ''), (string) ($a['releaseDate'] ?? '')));

        $limit = $pull->scopeLimit();
        if ($limit !== null) {
            $items = array_slice($items, 0, $limit);
        }

        foreach ($items as $item) {
            yield new Record('listen', $item['trackId'], $item);
        }

        // One newest-first page is only ever the recent window — a prefix
        // down to the oldest episode actually seen, never the whole back
        // catalogue (C5).
        $dates = array_filter(array_column($items, 'releaseDate'));
        yield new Covered('listen', Coverage::prefix($dates === [] ? null : min($dates), count($items)));
    }

    /**
     * Re-assert the apple-podcasts listen vocabulary on each driver row —
     * the driver normalized already, but a landed doc's shape is this
     * connector's claim, so it never passes rows through on trust (the
     * Pinterest mapPin discipline).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function mapEpisode(array $row): ?array
    {
        $trackId = is_string($row['trackId'] ?? null) ? trim($row['trackId']) : '';
        $trackName = is_string($row['trackName'] ?? null) ? trim($row['trackName']) : '';
        if ($trackId === '' || $trackName === '') {
            return null;
        }

        return [
            'trackId' => $trackId,
            'trackName' => $trackName,
            'collectionName' => is_string($row['collectionName'] ?? null) ? $row['collectionName'] : null,
            'releaseDate' => is_string($row['releaseDate'] ?? null) ? $row['releaseDate'] : null,
            'artworkUrl600' => is_string($row['artworkUrl600'] ?? null) ? $row['artworkUrl600'] : null,
            'trackViewUrl' => is_string($row['trackViewUrl'] ?? null)
                ? $row['trackViewUrl']
                : 'https://open.spotify.com/episode/'.$trackId,
            'description' => is_string($row['description'] ?? null) ? $row['description'] : null,
        ];
    }
}
