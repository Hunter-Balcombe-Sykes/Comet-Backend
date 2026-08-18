<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Routing\IriCanonicalizer;
use App\Routing\LinkObserver;
use App\Routing\Placement;
use App\Routing\Probes\ProbeGate;
use App\Routing\Projection;
use App\Routing\RoutingContext;
use App\Routing\Verdict;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Shop\ProductPageAdder;
use App\Services\Shop\ShopConnections;
use App\Services\Shop\ShopContentWriter;
use App\Services\Shop\StoreRecord;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Job-context seeding of ONE individually-added product from a scanned product
// page (signup-v2 C2). Mirrors ShopController::addProduct()'s locked body —
// the reserved 'individual' bucket, MAX_INDIVIDUAL_PRODUCTS cap, dedup by
// productId, newest-first ordering — without its HTTP/policy wrapper (same
// convention as EventsSeeder/ShopBrandSeeder; see their docblocks for the
// server-derived-user + caller-gating contract).
//
// Slice 5a: the products live in content.* now, so this is no longer a
// "transactional rebuild" of site.shop_products. The existing half is read
// back through ShopContentWriter::currentCatalogue() and the merged set is
// reconciled by syncStore(), which upserts by coord and retires (never
// deletes) what the merge dropped. site.shop_brands is still written as the
// bucket anchor until slice 7 retires it.
class ShopProductSeeder
{
    public function __construct(
        private readonly IntegrationConnectionCacheRefresher $refresher,
        private readonly ShopContentWriter $content,
        private readonly ShopConnections $shop,
        private readonly IriCanonicalizer $canonicalizer,
        private readonly LinkObserver $observer,
    ) {}

    /**
     * $origin names the caller for the observation ledger, exactly as
     * StoreBrandSeeder's does — 'paste' is the safe default (see
     * CommerceProbeJob::ORIGIN for why the probe deliberately does not claim
     * it).
     *
     * @param  array<string,mixed>  $product  GenericShopScraper::readProductPage()'s product shape
     */
    public function seed(User $user, array $product, string $origin = 'paste'): bool
    {
        $context = RoutingContext::forUser($user, $origin);

        // Tombstone parity with ShopBrandSeeder: an explicitly-removed shop
        // connection is never resurrected by a scan.
        //
        // Convergence Phase 6: matched across the whole shop family, not the
        // retired 'shop' slug. A user who disconnected their shop after the
        // split has tombstoned per-store rows and no 'shop' row at all, so the
        // old match found neither the tombstone nor a live row — and the guard
        // silently stopped guarding.
        $surfaces = [...ShopConnections::surfaces(), ShopConnections::LEGACY_SURFACE];
        $wasDisconnected = IntegrationConnection::onlyTrashed()
            ->where('user_id', $user->id)->whereIn('surface_key', $surfaces)
            ->exists();
        $hasLive = IntegrationConnection::query()
            ->where('user_id', $user->id)->whereIn('surface_key', $surfaces)
            ->exists();
        if ($wasDisconnected && ! $hasLive) {
            $this->observe($product, $context, Placement::reject('tombstoned', ShopConnections::INDIVIDUAL_SURFACE));

            return false;
        }

        $key = CacheKeyGenerator::platformConnectionLock('shop', (string) $user->id);
        try {
            $written = Cache::lock($key, 10)->block(5, function () use ($user, $product): bool {
                // The individual-products bucket is not a store, so it anchors
                // on `partna.manual_product` — hidden, dormant and explicitly
                // NOT retired (§16 names it "the manual product add-path"),
                // which is exactly this case. `partna.storefront` is retired.
                $connection = $this->shop->individualAnchor($user);

                // Re-home Task 10: the bucket is a content.* store. The
                // firstOrCreate this replaces had "create only if absent"
                // semantics that upsertStore() does not — it writes every
                // column — so an EXISTING bucket keeps its own position and
                // currency and only a brand-new one takes the defaults.
                // Without that fold, a second seed() for the same user would
                // renumber the bucket and overwrite the currency the first set.
                $stores = $this->shop->stores($user);
                $maxPosition = $stores->max('position');
                $individual = $stores->get(StoreRecord::INDIVIDUAL_REF) ?? new StoreRecord(
                    externalRef: StoreRecord::INDIVIDUAL_REF,
                    provider: ShopProviderDetector::PROVIDER_GENERIC,
                    position: ($maxPosition === null ? -1 : $maxPosition) + 1,
                    url: '',
                    sourceUrl: '',
                    currency: $product['currency'] ?? null,
                    discountCode: '',
                    isIndividual: true,
                );

                $productId = $product['productId'] ?? null;

                // Task 6 fix round 1, Finding 1: the "existing" half now
                // reads content.* (ShopContentWriter::currentCatalogue()),
                // not legacy site.shop_products — this seeder no longer
                // writes that table, so a second seed() call for the same
                // user (CommerceProbeJob fires once per resolved link, so
                // this is a real, non-racy path, not a corner case) would
                // otherwise read a frozen snapshot missing whatever the
                // FIRST call wrote, and syncStore()'s retire-absent would
                // silently drop it. Newest first, de-duped by productId,
                // capped — byte-for-byte the addProduct() ordering contract.
                $collectionId = $this->content->upsertStore($individual, (string) $user->id);
                $ordered = collect($this->content->currentCatalogue($collectionId))
                    ->reject(fn (array $p) => ($p['productId'] ?? null) === $productId)
                    ->prepend($product)
                    ->take(ProductPageAdder::MAX_INDIVIDUAL_PRODUCTS)
                    ->values();

                $this->content->syncStore((string) $user->id, $collectionId, $ordered->all(), $individual->currency);

                // Final review F4: lane 2. writeManualItem() covers the build
                // state and refresh() covers the edge, but nothing moved
                // site.sites.updated_at — the column
                // IndividualProfilePayloadBuilder composes its 60s cache key
                // from — so a freshly seeded product stayed invisible to the
                // public payload for the full TTL. Raw DB::table() and
                // site-nullable for the same reasons as
                // ShopController::bumpSiteCache().
                DB::connection('pgsql')->table('site.sites')
                    ->where('user_id', $user->id)
                    ->update(['updated_at' => now()]);

                $this->refresher->refresh($connection);

                return true;
            });
        } catch (LockTimeoutException) {
            Log::warning('shop_product_seeder.lock_timeout', ['user_id' => (string) $user->id]);
            $this->observe($product, $context, Placement::reject('lock_timeout', ShopConnections::INDIVIDUAL_SURFACE));

            return false;
        }

        $this->observe($product, $context, $written
            ? new Placement(Verdict::Place, ShopConnections::INDIVIDUAL_SURFACE, isset($product['productId']) ? (string) $product['productId'] : null)
            : Placement::reject('not_written', ShopConnections::INDIVIDUAL_SURFACE));

        return $written;
    }

    /**
     * The product lane's ledger row (R6, 2026-08-18).
     *
     * StoreBrandSeeder records every storefront probe it resolves or refuses,
     * and bd593dfdf moved that write above its miss return so a miss lands
     * too. This lane recorded NOTHING — and CommerceProbeJob only reaches
     * StoreBrandSeeder on its store arms, so the 2026-08-18 wave logged all
     * six shop-probe misses while paytherent.net.au, the one URL that actually
     * became a product on a real user's site, had no observation at all. The
     * ledger read backwards from its stated purpose: only "why isn't it on my
     * page?" was answerable, never "why is it?".
     *
     * Hand-built rather than routed through LinkProjector/PlacementPolicy for
     * the same reason ProbeOutcome::toProjection() is: no detector can match a
     * merchant's own domain, so there is no projection to be had. The decision
     * was GenericShopScraper reading the page's own Product markup — direct
     * evidence rather than a pattern guess, which is what PROBE_CONFIDENCE
     * means, with a full margin because nothing competed.
     *
     * Best-effort, like every observation write: LinkObserver swallows its own
     * failures by design, so this cannot fail a seed.
     *
     * @param  array<string,mixed>  $product
     */
    private function observe(array $product, RoutingContext $context, Placement $placement): void
    {
        $iri = $this->canonicalizer->canonicalize((string) ($product['url'] ?? ''));

        $projection = new Projection(
            surfaceKey: ShopConnections::INDIVIDUAL_SURFACE,
            detectorId: null,
            captures: [],
            confidence: ProbeGate::PROBE_CONFIDENCE,
            margin: 100,
            identifier: isset($product['productId']) ? (string) $product['productId'] : null,
            reason: null,
        );

        $this->observer->record($iri, $projection, $placement, $context);
    }
}
