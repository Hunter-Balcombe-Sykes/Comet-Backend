<?php

namespace App\Observers\Professional;

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Professional\User;
use App\Observers\Concerns\LogsWithRequestContext;
use App\Services\Cache\ProfessionalCacheService;
use App\Services\Professional\SectionVisibilityService;
use Illuminate\Support\Facades\Log;

// V2: Invalidates professional cache on profile update/delete/restore.
// Also syncs Cloudflare KV when handle changes (subdomain routing table)
// and re-evaluates section visibility for blocks whose enablement reads
// from User columns (public_contact).
class ProfessionalObserver
{
    use LogsWithRequestContext;

    public bool $afterCommit = true;

    public function __construct(
        private ProfessionalCacheService $professionalCache,
        private SectionVisibilityService $visibilityService,
    ) {}

    public function updated(User $professional): void
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
        // Dispatch on handle change so SUBDOMAIN_KV stays in sync.
        if ($professional->wasChanged('handle')) {
            try {
                SyncSubdomainToKvJob::dispatch((string) $professional->id);
            } catch (\Throwable $e) {
                Log::warning('ProfessionalObserver: KV sync dispatch failed on handle change', $this->logContext(__METHOD__, [
                    'professional_id' => $professional->id,
                    'message' => $e->getMessage(),
                ]));
            }
        }

        // Public-contact section is_enabled is derived from `public_contact_*`
        // columns. Without this re-eval, a freshly-set contact email leaves
        // is_enabled=false on the section block and the public render path
        // hides the section until the next manual toggle.
        if ($professional->wasChanged(['public_contact_number', 'public_contact_email'])) {
            $this->reevaluatePublicContactSection($professional);
        }
    }

    private function reevaluatePublicContactSection(User $professional): void
    {
        try {
            $site = $professional->site;
            if (! $site) {
                return;
            }

            $this->visibilityService->reevaluateEnabled(
                (string) $professional->id,
                (string) $site->id,
                'public_contact',
            );
        } catch (\Throwable $e) {
            Log::warning('ProfessionalObserver: public_contact reevaluation failed', $this->logContext(__METHOD__, [
                'professional_id' => $professional->id,
                'message' => $e->getMessage(),
            ]));
        }
    }

    public function deleted(User $professional): void
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

    public function restored(User $professional): void
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
