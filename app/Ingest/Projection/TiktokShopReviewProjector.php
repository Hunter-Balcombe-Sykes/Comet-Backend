<?php

namespace App\Ingest\Projection;

/**
 * TikTok Shop product review → the `review` item kind (Item 10b, 2026-09-01).
 * FreshaReviewProjector's twin: Sample stream, never pinned, never edited;
 * author fields may be absent for two honest reasons — unclaimed accounts
 * land records post-redaction (reviewer PII strips when_unclaimed), and
 * TikTok's own anonymous reviewers arrive with no avatar and a masked
 * display name ("E**n") that passes through as the vendor's public rendering.
 *
 * headline is NULL BY CONTRACT — the identical reasoning as the Fresha and
 * Google projectors' docblocks: a reviewer name in headline would fold into
 * f_text/headline_cache, copies outside everything that governs review PII
 * (redaction scopes, content:prune-orphaned-review-pii, the DSAR omission in
 * DataExportPayloadBuilder::streamContentFReview()).
 *
 * The source-stats seat is held by PRODUCT-level aggregates here:
 * product_rating / product_rating_count ride every row the way Fresha's
 * venue_rating and Google's place_rating do — source-level aggregates with
 * no item of their own, upserted (last non-null set per run) onto
 * content.source_stats. Rows from different products in one run carry each
 * product's own numbers; the writer's last-set-wins upsert makes that a
 * best-selling-product stat, an accepted vendor-shaped approximation.
 *
 * The record's variant / verified / product_* context stays on the doc,
 * unprojected: f_review's column list (ProjectionWriter) has no home for
 * them and singletonFacetRow drops unlisted columns silently — landing them
 * would be a quiet no-op posing as data.
 */
class TiktokShopReviewProjector implements Projector
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

        $stats = array_filter([
            'rating_avg' => $view->float('product_rating'),
            'rating_count' => $view->int('product_rating_count'),
        ], static fn ($v) => $v !== null);

        $projection = [
            'kind' => self::kind(),
            'headline' => null,
            'facets' => array_filter([
                'f_review' => array_filter([
                    'author_name' => $view->string('author'),
                    'author_photo_url' => $view->string('author_photo'),
                    'rating' => $rating,
                    'text' => $view->string('text'),
                    'reviewed_at' => $view->string('publish_time'),
                ], static fn ($v) => $v !== null),
                'f_rated' => ['rating' => $rating, 'rating_max' => 5.0],
                // No `verbatim`: the vendor exposes only the ms-epoch the
                // normalizer already converted, never its own "ago" wording.
                'f_published' => $view->string('publish_time') === null ? null : [
                    'published_from' => $view->string('publish_time'),
                ],
            ]),
        ];

        if ($stats !== []) {
            $projection['source_stats'] = $stats;
        }

        return $projection;
    }
}
