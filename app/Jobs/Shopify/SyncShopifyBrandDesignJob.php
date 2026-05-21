<?php

namespace App\Jobs\Shopify;

use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use App\Services\Cache\SiteCacheService;
use App\Services\Media\BrandDesignMediaService;
use App\Services\Shopify\BrandDesignImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Unified brand-design importer. Replaces the old pair of
// SyncShopifyThemeTokensJob + SyncShopifyBrandLogoJob.
//
// What it does, per invocation:
//   1. Pulls the brand-design shape from Shopify via BrandDesignImporter
//      (Brand API for colours/logos/slogan; active theme's settings_data.json
//      for radius/border/spacing enums).
//   2. Downloads logo files from the Shopify CDN into our own media disk so
//      the URLs we persist are stable when Shopify's CDN tokens rotate.
//   3. Merges the new shape into site.settings.design using overwrite-if-present,
//      leave-if-absent semantics — a missing Shopify value never nulls a
//      user's existing edit.
//   4. Writes a `sidest.brand_design` shop metafield so other Sidest surfaces
//      (and debugging tools) can read the same shape from Shopify directly.
//   5. Busts the brand-design cache Hydrogen reads from.
class SyncShopifyBrandDesignJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    // Worst-case execution = tries * timeout = 360s. Hold the unique lock
    // longer than that so a stuck-then-retried run can never race another
    // copy of itself against the same site.settings.design write.
    public int $uniqueFor = 600;

    private const METAFIELDS_SET_MUTATION = <<<'GRAPHQL'
    mutation metafieldsSet($metafields: [MetafieldsSetInput!]!) {
      metafieldsSet(metafields: $metafields) {
        metafields { id key }
        userErrors { field message code }
      }
    }
    GRAPHQL;

    public function __construct(
        public string $integrationId,
    ) {
        $this->onQueue('integrations');
    }

    public function uniqueId(): string
    {
        return $this->integrationId;
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(BrandDesignImporter $importer, BrandDesignMediaService $brandDesign): void
    {
        $integration = ProfessionalIntegration::query()
            ->where('id', $this->integrationId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            return;
        }

        $site = Site::where('professional_id', $integration->professional_id)->first();

        if (! $site) {
            Log::warning('Brand design sync skipped — no site for professional.', [
                'integration_id' => $this->integrationId,
                'professional_id' => (string) $integration->professional_id,
            ]);

            return;
        }

        try {
            $imported = $importer->import($integration);
        } catch (\Throwable $e) {
            Log::warning('Brand design import failed.', [
                'integration_id' => $this->integrationId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        // Download logos and persist via BrandDesignMediaService — the same
        // service the dashboard upload path uses. Failures are non-fatal:
        // a missing logo just means the existing site_media row stays intact.
        $this->persistLogoFromShopify(
            $brandDesign,
            $site,
            (string) $integration->professional_id,
            'full',
            is_string($imported['logo']['full_url'] ?? null) ? $imported['logo']['full_url'] : null,
        );
        $this->persistLogoFromShopify(
            $brandDesign,
            $site,
            (string) $integration->professional_id,
            'square',
            is_string($imported['logo']['square_url'] ?? null) ? $imported['logo']['square_url'] : null,
        );

        // Merge non-media design tokens into site.settings.design with
        // leave-if-absent semantics. Logo is intentionally NOT in this merge —
        // it lives in site_media now.
        $settings = is_array($site->settings) ? $site->settings : [];
        $design = is_array($settings['design'] ?? null) ? $settings['design'] : [];
        $existingColors = is_array($design['colors'] ?? null) ? $design['colors'] : [];

        // Only accent stays brand-pickable. Background/text/border are derived
        // from theme_mode (light|dark) per Sidest theme.
        $design['colors'] = [
            'accent' => $imported['colors']['accent'] ?? ($existingColors['accent'] ?? null),
        ];
        $design['theme_mode'] = $imported['theme_mode'] ?? ($design['theme_mode'] ?? null);
        $design['corner_radius'] = $imported['corner_radius'] ?? ($design['corner_radius'] ?? null);
        $design['border_thickness'] = $imported['border_thickness'] ?? ($design['border_thickness'] ?? null);
        $design['section_spacing'] = $imported['section_spacing'] ?? ($design['section_spacing'] ?? null);
        $design['slogan'] = $imported['slogan'] ?? ($design['slogan'] ?? null);
        $design['short_description'] = $imported['short_description'] ?? ($design['short_description'] ?? null);

        // Strip the legacy logo subtree if any older row still has it. The
        // backfill migration cleans existing rows; this keeps us idempotent
        // for any row that gets re-synced before the migration runs.
        unset($design['logo']);

        $settings['design'] = $design;
        $site->settings = $settings;
        $site->save();

        // Dual-write slogan + short_description to BrandProfile per plan §6.1a /
        // §8.5. BrandProfile is the new canonical home for brand-identity
        // fields (queryable, exportable); site.settings.design.slogan stays for
        // existing Hydrogen / theme rendering consumers. Both kept in sync each
        // run. Overwrite semantics for design — Shopify is source of truth.
        $brandProfile = \App\Models\Core\Professional\BrandProfile::query()
            ->where('professional_id', $integration->professional_id)
            ->first();
        if ($brandProfile !== null) {
            $brandProfileDirty = false;

            if (is_string($imported['slogan'] ?? null) && $imported['slogan'] !== '') {
                $brandProfile->slogan = $imported['slogan'];
                $brandProfileDirty = true;
            }

            if (is_string($imported['short_description'] ?? null) && $imported['short_description'] !== '') {
                $brandProfile->short_description = $imported['short_description'];
                $brandProfileDirty = true;
            }

            if ($brandProfileDirty) {
                $brandProfile->save();
            }
        }

        // Mirror the shape to a shop metafield. Best-effort — a failure here
        // doesn't invalidate the DB write.
        if (! empty($imported['shop_gid'])) {
            try {
                $this->writeBrandDesignMetafield($integration, (string) $imported['shop_gid'], [
                    'colors' => $design['colors'],
                    'theme_mode' => $design['theme_mode'],
                    'corner_radius' => $design['corner_radius'],
                    'border_thickness' => $design['border_thickness'],
                    'section_spacing' => $design['section_spacing'],
                    'slogan' => $design['slogan'],
                    'short_description' => $design['short_description'],
                    'synced_at' => now()->toIso8601String(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Failed to write sidest.brand_design metafield.', [
                    'integration_id' => $this->integrationId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Bust the Hydrogen brand-design cache. The logo uploads above already
        // busted via BrandDesignMediaService::invalidateSiteCache, but the
        // site.settings.design writes (colors / enums / slogan) don't route
        // through that service, so this catches them.
        app(SiteCacheService::class)->forgetBrandDesign((string) $site->id);

        $integration->mergeProviderMetadata(['brand_design_state' => 'synced']);

        Log::info('Brand design synced.', [
            'integration_id' => $this->integrationId,
            'professional_id' => (string) $integration->professional_id,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Shopify brand design sync permanently failed.', [
            'integration_id' => $this->integrationId,
            'error' => $e->getMessage(),
        ]);

        $integration = ProfessionalIntegration::find($this->integrationId);
        $integration?->mergeProviderMetadata(['brand_design_state' => 'failed']);
    }

    /**
     * Download the logo bytes from Shopify CDN and hand them to
     * BrandDesignMediaService for persistence + variant generation. A null
     * sourceUrl or any HTTP failure is silently ignored — the existing
     * site_media row (if any) stays intact.
     */
    private function persistLogoFromShopify(
        BrandDesignMediaService $brandDesign,
        Site $site,
        string $professionalId,
        string $variant,
        ?string $sourceUrl,
    ): void {
        if (! is_string($sourceUrl) || $sourceUrl === '' || ! str_starts_with($sourceUrl, 'https://')) {
            return;
        }

        try {
            $response = Http::timeout(20)
                ->withOptions(['allow_redirects' => ['max' => 3, 'protocols' => ['https']]])
                ->get($sourceUrl);

            if (! $response->ok()) {
                return;
            }

            $bytes = $response->body();
            if ($bytes === '') {
                return;
            }

            $mime = (string) $response->header('Content-Type') ?: 'image/png';

            $brandDesign->upsertLogoFromBytes($site, $professionalId, $bytes, $mime, $variant);
        } catch (\Throwable $e) {
            Log::warning('Failed to persist Shopify-mirrored brand logo.', [
                'integration_id' => $this->integrationId,
                'variant' => $variant,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function writeBrandDesignMetafield(ProfessionalIntegration $integration, string $shopGid, array $value): void
    {
        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $shopDomain = trim((string) Arr::get($metadata, 'shop_domain', ''));
        $accessToken = trim((string) $integration->access_token);
        $apiVersion = trim((string) config('services.shopify.api_version', '2025-01'));

        if ($shopDomain === '' || $accessToken === '') {
            return;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode brand design for metafield.');
        }

        $response = app(\App\Services\Shopify\Client\ShopifyAdminClient::class)->graphql(
            \App\Services\Shopify\ShopDomain::fromUntrusted($shopDomain),
            $accessToken,
            $apiVersion,
            self::METAFIELDS_SET_MUTATION,
            [
                'metafields' => [[
                    'namespace' => 'partna',
                    'key' => 'brand_design',
                    'ownerId' => $shopGid,
                    'type' => 'json',
                    'value' => $encoded,
                ]],
            ],
        );

        $userErrors = $response->json('data.metafieldsSet.userErrors', []);
        if (is_array($userErrors) && $userErrors !== []) {
            $message = (string) Arr::get($userErrors, '0.message', 'Shopify metafieldsSet returned errors.');
            throw new \RuntimeException($message);
        }
    }
}
