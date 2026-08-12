<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\DefersBespokeConnect;
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
use App\Jobs\Platforms\ProcessShopBrandLogoJob;
use App\Jobs\Platforms\ShopBrandConnectJob;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\Site\ShopProduct;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Analytics\ContentPopularityReader;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\Concerns\JitteredTtl;
use App\Services\Http\FetchBudget;
use App\Services\Platforms\GenericShopScraper;
use App\Services\Platforms\IntegrationConnectionCacheRefresher;
use App\Services\Platforms\Payloads\ShopPayload;
use App\Services\Platforms\ShopBrandIdentity;
use App\Services\Platforms\ShopBrandProfiler;
use App\Services\Platforms\ShopCatalog;
use App\Services\Platforms\ShopifyScraper;
use App\Services\Platforms\ShopProviderDetector;
use App\Services\Platforms\StrandedPendingWindow;
use App\Services\Platforms\Strategies\Fetch\FetchUnavailableException;
use App\Services\Platforms\WooCommerceScraper;
use App\Services\Shop\ShopContentReader;
use App\Site\Pools\AutoSyncSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
    use DefersBespokeConnect;
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

    // W9 §3a/§7 R1: the only three providers whose brandProfileFor() actually
    // performs HTTP beyond detection. bigcartel/generic/client-assisted already
    // hold the whole profile in memory at 202 time (zero extra fetch), so
    // deferring them would only serialise a no-op into a job — they stay
    // synchronous regardless of the flag.
    private const DEFERRABLE_PROVIDERS = [
        ShopProviderDetector::PROVIDER_SHOPIFY,
        ShopProviderDetector::PROVIDER_WOOCOMMERCE,
        ShopProviderDetector::PROVIDER_SQUARESPACE,
    ];

    public function __construct(
        private readonly ShopProviderDetector $detector,
        private readonly ShopifyScraper $shopify,
        private readonly WooCommerceScraper $woocommerce,
        private readonly GenericShopScraper $generic,
        private readonly IntegrationConnectionCacheRefresher $refresher,
        private readonly ShopCatalog $catalog,
        private readonly FetchBudget $budget,
        private readonly ShopBrandProfiler $profiler,
        private readonly ShopBrandIdentity $identity,
        private readonly ContentPopularityReader $popularity,
        private readonly ShopContentReader $contentReader,
    ) {}

    protected function platform(): string
    {
        return 'shop';
    }

    // GET /api/platforms/shop/brands — all connected brands. Task 7: served
    // from content.* (ShopContentReader) merged over site.shop_brands — see
    // hybridBrandMap()'s docblock for why a bare content.*-only read is
    // unsafe (a brand not yet synced into content.* would silently vanish
    // from this list until Task 8 repoints the writes).
    public function brands(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $map = $this->hybridBrandMap($user);

        return $this->success([
            'brands' => ShopBrandResource::collection(array_values($map))->resolve(),
        ]);
    }

    // POST /api/platforms/shop/brands — add (or refresh) a brand. Detects the
    // provider, resolves the brand profile, dedups by canonical id, caps at
    // MAX_BRANDS, stores with the brand's existing products preserved.
    //
    // W9 §4 Unit 4: detection stays fully synchronous (it's where almost all of
    // Shop's latency lives, and it's what makes brand_id/provider truthful at
    // 202 time). Only the brand-profile fetch (name/currency/favicon/logo) is
    // deferred, and only for shopify/woocommerce/squarespace (self::DEFERRABLE_
    // PROVIDERS) — bigcartel/generic/client-assisted already hold the whole
    // profile in memory (zero extra HTTP), so they stay synchronous 200s
    // regardless of the flag (§7 R1/R2 — this endpoint's status code is
    // provider-dependent by design).
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

            // $id is deliberately derived PER-BRANCH, not once up front — the
            // two derivations are not interchangeable for Shopify. $deferred
            // itself only reads provider + clientBrand, never $id, so the
            // branch decision doesn't need to wait for either derivation.
            $deferred = $this->shouldDeferConnect('shop')
                && in_array($detected['provider'], self::DEFERRABLE_PROVIDERS, true)
                // Client-assisted detection always reports provider=woocommerce
                // (WooCommerceScraper::brandFromClient()) but already holds the
                // whole profile from the browser fetch — never defer it.
                && ! isset($detected['clientBrand']);

            if ($deferred) {
                // No profile fetch on this path (that's the whole point of
                // deferring), so ShopBrandIdentity is the ONLY way to get a
                // truthful key — exactly what it exists for.
                $id = $this->identity->for($detected);

                return ['detected' => $detected, 'id' => $id, 'deferred' => true, 'brand' => null, 'detectedProducts' => null];
            }

            [$brand, $detectedProducts] = $this->profiler->forDetected($detected);
            // Byte-identical to pre-W9: the id comes from the SAME fetch that
            // produced name/currency/favicon/logo (for Shopify, fetchBrand()'s
            // own GET /meta.json), so the two can never disagree. Using
            // ShopBrandIdentity here instead would derive the id from a
            // SEPARATE meta.json read (the one probeMeta() captured during
            // detection) — atomic-by-construction today, but not once two
            // independent HTTP round-trips are in play.
            $id = $brand['id'];

            return ['detected' => $detected, 'id' => $id, 'deferred' => false, 'brand' => $brand, 'detectedProducts' => $detectedProducts];
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
        $id = $outcome['id'];
        $deferred = $outcome['deferred'];
        $brand = $outcome['brand'];
        $detectedProducts = $outcome['detectedProducts'];

        // Sentinel pattern (GenericPlatformController::connectDeferred()'s own
        // idiom): the lock closure always returns a JsonResponse, but the REAL
        // signal is $brandRow via reference. A null $brandRow after the lock
        // releases means the closure already produced a terminal response (the
        // cap's 422, or withConnectionLock's own 423 lock-timeout) — return it
        // unchanged, dispatch nothing. This is what lets the job dispatch
        // happen AFTER the lock releases: under QUEUE_CONNECTION=sync,
        // dispatching from inside the closure would self-deadlock the job
        // against this same per-user platform lock.
        $brandRow = null;
        $lockResponse = $this->withConnectionLock($user, function () use ($user, $detected, $brand, $detectedProducts, $id, $deferred, $validated, &$brandRow): JsonResponse {
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

            $values = [
                'provider' => $detected['provider'],
                'url' => $detected['origin'],
                'source_url' => $detected['sourceUrl'],
                'discount_code' => $discount,
                // Client-connected brands can't be re-scraped server-side; the
                // flag routes product reads through the catalog cache + the
                // client re-warm endpoint instead of abort(502)ing.
                'fetch_mode' => $detected['fetchMode'] ?? null,
                'is_individual' => false,
                'position' => $position,
            ];

            if ($deferred) {
                // name/favicon/logo are OMITTED entirely (not set to null) —
                // updateOrCreate only writes present keys, so a re-add of an
                // already-settled brand keeps its display profile for the
                // whole pending window instead of being blanked (§3c).
                //
                // currency is the one exception: for Shopify it is truthfully
                // known at 202 time with zero extra HTTP (the carried
                // meta.json), and ShopCatalog::providerProducts() passes it as
                // the per-product currency fallback — omitting it would make
                // a picker GET during the pending window show null currency
                // wherever a Shopify variant lacks presentment_prices. Woo's
                // currency is always null anyway; Squarespace's genuinely
                // isn't known until the deferred fetch. syncCurrencyFor()
                // returns null for both, so the key is simply omitted there —
                // the same non-destructive-omit discipline still holds.
                $currency = $this->profiler->syncCurrencyFor($detected);
                if ($currency !== null) {
                    $values['currency'] = $currency;
                }
                $values['connect_status'] = 'pending';
                $values['connect_error'] = null;
            } else {
                $values['name'] = $brand['name'];
                $values['currency'] = $brand['currency'] ?? null;
                $values['favicon'] = $brand['favicon'];
                $values['logo'] = $brand['logo'];
                // Settle/clear any prior pending or failed state.
                $values['connect_status'] = null;
                $values['connect_error'] = null;
            }

            $brandRow = ShopBrand::updateOrCreate(
                ['connection_id' => $connection->id, 'brand_id' => $id],
                $values,
            );

            if ($deferred) {
                // P1 review fix: force-refresh the staleness clock on every
                // pending write. A retry of an ALREADY-pending brand writes
                // byte-identical values to what's already stored (provider/
                // url/source_url/discount/fetch_mode/position/currency/
                // connect_status/connect_error all unchanged), so nothing is
                // dirty — updateOrCreate()'s fill($values)->save() would then
                // skip the UPDATE entirely and leave updated_at at its
                // original timestamp. The poll's stale-pending backstop
                // (connectStatus() below) would then report a freshly-retried
                // connect as 'failed'. touch() sets
                // updated_at directly (it isn't in $fillable, so it can't
                // ride along in $values above) and always issues the UPDATE,
                // dirty or not. The synchronous branch below settles
                // connect_status to null, so the backstop (gated on
                // connect_status === 'pending') never reads this row's
                // updated_at and does not need the same treatment.
                $brandRow->touch();
            }

            // The generic detector already read the page's products — warm the
            // picker catalog so the immediately-following GET is instant. Never
            // reached on the deferred branch ($detectedProducts is only ever
            // non-null for generic/client, both always synchronous — §3e).
            if ($detectedProducts !== null) {
                Cache::put($this->catalogKey($id), $detectedProducts, self::applyJitter(self::CATALOG_TTL_MINUTES * 60));
            }

            // The connection's payload is a static marker (FOUND-25) — the
            // observer's payload-dirty gate won't fire for a brand add after the
            // first connect, so purge + preset-resolve explicitly, once.
            $this->refresher->refresh($connection);

            // P3 review fix: build the response body INSIDE the lock (restored
            // pre-W9 behaviour). Reading $brandRow->fresh() AFTER the lock
            // releases left a window where a concurrent removeBrand()/forget()
            // from the same user could delete this row between lock release
            // and the read, turning fresh() into null and toBrandArray() into
            // a fatal error on a null. Only the job DISPATCH below needs to
            // stay outside the lock (self-deadlocks under the sync driver —
            // see the sentinel comment above); the response payload itself has
            // no such constraint and is cheap to build while still holding it.
            $resolved = (new ShopBrandResource($brandRow->fresh('products')->toBrandArray()))->resolve();

            if (! $deferred) {
                // Best-effort mark processing (background removal + SVG) —
                // the raw favicon/logo stays either way.
                ProcessShopBrandLogoJob::dispatch((string) $brandRow->id);

                return $this->success($resolved);
            }

            // Envelope wins over a colliding resource key (matches
            // DefersBespokeConnect::deferredConnectResponse()'s own spread order) —
            // not load-bearing here (the resource carries no 'status'/'statusUrl'
            // keys) but kept consistent with the rest of the deferred-connect family.
            return $this->success([
                ...$resolved,
                'status' => 'pending',
                'statusUrl' => url("/api/platforms/shop/brands/{$id}/connect/status"),
            ], 202);
        });

        if ($brandRow === null) {
            return $lockResponse;
        }

        if ($deferred) {
            // AFTER the lock has released — see the sentinel comment above.
            ShopBrandConnectJob::dispatch($brandRow->id)->afterCommit();
        }

        return $lockResponse;
    }

    // GET /api/platforms/shop/brands/{id}/connect/status — poll a deferred
    // connect (W9 §4 Unit 4). Shop polls a per-BRAND sub-resource, not the
    // connection row the six bespoke platforms poll, so this does not reuse
    // DefersBespokeConnect::bespokeConnectStatus() (that method hardcodes
    // last_refresh_status off the connection; see the trait's own docblock) —
    // only shouldDeferConnect() is shared. 404, never 403: connectionFor()
    // already scopes to the caller's own connection, so another user's brand
    // is never visible to look up in the first place (mirrors updateBrand()/
    // brandProducts()'s existing contract).
    public function connectStatus(Request $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);
        $connection = $this->connectionFor($user);
        $brand = $connection ? ShopBrand::where('connection_id', $connection->id)->where('brand_id', $id)->first() : null;

        if (! $brand) {
            return $this->error('Brand not found.', 404);
        }

        if ($brand->connect_status === 'pending') {
            // Stale-pending backstop, ported from GenericPlatformController::
            // connectStatus() (NOT Instagram's connectStatus(), which has no
            // staleness check) — a worker that dies leaves the row 'pending'
            // forever with nothing to flip it. Window and its justification:
            // StrandedPendingWindow. SYNTHETIC — never writes the row, so a
            // merely-slow (not dead) worker can still land its real settle
            // afterwards and the next poll reports 'ready'.
            if ($brand->updated_at !== null && $brand->updated_at->lt(now()->subMinutes(StrandedPendingWindow::MINUTES))) {
                return $this->success(['status' => 'failed', 'error' => FetchUnavailableException::STALE_CONNECT_ERROR]);
            }

            return $this->success(['status' => 'pending']);
        }

        if ($brand->connect_status === 'failed') {
            // Unlike the six bespoke platforms, a failed Shop brand IS still
            // usable — brand_id/provider/url/source_url are all truthful, so
            // the picker and public render both work (§3g) — carry it.
            return $this->success([
                'status' => 'failed',
                'error' => $brand->connect_error ?: self::UNKNOWN_CONNECT_ERROR,
                'brand' => (new ShopBrandResource($this->brandPayload($user, $brand)))->resolve(),
            ]);
        }

        return $this->success([
            'status' => 'ready',
            'id' => $brand->brand_id,
            'brand' => (new ShopBrandResource($this->brandPayload($user, $brand)))->resolve(),
        ]);
    }

    /**
     * Task 7: the embedded brand payload for connectStatus()'s 'failed'/
     * 'ready' branches, preferring content.* (ShopContentReader) but falling
     * back to the live site.shop_brands row when content.* has no row yet.
     *
     * Deliberately NOT a bare ShopContentReader::brandMap() call like brands()/
     * brandProducts()/selection() below: connectStatus() is a real-time poll
     * of an in-flight/just-settled async job (ShopBrandConnectJob), and that
     * job settles connect_status/connect_error/name/currency/favicon/logo on
     * site.shop_brands directly — it never calls ShopContentWriter::
     * upsertStore(). So at the exact moment this endpoint has anything
     * interesting to report ('failed' or freshly-'ready'), content.* almost
     * always has NO row for this brand yet (see ShopContentReader's gap 1) —
     * a bare content.*-only read would 404-shaped-empty the response right
     * when the dashboard's "connecting your store…" UI is watching it
     * resolve. The fallback closes that window; once the brand has been
     * through its first sync, content.* takes over transparently.
     */
    private function brandPayload(User $user, ShopBrand $brand): array
    {
        return $this->contentReader->brandMap($user)[$brand->brand_id]
            ?? $brand->fresh('products')->toBrandArray();
    }

    // PATCH /api/platforms/shop/brands/{id} — update PER-BRAND settings: the
    // discount code and the referral URL (stored as its parsed query suffix).
    // Every field is optional; only what's present is applied.
    //
    // linkMode is still ACCEPTED here for backward compatibility but is DORMANT
    // as of 2026-07-08: it became one GLOBAL site setting (site.sites.shop_link_mode
    // via /platforms/shop/settings), and the public payload always stamps linkMode
    // from the global — so a per-brand write to it is inert on the wire. The
    // dashboard no longer sends it.
    //
    // selectionMode is NOT dormant — #SEM-1: setting it to 'latest' clears
    // products_curated_at (the opt-BACK-IN path after setProducts() curated the
    // brand — see that method) and triggers a one-off immediate sync below.
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
                // #SEM-1 opt-back-in: 'latest' un-curates the brand so
                // ShopFetch's whereNull('products_curated_at') picks it back
                // up on the next scheduled sync (the immediate sync below
                // also runs, so the two never race against each other).
                if ($validated['selectionMode'] === 'latest') {
                    $updates['products_curated_at'] = null;
                }
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
            // No shop connected → nothing to remove. Previously this fell through to
            // writeConnection() below, which RECREATED the marker row at status 'ok'
            // — a delete that resurrected the connection (and, since the trait's
            // emit point fires on wasRecentlyCreated, rang "Shop connected" on it).
            // removeProduct (below) already 404s on the same condition.
            $connection = $this->connectionFor($user);
            if (! $connection) {
                return $this->error('Brand not found.', 404);
            }

            $brand = ShopBrand::where('connection_id', $connection->id)->where('brand_id', $id)->first();
            if ($brand) {
                // Explicit child delete (not just relying on the DB's ON DELETE
                // CASCADE) — mirrors MenuFetchJob's pattern elsewhere in this
                // codebase, and keeps this deterministic on SQLite in tests,
                // which doesn't enforce FK cascade.
                ShopProduct::where('brand_id', $brand->id)->delete();
                $brand->delete();
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
    // Task 7: the existence check + the {url, provider, sourceUrl, fetchMode}
    // dispatch shape providerProducts() reads now come from hybridBrandMap()
    // (content.* merged over site.shop_brands) — see that method's docblock
    // for why the merge, not a bare ShopContentReader::brandMap() call.
    public function brandProducts(Request $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);
        $map = $this->hybridBrandMap($user);
        if (! isset($map[$id])) {
            return $this->error('Brand not found.', 404);
        }

        $seconds = (float) config('partna.http_fetch.connect_budget_seconds', 20);
        try {
            $products = Cache::remember(
                $this->catalogKey($id),
                self::applyJitter(self::CATALOG_TTL_MINUTES * 60),
                fn () => $this->budget->open($seconds, fn () => $this->providerProducts($map[$id])),
            );
        } catch (HttpException $e) {
            // Scrapers abort(502) when the store's catalog endpoint is blocked
            // (WAF 429s, disabled products.json). Raw 502s render as an opaque
            // dashboard failure — surface the same coded-422 contract the
            // add-brand/add-product flows use (WS-B1) so it can render inline.
            // Anything non-502 (e.g. a 429 from our own budget guards) keeps
            // its meaning.
            if ($e->getStatusCode() !== 502) {
                throw $e;
            }

            return $this->error(
                'This store is temporarily blocking product browsing — try again later.',
                422,
                extra: ['code' => 'store_catalog_blocked'],
            );
        }

        return $this->success(['products' => $products]);
    }

    // PUT /api/platforms/shop/brands/{id}/selection — snapshot the chosen
    // products (re-fetched live, order preserved). Callable any time.
    //
    // W9 §3f/unit 5: the vendor fetch (up to the 20s connect budget) runs
    // OUTSIDE withConnectionLock() — its lock has only a 10s TTL, so running
    // the fetch inside it let the TTL expire while the DB transaction below
    // was still open, letting a second writer acquire the "free" lock and
    // interleave with this request's uncommitted write. Only the
    // read→mutate→write cycle is serialised now.
    public function setProducts(SetShopProductsRequest $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);
        $validated = $request->validated();

        // Pre-lock read — deliberately duplicated by the authoritative re-read
        // inside the lock below, not a simplification opportunity: a concurrent
        // removeBrand/forget can delete this brand while the fetch (below) is
        // still running, and collapsing the two reads into one would reopen
        // exactly the delete-between-read-and-write race this split closes.
        // This read exists only to produce the 404 and to give providerProducts()
        // a brand shape to dispatch on.
        $connection = $this->connectionFor($user);
        $brand = $connection
            ? ShopBrand::where('connection_id', $connection->id)->where('brand_id', $id)->with('products')->first()
            : null;
        if (! $brand) {
            return $this->error('Brand not found.', 404);
        }

        // Prefer the catalog the picker just warmed; only re-scrape if it has
        // gone cold (a save long after the picker was opened). Outside the
        // lock — see the docblock above.
        $seconds = (float) config('partna.http_fetch.connect_budget_seconds', 20);
        $catalog = Cache::get($this->catalogKey($id))
            ?? $this->budget->open($seconds, fn () => $this->providerProducts($brand->toBrandArray()));

        return $this->withConnectionLock($user, function () use ($user, $id, $validated, $catalog) {
            // Authoritative re-read — the pre-lock $brand above may be stale.
            $connection = $this->connectionFor($user);
            $brand = $connection
                ? ShopBrand::where('connection_id', $connection->id)->where('brand_id', $id)->with('products')->first()
                : null;
            if (! $brand) {
                return $this->error('Brand not found.', 404);
            }

            // A fresher catalog may have landed in the cache while the pre-lock
            // fetch was in flight (a picker GET, or another setProducts() call
            // for the same brand — the two now scrape unserialized, unlike the
            // old fully-locked version). Prefer it; the pre-lock snapshot is
            // only a FALLBACK for a genuinely cold cache, never the authority.
            // Do NOT re-scrape here — that would put the vendor fetch back
            // inside the lock and undo the whole point of this unit.
            $catalog = Cache::get($this->catalogKey($id)) ?? $catalog;

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
            //
            // Bulk insert (MenuFetchJob::persist()'s own idiom), not a per-row
            // create() loop: up to 250 productIds (SetShopProductsRequest's
            // max) means up to 250 sequential round-trips over Supavisor inside
            // this lock's 10s TTL — the exact failure mode unit 5 exists to
            // close. A single insert() bypasses ShopProduct's HasUuids hook and
            // `data` array cast, so both are reproduced by hand below.
            DB::connection('pgsql')->transaction(function () use ($brand, $selected) {
                ShopProduct::where('brand_id', $brand->id)->delete();
                if ($selected->isNotEmpty()) {
                    $now = now();
                    $rows = $selected->map(fn (array $productData, int $index) => [
                        'id' => (string) Str::uuid7(),
                        'brand_id' => $brand->id,
                        'product_id' => (string) ($productData['productId'] ?? ''),
                        'position' => $index,
                        'data' => json_encode($productData),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all();
                    ShopProduct::query()->insert($rows);
                }
            });

            // #SEM-1: products_curated_at is what ShopFetch actually reads to
            // skip this brand on the next scheduled sync (see that class's
            // docblock) — selection_mode alone can't carry this fact, since
            // its default IS 'manual' and addBrand() never sets it. Still
            // write selection_mode too: it stays a truthful dashboard label,
            // just no longer pretends to be the guard.
            $updates = ['products_curated_at' => now()];
            if (($brand->selection_mode ?? 'manual') === 'latest') {
                $updates['selection_mode'] = 'manual';
            }
            $brand->update($updates);

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
    // rendering. Returns null when no brand has products. Task 7: served from
    // hybridBrandMap() (content.* merged over site.shop_brands) — this endpoint
    // also feeds a public path (context note in the Task 7 brief), so a brand
    // curated moments ago via setProducts() (no content.* row yet — Task 8's
    // territory) must not go dark here.
    public function selection(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $primary = ShopPayload::fromArray($this->hybridBrandMap($user))->primaryWithProducts();

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
    // Task 7: in scope per the brief, but has no ShopBrand/content.*
    // dependency to repoint — settingsPayload() only ever reads site.sites
    // and the AutoSyncSetting toggle. Left as-is.
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
        if ($updates !== []) {
            $site->update($updates);
        }
        if (array_key_exists('autoLatest', $validated)) {
            // 2026-08-05: auto-latest lives on the store connections' own
            // display_settings now (one toggle grammar); still site-wide in
            // effect because the setter writes every store connection.
            AutoSyncSetting::set((string) $user->id, 'shop', (bool) $validated['autoLatest']);
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
            'autoLatest' => AutoSyncSetting::isOn((string) $site->user_id, 'shop'),
        ];
    }

    // ── internals ────────────────────────────────────────────────

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
            ->map(fn (ShopBrand $b) => $b->toBrandArray($this->productRanksFor($user)))
            ->all();
    }

    /**
     * shop_product ranks (keyed by product HANDLE), the same annotation the
     * public wire carries — the dashboard's Smart order switch sorts on
     * popularityRank, and until 2026-08-04 the dashboard path omitted the
     * key entirely, so "engagement order" silently meant "stored order".
     * Fail-open: a read fault degrades to null ranks. Shared by the legacy
     * (site.shop_brands) brandMap() above and Task 7's ShopContentReader
     * call sites below — both must pass an array (never null) here so
     * popularityRank stays PRESENT (not omitted) on every dashboard read,
     * matching ShopBrand::toBrandArray()'s own contract.
     */
    private function productRanksFor(User $user): array
    {
        return $this->popularity->forSite($user->site?->id)['shop_product'] ?? [];
    }

    /**
     * ============================================================
     * TEMPORARY — Task 7 fix round 1, Finding 6. DELETE THIS METHOD, and
     * call ShopContentReader::brandMap() directly from brands()/
     * brandProducts()/selection(), the moment Task 8 ships.
     * ============================================================
     *
     * This exists ONLY because three writers still bypass content.* today:
     *   1. ShopController::addBrand()
     *   2. ShopBrandConnectJob (the deferred-connect settle job)
     *   3. ShopController::setProducts()
     * None of the three call ShopContentWriter::upsertStore() — Task 8 is
     * what repoints them. Until all three do, a brand has no content.* row
     * between "just connected"/"just curated" and "first synced", and a
     * bare ShopContentReader::brandMap() call 404s/empties it out — proven,
     * not just predicted: three PRE-EXISTING tests that seed a brand the
     * same way addBrand()/setProducts() do — ShopPayloadFeatureTest's
     * "shop selection returns…"/"…seeded popularityRank…", ShopUrlValidation
     * Test's "connects a WooCommerce store end-to-end…" — broke under a bare
     * content.*-only read in exactly this way.
     *
     * The brand map brands()/brandProducts()/selection() actually read —
     * content.* (ShopContentReader) MERGED over the legacy site.shop_brands
     * map, not content.* alone. Legacy is authoritative for EXISTENCE and
     * ORDER (so a request never loses a brand), content.* wins PER BRAND
     * once it has a row (so the reconstruction — and its documented field
     * losses/backfill lag, see ShopContentReader — takes over transparently
     * as brands sync). Mirrors ShopContentWriter::isCurated()'s own
     * transitional-read precedent elsewhere in this slice.
     */
    private function hybridBrandMap(User $user): array
    {
        $legacy = $this->brandMap($user);
        $content = $this->contentReader->brandMap($user, $this->productRanksFor($user));

        $merged = [];
        foreach ($legacy as $id => $brand) {
            $merged[$id] = $content[$id] ?? $brand;
        }

        return $merged;
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
