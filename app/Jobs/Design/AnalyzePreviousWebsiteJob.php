<?php

namespace App\Jobs\Design;

use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use App\Services\Design\LogoAutoGrabber;
use App\Services\Design\WebsiteStyleAnalyzer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

// Analyzes a site's previous-website URL into stored brand-signal conclusions
// (workplaces.previous_website_analysis), attempts the empty-slot logo grab,
// then queues a preset re-resolve. Dispatched by WorkplaceObserver whenever
// the stored URL and its analysis disagree (state reconciliation — loop-proof:
// this job writing the analysis makes them agree). ShouldBeUnique per site so
// rapid re-saves coalesce.
class AnalyzePreviousWebsiteJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [30];

    // Page + up to 5 stylesheets + up to 3 image candidates, 8s each worst-case.
    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return $this->siteId;
    }

    public function __construct(public readonly string $siteId) {}

    public function handle(WebsiteStyleAnalyzer $analyzer, LogoAutoGrabber $grabber): void
    {
        $workplace = Workplace::query()->find($this->siteId);
        $url = trim((string) ($workplace?->previous_website ?? ''));

        // URL cleared (or workplace row gone): drop any stale analysis and
        // re-resolve so the factor's contributions sweep.
        if ($url === '') {
            if ($workplace !== null && $workplace->previous_website_analysis !== null) {
                $workplace->previous_website_analysis = null;
                $workplace->save();
            }
            $this->dispatchResolve();

            return;
        }

        // Reconciliation idempotence: an analysis for this exact URL already
        // exists (success OR recorded failure) — nothing to do.
        $current = $workplace->previous_website_analysis;
        if (is_array($current) && ($current['url'] ?? null) === $url) {
            return;
        }

        $analysis = $analyzer->analyze($url);
        $workplace->previous_website_analysis = $analysis;
        $workplace->save();

        Log::info('AnalyzePreviousWebsiteJob: analyzed.', [
            'site_id' => $this->siteId,
            'ok' => $analysis['ok'],
            'accent' => $analysis['accent'] ?? null,
            'tiers' => array_filter($analysis['tiers'] ?? []),
        ]);

        // Logo auto-grab — fills EMPTY slots only; never blocks the styling path.
        if ($analysis['ok']) {
            try {
                $site = Site::query()->find($this->siteId);
                $pro = $site?->user_id ? User::query()->find($site->user_id) : null;
                if ($site !== null && $pro !== null) {
                    $grabber->grabIfEmpty($pro, $site, (array) ($analysis['logoCandidates'] ?? []));
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->dispatchResolve();
    }

    private function dispatchResolve(): void
    {
        $userId = Site::query()->whereKey($this->siteId)->value('user_id');
        if ($userId) {
            ResolveDesignPresetsJob::dispatch((string) $userId);
        }
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('design.previous_website.analyze.failed', [
            'site_id' => $this->siteId,
            'error' => $e->getMessage(),
        ]);
    }
}
