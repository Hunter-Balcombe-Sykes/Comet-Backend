<?php

namespace App\Services\PreAccount;

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
    public function claim(string $uid, string $verifiedEmail, string $subdomain): array
    {
        $result = DB::connection('pgsql')->transaction(function () use ($uid, $verifiedEmail, $subdomain) {
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
                if ($this->emailGuard->isClaimedByAnotherAuthUser($verifiedEmail, $uid)) {
                    throw new RuntimeException('EMAIL_ALREADY_REGISTERED', 0, $e);
                }
                throw $e;
            }

            $build->update(['claimed_at' => now()]);

            // Claim-time side effects moved from the retired bootstrap create branch.
            $this->sideEffects->ensureSidestUpdatesSubscription($professional->primary_email);
            $this->sideEffects->createWelcomeNotification($professional);

            return ['professional' => $professional->fresh(), 'site' => $site->fresh()];
        });

        // Post-commit: bust caches and re-sync KV (status active → permanent
        // entry, clearing the unclaimed TTL). SyncSubdomainToKvJob remains the
        // single KV writer — this only dispatches it.
        $this->userCache->invalidateUser($result['professional']);
        $this->siteCache->invalidateSite($result['site']);
        SyncSubdomainToKvJob::dispatch((string) $result['professional']->id);

        return $result;
    }
}
