<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\WebsiteScan\GalleryAutoGrabber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

// Separate job (not inline in ScanPreviousWebsiteContentJob) because
// downloading + validating + uploading several candidate photos can run
// long — same rationale as WebsiteMenuPdfScanJob being split out from the
// orchestrator's own 60s window.
class WebsiteGalleryScanJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [30];

    public int $timeout = 90;

    // Matches this job's own named sibling family, WebsiteMenuHtmlScanJob/
    // WebsiteMenuPdfScanJob (both split out of ScanPreviousWebsiteContentJob for the
    // same reason — see class docblock above). No default means UniqueLock falls
    // back to `?? 0` and RedisLock treats 0 as "no expiry" (plain SETNX) — a worker
    // killed mid-job (OOM, deploy, timeout) would strand that lock forever.
    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $userId,
        public readonly string $siteId,
        /** @var list<string> */
        public readonly array $candidateUrls,
    ) {
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
    }

    public function uniqueId(): string
    {
        return $this->userId.':website-gallery-scan';
    }

    public function handle(GalleryAutoGrabber $grabber): void
    {
        $user = User::find($this->userId);
        $site = Site::find($this->siteId);
        if ($user === null || $site === null) {
            return;
        }

        $decisions = $grabber->grabIfEmpty($user, $site, $this->candidateUrls);
        if ($decisions !== []) {
            Log::info('website_scan.gallery_grab', [
                'user_id' => $this->userId,
                'site_id' => $this->siteId,
                'decisions' => $decisions,
            ]);
        }
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('website_scan.gallery_scan_failed', [
            'user_id' => $this->userId,
            'site_id' => $this->siteId,
            'error' => $e->getMessage(),
        ]);
    }
}
