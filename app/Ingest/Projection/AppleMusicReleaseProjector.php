<?php

namespace App\Ingest\Projection;

use App\Ingest\Connectors\AppleMusicConnector;

/**
 * iTunes Lookup album collection → the `release` item kind. Field names are
 * Apple's own (collectionId/collectionName/…) — the connector lands the
 * response verbatim and interpretation happens here, where it is versioned.
 */
class AppleMusicReleaseProjector implements Projector
{
    public static function version(): int
    {
        return 2;
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

        // Format (listen restructure 2026-08-18): iTunes labels every album
        // lookup row `collectionType: Album`; the honest signal is the name
        // suffix Apple itself appends (" - Single", " - EP") and, failing
        // that, the track count. The suffix is stripped from the headline —
        // "Currents - Single" reads as Currents · Single, not as a title.
        [$title, $format] = self::formatFromName($title, $view->int('trackCount'));

        return [
            'kind' => self::kind(),
            'headline' => $title,
            'facets' => array_filter([
                'f_catalog' => ['release_type' => $format],
                'f_link' => $view->string('collectionViewUrl') === null ? null : ['url' => $view->string('collectionViewUrl')],
                'f_published' => $view->string('releaseDate') === null ? null : ['published_from' => $view->string('releaseDate')],
                'f_authored' => $view->string('artistName') === null ? null : ['creator' => $view->string('artistName')],
            ]),
            'tags' => $genre === null ? [] : [['tag' => $genre, 'tag_type' => 'genre']],
            // 1200x1200 (R10 best quality): mzstatic serves any square size by
            // path, and the lookup only hands us the 100px thumbnail.
            'media' => $view->string('artworkUrl100') === null ? [] : [
                ['role' => 'cover', 'url' => AppleMusicConnector::upscaleArtwork((string) $view->string('artworkUrl100'))],
            ],
        ];
    }

    /**
     * @return array{0: string, 1: string} [clean title, album|ep|single]
     */
    public static function formatFromName(string $title, ?int $trackCount): array
    {
        if (preg_match('/^(.*\S)\s+-\s+(Single|EP)$/iu', $title, $m)) {
            return [$m[1], strtolower($m[2])];
        }
        if ($trackCount !== null && $trackCount > 0) {
            if ($trackCount <= 3) {
                return [$title, 'single'];
            }
            if ($trackCount <= 6) {
                return [$title, 'ep'];
            }
        }

        return [$title, 'album'];
    }
}
