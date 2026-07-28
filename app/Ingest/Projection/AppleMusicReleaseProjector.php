<?php

namespace App\Ingest\Projection;

/**
 * iTunes Lookup album collection → the `release` item kind. Field names are
 * Apple's own (collectionId/collectionName/…) — the connector lands the
 * response verbatim and interpretation happens here, where it is versioned.
 */
class AppleMusicReleaseProjector implements Projector
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
        $title = $view->string('collectionName');
        if ($title === null) {
            return null;
        }

        $genre = $view->string('primaryGenreName');

        return [
            'kind' => self::kind(),
            'headline' => $title,
            'facets' => array_filter([
                'f_link' => $view->string('collectionViewUrl') === null ? null : ['url' => $view->string('collectionViewUrl')],
                'f_published' => $view->string('releaseDate') === null ? null : ['published_from' => $view->string('releaseDate')],
                'f_authored' => $view->string('artistName') === null ? null : ['creator' => $view->string('artistName')],
            ]),
            'tags' => $genre === null ? [] : [['tag' => $genre, 'tag_type' => 'genre']],
            'media' => $view->string('artworkUrl100') === null ? [] : [
                ['role' => 'cover', 'url' => $view->string('artworkUrl100')],
            ],
        ];
    }
}
