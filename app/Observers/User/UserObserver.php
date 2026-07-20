<?php

namespace App\Observers\User;

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\User\User;
use App\Observers\Concerns\LogsWithRequestContext;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Accounts\LifestyleConnectionCleanup;
use App\Services\Cache\SiteCacheInvalidator;
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
     * Source: IndividualProfileResource (handle, display_name). first_name +
     * last_name are included because display_name accessors typically derive
     * from them and editing one without the composite is a realistic flow.
     *
     * @var list<string>
     */
    private const PUBLIC_PROFILE_USER_FIELDS = [
        'handle',
        'display_name',
        'first_name',
        'last_name',
    ];

    public function __construct(
        private UserCacheService $userCache,
        private SectionVisibilityService $visibilityService,
        private readonly SiteCacheInvalidator $invalidator,
        private readonly LifestyleConnectionCleanup $lifestyleCleanup,
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
            // CCH-101: Log::warning alone raises no Nightwatch issue — report() so a
            // stale cache after a write doesn't go unnoticed until a user complains.
            report($e);
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

        // account_type → business: the lifestyle integration groups (Listen /
        // Community / Other) are hidden from the business dashboard, so any such
        // connection carried over from when the account was `partna` becomes an
        // un-removable orphan. Soft-delete them at the switch so no orphan is
        // created (the sitepage already stops showing the page — presentPageIds).
        if ($professional->wasChanged('account_type')) {
            $this->cleanupLifestyleConnectionsIfBusiness($professional);
        }
    }

    private function cleanupLifestyleConnectionsIfBusiness(User $professional): void
    {
        try {
            // Flush the per-request capability memo so the guard reads the
            // freshly-saved account_type, not a value computed earlier this request.
            AccountCapabilities::flushCache();
            $this->lifestyleCleanup->forUser($professional);
        } catch (\Throwable $e) {
            Log::warning('UserObserver: lifestyle connection cleanup failed', $this->logContext(__METHOD__, [
                'user_id' => $professional->id,
                'message' => $e->getMessage(),
            ]));
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
     * dispatches CloudflareCachePurgeJob — without this, display_name/etc.
     * edits stay invisible at the edge until the §28.8 5-minute subrequest
     * cache and the 60s Redis key expire.
     */
    private function touchParentSiteIfPublicFieldChanged(User $professional): void
    {
        if (! $professional->wasChanged(self::PUBLIC_PROFILE_USER_FIELDS)) {
            return;
        }

        $this->invalidator->touchSite(fn () => $professional->site, 'user public-field change', [
            'user_id' => $professional->id,
        ]);
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
            // CCH-101: see updated() — report() so Nightwatch sees the failure.
            report($e);
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
            // CCH-101: see updated() — report() so Nightwatch sees the failure.
            report($e);
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
