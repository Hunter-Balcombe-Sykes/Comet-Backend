<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 10b (2026-09-01): /v1/tiktok/shop/product-reviews → review rows in the
// exact field vocabulary the reviews pool's `review`-kind projectors read off
// a Record (FreshaConnector::mapReview is the template: review_id / rating /
// text / author / author_photo / publish_time), so the eventual TikTok Shop
// projector can be FreshaReviewProjector's twin rather than a new dialect.
// product_rating / product_rating_count ride every row the way Fresha's
// venue_rating and Google's place_rating do — source-level aggregates with no
// item of their own, destined for content.source_stats.
//
// Trial-verified quirks absorbed here (recorded payload 2026-09-01):
//  - review_time is EPOCH MILLISECONDS in a string ("1747497773104") —
//    converted to ISO here, or every review claims a year past 55,000.
//  - anonymous reviewers arrive with reviewer_id "" and NO
//    reviewer_avatar_url key, while reviewer_name still carries the masked
//    display value ("E**n") — masked names are the vendor's own public
//    rendering and pass through as the author.
//  - total_reviews / review_ratings.review_count are numeric STRINGS.
class TiktokShopReviewsNormalizer
{
    /** Matches Ingest\Support\Text::MAX_LENGTH's posture: bound stored review prose. */
    private const MAX_TEXT_LENGTH = 2000;

    /**
     * @param  array<string, mixed>  $body  one /v1/tiktok/shop/product-reviews page
     * @return non-empty-list<array<string, mixed>>|null null unless the page positively carries at least one
     *                                                   id-and-rating-bearing review — a billed NotFound husk must
     *                                                   read as "vendor miss", never as a review-less product.
     */
    public function rows(array $body): ?array
    {
        $list = $body['product_reviews'] ?? null;
        if (! is_array($list)) {
            return null;
        }

        $productRating = is_numeric(data_get($body, 'review_ratings.overall_score'))
            ? (float) data_get($body, 'review_ratings.overall_score')
            : null;
        $productCount = is_numeric($body['total_reviews'] ?? null) ? (int) $body['total_reviews'] : null;

        $rows = [];
        foreach ($list as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = trim((string) ($item['review_id'] ?? ''));
            $rating = $item['review_rating'] ?? null;
            if (preg_match('/^\d+$/', $id) !== 1 || ! is_numeric($rating)) {
                continue;
            }

            $text = is_string($item['review_text'] ?? null) ? trim($item['review_text']) : null;

            $rows[] = array_filter([
                'review_id' => $id,
                'rating' => (float) $rating,
                'text' => $text !== null && $text !== '' ? mb_substr($text, 0, self::MAX_TEXT_LENGTH) : null,
                'author' => $this->str($item['reviewer_name'] ?? null),
                'author_photo' => $this->str($item['reviewer_avatar_url'] ?? null),
                'publish_time' => $this->iso($item['review_time'] ?? null),
                'verified' => ($item['is_verified_purchase'] ?? null) === true ? true : null,
                'variant' => $this->str($item['sku_specification'] ?? null),
                'product_rating' => $productRating,
                'product_rating_count' => $productCount,
            ], static fn ($v) => $v !== null);
        }

        return $rows === [] ? null : $rows;
    }

    /** The millisecond-epoch quirk: "1747497773104" → 2025-05-17T16:02:53.000Z. */
    private function iso(mixed $value): ?string
    {
        if (! is_numeric($value) || (float) $value <= 0) {
            return null;
        }

        return gmdate('Y-m-d\TH:i:s.000\Z', (int) ((float) $value / 1000));
    }

    private function str(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return ($value === null || $value === '') ? null : $value;
    }
}
