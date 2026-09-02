<?php

namespace App\Services\PreAccount;

use App\Jobs\Cache\WarmPublicSiteCacheJob;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Jobs\Platforms\RefreshConnectionJob;
use App\Mail\Account\WelcomeMail;
use App\Models\Core\Notifications\Notification;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\Cache\SiteCacheService;
use App\Services\Cache\UserCacheService;
use App\Services\Site\SubdomainAvailabilityService;
use App\Services\User\EmailReuseGuard;
use App\Services\User\SignupSideEffects;
use App\Services\User\StaffProvisioningGuard;
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
        private readonly StaffProvisioningGuard $staffGuard,
        private readonly SubdomainAvailabilityService $availability,
    ) {}

    /**
     * @return array{professional: User, site: Site, is_new_claim?: bool}
     *
     * @throws RuntimeException CLAIM_NOT_FOUND|ALREADY_CLAIMED|BUILD_FAILED|ACCOUNT_EXISTS|EMAIL_ALREADY_REGISTERED|CLAIM_EMAIL_MISMATCH|CLAIM_NOT_INVITED|STAFF_ACCOUNT_NO_PROFILE
     */
    public function claim(string $uid, string $verifiedEmail, string $subdomain, bool $marketingOptIn = false, ?string $claimToken = null, array $profile = []): array
    {
        $result = DB::connection('pgsql')->transaction(function () use ($uid, $verifiedEmail, $subdomain, $marketingOptIn, $claimToken, $profile) {
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

            // Invite-gate (owner decision, 2026-08-24; widened #W2-SEC-1). An
            // OUTREACH build was made FOR a business that has never heard of
            // Partna — it carries their name, photos and hours. The
            // email-gate below only bites when contact_email is set, and its
            // "absent = first-come" arm was written for self-serve builds,
            // where the person claiming IS the person who just built it. On
            // an outreach row that same arm hands a real business's site to
            // whoever guesses the handle.
            //
            // A valid claim token IS proof of invitation (spec §6.2). It
            // stands in for contact_email, which is what lets a DM'd lead
            // claim with no email ever entering the flow.
            $tokenOk = $this->tokens->matches($build, $claimToken);
            $contactEmail = trim((string) $build->contact_email);

            // Lane-agnostic proof gate (#W2-SEC-1). isOutreach() is no longer
            // the right predicate to gate ON: per CLAUDE.md its "only from a
            // staff-authenticated write" premise is already dead — the
            // ManyChat webhook also mints VIA_STAFF with no staff row — and
            // entitlement was never actually about who created the row, only
            // about whether the claimer can prove they're the intended
            // owner. A self-serve (VIA_SIGNUP) build with no contact_email
            // and no token has exactly the same "nobody to invite" shape as
            // an outreach one; #W2-SEC-1 is that gap (17 such builds on dev
            // with zero ownership check). Behind a flag because enforcing it
            // today 404s every existing self-serve claimer until the claim
            // page persists and forwards claim_token — mint-first,
            // enforce-later.
            if ((bool) config('partna.pre_account.require_claim_proof', false)
                && $contactEmail === '' && ! $tokenOk) {
                throw new RuntimeException('CLAIM_NOT_INVITED');
            }

            // Legacy outreach arm (owner decision, 2026-08-24), unconditional
            // — deliberately NOT folded into the flag above. This must keep
            // firing even while require_claim_proof is false, or turning the
            // flag off would re-open the outreach lane that has been gated
            // since 2026-08-24: an outreach build with nobody to invite is
            // not claimable at all until staff attach an address
            // (StaffPreAccountBuildController::attachContactEmail). The flag
            // can only ADD restriction (self-serve), never remove it.
            if ($build->isOutreach() && $contactEmail === '' && ! $tokenOk) {
                throw new RuntimeException('CLAIM_NOT_INVITED');
            }

            // Email-gate (spec §3.2): a build carrying a contact_email may only
            // be claimed by someone who verified control of THAT inbox via
            // Supabase OTP. Case-insensitive.
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

            // ...and a staff account must own NO site. This is the live lane —
            // bootstrap's create branch is HTTP-dead behind 410 SIGNUP_MOVED, so
            // claim is the one way an auth user still acquires a professional
            // profile over HTTP. Inside the lockForUpdate above, ahead of the
            // bind.
            $this->staffGuard->assertMayHoldProfile($uid, 'claim');

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

            // A.8 (decision 8): the sign-up flow's own answers land inside the
            // same transaction as the binds — the person chose a handle and
            // typed their names minutes ago; the claim is where they stick.
            $this->applyClaimProfile($professional, $site, $profile);

            // #W1-PRIV-1 commit 3: this is the only place a verified human email
            // gets bound to an account (bootstrap's create branch is HTTP-dead —
            // 410 SIGNUP_MOVED), so it's the one place a waitlist row can be
            // linked going forward. status = 'waitlist' is load-bearing and must
            // NOT be relaxed to invited/signed_up: verified mailbox control TODAY
            // is not proof of authorship YESTERDAY, and stamping a past-waitlist
            // row would launder DataExportPayloadBuilder::streamEarlyAccessSignups()'s
            // bucket C (unowned, email-matched, past waitlist — exported redacted)
            // into bucket A (owned — exported in full) on first claim, defeating
            // the redaction that ownership rule exists to enforce. Best-effort,
            // matching the ContactFormSeeder pattern below: a bookkeeping link
            // must never fail a claim.
            //
            // Savepoint, not a bare try/catch: early_access_signups_user_id_unique
            // is a real partial UNIQUE, and an account that already owns a linked
            // row (ApproveEarlyAccessBuildJob) hitting a second waitlist row under
            // its claimed address raises 23505. On Postgres a caught error still
            // leaves the surrounding transaction aborted, so a bare catch here
            // would swallow the exception and then kill every write below it —
            // ContactFormSeeder, the publish flip, the claimed_at burn. The
            // SQLite lane cannot see this: it has neither the partial index nor
            // Postgres's abort-on-error semantics. Same guard, same reason, as
            // the $professional->save() savepoint above.
            try {
                DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')
                    ->table('core.early_access_signups')
                    ->where('email_lc', $professional->primary_email)
                    ->whereNull('user_id')
                    ->where('status', 'waitlist')
                    ->update(['user_id' => $professional->id, 'updated_at' => now()]));
            } catch (\Throwable $e) {
                report($e);
            }

            // T20 (owner, 2026-08-27): an auto-seeded or empty contact-form
            // notification email defaults to the freshly-bound account email;
            // an owner-typed address is never touched. Best-effort — a block
            // fault must never fail a claim.
            try {
                app(ContactFormSeeder::class)->applyClaimDefault($professional);
            } catch (\Throwable $e) {
                report($e);
            }

            // Auto-publish on claim (spec §3.3). Flow 2 sites are already published
            // (no-op); Flow 1 / early-access flip live here. Whether THIS claim
            // performed the flip is recorded on the build (T28, issue 22) so
            // release() can restore the exact pre-claim publish state instead
            // of guessing from the build type.
            $publishedByClaim = ! (bool) $site->is_published;
            if ($publishedByClaim) {
                $site->is_published = true;
                $site->unpublished_at = null;
                // saveQuietly: the explicit post-commit block below already invalidates
                // cache + purges the edge for this handle — a plain save() would
                // double-dispatch via SiteObserver.
                $site->saveQuietly();
            }

            // SEC-4: claimed_at is no longer fillable — forceFill so a dropped write
            // can't leave the build re-servable forever (scopeLive() filters on it).
            // Single-use = USED, not opened (spec §4). Folded into the
            // claimed_at write rather than issued separately: this is the last
            // write in the claim, so the burn lands strictly AFTER every throw
            // above. That makes "a failed claim does not consume the lead's
            // link" structural, not dependent on transaction rollback.
            $build->forceFill([
                'claimed_at' => now(),
                'published_by_claim' => $publishedByClaim,
            ] + ($tokenOk ? $this->tokens->burn() : []))->save();

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
     * Staff release: undo a claim WITHOUT destroying the built site.
     *
     * The only pre-existing recovery was adminPurgeNow() (force-delete), which
     * takes the scraped site with it and forces the rightful owner to rebuild.
     * This returns the row to the state claim() found it in, so the owner can
     * claim through the normal public endpoint.
     *
     * @return array{professional: User, build: PreAccountBuild}
     *
     * @throws RuntimeException NOT_CLAIMED|NOT_PRE_ACCOUNT
     */
    public function release(User $professional): array
    {
        $result = DB::connection('pgsql')->transaction(function () use ($professional) {
            // Same lock claim() takes: a release racing a claim must serialise,
            // or the release can land between claim()'s check and its write and
            // leave a bound auth_user_id sitting on an 'unclaimed' row.
            $locked = User::query()->whereKey($professional->id)->lockForUpdate()->first();
            if (! $locked) {
                throw new RuntimeException('NOT_PRE_ACCOUNT');
            }

            $build = PreAccountBuild::query()->where('user_id', $locked->id)->lockForUpdate()->first();
            if (! $build) {
                throw new RuntimeException('NOT_PRE_ACCOUNT');
            }

            if ($locked->auth_user_id === null) {
                throw new RuntimeException('NOT_CLAIMED');
            }

            // auth_user_id / primary_email are not fillable — direct assignment,
            // matching claim()'s own convention.
            $locked->auth_user_id = null;
            $locked->primary_email = null;
            $locked->status = 'unclaimed';
            $locked->save();

            // SEC-4: claimed_at is not fillable. Nulling it is what returns the
            // build to scopeLive() — without it the build is neither claimable
            // nor prunable, so a released site would be permanently stranded.
            $build->forceFill(['claimed_at' => null])->save();

            // claim() gates the welcome EMAIL on createWelcomeNotification()
            // returning > 0, and that insertOrIgnore dedupes on
            // (user_id, dedupe_key). The user_id SURVIVES a release — the
            // provisional row is reused, not recreated — so a surviving welcome
            // row makes the next claim return 0 and the rightful owner silently
            // never receives a welcome email. Delete it to re-arm the next claim.
            //
            // Not covered by a SQLite-lane assertion on Mail: the stand-in
            // `notifications` table has no unique index on dedupe_key, so
            // insertOrIgnore never conflicts there. Asserted on the row instead.
            Notification::query()
                ->where('user_id', $locked->id)
                ->where('dedupe_key', 'welcome:'.$locked->id)
                ->delete();

            // Restore the pre-claim publish state — EXACTLY (T28, issue 22).
            // claim() records whether IT performed the publish flip
            // (published_by_claim), so release unpublishes precisely when the
            // claim published, and never otherwise. This replaces the old
            // `! isOutreach()` heuristic, which assumed outreach builds are
            // provisioned published — but publish intent is a requestBuild()
            // PARAM that only rides the job dispatch, so a staff/outreach
            // build CAN be provisioned unpublished (the whole 2026-08-27 test
            // fleet was), and releasing a claim on one left is_published=true
            // on an unclaimed row: more exposed than before the claim, owned
            // by nobody — PublicSiteResolver gates on is_published.
            $site = Site::query()->where('user_id', $locked->id)->first();
            if ($site && (bool) $build->published_by_claim && (bool) $site->is_published) {
                $site->is_published = false;
                $site->unpublished_at = now();
                // saveQuietly for the same reason claim() does: the post-commit
                // block below busts cache and purges the edge for this handle,
                // so a plain save() would double-dispatch via SiteObserver.
                $site->saveQuietly();
            }
            $build->forceFill(['published_by_claim' => false])->save();

            return ['professional' => $locked->fresh(), 'build' => $build->fresh(), 'site' => $site?->fresh()];
        });

        // Post-commit, mirroring claim()'s lanes — same per-step isolation so a
        // Redis blip cannot report an already-committed release as a failure.
        $userId = (string) $result['professional']->id;

        $this->afterClaim('cache.user', $userId, fn () => $this->userCache->invalidateUser($result['professional']), 'release');

        if ($result['site']) {
            $this->afterClaim('cache.site', $userId, fn () => $this->siteCache->invalidateSite($result['site']), 'release');
        }

        // SyncSubdomainToKvJob is the ONLY KV writer. The claim turned this
        // handle's KV entry into a PERMANENT one (status 'active'); the release
        // must put it back to the unclaimed pointer with its expiry TTL, or a
        // released site keeps a permanent routing entry it is no longer entitled
        // to and outlives its own build expiry.
        $this->afterClaim('kv.sync', $userId, fn () => SyncSubdomainToKvJob::dispatch($userId), 'release');

        // EDGE-1, same reasoning as claim(): the status flip is invisible to
        // SiteObserver (PUBLIC_PROFILE_USER_FIELDS excludes 'status'), so
        // without this the squatter's cached payload survives at the edge.
        $handle = strtolower(trim((string) ($result['site']->subdomain ?? '')));
        if ($handle !== '') {
            $customDomain = $result['site']->custom_domain_status === 'active' && $result['site']->custom_domain
                ? (string) $result['site']->custom_domain
                : null;
            $this->afterClaim('edge.purge', $userId, fn () => CloudflareCachePurgeJob::dispatch($handle, $customDomain), 'release');
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
    private function afterClaim(string $step, string $userId, callable $effect, string $event = 'claim'): void
    {
        try {
            $effect();
        } catch (\Throwable $e) {
            Log::warning($event.'.post_commit_failed', [
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

    /**
     * The sign-up flow's answers, applied at claim (A.8, decision 8). Every
     * field is optional — the ManyChat claim page sends none of them.
     *
     * @param  array{handle?: ?string, first_name?: ?string, last_name?: ?string, display_name?: ?string, sector?: ?string}  $profile
     */
    private function applyClaimProfile(User $professional, Site $site, array $profile): void
    {
        $handle = strtolower(trim((string) ($profile['handle'] ?? '')));
        if ($handle !== '') {
            $this->assignHandle($professional, $site, $handle);
        }

        foreach (['first_name', 'last_name'] as $field) {
            $value = trim((string) ($profile[$field] ?? ''));
            if ($value !== '') {
                $professional->{$field} = $value;
            }
        }
        $displayName = trim((string) ($profile['display_name'] ?? ''));
        if ($displayName !== '') {
            $professional->display_name = $displayName;
        }

        // Stamped 'manual' ONLY when the answer differs from the stored value:
        // confirming a scraped guess must not upgrade its provenance and lock
        // the Google refiner out (SectorProvenance ladder).
        $sector = trim((string) ($profile['sector'] ?? ''));
        if ($sector !== '' && $sector !== (string) $professional->sector) {
            $professional->sector = $sector;
            $professional->sector_source = 'manual';
        }

        if ($professional->isDirty()) {
            $professional->save();
        }
    }

    /**
     * First handle set (A.8): lock the site row, availability-check against
     * everyone else, and write subdomain + handle/handle_lc together — the
     * RenameSubdomainAction sync block with its had-a-handle gate removed.
     * No alias and no cooldown stamp: a sign-up build's generated subdomain
     * was never routable (SyncSubdomainToKvJob withholds it pre-claim), so
     * there is nothing to redirect from.
     */
    private function assignHandle(User $professional, Site $site, string $incoming): void
    {
        Site::query()->whereKey($site->id)->lockForUpdate()->get();

        if ($incoming !== strtolower((string) $site->subdomain)) {
            $check = $this->availability->check($incoming, ownSiteId: (string) $site->id);
            if (! $check['available']) {
                throw new RuntimeException('HANDLE_TAKEN');
            }
            $site->subdomain = $incoming;
            // saveQuietly: the post-commit block already re-syncs KV + purges
            // for the final handle; a plain save would double-dispatch.
            $site->saveQuietly();
        }

        $professional->forceFill([
            'handle' => $incoming,
            'handle_lc' => $incoming,
        ]);
    }
}
