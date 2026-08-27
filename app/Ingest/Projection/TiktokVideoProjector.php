<?php

namespace App\Ingest\Projection;

/**
 * TikTok video → the `video` item kind (T27c, 2026-08-28). The headline is
 * the caption's first line — TikTok has no separate title field, and a
 * caption-less video still deserves a card, so unlike YouTube/Vimeo a
 * missing headline does not kill the projection (the watch card renders
 * cover + platform chip alone). The cover URL is signed-and-expiring, so it
 * carries an owned `tiktok:` ref for MediaMirror; the embed key is the
 * numeric video id (TikTok's /embed/v2/{id} iframe shape).
 */
class TiktokVideoProjector implements Projector
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
        $id = $view->string('id');
        $url = $view->string('url');
        if ($id === null || $url === null) {
            return null;
        }

        $caption = $view->string('caption');
        $headline = $caption === null ? null : mb_substr(trim(strtok($caption, "\n")), 0, 150);

        return [
            'kind' => self::kind(),
            'headline' => $headline === '' ? null : $headline,
            'facets' => array_filter([
                'f_link' => ['url' => $url],
                'f_text' => $caption === null ? null : ['body' => $caption],
                'f_published' => $view->string('created_at') === null ? null : ['published_from' => $view->string('created_at')],
                'f_duration' => $view->int('duration') === null ? null : ['seconds' => $view->int('duration')],
                'f_embed' => ['provider' => 'tiktok', 'embed_key' => $id],
            ]),
            'media' => $view->string('cover') === null ? [] : [
                ['role' => 'cover', 'url' => $view->string('cover'), 'ref' => "tiktok:{$id}:cover"],
            ],
        ];
    }
}
