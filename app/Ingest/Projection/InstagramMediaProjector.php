<?php

namespace App\Ingest\Projection;

/**
 * Instagram post → the `media` item kind. A carousel is ONE item whose
 * gallery rows are the child frames in order (plan §6); the first frame
 * doubles as the cover. The asset URLs here are Instagram's expiring CDN
 * links — the media pipeline mirrors bytes to R2 (owned-bytes policy) and
 * the asset fingerprint survives that swap because it is keyed on the
 * shortcode-stable ref, not the signed URL.
 */
class InstagramMediaProjector implements Projector
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
        $shortcode = $view->string('shortcode');
        if ($shortcode === null) {
            return null;
        }

        $images = array_values(array_filter($view->list('images'), 'is_string'));
        if ($images === [] && $view->string('display_url') !== null) {
            $images = [(string) $view->string('display_url')];
        }

        $media = [];
        foreach ($images as $i => $url) {
            $media[] = [
                'role' => $i === 0 ? 'cover' : 'gallery',
                'url' => $url,
                // Stable across CDN re-signing: the frame's place in the post.
                'ref' => "instagram:{$shortcode}:{$i}",
            ];
        }
        // A reel/video post also carries its mp4 as a `video` frame (owner
        // ruling R7, overnight 2026-08-18): the ref is instagram-owned so
        // MediaMirror copies the bytes to R2 (the CDN url expires), and
        // PoolResolver::frames() emits it with kind=video + the cover as
        // poster so the sitepage can play it. The cover stays frame 0.
        $video = $view->string('video_url');
        if ($video !== null && $video !== '') {
            $media[] = [
                'role' => 'video',
                'url' => $video,
                'ref' => "instagram:{$shortcode}:video",
            ];
        }

        return [
            'kind' => self::kind(),
            'headline' => null,
            'facets' => array_filter([
                'f_link' => $view->string('url') === null ? null : ['url' => $view->string('url')],
                'f_text' => $view->string('caption') === null ? null : ['body' => $view->string('caption')],
                'f_published' => $view->string('taken_at') === null ? null : ['published_from' => $view->string('taken_at')],
                'f_playable' => $view->string('video_url') === null ? null : ['stream_url' => $view->string('video_url')],
            ]),
            'media' => $media,
        ];
    }
}
