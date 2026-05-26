<?php

namespace App\Observers\Core;

use App\Models\Core\Site\SiteMedia;
use App\Observers\Concerns\LogsWithRequestContext;
use App\Services\User\SectionVisibilityService;
use Illuminate\Support\Facades\Log;

// V2: Re-evaluates section visibility when media rows are saved, deleted, or restored.
// Handles gallery images and documents — each maps to a distinct section block type;
// mapping is defined in poolToBlockType().
class SiteMediaObserver
{
    use LogsWithRequestContext;

    public bool $afterCommit = true;

    public function __construct(
        private readonly SectionVisibilityService $visibilityService,
    ) {}

    public function saved(SiteMedia $media): void
    {
        $this->reevaluateIfRelevant($media);
        $this->touchParentSite($media, 'save');
    }

    public function deleted(SiteMedia $media): void
    {
        $this->reevaluateIfRelevant($media);
        $this->touchParentSite($media, 'delete');
    }

    public function restored(SiteMedia $media): void
    {
        $this->reevaluateIfRelevant($media);
        $this->touchParentSite($media, 'restore');
    }

    /**
     * Bump `sites.updated_at` so `SiteObserver::saved` fires and dispatches
     * `CloudflareCachePurgeJob`. Without this, the Cloudflare edge cache for
     * `<handle>.partna.au` (Astro Worker path for individuals + Hydrogen
     * affiliate path for partners) would hold the pre-upload HTML for the
     * full `s-maxage` window (~5 min) before refreshing — content image
     * uploads then took 5–15 min to appear publicly.
     *
     * `touch()` only changes `updated_at`. SiteObserver's other dispatches
     * (SyncSubdomainToKvJob) gate on `wasChanged('subdomain')` and stay
     * inert. Cost is one UPDATE + one CF purge enqueue per media write.
     */
    private function touchParentSite(SiteMedia $media, string $action): void
    {
        try {
            $site = $media->site;
            if (! $site) {
                return;
            }
            $site->touch();
        } catch (\Throwable $e) {
            Log::warning('Parent site touch() failed on SiteMedia '.$action, $this->logContext(__METHOD__, [
                'site_media_id' => $media->id,
                'site_id' => $media->site_id,
                'pool' => $media->pool,
                'message' => $e->getMessage(),
            ]));
        }
    }

    private function reevaluateIfRelevant(SiteMedia $media): void
    {
        $blockType = $this->poolToBlockType($media->pool);
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
                'pool' => $media->pool,
                'block_type' => $blockType,
                'message' => $e->getMessage(),
            ]));
        }
    }

    /**
     * Map a site_media pool to the section block_type it feeds. Returns null
     * for pools that don't drive a section (brand gallery, design, product).
     */
    private function poolToBlockType(?string $pool): ?string
    {
        return match ($pool) {
            SiteMedia::POOL_GALLERY => 'gallery',
            SiteMedia::POOL_DOCUMENTS => 'documents',
            default => null,
        };
    }
}
