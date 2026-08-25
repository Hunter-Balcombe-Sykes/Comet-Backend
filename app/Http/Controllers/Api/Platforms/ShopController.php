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
use App\Jobs\Platforms\ShopInitialFillJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Analytics\ContentPopularityReader;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\Concerns\JitteredTtl;
use App\Services\Http\FetchBudget;
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
use App\Services\Shop\ProductPageAdder;
use App\Services\Shop\ShopConnections;
use App\Services\Shop\ShopContentReader;
use App\Services\Shop\ShopContentWriter;
use App\Services\Shop\StoreRecord;
use App\Site\Documents\BuildState;
use App\Site\Pools\AutoSyncSetting;
use Illuminate\Database\QueryException;
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
// FOUND-25 + slice 5a: brands + their chosen products live in content.* —
// a content.collections row (kind='storefront') with a content.storefronts
// sidecar per brand, and one content.items row per product, written through
// ShopContentWriter and read back through ShopContentReader. NOT in the
// connection's JSONB payload, and no longer in site.shop_brands /
// site.shop_products either — the shop re-home moved every read and write off
// both and DROPPED them (20260819000200 / 20260819000210). The connection row
// survives as the lifecycle/authorization anchor; the store itself is
// content.* and nothing else.
//
// Convergence Phase 6 (owner ruling 4): there is no longer a single 'shop'
// marker row. Each connected store carries its OWN connection on its real
// brand surface — shopify.store / woocommerce.store / squarespace.store /
// bigcartel.store, or generic.store for a storefront whose platform has no
// name — keyed by the brand id, and `partna.storefront` is retired. The
// individually-added products bucket, which is not a store, anchors on
// `partna.manual_product` (hidden, dormant, explicitly NOT retired).
//
// That is scope §1.6's whole complaint answered: five Shopify stores and a
// WooCommerce one used to sit behind one pseudo-platform row, so ingest could
// not tell them apart and no per-brand feature could reach them.
//
// Consequences that shape the code below:
//   - No read may scope on one connection id. Every brand lookup goes through
//     ShopConnections::brands()/brand(), which span the user's whole shop
//     family — including a pre-migration marker row, so reads stay correct
//     between this deploy and the retirement command.
//   - The payload is still a static MARKER, so IntegrationConnectionObserver's
//     payload-dirty gate still only fires on first connect; every mutating
//     method below still invokes IntegrationConnectionCacheRefresher once so
//     brand/product edits purge the sitepage edge cache and re-resolve presets.
//   - Scheduled product refresh had to learn about it: those surfaces are
//     catalog-only, so they carry no PlatformRegistry descriptor. See
//     PlatformRegistry::forConnection().
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

    /** The store cap, from `partna.shop_brands_max` — the ONE definition (#CFG-3). */
    private static function maxBrands(): int
    {
        return (int) config('partna.shop_brands_max');
    }

    // How long the picker-warmed product catalog stays cached, so a PUT
    // /selection right after the picker opened reuses it instead of re-scraping.
    private const CATALOG_TTL_MINUTES = 10;

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

    /** Cap on the stored referral suffix; mirrored by UrlParamExtractor. */
    private const MAX_REFERRAL_QUERY = 500;

    // Query params ShopOutboundUrl::compose() writes itself. A referral is
    // appended AFTER the merchant's own discount, and stores that see a
    // repeated key take the last one — so a referral carrying one of these
    // would override the merchant. Matched case-insensitively.
    private const REFERRAL_RESERVED_PARAMS = ['discount', 'add-to-cart'];

    public function __construct(
        private readonly ShopProviderDetector $detector,
        private readonly ShopifyScraper $shopify,
        private readonly WooCommerceScraper $woocommerce,
        private readonly IntegrationConnectionCacheRefresher $refresher,
        private readonly ShopCatalog $catalog,
        private readonly FetchBudget $budget,
        private readonly ShopBrandProfiler $profiler,
        private readonly ShopBrandIdentity $identity,
        private readonly ContentPopularityReader $popularity,
        private readonly ShopContentReader $contentReader,
        private readonly ShopContentWriter $content,
        private readonly ShopConnections $shop,
        private readonly ProductPageAdder $products,
    ) {}

    // The FAMILY key: the per-user lock and FeatureAvailability only. Rows carry
    // per-store brand surfaces, and reads scope on ShopConnections' explicit
    // surface list — NOT on routingClass(), because `shop` as a routing class
    // also covers gumroad/stan/bandcamp, which are separate platforms with
    // their own controllers. See ShopConnections::connectionIds().
    protected function platform(): string
    {
        return 'shop';
    }

    /**
     * Purge the sitepage cache after a shop mutation.
     *
     * The anchor payload is a static marker, so
     * IntegrationConnectionObserver's payload-dirty gate never fires for shop
     * and every mutating method has always had to invoke the refresher
     * explicitly. It used to pass THE marker row; with one connection per store
     * there is no single row to pass, and after a removeBrand()/removeProduct()
     * that emptied the shop there may be none at all.
     *
     * The refresher's work is per-USER (it resolves that owner's subdomain and
     * purges their edge cache), so any of their shop connections is an
     * equivalent argument. When none survives, the delete that emptied the shop
     * already fired the observer's own purge, so nothing is lost — which is why
     * this does not manufacture a row to pass.
     */
    private function refreshShopCache(User $user): void
    {
        $connection = $this->shop->connections($user)->first();

        if ($connection !== null) {
            $this->refresher->refresh($connection);
        }

        $this->bumpSiteCache($user);
    }

    /**
     * Find-or-create the connection anchoring ONE store, then gate it exactly as
     * writeConnection() did the marker — the create/update ability and the
     * staff-takedown check both still fire, on the same family key.
     */
    private function shopAnchor(User $user, ?string $provider, string $brandId): IntegrationConnection
    {
        $this->assertPlatformAvailable($user);

        $surface = ShopConnections::surfaceFor($provider);
        $existing = $user->integrationConnections()
            ->where('surface_key', $surface)->where('resource_id', $brandId)->first();

        $this->authorizeForUser(
            $user,
            $existing ? 'update' : 'create',
            $existing ?? new IntegrationConnection([
                'user_id' => $user->id, 'platform' => $surface, 'resource_id' => $brandId,
            ]),
        );

        return $this->shop->anchor($user, $provider, $brandId);
    }

    // GET /api/platforms/shop/brands — all connected brands. Task 7 served
    // this from content.* (ShopContentReader) merged over the live
    // site.shop_brands, because addBrand()/the deferred-connect job/
    // setProducts() didn't write content.* yet. Task 8 makes all three do
    // so (see each method below), so the merge is gone — a bare
    // ShopContentReader::brandMap() read is now safe.
    public function brands(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $map = $this->contentReader->brandMap($user, $this->productRanksFor($user));

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
        // signal is $collectionId via reference. A null $collectionId after the
        // lock releases means the closure already produced a terminal response
        // (the cap's 422, or withConnectionLock's own 423 lock-timeout) —
        // return it unchanged, dispatch nothing. This is what lets the job
        // dispatch happen AFTER the lock releases: under QUEUE_CONNECTION=sync,
        // dispatching from inside the closure would self-deadlock the job
        // against this same per-user platform lock.
        //
        // Re-home Task 7/9: it was $brandRow, the legacy row, until that write
        // was replaced by upsertStore() — whose return value is both the
        // sentinel AND what the two async jobs key on, so one out-parameter now
        // does the work of two.
        $collectionId = null;
        $lockResponse = $this->withConnectionLock($user, function () use ($user, $detected, $brand, $detectedProducts, $id, $deferred, $validated, &$collectionId): JsonResponse {
            // Convergence Phase 6 (owner ruling 4): the cap and the position are
            // resolved across the user's WHOLE shop family before the anchor is
            // minted, because minting first would leave an empty connection
            // behind on the 422 — a store slot occupied by nothing.
            //
            // Re-home Task 6: all three reads below come off ONE stores() call.
            // Each brands() call was its own query; stores() rebuilds the whole
            // family per call, so three calls would be three full scans of it.
            $stores = $this->shop->stores($user);
            $existing = $stores->get($id);
            // The reserved individual-products bucket doesn't occupy a store slot.
            // Strict filter(), not ->where('isIndividual', false): Collection's
            // where() compares loosely, so a falsey-but-not-false value would
            // count as a store.
            $storeCount = $stores->filter(fn (StoreRecord $s): bool => $s->isIndividual === false)->count();
            if (! $existing && $storeCount >= self::maxBrands()) {
                return $this->error('You can connect up to '.self::maxBrands().' stores.', 422);
            }

            // One connection per STORE, on its real brand surface, keyed by the
            // brand id. `partna.storefront` — one marker row for every store a
            // user had — is retired.
            $connection = $this->shopAnchor($user, $detected['provider'], $id);

            $discount = array_key_exists('discountCode', $validated)
                ? trim((string) $validated['discountCode'])
                // Not `?->`: on the left of ?? the nullsafe is redundant —
                // ?? uses isset() semantics, so it already absorbs a null
                // $existing without a warning. PHPStan rejects the pair.
                : ($existing->discountCode ?? '');

            // max() on a Collection of objects returns null for an empty set
            // where the query Builder returned 0 — hence the coalesce, which the
            // Builder version did not need.
            $maxPosition = $stores->max('position');
            $position = $existing?->position ?? (($maxPosition === null ? -1 : $maxPosition) + 1);

            // Re-home Task 7: ONE write, straight to content.* through
            // upsertStore(). The legacy site.shop_brands row this used to mint
            // (and the mirroring upsertStore() that followed it) are both gone.
            //
            // The non-destructive-omit discipline the legacy write got for free
            // from updateOrCreate — absent keys simply not written — has to be
            // spelled out here, because upsertStore() lists every column
            // unconditionally in its ON CONFLICT clause. Anything not being
            // deliberately set is carried from $existing, the store as
            // content.* already holds it.
            $store = new StoreRecord(
                externalRef: $id,
                provider: $detected['provider'],
                // Deferred: the display profile is NOT known yet, so a re-add of
                // an already-settled store must keep the one it has for the
                // whole pending window instead of being blanked (§3c).
                // ShopBrandConnectJob writes the real one when it settles.
                name: $deferred ? $existing?->name : $brand['name'],
                position: $position,
                url: $detected['origin'],
                sourceUrl: $detected['sourceUrl'],
                // currency is the one thing the deferred branch DOES know: for
                // Shopify it is truthful at 202 time with zero extra HTTP (the
                // carried meta.json), and ShopCatalog::providerProducts() uses
                // it as the per-product fallback, so dropping it would show a
                // null currency in the picker during the pending window.
                // syncCurrencyFor() returns null for Woo (never known) and
                // Squarespace (not until the deferred fetch), and the coalesce
                // then falls back to what is already stored rather than
                // overwriting it with null.
                currency: $deferred
                    ? ($this->profiler->syncCurrencyFor($detected) ?? $existing?->currency)
                    : ($brand['currency'] ?? null),
                discountCode: $discount,
                // Never touched by a connect — the owner sets it, and a re-add
                // must not lose it.
                referralQuery: $existing->referralQuery ?? '',
                isIndividual: false,
                // Client-connected stores can't be re-scraped server-side; the
                // flag routes product reads through the catalog cache + the
                // client re-warm endpoint instead of abort(502)ing.
                fetchMode: $detected['fetchMode'] ?? null,
                // Settle/clear any prior pending or failed state on the sync
                // branch; arm the poll on the deferred one.
                connectStatus: $deferred ? 'pending' : null,
                connectError: null,
                logoUrl: $deferred ? $existing?->logoUrl : $brand['logo'],
                faviconUrl: $deferred ? $existing?->faviconUrl : $brand['favicon'],
                // ProcessShopBrandLogoJob's to write — carried so a re-add does
                // not blank marks an earlier processing run earned.
                logoMarkUrl: $existing?->logoMarkUrl,
                logoMarkSvgUrl: $existing?->logoMarkSvgUrl,
            );

            // No ->touch() equivalent is needed and none exists any more. The
            // legacy write needed one because a retry of an already-pending
            // store wrote byte-identical values, so Eloquent's fill()->save()
            // skipped the UPDATE and left updated_at stale — which the poll's
            // stale-pending backstop then read as 'failed'. upsertStore()'s ON
            // CONFLICT DO UPDATE has no dirty check and always writes the row,
            // so the clock moves on every call (spec §12.2, and pinned by
            // ShopAsyncConnectTest's P1).
            $collectionId = $this->content->upsertStore($store, (string) $user->id);

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
            $this->bumpSiteCache($user);

            // P3 review fix: build the response body INSIDE the lock (restored
            // pre-W9 behaviour). Reading the store AFTER the lock releases left
            // a window where a concurrent removeBrand()/forget() from the same
            // user could retire it between lock release and the read, turning
            // the payload build into a fatal on a null. Only the job DISPATCH
            // below needs to
            // stay outside the lock (self-deadlocks under the sync driver —
            // see the sentinel comment above); the response payload itself has
            // no such constraint and is cheap to build while still holding it.
            //
            // Built from ShopContentReader now (Task 8), matching brands()/
            // etc — the upsertStore() call just above guarantees a row exists
            // to read. Re-home Task 2 dropped the legacy toBrandArray()
            // fallback: it read site.shop_products, which nothing has written
            // since slice 5a. `?? []` keeps the never-fatal property that
            // fallback also had (ShopBrandResource is wholly `??`-tolerant),
            // rather than letting an impossible miss raise on array access.
            $resolved = (new ShopBrandResource(
                $this->contentReader->brandMap($user)[$id] ?? []
            ))->resolve();

            if (! $deferred) {
                // Best-effort mark processing (background removal + SVG) —
                // the raw favicon/logo stays either way.
                ProcessShopBrandLogoJob::dispatch($collectionId);

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

        if ($collectionId === null) {
            return $lockResponse;
        }

        if ($deferred) {
            // AFTER the lock has released — see the sentinel comment above.
            ShopBrandConnectJob::dispatch($collectionId)->afterCommit();
        } else {
            // The synchronous lane (generic/client-detected) never passes
            // through ShopBrandConnectJob, so without this it was the ONE
            // connect lane with no initial catalogue fill and no first-connect
            // auto-select (T1 critic pass, 2026-08-20). The job's fill +
            // ShopAutoSelector are both idempotent, and its settle() CAS
            // no-ops here (connect_status is already null) — only the
            // post-settle tail... which is exactly why ShopInitialFillJob
            // exists rather than reusing ShopBrandConnectJob.
            ShopInitialFillJob::dispatch($collectionId)->afterCommit();
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
        // Convergence Phase 6: stores are resolved across the user's whole shop
        // family, not off one marker connection. Re-home Task 6: off content.*
        // rather than site.shop_brands. stores() scopes on the collection's
        // user_id, so another owner's store is still never visible.
        $store = $this->shop->store($user, $id);

        if (! $store) {
            return $this->error('Brand not found.', 404);
        }

        if ($store->connectStatus === 'pending') {
            // Stale-pending backstop, ported from GenericPlatformController::
            // connectStatus() (NOT Instagram's connectStatus(), which has no
            // staleness check) — a worker that dies leaves the row 'pending'
            // forever with nothing to flip it. Window and its justification:
            // StrandedPendingWindow. SYNTHETIC — never writes the row, so a
            // merely-slow (not dead) worker can still land its real settle
            // afterwards and the next poll reports 'ready'.
            //
            // The clock is content.storefronts.updated_at now (spec §12.2).
            // upsertStore() bumps it on every write including a byte-identical
            // one — ON CONFLICT DO UPDATE has no dirty check — so addBrand()'s
            // explicit ->touch(), which existed only to defeat Eloquent's,
            // is gone rather than ported.
            if ($store->updatedAt !== null && $store->updatedAt->lt(now()->subMinutes(StrandedPendingWindow::MINUTES))) {
                return $this->success(['status' => 'failed', 'error' => FetchUnavailableException::STALE_CONNECT_ERROR]);
            }

            return $this->success(['status' => 'pending']);
        }

        // The store resolved through content.*, so brandMap() has an entry for
        // it by construction — same query, same scope. That is what retires the
        // legacy fallback brandPayload() carried: the miss it guarded against
        // now 404s five lines up.
        $payload = (new ShopBrandResource($this->contentReader->brandMap($user)[$id] ?? []))->resolve();

        if ($store->connectStatus === 'failed') {
            // Unlike the six bespoke platforms, a failed Shop store IS still
            // usable — external_ref/provider/url/source_url are all truthful, so
            // the picker and public render both work (§3g) — carry it.
            return $this->success([
                'status' => 'failed',
                'error' => $store->connectError ?: self::UNKNOWN_CONNECT_ERROR,
                'brand' => $payload,
            ]);
        }

        return $this->success([
            'status' => 'ready',
            'id' => $store->externalRef,
            'brand' => $payload,
        ]);
    }

    // PATCH /api/platforms/shop/brands/{id} — update PER-BRAND settings: the
    // discount code and the referral URL (stored as its parsed query suffix).
    // Every field is optional; only what's present is applied.
    //
    // The per-brand linkMode was DELETED 2026-08-19 (dormant since
    // 2026-07-08): link mode is one GLOBAL site setting
    // (site.sites.shop_link_mode via /platforms/shop/settings). The request
    // no longer accepts the key and the resource no longer echoes it.
    //
    // selectionMode is NOT dormant — #SEM-1: setting it to 'latest' clears
    // products_curated_at (the opt-BACK-IN path after setProducts() curated the
    // brand — see that method) and triggers a one-off immediate sync below.
    public function updateBrand(UpdateShopBrandRequest $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);
        $validated = $request->validated();

        return $this->withConnectionLock($user, function () use ($user, $id, $validated) {
            $store = $this->shop->store($user, $id);
            if (! $store) {
                return $this->error('Brand not found.', 404);
            }

            // #SEC-10 defence-in-depth: store() is already user-scoped, but a
            // second lock on the real anchor connection catches a future
            // scoping regression. No-op when unminted (mirrors removeBrand()).
            if (($anchor = $this->shop->anchorFor($user, $id)) !== null) {
                $this->authorizeForUser($user, 'update', $anchor);
            }

            // Re-home Task 7: the edit folds onto the record content.* holds and
            // goes back through upsertStore(), which is now the only writer.
            //
            // Two of the four accepted fields have NOTHING to write. selectionMode
            // and linkMode are dead columns: ShopContentReader reports
            // selectionMode as the constant 'manual' and linkMode from
            // site.sites.shop_link_mode (its gap 3), so the legacy per-store
            // columns had nothing left reading them even before the DROP. What
            // selectionMode='latest' still DOES is un-curate the store, and that
            // is the direct write below.
            $overrides = [];
            if (array_key_exists('discountCode', $validated)) {
                $overrides['discountCode'] = trim((string) $validated['discountCode']);
            }
            if (array_key_exists('referralUrl', $validated)) {
                $overrides['referralQuery'] = self::referralQueryFrom($validated['referralUrl']);
            }

            $store = $store->with($overrides);
            $collectionId = $this->content->upsertStore($store, (string) $user->id);

            // Per-store auto-latest (2026-08-17): written on THIS store's
            // anchor connection alone — the read-time storefront arm
            // (SectionCandidates) publishes the store's newest while it is
            // on, and ShopFetch tracks the catalogue on the same key.
            // Distinct from /shop/settings autoLatest, which writes every
            // store at once.
            if (array_key_exists('autoLatest', $validated)) {
                // anchor(), not anchorFor(): find-or-create, so a store whose
                // anchor row never minted (pre-re-home fixtures, a partial
                // connect) heals here instead of the toggle silently no-oping.
                $anchor = $this->shop->anchor($user, $store->provider, $id);
                $settings = (array) ($anchor->display_settings ?? []);
                $settings[AutoSyncSetting::KEY] = (bool) $validated['autoLatest'];
                $anchor->display_settings = $settings;
                $anchor->save();
                $this->refresher->refresh($anchor);
            }

            if (($validated['selectionMode'] ?? null) === 'latest') {
                // #SEM-1 opt-back-in: 'latest' un-curates the store so ShopFetch
                // picks it back up on the next scheduled sync (the immediate
                // sync below also runs, so the two never race).
                //
                // A direct write because upsertStore()'s conflict clause
                // deliberately COALESCEs products_curated_at — it never lets a
                // routine sync clobber an existing stamp — so it cannot itself
                // un-curate a store. This is the one place allowed to un-set it.
                DB::table('content.storefronts')->where('collection_id', $collectionId)
                    ->update(['products_curated_at' => null, 'updated_at' => now()]);
                $store = $store->with(['productsCuratedAt' => null, 'collectionId' => $collectionId]);
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
                    $syncFailed = $this->budget->open($seconds, fn () => $this->catalog->syncLatest($store, (string) $user->id)) === null;
                } catch (HttpException) {
                    $syncFailed = true;
                }
            }

            $this->refreshShopCache($user);

            $payload = (new ShopBrandResource(
                $this->contentReader->brandMap($user)[$id] ?? []
            ))->resolve();
            // Fix round 1, I2: echo back the mode the client just set. The
            // content.* read path reports `selectionMode` as the derived
            // constant 'manual' (ShopContentReader gap 3 — the column was
            // dead), which Task 7 sanctioned for GET. Letting that constant
            // answer a PATCH that just sent 'latest' is different: a dashboard
            // that reconciles its toggle from the mutation response would
            // visibly flip back. The write itself still happened
            // (products_curated_at cleared + the immediate sync below).
            if (array_key_exists('selectionMode', $validated)) {
                $payload['selectionMode'] = $validated['selectionMode'];
            }
            $payload['latestSyncPending'] = $syncFailed;

            return $this->success($payload);
        });
    }

    /**
     * Extract the query-string "end bit" from a pasted referral URL. Accepts a
     * full URL (`https://store.com/?ref=abc` → `ref=abc`), a bare query
     * (`ref=abc`), or empty/null to clear. Anything unparseable stores ''.
     * The result is appended to every outbound product link.
     *
     * REBUILT from the parse rather than returned raw (2026-08-14). The old
     * body called parse_str() only to test well-formedness and then returned
     * the caller's original string untouched, so `ref=abc&discount=FREE`
     * passed validation and was stored whole — and since
     * ShopOutboundUrl::compose() appends the merchant's own `discount=` FIRST
     * and this suffix after it, a store reading the last repeated key took the
     * crafted one. Round-tripping through http_build_query() drops the
     * reserved params and percent-encodes `#` and friends, which the raw
     * passthrough let straight into the composed URL.
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

        $parsed = array_filter(
            $parsed,
            fn ($key) => ! in_array(strtolower((string) $key), self::REFERRAL_RESERVED_PARAMS, true),
            ARRAY_FILTER_USE_KEY,
        );

        if ($parsed === []) {
            return '';
        }

        $built = http_build_query($parsed, '', '&', PHP_QUERY_RFC3986);
        if (mb_strlen($built) <= self::MAX_REFERRAL_QUERY) {
            return $built;
        }

        // Over the cap: drop whole pairs. Cutting mid-`%XX` would store a
        // malformed escape that the store would then reject or mis-read.
        $cut = mb_strrpos(mb_substr($built, 0, self::MAX_REFERRAL_QUERY + 1), '&');

        return $cut === false ? '' : mb_substr($built, 0, $cut);
    }

    // DELETE /api/platforms/shop/brands/{id} — remove a brand.
    public function removeBrand(Request $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->withConnectionLock($user, function () use ($user, $id) {
            // No such brand → 404. The old version gated on the marker
            // connection existing, because a miss there fell through to
            // writeConnection() and RECREATED the marker at status 'ok' — a
            // delete that resurrected the connection and rang "Shop connected"
            // on it. Convergence Phase 6 removes the shape of that bug rather
            // than guarding it: there is no shared marker to resurrect, and
            // nothing below this line mints a connection.
            $store = $this->shop->store($user, $id);
            if (! $store) {
                return $this->error('Brand not found.', 404);
            }

            // Retire this store's items and drop its content.* row. NEVER a
            // hard delete of content.items — see retireStore()'s own docblock.
            // The collection id is on the record already (re-home Task 9), so
            // the read-only collectionIdFor() lookup this used to need is gone.
            $this->content->retireStore((string) $user->id, (string) $store->collectionId);

            // Convergence Phase 6: the store's OWN anchor goes with it. One
            // connection per store means a surviving anchor is an empty store —
            // it would keep the surface reading as connected on the dashboard,
            // keep being selected by the refresh cron, and (since the anchor's
            // resource_id IS the store id) collide with a later re-add.
            //
            // Re-home Task 7: the guard was `no ShopBrand rows left on this
            // connection`, which existed for ONE case — the retired
            // `partna.storefront` marker, the only surface where several stores
            // shared an anchor. Live dev carries zero of those (Phase 6's
            // retirement migration cleared them), and content.* has no
            // connection linkage to count through anyway. Guard on the SURFACE
            // instead: a per-store anchor is emptied by definition once its own
            // store is retired, and a marker row that somehow survived is left
            // alone rather than deleted out from under other stores.
            $anchor = $this->shop->anchorFor($user, $store->externalRef);
            if ($anchor !== null && $anchor->surface_key !== ShopConnections::LEGACY_SURFACE) {
                $this->authorizeForUser($user, 'delete', $anchor);
                $anchor->delete();
            }

            $this->refreshShopCache($user);

            return $this->success(['brands' => ShopBrandResource::collection($this->allBrands($user))->resolve()]);
        });
    }

    // POST /api/platforms/shop/brands/{id}/catalog — client-assisted catalog
    // re-warm for a client-mode brand: the browser fetched the store's public
    // Store API and posts the raw products; we normalise + host-pin them and
    // warm the same picker cache the live scrape would have.
    public function catalog(SubmitShopCatalogRequest $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);
        $map = $this->brandMap($user);
        if (! isset($map[$id])) {
            return $this->error('Brand not found.', 404);
        }

        // #SEC-10 defence-in-depth: brandMap() is already user-scoped, but a
        // second lock on the real anchor connection catches a future scoping
        // regression. No-op when unminted (mirrors removeBrand()).
        if (($anchor = $this->shop->anchorFor($user, $id)) !== null) {
            $this->authorizeForUser($user, 'update', $anchor);
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
    // Task 8: the existence check + the {url, provider, sourceUrl, fetchMode}
    // dispatch shape providerProducts() reads now come straight from
    // ShopContentReader::brandMap() — every writer below keeps content.* in
    // sync, so the Task 7 hybrid merge is no longer needed.
    public function brandProducts(Request $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);
        $map = $this->contentReader->brandMap($user, $this->productRanksFor($user));
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
        $store = $this->shop->store($user, $id);
        if (! $store) {
            return $this->error('Brand not found.', 404);
        }

        // Prefer the catalog the picker just warmed; only re-scrape if it has
        // gone cold (a save long after the picker was opened). Outside the
        // lock — see the docblock above.
        //
        // Re-home Task 2: the dispatch shape is ShopCatalog::dispatchShapeFor()
        // now, not $brand->toBrandArray() — same {url, provider, sourceUrl,
        // currency, fetchMode} fields, but its `products` fallback reads
        // content.* instead of the long-unwritten site.shop_products. Shared
        // with syncLatest() rather than rebuilt here, so the two can't drift.
        $seconds = (float) config('partna.http_fetch.connect_budget_seconds', 20);
        $catalog = Cache::get($this->catalogKey($id))
            ?? $this->budget->open($seconds, fn () => $this->catalog->providerProducts(
                $this->catalog->dispatchShapeFor($store, (string) $user->id)
            ));

        return $this->withConnectionLock($user, function () use ($user, $id, $validated, $catalog) {
            // Authoritative re-read — the pre-lock $store above may be stale.
            $store = $this->shop->store($user, $id);
            if (! $store) {
                return $this->error('Brand not found.', 404);
            }

            // #SEC-10 defence-in-depth: store() is already user-scoped, but a
            // second lock on the real anchor connection catches a future
            // scoping regression. No-op when unminted (mirrors removeBrand()).
            if (($anchor = $this->shop->anchorFor($user, $id)) !== null) {
                $this->authorizeForUser($user, 'update', $anchor);
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

            // Task 8: reconcile straight into content.* — syncStore() upserts
            // by coord and retires (items.removed_at) whatever this
            // collection no longer carries, never a hard delete (analytics.
            // item_views references item ids). Order preserved: $selected is
            // already in the caller's chosen order, and syncStore() writes
            // content.collection_items.position from the array index. This
            // replaces the old delete+reinsert into site.shop_products
            // entirely — that table is written by nothing from this endpoint
            // now (Step 1/Step 5 of the Task 8 plan).
            $collectionId = (string) $store->collectionId;
            $this->content->syncStore((string) $user->id, $collectionId, $selected->all(), $store->currency);

            // #SEM-1: products_curated_at is what ShopFetch's ShopContentWriter::
            // isCurated() reads FIRST to skip this brand on the next scheduled
            // sync — a direct write, not routed through upsertStore()'s own
            // COALESCE conflict clause, because that clause deliberately
            // refuses to move an already-non-null stamp (a routine sync must
            // never clobber a curation event) and would therefore also refuse
            // to REFRESH it to a newer "now" on a second curation of the same
            // brand. selection_mode is not written here — it is a derived
            // constant on the content.* read path now (always 'manual'; see
            // ShopContentReader's docblock, gap 3), so the legacy column has
            // nothing left reading it.
            DB::table('content.storefronts')->where('collection_id', $collectionId)
                ->update(['products_curated_at' => now(), 'updated_at' => now()]);

            $this->refreshShopCache($user);
            // Invalidate the picker catalog so a subsequent GET re-scrapes the
            // store instead of serving the pre-selection snapshot for up to 10 min.
            Cache::forget($this->catalogKey($id));

            return $this->success((new ShopBrandResource(
                $this->contentReader->brandMap($user)[$id] ?? []
            ))->resolve());
        });
    }

    // GET /api/platforms/shop/selection — COMPAT flat view of the primary brand
    // (first brand that has products) so partna-pages' existing Shop card keeps
    // rendering. Returns null when no brand has products. Served straight from
    // ShopContentReader as of Task 8 — setProducts() now stamps content.*
    // synchronously, so a brand curated moments ago is never dark here.
    public function selection(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $primary = ShopPayload::fromArray($this->contentReader->brandMap($user, $this->productRanksFor($user)))->primaryWithProducts();

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
            // Re-home Task 7: the legacy brand-row delete that used to run
            // first is gone with the rest of the legacy writes. The retire
            // below was always the real one — it is what clears the user's
            // stores everywhere the dashboard and the public page read.
            //
            // Retire every content.* storefront this user has, deliberately NOT
            // scoped to the connections resolved below: a store that somehow
            // landed in content.* without (or after losing) its anchor still
            // gets cleaned up. NEVER a hard delete of content.items — see
            // ShopContentWriter::retireStore()'s own docblock.
            $collectionIds = DB::table('content.collections')
                ->where('user_id', (string) $user->id)->where('kind', 'storefront')
                ->pluck('id');
            foreach ($collectionIds as $collectionId) {
                $this->content->retireStore((string) $user->id, (string) $collectionId);
            }

            // Every store's anchor, not one marker. NOT forgetAllConnections():
            // that scopes on platform()/routingClass(), and `shop` as a routing
            // class also covers gumroad/stan/bandcamp — separate platforms with
            // their own controllers, which this endpoint must never touch. Each
            // delete fires the observer's deleted() → unconditional cache
            // refresh, so unlike the other mutations (which only touch child
            // rows) no explicit refresh is needed here.
            foreach ($this->shop->connections($user) as $anchor) {
                $this->authorizeForUser($user, 'delete', $anchor);
                $anchor->delete();
            }
            $this->bumpSiteCache($user);

            return $this->success(['brands' => []]);
        });
    }

    // POST /api/platforms/shop/products — add a single product by URL, not tied
    // to a connected store (mirrors standalone events). Scraped from its own
    // page (JSON-LD → OpenGraph) and kept in the reserved 'individual' bucket.
    public function addProduct(AddShopProductRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        // The OV-A availability assert is explicit here because the adder is a
        // storage helper and does not gate; losing it silently would have let a
        // staff takedown of the shop integration still accept a product add.
        $this->assertPlatformAvailable($user);

        // #SEC-10 defence-in-depth: there is no route-supplied id to mis-scope
        // (the write always lands in $user's own reserved bucket via
        // individualAnchor($user)) — pre-create idiom, mirrors the
        // `new SiteMedia([...])` shape CLAUDE.md documents elsewhere.
        $this->authorizeForUser($user, 'create', new IntegrationConnection(['user_id' => $user->id]));

        // ONE way in (2026-08-17): ProductPageAdder is the page read + the
        // reserved-bucket write this method used to carry inline, shared with
        // the pool lane's POST /content/pools/shop/items — which also pins.
        // This legacy endpoint keeps its own response shape and status codes.
        $added = $this->products->add($user, $request->validated()['url'], connectStore: false);

        // Distinct code when the "product" URL is really a storefront homepage
        // (WS-B1.1) — the dashboard suggests connecting it as a brand instead,
        // prefilled with `storeUrl`.
        if ($added['outcome'] === ProductPageAdder::OUTCOME_STORE_PAGE) {
            return $this->error(
                "That looks like a store's homepage, not a product page. Connect it as a brand to import its products, or paste a specific product's page URL.",
                422,
                extra: ['code' => 'store_homepage', 'storeUrl' => $added['storeUrl']],
            );
        }
        if ($added['outcome'] !== ProductPageAdder::OUTCOME_PRODUCT) {
            return $this->error(
                "Couldn't read a product from that link. Open the product's own page and paste its URL — it needs a title and price in standard product markup.",
                422,
                extra: ['code' => 'no_product_found'],
            );
        }

        $this->bumpSiteCache($user);

        return $this->success((new ShopBrandResource(
            $this->contentReader->brandMap($user)[StoreRecord::INDIVIDUAL_REF] ?? []
        ))->resolve());
    }

    // DELETE /api/platforms/shop/products/{productId} — remove one individual
    // product; drops the reserved bucket entirely once it's empty.
    public function removeProduct(Request $request, string $productId): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->withConnectionLock($user, function () use ($user, $productId) {
            $individual = $this->shop->store($user, StoreRecord::INDIVIDUAL_REF);
            if (! $individual) {
                return $this->error('Product not found.', 404);
            }

            // #SEC-10 defence-in-depth: store() is already user-scoped, but a
            // second lock on the real anchor connection catches a future
            // scoping regression. No-op when unminted (mirrors removeBrand()).
            if (($anchor = $this->shop->anchorFor($user, StoreRecord::INDIVIDUAL_REF)) !== null) {
                $this->authorizeForUser($user, 'update', $anchor);
            }

            // Reconstruct what remains (mirrors addProduct()'s currentCatalogue()
            // read), sync it, and if nothing is left retire the collection the
            // same way removeBrand() does rather than leaving an empty
            // content.storefronts row behind.
            $collectionId = (string) $individual->collectionId;
            $remaining = collect($this->content->currentCatalogue($collectionId))
                ->reject(fn (array $p) => ($p['productId'] ?? null) === $productId)
                ->values();
            $this->content->syncStore((string) $user->id, $collectionId, $remaining->all(), $individual->currency);
            if ($remaining->isEmpty()) {
                // Fix round 1, C1: the bucket goes ONLY when it is genuinely
                // empty, and content.* is the only place that fact lives. The
                // old guard asked site.shop_products, which addProduct() had
                // stopped writing — so for any bucket built after that deploy it
                // was unconditionally true: the FIRST removal dropped the bucket
                // while content.* still held the remaining products, stranding
                // them (every later DELETE 404s) while the dashboard kept
                // listing them.
                //
                // Re-home Task 7: retireStore() IS the removal now — the legacy
                // row it used to be paired with is gone, so there is one delete
                // where there were two and no way for them to disagree.
                $this->content->retireStore((string) $user->id, $collectionId);
            }
            $this->refreshShopCache($user);

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
            AutoSyncSetting::set((string) $user->id, [...ShopConnections::surfaces(), ShopConnections::LEGACY_SURFACE], (bool) $validated['autoLatest']);
        }

        // Propagate the new public linkMode to the CDN — the shop connection's
        // payload is a static marker (FOUND-25), so its own save never busts
        // the cache; refresh explicitly when the user has a shop connected.
        $connection = $this->connectionFor($user);
        if ($connection) {
            $this->refresher->refresh($connection);
        }
        $this->bumpSiteCache($user);

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
            'autoLatest' => AutoSyncSetting::isOn((string) $site->user_id, [...ShopConnections::surfaces(), ShopConnections::LEGACY_SURFACE]),
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
     * The stored brand map (id => brand), or empty.
     *
     * catalog() is its ONLY caller. Re-home Task 2 moved it off the legacy
     * site.shop_brands/site.shop_products child tables onto content.*, which
     * carries the same {url, provider, sourceUrl, fetchMode} dispatch shape
     * catalog()'s live re-scrape reads — so this is now allBrands()'s keyed
     * twin, not a second source of truth.
     */
    private function brandMap(User $user): array
    {
        return $this->contentReader->brandMap($user, $this->productRanksFor($user));
    }

    /**
     * shop_product ranks re-keyed by product HANDLE for the legacy catalogue
     * shape (ShopContentReader carries handle, not item id). The stored rows
     * key by content.items.id since 2026-08-23, so the id → handle map comes
     * from f_catalog. Fail-open: a read fault degrades to null ranks. Every
     * caller must pass an array (never null) so popularityRank stays PRESENT
     * (not omitted) on every dashboard read, matching brandMap()'s contract.
     *
     * @return array<string, int> handle => rank
     */
    private function productRanksFor(User $user): array
    {
        $byId = $this->popularity->forSite($user->site?->id)['shop_product'] ?? [];
        if ($byId === []) {
            return [];
        }
        $out = [];
        try {
            DB::table('content.items as i')
                ->join('content.f_catalog as fc', 'fc.item_id', '=', 'i.id')
                ->where('i.user_id', (string) $user->id)
                ->where('i.kind', 'product')
                ->whereIn('i.id', array_keys($byId))
                ->whereNotNull('fc.handle')
                ->get(['i.id', 'fc.handle'])
                ->each(function ($row) use (&$out, $byId): void {
                    $out[(string) $row->handle] ??= $byId[(string) $row->id];
                });
        } catch (QueryException) {
            return [];
        }

        return $out;
    }

    /**
     * Brands as a plain ordered list — the content.* shape, matching
     * brands()/brandProducts()/selection() above (Task 8 dropped the Task 7
     * hybridBrandMap() merge once addBrand()/ShopBrandConnectJob/
     * setProducts() all started keeping content.* current).
     */
    private function allBrands(User $user): array
    {
        return array_values($this->contentReader->brandMap($user, $this->productRanksFor($user)));
    }

    /**
     * The two cache lanes IntegrationConnectionCacheRefresher doesn't own
     * (that class only dispatches the edge purge — see its own docblock).
     * Every write endpoint below now bypasses Eloquent for its content.*
     * writes (ShopContentWriter is raw DB::table(), no model, no observer),
     * so nothing else fires BuildState::bump()/site.sites.updated_at for
     * them. The same "raw-write seam, all three lanes by hand" discipline
     * ShopBackfiller::invalidate() established before it was deleted with the
     * legacy tables. Site-nullable: a fixture/
     * connection with no site row simply skips both (mirrors the
     * refresher's own nullable subdomain lookup).
     */
    private function bumpSiteCache(User $user): void
    {
        $site = $user->site;
        if ($site === null) {
            return;
        }

        BuildState::bump((string) $site->id);
        DB::connection('pgsql')->table('site.sites')->where('id', $site->id)->update(['updated_at' => now()]);
    }

    /** Per-brand picker-catalog cache key. */
    private function catalogKey(string $id): string
    {
        return CacheKeyGenerator::shopifyBrandCatalog($id);
    }
}
