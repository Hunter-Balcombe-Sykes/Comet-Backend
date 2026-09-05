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
            // The public oEmbed endpoint only (refreshCovers) — the feed
            // itself is the billed actor effect.
            hosts: ['www.tiktok.com'],
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

        $items = $this->refreshCovers($items, $io);

        foreach ($items as $item) {
            yield new Record('videos', $item['id'], $item);
        }

        // The feed is only ever the recent window — a prefix down to the
        // oldest video actually seen, never the whole account (C5).
        $dates = array_filter(array_column($items, 'created_at'));
        yield new Covered('videos', Coverage::prefix($dates === [] ? null : min($dates), count($items)));
    }

    /**
     * A cover the mirror can actually serve (2026-09-05, st_ali retest #3:
     * 25 of 30 TikTok cards drew the video glyph). The vendor feed hands
     * back two kinds of unusable cover — a `.heic` render (an ISO-BMFF body
     * MediaMirror rightly refuses for an image role, and no browser but
     * Safari draws) and a signature that had ALREADY expired when the feed
     * was served (24 of the 30 carried `x-expires` at that day's midnight;
     * the vendor caches). TikTok's public oEmbed answers with a fresh,
     * plain JPEG for the video's own URL, one GET per video, pooled.
     * Best-effort: a refresh that fails leaves the vendor cover in place,
     * which is exactly what shipped before.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function refreshCovers(array $items, Io $io): array
    {
        $oembedUrls = [];
        foreach ($items as $index => $item) {
            if (self::coverNeedsRefresh($item['cover'] ?? null) && is_string($item['url'] ?? null)) {
                $oembedUrls[$index] = 'https://www.tiktok.com/oembed?url='.rawurlencode($item['url']);
            }
        }
        if ($oembedUrls === []) {
            return $items;
        }

        try {
            $responses = $io->getMany(array_values(array_unique($oembedUrls)), ['Accept' => 'application/json']);
        } catch (\Throwable $e) {
            report($e);

            return $items;
        }

        foreach ($oembedUrls as $index => $oembedUrl) {
            $response = $responses[$oembedUrl] ?? null;
            if ($response === null || $response['status'] !== 200) {
                continue;
            }
            $decoded = json_decode((string) $response['body'], true);
            $thumbnail = is_array($decoded) && is_string($decoded['thumbnail_url'] ?? null) ? trim($decoded['thumbnail_url']) : '';
            if ($thumbnail !== '' && ! self::coverNeedsRefresh($thumbnail)) {
                $items[$index]['cover'] = $thumbnail;
            }
        }

        return $items;
    }

    private static function coverNeedsRefresh(mixed $cover): bool
    {
        if (! is_string($cover) || $cover === '') {
            return true;
        }
        if (preg_match('/\.heic(?:$|\?)/i', $cover) === 1) {
            return true;
        }
        parse_str((string) parse_url($cover, PHP_URL_QUERY), $query);
        $expires = is_numeric($query['x-expires'] ?? null) ? (int) $query['x-expires'] : null;

        // An hour of slack: the mirror runs on a queue, not in this request.
        return $expires !== null && $expires < now()->getTimestamp() + 3600;
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
