<?php

namespace App\Ingest\Projection;

/**
 * Bandcamp release → the `release` item kind. Reads every field through the
 * RecordView so the paths it depends on are observable, which is what lets
 * the volatility audit catch a field being both "ignored for change
 * detection" and "actually used".
 */
class BandcampReleaseProjector implements Projector
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

        // A release we cannot name or link to is not showable; the shape gate
        // should have caught this, so reaching here means the record changed
        // shape between landing and projection.
        if ($title === null || $url === null) {
            return null;
        }

        return [
            'kind' => self::kind(),
            'headline' => $title,
            'facets' => [
                'f_link' => ['url' => $url],
                'f_published' => ['published_from' => $view->string('release_date')],
                'f_authored' => ['creator' => $view->string('artist')],
                'f_catalog' => ['release_type' => $view->string('type')],
            ],
            'media' => $view->string('art_url') === null ? [] : [
                ['role' => 'cover', 'url' => $view->string('art_url')],
            ],
        ];
    }
}
