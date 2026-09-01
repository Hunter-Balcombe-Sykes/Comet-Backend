<?php

namespace App\Ingest\Projection;

/**
 * Twitch VOD → the `video` item kind (Item 10a, 2026-09-01). Its own class
 * rather than a YoutubeVideoProjector reuse for one load-bearing reason: the
 * f_embed provider is a hardcoded contract per projector, and a Twitch VOD
 * must never claim provider 'youtube' (Twitch's player additionally requires
 * the sitepage host bound into `parent` — the same bindsHost rule the catalog
 * surface declares for the channel embed).
 *
 * Thumbnails hot-link static-cdn.jtvnw.net with no owned ref — unsigned,
 * stable URLs, the YouTube/i.ytimg.com precedent, not the TikTok
 * signed-and-expiring case. `views`/`game` stay in the record doc only: views
 * is a declared-volatile path (reading it here would fail
 * ingest:volatility-audit), and no watch facet exists for either.
 */
class TwitchVideoProjector implements Projector
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
                'f_duration' => $view->int('duration') === null ? null : ['seconds' => $view->int('duration')],
                'f_embed' => $view->string('id') === null ? null : ['provider' => 'twitch', 'embed_key' => $view->string('id')],
            ]),
            'media' => $view->string('thumbnail') === null ? [] : [
                ['role' => 'cover', 'url' => $view->string('thumbnail')],
            ],
        ];
    }
}
