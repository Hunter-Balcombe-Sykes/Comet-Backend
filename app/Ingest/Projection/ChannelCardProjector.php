<?php

namespace App\Ingest\Projection;

/**
 * Normalized account-card doc → the `channel` item kind. Shared by the
 * og-scrape connectors (twitch channel, skool, strava) — they all land the
 * same shape on purpose: {name, url, handle?, avatar?, description?,
 * followers?, embed{provider,key}?}. One projector, three sources, exactly
 * the "community cards preserved as channel items" row from plan §11.
 */
class ChannelCardProjector implements Projector
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
        $name = $view->string('name');
        $url = $view->string('url');

        if ($name === null || $url === null) {
            return null;
        }

        return [
            'kind' => self::kind(),
            'headline' => $name,
            'facets' => array_filter([
                'f_link' => ['url' => $url],
                'f_text' => $view->string('description') === null ? null : ['body' => $view->string('description')],
                'f_channel' => array_filter([
                    'handle' => $view->string('handle'),
                    'avatar_url' => $view->string('avatar'),
                    'followers' => $view->int('followers'),
                ], static fn ($v) => $v !== null) ?: null,
                'f_embed' => $view->string('embed.key') === null ? null : [
                    'provider' => $view->string('embed.provider'),
                    'embed_key' => $view->string('embed.key'),
                ],
                // Strava clubs carry a "City, Region" locality on the card.
                'f_place' => $view->string('location') === null ? null : ['locality' => $view->string('location')],
            ]),
            'media' => $view->string('avatar') === null ? [] : [
                ['role' => 'avatar', 'url' => $view->string('avatar')],
            ],
        ];
    }
}
