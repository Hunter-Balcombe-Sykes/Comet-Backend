<?php

namespace App\Observers\Professional;

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Professional\Professional;
use App\Observers\Concerns\LogsWithRequestContext;
use App\Services\Cache\ProfessionalCacheService;
use Illuminate\Support\Facades\Log;

// V2: Invalidates professional cache on profile update/delete/restore.
// Also syncs Cloudflare KV when handle changes (subdomain routing table).
class ProfessionalObserver
{
    use LogsWithRequestContext;

    public bool $afterCommit = true;

    public function __construct(
        private ProfessionalCacheService $professionalCache,
    ) {}

    public function updated(Professional $professional): void
    {
        try {
            $this->professionalCache->invalidateProfessional($professional);
        } catch (\Throwable $e) {
            Log::warning('Professional cache invalidation failed on update', $this->logContext(__METHOD__, [
                'professional_id' => $professional->id,
                'message' => $e->getMessage(),
            ]));
        }

        // Handle change → KV needs to re-sync. SyncSubdomainToKvJob now writes
        // entries for the current handle AND every alias (the old handle gets
        // added to professional_handle_aliases by UpdateSiteAction), so a
        // separate RetireSubdomainFromKvJob is no longer needed here — the old
        // subdomain keeps resolving via its alias entry. Retire is still
        // available for explicit single-handle deletions elsewhere.
        // Dispatch on handle OR account_type change — the latter so any direct
        // mutation that bypasses AccountTypeTransitionService (admin tooling,
        // ops migrations) still keeps SUBDOMAIN_KV in sync. Job is ShouldBeUnique
        // with a 45s window, so double-dispatch from the service + observer
        // collapses to one KV write (audit #13).
        if ($professional->wasChanged('handle') || $professional->wasChanged('account_type')) {
            try {
                SyncSubdomainToKvJob::dispatch((string) $professional->id);
            } catch (\Throwable $e) {
                Log::warning('ProfessionalObserver: KV sync dispatch failed on handle/account_type change', $this->logContext(__METHOD__, [
                    'professional_id' => $professional->id,
                    'message' => $e->getMessage(),
                ]));
            }
        }
    }

    public function deleted(Professional $professional): void
    {
        try {
            $this->professionalCache->invalidateProfessional($professional);
        } catch (\Throwable $e) {
            Log::warning('Professional cache invalidation failed on delete', $this->logContext(__METHOD__, [
                'professional_id' => $professional->id,
                'message' => $e->getMessage(),
            ]));
        }
    }

    public function restored(Professional $professional): void
    {
        try {
            $this->professionalCache->invalidateProfessional($professional);
        } catch (\Throwable $e) {
            Log::warning('Professional cache invalidation failed on restore', $this->logContext(__METHOD__, [
                'professional_id' => $professional->id,
                'message' => $e->getMessage(),
            ]));
        }
    }
}
