<?php

namespace App\Ingest\Connectors;

use App\Ingest\Manifest\CostClass;
use App\Ingest\Manifest\Manifest;
use App\Ingest\Manifest\SourceKey;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Manifest\StreamSpec;
use App\Ingest\Message\Message;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Runtime\Connector;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

/**
 * TikTok Shop via the ScrapeCreators vendor lane (Item 10b, 2026-09-01) —
 * the Pinterest pattern with the reviews-pool target: the billed effect is
 * ('vendor', 'tiktok_shop') on TiktokShopVendorDriver, there is no Apify
 * actor behind it, and CostClass::Actor is what keeps it off the scheduler by
 * construction (auto_sync=false at provisioning) with eagerOnConnect the ONE
 * trigger. The daily ceiling is ScrapeCreatorsBudget's 'tiktok_shop' source
 * cap, claimed per call inside the driver. `hosts` is empty because nothing
 * here fetches TikTok over HTTP.
 *
 * The identifier is the storefront's SELLER ID — the same value the shop
 * lane stores as content.storefronts.external_ref, so the two lanes of one
 * connected store can never name it two ways. (The driver tolerates a full
 * /shop/store/ URL too, through the same TiktokShopScraper::sellerIdFrom.)
 *
 * One stream off the vendor rows:
 *   - `reviews` (Sample → reviews pool): top products' review pages in the
 *     vendor's best-selling order. Sample + null orderField because a review
 *     stream must NEVER delete — the fresha/google_business contract — and a
 *     per-product page walk is a vendor-shaped sample of the storefront, so
 *     absence never means deletion. Products themselves are NOT a stream
 *     here: they enter the shop pool through the provider seam
 *     (TiktokShopScraper → ShopContentWriter::syncStore), never the ingest
 *     pools.
 *
 * Reviewer PII takes the fresha/google_business posture: an unclaimed owner
 * never held reviewer identities by consent, so author fields strip
 * pre-claim; review TEXT survives.
 */
class TiktokShopConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('tiktok_shop'),
            identifierKind: 'id',
            hosts: [],
            streams: [
                'reviews' => new StreamSpec(
                    name: 'reviews',
                    target: 'review',
                    profile: SourceProfile::Sample,
                    requires: ['rating'],
                    volatile: [],
                    orderField: null,
                ),
            ],
            cost: CostClass::Actor,
            defaultIntervalSeconds: 604800,
            eagerOnConnect: true,
            redactions: ['author', 'author_photo'],
            redactionScopes: [
                'author' => 'when_unclaimed',
                'author_photo' => 'when_unclaimed',
            ],
        );
    }

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable
    {
        $sellerId = trim($pull->identifier);

        $effect = $io->effect('vendor', 'tiktok_shop', ['seller_id' => $sellerId]);

        if (($effect['status'] ?? null) !== 'ok') {
            // A refused/abandoned/failed ledger verdict is the budget doing
            // its job, not a crash — same fold as an unreachable vendor.
            yield new Unavailable("tiktok_shop vendor effect returned status '{$effect['status']}'");

            return;
        }

        $items = [];
        foreach ((array) $effect['data'] as $row) {
            $item = is_array($row) ? $this->mapReview($row) : null;
            if ($item !== null) {
                $items[] = $item;
            }
        }

        if ($items === []) {
            yield new Note('no_reviews', 'No reviews parsed from the vendor result');

            return;
        }

        // NO recency re-sort: the driver's row order is the vendor's own
        // best-selling product walk, and a Sample stream claims nothing about
        // order anyway.
        $limit = $pull->scopeLimit();
        if ($limit !== null) {
            $items = array_slice($items, 0, $limit);
        }

        foreach ($items as $item) {
            yield new Record('reviews', $item['review_id'], $item);
        }
    }

    /** @return array<string, mixed>|null */
    private function mapReview(array $row): ?array
    {
        $id = trim((string) ($row['review_id'] ?? ''));
        $rating = $row['rating'] ?? null;
        if (preg_match('/^\d+$/', $id) !== 1 || ! is_numeric($rating)) {
            return null;
        }

        // The normalizer's vocabulary plus the driver's product context,
        // re-gated key by key so a driver row can never smuggle an
        // unexpected field into a persisted Record.
        return array_filter([
            'review_id' => $id,
            'rating' => (float) $rating,
            'text' => $this->str($row['text'] ?? null),
            'author' => $this->str($row['author'] ?? null),
            'author_photo' => $this->str($row['author_photo'] ?? null),
            'publish_time' => $this->str($row['publish_time'] ?? null),
            'verified' => ($row['verified'] ?? null) === true ? true : null,
            'variant' => $this->str($row['variant'] ?? null),
            'product_id' => $this->str($row['product_id'] ?? null),
            'product_title' => $this->str($row['product_title'] ?? null),
            'product_url' => $this->str($row['product_url'] ?? null),
            'product_rating' => is_numeric($row['product_rating'] ?? null) ? (float) $row['product_rating'] : null,
            'product_rating_count' => is_numeric($row['product_rating_count'] ?? null) ? (int) $row['product_rating_count'] : null,
        ], static fn ($v) => $v !== null);
    }

    private function str(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return ($value === null || $value === '') ? null : $value;
    }
}
