<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Services\Content\PastedLinkClassifier;
use App\Services\Http\FetchBudget;
use App\Services\Platforms\GenericShopScraper;
use App\Services\Platforms\LinkCardScraper;
use App\Services\Platforms\StorefrontMarkers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// What a link WOULD become, before it exists (2026-08-17). The dashboard's
// two-step "Add link" pastes a URL, shows what we read off the page (title,
// description, share image, favicon) for the owner to check and change, and
// only THEN creates the item — through PoolItemCreateController, carrying the
// checked values. Without this the flow had to create first and enrich later.
//
// LinkCardScraper is the same one-shot snapshot the connect lane and the
// enrichment job use, behind SafeUrlFetcher (SSRF-guarded), so this reads
// nothing the backend does not already read. Writes nothing. Its own throttle
// — like /routing/preview — because it fires per paste, not per save.
class LinkPreviewController extends ApiController
{
    use ResolveCurrentUser;

    public function __construct(
        private readonly LinkCardScraper $scraper,
        private readonly GenericShopScraper $shop,
        private readonly FetchBudget $budget,
        private readonly PastedLinkClassifier $classifier,
    ) {}

    /**
     * POST /api/content/links/classify  { url } — the pure-grammar half of
     * the preview, alone: no page fetch, so the pool add sheets can ask it
     * per keystroke settle and show cross-pool/account guidance in step 1.
     */
    public function classify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'min:3', 'max:2048'],
        ]);

        return $this->success($this->classifier->classify($data['url']));
    }

    /** POST /api/content/links/preview  { url, intent? }  intent: "link" (default) | "product" */
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'min:3', 'max:2048'],
            'intent' => ['nullable', 'string', 'in:link,product'],
        ]);

        $url = $this->scraper->normalizeUrl($data['url']);
        if ($url === null) {
            return $this->error('Enter a valid link (https://...).', 422);
        }

        // PRODUCT intent (2026-08-17, the Sell pool's Add): the same page read
        // the create endpoint will make — JSON-LD Product/Offer, OG fallback
        // — so step 2 shows the price, stock and gallery BEFORE anything is
        // created, and a store homepage is caught here rather than on Add.
        if (($data['intent'] ?? 'link') === 'product') {
            $seconds = (float) config('partna.http_fetch.connect_budget_seconds', 20);
            $read = $this->budget->open($seconds, fn () => $this->shop->readProductPage($url));
            if ($read['outcome'] === GenericShopScraper::OUTCOME_STORE_PAGE) {
                return $this->error(
                    "That looks like a store's homepage, not a product page. Connect it as a platform to bring in its products, or paste a specific product's page.",
                    422,
                    extra: ['code' => 'store_homepage', 'storeUrl' => $read['storeUrl']],
                );
            }
            $product = $read['product'];
            if ($product !== null) {
                $images = array_values(array_filter((array) ($product['images'] ?? []), 'is_string'));

                return $this->success([
                    'url' => $product['url'] ?? $url,
                    'name' => $product['title'] ?? null,
                    'description' => $product['description'] ?? null,
                    'favicon' => null,
                    'logo' => $product['image'] ?? ($images[0] ?? null),
                    'fetched' => true,
                    'product' => [
                        'price' => $product['price'] ?? null,
                        'currency' => $product['currency'] ?? null,
                        'availability' => ($product['available'] ?? true) ? 'in_stock' : 'out_of_stock',
                        'images' => $images,
                    ],
                ]);
            }
            // No product markup: fall through to the link card, product null.
        }

        // snapshotOrMinimal, never a 404: a bot-blocked or JS-only page still
        // yields a usable card (host as the name, a service favicon), which is
        // exactly what the create endpoint would have stored anyway.
        $card = $this->scraper->snapshotOrMinimal($url);

        // T4 (2026-08-20): the page we just read IS a storefront — its HTML
        // carries a store platform's runtime markers — so the sheet can offer
        // "connect it as a store" beside the card instead of letting a pasted
        // natalieanne.com quietly become a flat link. The pure grammar below
        // can only know platform-OWNED hosts (myshopify.com); this marker
        // read covers merchant domains, off the fetch already in hand.
        $storefront = $card['storefront'] ?? null;
        $scheme = parse_url($card['url'], PHP_URL_SCHEME);
        $host = parse_url($card['url'], PHP_URL_HOST);

        return $this->success([
            'url' => $card['url'],
            'name' => $card['name'],
            'description' => $card['description'],
            'favicon' => $card['favicon'],
            'logo' => $card['logo'],
            // The dashboard shows a quieter "we couldn't read the page" when
            // the snapshot itself failed and only the minimal card came back.
            'fetched' => $card['description'] !== null || $card['logo'] !== null || $card['name'] !== (parse_url($url, PHP_URL_HOST) ?: $url),
            'product' => null,
            // The pure-grammar classification, so the Links sheet can offer
            // "this looks like a track — add it on Listen" in step 1.
            'classification' => $this->classifier->classify($url),
            'store' => $storefront === null || ! is_string($scheme) || ! is_string($host) ? null : [
                'provider' => $storefront,
                'label' => StorefrontMarkers::LABELS[$storefront] ?? $storefront,
                'url' => strtolower($scheme).'://'.strtolower($host).'/',
            ],
            // T8: a DEEP page carrying product markup reads as a product —
            // the sheet offers "add it on your Sell page" (whose STORE-FIRST
            // read is the authority). A root page with markup is a
            // storefront; the store answer above already covers it.
            'productPage' => (bool) ($card['productMarkup'] ?? false)
                && trim((string) (parse_url($card['url'], PHP_URL_PATH) ?? ''), '/') !== '',
        ]);
    }
}
