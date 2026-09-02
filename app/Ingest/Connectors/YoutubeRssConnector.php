<?php

namespace App\Ingest\Connectors;

use App\Ingest\Landing\Coverage;
use App\Ingest\Manifest\CostClass;
use App\Ingest\Manifest\Manifest;
use App\Ingest\Manifest\SourceKey;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Manifest\StreamSpec;
use App\Ingest\Message\Bookmark;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Message;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Runtime\Connector;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;
use App\Ingest\Support\YoutubeFeed;
use App\Services\Platforms\YoutubeScraper;
use App\Services\Platforms\YoutubeThumbnailResolver;

/**
 * YouTube's keyless channel RSS (GET youtube.com/feeds/videos.xml?channel_id=)
 * — an Atom feed capped at the latest 15 uploads, newest first. Same honesty
 * requirement as Vimeo: only ever a prefix down to the oldest `published`
 * actually seen, never exhaustive (C5).
 *
 * Item 11c (2026-09-01): the channel's Shorts shelf blends into the SAME
 * `watch` stream, via YoutubeScraper::fetchShorts() — no new stream, no new
 * platform, because a short IS a video to every watch-pool reader (the shorts
 * normalizer's contract). The RSS lane is the backbone and stays byte-for-byte
 * as it was: the vendor call happens only after the feed has landed, and every
 * shorts failure mode (no key, budget, transport, billed husk) is a null that
 * blends nothing. Budget discipline lives inside fetchShorts() — its own
 * 'youtube_shorts' source cap, claim before the call, release on
 * transport-null, slot kept on a billed husk — never re-implemented here.
 * fetchShorts() reaches the vendor through the Http facade, not Io, exactly as
 * the thumbnail resolver below reaches i.ytimg.com: `hosts` stays the free
 * lane's own and never admits the vendor host to Io.
 *
 * The identifier may be a UC… channel id OR a bare handle: connections made
 * through the legacy connect flow store only `payload.handle`, and the feed
 * endpoint accepts nothing but the id. A handle is resolved once via the
 * channel page (the same `"channelId":"UC…"` capture YoutubeScraper has
 * proven in production) and cached in the stream cursor via Bookmark, so
 * later runs cost one request again.
 *
 * Feed parsing (incl. the XXE guard) lives in Support\YoutubeFeed, shared
 * with YoutubeMusicConnector — the YT Music releases strategy reads the same
 * uploads feed off the artist's auto-generated "- Topic" channel.
 */
class YoutubeRssConnector implements Connector
{
    // The feed's own media:thumbnail is hqdefault.jpg — 480×360 4:3, with black
    // letterbox bars BAKED IN for any 16:9 upload (visible the moment a surface
    // renders the image at its natural ratio, as the sitepage's item cards do).
    // YoutubeThumbnailResolver already exists for exactly this (the scraper lane
    // has used it since it was written): one batched HEAD probe per pull picks
    // maxresdefault.jpg (1280×720, true 16:9) where YouTube has generated it and
    // falls back to hqdefault otherwise. Resolved through the container, which is
    // how ConnectorRegistry builds every connector.
    public function __construct(
        private readonly YoutubeThumbnailResolver $thumbnails,
        private readonly YoutubeScraper $scraper,
    ) {}

    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('youtube'),
            identifierKind: 'channel_id',
            hosts: ['youtube.com', '*.youtube.com', '*.ytimg.com'],
            streams: [
                'watch' => new StreamSpec(
                    name: 'watch',
                    target: 'video',
                    profile: SourceProfile::Feed,
                    requires: ['id', 'title', 'url'],
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
        $identifier = trim($pull->identifier);
        $resolvedFresh = false;

        if ($this->isChannelId($identifier)) {
            $channelId = $identifier;
        } else {
            $cached = $pull->cursor['channel_id'] ?? null;
            $channelId = is_string($cached) && $this->isChannelId($cached)
                ? $cached
                : $this->resolveHandle($identifier, $io);
            $resolvedFresh = $channelId !== null && $channelId !== $cached;

            if ($channelId === null) {
                // No id means no feed URL at all — and a failed resolution is
                // UNAVAILABLE, never "the channel has no uploads" (C5).
                yield new Unavailable("could not resolve handle '{$identifier}' to a channel id");

                return;
            }
            // 2026-09-02: say what the handle resolved to, so the connection
            // can carry channelId (IngestStatusWriteback) and a second row for
            // the same channel — youtube.com/channel/UC… beside
            // youtube.com/@handle on one link page — retires.
            if ($resolvedFresh) {
                yield new Note('channel_resolved', "handle '{$identifier}' resolved to {$channelId}", ['channelId' => $channelId]);
            }
        }

        $response = $io->get('https://www.youtube.com/feeds/videos.xml?'.http_build_query(['channel_id' => $channelId]));

        if ($response['status'] !== 200 || $response['body'] === '') {
            yield new Unavailable("videos.xml returned {$response['status']}", $response['status']);

            return;
        }

        $items = YoutubeFeed::parse($response['body']);

        if ($items === null) {
            // Unparseable XML is a shape break (a layout/schema change, or a
            // non-XML error body under a 200) — never "no uploads". An empty
            // but well-formed <feed> is handled separately below.
            yield new Unavailable('videos.xml did not parse as XML', $response['status']);

            return;
        }

        if ($items === []) {
            // No shorts attempt either: shorts ARE uploads, so a channel whose
            // uploads feed is empty has nothing on the shelf — spending a
            // vendor credit here could only confirm that for money.
            yield new Note('empty_feed', 'No entries parsed from the channel RSS feed');

            return;
        }

        // Dedupe is against the WHOLE feed, not the sliced page: an id the
        // scope cut off must not sneak back in through the vendor lane.
        $feedIds = array_flip(array_column($items, 'id'));

        $limit = $pull->scopeLimit();
        if ($limit !== null) {
            $items = array_slice($items, 0, $limit);
        }

        // One batched probe for the whole page of entries; a missing key means
        // the probe found nothing better, so the feed's own value stands.
        $better = $this->thumbnails->bestForMany(array_column($items, 'id'));

        foreach ($items as $item) {
            $item['thumbnail'] = $better[$item['id']] ?? $item['thumbnail'] ?? null;
            yield new Record('watch', $item['id'], $item);
        }

        foreach ($this->blendableShorts($channelId, $items, $feedIds, $limit) as $short) {
            yield new Record('watch', $short['id'], $short);
        }

        // The feed gives the latest 15 (YouTube's own server-side cap) —
        // only a prefix down to the oldest entry actually seen. Computed off
        // the RSS entries ALONE, never the blended shorts: the shelf is not
        // exhaustive over its date range for non-shorts, so letting an older
        // short drag `from` down would claim regular videos in that gap were
        // seen and absence would delete live uploads (C5). Shorts older than
        // the feed window land BELOW the claim and are simply never dominated.
        $dates = array_filter(array_column($items, 'published'));
        yield new Covered('watch', Coverage::prefix($dates === [] ? null : min($dates), count($items)));

        if ($resolvedFresh) {
            // Cache the resolution so the next run skips the channel-page hop.
            yield new Bookmark('watch', ['channel_id' => $channelId]);
        }
    }

    private function isChannelId(string $value): bool
    {
        return (bool) preg_match('/^UC[A-Za-z0-9_-]{22}$/', $value);
    }

    /**
     * Item 11c: the Shorts shelf, re-spoken in the feed's own vocabulary so a
     * blended short is indistinguishable from an RSS entry to the projector
     * and to `orderField` (videoId→id, name→title, link→url, date→published).
     * fetchShorts() owns the whole vendor discipline and answers null for
     * every failure mode, so the blend degrades to nothing and the RSS lane
     * stands alone — exactly the fallback contract.
     *
     * The already-yielded RSS page rides in for two reasons: its length is
     * what is left of a latest_n scope (when the feed alone filled it, the
     * vendor call is skipped OUTRIGHT — no credit spent on rows the slice
     * would discard), and its first row donates `channel_title`, which the
     * shelf rows lack but the projector's f_authored facet reads.
     *
     * @param  list<array<string, mixed>>  $rssItems  the sliced, yielded page
     * @param  array<string, int>  $feedIds  every id the full feed carried
     * @return list<array<string, mixed>>
     */
    private function blendableShorts(string $channelId, array $rssItems, array $feedIds, ?int $limit): array
    {
        $remaining = $limit === null ? PHP_INT_MAX : $limit - count($rssItems);
        if ($remaining <= 0) {
            return [];
        }

        $rows = $this->scraper->fetchShorts($channelId);
        if ($rows === null) {
            return [];
        }

        $channelTitle = $rssItems[0]['channel_title'] ?? null;

        $shorts = [];
        foreach ($rows as $row) {
            // The feed copy wins a duplicate: it carries channel_title and a
            // probed thumbnail, and its `published` is already in feed format.
            // An unnamed short is skipped — the stream requires a title and
            // the projector would drop the row anyway.
            if (isset($feedIds[$row['videoId']]) || $row['name'] === '') {
                continue;
            }

            $shorts[] = [
                'id' => $row['videoId'],
                'title' => $row['name'],
                'url' => $row['link'],
                'published' => $this->feedFormatDate($row['date']),
                'thumbnail' => $row['thumbnail'] !== '' ? $row['thumbnail'] : null,
                'channel_title' => is_string($channelTitle) ? $channelTitle : null,
            ];
        }

        // Explicit newest-first, dateless rows last — the record order never
        // leans on the vendor's default sort.
        usort($shorts, fn (array $a, array $b) => strcmp($b['published'] ?? '', $a['published'] ?? ''));

        return array_slice($shorts, 0, $remaining);
    }

    /**
     * Vendor dates arrive with a Pacific offset ('…T09:00:04-07:00'); the feed
     * speaks UTC ('…T16:00:04+00:00'). PrefixCoverage compares order values
     * with strcmp, so one stream must not carry two clocks — re-express the
     * vendor's instant in the feed's format. Unparseable ⇒ null: a dateless
     * row is never dominated, so it can never be wrongly deleted.
     */
    private function feedFormatDate(?string $date): ?string
    {
        if ($date === null) {
            return null;
        }
        $timestamp = strtotime($date);

        return $timestamp === false ? null : gmdate('Y-m-d\TH:i:sP', $timestamp);
    }

    /**
     * Handle → channel id via the public channel page. Mirrors
     * YoutubeScraper's production-proven capture; a page that will not yield
     * one (renamed handle, consent interstitial) resolves to null and the
     * caller degrades to Unavailable.
     */
    private function resolveHandle(string $handle, Io $io): ?string
    {
        $handle = ltrim($handle, '@');
        if ($handle === '' || preg_match('~[\s/]~u', $handle)) {
            return null;
        }

        $response = $io->get('https://www.youtube.com/@'.rawurlencode($handle));
        if ($response['status'] !== 200 || $response['body'] === '') {
            return null;
        }

        // Same precedence as YoutubeScraper::resolveChannelId(). A channel
        // page carries MANY "channelId" values — featured/related channels
        // come FIRST in the markup — so the bare channelId regex resolved
        // @mkbhd to a related channel and the pool filled with the wrong
        // uploads (overnight 2026-08-18 F3). "externalId" is the page's own
        // channel; the RSS alternate link and /channel/ path agree with it.
        $body = $response['body'];
        if (preg_match('/"externalId":"(UC[A-Za-z0-9_-]{22})"/', $body, $m)
            || preg_match('~feeds/videos\.xml\?channel_id=(UC[A-Za-z0-9_-]{22})~', $body, $m)
            || preg_match('~/channel/(UC[A-Za-z0-9_-]{22})~', $body, $m)
            || preg_match('/"channelId":"(UC[A-Za-z0-9_-]{22})"/', $body, $m)) {
            return $m[1];
        }

        return null;
    }
}
