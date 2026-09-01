<?php

namespace App\Ingest\Projection;

/**
 * Pinterest pin → the `media` item kind (Item 10a, 2026-09-01). The Instagram
 * media conventions verbatim: headline stays null (media-pool items are
 * imagery, not cards), text rides f_text, and a video pin carries its mp4 as
 * a `video` frame with f_playable so PoolResolver::frames() can emit it with
 * the cover as poster. One deliberate difference: NO f_published — board
 * pins carry no dates (recorded 2026-09-01) and a synthesized one would put
 * fake recency into the pool's ordering.
 *
 * pinimg URLs are unsigned, but every frame still carries an owned
 * `pinterest:` ref so MediaMirror copies bytes to R2 — the owned-bytes
 * policy is about serving our pages from our storage, not only about
 * expiring signatures.
 */
class PinterestMediaProjector implements Projector
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
        $image = $view->string('image');
        if ($id === null || $image === null) {
            return null;
        }

        $media = [
            ['role' => 'cover', 'url' => $image, 'ref' => "pinterest:{$id}:0"],
        ];
        $video = $view->string('video_url');
        if ($video !== null && $video !== '') {
            $media[] = ['role' => 'video', 'url' => $video, 'ref' => "pinterest:{$id}:video"];
        }

        // Pins split their words across title and description; the caption
        // wins when both exist (it is the sentence, the title is the label).
        $body = $view->string('caption') ?? $view->string('title');

        return [
            'kind' => self::kind(),
            'headline' => null,
            'facets' => array_filter([
                'f_link' => $view->string('url') === null ? null : ['url' => $view->string('url')],
                'f_text' => $body === null ? null : ['body' => $body],
                'f_playable' => $video === null || $video === '' ? null : ['stream_url' => $video],
            ]),
            'media' => $media,
        ];
    }
}
