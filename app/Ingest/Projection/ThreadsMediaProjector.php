<?php

namespace App\Ingest\Projection;

/**
 * Threads post → the `media` item kind (Item 10a, 2026-09-01). The Instagram
 * media conventions verbatim: headline stays null (media-pool items are
 * imagery, not cards), text rides f_text, the post date rides f_published,
 * and a video post carries its mp4 as a `video` frame with f_playable so
 * PoolResolver::frames() can emit it with the cover as poster.
 *
 * One deliberate difference from the Instagram projector: the doc's media
 * entries arrive ALREADY in projector vocabulary — ThreadsPostsNormalizer
 * mints the position-stable `threads:{pk}:{i}` / `threads:{pk}:video` refs at
 * normalize time — so this class validates and passes frames through rather
 * than re-minting them. The ref namespace is load-bearing, not decorative:
 * every URL here is IG-signed and expiring, MediaMirror::OWNED_REF_PREFIXES
 * carries 'threads:' so the bytes land on R2, and a frame that lost its ref
 * would fail safe as never-mirrored — which on an expiring CDN means a dead
 * image. Dropping such an entry here is the honest outcome.
 *
 * A text-only thread (media:[]) projects to NOTHING. The normalizer keeps the
 * row and leaves this call to us: the media pool is imagery, and a frameless
 * item has nothing to render there. The record itself still lands, so a
 * future textual surface can project it without a re-scrape.
 */
class ThreadsMediaProjector implements Projector
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
        $video = null;
        foreach ($view->list('media') as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $role = $entry['role'] ?? null;
            $url = $entry['url'] ?? null;
            $ref = $entry['ref'] ?? null;
            if (! in_array($role, ['cover', 'gallery', 'video'], true)
                || ! is_string($url) || $url === ''
                || ! is_string($ref) || ! str_starts_with($ref, 'threads:')) {
                continue;
            }
            $media[] = ['role' => $role, 'url' => $url, 'ref' => $ref];
            if ($role === 'video') {
                $video = $url;
            }
        }

        if ($media === []) {
            return null;
        }

        return [
            'kind' => self::kind(),
            'headline' => null,
            'facets' => array_filter([
                'f_link' => $view->string('url') === null ? null : ['url' => $view->string('url')],
                'f_text' => $view->string('caption') === null ? null : ['body' => $view->string('caption')],
                'f_published' => $view->string('taken_at') === null ? null : ['published_from' => $view->string('taken_at')],
                'f_playable' => $video === null ? null : ['stream_url' => $video],
            ]),
            'media' => $media,
        ];
    }
}
