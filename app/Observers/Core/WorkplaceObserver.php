<?php

namespace App\Observers\Core;

use App\Jobs\Design\AnalyzePreviousWebsiteJob;
use App\Models\Core\Site\Workplace;
use App\Services\Cache\SiteCacheInvalidator;
use App\Services\Design\WebsiteStyleAnalyzer;
use Illuminate\Support\Facades\Log;

// Keeps the previous-website brand analysis in step with the stored URL via
// STATE RECONCILIATION (current URL vs current analysis.url — no
// original-value diffing, so it works identically under afterCommit,
// transactions, and every write path: dashboard PATCH, full workplace upsert,
// Google Business auto-fill). The analyze job writing its result makes the
// two agree, so the loop terminates by construction.
//
// ALSO busts the public-page cache when a workplace field that renders on the
// sitepage changes. The workplace card (address/hours/phone/description/…) feeds
// the public payload, but editing an ALREADY-ENABLED card never touched any
// cache layer before: UserWorkplaceController relies on SectionVisibilityService
// which only saves the Block (→ BlockObserver → purge) when the enabled-state
// flips, so a plain hours/address edit went live-invisible until an unrelated
// mutation happened to touch the site.
class WorkplaceObserver
{
    public bool $afterCommit = true;

    // Columns whose change is visible on the public sitepage. A save touching
    // ONLY other columns (previous_website + its system-written analysis /
    // field_sources provenance — design-factor inputs, never rendered) skips the
    // purge, so AnalyzePreviousWebsiteJob writing its result back doesn't
    // spuriously bust the edge cache on every analysis pass.
    private const CACHE_AFFECTING_COLUMNS = [
        'name', 'address', 'address_line1', 'city', 'state', 'postcode', 'country',
        'latitude', 'longitude', 'phone', 'website', 'category', 'description',
        'opening_hours', 'contact_email',
    ];

    public function __construct(
        private readonly SiteCacheInvalidator $invalidator,
    ) {}

    public function saved(Workplace $workplace): void
    {
        $this->reconcile($workplace);

        // wasRecentlyCreated covers first insert (whole card is new content);
        // wasChanged() gates updates to the public-visible columns only.
        if ($workplace->wasRecentlyCreated || $workplace->wasChanged(self::CACHE_AFFECTING_COLUMNS)) {
            $this->invalidator->touchSite(
                fn () => $workplace->site,
                'workplace-save',
                ['site_id' => $workplace->site_id],
            );
        }
    }

    public function deleted(Workplace $workplace): void
    {
        // Row gone → URL gone → analysis died with the row. Re-resolve so the
        // previous-website contributions sweep. (The analyze job handles the
        // no-workplace case as "URL cleared".)
        $this->dispatch($workplace);

        // The card also vanishes from the public page — bust the edge cache.
        $this->invalidator->touchSite(
            fn () => $workplace->site,
            'workplace-delete',
            ['site_id' => $workplace->site_id],
        );
    }

    private function reconcile(Workplace $workplace): void
    {
        $url = trim((string) $workplace->previous_website);
        $analysis = $workplace->previous_website_analysis;
        $analyzedUrl = is_array($analysis) ? ($analysis['url'] ?? null) : null;
        $analyzedVersion = is_array($analysis) ? ($analysis['v'] ?? null) : null;

        // In-sync states: no URL + no analysis, or a CURRENT-version analysis
        // matching the URL. A version bump makes every stored analysis stale,
        // so re-scans roll out organically (and via the backfill command).
        if (($url === '' && $analysis === null)
            || ($url !== '' && $analyzedUrl === $url && $analyzedVersion === WebsiteStyleAnalyzer::VERSION)) {
            return;
        }

        $this->dispatch($workplace);
    }

    // Observer must never crash the parent write.
    private function dispatch(Workplace $workplace): void
    {
        try {
            AnalyzePreviousWebsiteJob::dispatch((string) $workplace->site_id);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('WorkplaceObserver analyze dispatch failed', [
                'site_id' => $workplace->site_id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
