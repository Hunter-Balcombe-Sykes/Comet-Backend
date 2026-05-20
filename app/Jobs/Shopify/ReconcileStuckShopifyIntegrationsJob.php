<?php

namespace App\Jobs\Shopify;

use App\Enums\BrandStatus;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * LIFE-1: Reconciles connected Shopify integrations against the Admin API to
 * auto-heal stuck-in-Connected state when the app/uninstalled webhook is lost.
 *
 * Shopify's app/uninstalled delivery is documented at-least-once-occasionally-zero.
 * Without this sweep, a brand whose webhook was dropped sits with a 401-ing access
 * token forever, every queued job for them fails, and the only recovery is an ops
 * ticket.
 *
 * For each integration where access_token IS NOT NULL AND disconnected_at IS NULL,
 * HEAD the Admin API and:
 *   401                       → revoked. Auto-heal: null token, write disconnected_at.
 *   shop_domain_mismatch      → revoked or transferred. Auto-heal as above.
 *   5xx / network exception   → transient outage. Leave alone (don't punish merchants
 *                               for Shopify hiccups; next sweep will retry).
 *   2xx + matching domain     → healthy. Leave alone.
 *
 * Mirrors the webhook controller's disconnect path so a manual reconcile-driven
 * heal is indistinguishable downstream from a webhook-driven uninstall, except
 * for the disconnected_reason tag.
 */
class ReconcileStuckShopifyIntegrationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $backoff = 0;

    public int $timeout = 600;

    private const BATCH_LIMIT = 200;

    public function __construct()
    {
        $this->onQueue('integrations');
    }

    public function handle(): void
    {
        // Post-DATA-2: disconnected_at is a real column, so the filter pushes
        // down to the planner and the partial index on disconnected_at lets
        // the candidate set skip already-disconnected rows entirely.
        $candidates = ProfessionalIntegration::query()
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->whereNotNull('access_token')
            ->whereNull('disconnected_at')
            ->orderBy('updated_at')
            ->limit(self::BATCH_LIMIT)
            ->get();

        if ($candidates->isEmpty()) {
            Log::info('shopify.reconcile.nothing_to_reconcile');

            return;
        }

        $healed = 0;
        $healthy = 0;
        $transient = 0;
        $inspected = 0;
        $deadlineReached = false;

        // Each candidate costs one Admin API round-trip (up to 10s). 200 × 10s
        // far exceeds the 600s job timeout, so without a wall-clock guard a slow
        // Shopify could let the worker get SIGKILL'd mid-loop. Stop at 80% of the
        // timeout, leaving headroom for the trailing log writes; un-inspected
        // rows are simply picked up by the next daily run.
        $start = microtime(true);
        $softDeadlineSeconds = $this->timeout * 0.8;

        foreach ($candidates as $integration) {
            if (microtime(true) - $start > $softDeadlineSeconds) {
                $deadlineReached = true;
                break;
            }

            $inspected++;
            $check = $this->validateAccessToken($integration);

            if ($check['valid']) {
                if ($check['reason'] === 'transient_outage') {
                    $transient++;
                } else {
                    $healthy++;
                }

                continue;
            }

            $this->markDisconnected($integration, (string) $check['reason']);
            $healed++;
        }

        $unprocessed = $candidates->count() - $inspected;

        if ($deadlineReached) {
            Log::warning('shopify.reconcile.deadline_reached', [
                'inspected' => $inspected,
                'unprocessed' => $unprocessed,
                'soft_deadline_seconds' => (int) $softDeadlineSeconds,
            ]);
        }

        Log::info('shopify.reconcile.completed', [
            'inspected' => $inspected,
            'unprocessed' => $unprocessed,
            'healed' => $healed,
            'healthy' => $healthy,
            'transient' => $transient,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        report($e);

        Log::error('shopify.reconcile.failed', [
            'error_class' => class_basename($e),
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Mirrors EmbeddedSetupController::validateShopifyAccessToken — see that method
     * for the full contract. Classification:
     *   401                       → revoked (heal).
     *   5xx / network exception   → transient outage (leave alone).
     *   other non-2xx (403, 404)  → unexpected_status_<code>, treated as a definitive
     *                               client error and healed. 403 = shop suspended;
     *                               404 = shop deleted / URL changed. The controller
     *                               makes the same distinction; previously the job
     *                               lumped these in with 5xx and never auto-healed.
     *   2xx + matching domain     → healthy.
     *   2xx + mismatched domain   → shop_domain_mismatch (heal).
     *
     * @return array{valid: bool, reason: ?string}
     */
    private function validateAccessToken(ProfessionalIntegration $integration): array
    {
        $shopDomain = strtolower(trim((string) $integration->shopify_shop_domain, ' /'));
        $accessToken = (string) $integration->access_token;

        if ($accessToken === '' || ! preg_match('/^[a-z0-9\-]+\.myshopify\.com$/', $shopDomain)) {
            return ['valid' => true, 'reason' => 'transient_outage'];
        }

        $apiVersion = (string) config('services.shopify.api_version', '2026-04');
        $url = "https://{$shopDomain}/admin/api/{$apiVersion}/shop.json";

        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
                'Accept' => 'application/json',
            ])->timeout(10)->get($url);
        } catch (\Throwable $e) {
            Log::warning('shopify.reconcile.network_error', [
                'shop_domain' => $shopDomain,
                'error_class' => class_basename($e),
            ]);

            return ['valid' => true, 'reason' => 'transient_outage'];
        }

        if ($response->status() === 401) {
            return ['valid' => false, 'reason' => 'invalid_token'];
        }

        if ($response->status() >= 500) {
            return ['valid' => true, 'reason' => 'transient_outage'];
        }

        if (! $response->successful()) {
            return ['valid' => false, 'reason' => 'unexpected_status_'.$response->status()];
        }

        $body = $response->json();
        $responseDomain = strtolower(trim((string) ($body['shop']['myshopify_domain'] ?? ''), ' /'));

        if ($responseDomain !== '' && $responseDomain !== $shopDomain) {
            return ['valid' => false, 'reason' => 'shop_domain_mismatch'];
        }

        return ['valid' => true, 'reason' => null];
    }

    private function markDisconnected(ProfessionalIntegration $integration, string $detectionSignal): void
    {
        // Label/audit-trail JSONB: reason tag + detection signal. The state
        // itself — disconnected_at + webhook_registration_state='uninstalled'
        // — lives on dedicated columns (DATA-2).
        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $metadata['disconnected_reason'] = 'reconcile_detected_revocation';
        $metadata['reconcile_detection_signal'] = $detectionSignal;

        $integration->update([
            'access_token' => null,
            'refresh_token' => null,
            'provider_metadata' => $metadata,
            'disconnected_at' => now(),
            'webhook_registration_state' => 'uninstalled',
        ]);

        // Mirrors the webhook controller's disconnect path. Wizard progress is
        // preserved so the brand can resume on reinstall.
        BrandProfile::where('professional_id', $integration->professional_id)
            ->update([
                'brand_status' => BrandStatus::Disconnected->value,
                'setup_complete' => false,
            ]);

        Log::warning('shopify.reconcile.healed', [
            'professional_id' => (string) $integration->professional_id,
            'shop_domain' => $integration->shopify_shop_domain,
            'detection_signal' => $detectionSignal,
        ]);
    }
}
