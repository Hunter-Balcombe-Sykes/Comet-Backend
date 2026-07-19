<?php

namespace App\Services\User;

use App\Enums\AccountType;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Cache\UserCacheService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// Bootstrap a new or existing professional from a validated request. Runs the
// create-or-update under a single DB transaction. Disabled-status check is
// performed BEFORE the transaction so the 403 short-circuits cleanly — the
// previous in-closure `return $this->error(...)` returned a JsonResponse object
// through the transaction, which the outer success() re-encoded as '{}'.
class UserBootstrapService
{
    public function __construct(
        private readonly SiteProvisioningService $siteProvisioning,
        private readonly UserCacheService $cache,
        private readonly EmailReuseGuard $emailReuseGuard,
        private readonly SignupSideEffects $sideEffects,
    ) {}

    /**
     * Bootstrap or update a professional + their site.
     *
     * @param  array<string, mixed>  $data  Validated payload from BootstrapRequest
     * @return array{professional: User, site: Site, created: bool}
     *
     * @throws RuntimeException with one of: 'ACCOUNT_DISABLED', 'EMAIL_ALREADY_REGISTERED'.
     *                          Other exceptions propagate.
     */
    public function bootstrap(string $uid, array $data): array
    {
        $existing = User::query()->where('auth_user_id', $uid)->first();
        if ($existing && in_array($existing->status, ['disabled', 'suspended', 'pending_deletion'], true)) {
            throw new RuntimeException('ACCOUNT_DISABLED');
        }

        // Pin to 'pgsql' so the transaction shares the connection with the Eloquent
        // writes inside (User/Site extend BaseModel which forces pgsql). Bare
        // DB::transaction() targets the default connection — 'sqlite' in feature
        // tests — making the wrapper a no-op and breaking rollback. Mirrors
        // AccountDeletionService::request().
        $result = DB::connection('pgsql')->transaction(function () use ($uid, $data) {
            // Re-fetch the row under a lock INSIDE the transaction (07-08 #LIFE-12). The
            // pre-transaction read above is only for the fast disabled-account short-circuit;
            // reusing it here would let two concurrent bootstraps for the same auth user both
            // operate on a stale snapshot and last-write-wins clobber each other. Locking here
            // serializes the existing-user update path; a brand-new user has no row to lock and
            // is guarded instead by the users_auth_user_id_unique partial index (a genuine
            // double-create fails loudly rather than silently duplicating).
            $existing = User::query()->where('auth_user_id', $uid)->lockForUpdate()->first();

            $createdProfessional = false;
            $professional = $existing;

            if (! $professional) {
                $this->guardAgainstEmailReuseByDifferentAuthUser((string) ($data['primary_email'] ?? ''), $uid);

                $createdProfessional = true;
                $professional = new User([
                    'handle' => $data['handle'],
                    'display_name' => $data['display_name'],
                    'country_code' => $data['country_code'] ?? null,
                    'timezone' => $data['timezone'] ?? null,
                    // Defaults to Partna; the signup account-type step may send
                    // 'business'. BootstrapRequest validates the value, so tryFrom
                    // only ever resolves to Partna or Business here.
                    'account_type' => AccountType::tryFrom((string) ($data['account_type'] ?? '')) ?? AccountType::Partna,
                    'status' => 'active',
                    'onboarding_step' => 0,
                    'phone' => $data['phone'] ?? null,
                    'primary_email' => $data['primary_email'],
                    'first_name' => $data['first_name'] ?? '',
                    'last_name' => $data['last_name'] ?? null,
                    'public_contact_number' => null,
                    'public_contact_email' => null,
                    'handle_lc' => $data['handle_lc'],
                ]);
                $professional->auth_user_id = $uid;
            } else {
                $professional->fill([
                    'handle' => $data['handle'],
                    'display_name' => $data['display_name'],
                    'primary_email' => $data['primary_email'],
                    'phone' => array_key_exists('phone', $data) ? $data['phone'] : $professional->phone,
                    'first_name' => $data['first_name'] ?? $professional->first_name,
                    'last_name' => $data['last_name'] ?? $professional->last_name,
                    'country_code' => $data['country_code'] ?? $professional->country_code,
                    'timezone' => $data['timezone'] ?? $professional->timezone,
                    'handle_lc' => $data['handle_lc'],
                ]);
            }

            try {
                // LIFE-101: nested tx → SAVEPOINT/ROLLBACK TO SAVEPOINT when run inside the
                // outer signup transaction above (mirrors SiteProvisioningService::tryCreateSite).
                // Postgres otherwise aborts the WHOLE transaction on a 23505, which made a
                // post-catch re-query impossible and forced the old code to string-match the
                // driver's error message (fragile across driver/version/locale — and untestable,
                // since SQLite's message differs from Postgres's). The savepoint rollback undoes
                // only this insert/update, leaving the outer transaction healthy so we can re-query
                // and classify the cause deterministically instead.
                DB::connection('pgsql')->transaction(function () use ($professional) {
                    $professional->save();
                });
            } catch (UniqueConstraintViolationException $e) {
                // LIFE-6 backstop for the TOCTOU race guardAgainstEmailReuseByDifferentAuthUser
                // can't see (a concurrent signup claims the email between the pre-check and this
                // write). Re-query with the same helper the pre-check uses: if another auth user
                // now holds this email, surface the same friendly EMAIL_ALREADY_REGISTERED the
                // pre-check throws instead of a raw 500. Any other unique violation (e.g.
                // users_auth_user_id_unique / core_users_handle_lc_unique) re-throws unchanged.
                if ($this->emailReuseGuard->isClaimedByAnotherAuthUser((string) $professional->primary_email, $uid)) {
                    throw new RuntimeException('EMAIL_ALREADY_REGISTERED', 0, $e);
                }

                throw $e;
            }

            $this->sideEffects->ensureSidestUpdatesSubscription($professional->primary_email);

            $site = Site::query()->where('user_id', $professional->id)->first();
            if (! $site) {
                $base = $this->siteProvisioning->subdomainBaseFromHandle($data['handle']);
                $site = $this->siteProvisioning->createSiteWithRetry($professional->id, $base);
            }

            if ($createdProfessional) {
                $this->sideEffects->createWelcomeNotification($professional);
            }

            return [
                'professional' => $professional->fresh(),
                'site' => $site->fresh(),
                'created' => $createdProfessional,
            ];
        });

        // Invalidate after commit so a concurrent reader rewarms from committed state,
        // not from a partial write that may still be in-flight inside the transaction.
        // NOTE: this call uses the fresh() model (wasChanged() is false), so it busts
        // only current-handle keys. Old-handle key busting on a rename is handled by
        // UserObserver::updated() (afterCommit = true, dirty instance) — keep that in
        // mind if the observer is ever refactored.
        $this->cache->invalidateUser($result['professional']);

        return $result;
    }

    private function guardAgainstEmailReuseByDifferentAuthUser(string $email, string $uid): void
    {
        if ($this->emailReuseGuard->isClaimedByAnotherAuthUser($email, $uid)) {
            throw new RuntimeException('EMAIL_ALREADY_REGISTERED');
        }
    }
}
