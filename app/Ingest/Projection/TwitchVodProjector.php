<?php

namespace App\Ingest\Projection;

/**
 * Helix archive video → the `video` item kind. The embed variant is 'vod' so
 * Embed.astro builds player.twitch.tv/?video={id} rather than the channel
 * player — the `{host}` parent substitution stays a render-time concern
 * (plan §9's one declared token).
 */
class TwitchVodProjector implements Projector
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
                'f_duration' => $view->int('duration_seconds') === null ? null : ['seconds' => $view->int('duration_seconds')],
                'f_embed' => $view->string('id') === null ? null : [
                    'provider' => 'twitch',
                    'embed_key' => $view->string('id'),
                    'variant' => 'vod',
                ],
            ]),
            'media' => $view->string('thumbnail') === null ? [] : [
                ['role' => 'cover', 'url' => $view->string('thumbnail')],
            ],
        ];
    }
}
