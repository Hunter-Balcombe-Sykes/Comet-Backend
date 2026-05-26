`★ Insight ─────────────────────────────────────`
- **FF-1 is confirmed real**: the `use` import for `OverrideScope` is absent from the override controller — PHP will attempt to resolve it in the controller's own namespace and throw a fatal `Class not found` on every POST. The grep returned zero results, which is definitive.
- **FF-2 context**: The `feature:square_sync` / `feature:fresha_sync` gates appear on `professional.php` routes too (not just staff), meaning these aren't dead routes — they're still active brand-facing paths. The middleware limitation is genuinely worth documenting.
- The soft-delete divergence (FF-3) is an inconsistency that only surfaces during a Redis outage — real but exceptional-path hardening.
`─────────────────────────────────────────────────`

# Security, Auth & Feature Flag Audit — 2026-05-18

**Branch:** development
**Lens:** security auth and injection risks feature flags
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagOverrideController.php
- app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagController.php
- app/Services/FeatureFlags/FeatureFlagService.php
- app/Services/FeatureFlags/OverrideScope.php
- app/Http/Middleware/FeatureGate.php
- app/Policies/FeatureFlagPolicy.php
- app/Http/Requests/Api/Staff/FeatureFlag/CreateOverrideRequest.php
- app/Http/Requests/Api/Staff/FeatureFlag/CreateFeatureFlagRequest.php
- app/Http/Requests/Api/Staff/FeatureFlag/UpdateFeatureFlagRequest.php
- routes/api/staff.php

## Progress

- P0 Blockers: 0 of 1 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 1 complete

---

## P0 — Must fix before any real user touches the system

- [ ] **#FF-1** · P0 — Missing `OverrideScope` import causes fatal 500 on every override creation
    - **Where:** app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagOverrideController.php:57–59
    - **Affects:** All staff admin users — `POST /staff/feature-flags/{key}/overrides` crashes on every request. No per-brand or per-professional feature flag override can be created or updated through the API.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `use App\Services\FeatureFlags\OverrideScope;` to the import block at the top of `StaffFeatureFlagOverrideController.php`.
        - Add a smoke test: `POST /staff/feature-flags/{key}/overrides` with a valid `brand_id` payload → assert 201 and that `FeatureFlagService::setOverride()` was called with a brand-scoped `OverrideScope`.
    - **Technical:** `store()` references `OverrideScope::forBrand(...)` and `OverrideScope::forProfessional(...)` as bare class names. PHP resolves unqualified names against the current namespace — `App\Http\Controllers\Api\Staff\FeatureFlag\OverrideScope` — which does not exist. The actual class lives at `App\Services\FeatureFlags\OverrideScope`. Confirmed absent via grep: zero `use App\Services\FeatureFlags\OverrideScope` lines exist in the file. This is a `Class not found` fatal on every call to `store()`. The `destroy()` method calls `$this->service->forgetBrand/forgetPro` directly and is unaffected. Commit `401aee45` (feat: feature-flags OverrideScope value object) added the class but the subsequent controller commit omitted the import.
    - **Plain English:** The controller is like a recipe that calls for a specific spice by brand name, but the pantry label uses a different brand. Every time a staff member tries to set a feature flag override — like enabling a beta feature for a specific brand — the kitchen explodes because nobody can find the right jar. It's a single missing line at the top of the file.
    - **Evidence:**
        ```php
        // Import block — OverrideScope is absent:
        use App\Http\Controllers\Api\ApiController;
        use App\Http\Requests\Api\Staff\FeatureFlag\CreateOverrideRequest;
        use App\Http\Resources\FeatureFlagOverrideResource;
        use App\Models\Core\FeatureFlag;
        use App\Models\Core\FeatureFlagOverride;
        use App\Services\FeatureFlags\FeatureFlagService;
        use Carbon\Carbon;
        use Illuminate\Http\JsonResponse;
        use Illuminate\Http\Request;

        // store() — bare class references that will fatal:
        $scope = ($data['brand_id'] ?? null)
            ? OverrideScope::forBrand($data['brand_id'])
            : OverrideScope::forProfessional($data['professional_id']);
        ```

---

## P2 — Should fix

- [ ] **#FF-2** · P2 — `FeatureGate` middleware resolves flags without professional/brand context, silently ignoring per-tenant overrides and rollouts
    - **Where:** app/Http/Middleware/FeatureGate.php:14
    - **Affects:** All routes gated by `feature:square_sync`, `feature:fresha_sync`, and `feature:smart_booking` — including active brand-facing routes in `professional.php` (lines 302, 485, 170). Per-brand and per-professional overrides created via the staff admin API have no effect at the route layer.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - **Option A (preferred):** Add a `@note` docblock to `FeatureGate` explicitly documenting that it resolves against the global registry only (no pro/brand context), and update the staff route group comments to reflect this. This is appropriate because route-level gates are pre-auth blunt gates, not per-tenant switches.
        - **Option B:** If per-tenant route gating is genuinely needed, resolve the professional from `$request->attributes->get('professional')` (the Supabase JWT-resolved actor) and pass it to `FeatureFlagService::enabled($flag, $pro)`. Do not attempt to resolve `$brand` at this layer — brand context is not available on all gated routes.
        - Either way, add a test asserting that the middleware uses global-only resolution and that a per-brand override does NOT affect the route gate, so future developers don't accidentally assume override support exists here.
    - **Technical:** `FeatureGate::handle()` calls `app(FeatureFlagService::class)->enabled($flag)` with no `$pro` or `$brand` argument. `FeatureFlagService::enabled()` skips resolution steps 1–3 (brand override, pro override, percentage rollout) entirely when both are null, falling directly to the registry default and config fallback. This is an architectural ambiguity: the staff admin UI allows creating per-brand overrides for `square_sync`, but a brand's request to `GET /professionals/{p}/square/status` will still see the global flag value — making the override appear to have no effect. The limitation is undocumented.
    - **Plain English:** Imagine an event venue where the manager can put specific guests on a VIP list, but the front-door bouncer's instructions say "check the overall event policy, not the guest list." Even if the manager adds someone to the VIP list, the bouncer turns them away. The system works as programmed — it just doesn't match what the admin UI implies. The quickest fix is to put a sign on the bouncer's station saying "VIP list not consulted here," so future staff don't waste time creating overrides that won't take effect.
    - **Evidence:**
        ```php
        // FeatureGate.php — no pro/brand passed to the resolver:
        public function handle(Request $request, Closure $next, string $flag): mixed
        {
            if (! app(FeatureFlagService::class)->enabled($flag)) {  // ← global-only, no $pro/$brand
                return response()->json([
                    'message' => 'Feature not available',
                    'feature' => $flag,
                ], 503);
            }
            return $next($request);
        }
        ```

- [ ] **#FF-3** · P2 — DB fallback path omits soft-delete guards, creating inconsistent override resolution during cache outages
    - **Where:** app/Services/FeatureFlags/FeatureFlagService.php:232–270 (`resolveFromDb`), 185–225 (`allForFromDb`)
    - **Affects:** Feature flag resolution for any professional or brand when Redis is unavailable. Overrides belonging to soft-deleted professionals, brands, or flags may continue to apply during outage windows, diverging from the cached (correct) behavior.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add the same `pgsql`-gated `whereExists` soft-delete guards used in `loadProOverrides()` and `loadBrandOverrides()` to the equivalent queries inside `resolveFromDb()` and `allForFromDb()`.
        - Extract the guard block into a private helper (e.g., `applyProSoftDeleteGuard(Builder $q): void` and `applyBrandSoftDeleteGuard(Builder $q): void`) so the hot path and fallback path can't diverge silently again.
        - Add a SQLite-compatible test asserting that a soft-deleted professional's override is excluded on the fallback path (mock the cache to throw so the fallback triggers).
    - **Technical:** `loadProOverrides()` and `loadBrandOverrides()` use `whereExists` subqueries on `core.professionals`, `brand.brand_profiles`, and `core.feature_flags` (checking `deleted_at`) to exclude overrides for soft-deleted entities. The fallback methods `resolveFromDb()` and `allForFromDb()` omit these checks — they filter only by `expires_at`. On a Redis outage, a flag override for a soft-deleted professional (e.g., a churned account still within the 30-day retention window) will resolve as `true` or `false` based on the stale override row, rather than being skipped. The divergence is hidden in normal operation because the cache path masks it.
    - **Plain English:** The system has two ways to check feature flag permissions: a fast electronic system (the cache) and a manual paper-ledger backup (the database fallback). The electronic system automatically ignores entries for cancelled accounts. But the paper ledger doesn't — it still shows those entries. If the electronic system goes down briefly, cancelled accounts can temporarily get features they shouldn't have. The fix is to make the paper ledger check the same cancellation list.
    - **Evidence:**
        ```php
        // resolveFromDb — no soft-delete guard on professional:
        $row = FeatureFlagOverride::where('flag_key', $key)
            ->where('professional_id', $pro->id)
            ->whereNull('brand_id')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();
        // ↑ Missing whereExists on core.professionals.deleted_at

        // Compare: loadProOverrides — HAS the guard:
        if (DB::getDriverName() === 'pgsql') {
            $query->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('core.professionals')
                ->whereColumn('core.professionals.id', 'core.feature_flag_overrides.professional_id')
                ->whereNull('core.professionals.deleted_at'));
        }
        ```

---

## P3 — Nice to have

- [ ] **#FF-4** · P3 — Config fallback uses string interpolation into `config()` — safe today, permanently fragile
    - **Where:** app/Services/FeatureFlags/FeatureFlagService.php:137
    - **Affects:** Future callers of `FeatureFlagService::enabled()` or `allFor()` who supply a key not yet validated by a route regex or Form Request. No current caller is affected.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `config('partna.features.'.$key, false)` with an array-key lookup: `$features = config('partna.features', []); return (bool) ($features[$key] ?? false);`. This eliminates dot-traversal entirely without changing semantics.
        - Alternatively, add a guard at the top of `enabled()` and `allFor()`: `if (! preg_match('/^[a-z][a-z0-9_]*$/', $key)) { return false; }`.
    - **Technical:** `config('partna.features.'.$key)` uses Laravel's dot-notation config accessor. A key containing dots (e.g., `foo.bar`) would traverse into nested array nodes rather than looking up a literal key `foo.bar`. Today all call sites supply hardcoded strings or route parameters constrained by `->where('key', '[a-z][a-z0-9_]*')` in `staff.php`. However, `enabled()` is a public service method with no internal key-format assertion. The array-key lookup variant is strictly equivalent in behavior for valid keys and removes the traversal risk permanently with one line.
    - **Plain English:** There's a filing cabinet where feature flag defaults are stored. The current system looks up files by reading a path like "folder/subfolder/filename" directly. All the current users hand it a simple filename, so it works fine. But the cabinet technically accepts complex paths — if someone accidentally passes a path with slashes in a future code change, it goes into a wrong drawer. The fix is to change the lookup to just check the top-level drawer by filename directly.
    - **Evidence:**
        ```php
        // Step 5 config fallback — $key interpolated with dot notation:
        return (bool) config('partna.features.'.$key, false);
        ```
