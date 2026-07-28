<?php

namespace App\Ingest\Projection;

/**
 * Substack RSS post → the `article` item kind (dormant on the sitepage until
 * a Posts section exists; landing it now is what makes that section instant
 * later).
 */
class SubstackArticleProjector implements Projector
{
    public static function version(): int
    {
        return 1;
    }

    public static function kind(): string
    {
        return 'article';
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
            ]),
        ];
    }
}
