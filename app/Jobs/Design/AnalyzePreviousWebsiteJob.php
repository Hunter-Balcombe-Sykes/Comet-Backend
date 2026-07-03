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

        // Reconciliation idempotence: a SUCCESSFUL current-version analysis for
        // this exact URL exists — nothing to do. Failed docs are retried when
        // the job is explicitly dispatched (backfill) — loop-safe because the
        // observer's in-sync check treats a failed current doc as in-sync and
        // never re-dispatches it on saves.
        $current = $workplace->previous_website_analysis;
        if (is_array($current)
            && ($current['url'] ?? null) === $url
            && ($current['v'] ?? null) === WebsiteStyleAnalyzer::VERSION
            && ($current['ok'] ?? false) === true) {
            return;
        }

        // Guard ONLY the analyze() call — an uncaught throw (network blip,
        // analyzer bug) would otherwise exhaust retries and leave the stored
        // analysis stale, so WorkplaceObserver::reconcile() (url+v match only,
        // it doesn't check ok) never sees it as in-sync and re-dispatches on
        // every save — a storm. Negatively-cache instead: store an ok:false
        // doc matching WebsiteStyleAnalyzer::doc()'s shape so the URL+version
        // read as in-sync and stop the storm. PreviousWebsiteFactor::usable()
        // requires ok===true, so the failed doc contributes nothing. The save()
        // below stays unguarded so a transient DB error still retries via
        // $tries/$backoff.
        try {
            $analysis = $analyzer->analyze($url);
        } catch (Throwable $e) {
            report($e);
            $analysis = [
                'v' => WebsiteStyleAnalyzer::VERSION,
                'url' => $url,
                'finalUrl' => null,
                'ok' => false,
                'mode' => 'none',
                'failure' => 'exception',
                'analyzedAt' => now()->toIso8601String(),
                'accent' => null,
                'signals' => [],
                'logo' => ['candidates' => []],
                'notes' => [$e->getMessage()],
            ];
            $workplace->previous_website_analysis = $analysis;
            $workplace->save();
            $this->dispatchResolve();

            return;
        }

        $workplace->previous_website_analysis = $analysis;
        $workplace->save();

        Log::info('AnalyzePreviousWebsiteJob: analyzed.', [
            'site_id' => $this->siteId,
            'ok' => $analysis['ok'],
            'mode' => $analysis['mode'] ?? null,
            'failure' => $analysis['failure'] ?? null,
            'accent' => $analysis['accent']['hex'] ?? null,
            'signals' => array_map(fn (array $s) => $s['tier'], $analysis['signals'] ?? []),
        ]);

        // Logo auto-grab — fills EMPTY slots only; never blocks the styling
        // path. Decisions are merged back onto the stored analysis so every
        // grab (or rejection) is auditable. The extra save is loop-safe: the
        // observer's in-sync check (url + version match) short-circuits.
        if ($analysis['ok']) {
            try {
                $site = Site::query()->find($this->siteId);
                $pro = $site?->user_id ? User::query()->find($site->user_id) : null;
                if ($site !== null && $pro !== null) {
                    $decisions = $grabber->grabIfEmpty($pro, $site, (array) ($analysis['logo']['candidates'] ?? []));
                    if ($decisions !== []) {
                        $analysis['logo']['grab'] = $decisions;
                        $workplace->previous_website_analysis = $analysis;
                        $workplace->save();
                    }
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
