<?php

namespace App\Ingest\Projection;

/**
 * Spotify discography row → the `release` item kind (listen restructure,
 * 2026-08-18). Same shape AppleMusicReleaseProjector lands, so the two merge
 * on TitleRelease and the pool shows one album with two platform links and
 * the best cover.
 */
final class SpotifyReleaseProjector implements Projector
{
    public static function version(): int
    {
        return 1;
    }

    public static function kind(): string
    {
        return 'release';
    }

    public function project(RecordView $view): ?array
    {
        $title = $view->string('title');
        $url = $view->string('url');
        if ($title === null || $url === null) {
            return null;
        }
        $format = $view->string('format');
        $artwork = $view->string('artwork');
        $artist = $view->string('artist');
        $published = $view->string('published');

        return [
            'kind' => self::kind(),
            'headline' => $title,
            'facets' => array_filter([
                'f_link' => ['url' => $url],
                'f_catalog' => ['release_type' => in_array($format, ['album', 'ep', 'single', 'compilation'], true) ? $format : 'album'],
                'f_published' => $published === null ? null : ['published_from' => $published],
                'f_authored' => $artist === null ? null : ['creator' => $artist],
                'f_embed' => ['provider' => 'spotify', 'embed_key' => ltrim((string) parse_url($url, PHP_URL_PATH), '/')],
            ]),
            'media' => $artwork === null ? [] : [['role' => 'cover', 'url' => $artwork]],
        ];
    }
}
