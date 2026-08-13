<?php

namespace App\Ingest\Projection;

/**
 * Places review → the `review` item kind (Sample stream: latest-from-Google,
 * never pinned, never edited). Author fields may be absent — unclaimed
 * accounts land the record post-redaction, and the projection must render
 * that honestly rather than require what redaction removed.
 *
 * headline is NULL BY CONTRACT (slice 6 §2.3), the same rule
 * GoogleBusinessMediaProjector follows. It used to be the reviewer's display
 * name, and ProjectionWriter folds any non-empty headline into the f_text
 * facet, which then resolves into content.items.headline_cache — two copies
 * of third-party PII outside everything that governs it: Manifest::
 * $redactionScopes, content:prune-orphaned-review-pii, and the DSAR omission
 * in DataExportPayloadBuilder::streamContentFReview(). KindRegistry already
 * declares this kind's facets as f_review/f_rated/f_published with no f_text.
 *
 * Do not restore a headline here to give the review card a title. The card
 * renders from the pool payload's `review` block, which reads f_review — the
 * one copy that redaction, pruning and DSAR all reach.
 */
class GoogleBusinessReviewProjector implements Projector
{
    public static function version(): int
    {
        return 2;
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

        // Slice 6 §5.2: source-level aggregates about the PLACE, ridden in on
        // every review record because they have no item of their own. The
        // writer upserts the last non-null set per run onto
        // content.source_stats — they are identical across a run.
        $stats = array_filter([
            'rating_avg' => $view->float('place_rating'),
            'rating_count' => $view->int('place_rating_count'),
            'summary_text' => $view->string('place_review_summary'),
        ], static fn ($v) => $v !== null);

        $projection = [
            'kind' => self::kind(),
            'headline' => null,
            'facets' => array_filter([
                'f_review' => [
                    'author_name' => $view->string('author'),
                    'author_photo_url' => $view->string('author_photo'),
                    'author_uri' => $view->string('author_uri'),
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

        if ($stats !== []) {
            $projection['source_stats'] = $stats;
        }

        return $projection;
    }
}
