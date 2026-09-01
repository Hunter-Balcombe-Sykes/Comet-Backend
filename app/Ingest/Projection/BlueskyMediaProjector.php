<?php

namespace App\Ingest\Projection;

/**
 * Bluesky post → the `media` item kind (Item 10b, 2026-09-01). The Instagram
 * media conventions: headline stays null (media-pool items are imagery, not
 * cards), the post text rides f_text, createdAt rides f_published, and a
 * multi-image post is ONE item whose gallery rows are the embed frames in
 * order, first frame doubling as cover.
 *
 * One deliberate divergence from the Instagram/Pinterest video shape: NO
 * `video` frame and NO f_playable. Bluesky serves video as an HLS playlist
 * only (no mp4 — recorded 2026-09-01), which MediaMirror cannot mirror as
 * bytes and the sitepage's <video> frame path cannot reliably play — so a
 * video post lands its thumbnail as the cover (the one mirrorable frame,
 * exactly what BlueskyPostsNormalizer's capture notes prescribe) and the
 * card links out to the post. The doc keeps isVideo + video.playlist, so a
 * future HLS/embed lane is a projector version bump, not a re-scrape.
 *
 * cdn.bsky.app URLs are unsigned, but every frame still carries an owned
 * `bluesky:` ref so MediaMirror copies bytes to R2 — the owned-bytes policy
 * is about serving our pages from our storage, not only about expiring
 * signatures. Bluesky alt text is first-class user content and rides along.
 */
class BlueskyMediaProjector implements Projector
{
    public static function version(): int
    {
        return 1;
    }

    public static function kind(): string
    {
        return 'media';
    }

    public function project(RecordView $view): ?array
    {
        $id = $view->string('id');
        if ($id === null) {
            return null;
        }

        $media = [];
        foreach ($view->list('images') as $i => $image) {
            $url = is_array($image) && is_string($image['url'] ?? null) && $image['url'] !== '' ? $image['url'] : null;
            if ($url === null) {
                continue;
            }
            $media[] = array_filter([
                'role' => $media === [] ? 'cover' : 'gallery',
                'url' => $url,
                // Stable across any CDN churn: the frame's place in the post.
                'ref' => "bluesky:{$id}:{$i}",
                'alt' => is_string($image['alt'] ?? null) && $image['alt'] !== '' ? $image['alt'] : null,
            ], static fn ($v) => $v !== null);
        }

        if ($media === [] && $view->string('video.thumbnail') !== null) {
            $media[] = array_filter([
                'role' => 'cover',
                'url' => $view->string('video.thumbnail'),
                'ref' => "bluesky:{$id}:cover",
                'alt' => $view->string('video.alt'),
            ], static fn ($v) => $v !== null);
        }

        // No frame ⇒ no media item — the connector already drops text-only
        // posts, so this refusal is the projector holding its own contract.
        if ($media === []) {
            return null;
        }

        return [
            'kind' => self::kind(),
            'headline' => null,
            'facets' => array_filter([
                'f_link' => $view->string('url') === null ? null : ['url' => $view->string('url')],
                'f_text' => $view->string('text') === null ? null : ['body' => $view->string('text')],
                'f_published' => $view->string('createdAt') === null ? null : ['published_from' => $view->string('createdAt')],
            ]),
            'media' => $media,
        ];
    }
}
