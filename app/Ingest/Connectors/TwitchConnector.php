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
 * Twitch via the ScrapeCreators vendor lane (Item 10a, 2026-09-01) — the
 * platform's upgrade from link-only to a synced watch-pool source, on the
 * Pinterest frame: the billed effect is ('vendor', 'twitch') on
 * TwitchVendorDriver, there is no Apify actor behind it, and CostClass::Actor
 * ("third-party billed per invocation") keeps this off the scheduler by
 * construction (auto_sync=false at provisioning) with eagerOnConnect the ONE
 * trigger. The daily ceiling is ScrapeCreatorsBudget's 'twitch' source cap,
 * claimed inside the driver. `hosts` is empty because nothing here fetches
 * Twitch over HTTP. Identity (displayName/avatar/bio/isLive/socialLinks)
 * does NOT ride this lane — TwitchConnect writes it onto the connection
 * payload at connect time, and ProjectorRegistry's own docblock is the
 * standing warning against parking an identity stream in here.
 *
 * One stream off the vendor rows:
 *   - `watch` (Feed → the `video` item kind, YoutubeRss/Vimeo's pool): the
 *     channel's recent VODs, newest first. Feed + orderField 'published'
 *     because prefix domination is HONEST here — Twitch itself expires
 *     ARCHIVE VODs after 7-60 days, so a VOD that vanishes from the recent
 *     prefix is genuinely gone (its URL 404s), and keeping it published
 *     would card a dead link.
 *
 * Volatility, deliberately asymmetric against the normalizer's docblock:
 *   - `views` IS declared volatile — viewCount grows on every row, and
 *     without the exclusion every pull would read as a full content change.
 *     No projector reads it, which is what ingest:volatility-audit requires.
 *   - `duration` is NOT declared volatile, although the in-progress VOD's
 *     lengthSeconds grows across calls: the projector reads it (f_duration
 *     on the watch card), and the audit rightly fails any path that is both
 *     volatile and load-bearing. A live stream's VOD getting longer is a
 *     real content change, re-landed at most once per (rare, billed) pull.
 */
class TwitchConnector implements Connector
{
    /**
     * The watch window. The endpoint answers up to 100 VODs; the pool keeps
     * the recent shelf, mirroring YouTube's server-side RSS cap of 15.
     */
    private const LIMIT = 15;

    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('twitch'),
            identifierKind: 'username',
            hosts: [],
            streams: [
                'watch' => new StreamSpec(
                    name: 'watch',
                    target: 'video',
                    profile: SourceProfile::Feed,
                    requires: ['id', 'title', 'url'],
                    volatile: ['views'],
                    orderField: 'published',
                ),
            ],
            cost: CostClass::Actor,
            defaultIntervalSeconds: 604800,
            eagerOnConnect: true,
        );
    }

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable
    {
        $login = strtolower(ltrim(trim($pull->identifier), '@'));

        $effect = $io->effect('vendor', 'twitch', ['login' => $login]);

        if (($effect['status'] ?? null) !== 'ok') {
            // A refused/abandoned/failed ledger verdict is the budget doing
            // its job, not a crash — same fold as an unreachable vendor.
            yield new Unavailable("twitch vendor effect returned status '{$effect['status']}'");

            return;
        }

        $items = [];
        foreach ((array) $effect['data'] as $row) {
            $item = is_array($row) ? $this->mapVideo($row) : null;
            if ($item !== null) {
                $items[] = $item;
            }
        }

        if ($items === []) {
            yield new Note('no_videos', 'No VODs parsed from the vendor result');

            return;
        }

        // The endpoint answers newest-first under sort_by=TIME, but ordering
        // is a contract, not an observation (the Instagram pinned-post rule):
        // re-sort by recency before the window cut so no stale row can claim
        // the top of the feed.
        usort($items, static fn (array $a, array $b) => strcmp((string) ($b['published'] ?? ''), (string) ($a['published'] ?? '')));

        $items = array_slice($items, 0, min(self::LIMIT, $pull->scopeLimit() ?? self::LIMIT));

        foreach ($items as $item) {
            yield new Record('watch', $item['id'], $item);
        }

        // Only ever the recent window — a prefix down to the oldest VOD
        // actually seen, never the whole channel (C5).
        $dates = array_filter(array_column($items, 'published'));
        yield new Covered('watch', Coverage::prefix($dates === [] ? null : min($dates), count($items)));
    }

    /** @return array<string, mixed>|null */
    private function mapVideo(array $row): ?array
    {
        $id = is_string($row['id'] ?? null) ? trim($row['id']) : '';
        $title = is_string($row['title'] ?? null) ? trim($row['title']) : '';
        $url = is_string($row['url'] ?? null) ? trim($row['url']) : '';
        if (preg_match('/^\d+$/', $id) !== 1 || $title === '' || $url === '') {
            // Un-renderable and un-linkable — the watch stream requires
            // id/title/url, so a row missing any is dropped, not landed.
            return null;
        }

        return array_filter([
            'id' => $id,
            'title' => $title,
            'url' => $url,
            'published' => is_string($row['published'] ?? null) ? $row['published'] : null,
            // Already null for the 404_processing placeholder — the
            // normalizer absorbed that quirk; nothing to re-check here.
            'thumbnail' => is_string($row['thumbnail'] ?? null) ? $row['thumbnail'] : null,
            'duration' => is_numeric($row['duration'] ?? null) && (int) $row['duration'] > 0 ? (int) $row['duration'] : null,
            'views' => is_numeric($row['views'] ?? null) ? (int) $row['views'] : null,
            'game' => is_string($row['game'] ?? null) && $row['game'] !== '' ? $row['game'] : null,
        ], static fn ($v) => $v !== null);
    }
}
