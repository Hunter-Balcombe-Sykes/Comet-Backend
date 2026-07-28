<?php

namespace App\Ingest\Projection;

/**
 * Places review → the `review` item kind (Sample stream: latest-from-Google,
 * never pinned, never edited). Author fields may be absent — unclaimed
 * accounts land the record post-redaction, and the projection must render
 * that honestly rather than require what redaction removed.
 */
class GoogleBusinessReviewProjector implements Projector
{
    public static function version(): int
    {
        return 1;
    }

    public static function kind(): string
    {
        return 'review';
    }

    public function project(RecordView $view): ?array
    {
        $rating = $view->float('rating');
        if ($rating === null) {
            return null;
        }

        $author = $view->string('author');

        return [
            'kind' => self::kind(),
            'headline' => $author ?? 'Google review',
            'facets' => array_filter([
                'f_review' => [
                    'author_name' => $author,
                    'author_photo_url' => $view->string('author_photo'),
                    'rating' => $rating,
                    'text' => $view->string('text'),
                    'reviewed_at' => $view->string('publish_time'),
                ],
                'f_rated' => ['rating' => $rating, 'rating_max' => 5.0],
                'f_published' => $view->string('publish_time') === null ? null : [
                    'published_from' => $view->string('publish_time'),
                    // The vendor's own wording ("3 months ago") is provenance
                    // for the dashboard; the public document renders absolute.
                    'verbatim' => $view->string('published_ago'),
                ],
            ]),
        ];
    }
}
