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
use App\Models\Core\Site\IntegrationConnection;
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
use App\Services\Shop\ShopConnections;
use App\Services\Shop\ShopContentReader;
use App\Services\Shop\ShopContentWriter;
use App\Site\Documents\BuildState;
use App\Site\Pools\AutoSyncSetting;
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
// connection's JSONB payload. site.shop_brands survives as the per-brand
// identity/lifecycle anchor this controller still writes (and which the
// not-yet-repointed public read path still reads); site.shop_products is
// no longer written at all — the deletes below only clear pre-deploy rows.
// Slice 7 drops both.
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

    private const MAX_BRANDS = 5;

    private const MAX_INDIVIDUAL_PRODUCTS = 20;

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
        private readonly GenericShopScraper $generic,
        private readonly IntegrationConnectionCacheRefresher $refresher,
        private readonly ShopCatalog $catalog,
        private readonly FetchBudget $budget,
        private readonly ShopBrandProfiler $profiler,
        private readonly ShopBrandIdentity $identity,
        private readonly ContentPopularityReader $popularity,
        private readonly ShopContentReader $contentReader,
        private readonly ShopContentWriter $content,
        private readonly ShopConnections $shop,
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
        // signal is $brandRow via reference. A null $brandRow after the lock
        // releases means the closure already produced a terminal response (the
        // cap's 422, or withConnectionLock's own 423 lock-timeout) — return it
        // unchanged, dispatch nothing. This is what lets the job dispatch
        // happen AFTER the lock releases: under QUEUE_CONNECTION=sync,
        // dispatching from inside the closure would self-deadlock the job
        // against this same per-user platform lock.
        $brandRow = null;
        $lockResponse = $this->withConnectionLock($user, function () use ($user, $detected, $brand, $detectedProducts, $id, $deferred, $validated, &$brandRow): JsonResponse {
            // Convergence Phase 6 (owner ruling 4): the cap and the position are
            // resolved across the user's WHOLE shop family before the anchor is
            // minted, because minting first would leave an empty connection
            // behind on the 422 — a store slot occupied by nothing.
            $existing = $this->shop->brand($user, $id);
            // The reserved individual-products bucket doesn't occupy a store slot.
            $storeCount = $this->shop->brands($user)->where('is_individual', false)->count();
            if (! $existing && $storeCount >= self::MAX_BRANDS) {
                return $this->error('You can connect up to '.self::MAX_BRANDS.' stores.', 422);
            }

            // One connection per STORE, on its real brand surface, keyed by the
            // brand id. `partna.storefront` — one marker row for every store a
            // user had — is retired.
            $connection = $this->shopAnchor($user, $detected['provider'], $id);

            $discount = array_key_exists('discountCode', $validated)
                ? trim((string) $validated['discountCode'])
                : ($existing?->discount_code ?? '');

            $maxPosition = $this->shop->brands($user)->max('position');
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

            // Keyed off the row we already resolved, not a fresh updateOrCreate
            // on (connection_id, brand_id): a re-add of a store whose row still
            // hangs off the pre-migration `partna.storefront` marker would match
            // nothing on the new anchor and CREATE A SECOND ROW for one store.
            // Repointing in place is also what migrates it — a re-add settles a
            // legacy row onto its brand surface without waiting for the command.
            $brandRow = $existing ?? new ShopBrand(['brand_id' => $id]);
            $brandRow->connection_id = $connection->id;
            $brandRow->fill($values)->save();

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

            // Task 8: keep content.* current the SAME moment the legacy
            // anchor row is — even on the deferred branch, which writes only
            // url/provider/source_url/currency/connect_status='pending' here
            // (name/favicon/logo land later via ShopBrandConnectJob's own
            // upsertStore() call, mirroring this one). This is what makes
            // the Task 7 hybridBrandMap() merge droppable: brands()/
            // brandProducts()/selection() now always find a row.
            $this->content->upsertStore($brandRow->fresh(), (string) $user->id);

            // The connection's payload is a static marker (FOUND-25) — the
            // observer's payload-dirty gate won't fire for a brand add after the
            // first connect, so purge + preset-resolve explicitly, once.
            $this->refresher->refresh($connection);
            $this->bumpSiteCache($user);

            // P3 review fix: build the response body INSIDE the lock (restored
            // pre-W9 behaviour). Reading $brandRow->fresh() AFTER the lock
            // releases left a window where a concurrent removeBrand()/forget()
            // from the same user could delete this row between lock release
            // and the read, turning fresh() into null and toBrandArray() into
            // a fatal error on a null. Only the job DISPATCH below needs to
            // stay outside the lock (self-deadlocks under the sync driver —
            // see the sentinel comment above); the response payload itself has
            // no such constraint and is cheap to build while still holding it.
            //
            // Built from ShopContentReader now (Task 8), matching brands()/
            // etc — the upsertStore() call just above guarantees a row exists
            // to read; the legacy toBrandArray() fallback covers only a
            // theoretical read-your-own-write miss.
            $resolved = (new ShopBrandResource(
                $this->contentReader->brandMap($user)[$id] ?? $brandRow->fresh('products')->toBrandArray()
            ))->resolve();

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
        // Convergence Phase 6: brands are resolved across the user's whole shop
        // family, not off one marker connection. brands() scopes on the user's
        // own connections, so another owner's brand is still never visible.
        $brand = $this->shop->brand($user, $id);

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
     * of an in-flight/just-settled async job (ShopBrandConnectJob). As of
     * Task 8 that job DOES call ShopContentWriter::upsertStore() on every
     * settle (success or terminal failure) — same-request-cycle read-your-
     * own-write, so the content.* branch is what actually serves the
     * overwhelming majority of polls now. The legacy fallback stays for two
     * narrower cases this endpoint alone still needs to cover: (1) a brand
     * connected before Task 8 shipped that was never backfilled/synced, and
     * (2) addBrand()'s SYNCHRONOUS (non-deferred) branch settles the legacy
     * row and calls upsertStore() itself inside the SAME lock — genuinely
     * atomic — but this endpoint is never the one polled for a sync connect
     * in practice (the 200 response already carries the full brand), so the
     * fallback here is defence-in-depth rather than a load-bearing gap-
     * closer the way it was pre-Task-8.
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
            $brand = $this->shop->brand($user, $id);
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

            // Task 8: mirror onto content.* so the dashboard read endpoints
            // (bare ShopContentReader calls now, no legacy fallback) see
            // this edit immediately.
            $collectionId = $this->content->upsertStore($brand->fresh(), (string) $user->id);
            if (($validated['selectionMode'] ?? null) === 'latest') {
                // #SEM-1 opt-back-in, content.* side: upsertStore()'s own
                // conflict clause deliberately COALESCEs products_curated_at
                // (never lets a routine sync clobber an existing stamp — see
                // that method's docblock), so it cannot itself un-curate a
                // brand. This direct write is the one place allowed to
                // un-set it — mirrors the legacy $updates write above.
                DB::table('content.storefronts')->where('collection_id', $collectionId)
                    ->update(['products_curated_at' => null, 'updated_at' => now()]);
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

            $this->refreshShopCache($user);

            $payload = (new ShopBrandResource(
                $this->contentReader->brandMap($user)[$id] ?? $brand->fresh('products')->toBrandArray()
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
            $brand = $this->shop->brand($user, $id);
            if (! $brand) {
                return $this->error('Brand not found.', 404);
            }

            // Task 8: retire this store's items and drop its content.*
            // row BEFORE the legacy delete below — collectionIdFor() is
            // read-only (never mints a row just to immediately delete
            // it), so a brand that never synced into content.* is a
            // no-op here. NEVER a hard delete of content.items — see
            // ShopContentWriter::retireStore()'s own docblock.
            $collectionId = $this->content->collectionIdFor($brand, (string) $user->id);
            if ($collectionId !== null) {
                $this->content->retireStore((string) $user->id, $collectionId);
            }

            // Explicit child delete (not just relying on the DB's ON DELETE
            // CASCADE) — mirrors MenuFetchJob's pattern elsewhere in this
            // codebase, and keeps this deterministic on SQLite in tests,
            // which doesn't enforce FK cascade.
            ShopProduct::where('brand_id', $brand->id)->delete();
            $anchorId = (string) $brand->connection_id;
            $brand->delete();

            // Convergence Phase 6: the store's OWN anchor goes with it. One
            // connection per store means a surviving anchor is an empty store —
            // it would keep the surface reading as connected on the dashboard,
            // keep being selected by the refresh cron, and (since the anchor's
            // resource_id is the brand id) collide with a later re-add.
            // Guarded on the anchor holding nothing else, so the shared
            // individual-products bucket is never dragged out by a store delete.
            $anchor = $this->shop->connections($user)->firstWhere('id', $anchorId);
            if ($anchor !== null && ! ShopBrand::where('connection_id', $anchorId)->exists()) {
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
        $brand = $this->shop->brands($user)->where('brand_id', $id)->with('products')->first();
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
            $brand = $this->shop->brands($user)->where('brand_id', $id)->with('products')->first();
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

            // Task 8: reconcile straight into content.* — syncStore() upserts
            // by coord and retires (items.removed_at) whatever this
            // collection no longer carries, never a hard delete (analytics.
            // item_views references item ids). Order preserved: $selected is
            // already in the caller's chosen order, and syncStore() writes
            // content.collection_items.position from the array index. This
            // replaces the old delete+reinsert into site.shop_products
            // entirely — that table is written by nothing from this endpoint
            // now (Step 1/Step 5 of the Task 8 plan).
            $collectionId = $this->content->upsertStore($brand, (string) $user->id);
            $this->content->syncStore((string) $user->id, $collectionId, $selected->all(), $brand->currency);

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
                $this->contentReader->brandMap($user)[$id] ?? $brand->fresh('products')->toBrandArray()
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
            // Drop brand/product rows BEFORE the soft-delete so nothing is left
            // hanging off the tombstoned connection row (they'd otherwise orphan
            // until the 30-day hard-delete purge). Explicit child delete (not just
            // the DB's ON DELETE CASCADE) — see removeBrand() for why.
            $connectionIds = $this->shop->connectionIds($user);
            if ($connectionIds !== []) {
                $brandIds = ShopBrand::whereIn('connection_id', $connectionIds)->pluck('id');
                ShopProduct::whereIn('brand_id', $brandIds)->delete();
                ShopBrand::whereIn('connection_id', $connectionIds)->delete();
            }

            // Task 8: retire every content.* storefront this user has — not
            // scoped to $connection above, so a store that somehow landed in
            // content.* without (or after losing) its legacy anchor row still
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
            // The individual-products bucket is not a store, so it does not get
            // a store surface: its anchor is `partna.manual_product`, hidden,
            // dormant and explicitly NOT retired (§16 names it "the manual
            // product add-path"). That reservation is exactly this case.
            $connection = $this->shop->individualAnchor($user);

            $maxPosition = $this->shop->brands($user)->max('position');
            $individual = ShopBrand::firstOrCreate(
                ['connection_id' => $connection->id, 'brand_id' => ShopBrand::INDIVIDUAL_BRAND_ID],
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

            // Task 8: mirrors ShopProductSeeder::seed() exactly (that class
            // is the reference implementation for this same reserved-bucket
            // pattern, already shipped) — newest first, de-duped by
            // productId, capped, reconstructed from content.* rather than
            // read off the now-unwritten site.shop_products rows.
            $collectionId = $this->content->upsertStore($individual, (string) $user->id);
            $ordered = collect($this->content->currentCatalogue($collectionId))
                ->reject(fn (array $p) => ($p['productId'] ?? null) === $productId)
                ->prepend($product)
                ->take(self::MAX_INDIVIDUAL_PRODUCTS)
                ->values();

            $this->content->syncStore((string) $user->id, $collectionId, $ordered->all(), $individual->currency);

            $this->refresher->refresh($connection);
            $this->bumpSiteCache($user);

            return $this->success((new ShopBrandResource(
                $this->contentReader->brandMap($user)[ShopBrand::INDIVIDUAL_BRAND_ID] ?? $individual->fresh('products')->toBrandArray()
            ))->resolve());
        });
    }

    // DELETE /api/platforms/shop/products/{productId} — remove one individual
    // product; drops the reserved bucket entirely once it's empty.
    public function removeProduct(Request $request, string $productId): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->withConnectionLock($user, function () use ($user, $productId) {
            $individual = $this->shop->brand($user, ShopBrand::INDIVIDUAL_BRAND_ID);
            if (! $individual) {
                return $this->error('Product not found.', 404);
            }

            ShopProduct::where('brand_id', $individual->id)->where('product_id', $productId)->delete();

            // Task 8: content.* side — reconstruct what remains (mirrors
            // addProduct()'s currentCatalogue() read), sync it, and if
            // nothing's left, retire the collection the same way removeBrand()
            // does rather than leaving an empty content.storefronts row behind.
            $collectionId = $this->content->upsertStore($individual, (string) $user->id);
            $remaining = collect($this->content->currentCatalogue($collectionId))
                ->reject(fn (array $p) => ($p['productId'] ?? null) === $productId)
                ->values();
            $this->content->syncStore((string) $user->id, $collectionId, $remaining->all(), $individual->currency);
            if ($remaining->isEmpty()) {
                $this->content->retireStore((string) $user->id, $collectionId);
                // Fix round 1, C1: the anchor row goes ONLY when the bucket is
                // genuinely empty, and content.* is the only place that fact
                // now lives. The old guard asked site.shop_products —
                // addProduct() stopped writing that table in this very
                // commit, so for any bucket built after this deploy it was
                // unconditionally true: the FIRST removal deleted the anchor
                // while content.* still held the remaining products, which
                // stranded them (every later DELETE 404s on the missing
                // anchor) while the dashboard kept listing them.
                //
                // Explicit legacy child delete, like removeBrand(): a bucket
                // that predates this deploy can still hold site.shop_products
                // rows this endpoint's single-row delete above didn't cover,
                // and SQLite (tests) doesn't enforce the ON DELETE CASCADE
                // Postgres would rely on.
                ShopProduct::where('brand_id', $individual->id)->delete();
                $individual->delete();
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
            AutoSyncSetting::set((string) $user->id, 'shop', (bool) $validated['autoLatest']);
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
     *
     * catalog() is its ONLY remaining caller — every other read is
     * ShopContentReader::brandMap() now. It stays on the legacy row
     * deliberately: catalog() dispatches a LIVE re-scrape of the store off
     * {url, provider, sourceUrl, fetchMode}, which site.shop_brands still
     * carries and still has written to it (the anchor row is not retired by
     * this slice).
     */
    private function brandMap(User $user): array
    {
        // Convergence Phase 6: across the user's whole shop family. Read off
        // one connection this returned exactly ONE store per user — the one
        // whose anchor happened to be found — so the catalog re-warm endpoint
        // 404'd every store but that one.
        //
        // Fix round 3, Finding 4: $ranks is hoisted OUT of the map closure
        // below — it used to be called PER BRAND, an N+1 on a path GET
        // /selection (a public-feeding endpoint) shares. One value for the
        // whole map, same as ShopContentReader::brandMap()'s own ranks parameter.
        $ranks = $this->productRanksFor($user);

        return $this->shop->brands($user)->with('products')->get()
            ->keyBy('brand_id')
            ->map(fn (ShopBrand $b) => $b->toBrandArray($ranks))
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
     * them — mirrors ShopBackfiller::invalidate()'s identical "raw-write
     * seam, all three lanes by hand" discipline. Site-nullable: a fixture/
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
