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
 * TikTok via the paid Apify profile actor (T27c, 2026-08-28) — the Instagram
 * pattern verbatim: CostClass::Actor keeps it off the scheduler by
 * construction, eagerOnConnect is the ONE trigger, ApifyBudget's per-actor +
 * global caps are the hard ceiling (claimed in SocialActorDriver), and
 * `hosts` is empty because nothing here fetches TikTok over HTTP.
 *
 * One stream off the actor result:
 *   - `videos` (Feed): the profile's recent videos, one `video` item each,
 *     re-sorted by createTime so a pinned-but-old video cannot masquerade as
 *     the newest (the actor lists pinned first, like Instagram's grid). A
 *     photo-mode post (isSlideshow) still lands — it has a cover, a URL and a
 *     date, which is all the watch card renders.
 *
 * Field shapes verified against a live clockworks~tiktok-profile-scraper
 * dataset (2026-08-28): id, text, createTimeISO, webVideoUrl,
 * videoMeta{coverUrl,duration}, isPinned. coverUrl carries a signed
 * `x-expires` query (≈1 week) — volatile for the hash, and the projector
 * hands it to MediaMirror under an owned `tiktok:` ref so the served bytes
 * outlive the signature.
 */
class TiktokConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('tiktok'),
            identifierKind: 'username',
            hosts: [],
            streams: [
                'videos' => new StreamSpec(
                    name: 'videos',
                    target: 'video',
                    profile: SourceProfile::Feed,
                    requires: ['id'],
                    volatile: ['cover?query'],
                    orderField: 'created_at',
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
        $username = strtolower(ltrim(trim($pull->identifier), '@'));

        $effect = $io->effect('actor', 'tiktok', ['username' => $username]);

        if (($effect['status'] ?? null) !== 'ok') {
            // A definite account verdict keeps its own name (O.2): the
            // writeback retires the connection on it, where a plain vendor
            // miss only leaves it pending for the next attempt.
            $reason = ($effect['reason'] ?? null) === 'account_deactivated'
                ? 'account_deactivated'
                : "tiktok actor effect returned status '{$effect['status']}'";
            yield new Unavailable($reason, $reason === 'account_deactivated' ? 404 : null);

            return;
        }

        $items = [];
        foreach ((array) $effect['data'] as $row) {
            $item = is_array($row) ? $this->mapVideo($row, $username) : null;
            if ($item !== null) {
                $items[] = $item;
            }
        }

        if ($items === []) {
            yield new Note('no_videos', 'No videos parsed from the actor result');

            return;
        }

        usort($items, static fn (array $a, array $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        $limit = $pull->scopeLimit();
        if ($limit !== null) {
            $items = array_slice($items, 0, $limit);
        }

        foreach ($items as $item) {
            yield new Record('videos', $item['id'], $item);
        }

        // The feed is only ever the recent window — a prefix down to the
        // oldest video actually seen, never the whole account (C5).
        $dates = array_filter(array_column($items, 'created_at'));
        yield new Covered('videos', Coverage::prefix($dates === [] ? null : min($dates), count($items)));
    }

    /** @return array<string, mixed>|null */
    private function mapVideo(array $row, string $username): ?array
    {
        $id = is_string($row['id'] ?? null) ? trim($row['id']) : '';
        if ($id === '' || preg_match('/^\d+$/', $id) !== 1) {
            return null;
        }

        $url = is_string($row['webVideoUrl'] ?? null) && trim($row['webVideoUrl']) !== ''
            ? trim($row['webVideoUrl'])
            : 'https://www.tiktok.com/@'.$username.'/video/'.$id;
        $meta = is_array($row['videoMeta'] ?? null) ? $row['videoMeta'] : [];
        $duration = is_numeric($meta['duration'] ?? null) ? (int) $meta['duration'] : null;

        return array_filter([
            'id' => $id,
            'caption' => is_string($row['text'] ?? null) && trim($row['text']) !== '' ? trim($row['text']) : null,
            'created_at' => is_string($row['createTimeISO'] ?? null) ? $row['createTimeISO'] : null,
            'url' => $url,
            'cover' => is_string($meta['coverUrl'] ?? null) ? $meta['coverUrl'] : null,
            'duration' => $duration !== null && $duration > 0 ? $duration : null,
        ], static fn ($v) => $v !== null);
    }
}
