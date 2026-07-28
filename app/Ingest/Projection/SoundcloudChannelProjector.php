<?php

namespace App\Ingest\Projection;

/**
 * SoundCloud oEmbed → the `channel` item kind. Unlike Spotify's, the landed
 * doc carries the permalink AND the parsed player src, so the embed_key is
 * the full widget URL rather than an entity path — Embed.astro's soundcloud
 * variant consumes the src directly.
 */
class SoundcloudChannelProjector implements Projector
{
    public static function version(): int
    {
        return 1;
    }

    public static function kind(): string
    {
        return 'channel';
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
                'f_embed' => $view->string('embed_url') === null ? null : [
                    'provider' => 'soundcloud',
                    'embed_key' => $view->string('embed_url'),
                ],
                'f_channel' => array_filter([
                    'avatar_url' => $view->string('thumbnail_url'),
                    'handle' => $view->string('author_name'),
                ]) ?: null,
            ]),
            'media' => $view->string('thumbnail_url') === null ? [] : [
                ['role' => 'avatar', 'url' => $view->string('thumbnail_url')],
            ],
        ];
    }
}
