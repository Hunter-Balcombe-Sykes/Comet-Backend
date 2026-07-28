<?php

namespace App\Ingest\Projection;

/**
 * Spotify oEmbed → the `channel` item kind (plan §6: account players ARE
 * items). The doc carries only display fields; the entity identity lives in
 * the record KEY (the entity path, e.g. `artist/abc123`), which is also what
 * the embed needs.
 */
class SpotifyChannelProjector implements Projector
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
        $entityPath = $view->key();

        if ($title === null || $entityPath === null || $entityPath === '') {
            return null;
        }

        return [
            'kind' => self::kind(),
            'headline' => $title,
            'facets' => [
                'f_link' => ['url' => 'https://open.spotify.com/'.ltrim($entityPath, '/')],
                'f_embed' => ['provider' => 'spotify', 'embed_key' => ltrim($entityPath, '/')],
                'f_channel' => ['avatar_url' => $view->string('thumbnail_url')],
            ],
            'media' => $view->string('thumbnail_url') === null ? [] : [
                ['role' => 'avatar', 'url' => $view->string('thumbnail_url')],
            ],
        ];
    }
}
