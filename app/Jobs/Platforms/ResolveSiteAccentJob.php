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
 * inherited, not reimplemented here). Two dispatch sites since 9e
 * (2026-09-01): ScanPreviousWebsiteContentJob fires it immediately with the
 * synchronously-available tiers (theme-color/favicon), and SiteMediaObserver
 * chains it whenever a logo/gallery asset reaches READY with a dominant
 * colour — the exact state the async tiers query, replacing the old blind
 * +120s re-dispatch. Cheap and safe to over-dispatch: idempotent, and a
 * no-op once an accent exists.
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
