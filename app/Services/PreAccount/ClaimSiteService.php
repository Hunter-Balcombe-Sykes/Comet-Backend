<?php

namespace App\Services\PreAccount;

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\Cache\SiteCacheService;
use App\Services\Cache\UserCacheService;
use App\Services\User\EmailReuseGuard;
use App\Services\User\SignupSideEffects;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
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
    ) {}

    /**
     * @return array{professional: User, site: Site}
     *
     * @throws RuntimeException CLAIM_NOT_FOUND|ALREADY_CLAIMED|BUILD_NOT_READY|ACCOUNT_EXISTS|EMAIL_ALREADY_REGISTERED
     */
    public function claim(string $uid, string $verifiedEmail, string $subdomain, bool $marketingOptIn = false): array
    {
        $result = DB::connection('pgsql')->transaction(function () use ($uid, $verifiedEmail, $subdomain, $marketingOptIn) {
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

            $build = PreAccountBuild::query()->where('user_id', $professional->id)->lockForUpdate()->first();
            if (! $build || $build->build_state !== PreAccountBuild::STATE_READY) {
                throw new RuntimeException('BUILD_NOT_READY');
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
            $build->forceFill(['claimed_at' => now()])->save();

            // Claim-time side effects moved from the retired bootstrap create branch.
            // PRIV-101: subscription is opt-in only — $marketingOptIn comes straight
            // from the claim request and defaults to false (fail-closed) upstream.
            $this->sideEffects->ensureSidestUpdatesSubscription($professional->primary_email, $marketingOptIn, 'claim');
            $this->sideEffects->createWelcomeNotification($professional);

            return ['professional' => $professional->fresh(), 'site' => $site->fresh()];
        });

        // Post-commit: bust caches and re-sync KV (status active → permanent
        // entry, clearing the unclaimed TTL). SyncSubdomainToKvJob remains the
        // single KV writer — this only dispatches it.
        $this->userCache->invalidateUser($result['professional']);
        $this->siteCache->invalidateSite($result['site']);
        SyncSubdomainToKvJob::dispatch((string) $result['professional']->id);

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
            CloudflareCachePurgeJob::dispatch($handle, $customDomain)->afterCommit();
        }

        return $result;
    }
}
