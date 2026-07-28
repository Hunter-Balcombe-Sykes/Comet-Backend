<?php

namespace App\Ingest\Projection;

/**
 * Vimeo Simple API video → the `video` item kind.
 */
class VimeoVideoProjector implements Projector
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
                'f_text' => $view->string('description') === null ? null : ['body' => $view->string('description')],
                'f_published' => $view->string('upload_date') === null ? null : ['published_from' => $view->string('upload_date')],
                'f_duration' => $view->int('duration') === null ? null : ['seconds' => $view->int('duration')],
                'f_embed' => $view->string('id') === null ? null : ['provider' => 'vimeo', 'embed_key' => $view->string('id')],
            ]),
            'media' => $view->string('thumbnail_large') === null ? [] : [
                ['role' => 'cover', 'url' => $view->string('thumbnail_large')],
            ],
        ];
    }
}
