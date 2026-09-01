<?php

namespace App\Ingest\Runtime\Effects;

use App\Ingest\Runtime\EffectNotAttempted;
use App\Services\Cache\ScrapeCreatorsBudget;
use App\Services\Platforms\ScrapeCreators\ScrapeCreatorsClient;
use App\Services\Platforms\ScrapeCreators\TiktokShopProductsNormalizer;
use App\Services\Platforms\ScrapeCreators\TiktokShopReviewsNormalizer;
use App\Services\Platforms\TiktokShopScraper;
use Illuminate\Support\Facades\Log;

/**
 * ('vendor', 'tiktok_shop') — product reviews into the reviews pool (Item
 * 10b, 2026-09-01). The PinterestVendorDriver shape verbatim: ScrapeCreators-
 * only, no Apify actor behind it, so a vendor miss is this driver's own
 * noAnswer rather than someone else's fall-through.
 *
 * ORDERING IS LOAD-BEARING, as in every billed driver: every check that can
 * refuse a run happens BEFORE the first budget claim. Refusals before a claim
 * throw EffectNotAttempted (ledger claim deleted, digest free to retry);
 * after the first claim only answered/noAnswer may leave.
 *
 * Two endpoints per run, each call one budget slot: the storefront's product
 * list first (discovery — reviews are a per-PRODUCT endpoint, so the walk
 * needs product ids), then reviews for the top products in the vendor's own
 * best-selling order, up to review_products_per_run. Budget rules are the
 * Item 8 contract verbatim: claim before the call, release on transport-null,
 * keep the slot spent on billed husks. One walk difference from Pinterest: a
 * husk-shaped reviews page CONTINUES to the next product instead of breaking,
 * because a product with no reviews yet answers exactly like a husk and is
 * routine mid-walk — the fan-out stays bounded by the per-run cap either way.
 * A mid-walk transport failure keeps the rows already landed — paid reviews
 * are not discarded.
 *
 * Every row leaves stamped with its product context (product_id /
 * product_title / product_url) on top of the normalizer's vocabulary, and
 * carries product_rating / product_rating_count for the projector's
 * source_stats — the venue-stats precedent.
 *
 * Empty rows ⇒ noAnswer, never answered([]) — a storefront whose reviews
 * cannot be read is indistinguishable from a vendor miss, and settling it ok
 * would serve "this shop has no reviews" for the whole freshness window.
 */
final class TiktokShopVendorDriver implements BilledEffectDriver
{
    private const SOURCE = 'tiktok_shop';

    public function supports(string $kind, string $name): bool
    {
        return $kind === 'vendor' && $name === self::SOURCE;
    }

    public function run(BilledEffectContext $ctx): BilledEffectResult
    {
        // Tolerates a full /shop/store/ URL as well as the bare id — same
        // resolution the shop lane uses, so the two can never disagree.
        $sellerId = TiktokShopScraper::sellerIdFrom((string) ($ctx->input['seller_id'] ?? ''));
        if ($sellerId === null) {
            return BilledEffectResult::noAnswer('tiktok_shop vendor effect carried no seller id');
        }

        $client = app(ScrapeCreatorsClient::class);
        if (! $client->enabled()) {
            // No fallback lane exists for tiktok_shop — a missing key refuses
            // the run outright instead of failing it, so the digest may retry
            // once the key lands.
            throw new EffectNotAttempted('no ScrapeCreators key configured for the tiktok_shop vendor');
        }

        $budget = app(ScrapeCreatorsBudget::class);
        if (! $budget->tryClaim(self::SOURCE)) {
            throw new EffectNotAttempted("ScrapeCreators daily cap reached for source '".self::SOURCE."'");
        }

        $body = $client->get('/v1/tiktok/shop/products', [
            'url' => TiktokShopScraper::storeUrlFor($sellerId),
            'region' => 'US',
            'sort_by' => 'top',
        ], $ctx->userId);
        if ($body === null) {
            $budget->release(self::SOURCE);

            return BilledEffectResult::noAnswer('tiktok_shop products call did not answer');
        }

        // From here the products call was billed upstream — the slot stays spent.
        $page = app(TiktokShopProductsNormalizer::class)->normalize($body);
        if ($page === null) {
            $this->log('tiktok_shop.vendor.unusable_shape', $ctx, ['endpoint' => 'products']);

            return BilledEffectResult::noAnswer('tiktok_shop products answer carried no usable storefront');
        }

        $rows = $this->reviewRows($page['products'], $client, $budget, $ctx);
        if ($rows === []) {
            return BilledEffectResult::noAnswer('tiktok_shop products yielded no readable reviews');
        }

        $this->log('tiktok_shop.vendor.ok', $ctx, ['rows' => count($rows)]);

        return BilledEffectResult::answered($rows);
    }

    /**
     * @param  non-empty-list<array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    private function reviewRows(array $products, ScrapeCreatorsClient $client, ScrapeCreatorsBudget $budget, BilledEffectContext $ctx): array
    {
        $productCap = max(1, (int) config('partna.limits.scrapecreators.tiktok_shop.review_products_per_run', 3));
        $limit = max(1, (int) config('partna.limits.scrapecreators.tiktok_shop.results_limit', 30));

        /** @var array<string, array<string, mixed>> $rows keyed by review id — belt and braces against a vendor double-list */
        $rows = [];
        $fetched = 0;

        foreach ($products as $product) {
            if ($fetched >= $productCap || count($rows) >= $limit) {
                break;
            }

            if (! $budget->tryClaim(self::SOURCE)) {
                break;
            }
            $fetched++;

            // 'product/reviews' with a SLASH — verified against the live API
            // 2026-09-02 (the hyphen variant 404s; a wildcard Http::fake hid
            // that until the first live run, so the lane test now pins the
            // exact path).
            $body = $client->get('/v1/tiktok/shop/product/reviews', [
                'product_id' => (string) $product['productId'],
                'region' => 'US',
                'page' => 1,
            ], $ctx->userId);
            if ($body === null) {
                $budget->release(self::SOURCE);
                break;
            }

            $pageRows = app(TiktokShopReviewsNormalizer::class)->rows($body);
            if ($pageRows === null) {
                // Routine, not a rotation signal: a product with no reviews
                // yet bills a husk of exactly this shape — walk on.
                $this->log('tiktok_shop.vendor.reviewless_product', $ctx, ['product_id' => $product['productId']]);

                continue;
            }

            foreach ($pageRows as $row) {
                $rows[(string) $row['review_id']] ??= $row + [
                    'product_id' => (string) $product['productId'],
                    'product_title' => $product['title'],
                    'product_url' => $product['url'],
                ];
            }
        }

        return array_slice(array_values($rows), 0, $limit);
    }

    /** @param array<string, mixed> $extra */
    private function log(string $event, BilledEffectContext $ctx, array $extra): void
    {
        // info level: cloud env:logs surfaces info, and a failed scrape must
        // be diagnosable from the stream.
        Log::info($event, $extra + [
            'source_id' => $ctx->sourceId,
            'run_id' => $ctx->runId,
            'user_id' => $ctx->userId,
        ]);
    }
}
