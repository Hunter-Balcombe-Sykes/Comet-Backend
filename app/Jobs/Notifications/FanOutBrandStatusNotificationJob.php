<?php

namespace App\Jobs\Notifications;

use App\Models\Core\Professional\Professional;
use App\Services\Accounts\AccountCapabilities;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Fans out brand status change notifications to all connected affiliates.
// Only fires for affiliate-facing status transitions (live, building, systems_down).
// preview transitions are internal wizard state and don't notify affiliates.
// Uses Bus::batch() so each sub-chunk of jobs shares one Redis pipeline write
// instead of one write per job, protecting against unbounded queue pressure
// as affiliate counts grow.
class FanOutBrandStatusNotificationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // Surface deterministic failures fast — fail after 2 consecutive throws
    // instead of burning the full backoff window before Horizon alerts.
    public int $maxExceptions = 2;

    public int $backoff = 30;

    public int $timeout = 120;

    // Prevent concurrent fan-out for the same brand+status transition. The leaf
    // job's dedupe key blocks duplicate notification rows, but without this a
    // concurrent dispatch doubles the per-affiliate queue work. Keyed on both
    // brand and status so a brand flipping back and forth still fans out each
    // distinct transition.
    public function uniqueId(): string
    {
        return $this->brandProfessionalId.':'.$this->brandStatus;
    }

    // Batch size sourced from config('partna.notifications.batch_chunk_size')
    // so this and SendStaffBroadcastEmailsJob stay in lockstep without manual
    // sync (#CFG-3).

    public function __construct(
        public readonly string $brandProfessionalId,
        public readonly string $brandStatus, // BrandStatus::ReadyForAffiliates->value | BrandStatus::Onboarding->value | BrandStatus::SystemsDown->value
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $start = microtime(true);

        $brand = Professional::find($this->brandProfessionalId);

        if (! $brand) {
            Log::warning('FanOutBrandStatusNotificationJob: brand not found, skipping fan-out', [
                'brand_professional_id' => $this->brandProfessionalId,
            ]);

            return;
        }

        // Identity gate: this job only makes sense for brand accounts. A non-brand
        // professional should never reach here — log a warning (unexpected, not benign)
        // and bail so we don't fan out status notifications to their contacts.
        if (! $brand->isBrand()) {
            Log::warning('FanOutBrandStatusNotificationJob: sender is not a brand, skipping fan-out', [
                'brand_professional_id' => $this->brandProfessionalId,
                'account_type' => $brand->account_type?->value,
                'job' => self::class,
            ]);

            return;
        }

        $yearWeek = now()->format('o-W');
        $brandName = (string) ($brand->display_name ?: $brand->handle ?: 'Brand');
        $totalAffiliates = 0;

        // Exclude soft-deleted links — ex-partners must not receive brand
        // status notifications post-disconnect (plan §28.16).
        DB::table('brand.brand_partner_links')
            ->where('brand_professional_id', $this->brandProfessionalId)
            ->whereNull('deleted_at')
            ->chunkById(500, function ($rows) use ($brandName, $yearWeek, &$totalAffiliates) {
                // Defence-in-depth capability filter (§28.10 / §28.11).
                // SendBrandStatusNotificationJob already gates per-recipient, but
                // dropping ineligible affiliates here avoids ~N queue dispatches +
                // ~N notification table inserts that would just be skipped at the
                // leaf. ex-partners and any transitioned-to-individual cohort fall
                // out cleanly without burning queue capacity.
                $affiliateIds = $rows->pluck('affiliate_professional_id')->all();
                $affiliates = Professional::query()->whereIn('id', $affiliateIds)->get()->keyBy('id');

                $jobs = $rows
                    ->filter(function ($row) use ($affiliates) {
                        $aff = $affiliates->get($row->affiliate_professional_id);

                        // FAIL-CLOSED: production has FK enforcement so a missing
                        // Professional row indicates a data-integrity bug. Drop the
                        // notification rather than blindly forwarding it. Tests must
                        // seed real Professional rows for these branches to fire.
                        if (! $aff) {
                            return false;
                        }

                        return AccountCapabilities::for($aff)->receives_brand_status_notifications;
                    })
                    ->map(fn ($row) => new SendBrandStatusNotificationJob(
                        affiliateProfessionalId: $row->affiliate_professional_id,
                        brandProfessionalId: $this->brandProfessionalId,
                        brandName: $brandName,
                        brandStatus: $this->brandStatus,
                        yearWeek: $yearWeek,
                    ))->all();

                if ($jobs === []) {
                    return;
                }

                // One Redis pipeline write per batch vs. one per job if dispatched
                // individually. allowFailures() preserves the per-job retry semantics
                // SendBrandStatusNotificationJob's $tries=3 promises — without it, a
                // single failure cancels remaining (still-pending) jobs in the batch.
                foreach (array_chunk($jobs, (int) config('partna.notifications.batch_chunk_size', 200)) as $chunk) {
                    $batch = Bus::batch($chunk)
                        ->onQueue('notifications')
                        ->name('brand-status-fanout:'.$this->brandProfessionalId)
                        ->allowFailures()
                        ->dispatch();

                    Log::info('Brand status fan-out batch dispatched', [
                        'batch_id' => $batch->id,
                        'brand_professional_id' => $this->brandProfessionalId,
                        'brand_status' => $this->brandStatus,
                        'job_count' => count($chunk),
                    ]);
                }

                $totalAffiliates += count($jobs);
            });

        // Alert threshold: 20s for the dispatch walk itself (does NOT include
        // the actual notification sends — those run inside the dispatched batches).
        // Slow here means chunkById is degraded or Redis batch dispatch is backed up.
        $durationMs = (int) round((microtime(true) - $start) * 1000);
        if ($durationMs > 20_000) {
            Log::warning('FanOutBrandStatusNotificationJob slow fan-out', [
                'brand_professional_id' => $this->brandProfessionalId,
                'brand_status' => $this->brandStatus,
                'affiliate_count' => $totalAffiliates,
                'duration_ms' => $durationMs,
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        report($e);

        Log::error('FanOutBrandStatusNotificationJob failed', [
            'brand_professional_id' => $this->brandProfessionalId,
            'brand_status' => $this->brandStatus,
            'message' => $e->getMessage(),
        ]);
    }
}
