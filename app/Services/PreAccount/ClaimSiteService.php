<?php

namespace App\Services\PreAccount;

use App\Jobs\Cache\WarmPublicSiteCacheJob;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Jobs\Platforms\RefreshConnectionJob;
use App\Mail\Account\WelcomeMail;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\Cache\SiteCacheService;
use App\Services\Cache\UserCacheService;
use App\Services\User\EmailReuseGuard;
use App\Services\User\SignupSideEffects;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

// First-come claim: binds a Supabase auth user (email OTP JWT) to a provisional
// unclaimed user by subdomain. Mirrors UserBootstrapService's discipline: pgsql-
// pinned transaction, lockForUpdate on the user row, savepoint-wrapped save with
// a 23505 → EMAIL_ALREADY_REGISTERED backstop (LIFE-101 pattern).
class ClaimSiteService
{
    public function __construct(
        private readonly EmailReuseGuard $emailGuard,
        private readonly SignupSideEffects $sideEffects,
        private readonly UserCacheService $userCache,
        private readonly SiteCacheService $siteCache,
        private readonly ClaimTokenIssuer $tokens,
    ) {}

    /**
     * @return array{professional: User, site: Site, is_new_claim?: bool}
     *
     * @throws RuntimeException CLAIM_NOT_FOUND|ALREADY_CLAIMED|BUILD_FAILED|ACCOUNT_EXISTS|EMAIL_ALREADY_REGISTERED|CLAIM_EMAIL_MISMATCH|CLAIM_NOT_INVITED
     */
    public function claim(string $uid, string $verifiedEmail, string $subdomain, bool $marketingOptIn = false, ?string $claimToken = null): array
    {
        $result = DB::connection('pgsql')->transaction(function () use ($uid, $verifiedEmail, $subdomain, $marketingOptIn, $claimToken) {
            $site = Site::query()->whereRaw('lower(subdomain) = ?', [strtolower(trim($subdomain))])->first();
            if (! $site) {
                throw new RuntimeException('CLAIM_NOT_FOUND');
            }

            $professional = User::query()->whereKey($site->user_id)->lockForUpdate()->first();
            if (! $professional) {
                throw new RuntimeException('CLAIM_NOT_FOUND');
            }

            // Idempotency FIRST: a double-tap / network retry by the rightful new
            // owner must return success, never 409 (spec §5.2).
            if ($professional->auth_user_id === $uid) {
                return ['professional' => $professional->fresh(), 'site' => $site->fresh()];
            }

            if (! $professional->isUnclaimed() || $professional->auth_user_id !== null) {
                throw new RuntimeException('ALREADY_CLAIMED');
            }

            // Claiming no longer waits on the build reaching 'ready' — pending
            // and building both claim fine; the dashboard (not this gate) is
            // what shows a "still processing" state and polls until ready.
            // Only a missing build row or a genuinely failed one blocks claim.
            $build = PreAccountBuild::query()->where('user_id', $professional->id)->lockForUpdate()->first();
            if (! $build || $build->build_state === PreAccountBuild::STATE_FAILED) {
                throw new RuntimeException('BUILD_FAILED');
            }

            // Invite-gate (owner decision, 2026-08-24). An OUTREACH build was
            // made FOR a business that has never heard of Partna — it carries
            // their name, photos and hours. The email-gate below only bites
            // when contact_email is set, and its "absent = first-come" arm was
            // written for self-serve builds, where the person claiming IS the
            // person who just built it. On an outreach row that same arm hands
            // a real business's site to whoever guesses the handle.
            //
            // So: an outreach build with nobody to invite is not claimable at
            // all until staff attach an address (StaffPreAccountBuildController
            // ::attachContactEmail). Self-serve builds are untouched.
            // A valid claim token IS proof of invitation (spec §6.2). It stands
            // in for contact_email on the outreach lane, which is what lets a
            // DM'd lead claim with no email ever entering the flow.
            $tokenOk = $this->tokens->matches($build, $claimToken);

            $contactEmail = trim((string) $build->contact_email);
            if ($build->isOutreach() && $contactEmail === '' && ! $tokenOk) {
                throw new RuntimeException('CLAIM_NOT_INVITED');
            }

            // NOTE the absence of $tokenOk here — deliberate (owner, 2026-08-25).
            // The token is NARROW: it proves INVITATION, not identity. A build
            // carrying a contact_email still requires that address, so a token
            // holder cannot claim an email-gated build with some other inbox.
            if ($contactEmail !== ''
                && strtolower(trim($verifiedEmail)) !== strtolower($contactEmail)) {
                throw new RuntimeException('CLAIM_EMAIL_MISMATCH');
            }

            // One account, one site: the claimer must not already own a row.
            if (User::query()->where('auth_user_id', $uid)->exists()) {
                throw new RuntimeException('ACCOUNT_EXISTS');
            }

            if ($this->emailGuard->isClaimedByAnotherAuthUser($verifiedEmail, $uid)) {
                throw new RuntimeException('EMAIL_ALREADY_REGISTERED');
            }

            $professional->auth_user_id = $uid; // not fillable — direct assignment
            $professional->primary_email = strtolower(trim($verifiedEmail));
            $professional->status = 'active';

            // display_name is populated by the source generator, but fall back to
            // the handle so auto-publish below can never be blocked by an empty name
            // (the UpdateSiteRequest publish guard doesn't run on this direct write).
            if (empty($professional->display_name)) {
                $professional->display_name = $professional->handle;
            }

            try {
                // Savepoint: a 23505 rollback must not poison the outer transaction
                // (partial unique index race on users_email_unique).
                DB::connection('pgsql')->transaction(fn () => $professional->save());
            } catch (UniqueConstraintViolationException $e) {
                // Deliberate post-23505 race backstop, not dead code: PHPStan only
                // accepts this re-check as live because of the @phpstan-impure tag
                // on EmailReuseGuard::isClaimedByAnotherAuthUser() — don't strip that tag.
                if ($this->emailGuard->isClaimedByAnotherAuthUser($verifiedEmail, $uid)) {
                    throw new RuntimeException('EMAIL_ALREADY_REGISTERED', 0, $e);
                }
                throw $e;
            }

            // SEC-4: claimed_at is no longer fillable — forceFill so a dropped write
            // can't leave the build re-servable forever (scopeLive() filters on it).
            // Single-use = USED, not opened (spec §4). Folded into the
            // claimed_at write rather than issued separately: this is the last
            // write in the claim, so the burn lands strictly AFTER every throw
            // above. That makes "a failed claim does not consume the lead's
            // link" structural, not dependent on transaction rollback.
            $build->forceFill(['claimed_at' => now()] + ($tokenOk ? $this->tokens->burn() : []))->save();

            // Auto-publish on claim (spec §3.3). Flow 2 sites are already published
            // (no-op); Flow 1 / early-access flip live here.
            if (! (bool) $site->is_published) {
                $site->is_published = true;
                $site->unpublished_at = null;
                // saveQuietly: the explicit post-commit block below already invalidates
                // cache + purges the edge for this handle — a plain save() would
                // double-dispatch via SiteObserver.
                $site->saveQuietly();
            }

            // Claim-time side effects moved from the retired bootstrap create branch.
            // PRIV-101: subscription is opt-in only — $marketingOptIn comes straight
            // from the claim request and defaults to false (fail-closed) upstream.
            $this->sideEffects->ensureSidestUpdatesSubscription($professional->primary_email, $marketingOptIn, 'claim');
            $isNewClaim = $this->sideEffects->createWelcomeNotification($professional) > 0;

            return ['professional' => $professional->fresh(), 'site' => $site->fresh(), 'is_new_claim' => $isNewClaim];
        });

        $userId = (string) $result['professional']->id;

        // Post-commit: bust caches and re-sync KV (status active → permanent
        // entry, clearing the unclaimed TTL). SyncSubdomainToKvJob remains the
        // single KV writer — this only dispatches it. Every step goes through
        // afterClaim() so a Redis blip can't report a committed claim as failed
        // (see its docblock).
        $this->afterClaim('cache.user', $userId, fn () => $this->userCache->invalidateUser($result['professional']));
        $this->afterClaim('cache.site', $userId, fn () => $this->siteCache->invalidateSite($result['site']));
        $this->afterClaim('kv.sync', $userId, fn () => SyncSubdomainToKvJob::dispatch($userId));
        $this->afterClaim('google_business.reenrich', $userId, fn () => $this->reEnrichClaimedGoogleBusinessConnection($result['professional']));

        // Welcome email — genuinely post-commit (the transaction above has
        // already committed by this point) and gated on is_new_claim so a
        // double-tap / network retry through the idempotency-first branch
        // (which never sets this flag) can never re-send it.
        if (($result['is_new_claim'] ?? false) === true) {
            $email = (string) ($result['professional']->primary_email ?? '');
            if ($email !== '') {
                try {
                    Mail::to($email)->queue(new WelcomeMail($email, (string) $result['site']->subdomain));
                } catch (\Throwable $e) {
                    Log::warning('claim.welcome_mail_failed', ['user_id' => $result['professional']->id, 'error' => $e->getMessage()]);
                }
            }
        }

        // EDGE-1: also purge the Cloudflare edge cache — invalidateSite() above
        // only busts Redis, so without this a claim's status flip never reaches
        // the edge (UserObserver::PUBLIC_PROFILE_USER_FIELDS deliberately excludes
        // 'status', so SiteObserver's own purge never fires for a claim). Mirrors
        // SiteObserver::saved()'s exact pattern: lowercased handle, custom domain
        // only when actually served. ->afterCommit() is a no-op here — we're
        // already outside the transaction closure — kept for convention
        // consistency with the observer. invalidateSite() (Redis-only) is not
        // touchSite() (which fires SiteObserver's own purge), so this does not
        // double-dispatch the purge job.
        $handle = strtolower(trim((string) $result['site']->subdomain));
        $customDomain = $result['site']->custom_domain_status === 'active' && $result['site']->custom_domain
            ? (string) $result['site']->custom_domain
            : null;
        if ($handle !== '') {
            $this->afterClaim('edge.purge', $userId, fn () => CloudflareCachePurgeJob::dispatch($handle, $customDomain)->afterCommit());

            // Pre-warm the freshly-published claimed site: saveQuietly() above
            // skipped SiteObserver::saved(), which would have dispatched this same
            // job when is_published flips true. Mirrors the observer's own gate.
            if ((bool) $result['site']->is_published) {
                $this->afterClaim('cache.warm', $userId, fn () => WarmPublicSiteCacheJob::dispatch($handle)->afterCommit());
            }
        }

        return $result;
    }

    /**
     * Runs one post-commit side effect without letting it fail the claim.
     *
     * Everything below the transaction runs against an ALREADY COMMITTED claim:
     * the account is bound, the build consumed, the site published. An exception
     * escaping from here reports a successful claim as a failure — the claimer
     * sees an error for an account they now own, and (worse) is likely to treat
     * it as "the claim didn't work". A Redis outage inside invalidateUser() is
     * enough to trigger it; surfaced by tests/Postgres/ClaimConcurrencyTest.php,
     * where an under-provisioned fixture made a genuinely successful claim
     * report 42P01.
     *
     * Swallowing is safe HERE specifically because the endpoint is idempotent:
     * a retry re-enters through claim()'s idempotency-first branch and re-runs
     * every step below it, including the KV dispatch the site's routing depends
     * on. report() keeps it visible in Nightwatch rather than silent — the same
     * CCH-101 discipline UserObserver applies to its own cache busts.
     *
     * Per-step isolation is the point: one wrapper around the whole block would
     * let a failed cache bust skip the KV sync behind it.
     */
    private function afterClaim(string $step, string $userId, callable $effect): void
    {
        try {
            $effect();
        } catch (\Throwable $e) {
            Log::warning('claim.post_commit_failed', [
                'step' => $step,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            report($e);
        }
    }

    /**
     * REV1: a pre-account build's google-business connection had its reviews
     * stripped (GoogleBusinessPayload::stripThirdPartyPii — correct for an
     * unclaimed listing nobody has proven ownership of). GoogleBusinessFetch
     * already re-checks live claimed status on every scheduled refresh and
     * stops stripping the moment it sees 'active' — but that refresh's own
     * 40h detailsFetchedAt freshness cache was stamped at BUILD time by the
     * same mapping function, so a claim inside that window (the common case)
     * makes the next scheduled refresh a no-op cache hit, not a real
     * re-fetch. Force it: clear detailsFetchedAt so the freshness check fails
     * open, then dispatch the SAME RefreshConnectionJob the hourly cron and
     * the dashboard's manual refresh button already use (manual: true gives
     * it its own dedup lane, matching RefreshController's identical pattern)
     * — reusing GoogleBusinessFetch's existing fetch/strip/gate/lock logic
     * rather than duplicating it in a new job.
     */
    private function reEnrichClaimedGoogleBusinessConnection(User $professional): void
    {
        $connection = IntegrationConnection::query()
            ->where('user_id', $professional->id)
            ->where('platform', 'google-business')
            ->where('is_active', true)
            ->first();

        if ($connection === null) {
            return;
        }

        $payload = $connection->payload;
        unset($payload['detailsFetchedAt']);
        $connection->updateQuietly(['payload' => $payload, 'last_refresh_status' => 'pending']);

        // afterCommit() is a no-op from here (this already runs past the claim
        // transaction) and that is the point: it makes the ordering structural.
        // GoogleBusinessFetch's PRIV-2 unset is DESTRUCTIVE — a worker that picked
        // this up before status='active' committed would read 'unclaimed' and
        // permanently retire the findings of someone who just claimed. Queue
        // connections all set after_commit=false, so without this the guarantee is
        // only the position of this call.
        RefreshConnectionJob::dispatch($connection->id, 'google-business', manual: true)->afterCommit();
    }
}
