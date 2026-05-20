<?php

namespace App\Jobs\Notifications;

use App\Enums\BrandStatus;
use App\Models\Core\Professional\Professional;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// V2: Delivers a brand-status change notification to a single affiliate.
// Dispatched by FanOutBrandStatusNotificationJob — one job per recipient
// so failures isolate and retry independently.
class SendBrandStatusNotificationJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // Surface deterministic failures fast — fail after 2 consecutive throws
    // instead of burning the full backoff window before Horizon alerts.
    public int $maxExceptions = 2;

    public int $backoff = 30;

    public int $timeout = 30;

    public function __construct(
        public readonly string $affiliateProfessionalId,
        public readonly string $brandProfessionalId,
        public readonly string $brandName,
        public readonly string $brandStatus, // BrandStatus::Onboarding | ReadyForAffiliates | SystemsDown ->value
        public readonly string $yearWeek,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(NotificationPublisher $publisher): void
    {
        // Feature gate: defence-in-depth against ex-partner-leak (§28.16).
        // FanOutBrandStatusNotificationJob already filters soft-deleted links,
        // but a partner who transitions to individual after the fan-out was
        // queued would no longer have receives_brand_status_notifications.
        $affiliate = Professional::find($this->affiliateProfessionalId);
        if (! $affiliate) {
            Log::warning('SendBrandStatusNotificationJob: affiliate not found, skipping', [
                'affiliate_professional_id' => $this->affiliateProfessionalId,
                'brand_professional_id' => $this->brandProfessionalId,
            ]);

            return;
        }

        if (! AccountCapabilities::for($affiliate)->receives_brand_status_notifications) {
            Log::debug('SendBrandStatusNotificationJob: capability gate skip', [
                'professional_id' => $this->affiliateProfessionalId,
                'capability' => 'receives_brand_status_notifications',
                'job' => self::class,
            ]);

            return;
        }

        // Guard against the brand being soft-deleted between fan-out dispatch
        // and this leaf job running — without it we'd send affiliates a
        // notification for a brand that no longer exists.
        $brand = Professional::find($this->brandProfessionalId);
        if (! $brand) {
            Log::debug('SendBrandStatusNotificationJob: brand not found, skipping', [
                'affiliate_professional_id' => $this->affiliateProfessionalId,
                'brand_professional_id' => $this->brandProfessionalId,
            ]);

            return;
        }

        match ($this->brandStatus) {
            BrandStatus::Onboarding->value => $publisher->publish(
                professionalId: $this->affiliateProfessionalId,
                frontendType: 'Warning',
                category: 'brand_status',
                title: 'Brand program paused',
                body: "{$this->brandName}'s affiliate program is no longer active.",
                dedupeKey: "brand.onboarding.{$this->brandProfessionalId}.{$this->yearWeek}",
                ctaUrl: '/account/store',
                retentionConfigKey: 'brand_status',
            ),
            BrandStatus::SystemsDown->value => $publisher->publish(
                professionalId: $this->affiliateProfessionalId,
                frontendType: 'Warning',
                category: 'brand_status',
                title: 'Brand program temporarily unavailable',
                body: "{$this->brandName}'s affiliate program is temporarily unavailable due to a platform issue.",
                dedupeKey: "brand.systems_down.{$this->brandProfessionalId}.{$this->yearWeek}",
                ctaUrl: '/account/store',
                retentionConfigKey: 'brand_status',
            ),
            BrandStatus::ReadyForAffiliates->value => $publisher->publish(
                professionalId: $this->affiliateProfessionalId,
                frontendType: 'Info',
                category: 'brand_status',
                title: 'Brand program now active',
                body: "{$this->brandName}'s affiliate program is now active.",
                dedupeKey: "brand.ready_for_affiliates.{$this->brandProfessionalId}.{$this->yearWeek}",
                ctaUrl: '/account/store',
                retentionConfigKey: 'brand_status',
            ),
            // Fail loud, not silently wrong: an unrecognised status (e.g. a new
            // enum case wired into the observer but not here) sends no
            // notification and surfaces in logs instead of mislabelling.
            default => Log::warning('SendBrandStatusNotificationJob: unrecognised brand status, no notification sent', [
                'affiliate_professional_id' => $this->affiliateProfessionalId,
                'brand_professional_id' => $this->brandProfessionalId,
                'brand_status' => $this->brandStatus,
            ]),
        };
    }

    public function failed(\Throwable $e): void
    {
        report($e);

        Log::error('SendBrandStatusNotificationJob failed', [
            'affiliate_professional_id' => $this->affiliateProfessionalId,
            'brand_professional_id' => $this->brandProfessionalId,
            'brand_status' => $this->brandStatus,
            'message' => $e->getMessage(),
        ]);
    }
}
