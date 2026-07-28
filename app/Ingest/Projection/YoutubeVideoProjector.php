<?php

namespace App\Ingest\Projection;

/**
 * YouTube channel-RSS entry → the `video` item kind.
 */
class YoutubeVideoProjector implements Projector
{
    public static function version(): int
    {
        return 1;
    }

    public static function kind(): string
    {
        return 'video';
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
                'f_authored' => $view->string('channel_title') === null ? null : ['creator' => $view->string('channel_title')],
                'f_embed' => $view->string('id') === null ? null : ['provider' => 'youtube', 'embed_key' => $view->string('id')],
            ]),
            'media' => $view->string('thumbnail') === null ? [] : [
                ['role' => 'cover', 'url' => $view->string('thumbnail')],
            ],
        ];
    }
}
