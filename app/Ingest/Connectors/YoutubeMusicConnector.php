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
use App\Ingest\Support\YoutubeFeed;

/**
 * YouTube Music releases (the remaining-work audit's #20): an artist's YT
 * Music presence is their auto-generated "- Topic" channel, whose uploads
 * feed IS the release list — read via the same keyless channel RSS as
 * YoutubeRssConnector (shared Support\YoutubeFeed parse), reshaped into
 * tracks whose links land on music.youtube.com and whose embeds stay the
 * standard YouTube player.
 *
 * The identifier is the UC… channel id only — the legacy connect flow always
 * resolved and stored `payload.channelId` for youtube-music, so unlike the
 * plain YouTube connector there is no handle fallback to carry.
 *
 * Empty-feed guard (named by the audit doc): a well-formed feed with zero
 * entries is a Note with NO coverage claim — a Topic channel mid-migration
 * or an RSS hiccup must never read as "the artist deleted their catalogue"
 * (C5). The legacy fetcher threw Unavailable here, which suppressed healthy
 * channels; the Note keeps the run ok while still landing nothing.
 */
class YoutubeMusicConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('youtube_music'),
            identifierKind: 'channel_id',
            hosts: ['youtube.com', '*.youtube.com', 'music.youtube.com', '*.ytimg.com'],
            streams: [
                'releases' => new StreamSpec(
                    name: 'releases',
                    target: 'track',
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
        $channelId = trim($pull->identifier);
        if (! preg_match('/^UC[A-Za-z0-9_-]{22}$/', $channelId)) {
            yield new Unavailable("'{$channelId}' is not a UC… channel id");

            return;
        }

        $response = $io->get('https://www.youtube.com/feeds/videos.xml?'.http_build_query(['channel_id' => $channelId]));

        if ($response['status'] !== 200 || $response['body'] === '') {
            yield new Unavailable("videos.xml returned {$response['status']}", $response['status']);

            return;
        }

        $items = YoutubeFeed::parse($response['body']);

        if ($items === null) {
            // Unparseable XML is a shape break, never "no releases".
            yield new Unavailable('videos.xml did not parse as XML', $response['status']);

            return;
        }

        if ($items === []) {
            // The empty-feed guard (see class docblock).
            yield new Note('empty_feed', 'No entries parsed from the Topic-channel uploads feed');

            return;
        }

        $limit = $pull->scopeLimit();
        if ($limit !== null) {
            $items = array_slice($items, 0, $limit);
        }

        foreach ($items as $item) {
            yield new Record('releases', $item['id'], $this->mapTrack($item));
        }

        // The feed serves the latest 15 — only ever a prefix down to the
        // oldest entry actually seen.
        $dates = array_filter(array_column($items, 'published'));
        yield new Covered('releases', Coverage::prefix($dates === [] ? null : min($dates), count($items)));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function mapTrack(array $item): array
    {
        // The uploads feed doubles as the release list, so the upload date is
        // the release date; links land on music.youtube.com; the "- Topic"
        // suffix is channel plumbing, not the artist's name.
        return array_filter([
            'id' => $item['id'],
            'title' => $item['title'],
            'url' => 'https://music.youtube.com/watch?v='.$item['id'],
            'published' => $item['published'],
            'thumbnail' => $item['thumbnail'],
            'artist' => is_string($item['channel_title'] ?? null)
                ? (trim((string) preg_replace('/\s+-\s+Topic$/', '', $item['channel_title'])) ?: null)
                : null,
        ], static fn ($v) => $v !== null);
    }
}
