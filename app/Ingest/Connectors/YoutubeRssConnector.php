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
use App\Services\Platforms\YoutubeThumbnailResolver;

/**
 * YouTube's keyless channel RSS (GET youtube.com/feeds/videos.xml?channel_id=)
 * — an Atom feed capped at the latest 15 uploads, newest first. Same honesty
 * requirement as Vimeo: only ever a prefix down to the oldest `published`
 * actually seen, never exhaustive (C5).
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
    public function __construct(private readonly YoutubeThumbnailResolver $thumbnails) {}

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
            yield new Note('empty_feed', 'No entries parsed from the channel RSS feed');

            return;
        }

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

        // The feed gives the latest 15 (YouTube's own server-side cap) —
        // only a prefix down to the oldest entry actually seen.
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
