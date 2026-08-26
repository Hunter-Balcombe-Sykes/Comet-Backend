<?php

namespace App\Providers;

use App\Http\Resources\Platforms\LinkConnectionResource;
use App\Http\Resources\Platforms\ShopBrandResource;
use App\Jobs\Platforms\RefreshConnectionJob;
use App\Jobs\Platforms\ThrottledByProvider;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\IntegrationConnectionCacheRefresher;
use App\Services\Platforms\Payloads\ShopPayload;
use App\Services\Platforms\Registry\DerivedDescriptorFactory;
use App\Services\Platforms\Registry\PlatformCategory as Cat;
use App\Services\Platforms\Registry\PlatformDescriptor as PD;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\Registry\PlatformRouteShape;
use App\Services\Platforms\ShopCatalog;
use App\Services\Platforms\Strategies\Connect\BrandLinkConnect;
use App\Services\Platforms\Strategies\Fetch\ShopFetch;
use App\Services\Shop\ShopConnections;
use App\Site\Pools\PoolResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

// Binds the PlatformRegistry singleton. Since PD-retirement (2026-08-27) a
// platform is DECLARED in the catalog (app/Catalog/Definitions) and derived by
// DerivedDescriptorFactory, with bespoke behaviour attached from its
// Registry\Bindings class — this provider hand-registers only the `shop`
// FAMILY descriptor (no catalog surface backs it), runs the derivation +
// upgrade passes, and owns the boot()-time rate limiters.
class PlatformRegistryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PlatformRegistry::class, function () {
            $r = new PlatformRegistry;

            // ── PD-retirement COMPLETE (P1–P5, 2026-08-27) ────────────────────
            // Every brand platform is CATALOG-DERIVED. The catalog definition
            // (app/Catalog/Definitions) is the single declaration of a
            // platform's existence; DerivedDescriptorFactory turns every
            // connectable/bound surface into a descriptor at boot, and a
            // platform's bespoke behavioural contract — strategies, resources,
            // payloads, toggles, cadences, detectors, route shape — attaches
            // from its class in Registry\Bindings (BEHAVIOUR_BINDINGS), or for
            // the link-only socials from LinkOnlyBindings. Frozen strings (422
            // copy, fetch-error copy, toggle copy) live in those bindings and
            // are pinned by tests; the registry:dump harness proved every
            // retirement byte-identical. Decision history: docs/
            // 2026-08-26-pd-registry-retirement-plan.md.
            //
            // What is deliberately NOT here any more: per-platform register()
            // calls, connect strategies, connectInput rules, display toggles,
            // refresh cadences, detect matchers and route-archetype mutations
            // — each rides its platform's binding. OpenTable keeps its bespoke
            // suggestion() endpoint in routes/api/platforms.php; instagram and
            // the apple pair keep their bespoke controllers + hand-written
            // route groups (their descriptors still derive).

            // ── Shop (multi-brand) + smart-detect category pseudo-platforms ──
            $r->register(PD::make('shop')->label('Shop')->category(Cat::Shop)->resource(ShopBrandResource::class)->refreshable()->payload(ShopPayload::class));
            // Latest-mode product sync — auto-tracks every non-individual
            // store's newest products when the site's global shop_auto_latest
            // is on, EXCEPT a store the user hand-curated (#SEM-1:
            // content.storefronts.products_curated_at IS NOT NULL) — see
            // ShopFetch's docblock; when there is nothing left to sync it 304s
            // inside.
            $r->get('shop')->fetch(fn () => new ShopFetch(
                app(ShopCatalog::class),
                app(IntegrationConnectionCacheRefresher::class),
                app(ShopConnections::class),
            ));
            $r->get('shop')->refreshEvery((int) config('partna.refresh.intervals.shop', 6 * 3600));
            // FOUND-25 + W9: a shop connection's payload is a static lifecycle
            // marker — brands/products live relationally and are decoupled from
            // connect (addBrand stores a brand with zero products). An active
            // connection alone isn't real content, so shop keeps a completeness
            // predicate; only the QUESTION it asks changed.
            //
            // Slice 5b: page presence is POOL-derived, exactly as events became
            // in slice 2. The previous closure counted content.collection_items
            // and deliberately did NOT filter items.removed_at, to stay in
            // lockstep with a payload that did not filter it either. The pool
            // read DOES filter it, so asking the pool the question directly is
            // what keeps presence and payload from disagreeing — lockstep by
            // construction rather than by two queries agreeing to be wrong in
            // the same way.
            //
            // The connect_status='pending' exclusion went with it: the pool has
            // no notion of connect_status, so a pending store's products both
            // render AND count. That is a real semantics change, and the right
            // one — W9's exclusion existed to stop presence advertising a page
            // whose payload was empty, and the payload is no longer empty.
            $r->get('shop')->complete(function (IntegrationConnection $c): bool {
                $site = Site::query()->where('user_id', (string) $c->user_id)->first();

                return $site !== null && app(PoolResolver::class)->hasSelection($site, 'shop');
            });
            // The custom / booking / reservations / online-ordering pseudo
            // descriptors left the registry 2026-08-19 (pseudo-platform
            // retirement): zero rows carry those platform keys and every
            // routed link lives on its real brand surface.

            // Shop's display toggle (the old site-wide shop_auto_latest
            // column, same key, same site-wide effect — AutoSyncSetting writes
            // every store connection). Every other platform's toggles ride its
            // binding.
            $r->get('shop')->displayToggles([
                ['key' => 'auto_sync_latest', 'label' => 'Latest products', 'description' => 'Each store keeps showing its newest products automatically.'],
            ]);
            // Derived last, and only into free slugs. The one hand-written
            // registration left is shop above — a FAMILY descriptor with no
            // catalog surface, so the derivation loop can never collide with
            // it in practice; the has() check stays as the safety net that a
            // future hand-written re-addition is skipped rather than a boot
            // failure. Pinned by RegistryCoverageTest's shadow test.
            $factory = app(DerivedDescriptorFactory::class);

            foreach ($factory->build($r->keys()) as $slug => $derived) {
                if (! $r->has($slug)) {
                    $r->register($derived);
                }
            }

            // Then UPGRADE the descriptors that are declared but routeless
            // (Bespoke shape + no connect field). Post-retirement this is the
            // strategy-less LinkOnly slugs — linkOnlyDescriptor() attaches
            // connect/routes only when a normalizer exists, so the 11 plain
            // link cards still take their Brand connect from THIS pass, exactly
            // as they did when they were hand-written. NEVER_UPGRADE carves out
            // instagram, whose derived descriptor has the same routeless shape
            // but a real bespoke connect flow.
            //
            // Mutated in place, not re-registered: the existing descriptor keeps
            // its label, category and resource, and register() would throw anyway.
            foreach ($factory->upgrades($r) as $slug => $spec) {
                $descriptor = $r->get($slug);
                if ($descriptor === null) {
                    continue;
                }

                // Most of these were detect-only cards and carry no Resource,
                // which connectBrand() needs to shape its response. Only fill the
                // gap — never overwrite one a hand-written descriptor already set.
                if ($descriptor->resourceClass() === null) {
                    $descriptor->resource(LinkConnectionResource::class);
                }

                $descriptor
                    ->surfaceKey($spec['surface'])
                    ->connect(
                        fn () => new BrandLinkConnect($slug, $spec['label'], $spec['surface']),
                        'Enter a valid '.$spec['label'].' link.'
                    )
                    ->connectInput('url', ['required', 'string', 'max:2048'])
                    ->routes(PlatformRouteShape::Brand, null, $spec['multi']);

                if ($spec['capability'] !== null) {
                    $capability = $spec['capability'];
                    $descriptor->requiresCapability(
                        static fn (User $user): bool => (bool) AccountCapabilities::for($user)->{$capability}
                    );
                }
            }

            return $r;
        });
    }

    public function boot(): void
    {
        // Per-provider outbound rate limit for the refresh queue. Cache-backed →
        // Redis in prod → shared across ALL workers, so the cap is global, not
        // per-process. Keyed by platform so one provider can't starve the others.
        RateLimiter::for('platform-refresh', function (RefreshConnectionJob $job) {
            $perMinute = (int) config(
                "partna.refresh.rate_limits.{$job->platform}",
                config('partna.refresh.rate_limits.default')
            );

            return Limit::perMinute($perMinute)->by('platform-refresh:'.$job->platform);
        });

        // Per-actor CONNECT-time burst gate (Seam 5). Separate from platform-refresh:
        // connect jobs hit paid Apify actors, refresh jobs hit official APIs — different
        // vendors, different budgets. Keyed by the job's Apify actor so one actor's
        // signup spike can't starve the others. Applied as middleware on the three
        // ThrottledByProvider connect jobs.
        RateLimiter::for('platform-connect', function (ThrottledByProvider $job) {
            $actor = $job->providerRateKey();
            $perMinute = (int) config(
                "partna.connect.rate_limits.{$actor}",
                config('partna.connect.rate_limits.default')
            );

            return Limit::perMinute($perMinute)->by('platform-connect:'.$actor);
        });

        // Per-vendor BURST gate for the pre-account scraping lane
        // (GeneratePreAccountSiteJob, ApproveEarlyAccessBuildJob). The 'instagram'
        // source rides the 'platform-connect' limiter above instead (shared paid-
        // Apify budget — same account as dashboard connects); THIS limiter covers
        // the 'google_business' source, which hits the official Google Places API —
        // a different vendor, so a separate budget. Same cache-backed → Redis-in-
        // prod → global-across-workers shape as the connect/refresh limiters.
        RateLimiter::for('preaccount-places', function (ThrottledByProvider $job) {
            $actor = $job->providerRateKey();
            $perMinute = (int) config(
                "partna.pre_account.rate_limits.{$actor}",
                config('partna.pre_account.rate_limits.default')
            );

            return Limit::perMinute($perMinute)->by('preaccount-places:'.$actor);
        });
    }
}
