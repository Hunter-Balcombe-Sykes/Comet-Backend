<?php

namespace App\Jobs\PreAccount;

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Jobs\Concerns\ThrottlesPreAccountScraping;
use App\Jobs\Platforms\ThrottledByProvider;
use App\Models\Core\EarlyAccess\EarlyAccessSignup;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\PreAccountBuild;
use App\Services\PreAccount\PreAccountBuildService;
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
        /** Staff who approved — stamped onto a build created here, so the claim
         *  invite-gate (PreAccountBuild::isOutreach) recognises it as outreach. */
        public readonly ?string $approvedByStaffId = null,
    ) {
        $this->onQueue(config('partna.queues.signup', 'signup'));
    }

    public function uniqueId(): string
    {
        return $this->signupId;
    }

    public function handle(
        SourceGeneratorRegistry $registry,
        PreAccountBuildService $builds,
    ): void {
        $signup = EarlyAccessSignup::find($this->signupId);
        if ($signup === null) {
            Log::info('early_access.approve.no_link', ['signup_id' => $this->signupId]);

            return;
        }

        // CREATE the build if the signup has none. Until 2026-08-24 the public
        // marketing form built one synchronously and this job only FRESHENED
        // it — but that form was a permanent handle squat (anonymous, no IP
        // cap, expires_at NULL and therefore never pruned), so it now captures
        // the lead only. That made this job a silent no-op for every row
        // created since: staff got 202 {ok:true}, the early-return below fired,
        // and nothing was ever built or claimable.
        //
        // Building HERE is safe where doing it on the public form was not:
        // this runs behind staff.admin + staffManage, so a human has decided
        // this lead should get a site, and `staff` is passed so the row carries
        // built_by_staff_id — which is what PreAccountBuild::isOutreach() reads
        // and what makes the claim invite-gate apply to it.
        if ($signup->user_id === null) {
            if (empty($signup->source_type) || empty($signup->source_ref)) {
                Log::info('early_access.approve.no_source', ['signup_id' => $signup->id]);

                return;
            }
            // Resolved from the id rather than serialised as a model: the job
            // may run long after dispatch, and a stale serialised staff row is
            // worse than a null one (null only loses the outreach stamp).
            $approvingStaff = $this->approvedByStaffId !== null
                ? PartnaStaff::find($this->approvedByStaffId)
                : null;

            try {
                $result = $builds->requestBuild(
                    accountType: $signup->type,
                    sourceType: $signup->source_type,
                    rawSourceRef: $signup->source_ref,
                    sourceName: null,
                    ipHash: null,
                    staff: $approvingStaff,
                    publish: true,
                    expiresDays: null,
                    contactEmail: $signup->email_lc,
                    builtVia: PreAccountBuild::VIA_EARLY_ACCESS,
                );
            } catch (Throwable $e) {
                Log::warning('early_access.approve.build_failed', [
                    'signup_id' => $signup->id, 'error' => $e->getMessage(),
                ]);
                report($e);
                // #JOB-3: the build did not happen, so Horizon must not record
                // this as processed — staff approved an invite that will never
                // be sent. report() alone reaches Nightwatch but leaves the
                // queue signal saying "fine". report() is KEPT alongside:
                // Job::fail() skips failed() entirely when the job is already
                // deleted, so it is not a guaranteed reporting path.
                $this->fail($e);

                return;
            }

            // Same collision guard the public path used to carry: the dedupe in
            // requestBuild re-serves ANY live build for this source ref. Linking
            // a signup/staff row here would leave the site NOT email-gated.
            if ($result['build']->built_via !== PreAccountBuild::VIA_EARLY_ACCESS) {
                Log::warning('early_access.approve.build_collision', [
                    'signup_id' => $signup->id,
                    'built_via' => $result['build']->built_via,
                ]);
                // Deliberately NOT $this->fail() (#JOB-3): a collision means a
                // live build for this source already exists, which is a
                // legitimate no-op, not a failure. Its three sibling paths above
                // and below DO fail the job.

                return;
            }

            $signup->forceFill(['user_id' => $result['build']->user_id])->save();
            $signup->refresh();
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
                // TRUE for the same reason as GeneratePreAccountSiteJob: an approved
                // early-access build creates a public unclaimed site with no human
                // present to answer "whose menu is this?". Taking the `false` default
                // here would have left this lane on the pre-2026-08-19 behaviour.
                $registry->for($build->source_type)->generate($user, $site, $build->source_ref, true);
            } catch (SourceGenerationException $e) {
                $build->forceFill(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => $e->failureCode])->save();
                report($e);
                Log::warning('early_access.approve.scrape_failed', ['build_id' => $build->id, 'failure_code' => $e->failureCode]);
                // #JOB-3: the invitee would get an empty site. Staff must see it.
                $this->fail($e);

                return;
            } catch (Throwable $e) {
                $build->forceFill(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => PreAccountBuild::FAILURE_SCRAPE_FAILED])->save();
                report($e);
                // #JOB-3: an unclassified fault is the clearest case of all.
                $this->fail($e);

                return;
            }
            $build->forceFill(['build_state' => PreAccountBuild::STATE_READY])->save();
        }

        // Open the 30-day claim window (this also lifts the "dark, unapproved"
        // IG-deactivation signal for any future re-generation).
        $build->forceFill(['expires_at' => now()->addDays((int) config('partna.pre_account.expiry_days', 30))])->save();

        // …and re-sync the route, because until this line the build had NO
        // expiry and SyncSubdomainToKvJob reads a null expires_at as gone
        // ("expired (or buildless) unclaimed — treat as gone"). Two deliberate
        // designs disagreeing: this lane sets expires_at NULL on purpose so an
        // unapproved build is never pruned, while the KV job takes the same
        // null as a signal to retire.
        //
        // Every KV sync before this point — SiteObserver's on site creation,
        // and GeneratePreAccountSiteJob's on success — therefore ran while the
        // expiry was still null and RETIRED the handle. Nothing re-dispatched
        // afterwards: this job never referenced SyncSubdomainToKvJob at all and
        // PreAccountBuild has no observer. So the claim window opened onto a
        // subdomain that did not resolve, and the invite below pointed at a
        // site the person could not see.
        //
        // Found 2026-08-30 by a fleet-wide sweep: 156 of 161 unclaimed sites
        // answered 200 and 3 answered 404 correctly (failed builds), leaving
        // exactly two anomalies — `business` and `business1`, both READY, both
        // 404, and both built_via=early_access with a null expires_at.
        SyncSubdomainToKvJob::dispatch((string) $build->user_id);

        $signup->forceFill(['status' => EarlyAccessSignup::STATUS_INVITED, 'invited_at' => now()])->save();

        // The invite is NOT sent here (2026-09-03), for the same reason it left
        // GeneratePreAccountSiteJob: build_state=ready means the site exists,
        // not that the cascade that fills it has finished. builds:settle-sweep
        // sends it once the build actually settles.
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('early_access.approve.failed', [
            'signup_id' => $this->signupId,
            'source_type' => $this->sourceType,
            'error' => $e->getMessage(),
        ]);
    }
}
