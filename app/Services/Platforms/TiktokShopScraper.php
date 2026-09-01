<?php

namespace App\Services\Platforms;

use App\Services\Cache\ScrapeCreatorsBudget;
use App\Services\Platforms\ScrapeCreators\ScrapeCreatorsClient;
use App\Services\Platforms\ScrapeCreators\TiktokShopProductsNormalizer;
use Illuminate\Support\Facades\Log;

// Item 10b (2026-09-01): TikTok Shop storefront → the shop pool through the
// SAME provider seam as every other store. ShopCatalog::providerProducts
// dispatches on StoreRecord.provider, and fetchProducts() here returns the
// exact catalogue blob ShopContentWriter::syncStore / ShopProductProjection::
// fromBlob consume — the contract TiktokShopProductsNormalizer pins against
// the recorded Goli payload. No bespoke lane anywhere.
//
// Identity is the SELLER ID (content.storefronts.external_ref). Trial-verified
// 2026-09-01: the vendor IGNORES the store URL's slug segment entirely —
// /shop/store/x/7495794203056835079 answers the Goli storefront verbatim (and
// echoes the fake slug back in shop_link, so shop_link is not authoritative
// for the slug either). One canonical request URL is therefore constructible
// from the id alone, and a connect by bare seller id needs no slug lookup.
//
// Unlike the keyless sibling scrapers this lane is BILLED, so the Item 8
// budget contract applies here (the "or scraper" half of the claim-site rule):
// claim before the call, release on transport-null, keep the slot spent on
// billed husks (NotFound bills with success:true — shape, not HTTP). Region
// is pinned US: the vendor's region enum has no AU value and US is its only
// reliable region (the normalizer's recorded note).
class TiktokShopScraper
{
    /** content.storefronts.provider for this lane's stores (also the slug + surface prefix). */
    public const PROVIDER = 'tiktok_shop';

    /** Region is pinned US (see class docblock), so the catalogue prices in USD. */
    public const CURRENCY = 'USD';

    private const SOURCE = 'tiktok_shop';

    public function __construct(
        private readonly ScrapeCreatorsClient $client,
        private readonly ScrapeCreatorsBudget $budget,
        private readonly TiktokShopProductsNormalizer $normalizer,
    ) {}

    /**
     * The provider seam's contract (ShopCatalog::providerProducts →
     * syncLatest): catalogue blob rows on success, abort(502) on everything
     * else. There is deliberately NO empty-list return: the normalizer folds
     * "no usable products" into the same null as a billed NotFound husk,
     * and its contract says a husk must never read as an empty storefront —
     * so every miss lands on syncLatest's unreachable path (logged, breaker-
     * counted) and a stale catalogue outlives a vendor wobble, which is the
     * recoverable direction. The failure CLASS (no key / budget / transport /
     * husk) is distinguished in storefront()'s logs, not in the status code.
     *
     * @return non-empty-list<array<string, mixed>>
     */
    public function fetchProducts(string $urlOrSellerId): array
    {
        $page = $this->storefront($urlOrSellerId);
        if ($page === null) {
            abort(502, 'TikTok Shop vendor answered nothing usable for this storefront — a vendor miss, never an empty catalogue.');
        }

        return $page['products'];
    }

    /**
     * One storefront read: shop identity + on-sale catalogue, or null on any
     * vendor miss. The connect wiring reads `shop` (seller_id → external_ref,
     * name/logo/url → display profile) and the seam above reads `products`
     * off the very same call — one claim serves both.
     *
     * @return array{shop: array{seller_id: string, name: string, url?: string, logo?: string, rating?: float, review_count?: int}, products: non-empty-list<array<string, mixed>>}|null
     */
    public function storefront(string $urlOrSellerId): ?array
    {
        $sellerId = self::sellerIdFrom($urlOrSellerId);
        if ($sellerId === null) {
            Log::info('tiktok_shop.scraper.bad_reference', ['input' => mb_substr($urlOrSellerId, 0, 200)]);

            return null;
        }

        if (! $this->client->enabled()) {
            Log::info('tiktok_shop.scraper.no_key', ['seller_id' => $sellerId]);

            return null;
        }

        if (! $this->budget->tryClaim(self::SOURCE)) {
            Log::info('tiktok_shop.scraper.budget_refused', ['seller_id' => $sellerId]);

            return null;
        }

        $body = $this->client->get('/v1/tiktok/shop/products', [
            'url' => self::storeUrlFor($sellerId),
            'region' => 'US',
            'sort_by' => 'top',
        ]);
        if ($body === null) {
            // Transport-null: nothing was billed upstream — hand the slot back.
            $this->budget->release(self::SOURCE);
            Log::info('tiktok_shop.scraper.no_answer', ['seller_id' => $sellerId]);

            return null;
        }

        // From here the call was billed — the slot stays spent, husk or not.
        $page = $this->normalizer->normalize($body);
        if ($page === null) {
            Log::info('tiktok_shop.scraper.unusable_shape', ['seller_id' => $sellerId]);

            return null;
        }

        return $page;
    }

    /**
     * The seller id out of whatever a connect surface holds: a bare numeric
     * id, or a /shop/store/ URL whose trailing segment carries it. Null means
     * "not a TikTok Shop reference" — never a guess.
     */
    public static function sellerIdFrom(string $input): ?string
    {
        $input = trim($input);
        if (preg_match('/^\d{6,}$/', $input) === 1) {
            return $input;
        }
        if (preg_match('#^(?:https?://)?(?:www\.)?tiktok\.com/shop/store/(?:[^/?\#]+/)?(\d{6,})(?:[/?\#]|$)#i', $input, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * The canonical request URL for a seller id. The literal slug is
     * arbitrary by the trial above; 's' is pinned so every run for one store
     * fires the identical URL.
     */
    public static function storeUrlFor(string $sellerId): string
    {
        return 'https://www.tiktok.com/shop/store/s/'.$sellerId;
    }
}
