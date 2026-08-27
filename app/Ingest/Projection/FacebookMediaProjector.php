<?php

namespace App\Ingest\Projection;

/**
 * Facebook page post → the `media` item kind (T27c, 2026-08-28). The
 * Instagram carousel rule verbatim: a multi-photo post is ONE item whose
 * gallery rows are the attached photos in order, first photo doubling as the
 * cover. fbcdn URLs expire (signed `oe=`), so every frame carries an owned
 * `facebook:` ref keyed on the postId-stable position and MediaMirror copies
 * the bytes to R2.
 */
class FacebookMediaProjector implements Projector
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
        $postId = $view->string('post_id');
        if ($postId === null) {
            return null;
        }

        $media = [];
        foreach ($view->list('images') as $i => $image) {
            $url = is_array($image) ? ($image['url'] ?? null) : null;
            if (is_string($url) && $url !== '') {
                $media[] = [
                    'role' => $i === 0 ? 'cover' : 'gallery',
                    'url' => $url,
                    // Stable across CDN re-signing: the frame's place in the post.
                    'ref' => "facebook:{$postId}:{$i}",
                ];
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
                'f_text' => $view->string('text') === null ? null : ['body' => $view->string('text')],
                'f_published' => $view->string('published_at') === null ? null : ['published_from' => $view->string('published_at')],
            ]),
            'media' => $media,
        ];
    }
}
