<?php

namespace App\Ingest\Projection;

/**
 * YT Music Topic-channel upload → the `track` item kind. Links land on
 * music.youtube.com; the embed stays the standard YouTube player keyed by
 * video id, exactly as the legacy YoutubeMusicItems reshape did.
 */
class YoutubeMusicTrackProjector implements Projector
{
    public static function version(): int
    {
        return 1;
    }

    public static function kind(): string
    {
        return 'track';
    }

    public function project(RecordView $view): ?array
    {
        $title = $view->string('title');
        $url = $view->string('url');
        if ($title === null || $url === null) {
            return null;
        }

        return [
            'kind' => self::kind(),
            'headline' => $title,
            'facets' => array_filter([
                'f_link' => ['url' => $url],
                'f_published' => $view->string('published') === null ? null : ['published_from' => $view->string('published')],
                'f_authored' => $view->string('artist') === null ? null : ['creator' => $view->string('artist')],
                'f_embed' => $view->string('id') === null ? null : ['provider' => 'youtube', 'embed_key' => $view->string('id')],
            ]),
            'media' => $view->string('thumbnail') === null ? [] : [
                ['role' => 'cover', 'url' => $view->string('thumbnail')],
            ],
        ];
    }
}
