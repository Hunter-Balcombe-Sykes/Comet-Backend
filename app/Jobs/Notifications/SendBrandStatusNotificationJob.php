<?php

namespace App\Jobs\Notifications;

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
        public readonly string $brandStatus, // 'live' | 'building' | 'systems_down'
        public readonly string $yearWeek,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(NotificationPublisher $publisher): void
    {
        match ($this->brandStatus) {
            'building' => $publisher->publish(
                professionalId: $this->affiliateProfessionalId,
                frontendType: 'Warning',
                category: 'brand_status',
                title: 'Brand program paused',
                body: "{$this->brandName}'s affiliate program is no longer active.",
                dedupeKey: "brand.building.{$this->brandProfessionalId}.{$this->yearWeek}",
                ctaUrl: '/account/store',
                retentionConfigKey: 'brand_status',
            ),
            'systems_down' => $publisher->publish(
                professionalId: $this->affiliateProfessionalId,
                frontendType: 'Warning',
                category: 'brand_status',
                title: 'Brand program temporarily unavailable',
                body: "{$this->brandName}'s affiliate program is temporarily unavailable due to a platform issue.",
                dedupeKey: "brand.systems_down.{$this->brandProfessionalId}.{$this->yearWeek}",
                ctaUrl: '/account/store',
                retentionConfigKey: 'brand_status',
            ),
            default => $publisher->publish(
                professionalId: $this->affiliateProfessionalId,
                frontendType: 'Info',
                category: 'brand_status',
                title: 'Brand program now active',
                body: "{$this->brandName}'s affiliate program is now active.",
                dedupeKey: "brand.live.{$this->brandProfessionalId}.{$this->yearWeek}",
                ctaUrl: '/account/store',
                retentionConfigKey: 'brand_status',
            ),
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
