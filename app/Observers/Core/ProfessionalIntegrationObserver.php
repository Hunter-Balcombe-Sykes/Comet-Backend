<?php

namespace App\Observers\Core;

use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Observers\Concerns\LogsWithRequestContext;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Notifications\NotificationPublisher;
use App\Services\Professional\SectionVisibilityService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

// V2: Publishes integration connect/disconnect notifications and re-evaluates booking section visibility.
//
// Master Pattern 15: also busts the Hydrogen brand-config cache. The cache key
// is keyed by shop_domain, which lives on this row — we use the in-memory model
// rather than re-resolving so the bust still works on delete (where the row is
// gone from the DB).
class ProfessionalIntegrationObserver
{
    use LogsWithRequestContext;

    public bool $afterCommit = true;

    public function __construct(
        private readonly NotificationPublisher $publisher,
        private readonly SectionVisibilityService $visibilityService,
    ) {}

    public function created(ProfessionalIntegration $integration): void
    {
        try {
            $professionalId = trim((string) ($integration->professional_id ?? ''));
            if ($professionalId === '') {
                return;
            }

            $provider = ucfirst(strtolower(trim((string) ($integration->provider ?? 'Integration'))));

            $this->publisher->publish(
                professionalId: $professionalId,
                frontendType: 'Success',
                category: 'integrations',
                title: "{$provider} connected",
                body: "Your {$provider} integration has been connected successfully.",
                dedupeKey: "integration.connected.{$integration->id}",
                ctaUrl: '/account/integrations',
                retentionConfigKey: 'integration',
            );
        } catch (\Throwable $e) {
            Log::warning('ProfessionalIntegration created notification failed', $this->logContext(__METHOD__, [
                'integration_id' => $integration->id,
                'professional_id' => $integration->professional_id,
                'message' => $e->getMessage(),
            ]));
        }

        $this->reevaluateBooking($integration);
        $this->bustHydrogenBrandConfig($integration);
    }

    public function updated(ProfessionalIntegration $integration): void
    {
        // Token rotations and provider_metadata edits (collection handles,
        // custom_photos_enabled, etc.) all flow through the brand-config payload.
        // No publisher fires here — only the cache bust.
        $this->bustHydrogenBrandConfig($integration);

        // If the shop_domain itself changed, the OLD cache key still points to
        // the previous payload. Resolve the original from the dirty attributes
        // and bust both keys.
        if ($integration->wasChanged('shopify_shop_domain')) {
            $original = $integration->getOriginal('shopify_shop_domain');
            if (is_string($original) && trim($original) !== '') {
                $oldKey = CacheKeyGenerator::hydrogenBrandConfig(strtolower(trim($original)));
                try {
                    Cache::deleteMultiple([$oldKey, $oldKey.':stale']);
                } catch (\Throwable $e) {
                    Log::warning('ProfessionalIntegration: old shop_domain Hydrogen bust failed', $this->logContext(__METHOD__, [
                        'integration_id' => $integration->id,
                        'old_shop_domain' => $original,
                        'message' => $e->getMessage(),
                    ]));
                }
            }
        }

        // provider_metadata holds `custom_photos_enabled` and the photo position,
        // both of which the per-affiliate products cache reads via
        // CustomPhotoPermissionService. Without this, flipping the flag would
        // leave every linked affiliate's product cache stale for up to 60s
        // primary + 600s stale window.
        if ($integration->wasChanged('provider_metadata')) {
            $this->bustLinkedAffiliateProductCaches($integration);
        }
    }

    public function deleted(ProfessionalIntegration $integration): void
    {
        try {
            $professionalId = trim((string) ($integration->professional_id ?? ''));
            if ($professionalId === '') {
                return;
            }

            $provider = ucfirst(strtolower(trim((string) ($integration->provider ?? 'Integration'))));

            $this->publisher->publish(
                professionalId: $professionalId,
                frontendType: 'Warning',
                category: 'integrations',
                title: "{$provider} disconnected",
                body: "Your {$provider} integration has been disconnected.",
                dedupeKey: "integration.disconnected.{$integration->id}",
                ctaUrl: '/account/integrations',
                retentionConfigKey: 'integration',
            );
        } catch (\Throwable $e) {
            Log::warning('ProfessionalIntegration deleted notification failed', $this->logContext(__METHOD__, [
                'integration_id' => $integration->id,
                'professional_id' => $integration->professional_id,
                'message' => $e->getMessage(),
            ]));
        }

        $this->reevaluateBooking($integration);
        $this->bustHydrogenBrandConfig($integration);
    }

    /**
     * Bust the Hydrogen brand-config cache using the shop_domain on the model
     * directly. Works on delete (where the row is gone from the DB) and on
     * non-shopify providers (which simply have no shopify_shop_domain to clear).
     */
    private function bustHydrogenBrandConfig(ProfessionalIntegration $integration): void
    {
        $provider = strtolower(trim((string) ($integration->provider ?? '')));
        if ($provider !== strtolower(ProfessionalIntegration::PROVIDER_SHOPIFY)) {
            return;
        }

        $shopDomain = is_string($integration->shopify_shop_domain ?? null)
            ? strtolower(trim((string) $integration->shopify_shop_domain))
            : '';

        if ($shopDomain === '') {
            return;
        }

        try {
            $key = CacheKeyGenerator::hydrogenBrandConfig($shopDomain);
            Cache::deleteMultiple([$key, $key.':stale']);
        } catch (\Throwable $e) {
            Log::warning('ProfessionalIntegration: Hydrogen brand-config bust failed', $this->logContext(__METHOD__, [
                'integration_id' => $integration->id,
                'professional_id' => $integration->professional_id,
                'shop_domain' => $shopDomain,
                'message' => $e->getMessage(),
            ]));
        }
    }

    /**
     * Bust the Hydrogen affiliate-products cache for every affiliate linked
     * to this brand. Fires when the brand toggles flags in provider_metadata
     * that the per-affiliate products payload depends on
     * (custom_photos_enabled, custom_photo_position).
     *
     * Synchronous bust — typical brands have <100 affiliates, and the bus is
     * a single Cache::deleteMultiple regardless of count. If brand fan-out
     * grows, dispatch the bust as a queued job mirroring
     * InvalidateBrandAffiliatesCacheJob.
     */
    private function bustLinkedAffiliateProductCaches(ProfessionalIntegration $integration): void
    {
        $brandId = trim((string) ($integration->professional_id ?? ''));
        if ($brandId === '') {
            return;
        }

        try {
            $affiliateIds = BrandPartnerLink::query()
                ->where('brand_professional_id', $brandId)
                ->pluck('affiliate_professional_id')
                ->all();

            if ($affiliateIds === []) {
                return;
            }

            $keys = [];
            foreach ($affiliateIds as $affiliateId) {
                $primary = CacheKeyGenerator::hydrogenAffiliateProducts((string) $affiliateId);
                $keys[] = $primary;
                $keys[] = $primary.':stale';
            }

            Cache::deleteMultiple(array_values(array_unique($keys)));
        } catch (\Throwable $e) {
            Log::warning('ProfessionalIntegration: linked-affiliate products bust failed', $this->logContext(__METHOD__, [
                'integration_id' => $integration->id,
                'brand_professional_id' => $brandId,
                'message' => $e->getMessage(),
            ]));
        }
    }

    private function reevaluateBooking(ProfessionalIntegration $integration): void
    {
        try {
            $pro = Professional::query()->with('site')->find($integration->professional_id);
            $site = $pro?->site;
            if (! $pro || ! $site) {
                return;
            }

            $this->visibilityService->reevaluateEnabled(
                (string) $integration->professional_id,
                (string) $site->id,
                'booking'
            );
        } catch (\Throwable $e) {
            Log::warning('Booking section visibility reevaluation failed on integration change', $this->logContext(__METHOD__, [
                'integration_id' => $integration->id,
                'professional_id' => $integration->professional_id,
                'message' => $e->getMessage(),
            ]));
        }
    }
}
