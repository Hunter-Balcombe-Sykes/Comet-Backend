<?php

namespace App\Ingest\Projection;

/**
 * iTunes Lookup podcast episode → the `episode` item kind. The show's name
 * rides as the creator: an episode card without its show is unattributable.
 */
class ApplePodcastsEpisodeProjector implements Projector
{
    public static function version(): int
    {
        return 1;
    }

    public static function kind(): string
    {
        return 'episode';
    }

    public function project(RecordView $view): ?array
    {
        $title = $view->string('trackName');
        if ($title === null) {
            return null;
        }

        return [
            'kind' => self::kind(),
            'headline' => $title,
            'facets' => array_filter([
                'f_text' => $view->string('description') === null ? null : ['body' => $view->string('description')],
                'f_link' => $view->string('trackViewUrl') === null ? null : ['url' => $view->string('trackViewUrl')],
                'f_published' => $view->string('releaseDate') === null ? null : ['published_from' => $view->string('releaseDate')],
                'f_authored' => $view->string('collectionName') === null ? null : ['creator' => $view->string('collectionName')],
            ]),
            'media' => $view->string('artworkUrl600') === null ? [] : [
                ['role' => 'cover', 'url' => $view->string('artworkUrl600')],
            ],
        ];
    }
}
