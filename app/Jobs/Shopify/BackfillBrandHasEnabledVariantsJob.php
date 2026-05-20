<?php

namespace App\Jobs\Shopify;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Store\BrandCatalogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Computes and writes `partna.has_enabled_variants` on every product in a
 * brand's Shopify catalog at the end of the OAuth install chain.
 *
 * Why we need this at install time:
 *   The derived flag is only written when brand-side variant enable/disable
 *   actions run (setVariantEnabledStates). On a fresh install no brand
 *   actions have happened yet, so every product starts with an empty value —
 *   which in turn means the Active Products smart collection (which requires
 *   `has_enabled_variants = true`) sees no products as "active" even if
 *   `partna.active` is true. Running this once post-install seeds the flag
 *   from the current variant state so the collection resolves correctly
 *   from the get-go.
 *
 * Logic (matches BackfillHasEnabledVariantsCommand, scoped to one brand):
 *   hasEnabled = true when the product has no variants at all, OR when at
 *   least one variant has `partna.enabled != false`. Missing metafield
 *   defaults to enabled.
 *
 * Scaling (F10):
 *   Writes are batched through metafieldsSet — up to 25 products per GraphQL
 *   round-trip — so a 500-product catalog costs ~20 calls instead of ~500.
 *   A resume cursor in `provider_metadata.has_enabled_variants_backfill_cursor`
 *   records every product checkpointed by a successful batch; if an attempt
 *   times out mid-catalog the next attempt skips already-written products and
 *   resumes rather than replaying from zero. Idempotent: skips products whose
 *   stored value already matches.
 *
 * Dispatch order:
 *   ShopifyIntegrationController → CreateShopifyMetafieldsJob →
 *     CreateShopifySalesChannelJob →
 *       CreateShopifyCollectionsJob →
 *         CreateShopifyAffiliateDiscountJob →
 *           BackfillBrandHasEnabledVariantsJob (this, last in the chain).
 */
class BackfillBrandHasEnabledVariantsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    // Generous ceiling for the catalog fetch (paginated, can be slow for large
    // brands). Writes themselves are cheap now they are batched 25-per-call;
    // the resume cursor means a timeout costs at most one batch of progress.
    public int $timeout = 300;

    // Must outlast the worst-case retry lifecycle (5 tries × 300s + backoff) so
    // a worker that is SIGKILLed without releasing the lock cannot let a
    // duplicate slip through before the unique lock's failsafe TTL expires.
    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return $this->integrationId;
    }

    public function backoff(): array
    {
        return [30, 90, 180, 300];
    }

    public function __construct(
        public string $integrationId
    ) {
        $this->onQueue('integrations');
    }

    public function handle(BrandCatalogService $catalogService): void
    {
        $integration = ProfessionalIntegration::query()
            ->where('id', $this->integrationId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration || empty($integration->access_token)) {
            return;
        }

        $brand = Professional::find($integration->professional_id);
        if (! $brand) {
            return;
        }

        try {
            $catalog = $catalogService->fetchBrandCatalog($brand);
        } catch (\Throwable $e) {
            Log::error('has_enabled_variants backfill: catalog fetch failed', [
                'integration_id' => $this->integrationId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        // Resume support: products written by a previous (timed-out) attempt
        // are recorded here so this run skips them instead of replaying.
        // This job is the sole writer of the cursor key, and ShouldBeUnique
        // keeps a single instance running per integration — so accumulating
        // the cursor from the in-process variable across batches is safe.
        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $cursor = $metadata['has_enabled_variants_backfill_cursor'] ?? [];
        $cursor = is_array($cursor) ? array_values(array_filter($cursor, 'is_string')) : [];
        $done = array_flip($cursor);

        // Build the write set (gid => desired flag), excluding products that
        // already hold the correct value or were checkpointed by a prior run.
        $pending = [];
        $skipped = 0;

        foreach ($catalog as $product) {
            $gid = $product['gid'] ?? '';
            if ($gid === '' || isset($done[$gid])) {
                continue;
            }

            $variants = $product['variants'] ?? [];
            // No variants → single-SKU product → trivially "has enabled
            // variants". Otherwise: true if at least one not-explicitly-disabled.
            $hasEnabled = empty($variants) || collect($variants)->contains(
                fn (array $v) => ($v['enabled'] ?? null) !== false
            );

            // Skip when the stored value already matches — avoids a
            // metafieldsSet write for products setVariantEnabledStates seeded.
            $existing = $product['metafields']['has_enabled_variants'] ?? null;
            if ($existing === $hasEnabled) {
                $skipped++;

                continue;
            }

            $pending[$gid] = $hasEnabled;
        }

        $writes = 0;
        $failures = 0;

        // Shopify caps metafieldsSet at 25 inputs per call. Checkpoint the
        // cursor after every successful batch so a timeout loses at most one
        // batch of work. A thrown transport error propagates to trigger a retry
        // that resumes from the cursor; userErrors are recorded as a partial
        // failure without throwing (they are not transient).
        foreach (array_chunk($pending, 25, true) as $batch) {
            $result = $catalogService->writeHasEnabledVariantsBatch($integration, $batch);

            if ($result['success']) {
                $writes += count($batch);
                $cursor = array_merge($cursor, array_keys($batch));
                $integration->mergeProviderMetadata([
                    'has_enabled_variants_backfill_cursor' => $cursor,
                ]);
            } else {
                $failures += count($batch);
                Log::warning('has_enabled_variants backfill batch failed', [
                    'integration_id' => $this->integrationId,
                    'batch_size' => count($batch),
                    'userErrors' => $result['userErrors'],
                ]);
            }
        }

        // On full success the cursor has served its purpose — clear it so a
        // later re-run starts clean. On partial failure keep it so a manual
        // retry resumes from where this run left off.
        $state = $failures > 0 ? 'partial' : 'complete';
        $metaUpdate = [
            'has_enabled_variants_backfill_state' => $state,
            'has_enabled_variants_backfill_at' => now()->toIso8601String(),
        ];
        if ($state === 'complete') {
            $metaUpdate['has_enabled_variants_backfill_cursor'] = [];
        }
        $integration->mergeProviderMetadata($metaUpdate);

        Log::info('has_enabled_variants backfill complete', [
            'integration_id' => $this->integrationId,
            'writes' => $writes,
            'skipped' => $skipped,
            'failures' => $failures,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $integration = ProfessionalIntegration::find($this->integrationId);
        // Keep the resume cursor intact — a manual re-dispatch picks up from
        // the last checkpointed batch rather than rewriting the whole catalog.
        $integration?->mergeProviderMetadata([
            'has_enabled_variants_backfill_state' => 'failed',
        ]);
    }
}
