<?php

namespace App\Observers\Core;

use App\Jobs\Design\AnalyzePreviousWebsiteJob;
use App\Models\Core\Site\Workplace;
use Illuminate\Support\Facades\Log;

// Keeps the previous-website brand analysis in step with the stored URL via
// STATE RECONCILIATION (current URL vs current analysis.url — no
// original-value diffing, so it works identically under afterCommit,
// transactions, and every write path: dashboard PATCH, full workplace upsert,
// Google Business auto-fill). The analyze job writing its result makes the
// two agree, so the loop terminates by construction.
class WorkplaceObserver
{
    public bool $afterCommit = true;

    public function saved(Workplace $workplace): void
    {
        $this->reconcile($workplace);
    }

    public function deleted(Workplace $workplace): void
    {
        // Row gone → URL gone → analysis died with the row. Re-resolve so the
        // previous-website contributions sweep. (The analyze job handles the
        // no-workplace case as "URL cleared".)
        $this->dispatch($workplace);
    }

    private function reconcile(Workplace $workplace): void
    {
        $url = trim((string) $workplace->previous_website);
        $analysis = $workplace->previous_website_analysis;
        $analyzedUrl = is_array($analysis) ? ($analysis['url'] ?? null) : null;

        // In-sync states: no URL + no analysis, or analysis matches the URL.
        if (($url === '' && $analysis === null) || ($url !== '' && $analyzedUrl === $url)) {
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
