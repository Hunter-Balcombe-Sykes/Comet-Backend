<?php

namespace App\Jobs\PreAccount;

use App\Jobs\Concerns\ThrottlesPreAccountScraping;
use App\Jobs\Platforms\ThrottledByProvider;
use App\Models\Core\EarlyAccess\EarlyAccessSignup;
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

// Staff "allow claiming" (spec Flow 3): freshen the dark early-access site,
// open its 30-day claim window, and invite the person. Runs on the scraping
// lane; a bulk approval fans one job per signup — so it's per-vendor rate-limited
// via ThrottlesPreAccountScraping (an unthrottled approve-bulk was the concrete
// Apify-stampede trigger). That limiter RELEASES when over-budget, so tries=0
// (releases are governed by retryUntil, not a finite try count); maxExceptions=1
// + failOnTimeout fail a real error/timeout fast so an already-run scrape never
// re-bills. Idempotent-ish: re-approving re-scrapes and re-notifies (a resend).
class ApproveEarlyAccessBuildJob implements ShouldBeUnique, ShouldQueue, ThrottledByProvider
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ThrottlesPreAccountScraping;

    public int $timeout = 300;

    // Unlimited attempts, bounded by retryUntil() (in the trait) — a rate-limit
    // release counts as an attempt, so a finite $tries would fail on first throttle.
    public int $tries = 0;

    /** @var list<int> Backoff between exception-triggered retries (not rate-limit releases). */
    public array $backoff = [30];

    // Fail fast on a genuine error (first exception) — never retry a scrape that
    // may already have billed Apify.
    public int $maxExceptions = 1;

    // A mid-scrape timeout fails immediately rather than retrying (which would
    // re-bill) — the guarantee the old tries=1 carried, now that tries=0.
    public bool $failOnTimeout = true;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly string $signupId,
        public readonly string $sourceType,
    ) {
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
    }

    public function uniqueId(): string
    {
        return $this->signupId;
    }

    public function handle(SourceGeneratorRegistry $registry, ClaimNotifier $notifier): void
    {
        $signup = EarlyAccessSignup::find($this->signupId);
        if ($signup === null || $signup->user_id === null) {
            Log::info('early_access.approve.no_link', ['signup_id' => $this->signupId]);

            return;
        }

        $build = PreAccountBuild::where('user_id', $signup->user_id)->first();
        if ($build === null || $build->claimed_at !== null || $build->built_via !== PreAccountBuild::VIA_EARLY_ACCESS) {
            return;
        }

        $user = $build->user;
        $site = $user?->site;
        if ($user === null || $site === null) {
            return;
        }

        // Freshen: re-scrape IG (Apify) so the invited person sees current content
        // and the connection reactivates (seeder sets is_active=true); or heal a
        // build that failed at signup. A healthy GBP build is left alone — it stays
        // fresh on the official-API treadmill (spec §3.4).
        $needsScrape = $build->build_state === PreAccountBuild::STATE_FAILED
            || $build->source_type === 'instagram';

        if ($needsScrape) {
            $build->forceFill(['build_state' => PreAccountBuild::STATE_BUILDING])->save();
            try {
                $registry->for($build->source_type)->generate($user, $site, $build->source_ref);
            } catch (SourceGenerationException $e) {
                $build->forceFill(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => $e->failureCode])->save();
                Log::warning('early_access.approve.scrape_failed', ['build_id' => $build->id, 'failure_code' => $e->failureCode]);

                return;
            } catch (Throwable $e) {
                $build->forceFill(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => PreAccountBuild::FAILURE_SCRAPE_FAILED])->save();
                report($e);

                return;
            }
            $build->forceFill(['build_state' => PreAccountBuild::STATE_READY])->save();
        }

        // Open the 30-day claim window (this also lifts the "dark, unapproved"
        // IG-deactivation signal for any future re-generation).
        $build->forceFill(['expires_at' => now()->addDays((int) config('partna.pre_account.expiry_days', 30))])->save();

        $signup->forceFill(['status' => EarlyAccessSignup::STATUS_INVITED, 'invited_at' => now()])->save();

        // After the writes commit: invite the person to claim (email; DM stub).
        $notifier->notify($build->fresh());
    }
}
