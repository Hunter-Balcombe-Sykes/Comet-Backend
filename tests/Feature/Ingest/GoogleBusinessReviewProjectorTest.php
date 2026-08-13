<?php

use App\Ingest\Projection\GoogleBusinessReviewProjector;
use App\Ingest\Projection\RecordView;

// Slice 6 §2.2: the projector's headline was the reviewer's display name, and
// ProjectionWriter folds any non-empty headline into f_text -> headline_cache
// — two copies of third-party PII that redaction, content:prune-orphaned-
// review-pii and the DSAR omission all fail to reach. Null headline is the
// fix, matching GoogleBusinessMediaProjector's null-by-contract.

it('never puts the reviewer name in the headline', function () {
    $projection = (new GoogleBusinessReviewProjector)->project(new RecordView([
        'review_id' => 'places/X/reviews/Y',
        'rating' => 5.0,
        'text' => 'Excellent service.',
        'author' => 'A Real Person',
        'author_uri' => 'https://maps.google.com/contrib/123',
        'author_photo' => 'https://lh3.googleusercontent.com/a/abc',
        'publish_time' => '2026-07-01T10:00:00Z',
    ], 'places/X/reviews/Y'));

    expect($projection['headline'])->toBeNull();

    // The name is still landed where redaction and pruning govern it.
    expect($projection['facets']['f_review']['author_name'])->toBe('A Real Person');
});

it('carries author_uri onto the f_review facet', function () {
    $projection = (new GoogleBusinessReviewProjector)->project(new RecordView([
        'review_id' => 'r1', 'rating' => 4.0,
        'author_uri' => 'https://maps.google.com/contrib/123',
    ], 'r1'));

    expect($projection['facets']['f_review']['author_uri'])
        ->toBe('https://maps.google.com/contrib/123');
});

// Redaction strips author fields BEFORE landing for unclaimed owners, so the
// projector must render their absence rather than require them.
it('projects a redacted record without author fields', function () {
    $projection = (new GoogleBusinessReviewProjector)->project(new RecordView([
        'review_id' => 'r2', 'rating' => 3.0, 'text' => 'Fine.',
    ], 'r2'));

    expect($projection['headline'])->toBeNull()
        ->and($projection['facets']['f_review']['author_name'])->toBeNull()
        ->and($projection['facets']['f_review']['author_uri'])->toBeNull()
        ->and($projection['facets']['f_review']['rating'])->toBe(3.0);
});

it('projects nothing when the record has no rating', function () {
    expect((new GoogleBusinessReviewProjector)->project(new RecordView(['review_id' => 'r3'], 'r3')))
        ->toBeNull();
});

// Slice 6 §5.2: the place's aggregates ride on every review record because
// they describe the PLACE and have no item to hang a facet on. The writer
// takes the last non-null one per run.
it('carries the place aggregates as source_stats', function () {
    $projection = (new GoogleBusinessReviewProjector)->project(new RecordView([
        'review_id' => 'r1', 'rating' => 5.0,
        'place_rating' => 4.7, 'place_rating_count' => 312,
        'place_review_summary' => 'Customers praise the friendly staff.',
    ], 'r1'));

    expect($projection['source_stats'])->toBe([
        'rating_avg' => 4.7,
        'rating_count' => 312,
        'summary_text' => 'Customers praise the friendly staff.',
    ]);
});

it('omits source_stats when the place carried no aggregates', function () {
    $projection = (new GoogleBusinessReviewProjector)->project(new RecordView([
        'review_id' => 'r1', 'rating' => 5.0,
    ], 'r1'));

    expect($projection)->not->toHaveKey('source_stats');
});
