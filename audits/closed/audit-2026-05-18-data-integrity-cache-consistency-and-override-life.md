# Data Integrity, Cache Consistency & Override Lifecycle Audit — 2026-05-18

**Branch:** development
**Lens:** data integrity cache consistency and override lifecycle
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/Services/FeatureFlags/FeatureFlagService.php`
- `app/Services/FeatureFlags/OverrideScope.php`
- `app/Models/Core/FeatureFlag.php`
- `app/Models/Core/FeatureFlagOverride.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **FF-1** · P1 — Soft-deleted-entity overrides surface in DB fallback paths
    - **Where:** `app/Services/FeatureFlags/FeatureFlagService.php` — `resolveFromDb()` and `allForFromDb()` methods
    - **Affects:** Any feature flag resolution when the Redis cache is unavailable. A soft-deleted professional's override, or an override tied to a soft-deleted flag, can still resolve to `true` — allowing continued access to a feature after revocation.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `resolveFromDb`, mirror the `whereExists` guards from `loadProOverrides` / `loadBrandOverrides`: add a pgsql-only soft-delete check against `core.professionals` when querying pro-scoped overrides, and against `core.feature_flags` (by `flag_key`) when querying both pro- and brand-scoped overrides.
        - In `allForFromDb`, apply the same two `whereExists` guards to the pro-override and brand-override queries — the flag soft-delete guard is particularly important here because `allForFromDb` currently has no protection at all on either dimension.
        - Wrap each guard in the same `DB::getDriverName() === 'pgsql'` check used in the cache-path methods, so SQLite tests remain unaffected.
    - **Technical:** The cache-load methods `loadProOverrides` and `loadBrandOverrides` correctly apply `whereExists` subqueries against `core.professionals`, `brand.brand_profiles`, and `core.feature_flags` (all with `whereNull('deleted_at')`) before populating the Redis-backed arrays. The DB-fallback methods `resolveFromDb` and `allForFromDb` skip these guards entirely. When Redis is unavailable — a known failure scenario — this asymmetry means the "deleted = access revoked" invariant breaks. Notably, the registry query in both fallback methods does filter `whereNull('deleted_at')` on flags, so a deleted flag won't appear in `$registry`; but because `resolveFromArrays` checks `$brandOverrides` and `$proOverrides` *before* the registry, an override keyed to a deleted flag still wins, overriding step 5's `config()` default. The commit `75f145b9` and `92072be4` show recent attention to this subsystem, but the fallback gap was not addressed.
    - **Plain English:** Think of this like a hotel key card system. When the main system is online, it checks that a guest hasn't checked out before letting them into a room. But when the backup system kicks in (main system down), it skips that check — so a guest who checked out yesterday can still open the door. The fix is to make the backup system run the same checkout check as the main one.
    - **Evidence:**
        ```php
        // resolveFromDb — no soft-delete guard for professionals or flags
        $row = FeatureFlagOverride::where('flag_key', $key)
            ->where('professional_id', $pro->id)
            ->whereNull('brand_id')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();
        ```
        ```php
        // allForFromDb — same omission on the pro-override query
        $proOverrides = FeatureFlagOverride::query()
            ->where('professional_id', $pro->id)
            ->whereNull('brand_id')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get()
            ->mapWithKeys(fn ($o) => [$o->flag_key => (bool) $o->enabled])
            ->all();
        ```
        ```php
        // loadProOverrides (cache path) — has the guard; fallback paths don't
        if (DB::getDriverName() === 'pgsql') {
            $query->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('core.professionals')
                ->whereColumn('core.professionals.id', 'core.feature_flag_overrides.professional_id')
                ->whereNull('core.professionals.deleted_at'));

            $query->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('core.feature_flags')
                ->whereColumn('core.feature_flags.key', 'core.feature_flag_overrides.flag_key')
                ->whereNull('core.feature_flags.deleted_at'));
        }
        ```

---

## P2 — Should fix

- [ ] **FF-2** · P2 — `flush()` docblock promises full cache clear but only clears the registry key
    - **Where:** `app/Services/FeatureFlags/FeatureFlagService.php:flush()`
    - **Affects:** Test tear-downs and admin operations that call `flush()` expecting a complete reset. Per-professional (`ff:pro:{id}`) and per-brand (`ff:brand:{id}`) override caches survive the call, leaving stale state that can affect subsequent flag checks for up to 5 minutes.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Either extend `flush()` to iterate and delete all known per-user cache keys (requires maintaining a tracking set), or — simpler and safer — document its actual scope honestly: rename it to `flushRegistry()` (making the existing `flushRegistry()` private or merging the two), and expose a separate `forgetPro()` / `forgetBrand()` pair (already public) for callers that need full per-entity resets.
        - Update any test tear-downs that call `flush()` to also call `forgetPro()` / `forgetBrand()` for each affected entity, or switch to per-entity cache manipulation directly.
    - **Technical:** `flush()` is documented as "Flush all FF cache keys. Useful in tests and admin operations." Its implementation is `$this->flushRegistry()`, which calls `Cache::forget('ff:registry')` and `Cache::forget('ff:registry:stale')`. The per-entity keys `ff:pro:{id}`, `ff:pro:{id}:stale`, `ff:brand:{id}`, `ff:brand:{id}:stale` are only evicted by `forgetPro()` and `forgetBrand()`. Since the TTL is 5 minutes (±60s jitter), a test that calls `flush()` between scenarios may run against cached override state from a previous scenario and see stale results. This is a misleading contract, not a security gap, but it produces hard-to-diagnose test flakiness.
    - **Plain English:** Imagine a café's "clear all orders" button that actually only clears the board at the counter — not the slips already handed to the kitchen. Staff pressing it to reset before the next rush would be confused when old orders keep coming out. The button should either do the full job or be relabeled so nobody presses it expecting something it can't deliver.
    - **Evidence:**
        ```php
        /** Flush all FF cache keys. Useful in tests and admin operations. */
        public function flush(): void
        {
            $this->flushRegistry();
        }
        ```
