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
            $build->update(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => PreAccountBuild::FAILURE_SCRAPE_FAILED]);

            return;
        }

        $build->update(['build_state' => PreAccountBuild::STATE_BUILDING]);

        try {
            $registry->for($build->source_type)->generate($user, $site, $build->source_ref);
        } catch (SourceGenerationException $e) {
            $build->update(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => $e->failureCode]);
            Log::info('pre_account.build_failed', ['build_id' => $build->id, 'failure_code' => $e->failureCode]);

            return;
        } catch (Throwable $e) {
            $build->update(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => PreAccountBuild::FAILURE_SCRAPE_FAILED]);
            report($e);

            return;
        }

        $build->update(['build_state' => PreAccountBuild::STATE_READY]);

        // Staff marketing builds go live immediately; the KV re-sync writes the
        // routing entry (with unclaimed TTL — see SyncSubdomainToKvJob) since
        // SiteObserver only auto-dispatches KV on create/subdomain-change.
        if ($this->publish) {
            $site->update(['is_published' => true]);
            SyncSubdomainToKvJob::dispatch($user->id);
        }
    }

    public function failed(Throwable $e): void
    {
        report($e);
        PreAccountBuild::query()->whereKey($this->buildId)
            ->update(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => PreAccountBuild::FAILURE_SCRAPE_FAILED]);
    }
}
