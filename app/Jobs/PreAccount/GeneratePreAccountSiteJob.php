<?php

namespace App\Jobs\PreAccount;

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\User\PreAccountBuild;
use App\Services\PreAccount\SourceGenerationException;
use App\Services\PreAccount\SourceGeneratorRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

// Populates a provisional user's site from its source (scrape/Places) on the
// scraping lane — a ManyChat marketing blast must never starve user-facing
// notification/cache queues (JOB-103 precedent). tries=1: a re-run re-bills the
// Apify scrape; failures surface as build_state='failed' (prunable, retryable
// via the dedupe re-serve path).
class GeneratePreAccountSiteJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Apify up to 110s + media mirroring + Places headroom. Stays under the
    // redis_scraping connection's retry_after=660 (HorizonQueueCoverageTest).
    public int $timeout = 300;

    public int $tries = 1;

    /** @var list<int> */
    public array $backoff = [30];

    public int $uniqueFor = 600;

    public function __construct(
        public readonly string $buildId,
        public readonly bool $publish = false,
    ) {
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
    }

    public function uniqueId(): string
    {
        return $this->buildId;
    }

    public function handle(SourceGeneratorRegistry $registry): void
    {
        $build = PreAccountBuild::find($this->buildId);
        if (! $build || $build->claimed_at !== null || $build->build_state === PreAccountBuild::STATE_READY) {
            return;
        }

        $user = $build->user;
        $site = $user?->site;
        if (! $user || ! $site) {
            // SEC-4: build_state/failure_code are no longer fillable — forceFill so a
            // dropped write can't silently strand this build in the wrong state.
            $build->forceFill(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => PreAccountBuild::FAILURE_SCRAPE_FAILED])->save();

            return;
        }

        // SEC-4: build_state is no longer fillable — forceFill so this transition
        // isn't a silent no-op.
        $build->forceFill(['build_state' => PreAccountBuild::STATE_BUILDING])->save();

        try {
            $registry->for($build->source_type)->generate($user, $site, $build->source_ref);
        } catch (SourceGenerationException $e) {
            // SEC-4: build_state/failure_code are no longer fillable — forceFill so a
            // dropped write can't silently strand this build in the wrong state.
            $build->forceFill(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => $e->failureCode])->save();
            Log::info('pre_account.build_failed', ['build_id' => $build->id, 'failure_code' => $e->failureCode]);

            return;
        } catch (Throwable $e) {
            // SEC-4: build_state/failure_code are no longer fillable — forceFill so a
            // dropped write can't silently strand this build in the wrong state.
            $build->forceFill(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => PreAccountBuild::FAILURE_SCRAPE_FAILED])->save();
            report($e);

            return;
        }

        // Keep dark, unapproved early-access Instagram builds OFF the Apify refresh
        // treadmill (spec §3.4): a site nobody has claimed must not be re-scraped via
        // Apify on the ~12h cadence. GBP (official Places API) stays active. The
        // signal is expires_at IS NULL = not yet approved; approval sets expires_at
        // and re-scrapes, which the seeder reactivates.
        if ($build->built_via === PreAccountBuild::VIA_EARLY_ACCESS
            && $build->source_type === 'instagram'
            && $build->expires_at === null) {
            \App\Models\Core\Site\IntegrationConnection::query()
                ->where('user_id', $user->id)
                ->where('platform', 'instagram')
                ->update(['is_active' => false]);
        }

        // SEC-4: build_state is no longer fillable — forceFill so this transition
        // isn't a silent no-op.
        $build->forceFill(['build_state' => PreAccountBuild::STATE_READY])->save();

        // Staff marketing builds go live immediately; the KV re-sync writes the
        // routing entry (with unclaimed TTL — see SyncSubdomainToKvJob) since
        // SiteObserver only auto-dispatches KV on create/subdomain-change.
        if ($this->publish) {
            $site->update(['is_published' => true]);
            SyncSubdomainToKvJob::dispatch($user->id);
            // Cold/marketing builds (Flow 2) go live immediately — invite the
            // person to claim via whatever channels we have (spec §3.1). Early-
            // access builds are unpublished here, so they never notify from this
            // path; their invite fires at staff approval instead.
            app(\App\Services\PreAccount\ClaimNotifier::class)->notify($build->fresh());
        }
    }

    public function failed(Throwable $e): void
    {
        report($e);
        PreAccountBuild::query()->whereKey($this->buildId)
            ->update(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => PreAccountBuild::FAILURE_SCRAPE_FAILED]);
    }
}
