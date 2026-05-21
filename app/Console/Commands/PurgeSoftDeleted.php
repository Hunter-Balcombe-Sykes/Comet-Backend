<?php

namespace App\Console\Commands;

use App\Models\Core\FeatureFlag;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Customer;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\Service;
use App\Models\Core\Professional\ServiceCategory;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use App\Models\Core\Site\SiteMedia;
use App\Services\Professional\AccountDeletionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

// V2: Hard-deletes soft-deleted rows (customers, services, media) past retention
// window, AND hard-deletes professionals whose self-service deletion grace period
// has elapsed (via AccountDeletionService).
class PurgeSoftDeleted extends Command
{
    protected $signature = 'partna:purge-soft-deletes {--days= : Override retention days}';

    protected $description = 'Permanently delete soft-deleted rows and pending-deletion professionals older than retention window.';

    /**
     * Models whose soft-deleted rows this command force-deletes after the
     * retention window. Keep in sync with handle(). Audit DATA-4 sweep test
     * asserts every SoftDeletes-using model is either listed here, in
     * PURGE_EXEMPT, or in PURGE_OTHER_PATH.
     */
    public const PURGE_HANDLED = [
        Customer::class,
        Service::class,
        SiteMedia::class,
        Enquiry::class,
        ServiceCategory::class,
        Block::class,
    ];

    /**
     * SoftDeletes-using models that are deliberately exempt from this purge.
     * Each entry must carry a justification so future maintainers don't add
     * them to PURGE_HANDLED without thought.
     */
    public const PURGE_EXEMPT = [
        FeatureFlag::class => 'Lifecycle managed by FeatureFlagService — flags are tombstoned for audit history and never auto-purged.',
        BrandPartnerLink::class => 'Pending deletion in Task 3 of the standalone-pages strip — model and table will be removed entirely.',
    ];

    /**
     * SoftDeletes-using models handled by a different purge path (not the
     * generic retention loop in handle()).
     */
    public const PURGE_OTHER_PATH = [
        // purgePendingDeletionProfessionals() — different lifecycle (Supabase
        // Admin API call + audit log).
        Professional::class => 'purgePendingDeletionProfessionals() — uses AccountDeletionService::purge() instead of forceDelete().',
    ];

    public function handle(AccountDeletionService $deletionService): int
    {
        $days = (int) ($this->option('days') ?: config('partna.soft_delete_retention_days', 30));
        $cutoff = now()->subDays($days);

        $this->info("Purging soft-deleted rows older than {$days} days (before {$cutoff}).");

        $total = 0;

        foreach (self::PURGE_HANDLED as $modelClass) {
            $total += $this->purgeModel($modelClass, $cutoff);
        }

        $this->info("Done with soft deletes. Force-deleted {$total} rows.");

        $this->purgeFailedMedia();

        // Pending-deletion professionals past grace period
        $this->purgePendingDeletionProfessionals($cutoff, $deletionService);

        return self::SUCCESS;
    }

    private function purgeModel(string $modelClass, Carbon $cutoff): int
    {
        $count = 0;
        $failed = 0;

        $modelClass::onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->orderBy('deleted_at')
            ->chunk(500, function ($rows) use (&$count, &$failed) {
                foreach ($rows as $row) {
                    try {
                        $row->forceDelete();
                        $count++;
                    } catch (\Throwable $e) {
                        $failed++;
                        report($e);
                    }
                }
            });

        $this->line(class_basename($modelClass).": {$count} purged, {$failed} failed.");

        return $count;
    }

    /**
     * Hard-delete SiteMedia rows stuck in a terminal 'failed' state for more than 7 days.
     * Failed rows are never soft-deleted — they hold deleted_at = NULL — so the standard
     * purgeModel pass does not reach them. A shorter 7-day window is used because a failed
     * upload is irrecoverable: the file was never stored, there is nothing for the user to
     * recover, and each failed row occupies a gallery slot count in the upload controller.
     */
    private function purgeFailedMedia(): void
    {
        $failedCutoff = now()->subDays(7);
        $count = 0;
        $failed = 0;

        SiteMedia::query()
            ->where('processing_state', SiteMedia::PROCESSING_STATE_FAILED)
            ->where('created_at', '<', $failedCutoff)
            ->orderBy('created_at')
            ->chunk(500, function ($rows) use (&$count, &$failed) {
                foreach ($rows as $row) {
                    try {
                        $row->forceDelete();
                        $count++;
                    } catch (\Throwable $e) {
                        $failed++;
                        report($e);
                    }
                }
            });

        $this->line("SiteMedia (failed): {$count} purged, {$failed} failed.");
    }

    /**
     * Hard-delete professionals whose grace period has elapsed. Each is handled
     * via AccountDeletionService::purge() which calls Supabase Admin API first.
     * Failures are logged to the audit table and retried on the next daily run.
     */
    private function purgePendingDeletionProfessionals(Carbon $cutoff, AccountDeletionService $deletionService): void
    {
        $purged = 0;
        $failed = 0;

        Professional::query()
            ->where('status', 'pending_deletion')
            ->where('deletion_confirmed_at', '<', $cutoff)
            ->orderBy('deletion_confirmed_at')
            ->chunk(100, function ($professionals) use ($deletionService, &$purged, &$failed) {
                foreach ($professionals as $professional) {
                    if ($deletionService->purge($professional)) {
                        $purged++;
                    } else {
                        $failed++;
                    }
                }
            });

        $this->line("PendingDeletion professionals: {$purged} purged, {$failed} failed (will retry next run).");
    }
}
