<?php

namespace App\Services\Brand;

use App\Models\Core\Site\ShopBrand;
use App\Models\Core\User\User;
use App\Routing\IriCanonicalizer;
use App\Routing\LinkObserver;
use App\Routing\PlacementPolicy;
use App\Routing\Probes\LinkProbeWorker;
use App\Routing\Probes\ProbeOutcome;
use App\Routing\RoutingContext;
use App\Routing\SourceReconciler;
use App\Routing\Verdict;
use App\Services\Platforms\UrlParamExtractor;
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
     * Mirrors ShopController::MAX_BRANDS / the legacy ShopBrandSeeder's own
     * copy — keep in lockstep. The reserved individual-products bucket
     * (is_individual = true) never counts against this.
     */
    private const MAX_BRANDS = 5;

    public function __construct(
        private readonly IriCanonicalizer $canonicalizer,
        private readonly LinkProbeWorker $worker,
        private readonly PlacementPolicy $policy,
        private readonly SourceReconciler $reconciler,
        private readonly LinkObserver $observer,
        private readonly BrandAssetPipeline $assets,
    ) {}

    /**
     * @return array{outcome: string, verdict: ?string, reason: ?string, connectionId: ?string, brandId: ?string}
     */
    public function seed(User $user, string $url, string $origin = 'paste'): array
    {
        $iri = $this->canonicalizer->canonicalize($url);
        $probe = $this->worker->probe($iri, (string) $user->id);

        if (! $probe->isMatch()) {
            return [
                'outcome' => $probe->outcome,
                'verdict' => null,
                'reason' => $probe->reason,
                'connectionId' => null,
                'brandId' => null,
            ];
        }

        $context = RoutingContext::forUser($user, $origin);
        $projection = $probe->toProjection();
        $placement = $this->policy->decide($projection, $context);

        // The probe leaves the same trace a paste does. "Why is this store on
        // my page?" and "why isn't it?" must both be answerable, and a probe
        // that wrote nothing to the observation log is a decision nobody can
        // reconstruct.
        $this->observer->record($iri, $projection, $placement, $context);

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
        $isNewBrand = ! ShopBrand::where('connection_id', $applied['connection_id'])
            ->where('brand_id', $probe->identifier)
            ->exists();
        if ($isNewBrand) {
            $storeCount = ShopBrand::where('is_individual', false)
                ->whereHas('connection', fn ($q) => $q->where('user_id', $user->id))
                ->count();
            if ($storeCount >= self::MAX_BRANDS) {
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

        $brand = $this->upsertBrand($applied['connection_id'], $probe, $iri->canonical ?? $url);

        // §12: the store's logo becomes an owned, sanitised asset rather than a
        // hotlink to a CDN URL that can rot or be swapped under us.
        $this->assets->queueStoreLogo($user, $applied['connection_id'], $brand);

        return [
            'outcome' => 'placed',
            'verdict' => $applied['verdict'],
            'reason' => null,
            'connectionId' => $applied['connection_id'],
            'brandId' => (string) $brand->brand_id,
        ];
    }

    /**
     * Brand-plane identity for the storefront. Everything written here came
     * off the probe's own response — the evidence rides forward on the outcome
     * precisely so this never costs a second request.
     */
    private function upsertBrand(string $connectionId, ProbeOutcome $probe, string $sourceUrl): ShopBrand
    {
        $existing = ShopBrand::where('connection_id', $connectionId)
            ->where('brand_id', $probe->identifier)
            ->first();

        $maxPosition = ShopBrand::where('connection_id', $connectionId)->max('position');

        // A store link shared with a discount or referral param keeps it, read
        // from the URL as pasted. `?:` not `??`: both columns default to ''
        // rather than NULL, so `??` would read an empty string as "already
        // set" and never apply the scanned code. Carried verbatim from
        // ShopBrandSeeder — a hand-typed code must survive every re-scan.
        $scanned = UrlParamExtractor::extract($sourceUrl);

        // Favicon/logo are written only when THIS probe carried them: not every
        // probe fetches them (Shopify's doesn't), and a re-seed must never wipe
        // a value an earlier fetch already earned.
        $carried = array_filter([
            'favicon' => $probe->evidence['favicon'] ?? null,
            'logo' => $probe->evidence['logo'] ?? null,
        ], fn ($v) => $v !== null);

        return ShopBrand::updateOrCreate(
            ['connection_id' => $connectionId, 'brand_id' => $probe->identifier],
            [
                'provider' => explode('.', (string) $probe->surfaceKey)[0],
                'url' => $probe->evidence['origin'] ?? null,
                // Squarespace's probe discovers the products-collection URL and
                // generic's the exact product page — refreshes must hit that,
                // not whatever the user happened to paste.
                'source_url' => $probe->evidence['source_url'] ?? $sourceUrl,
                'name' => $probe->evidence['shop_name'] ?? null,
                'currency' => $probe->evidence['currency'] ?? null,
                'discount_code' => $existing?->discount_code ?: ($scanned['discountCode'] ?? ''),
                'referral_query' => $existing?->referral_query ?: ($scanned['referralQuery'] ?? ''),
                'is_individual' => false,
                'position' => $existing !== null ? $existing->position : (($maxPosition === null ? -1 : $maxPosition) + 1),
                ...$carried,
            ],
        );
    }
}
