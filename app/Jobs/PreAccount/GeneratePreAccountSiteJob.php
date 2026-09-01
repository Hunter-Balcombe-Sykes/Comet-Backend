<?php

namespace App\Jobs\PreAccount;

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Jobs\Concerns\ThrottlesPreAccountScraping;
use App\Jobs\Platforms\ThrottledByProvider;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\PreAccountBuild;
use App\Services\PreAccount\ClaimNotifier;
use App\Services\PreAccount\ContactFormSeeder;
use App\Services\PreAccount\PreAccountBuildService;
use App\Services\PreAccount\SourceGenerationException;
use App\Services\PreAccount\SourceGeneratorRegistry;
use App\Services\Site\SectionBlockProvisioner;
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

        // SEC-4: build_state is no longer fillable — forceFill so this transition
        // isn't a silent no-op.
        $build->forceFill(['build_state' => PreAccountBuild::STATE_BUILDING])->save();

        // ── Item 1a, phase one: verify the source BEFORE any identity exists.
        // A failed prefetch fails the build with NOTHING to clean up — no user,
        // no handle squatted, no site row, no KV route ever published. This is
        // structurally where the bydannydixon handle-retention class died.
        $generator = $registry->for($build->source_type);
        try {
            $prefetch = $generator->prefetch($build->source_ref, $build->source_name, $build->user_id);
        } catch (SourceGenerationException $e) {
            $build->forceFill(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => $e->failureCode])->save();
            report($e);
            Log::warning('pre_account.build_failed', ['build_id' => $build->id, 'failure_code' => $e->failureCode, 'phase' => 'prefetch']);
            $this->retireRouteIfAny($build);

            return;
        } catch (Throwable $e) {
            $build->forceFill(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => PreAccountBuild::FAILURE_SCRAPE_FAILED])->save();
            report($e);
            $this->retireRouteIfAny($build);

            return;
        }

        // ── Phase two: materialize the identity the verified source earned.
        // A re-run (failed-build re-serve, early-access re-generation) already
        // carries its user — allocation is once-per-build, never repeated.
        $user = $build->user;
        if (! $user) {
            try {
                $user = app(PreAccountBuildService::class)->materializeIdentity($build, $prefetch);
            } catch (Throwable $e) {
                $build->forceFill(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => PreAccountBuild::FAILURE_SCRAPE_FAILED])->save();
                report($e);

                return;
            }
        }
        $site = $user->site;
        if (! $site) {
            $build->forceFill(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => PreAccountBuild::FAILURE_SCRAPE_FAILED])->save();

            return;
        }

        try {
            // Auto-connect a discovered booking menu for EVERY pre-account build.
            // This read $build->built_by_staff_id !== null until 2026-08-19, on the
            // reasoning that a public site-first signup has the person on the other
            // end of the request and the frontend asks them to pick. That premise is
            // false: an unclaimed pre-account site renders publicly from the moment
            // it is built (the profiles route ignores is_published for 'unclaimed')
            // and may sit unclaimed until expires_at. Nobody is asked anything in the
            // meantime, so the Fresha connection landed selection-less and published
            // no services at all — F7 of the 2026-08-10 build wave, re-found
            // unchanged as R14 on 2026-08-19.
            //
            // This restores the scope the v3 design specified: its construction-site
            // table marks every Instagram-origin site true, with only the dashboard
            // paste false (docs/superpowers/specs/2026-08-10-fresha-auto-route-
            // selection-design.md:103-108). FreshaAutoSelector decides whose menu —
            // the account holder's when FreshaStaffMatcher identifies them, storewide
            // when it cannot. Storewide understates prices, which that design accepts
            // as the trade against publishing nothing, bounded by the owner
            // correcting it after claim (payload.autoSelected surfaces the guess).
            $generator->generate(
                $user, $site, $build->source_ref, true, $prefetch,
            );
        } catch (SourceGenerationException $e) {
            // SEC-4: build_state/failure_code are no longer fillable — forceFill so a
            // dropped write can't silently strand this build in the wrong state.
            $build->forceFill(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => $e->failureCode])->save();
            report($e);
            Log::warning('pre_account.build_failed', ['build_id' => $build->id, 'failure_code' => $e->failureCode]);

            // Take the route back DOWN. SiteObserver published this subdomain when
            // the site row was created, before this job ran, so a failed build
            // leaves a live public page carrying the person's name and nothing
            // else. SyncSubdomainToKvJob reconciles from build_state, which is
            // now 'failed', so this dispatch retires it (2026-08-30).
            SyncSubdomainToKvJob::dispatch($user->id);

            return;
        } catch (Throwable $e) {
            // SEC-4: build_state/failure_code are no longer fillable — forceFill so a
            // dropped write can't silently strand this build in the wrong state.
            $build->forceFill(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => PreAccountBuild::FAILURE_SCRAPE_FAILED])->save();
            report($e);

            // Take the route back DOWN. SiteObserver published this subdomain when
            // the site row was created, before this job ran, so a failed build
            // leaves a live public page carrying the person's name and nothing
            // else. SyncSubdomainToKvJob reconciles from build_state, which is
            // now 'failed', so this dispatch retires it (2026-08-30).
            SyncSubdomainToKvJob::dispatch($user->id);

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

        // T15 (issue 9): provision the section blocks the dashboard's GET
        // /api/sections would have seeded on first visit — an unclaimed site
        // has no dashboard visitor, so without this the workplace/public_contact
        // envelopes can never go live no matter what data the linker jobs write
        // ("linked but invisible", verified on barber-in-law 2026-08-27).
        // Idempotent, and later data arrival re-seeds is_enabled via the
        // WorkplaceObserver hook.
        // Non-fatal on purpose: block provisioning failing must never fail a
        // build that already generated its content — the WorkplaceObserver
        // hook and the claim-time dashboard sync both re-provision later.
        try {
            app(SectionBlockProvisioner::class)->syncAllowed(
                (string) $user->id,
                (string) $site->id,
                config('partna.section_block_types', []),
            );
        } catch (Throwable $e) {
            report($e);
            Log::warning('pre_account.section_blocks_provision_failed', [
                'user_id' => $user->id,
                'site_id' => $site->id,
                'message' => $e->getMessage(),
            ]);
        }

        // T20 (owner, 2026-08-27): the contact form is enabled by default on
        // unclaimed sites, routed to the public contact email when one exists.
        try {
            app(ContactFormSeeder::class)->seedForBuild($user->fresh());
        } catch (Throwable $e) {
            report($e);
        }

        // SEC-4: build_state is no longer fillable — forceFill so this transition
        // isn't a silent no-op.
        $build->forceFill(['build_state' => PreAccountBuild::STATE_READY])->save();

        // T17 (owner, 2026-08-27) + Item 9d (2026-09-01): partna accounts get
        // their Instagram profile picture as the `headshot` design singleton
        // (fill-empty only). Dispatched AFTER ready rather than run inline —
        // it reads only our own R2 mirror and nothing in the first render
        // depends on it, so it must not sit on the critical path.
        SeedHeadshotJob::dispatch((string) $user->id, (string) $site->id)->afterCommit();

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

        // Same reason as the in-handle failure arms: retire the route a failed
        // build must not keep. This path is the job dying outright (timeout,
        // attempts exhausted), so $user was never in scope — read the id off the
        // build row we just marked.
        $userId = PreAccountBuild::query()->whereKey($this->buildId)->value('user_id');
        if ($userId) {
            SyncSubdomainToKvJob::dispatch((string) $userId);
        }
    }

    /**
     * Item 1a: a pre-identity failure has no route to retire — the KV entry
     * only ever exists once materializeIdentity() created the site. A re-run
     * failure (user already bound from a prior attempt) retires exactly as
     * the post-generate failure paths always did.
     */
    private function retireRouteIfAny(PreAccountBuild $build): void
    {
        if ($build->user_id !== null) {
            SyncSubdomainToKvJob::dispatch((string) $build->user_id);
        }
    }
}
