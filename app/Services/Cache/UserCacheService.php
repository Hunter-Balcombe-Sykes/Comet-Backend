<?php

namespace App\Services\Cache;

use App\Http\Resources\ServiceResource;
use App\Models\Core\User\Service;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// V2: Multi-lookup professional caching (by ID, handle, auth_user_id). Defensive validation prevents returning stale data after handle/auth changes.
class UserCacheService
{
    public function __construct(private CacheLockService $cacheLock) {}

    /* ---------------------------
     |  ID mapping (fast lookups)
     * --------------------------*/

    public function getIdByAuthId(string $authUserId): ?string
    {
        // Auth-path cache hit on every authenticated request — single-flight is critical.
        // Short null-TTL (30s) so a freshly-signed-up user doesn't see "not found" for 30 minutes.
        return $this->cacheLock->rememberLockedNullable(
            CacheKeyGenerator::userIdByAuthId($authUserId),
            (int) config('partna.cache.ttls.auth_id_lookup'),
            fn () => User::query()
                ->where('auth_user_id', $authUserId)
                ->value('id'),
            nullTtl: now()->addSeconds(30),
        );
    }

    public function getIdByHandle(string $handle): ?string
    {
        $handleLc = strtolower($handle);

        return $this->cacheLock->rememberLockedNullable(
            CacheKeyGenerator::userIdByHandle($handleLc),
            (int) config('partna.cache.ttls.professional_handle_lookup'),
            fn () => User::query()
                ->where('handle_lc', $handleLc)
                ->value('id'),
            nullTtl: now()->addSeconds(30),
        );
    }

    /* ---------------------------
     |  Payload (array pattern)
     * --------------------------*/

    public function getPayloadById(string $id): ?array
    {
        return $this->cacheLock->rememberLockedNullable(
            CacheKeyGenerator::professionalPayloadById($id),
            (int) config('partna.cache.ttls.professional_handle_lookup'),
            function () use ($id) {
                $pro = User::query()->with('site')->find($id);

                return $pro ? $this->toPayload($pro) : null;
            },
            nullTtl: now()->addSeconds(30),
        );
    }

    public function getPayloadByHandle(string $handle): ?array
    {
        $handleLc = strtolower($handle);
        $id = $this->getIdByHandle($handleLc);

        return $id ? $this->getPayloadById($id) : null;
    }

    public function getPayloadByAuthId(string $authUserId): ?array
    {
        $id = $this->getIdByAuthId($authUserId);

        return $id ? $this->getPayloadById($id) : null;
    }

    private function toPayload(User $pro): array
    {
        // NOTE: your Professional model has protected $with = ['site'];
        $site = $pro->site;
        $siteSettings = [];
        if ($site) {
            $siteSettings = is_array($site->settings) ? $site->settings : [];
        }

        return [
            'professional' => [
                'id' => $pro->id,
                'auth_user_id' => $pro->auth_user_id,
                'handle' => $pro->handle,
                'handle_lc' => $pro->handle_lc,
                'display_name' => $pro->display_name,
                'country_code' => $pro->country_code,
                'timezone' => $pro->timezone,
                'status' => $pro->status,
                'onboarding_step' => $pro->onboarding_step,

                'public_contact_number' => $pro->public_contact_number,
                'public_contact_email' => $pro->public_contact_email,

                'location_street_address' => $pro->location_street_address,
                'location_city' => $pro->location_city,
                'location_state' => $pro->location_state,
                'location_postcode' => $pro->location_postcode,
                'location_country' => $pro->location_country,

                'created_at' => optional($pro->created_at)->toIso8601String(),
                'updated_at' => optional($pro->updated_at)->toIso8601String(),
            ],
            'site' => $site ? [
                'id' => $site->id,
                'subdomain' => $site->subdomain,
                'is_published' => (bool) $site->is_published,
                'settings' => $siteSettings,
            ] : null,
        ];
    }

    /* ---------------------------
     |  Keep model-returning helpers (no model caching)
     * --------------------------*/

    /**
     * Resolve a Professional by their Supabase auth UUID.
     *
     * auth_user_id is immutable — set at account creation, never updated — so there is
     * no real mid-request race between the cached ID lookup and the model fetch.
     * The mismatch guard below is a belt-and-suspenders defence against stale/corrupt
     * cache entries only, not a concurrency fix.
     *
     * Two-level cache:
     *   1. id lookup (`pro:map:auth:{uid}`) — 30 min, immutable mapping
     *   2. hydrated model (`pro:model:{id}`) — 60 s with SWR + jitter via CacheLockService
     *
     * The model layer is what makes the auth path a Redis hit instead of a Postgres
     * round-trip on every authenticated request. Eloquent models serialize cleanly
     * through Redis; relations preserved across the boundary stay marked as loaded
     * (so `$pro->site` does not silently re-query). Bust both keys on profile writes
     * via `invalidateUser()`.
     */
    public function getByAuthId(string $authUserId): ?User
    {
        $id = $this->getIdByAuthId($authUserId);
        if (! $id) {
            return null;
        }

        // Cache the hydrated model for 60s with SWR + jitter. Eager-loading site
        // here makes it effectively free for every authenticated request — it rides
        // along inside the cached model, paid once per 60s window.
        // Short null-TTL (30s): if a user is deleted mid-session, avoid re-querying
        // Postgres on every request for the full professional_model TTL window.
        $professional = $this->cacheLock->rememberLockedNullable(
            CacheKeyGenerator::professionalModel($id),
            (int) config('partna.cache.ttls.professional_model'),
            fn () => User::query()->with(['site'])->find($id),
            nullTtl: now()->addSeconds(30),
        );
        if (! $professional) {
            return null;
        }

        // Defensive guard: if cache is stale/corrupt, never return another user's profile.
        if ((string) $professional->auth_user_id !== $authUserId) {
            $authIdKey = CacheKeyGenerator::userIdByAuthId($authUserId);
            $modelKey = CacheKeyGenerator::professionalModel($id);
            Log::warning('cache.auth_id_mismatch', ['cached_user_id' => $id, 'auth_user_id' => $authUserId]);
            Cache::forget($authIdKey);
            Cache::forget($modelKey);
            Cache::forget($modelKey.':stale');

            // CCH-1: re-cache the repaired auth-id → user-id mapping through the same
            // locked-nullable helper the normal lookup uses, not a bare Cache::put
            // (GS-1: no raw Cache:: writes; keeps the negative-cache + single-flight contract).
            $freshId = $this->cacheLock->rememberLockedNullable(
                $authIdKey,
                (int) config('partna.cache.ttls.auth_id_lookup'),
                fn () => User::query()->where('auth_user_id', $authUserId)->value('id'),
            );

            if (! $freshId) {
                return null;
            }

            return User::query()->with(['site'])->find($freshId);
        }

        return $professional;
    }

    /* ---------------------------
     |  Existing caches you already have
     * --------------------------*/

    public function getActiveServices(string $userId): array
    {
        // busts: professionalServices + professionalServices:stale (invalidateUser)
        // Returns Resource-shaped arrays (P1-05 sister cache): the /me payload
        // (UserSelfController::show) serves services from this method, so
        // without the wrap raw toArray() shape would still leak there.
        return $this->cacheLock->rememberLocked(
            CacheKeyGenerator::professionalServices($userId),
            (int) config('partna.cache.ttls.auth_id_lookup'),
            fn () => Service::query()
                ->with('categories:id')
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->orderBy('sort_order')
                ->get()
                ->map(fn (Service $s) => (new ServiceResource($s))->resolve())
                ->all()
        );
    }

    /**
     * Services list for the dashboard /api/services index — includes inactive
     * services so the management UI can render the visibility toggle. Excludes
     * soft-deleted (those surface only when ?include_archived=true, which the
     * controller serves uncached). 30-minute TTL mirrors getActiveServices.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDashboardServices(string $userId): array
    {
        // busts: professionalDashboardServices + professionalDashboardServices:stale (invalidateUser)
        // Returns Resource-shaped arrays (P1-05): without this the controller
        // would return raw Eloquent toArray() output on cache hits, bypassing
        // the ServiceResource allowlist.
        return $this->cacheLock->rememberLocked(
            CacheKeyGenerator::professionalDashboardServices($userId),
            (int) config('partna.cache.ttls.auth_id_lookup'),
            fn () => Service::query()
                ->with('categories:id')
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->get()
                ->map(fn (Service $s) => (new ServiceResource($s))->resolve())
                ->all()
        );
    }

    public function getCustomerCount(string $userId): int
    {
        // busts: customerCount + customerCount:stale (invalidateUser + CustomerObserver)
        return $this->cacheLock->rememberLocked(
            CacheKeyGenerator::customerCount($userId),
            (int) config('partna.cache.ttls.public_payload'),
            fn () => DB::table('site.customers')
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->count()
        );
    }

    /**
     * Bust the customer count keys for a given user. Uses deleteMultiple so both
     * the primary key and the SWR `:stale` copy are cleared atomically, mirroring
     * the pattern in invalidateUser(). Increment/decrement is intentionally avoided
     * because the count is soft-delete-aware and would drift under concurrent deletes.
     */
    public function invalidateCustomerCount(string $userId): void
    {
        $key = CacheKeyGenerator::customerCount($userId);
        Cache::deleteMultiple([$key, CacheKeyGenerator::staleKey($key)]);
    }

    /**
     * Bust only the services keys (dashboard + public-site view, both ± :stale).
     * Deliberately narrower than invalidateUser() — a category rename/reorder/
     * delete doesn't touch the hydrated model, payloads, or customer count, so
     * nuking those too would cause unnecessary Postgres round-trips. Called by
     * ServiceCategoryObserver to keep raw Cache:: calls inside the cache-service
     * layer, matching CustomerObserver's use of invalidateCustomerCount() to
     * stay within the GS-1 service-layer rule (no raw Cache:: outside cache
     * services) — CCH-1.
     */
    public function invalidateServices(string $userId): void
    {
        $dashKey = CacheKeyGenerator::professionalDashboardServices($userId);
        $svcKey = CacheKeyGenerator::professionalServices($userId);

        Cache::deleteMultiple([
            $dashKey,
            CacheKeyGenerator::staleKey($dashKey),
            $svcKey,
            CacheKeyGenerator::staleKey($svcKey),
        ]);
    }

    /**
     * Callers (UserObserver, LoadCurrentUser) deliberately swallow-and-report any
     * Throwable from this method rather than letting it propagate — a cache failure
     * must never abort a write. UserObserver::deleted() is dispatched as an
     * after-commit callback, so the row is already gone by the time this runs: a
     * throw here cannot roll the delete back, it only aborts the rest of the caller
     * (e.g. it kills the remaining candidates in a builds:prune-expired run).
     * The accepted risk is a *total* invalidation miss (every key here survives to
     * TTL) rather than a partial one — which is why this method must degrade
     * gracefully on a legitimate row state (e.g. an unclaimed user's null
     * auth_user_id) instead of throwing.
     */
    public function invalidateUser(User $professional, bool $bustSite = true): void
    {
        $handleLc = strtolower($professional->handle);

        $modelKey = CacheKeyGenerator::professionalModel($professional->id);

        $keys = [
            CacheKeyGenerator::professionalPayloadById($professional->id),
            CacheKeyGenerator::professionalPayloadByHandle($handleLc),
            CacheKeyGenerator::userIdByHandle($handleLc),

            // Auth-path hydrated-model cache (60 s SWR). Both the primary key and
            // the `:stale` last-good copy must die here, otherwise stale-while-
            // revalidate would let writes appear cached for up to 10 minutes.
            $modelKey,
            CacheKeyGenerator::staleKey($modelKey),

            CacheKeyGenerator::professionalServices($professional->id),
            CacheKeyGenerator::staleKey(CacheKeyGenerator::professionalServices($professional->id)),
            CacheKeyGenerator::professionalDashboardServices($professional->id),
            CacheKeyGenerator::staleKey(CacheKeyGenerator::professionalDashboardServices($professional->id)),
            CacheKeyGenerator::customerCount($professional->id),
            CacheKeyGenerator::staleKey(CacheKeyGenerator::customerCount($professional->id)),
        ];

        // auth_user_id is nullable (unclaimed pre-account users — see
        // supabase/migrations/20260718200000_pre_account_sites.sql). The two
        // generators below take a non-nullable string on purpose: a generator
        // that accepts null would push null-handling onto every call site and
        // make `Cache::get(null)` an easy accident. The nullable thing is the
        // column, not the key space — an authless user simply has no auth-keyed
        // entries, so skip them rather than casting to '' (which would produce
        // "pro:payload:auth:", a single key SHARED by every authless user).
        if ($professional->auth_user_id !== null) {
            $keys[] = CacheKeyGenerator::professionalPayloadByAuthId($professional->auth_user_id);
            $keys[] = CacheKeyGenerator::userIdByAuthId($professional->auth_user_id);
        }

        if ($professional->wasChanged('handle')) {
            $old = strtolower((string) $professional->getOriginal('handle'));
            if ($old !== '') {
                $keys[] = CacheKeyGenerator::professionalPayloadByHandle($old);
                $keys[] = CacheKeyGenerator::userIdByHandle($old);
            }
        }

        if ($professional->wasChanged('auth_user_id')) {
            $old = (string) $professional->getOriginal('auth_user_id');
            if ($old !== '') {
                $keys[] = CacheKeyGenerator::professionalPayloadByAuthId($old);
                $keys[] = CacheKeyGenerator::userIdByAuthId($old);
            }
        }

        Cache::deleteMultiple(array_values(array_unique($keys)));

        // Conservative catch-all: bust the site payload for ANY professional change.
        //
        // $bustSite is false when the caller guarantees a separate site bust will follow
        // (e.g. UserObserver::updated when a public field changed — touchParentSiteIfPublicFieldChanged
        // fires SiteObserver → invalidateSite; or ServiceObserver::bust() which is always
        // followed by touchParentSite()). Default true preserves the catch-all for all
        // other callers (deleted, restored, LoadCurrentUser, UserBootstrapService).
        if ($bustSite && $professional->site) {
            app(SiteCacheService::class)->invalidateSite($professional->site);
        }
    }
}
