<?php

namespace App\Observers\Core;

use App\Jobs\Platforms\ResolveSiteAccentJob;
use App\Models\Core\Site\SiteMedia;
use App\Observers\Concerns\LogsWithRequestContext;
use App\Services\Cache\SiteCacheInvalidator;
use App\Services\User\SectionVisibilityService;
use Illuminate\Support\Facades\Log;

// V2: Re-evaluates section visibility when media rows are saved, deleted, or restored.
// Handles gallery images and documents — each maps to a distinct section block type;
// mapping is defined in usageToBlockType().
class SiteMediaObserver
{
    use LogsWithRequestContext;

    public bool $afterCommit = true;

    public function __construct(
        private readonly SectionVisibilityService $visibilityService,
        private readonly SiteCacheInvalidator $invalidator,
    ) {}

    /**
     * Columns whose change has a visible impact on the public site. An update that
     * touches ONLY other columns (alt_text, caption, processing_error, etc.) is a
     * no-op for the public page and should not trigger a CF purge or site-cache sweep.
     *
     * `processing_state` is the critical one — it fires when an upload transitions
     * from pending/processing → ready, making the asset publicly visible for the
     * first time. `path` covers storage-path corrections after reprocessing.
     */
    private const CACHE_AFFECTING_COLUMNS = [
        'is_active', 'processing_state', 'usage', 'media_type', 'path', 'sort_order',
    ];

    public function created(SiteMedia $media): void
    {
        // New media always triggers section visibility reevaluation and cache bust.
        $this->reevaluateIfRelevant($media);
        $this->touchParentSite($media, 'create');
        $this->maybeChainAccentResolve($media);
    }

    public function updated(SiteMedia $media): void
    {
        // Skip the cache sweep for updates that touch only metadata columns
        // (alt_text, caption, processing_error, etc.) with no bearing on the
        // rendered public page.
        if (! $media->wasChanged(self::CACHE_AFFECTING_COLUMNS)) {
            return;
        }

        $this->reevaluateIfRelevant($media);
        $this->touchParentSite($media, 'update');
        $this->maybeChainAccentResolve($media);
    }

    /**
     * 9e (2026-09-01): the accent's logo/gallery tiers used to be reached by a
     * BLIND +120s re-dispatch from ScanPreviousWebsiteContentJob — a timer
     * guessing when the variant pipeline would land. This is the real event:
     * a logo or gallery asset reaching READY with a dominant colour IS the
     * input those tiers read (SiteAccentResolver queries exactly this state),
     * so resolution chains off it directly. DesignKitAccentApplier is
     * fill-if-empty, so once an accent exists every later transition no-ops —
     * over-dispatch is a queue push, never a visible change.
     */
    private function maybeChainAccentResolve(SiteMedia $media): void
    {
        // site_id is NOT NULL at the DB — only state and colour gate here.
        if ($media->processing_state !== SiteMedia::PROCESSING_STATE_READY
            || $media->dominant_color === null) {
            return;
        }

        $isLogo = $media->usage === SiteMedia::USAGE_DESIGN
            && in_array($media->purpose, [SiteMedia::PURPOSE_LOGO_FULL, SiteMedia::PURPOSE_LOGO_SQUARE], true);
        $isListable = in_array($media->usage, SiteMedia::LISTABLE_USAGES, true) && $media->is_active;
        if (! $isLogo && ! $isListable) {
            return;
        }

        ResolveSiteAccentJob::dispatch((string) $media->site_id, null, null);
    }

    public function deleted(SiteMedia $media): void
    {
        // Always bust on delete — presence/count always changes.
        $this->reevaluateIfRelevant($media);
        $this->touchParentSite($media, 'delete');
    }

    public function restored(SiteMedia $media): void
    {
        // Always bust on restore — presence/count always changes.
        $this->reevaluateIfRelevant($media);
        $this->touchParentSite($media, 'restore');
    }

    /**
     * Bump `sites.updated_at` so `SiteObserver::saved` fires and dispatches
     * `CloudflareCachePurgeJob`. Without this, the Cloudflare edge cache for
     * `<handle>.partna.au` would hold the pre-upload HTML for the full
     * `s-maxage` window (~5 min) before refreshing — content image uploads
     * then took 5–15 min to appear publicly.
     *
     * `touch()` only changes `updated_at`. SiteObserver's other dispatches
     * (SyncSubdomainToKvJob) gate on `wasChanged('subdomain')` and stay
     * inert. Cost is one UPDATE + one CF purge enqueue per media write.
     */
    private function touchParentSite(SiteMedia $media, string $action): void
    {
        $this->invalidator->touchSite(fn () => $media->site, $action, [
            'site_media_id' => $media->id,
            'site_id' => $media->site_id,
            'usage' => $media->usage,
        ]);
    }

    private function reevaluateIfRelevant(SiteMedia $media): void
    {
        $blockType = $this->usageToBlockType($media->usage);
        if ($blockType === null) {
            return;
        }

        $site = null;
        try {
            $site = $media->site;
            if (! $site || ! $site->user_id) {
                return;
            }

            $this->visibilityService->reevaluateEnabled(
                (string) $site->user_id,
                (string) $media->site_id,
                $blockType
            );
        } catch (\Throwable $e) {
            Log::warning('Section visibility reevaluation failed on SiteMedia event', $this->logContext(__METHOD__, [
                'site_media_id' => $media->id,
                'site_id' => $media->site_id,
                'user_id' => $site?->user_id,
                'usage' => $media->usage,
                'block_type' => $blockType,
                'message' => $e->getMessage(),
            ]));
        }
    }

    /**
     * Map a site_media usage to the section block_type it feeds. Returns null
     * for usages that don't drive a section (design).
     */
    private function usageToBlockType(?string $usage): ?string
    {
        return match ($usage) {
            // POOL_GALLERY's arm left with the pool (Wave 6, 2026-09-02).
            SiteMedia::USAGE_DOCUMENTS => 'documents',
            default => null,
        };
    }
}
