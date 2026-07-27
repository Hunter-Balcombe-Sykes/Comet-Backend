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
 * Vimeo uploads via the legacy Simple API v2 (keyless): GET
 * vimeo.com/api/v2/{user}/videos.json returns a plain JSON array of the
 * user's latest videos, newest first, capped at 20 (VimeoApi::fetchVideos
 * mirrors the same endpoint for the connect-time preview).
 *
 * The cap is why this stream can NEVER claim exhaustive: a profile with 21+
 * uploads always looks identical to one with exactly 20 from this endpoint
 * alone, so the only honest claim is a prefix down to the oldest upload_date
 * actually seen. Getting this wrong — claiming exhaustive on a capped
 * response — would let a legitimate 21st-and-older video get folded away as
 * "deleted" the moment absence-folding runs (C5).
 */
class VimeoConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('vimeo'),
            identifierKind: 'slug',
            // The API itself always lives on the bare vimeo.com host; art and
            // thumbnails are served from the vimeocdn.com family.
            hosts: ['vimeo.com', '*.vimeo.com', '*.vimeocdn.com'],
            streams: [
                'watch' => new StreamSpec(
                    name: 'watch',
                    target: 'video',
                    profile: SourceProfile::Feed,
                    // A video with no id/title/url cannot be rendered or even
                    // linked to; landing it would poison the projection.
                    requires: ['id', 'title', 'url'],
                    // Vimeo rotates a cache-busting query on thumbnail URLs —
                    // without stripping it every run looks like a content
                    // change even when nothing did.
                    volatile: ['thumbnail_large?query'],
                    orderField: 'upload_date',
                ),
            ],
            cost: CostClass::Free,
            defaultIntervalSeconds: 43200,
        );
    }

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable
    {
        $apiPath = trim($pull->identifier, '/');
        $response = $io->get("https://vimeo.com/api/v2/{$apiPath}/videos.json");

        if ($response['status'] !== 200 || $response['body'] === '') {
            // A failed fetch is UNAVAILABLE, never "this profile has no
            // videos" — emitting nothing here would let absence-folding
            // conclude the whole upload history was deleted (C5).
            yield new Unavailable("videos.json returned {$response['status']}", $response['status']);

            return;
        }

        $decoded = json_decode($response['body'], true);
        if (! is_array($decoded)) {
            // A 200 that isn't a JSON array (an HTML error/interstitial page,
            // most likely) is a shape break, not an empty catalogue.
            yield new Unavailable('videos.json did not decode to a JSON array', $response['status']);

            return;
        }

        $items = [];
        foreach ($decoded as $video) {
            $item = $this->mapVideo($video);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        if ($items === []) {
            // Parsed cleanly but nothing usable came back: a genuinely
            // video-less profile, or a response shape change. Either way this
            // is not evidence of deletion, so no Coverage is emitted.
            yield new Note('empty_videos', 'No videos parsed from the Vimeo Simple API response');

            return;
        }

        $limit = $pull->scopeLimit();
        if ($limit !== null) {
            $items = array_slice($items, 0, $limit);
        }

        foreach ($items as $item) {
            yield new Record('watch', $item['id'], $item);
        }

        // Newest-first, max 20 per page: only ever a prefix down to the
        // oldest upload_date actually observed, regardless of scope — never
        // exhaustive, even on an unscoped run (see class docblock).
        $dates = array_filter(array_column($items, 'upload_date'));
        yield new Covered('watch', Coverage::prefix($dates === [] ? null : min($dates), count($items)));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapVideo(mixed $video): ?array
    {
        if (! is_array($video) || ! isset($video['id'])) {
            return null;
        }
        $title = is_string($video['title'] ?? null) ? trim($video['title']) : '';
        if ($title === '') {
            return null;
        }
        $id = (string) $video['id'];

        return [
            'id' => $id,
            'title' => $title,
            'url' => is_string($video['url'] ?? null) && $video['url'] !== '' ? $video['url'] : "https://vimeo.com/{$id}",
            'upload_date' => is_string($video['upload_date'] ?? null) ? $video['upload_date'] : null,
            'thumbnail_large' => $video['thumbnail_large'] ?? $video['thumbnail_medium'] ?? null,
            'description' => is_string($video['description'] ?? null) ? $video['description'] : null,
            'duration' => $video['duration'] ?? null,
            'stats_number_of_plays' => $video['stats_number_of_plays'] ?? null,
        ];
    }
}
