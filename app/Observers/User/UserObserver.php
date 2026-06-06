<?php

namespace App\Observers\User;

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\User\User;
use App\Observers\Concerns\LogsWithRequestContext;
use App\Services\Cache\UserCacheService;
use App\Services\User\SectionVisibilityService;
use Illuminate\Support\Facades\Log;

// V2: Invalidates professional cache on profile update/delete/restore.
// Also syncs Cloudflare KV when handle changes (subdomain routing table),
// touches the parent site so public-payload edits propagate through
// SiteObserver → CloudflareCachePurgeJob within the §28.8 cache window,
// and re-evaluates section visibility for blocks whose enablement reads
// from User columns (public_contact).
class UserObserver
{
    use LogsWithRequestContext;

    public bool $afterCommit = true;

    /**
     * User columns that feed the §28.8 public profile payload. A change to any
     * of these advances `sites.updated_at` (via `touchParentSiteIfPublicFieldChanged`)
     * so the Redis cache key rolls forward AND CloudflareCachePurgeJob fires.
     *
     * Sources: IndividualProfileResource (handle, display_name) +
     * SitepageDataResolverService::getBio (bio, about). first_name + last_name
     * are included because display_name accessors typically derive from them
     * and editing one without the composite is a realistic flow.
     *
     * @var list<string>
     */
    private const PUBLIC_PROFILE_USER_FIELDS = [
        'handle',
        'display_name',
        'first_name',
        'last_name',
        'bio',
        'about',
    ];

    public function __construct(
        private UserCacheService $userCache,
        private SectionVisibilityService $visibilityService,
    ) {}

    public function updated(User $professional): void
    {
        // When a public profile field changed, touchParentSiteIfPublicFieldChanged will
        // fire SiteObserver → invalidateSite immediately after. Skip the site bust here
        // to avoid the same ~29 Redis DELs running twice for those fields.
        $publicFieldChanged = $professional->wasChanged(self::PUBLIC_PROFILE_USER_FIELDS);

        try {
            $this->userCache->invalidateUser($professional, bustSite: ! $publicFieldChanged);
        } catch (\Throwable $e) {
            Log::warning('Professional cache invalidation failed on update', $this->logContext(__METHOD__, [
                'user_id' => $professional->id,
                'message' => $e->getMessage(),
            ]));
        }

        $this->touchParentSiteIfPublicFieldChanged($professional);

        // Handle change → KV needs to re-sync. SyncSubdomainToKvJob writes
        // entries for the current handle AND every alias (the old handle gets
        // added to user_handle_aliases by UpdateSiteAction), so the old
        // subdomain keeps resolving via its alias entry — no separate retirement
        // dispatch is needed on a rename.
        if ($professional->wasChanged('handle')) {
            try {
                SyncSubdomainToKvJob::dispatch((string) $professional->id);
            } catch (\Throwable $e) {
                Log::warning('UserObserver: KV sync dispatch failed on handle change', $this->logContext(__METHOD__, [
                    'user_id' => $professional->id,
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
            Log::warning('UserObserver: public_contact reevaluation failed', $this->logContext(__METHOD__, [
                'user_id' => $professional->id,
                'message' => $e->getMessage(),
            ]));
        }
    }

    /**
     * When a public-visible User field changes, bump the parent site's
     * `updated_at`. SiteObserver::saved then handles Redis invalidation and
     * dispatches CloudflareCachePurgeJob — without this, bio/display_name/etc.
     * edits stay invisible at the edge until the §28.8 5-minute subrequest
     * cache and the 60s Redis key expire.
     */
    private function touchParentSiteIfPublicFieldChanged(User $professional): void
    {
        if (! $professional->wasChanged(self::PUBLIC_PROFILE_USER_FIELDS)) {
            return;
        }

        try {
            $professional->site?->touch();
        } catch (\Throwable $e) {
            Log::warning('UserObserver: parent site touch() failed on public-field change', $this->logContext(__METHOD__, [
                'user_id' => $professional->id,
                'message' => $e->getMessage(),
            ]));
        }
    }

    public function deleted(User $professional): void
    {
        try {
            $this->userCache->invalidateUser($professional);
        } catch (\Throwable $e) {
            Log::warning('Professional cache invalidation failed on delete', $this->logContext(__METHOD__, [
                'user_id' => $professional->id,
                'message' => $e->getMessage(),
            ]));
        }

        // Remove the subdomain routing entry so <handle>.partna.au stops
        // resolving immediately on (soft-)delete — otherwise the stale KV entry
        // routes for up to 7 days until the backfill cron, and blocks the handle
        // from being reclaimed. Capture the handle from the model instance (still
        // populated in the deleted event) and let SyncSubdomainToKvJob — the
        // single KV writer — perform the delete. The captured handle is required
        // because a hard-deleted row can't be looked up by the job.
        if ($professional->handle) {
            try {
                SyncSubdomainToKvJob::dispatch((string) $professional->id, (string) $professional->handle);
            } catch (\Throwable $e) {
                Log::warning('UserObserver: KV retire dispatch failed on delete', $this->logContext(__METHOD__, [
                    'user_id' => $professional->id,
                    'message' => $e->getMessage(),
                ]));
            }
        }
    }

    public function restored(User $professional): void
    {
        try {
            $this->userCache->invalidateUser($professional);
        } catch (\Throwable $e) {
            Log::warning('Professional cache invalidation failed on restore', $this->logContext(__METHOD__, [
                'user_id' => $professional->id,
                'message' => $e->getMessage(),
            ]));
        }

        // Restore re-adds the routing entry that deleted() removed — the same
        // job upserts now that the user is no longer trashed (mirrors delete()).
        if ($professional->handle) {
            try {
                SyncSubdomainToKvJob::dispatch((string) $professional->id);
            } catch (\Throwable $e) {
                Log::warning('UserObserver: KV sync dispatch failed on restore', $this->logContext(__METHOD__, [
                    'user_id' => $professional->id,
                    'message' => $e->getMessage(),
                ]));
            }
        }
    }
}
