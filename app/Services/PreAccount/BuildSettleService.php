<?php

namespace App\Services\PreAccount;

use App\Mail\Account\WelcomeMail;
use App\Models\Core\User\PreAccountBuild;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// What happens when a build reaches a terminal outcome. Both entry points --
// the sweep and claim -- route through here so the two orderings (claim then
// settle, settle then claim) cannot drift apart.
class BuildSettleService
{
    public function __construct(
        private readonly BuildProgressReader $progress,
        private readonly ClaimNotifier $notifier,
        private readonly ConnectedPlatformNames $platforms,
    ) {}

    /** @return string One of BuildProgressReader::OUTCOME_* */
    public function evaluate(PreAccountBuild $build): string
    {
        $outcome = $this->progress->outcome(
            $build,
            $this->progress->eventsFor($build),
            $this->progress->mediaCountsFor($build),
        );

        if ($outcome === BuildProgressReader::OUTCOME_PENDING) {
            return $outcome;
        }

        if ($outcome !== BuildProgressReader::OUTCOME_SETTLED) {
            // Ceiling or failed: terminal, but not a thing to email about
            // (owner, 2026-09-03). Stamped so the sweep stops looking and
            // staff can find it -- builds:stalled reads this column.
            if ($build->setup_stalled_at === null) {
                $build->forceFill(['setup_stalled_at' => now()])->save();
                Log::warning('build.setup_stalled', [
                    'build_id' => (string) $build->id,
                    'user_id' => (string) $build->user_id,
                    'outcome' => $outcome,
                ]);
            }

            return $outcome;
        }

        if ($build->settled_at === null) {
            $build->forceFill(['settled_at' => now()])->save();
        }

        // Two gates, mutually exclusive by claim state, so a build never
        // matches both.
        if ($build->claimed_at !== null) {
            $this->welcomeIfDue($build->fresh());
        } elseif ((bool) $build->auto_invite && $this->isPublished($build)) {
            // The invite's own idempotency is invited_at, under ClaimNotifier's
            // advisory lock -- not re-implemented here.
            $this->notifier->notify($build->fresh());
        }

        return $outcome;
    }

    /**
     * Send the welcome if this build is settled, claimed and unwelcomed.
     *
     * Claim calls this too: the sweep is window-bounded, so a claim weeks
     * after settle would never be observed by it. Whichever of {claim, settle}
     * lands second sends; welcomed_at makes it exactly one.
     */
    public function welcomeIfDue(PreAccountBuild $build): bool
    {
        if ($build->settled_at === null || $build->claimed_at === null || $build->welcomed_at !== null) {
            return false;
        }

        // An orphaned build (the user row went away under it) has nobody to
        // welcome; the sweep logs and moves on rather than throwing.
        $user = $build->user;
        if ($user === null) {
            return false;
        }
        $email = (string) ($user->primary_email ?? '');
        $handle = (string) ($user->site->subdomain ?? '');
        if ($email === '' || $handle === '') {
            return false;
        }

        // Claim the send by stamping FIRST, conditionally, so a sweep tick
        // racing a claim cannot both pass the null check and both send.
        // Mirrors ClaimNotifier's invited_at discipline; delivery retries are
        // the mail queue's job, not ours.
        $claimed = DB::connection('pgsql')->table('core.pre_account_builds')
            ->where('id', $build->id)
            ->whereNull('welcomed_at')
            ->update(['welcomed_at' => now()]);

        if ($claimed === 0) {
            return false;
        }

        $build->setAttribute('welcomed_at', now());

        try {
            Mail::to($email)->queue(new WelcomeMail($email, $handle, $this->platforms->for($user)));
        } catch (\Throwable $e) {
            Log::warning('build.welcome_mail_failed', [
                'build_id' => (string) $build->id,
                'error' => $e->getMessage(),
            ]);
        }

        return true;
    }

    /**
     * Spec §6: the outreach gate is unclaimed AND published AND auto_invite.
     * The publish term used to be structural — the send sat inside
     * GeneratePreAccountSiteJob's `if ($this->publish)` block — so it has to
     * be restated now that the settle path owns the send.
     */
    private function isPublished(PreAccountBuild $build): bool
    {
        // A direct read, not `$build->user->site`: the settle sweep loads
        // builds bare and lazy loading is disabled app-wide, so the relation
        // walk threw LazyLoadingViolationException on every auto_invite build
        // (Nightwatch #512, 2026-09-04) — after settled_at was already
        // stamped, so the invite was silently never sent.
        return (bool) DB::table('site.sites')
            ->where('user_id', (string) $build->user_id)
            ->value('is_published');
    }
}
