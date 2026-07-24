<?php

namespace App\Jobs\Platforms;

use App\Services\WebsiteScan\DesignKitAccentApplier;
use App\Services\WebsiteScan\SiteAccentResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs SiteAccentResolver's priority chain and applies the result
 * fill-if-empty (DesignKitAccentApplier already no-ops on a manual accent —
 * inherited, not reimplemented here). Dispatched TWICE from
 * ScanPreviousWebsiteContentJob: immediately (theme-color/favicon are
 * available synchronously) and again after a delay (the logo/gallery tiers
 * depend on async media processing this job has no direct handle to chain
 * onto — LogoAutoGrabber/GalleryAutoGrabber dispatch their own variant jobs
 * several layers down via MediaUploadService, opaque to the caller). Cheap
 * and safe to over-dispatch: idempotent, and a no-op once an accent exists.
 */
class ResolveSiteAccentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** @var list<int> A single 30s backoff before the one retry — matches this job's sibling scan jobs. */
    public array $backoff = [30];

    public int $timeout = 30;

    public function __construct(
        public readonly string $siteId,
        public readonly ?string $themeColor,
        public readonly ?string $faviconColor,
    ) {
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
    }

    public function handle(SiteAccentResolver $resolver, DesignKitAccentApplier $applier): void
    {
        $applier->apply($this->siteId, $resolver->resolve($this->siteId, $this->themeColor, $this->faviconColor));
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('website_scan.resolve_accent_failed', [
            'site_id' => $this->siteId,
            'error' => $e->getMessage(),
        ]);
    }
}
