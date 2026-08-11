<?php

namespace App\Jobs\PreAccount;

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Jobs\Concerns\ThrottlesPreAccountScraping;
use App\Jobs\Platforms\ThrottledByProvider;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\PreAccountBuild;
use App\Services\PreAccount\ClaimNotifier;
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
// notification/cache queues (JOB-103 precedent). Per-vendor rate-limited via
// ThrottlesPreAccountScraping so a signup/approval burst can't stampede Apify or
// Places; that limiter RELEASES the job when over-budget, so tries=0 (releases
// are governed by retryUntil, not a finite try count). maxExceptions=1 +
// failOnTimeout keep the original "never re-bill the scrape" guarantee: a real
// error or a timeout fails fast (build_state='failed', prunable/re-servable),
// only a pre-run throttle release ever re-queues.
class GeneratePreAccountSiteJob implements ShouldBeUnique, ShouldQueue, ThrottledByProvider
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ThrottlesPreAccountScraping;

    // Apify up to 110s + media mirroring + Places headroom. Stays under the
    // redis_scraping connection's retry_after=660 (HorizonQueueCoverageTest).
    public int $timeout = 300;

    // Unlimited attempts, bounded by retryUntil() (in the trait) — a rate-limit
    // release counts as an attempt, so a finite $tries would fail the scrape the
    // first time it's throttled.
    public int $tries = 0;

    /** @var list<int> Backoff between exception-triggered retries (not rate-limit releases). */
    public array $backoff = [30];

    // A genuine error surfaces on the first exception (matching the old tries=1
    // behaviour) — never retrying, so a scrape that already ran can't re-bill.
    public int $maxExceptions = 1;

    // A mid-scrape timeout fails immediately rather than retrying (which would
    // re-bill Apify) — the guarantee the old tries=1 carried, now that tries=0.
    public bool $failOnTimeout = true;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly string $buildId,
        public readonly string $sourceType,
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
            // Auto-connect a discovered booking menu ONLY for a staff/ManyChat
            // build. Read off built_by_staff_id rather than the $publish flag:
            // publish is a presentation choice that could change, whereas
            // "a staff member built this for someone who is not here" is exactly
            // the condition that makes a picker impossible. A public site-first
            // signup has the person on the other end of the request and the
            // frontend asks them to pick, same as the dashboard.
            $registry->for($build->source_type)->generate(
                $user, $site, $build->source_ref, $build->built_by_staff_id !== null,
            );
        } catch (SourceGenerationException $e) {
            // SEC-4: build_state/failure_code are no longer fillable — forceFill so a
            // dropped write can't silently strand this build in the wrong state.
            $build->forceFill(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => $e->failureCode])->save();
            report($e);
            Log::warning('pre_account.build_failed', ['build_id' => $build->id, 'failure_code' => $e->failureCode]);

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
            IntegrationConnection::query()
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
            // Cold/marketing builds (Flow 2) go live immediately. auto_invite=false
            // publishes but defers the invite for manual review + send (spec §4).
            if ($build->auto_invite) {
                app(ClaimNotifier::class)->notify($build->fresh());
            }
        }
    }

    public function failed(Throwable $e): void
    {
        report($e);
        PreAccountBuild::query()->whereKey($this->buildId)
            ->update(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => PreAccountBuild::FAILURE_SCRAPE_FAILED]);
    }
}
