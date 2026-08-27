<?php

namespace App\Ingest\Projection;

/**
 * Fresha venue review → the `review` item kind (T23b, owner 2026-08-28).
 * Same contract as GoogleBusinessReviewProjector: Sample stream, never
 * pinned, never edited; author fields may be absent because unclaimed
 * accounts land records post-redaction (Fresha reviewer PII strips
 * when_unclaimed) and the projection renders that honestly.
 *
 * headline is NULL BY CONTRACT — the identical reasoning as the Google
 * projector's docblock: a reviewer name in headline would fold into
 * f_text/headline_cache, copies outside everything that governs review PII
 * (redaction scopes, content:prune-orphaned-review-pii, the DSAR omission
 * in DataExportPayloadBuilder::streamContentFReview()).
 *
 * The one Fresha addition: `staff_name` inside f_review — Fresha's own
 * structured attribution ("with Emma"), the professional's OWN name, not
 * reviewer PII. Employee-mode connections only ever land reviews already
 * filtered to that staff member (FreshaConnector::reviewsMessages).
 */
class FreshaReviewProjector implements Projector
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

        // Venue-level aggregates ridden on every record (no item of their
        // own); the writer upserts the last non-null set per run onto
        // content.source_stats — identical across a run.
        $stats = array_filter([
            'rating_avg' => $view->float('venue_rating'),
            'rating_count' => $view->int('venue_rating_count'),
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
                    'staff_name' => $view->string('employee_name'),
                ], static fn ($v) => $v !== null),
                'f_rated' => ['rating' => $rating, 'rating_max' => 5.0],
                'f_published' => $view->string('publish_time') === null ? null : [
                    'published_from' => $view->string('publish_time'),
                    // Fresha's own footer wording ("1 month ago • Haircut •
                    // with Simon") — provenance for the dashboard; the
                    // public document renders absolute.
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
