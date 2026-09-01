<?php

namespace App\Services\Platforms;

use App\Services\Cache\ScrapeCreatorsBudget;
use App\Services\Platforms\ScrapeCreators\AmazonShopNormalizer;
use App\Services\Platforms\ScrapeCreators\ScrapeCreatorsClient;
use Illuminate\Support\Facades\Log;

// Item 10b (2026-09-01): the Amazon influencer-storefront vendor fetch —
// the TwitchScraper seam applied to the shop pool. ScrapeCreators is the
// ONLY path (Amazon bot-blocks a direct scrape outright — the reason
// WebsiteLinkHarvester filed it LINK_ONLY in the first place), so every
// miss (no key, budget denied, transport, husk, shape drift) returns null
// and the caller no-ops — never "this storefront is empty".
//
// REST path VERIFIED against the vendor's own OpenAPI
// (docs.scrapecreators.com/v1/amazon/shop/openapi.json, fetched 2026-09-01):
// GET /v1/amazon/shop?url=<storefront URL> — the SLASH form. The adapter
// unit's normalizer docblock says "/v1/amazon-shop"; that hyphenated
// spelling appears nowhere in the vendor spec.
//
// Budget contract (Item 8 adapter notes, verbatim from the Twitch/Pinterest
// lanes): claim BEFORE the call, release on transport-null, keep the slot
// spent on billed husks — NotFound bills a credit as success:true, so the
// gate is payload shape (AmazonShopNormalizer), never HTTP status. Source
// key 'amazon' (config partna.limits.scrapecreators.sources.amazon).
class AmazonShopScraper
{
    /**
     * The content.storefronts provider string for this lane. Deliberately a
     * NEW provider, not ShopProviderDetector::PROVIDER_GENERIC — see
     * AmazonShopConnectJob's docblock for the consumer-by-consumer decision.
     */
    public const PROVIDER = 'amazon-shop';

    /**
     * amazon.com implies USD at the store row: /v1/amazon/shop serves the
     * amazon.com storefront page and its payload carries NO currency field
     * anywhere (recorded 2026-09-01), so the store-level currency —
     * ShopProductProjection::fromBlob()'s $storeCurrency — is stamped here,
     * at the one place that knows which marketplace answered.
     */
    public const CURRENCY = 'USD';

    /** ScrapeCreatorsBudget source key — the G2 cap already provisioned. */
    private const SOURCE = 'amazon';

    public function __construct(
        private readonly ScrapeCreatorsClient $client,
        private readonly ScrapeCreatorsBudget $budget,
        private readonly AmazonShopNormalizer $normalizer,
    ) {}

    /**
     * The storefront handle inside an amazon.com/shop/<handle> URL, else
     * null. amazon.com ONLY (with or without www/scheme): the CURRENCY
     * contract above is a statement about the .com marketplace, so another
     * TLD's storefront must be refused here rather than minted with a
     * currency that is a guess. Lowercased — storefront URLs resolve
     * case-insensitively and the handle is this lane's external_ref, where
     * two casings of one storefront must not mint two stores (the
     * PinterestConnector handle rule).
     */
    public static function handleFromUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (preg_match('~^https?://~i', $url) !== 1) {
            $url = 'https://'.$url;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (preg_replace('~^www\.~', '', $host) !== 'amazon.com') {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        if (preg_match('~^/shop/([A-Za-z0-9._-]{1,100})(?:/|$)~', $path, $m) !== 1) {
            return null;
        }

        return strtolower($m[1]);
    }

    /**
     * The canonical storefront URL for a handle — what the vendor is asked
     * for, and what the store row's url column holds. Query params from the
     * pasted link (ref codes, list anchors) never reach either.
     */
    public static function storefrontUrlFor(string $handle): string
    {
        return 'https://www.amazon.com/shop/'.$handle;
    }

    /**
     * One storefront page, normalized: the identity (name/avatar) and the
     * trendingPicks as product-contract blobs, or null on any miss. Shape:
     * AmazonShopNormalizer::normalize().
     *
     * @return array{products: non-empty-list<array{url: string, image: string, productId?: string, price?: string}>, name?: string, avatar?: string}|null
     */
    public function fetchStorefront(string $handle, ?string $userId = null): ?array
    {
        if (! $this->client->enabled() || ! $this->budget->tryClaim(self::SOURCE)) {
            return null;
        }

        $body = $this->client->get('/v1/amazon/shop', ['url' => self::storefrontUrlFor($handle)], $userId);
        if ($body === null) {
            $this->budget->release(self::SOURCE);

            return null;
        }

        $page = $this->normalizer->normalize($body);
        if ($page === null) {
            // Success-shaped husk or shape drift — billed either way, the
            // slot stays spent. Handles are public storefront names, logged
            // raw like the Twitch lane's logins.
            Log::info('scrapecreators.amazon_shop.unusable_shape', ['handle' => $handle]);

            return null;
        }

        return $page;
    }
}
