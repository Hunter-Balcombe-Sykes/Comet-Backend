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

        foreach ($items as $item) {
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

        return preg_match('/"channelId":"(UC[A-Za-z0-9_-]{22})"/', $response['body'], $m) ? $m[1] : null;
    }
}
