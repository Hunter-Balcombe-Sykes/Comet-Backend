<?php

namespace App\Services\Brand;

use App\Jobs\Platforms\ShopInitialFillJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Routing\IriCanonicalizer;
use App\Routing\LinkObserver;
use App\Routing\Placement;
use App\Routing\PlacementPolicy;
use App\Routing\Probes\LinkProbeWorker;
use App\Routing\Probes\ProbeOutcome;
use App\Routing\RoutingContext;
use App\Routing\SourceReconciler;
use App\Routing\Verdict;
use App\Services\Platforms\StorefrontFaviconScraper;
use App\Services\Platforms\UrlParamExtractor;
use App\Services\Shop\ShopConnections;
use App\Services\Shop\ShopContentWriter;
use App\Services\Shop\StoreRecord;
use App\Site\Pools\AutoSyncSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The new-pipeline successor to ShopBrandSeeder (P8 blocker B2).
 *
 * What changes, and why it is the whole point: the legacy seeder DECIDED and
 * WROTE. It ran its own tombstone check (a soft-deleted `shop` connection with
 * no live sibling), its own lock, its own connection upsert. Four other
 * seeders did the same thing slightly differently, which is how a user's
 * deletion could be honoured on one path and ignored on the next.
 *
 * This one decides nothing. It asks the probe worker what the URL is, hands
 * the answer to PlacementPolicy as an ordinary Projection, and lets
 * SourceReconciler own the write. Tombstones, capability gates, class
 * thresholds, the XOR rule and the single-writer property all apply to a
 * probed storefront because it travels the same path a pasted link does — not
 * because this class remembered to re-implement them.
 *
 * The only thing left that is genuinely its own is the shop_brands row: the
 * storefront's display identity (name, currency, logo, the discount code the
 * link carried), which is brand-plane data hanging off whatever connection the
 * reconciler produced.
 */
class StoreBrandSeeder
{
    /**
     * The store cap, from `partna.shop_brands_max` — the ONE definition
     * (#CFG-3). This used to be a private const 5 while ShopController and
     * ConnectStoreFromProductJob both said 10, so a 6th store was connected
     * but its brand row capped: the store half-existed and never rendered.
     * The reserved individual-products bucket never counts against it.
     */
    private static function maxBrands(): int
    {
        return (int) config('partna.shop_brands_max');
    }

    public function __construct(
        private readonly IriCanonicalizer $canonicalizer,
        private readonly LinkProbeWorker $worker,
        private readonly PlacementPolicy $policy,
        private readonly SourceReconciler $reconciler,
        private readonly LinkObserver $observer,
        private readonly BrandAssetPipeline $assets,
        private readonly ShopConnections $shop,
        private readonly ShopContentWriter $content,
        private readonly StorefrontFaviconScraper $favicons,
    ) {}

    /**
     * @return array{outcome: string, verdict: ?string, reason: ?string, connectionId: ?string, brandId: ?string}
     */
    /**
     * @param  bool  $confirmed  the user pressed Accept on this store's own
     *                           suggestion. Since 2026-09-03 PlacementPolicy
     *                           mints Place only for a confirmed request, so
     *                           without this the accept lane would re-offer the
     *                           suggestion it is answering and never build the
     *                           store. The paste lane says the same thing
     *                           through origin 'paste' and needs no flag.
     */
    public function seed(User $user, string $url, string $origin = 'paste', bool $suggestOnly = false, bool $confirmed = false): array
    {
        $iri = $this->canonicalizer->canonicalize($url);
        $probe = $this->worker->probe($iri, (string) $user->id);

        $context = RoutingContext::forUser($user, $origin, confirmed: $confirmed);
        $projection = $probe->toProjection();
        $placement = $this->policy->decide($projection, $context);

        // The storefront's own name, straight off the probe that already
        // fetched it (evidence['shop_name'], used for the brand row below).
        // Attached HERE because this is the one place holding both the probe
        // and the placement — PlacementPolicy is a decision function and has
        // no business knowing what a network call returned.
        $placement = $placement
            ->withLabel($probe->evidence['shop_name'] ?? null)
            ->withIcon(
                (is_string($probe->evidence['favicon'] ?? null) ? $probe->evidence['favicon'] : null)
                    ?? (is_string($probe->evidence['logo'] ?? null) ? $probe->evidence['logo'] : null)
            );

        // A pasted link's probe (owner ask, 2026-08-18) OFFERS the store rather
        // than installing it: the user typed a URL into the link box, and a
        // connected store (products, a shop page) is a bigger thing than they
        // asked for. The reconciler writes the intent as a proposed
        // suggestion — "Is this your Shopify?" in the inbox — and the accept
        // path builds the store through this same seeder.
        if ($suggestOnly && $placement->verdict === Verdict::Place) {
            $placement = (new Placement(Verdict::Choose, $placement->surfaceKey, $placement->identifier, 'needs_confirmation', 'offered from a pasted link'))
                // Carry the name AND icon across the downgrade. This rebuild
                // is positional, so a new Placement field is silently dropped
                // here unless it is named — which is exactly how the store's
                // name failed to reach the suggestion it is FOR.
                ->withLabel($placement->identifierLabel)
                ->withIcon($placement->identifierIcon);
        }

        // The probe leaves the same trace a paste does. "Why is this store on
        // my page?" and "why isn't it?" must both be answerable, and a probe
        // that wrote nothing to the observation log is a decision nobody can
        // reconstruct.
        //
        // Recorded BEFORE the miss return, not after (N-E, 2026-08-18). It used
        // to sit below it, so only matches were ever logged and the "why isn't
        // it?" half of the sentence above was never true. The 2026-08-18
        // Instagram wave probed three real unknown hosts and left
        // routing.link_observations empty, which read as X3's CHECK widening
        // having failed when nothing had attempted the write at all.
        // ProbeOutcome::toProjection() already models the miss (confidence 0,
        // margin 0, reason 'probe_miss'), and LinkObserver is best-effort by
        // design, so this cannot fail the seed.
        $this->observer->record($iri, $projection, $placement, $context);

        if (! $probe->isMatch()) {
            return [
                'outcome' => $probe->outcome,
                'verdict' => null,
                'reason' => $probe->reason,
                'connectionId' => null,
                'brandId' => null,
            ];
        }

        $applied = $this->reconciler->reconcile($placement, $context, $iri);

        if ($placement->verdict !== Verdict::Place || $applied['connection_id'] === null) {
            return [
                'outcome' => 'not_placed',
                'verdict' => $applied['verdict'],
                'reason' => $applied['block_reason'] ?? $placement->blockReason,
                'connectionId' => null,
                'brandId' => null,
            ];
        }

        // MAX_BRANDS parity (WAVE-2C): the legacy seeder refused a 6th store
        // after its own connection/tombstone/lock decisions but before the
        // brand row write — the observation log and the reconciler's intent
        // ledger stay honest (the connection was placed; only the brand row
        // is capped) exactly as they were for the legacy seeder's identical
        // ordering. A re-scan of an ALREADY-connected store never counts
        // against the cap.
        //
        // Counted ACROSS every one of the user's shop connections, not just
        // this one: unlike the legacy seeder's single shared connection
        // (resource_id='shop') holding up to 5 ShopBrand rows, the probe
        // pipeline mints one connection PER store (SourceReconciler::
        // applyIntent() keys on surface_key+resource_id=identifier — each
        // distinct storefront, even same-provider, gets its own row). A count
        // scoped to $applied['connection_id'] alone would only ever see the
        // ONE brand that connection can hold and never cap anything.
        // Re-home Task 10: both reads come off content.* and are USER-scoped,
        // which is what the paragraph above already says is wanted. The legacy
        // connection_id scope was equivalent only because the probe pipeline
        // mints one connection per store with resource_id = identifier =
        // external_ref — a 1:1 that content.* expresses directly.
        $stores = $this->shop->stores($user);
        $isNewBrand = ! $stores->has($probe->identifier);
        if ($isNewBrand) {
            // Strict comparison, not Collection::where() — that compares loosely.
            $storeCount = $stores->filter(fn (StoreRecord $s): bool => $s->isIndividual === false)->count();
            if ($storeCount >= self::maxBrands()) {
                Log::info('store_brand_seeder.cap', ['user_id' => (string) $user->id]);

                return [
                    'outcome' => 'capped',
                    'verdict' => $applied['verdict'],
                    'reason' => 'max_brands',
                    'connectionId' => null,
                    'brandId' => null,
                ];
            }
        }

        $store = $this->upsertBrand($user, $stores, $probe, $iri->canonical ?? $url);

        $connection = IntegrationConnection::query()->find($applied['connection_id']);

        // The connection the reconciler wrote carries only {url, source}; the
        // store's name lives on the brand row. Stamp it onto the payload so
        // the Platforms table / connect summary read "Beardbrand", not the
        // brand key or the URL (ConnectionDisplayName reads payload.name;
        // 2026-08-18, probed stores offered from a paste).
        if ($store->name !== null && $store->name !== '' && $connection !== null
            && (($connection->payload['name'] ?? null) !== $store->name)) {
            $connection->forceFill(['payload' => [...($connection->payload ?? []), 'name' => $store->name]])->saveQuietly();
        }

        // L-5 (owner run 2026-08-20): dedicated connects mint with
        // auto_sync_latest OFF (ShopConnections::anchor()); this lane mints
        // through SourceReconciler, which leaves the key absent — and absent
        // means ON, so a suggestion-accepted store silently auto-published its
        // newest product while a dedicated connect didn't. Same write, same
        // once-only guard as anchor(): never over an owner's later choice.
        if ($isNewBrand && $connection !== null) {
            $settings = (array) ($connection->display_settings ?? []);
            if (! array_key_exists(AutoSyncSetting::KEY, $settings)) {
                $connection->display_settings = $settings + [AutoSyncSetting::KEY => false];
                $connection->save();
            }
        }

        // §12: the store's logo becomes an owned, sanitised asset rather than a
        // hotlink to a CDN URL that can rot or be swapped under us.
        $this->assets->queueStoreLogo($user, $applied['connection_id'], $store);

        // L-4 (owner run 2026-08-20): this lane never reaches
        // ShopBrandConnectJob — its settle() is compare-and-set on
        // connect_status='pending' and seeder stores mint settled — so nothing
        // filled the catalogue until the 6-hourly ShopFetch. First connect
        // only: a re-scan of an already-connected store asked no fill question.
        if ($isNewBrand && $store->collectionId !== null) {
            ShopInitialFillJob::dispatch($store->collectionId);
        }

        return [
            'outcome' => 'placed',
            'verdict' => $applied['verdict'],
            'reason' => null,
            'connectionId' => $applied['connection_id'],
            'brandId' => $store->externalRef,
        ];
    }

    /**
     * Brand-plane identity for the storefront. Everything written here came
     * off the probe's own response — the evidence rides forward on the outcome
     * precisely so this never costs a second request.
     *
     * $stores is the caller's already-fetched family; the store as content.*
     * currently holds it (or null) comes off it rather than costing a second
     * query. This method MUST fold onto that existing record rather than
     * overwrite: upsertStore() has no partial-write semantics — every column is
     * unconditionally in its ON CONFLICT list — where the legacy
     * updateOrCreate() simply omitted absent keys. Without the fold, the
     * `$carried` rule below would have inverted itself and every re-scan would WIPE
     * a favicon or logo an earlier fetch had earned.
     */
    private function upsertBrand(User $user, Collection $stores, ProbeOutcome $probe, string $sourceUrl): StoreRecord
    {
        $existing = $stores->get($probe->identifier);

        // A store link shared with a discount or referral param keeps it, read
        // from the URL as pasted. `?:` not `??`: both columns default to ''
        // rather than NULL, so `??` would read an empty string as "already
        // set" and never apply the scanned code. Carried verbatim from
        // ShopBrandSeeder — a hand-typed code must survive every re-scan.
        $scanned = UrlParamExtractor::extract($sourceUrl);

        // Favicon/logo are written only when THIS probe carried them: not every
        // probe fetches them (Shopify's doesn't), and a re-seed must never wipe
        // a value an earlier fetch already earned. The legacy write expressed
        // that by OMITTING the keys; upsertStore() writes every column, so the
        // same rule is expressed as a coalesce onto what content.* already has.
        $favicon = $probe->evidence['favicon'] ?? $existing?->faviconUrl;

        // The Shopify and Big Cartel probes read a platform endpoint, never the
        // storefront HTML, so they carry no favicon and this column stayed NULL
        // for every store they placed — blanking the Platforms table icon as
        // well as the card. Fetched here, once, only when nothing already has
        // one: a probe's budget is the scarce thing and most probed URLs never
        // become stores, but a store being WRITTEN is rare and worth a request.
        if ($favicon === null) {
            $favicon = $this->favicons->fetch(is_string($probe->evidence['origin'] ?? null) ? $probe->evidence['origin'] : $sourceUrl);
        }
        $logo = $probe->evidence['logo'] ?? $existing?->logoUrl;

        // From the family the caller already fetched — re-reading it here was
        // a second identical round-trip per seed.
        $maxPosition = $stores->max('position');

        $store = new StoreRecord(
            externalRef: $probe->identifier,
            provider: explode('.', (string) $probe->surfaceKey)[0],
            name: $probe->evidence['shop_name'] ?? null,
            position: $existing !== null ? $existing->position : (($maxPosition === null ? -1 : $maxPosition) + 1),
            url: $probe->evidence['origin'] ?? null,
            // Squarespace's probe discovers the products-collection URL and
            // generic's the exact product page — refreshes must hit that,
            // not whatever the user happened to paste.
            sourceUrl: $probe->evidence['source_url'] ?? $sourceUrl,
            currency: $probe->evidence['currency'] ?? null,
            discountCode: $existing?->discountCode ?: ($scanned['discountCode'] ?? ''),
            referralQuery: $existing?->referralQuery ?: ($scanned['referralQuery'] ?? ''),
            isIndividual: false,
            faviconUrl: $favicon,
            logoUrl: $logo,
            // Processed marks are ProcessShopBrandLogoJob's to write; carry
            // them so a re-seed does not blank them, same rule as the raw pair.
            logoMarkUrl: $existing?->logoMarkUrl,
            logoMarkSvgUrl: $existing?->logoMarkSvgUrl,
        );

        $collectionId = $this->content->upsertStore($store, (string) $user->id);

        // Carried back so seed() can dispatch the collection-keyed fill job
        // without a second collectionIdFor() lookup.
        return $store->with(['collectionId' => $collectionId]);
    }
}
