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
 * Bluesky via the ScrapeCreators vendor lane (Item 10b, 2026-09-01) — the
 * Pinterest substitution of the Instagram pattern: the billed effect is
 * ('vendor', 'bluesky') on BlueskyVendorDriver, because bluesky has no Apify
 * actor at all. CostClass::Actor still applies — "third-party billed per
 * invocation" is what keeps this off the scheduler by construction
 * (auto_sync=false at provisioning), and eagerOnConnect is the ONE trigger,
 * exactly the InstagramConnector contract. The daily ceiling is
 * ScrapeCreatorsBudget's 'bluesky' source cap, claimed per call inside the
 * driver — along with the EXACT-ACCOUNT validation (squatter handles answer
 * successfully; the driver refuses any profile that is not the account it
 * asked for, and filters the feed by the validated did). `hosts` is empty
 * because nothing here fetches Bluesky over HTTP.
 *
 * One stream off the vendor rows:
 *   - `posts` (Feed → media pool): the account's own authored posts that
 *     carry imagery — image embeds, or a video's poster frame. Text-only
 *     posts are dropped HERE, not in the driver: they are real vendor truth
 *     (the driver must answer them so an all-text account settles as
 *     answered, not as a retryable miss), but they hold no frame the media
 *     pool could serve. The pinned post rides first in the vendor feed OUT
 *     of chronological order, so items re-sort by createdAt before landing —
 *     the Instagram pinned-post rule verbatim. cdn.bsky.app URLs are
 *     unsigned and stable — no volatile entries — but the pool still mirrors
 *     bytes to R2 under `bluesky:` refs (owned-bytes policy), never
 *     hot-links.
 */
class BlueskyConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('bluesky'),
            identifierKind: 'handle',
            hosts: [],
            streams: [
                'posts' => new StreamSpec(
                    name: 'posts',
                    target: 'media',
                    profile: SourceProfile::Feed,
                    requires: ['id'],
                    orderField: 'createdAt',
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
        // Handles are domains (or did:plc ids) — lowercase is canonical, and
        // the @ prefix is chrome someone pasted, never identity.
        $handle = strtolower(ltrim(trim($pull->identifier), '@'));

        $effect = $io->effect('vendor', 'bluesky', ['handle' => $handle]);

        if (($effect['status'] ?? null) !== 'ok') {
            // A refused/abandoned/failed ledger verdict is the budget doing
            // its job, not a crash — same fold as an unreachable vendor.
            yield new Unavailable("bluesky vendor effect returned status '{$effect['status']}'");

            return;
        }

        $items = [];
        foreach ((array) $effect['data'] as $row) {
            $item = is_array($row) ? $this->mapPost($row) : null;
            if ($item !== null) {
                $items[] = $item;
            }
        }

        if ($items === []) {
            yield new Note('no_media_posts', 'No imagery-bearing posts parsed from the vendor result');

            return;
        }

        // The vendor feed leads with the pinned post regardless of age —
        // order by recency, not array order, so a pinned-but-old post cannot
        // claim the top of the feed (the Instagram rule).
        usort($items, static fn (array $a, array $b) => strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? '')));

        $limit = $pull->scopeLimit();
        if ($limit !== null) {
            $items = array_slice($items, 0, $limit);
        }

        foreach ($items as $item) {
            yield new Record('posts', $item['id'], $item);
        }

        // The feed is only ever the recent window — a prefix down to the
        // oldest post actually seen, never the whole account (C5).
        $dates = array_filter(array_column($items, 'createdAt'));
        yield new Covered('posts', Coverage::prefix($dates === [] ? null : min($dates), count($items)));
    }

    /** @return array<string, mixed>|null */
    private function mapPost(array $row): ?array
    {
        $id = is_string($row['id'] ?? null) ? trim($row['id']) : '';
        if ($id === '') {
            return null;
        }

        $images = [];
        foreach (is_array($row['images'] ?? null) ? $row['images'] : [] as $image) {
            if (is_array($image) && is_string($image['url'] ?? null) && $image['url'] !== '') {
                $images[] = $image;
            }
        }

        $video = is_array($row['video'] ?? null) ? $row['video'] : null;
        $poster = is_string($video['thumbnail'] ?? null) && $video['thumbnail'] !== '' ? $video['thumbnail'] : null;

        // A post with no frame is not a media CANDIDATE — the text-only
        // pinned intro every account seems to carry stays on bsky.app.
        if ($images === [] && $poster === null) {
            return null;
        }

        return array_filter([
            'id' => $id,
            'uri' => is_string($row['uri'] ?? null) ? $row['uri'] : null,
            'url' => is_string($row['url'] ?? null) ? $row['url'] : null,
            'text' => is_string($row['text'] ?? null) ? $row['text'] : null,
            'createdAt' => is_string($row['createdAt'] ?? null) ? $row['createdAt'] : null,
            'isVideo' => $video !== null,
            'images' => $images === [] ? null : $images,
            'video' => $video,
        ], static fn ($v) => $v !== null);
    }
}
