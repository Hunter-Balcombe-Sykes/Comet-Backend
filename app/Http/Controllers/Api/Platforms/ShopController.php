<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\AddShopBrandRequest;
use App\Http\Requests\Platforms\AddShopProductRequest;
use App\Http\Requests\Platforms\SetShopProductsRequest;
use App\Http\Requests\Platforms\SubmitShopCatalogRequest;
use App\Http\Requests\Platforms\UpdateShopBrandRequest;
use App\Http\Requests\Platforms\UpdateShopSettingsRequest;
use App\Http\Resources\Platforms\ShopBrandResource;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\Site\ShopProduct;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\Concerns\JitteredTtl;
use App\Services\Http\FetchBudget;
use App\Services\Platforms\GenericShopScraper;
use App\Services\Platforms\IntegrationConnectionCacheRefresher;
use App\Services\Platforms\Payloads\ShopPayload;
use App\Services\Platforms\ShopCatalog;
use App\Services\Platforms\ShopifyScraper;
use App\Services\Platforms\ShopProviderDetector;
use App\Services\Platforms\SquarespaceScraper;
use App\Services\Platforms\WooCommerceScraper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

// PROVIDER-AGNOSTIC shop endpoints (formerly ShopifyController) — MULTI-BRAND.
// A user connects up to 5 stores by URL alone; ShopProviderDetector works out
// whether each one is Shopify, WooCommerce, Squarespace, Big Cartel, or a
// generic storefront with Product JSON-LD — the user never chooses. Each brand carries its provider,
// profile (name/favicon/logo), discount code, and chosen products.
//
// FOUND-25: brands + their chosen products live in site.shop_brands /
// site.shop_products (one row per brand / product), not in the connection's
// JSONB payload — the single 'shop' IntegrationConnection row is now just the
// lifecycle/authorization anchor, its payload a static MARKER. Every mutating
// method still writes that marker via writeConnection() so the create/update
// ability keeps firing off the same chokepoint. Because the marker never
// changes, IntegrationConnectionObserver's payload-dirty gate only fires on
// the FIRST connect — every mutating method below explicitly invokes
// IntegrationConnectionCacheRefresher once so brand/product edits still purge
// the sitepage edge cache and re-resolve design presets.
//
// Product selection is decoupled from connect: adding a brand stores it with
// zero products; the picker (GET .../products + PUT .../selection) runs any time.
//
// GET /selection returns a COMPAT flat view of the primary brand so partna-pages
// keeps rendering the Shop card until the skeleton is reworked for multi-brand.
class ShopController extends ApiController
{
    use JitteredTtl;
    use ManagesIntegrationConnection;
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    private const MAX_BRANDS = 5;

    // Reserved brand bucket holding individually-added products (not tied to a
    // connected store). Doesn't count against MAX_BRANDS.
    private const INDIVIDUAL_BRAND_ID = 'individual';

    private const MAX_INDIVIDUAL_PRODUCTS = 20;

    // How long the picker-warmed product catalog stays cached, so a PUT
    // /selection right after the picker opened reuses it instead of re-scraping.
    private const CATALOG_TTL_MINUTES = 10;

    // The connection row's payload shrinks to this marker (FOUND-25) — brand
    // data lives relationally now; the row itself is purely the lifecycle/
    // authorization anchor.
    private const MARKER = ['storage' => 'relational'];

    public function __construct(
        private readonly ShopProviderDetector $detector,
        private readonly ShopifyScraper $shopify,
        private readonly WooCommerceScraper $woocommerce,
        private readonly SquarespaceScraper $squarespace,
        private readonly GenericShopScraper $generic,
        private readonly IntegrationConnectionCacheRefresher $refresher,
        private readonly ShopCatalog $catalog,
        private readonly FetchBudget $budget,
    ) {}

    protected function platform(): string
    {
        return 'shop';
    }

    // GET /api/platforms/shop/brands — all connected brands.
    public function brands(Request $request): JsonResponse
    {
        return $this->success([
            'brands' => ShopBrandResource::collection($this->allBrands($this->currentUser($request)))->resolve(),
        ]);
    }

    // POST /api/platforms/shop/brands — add (or refresh) a brand. Detects the
    // provider, resolves the brand profile, dedups by canonical id, caps at
    // MAX_BRANDS, stores with the brand's existing products preserved.
    public function addBrand(AddShopBrandRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $validated = $request->validated();

        // Detection + scrape run outside the lock (slow external HTTP), bounded
        // by one budget spanning the whole cascade (detect → client-payload
        // fallback → brand profile fetch). Only the read→mutate→write cycle is
        // serialised. Both early-return branches (unsupported/unreachable) are
        // preserved byte-for-byte by signalling out of the closure via a struct.
        $seconds = (float) config('partna.http_fetch.connect_budget_seconds', 20);
        $outcome = $this->budget->open($seconds, function () use ($validated) {
            $detection = $this->detector->detectDetailed($validated['url']);
            $detected = $detection['detected'];

            // Server-side probe failed AND the dashboard supplied a browser-fetched
            // Store API payload — stores whose WAF blocks datacenter IPs (403s every
            // server request) still connect via the user's own browser.
            if ($detected === null && is_array($validated['storeApi'] ?? null)) {
                $detected = $this->detectFromClientPayload($validated['url'], $validated['storeApi']);
            }

            if ($detected === null) {
                return ['detected' => null, 'failure' => $detection['failure']];
            }

            [$brand, $detectedProducts] = $this->brandProfileFor($detected);

            return ['detected' => $detected, 'brand' => $brand, 'detectedProducts' => $detectedProducts];
        });

        if ($outcome['detected'] === null) {
            // Distinct code per failure kind (WS-B1.2) — the dashboard offers
            // an add-as-custom-link fallback on `unsupported_store`, and its
            // client-assisted Store API retry on `store_unreachable`.
            if ($outcome['failure'] === ShopProviderDetector::FAILURE_UNSUPPORTED) {
                return $this->error(
                    "This site isn't on a store platform we can connect — we support Shopify, WooCommerce, Squarespace, Big Cartel, and pages with standard product markup. You can add it as a custom link instead, or add individual products by their product page URLs.",
                    422,
                    extra: ['code' => 'unsupported_store'],
                );
            }

            return $this->error(
                "Couldn't connect that as a store — we look for Shopify, WooCommerce, or standard product markup on the page. Some sites block automated requests. You can still add individual products from any store: paste a product's own page URL instead.",
                422,
                extra: ['code' => 'store_unreachable'],
            );
        }

        $detected = $outcome['detected'];
        $brand = $outcome['brand'];
        $detectedProducts = $outcome['detectedProducts'];
        $id = $brand['id'];

        return $this->withConnectionLock($user, function () use ($user, $detected, $brand, $detectedProducts, $id, $validated) {
            $connection = $this->writeConnection($user, self::MARKER);

            $existing = ShopBrand::where('connection_id', $connection->id)->where('brand_id', $id)->first();
            // The reserved individual-products bucket doesn't occupy a store slot.
            $storeCount = ShopBrand::where('connection_id', $connection->id)->where('is_individual', false)->count();
            if (! $existing && $storeCount >= self::MAX_BRANDS) {
                return $this->error('You can connect up to '.self::MAX_BRANDS.' stores.', 422);
            }

            $discount = array_key_exists('discountCode', $validated)
                ? trim((string) $validated['discountCode'])
                : ($existing?->discount_code ?? '');

            $maxPosition = ShopBrand::where('connection_id', $connection->id)->max('position');
            $position = $existing?->position ?? (($maxPosition === null ? -1 : $maxPosition) + 1);

            $brandRow = ShopBrand::updateOrCreate(
                ['connection_id' => $connection->id, 'brand_id' => $id],
                [
                    'provider' => $detected['provider'],
                    'url' => $detected['origin'],
                    'source_url' => $detected['sourceUrl'],
                    'name' => $brand['name'],
                    'currency' => $brand['currency'] ?? null,
                    'favicon' => $brand['favicon'],
                    'logo' => $brand['logo'],
                    'discount_code' => $discount,
                    // Client-connected brands can't be re-scraped server-side; the
                    // flag routes product reads through the catalog cache + the
                    // client re-warm endpoint instead of abort(502)ing.
                    'fetch_mode' => $detected['fetchMode'] ?? null,
                    'is_individual' => false,
                    'position' => $position,
                ],
            );

            // The generic detector already read the page's products — warm the
            // picker catalog so the immediately-following GET is instant.
            if ($detectedProducts !== null) {
                Cache::put($this->catalogKey($id), $detectedProducts, self::applyJitter(self::CATALOG_TTL_MINUTES * 60));
            }

            // The connection's payload is a static marker (FOUND-25) — the
            // observer's payload-dirty gate won't fire for a brand add after the
            // first connect, so purge + preset-resolve explicitly, once.
            $this->refresher->refresh($connection);

            return $this->success((new ShopBrandResource($brandRow->fresh('products')->toBrandArray()))->resolve());
        });
    }

    // PATCH /api/platforms/shop/brands/{id} — update PER-BRAND settings: the
    // discount code and the referral URL (stored as its parsed query suffix).
    // Every field is optional; only what's present is applied.
    //
    // selectionMode + linkMode are still ACCEPTED here for backward compatibility
    // but are DORMANT as of 2026-07-08: both became one GLOBAL site setting
    // (site.sites.shop_auto_latest / shop_link_mode via /platforms/shop/settings).
    // The public payload always stamps linkMode from the global, and ShopFetch
    // gates auto-latest on the global — so a per-brand write to either column is
    // inert on the wire. The dashboard no longer sends them. (Setting
    // selectionMode=latest here still triggers a one-off immediate sync, harmless.)
    public function updateBrand(UpdateShopBrandRequest $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);
        $validated = $request->validated();

        return $this->withConnectionLock($user, function () use ($user, $id, $validated) {
            $connection = $this->connectionFor($user);
            $brand = $connection ? ShopBrand::where('connection_id', $connection->id)->where('brand_id', $id)->first() : null;
            if (! $brand) {
                return $this->error('Brand not found.', 404);
            }

            $updates = [];
            if (array_key_exists('discountCode', $validated)) {
                $updates['discount_code'] = trim((string) $validated['discountCode']);
            }
            if (array_key_exists('selectionMode', $validated)) {
                $updates['selection_mode'] = $validated['selectionMode'];
            }
            if (array_key_exists('linkMode', $validated)) {
                $updates['link_mode'] = $validated['linkMode'];
            }
            if (array_key_exists('referralUrl', $validated)) {
                $updates['referral_query'] = self::referralQueryFrom($validated['referralUrl']);
            }
            if ($updates !== []) {
                $brand->update($updates);
            }

            $syncFailed = false;
            if (($validated['selectionMode'] ?? null) === 'latest') {
                // Sync now (idempotent on re-set). null = reachable but empty;
                // HttpException = store unreachable (OBS-2: syncLatest() now
                // re-throws instead of swallowing it). Either way, keep the
                // mode (the scheduled refresh retries) but tell the dashboard
                // so it can message the delay instead of 500ing.
                try {
                    $seconds = (float) config('partna.http_fetch.connect_budget_seconds', 20);
                    $syncFailed = $this->budget->open($seconds, fn () => $this->catalog->syncLatest($brand->fresh('products'))) === null;
                } catch (HttpException) {
                    $syncFailed = true;
                }
            }

            $connection = $this->writeConnection($user, self::MARKER);
            $this->refresher->refresh($connection);

            $payload = (new ShopBrandResource($brand->fresh('products')->toBrandArray()))->resolve();
            $payload['latestSyncPending'] = $syncFailed;

            return $this->success($payload);
        });
    }

    /**
     * Extract the query-string "end bit" from a pasted referral URL. Accepts a
     * full URL (`https://store.com/?ref=abc` → `ref=abc`), a bare query
     * (`ref=abc`), or empty/null to clear. Anything unparseable stores ''.
     */
    private static function referralQueryFrom(?string $raw): string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }

        $query = str_contains($raw, '?')
            ? (string) (parse_url($raw, PHP_URL_QUERY) ?? '')
            : $raw;

        // Bare-query form must look like key=value pairs, not a URL or prose.
        if ($query === '' || str_contains($query, '://') || str_contains($query, ' ') || ! str_contains($query, '=')) {
            return '';
        }

        parse_str($query, $parsed);
        if ($parsed === []) {
            return '';
        }

        return mb_substr($query, 0, 500);
    }

    // DELETE /api/platforms/shop/brands/{id} — remove a brand.
    public function removeBrand(Request $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->withConnectionLock($user, function () use ($user, $id) {
            $connection = $this->connectionFor($user);
            if ($connection) {
                $brand = ShopBrand::where('connection_id', $connection->id)->where('brand_id', $id)->first();
                if ($brand) {
                    // Explicit child delete (not just relying on the DB's ON DELETE
                    // CASCADE) — mirrors MenuFetchJob's pattern elsewhere in this
                    // codebase, and keeps this deterministic on SQLite in tests,
                    // which doesn't enforce FK cascade.
                    ShopProduct::where('brand_id', $brand->id)->delete();
                    $brand->delete();
                }
            }
            $connection = $this->writeConnection($user, self::MARKER);
            $this->refresher->refresh($connection);

            return $this->success(['brands' => ShopBrandResource::collection($this->allBrands($user))->resolve()]);
        });
    }

    // POST /api/platforms/shop/brands/{id}/catalog — client-assisted catalog
    // re-warm for a client-mode brand: the browser fetched the store's public
    // Store API and posts the raw products; we normalise + host-pin them and
    // warm the same picker cache the live scrape would have.
    public function catalog(SubmitShopCatalogRequest $request, string $id): JsonResponse
    {
        $map = $this->brandMap($this->currentUser($request));
        if (! isset($map[$id])) {
            return $this->error('Brand not found.', 404);
        }

        $products = $this->woocommerce->productsFromClient($map[$id]['url'], $request->validated()['products']);
        if ($products === []) {
            return $this->error('No products from this store were found in that payload.', 422);
        }

        Cache::put($this->catalogKey($id), $products, self::applyJitter(self::CATALOG_TTL_MINUTES * 60));

        return $this->success(['products' => $products]);
    }

    // GET /api/platforms/shop/brands/{id}/products — live products for the picker.
    // Cache::remember short-circuits the live scrape when the cache was already
    // warmed by addBrand or a recent GET, so re-opening the picker within the
    // 10-min window is instant and doesn't re-scrape the upstream store.
    public function brandProducts(Request $request, string $id): JsonResponse
    {
        $map = $this->brandMap($this->currentUser($request));
        if (! isset($map[$id])) {
            return $this->error('Brand not found.', 404);
        }

        $seconds = (float) config('partna.http_fetch.connect_budget_seconds', 20);
        $products = Cache::remember(
            $this->catalogKey($id),
            self::applyJitter(self::CATALOG_TTL_MINUTES * 60),
            fn () => $this->budget->open($seconds, fn () => $this->providerProducts($map[$id])),
        );

        return $this->success(['products' => $products]);
    }

    // PUT /api/platforms/shop/brands/{id}/selection — snapshot the chosen
    // products (re-fetched live, order preserved). Callable any time.
    public function setProducts(SetShopProductsRequest $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);
        $validated = $request->validated();

        return $this->withConnectionLock($user, function () use ($user, $id, $validated) {
            $connection = $this->connectionFor($user);
            $brand = $connection
                ? ShopBrand::where('connection_id', $connection->id)->where('brand_id', $id)->with('products')->first()
                : null;
            if (! $brand) {
                return $this->error('Brand not found.', 404);
            }

            // Prefer the catalog the picker just warmed; only re-scrape if it
            // has gone cold (a save long after the picker was opened).
            $seconds = (float) config('partna.http_fetch.connect_budget_seconds', 20);
            $catalog = Cache::get($this->catalogKey($id))
                ?? $this->budget->open($seconds, fn () => $this->providerProducts($brand->toBrandArray()));
            $all = collect($catalog)->keyBy('productId');
            $selected = collect($validated['productIds'])
                ->map(fn (string $pid) => $all->get($pid))
                ->filter()
                ->values();

            // Rebuild the product rows wholesale — mirrors the old
            // whole-array-replace semantics of the JSONB `products` write.
            // Transactional (MenuFetchJob::persist() precedent): the old single
            // JSONB write was atomic by nature, so the delete+reinsert here must
            // be too — a mid-loop failure must not leave a partial product set.
            DB::connection('pgsql')->transaction(function () use ($brand, $selected) {
                ShopProduct::where('brand_id', $brand->id)->delete();
                foreach ($selected as $index => $productData) {
                    ShopProduct::create([
                        'brand_id' => $brand->id,
                        'product_id' => (string) ($productData['productId'] ?? ''),
                        'position' => $index,
                        'data' => $productData,
                    ]);
                }
            });

            // A hand-picked selection is a manual choice — leaving latest mode
            // on would silently overwrite it on the next scheduled sync.
            if (($brand->selection_mode ?? 'manual') === 'latest') {
                $brand->update(['selection_mode' => 'manual']);
            }

            $connection = $this->writeConnection($user, self::MARKER);
            $this->refresher->refresh($connection);
            // Invalidate the picker catalog so a subsequent GET re-scrapes the
            // store instead of serving the pre-selection snapshot for up to 10 min.
            Cache::forget($this->catalogKey($id));

            return $this->success((new ShopBrandResource($brand->fresh('products')->toBrandArray()))->resolve());
        });
    }

    // GET /api/platforms/shop/selection — COMPAT flat view of the primary brand
    // (first brand that has products) so partna-pages' existing Shop card keeps
    // rendering. Returns null when no brand has products.
    public function selection(Request $request): JsonResponse
    {
        $primary = ShopPayload::fromArray($this->brandMap($this->currentUser($request)))->primaryWithProducts();

        $selection = $primary ? [
            'url' => $primary['url'],
            'provider' => $primary['provider'] ?? 'shopify',
            'discountCode' => $primary['discountCode'] ?? '',
            'products' => $primary['products'],
        ] : null;

        return $this->success(['selection' => $selection]);
    }

    // DELETE /api/platforms/shop — clear all brands.
    public function forget(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->withConnectionLock($user, function () use ($user) {
            // Drop brand/product rows BEFORE the soft-delete so nothing is left
            // hanging off the tombstoned connection row (they'd otherwise orphan
            // until the 30-day hard-delete purge). Explicit child delete (not just
            // the DB's ON DELETE CASCADE) — see removeBrand() for why.
            $connection = $this->connectionFor($user);
            if ($connection) {
                $brandIds = ShopBrand::where('connection_id', $connection->id)->pluck('id');
                ShopProduct::whereIn('brand_id', $brandIds)->delete();
                ShopBrand::where('connection_id', $connection->id)->delete();
            }
            // forgetConnection() soft-deletes the connection row, which fires the
            // observer's deleted() → unconditional cache refresh — so unlike the
            // other mutations (which only touch child rows) no explicit refresh is
            // needed here.
            $this->forgetConnection($user);

            return $this->success(['brands' => []]);
        });
    }

    // POST /api/platforms/shop/products — add a single product by URL, not tied
    // to a connected store (mirrors standalone events). Scraped from its own
    // page (JSON-LD → OpenGraph) and kept in the reserved 'individual' bucket.
    public function addProduct(AddShopProductRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $seconds = (float) config('partna.http_fetch.connect_budget_seconds', 20);
        $read = $this->budget->open($seconds, fn () => $this->generic->readProductPage($request->validated()['url']));

        // Distinct code when the "product" URL is really a storefront homepage
        // (WS-B1.1) — the dashboard suggests connecting it as a brand instead,
        // prefilled with `storeUrl`.
        if ($read['outcome'] === GenericShopScraper::OUTCOME_STORE_PAGE) {
            return $this->error(
                "That looks like a store's homepage, not a product page. Connect it as a brand to import its products, or paste a specific product's page URL.",
                422,
                extra: ['code' => 'store_homepage', 'storeUrl' => $read['storeUrl']],
            );
        }

        $product = $read['product'];
        if ($product === null) {
            return $this->error(
                "Couldn't read a product from that link. Open the product's own page and paste its URL — it needs a title and price in standard product markup.",
                422,
                extra: ['code' => 'no_product_found'],
            );
        }

        return $this->withConnectionLock($user, function () use ($user, $product) {
            $connection = $this->writeConnection($user, self::MARKER);

            $maxPosition = ShopBrand::where('connection_id', $connection->id)->max('position');
            $individual = ShopBrand::firstOrCreate(
                ['connection_id' => $connection->id, 'brand_id' => self::INDIVIDUAL_BRAND_ID],
                [
                    'provider' => ShopProviderDetector::PROVIDER_GENERIC,
                    'url' => '',
                    'source_url' => '',
                    'currency' => $product['currency'] ?? null,
                    'discount_code' => '',
                    'is_individual' => true,
                    'position' => ($maxPosition === null ? -1 : $maxPosition) + 1,
                ],
            );

            $productId = $product['productId'] ?? null;

            // Newest first, de-duped by productId, capped.
            $ordered = ShopProduct::where('brand_id', $individual->id)
                ->orderBy('position')
                ->get()
                ->reject(fn (ShopProduct $p) => $p->product_id === $productId)
                ->map(fn (ShopProduct $p) => $p->data)
                ->prepend($product)
                ->take(self::MAX_INDIVIDUAL_PRODUCTS)
                ->values();

            // Transactional rebuild — see setProducts() for why (mid-loop
            // failure must not leave a partial product set).
            DB::connection('pgsql')->transaction(function () use ($individual, $ordered) {
                ShopProduct::where('brand_id', $individual->id)->delete();
                foreach ($ordered as $index => $productData) {
                    ShopProduct::create([
                        'brand_id' => $individual->id,
                        'product_id' => (string) ($productData['productId'] ?? ''),
                        'position' => $index,
                        'data' => $productData,
                    ]);
                }
            });

            $this->refresher->refresh($connection);

            return $this->success((new ShopBrandResource($individual->fresh('products')->toBrandArray()))->resolve());
        });
    }

    // DELETE /api/platforms/shop/products/{productId} — remove one individual
    // product; drops the reserved bucket entirely once it's empty.
    public function removeProduct(Request $request, string $productId): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->withConnectionLock($user, function () use ($user, $productId) {
            $connection = $this->connectionFor($user);
            $individual = $connection
                ? ShopBrand::where('connection_id', $connection->id)->where('brand_id', self::INDIVIDUAL_BRAND_ID)->first()
                : null;
            if (! $individual) {
                return $this->error('Product not found.', 404);
            }

            ShopProduct::where('brand_id', $individual->id)->where('product_id', $productId)->delete();

            if (ShopProduct::where('brand_id', $individual->id)->doesntExist()) {
                $individual->delete();
            }
            $connection = $this->writeConnection($user, self::MARKER);
            $this->refresher->refresh($connection);

            return $this->success(['brands' => ShopBrandResource::collection($this->allBrands($user))->resolve()]);
        });
    }

    // GET /api/platforms/shop/settings — the user's GLOBAL shop link controls
    // (2026-07-08). One choice each, applied to every connected store:
    // linkMode ('checkout'|'product') stamps every brand's public linkMode;
    // autoLatest keeps every non-individual store's selection synced to its
    // newest products. Stored on the site row (site.sites), read here.
    public function settings(Request $request): JsonResponse
    {
        $site = $this->currentSite($this->currentUser($request));

        return $this->success($this->settingsPayload($site));
    }

    // PATCH /api/platforms/shop/settings — update the global link controls.
    // Every field optional (apply only what's present). A linkMode change flips
    // the public payload for every brand, so purge the sitepage edge cache for
    // the user's shop connection (same reason every shop mutation refreshes
    // explicitly — the connection's marker payload never goes dirty on its own).
    public function updateSettings(UpdateShopSettingsRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $site = $this->currentSite($user);
        $validated = $request->validated();

        $updates = [];
        if (array_key_exists('linkMode', $validated)) {
            $updates['shop_link_mode'] = $validated['linkMode'];
        }
        if (array_key_exists('autoLatest', $validated)) {
            $updates['shop_auto_latest'] = (bool) $validated['autoLatest'];
        }
        if ($updates !== []) {
            $site->update($updates);
        }

        // Propagate the new public linkMode to the CDN — the shop connection's
        // payload is a static marker (FOUND-25), so its own save never busts
        // the cache; refresh explicitly when the user has a shop connected.
        $connection = $this->connectionFor($user);
        if ($connection) {
            $this->refresher->refresh($connection);
        }

        return $this->success($this->settingsPayload($site->fresh()));
    }

    /**
     * The global shop-settings wire shape. Coalesces to the code-side defaults
     * (direct-to-checkout ON, auto-latest ON) so a site row that predates the
     * columns still reports sane values.
     *
     * @return array{linkMode:string, autoLatest:bool}
     */
    private function settingsPayload(Site $site): array
    {
        return [
            'linkMode' => $site->shop_link_mode ?? Site::DEFAULT_SHOP_LINK_MODE,
            'autoLatest' => (bool) ($site->shop_auto_latest ?? true),
        ];
    }

    // ── internals ────────────────────────────────────────────────

    /**
     * Resolve the brand profile (and, for generic pages, the products that
     * came with it) for a freshly-detected store.
     *
     * @param  array{provider:string, origin:string, sourceUrl:string, page:array|null,
     *               store:array|null, clientBrand?:array, clientProducts?:array,
     *               fetchMode?:string}  $detected
     * @return array{0: array{id:string, name:?string, currency:?string, favicon:?string, logo:?string}, 1: ?array}
     */
    private function brandProfileFor(array $detected): array
    {
        // Client-assisted detection already carries the brand + products the
        // browser fetched — no server round-trips (they'd be blocked anyway).
        if (isset($detected['clientBrand'])) {
            return [$detected['clientBrand'], $detected['clientProducts']];
        }

        return match ($detected['provider']) {
            ShopProviderDetector::PROVIDER_WOOCOMMERCE => [$this->woocommerce->fetchBrand($detected['origin']), null],
            ShopProviderDetector::PROVIDER_SQUARESPACE => [$this->squarespace->fetchBrand($detected['sourceUrl']), null],
            ShopProviderDetector::PROVIDER_BIGCARTEL => [$detected['store'], null],
            ShopProviderDetector::PROVIDER_GENERIC => [$detected['page']['brand'], $detected['page']['products']],
            default => [$this->shopify->fetchBrand($detected['origin']), null],
        };
    }

    /**
     * Server-probe-failed fallback: build a detection result from the
     * browser-fetched Store API payload. Null when the payload contains no
     * products actually belonging to the pasted store (host-pinned).
     *
     * @return array{provider:string, origin:string, sourceUrl:string, page:null, store:null, clientBrand:array, clientProducts:array, fetchMode:string}|null
     */
    private function detectFromClientPayload(string $url, array $storeApi): ?array
    {
        $origin = $this->shopify->originOf($url);
        if (! $origin) {
            return null;
        }

        $rawProducts = $storeApi['products'] ?? null;
        if (! is_array($rawProducts)) {
            return null;
        }

        $products = $this->woocommerce->productsFromClient($origin, $rawProducts);
        if ($products === []) {
            return null;
        }

        $root = is_array($storeApi['root'] ?? null) ? $storeApi['root'] : null;

        return [
            'provider' => ShopProviderDetector::PROVIDER_WOOCOMMERCE,
            'origin' => $origin,
            'sourceUrl' => $origin,
            'page' => null,
            'store' => null,
            'clientBrand' => $this->woocommerce->brandFromClient($origin, $root),
            'clientProducts' => $products,
            'fetchMode' => 'client',
        ];
    }

    /**
     * Live product catalog for a stored brand — delegated to ShopCatalog
     * (shared with the scheduled latest-mode refresh strategy).
     */
    private function providerProducts(array $brand): array
    {
        return $this->catalog->providerProducts($brand);
    }

    /**
     * The stored brand map (id => brand), or empty. FOUND-25: reads the
     * relational site.shop_brands/site.shop_products child tables (formerly
     * a single JSONB map on the connection's payload).
     */
    private function brandMap(User $user): array
    {
        $connection = $this->connectionFor($user);
        if (! $connection) {
            return [];
        }

        return $connection->shopBrands()->with('products')->get()
            ->keyBy('brand_id')
            ->map(fn (ShopBrand $b) => $b->toBrandArray())
            ->all();
    }

    /** Brands as a plain ordered list. */
    private function allBrands(User $user): array
    {
        return array_values($this->brandMap($user));
    }

    /** Per-brand picker-catalog cache key. */
    private function catalogKey(string $id): string
    {
        return CacheKeyGenerator::shopifyBrandCatalog($id);
    }
}
