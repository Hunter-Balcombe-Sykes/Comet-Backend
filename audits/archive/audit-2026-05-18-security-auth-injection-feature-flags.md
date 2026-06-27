`★ Insight ─────────────────────────────────────`
- `FeatureFlagOverride` has **no `SoftDeletes` trait** — both `$override->delete()` in the controller and the service's query-builder `->delete()` perform hard deletes. DeepSeek's FF-2 finding rests on a false premise (soft-delete inconsistency) that evaporates once you read the model. Always read the model before accepting a SoftDeletes-divergence finding.
- The `FeatureGate` middleware calls `enabled($flag)` with no professional/brand context, meaning per-tenant overrides are never applied at the route-gate level — only the global registry default is checked. This is probably intentional design but worth noting as a potential gap DeepSeek missed.
`─────────────────────────────────────────────────`

# Security, Auth & Feature Flags Audit — 2026-05-18

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
- app/Models/Core/FeatureFlagOverride.php
- routes/api/staff.php

## Progress

- P0 Blockers: 0 of 1 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 1 complete

---

## P0 — Must fix before any real user touches the system

- [ ] **#FF-1** · P0 — Missing `OverrideScope` import crashes override creation endpoint
    - **Where:** app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagOverrideController.php:45
    - **Affects:** All staff admin attempts to create a feature flag override via `POST /staff/feature-flags/{key}/overrides` — the endpoint throws a fatal `Class not found` error before any business logic executes.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `use App\Services\FeatureFlags\OverrideScope;` to the import block in `StaffFeatureFlagOverrideController.php`.
        - Verify the existing `tests/Feature` coverage for `StaffFeatureFlagOverrideController::store` actually runs the method body (a test that expects a 201 would have caught this immediately).
    - **Technical:** The `store()` method references `OverrideScope::forBrand(...)` and `OverrideScope::forProfessional(...)` as bare unqualified class names. PHP resolves unqualified names to the current namespace, which here is `App\Http\Controllers\Api\Staff\FeatureFlag\OverrideScope` — a class that does not exist. The real class lives at `App\Services\FeatureFlags\OverrideScope`. At runtime PHP throws `Error: Class "App\Http\Controllers\Api\Staff\FeatureFlag\OverrideScope" not found` on the first request that reaches line 45. The `FeatureFlagService` import is present and correct; only `OverrideScope` was omitted from the commit `dcaf53a0` that introduced the controller.
    - **Plain English:** The code tries to use a tool that it never brought into the room. When a staff member hits the "create override" button, the system looks for the tool in the wrong cupboard, finds nothing, and crashes with a 500 error. It takes one line — a single "import" statement at the top of the file — to point it at the right cupboard. Every single attempt to create an override will fail until this is fixed.
    - **Evidence:**
        ```php
        // Current imports — OverrideScope is absent:
        use App\Http\Controllers\Api\ApiController;
        use App\Http\Requests\Api\Staff\FeatureFlag\CreateOverrideRequest;
        use App\Http\Resources\FeatureFlagOverrideResource;
        use App\Models\Core\FeatureFlag;
        use App\Models\Core\FeatureFlagOverride;
        use App\Services\FeatureFlags\FeatureFlagService;
        use Carbon\Carbon;
        use Illuminate\Http\JsonResponse;
        use Illuminate\Http\Request;

        // store() uses the bare class name — resolves to the wrong namespace at runtime:
        $scope = ($data['brand_id'] ?? null)
            ? OverrideScope::forBrand($data['brand_id'])
            : OverrideScope::forProfessional($data['professional_id']);
        ```

---

## P2 — Should fix

- [ ] **#FF-2** · P2 — `FeatureGate` middleware ignores per-tenant overrides — route gates always use global default
    - **Where:** app/Http/Middleware/FeatureGate.php:15
    - **Affects:** Any route protected by `->middleware('feature:flag_key')`. Per-professional or per-brand overrides set via the admin panel have no effect when the flag is checked at the route level — only `default_enabled` and the registry rollout percent are considered. A brand with a targeted override to enable `square_sync` will still be blocked at the route gate.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - If the middleware is only ever used for global launch gates (flags with no per-tenant semantics), add a code comment documenting this explicitly so future authors don't expect overrides to be honoured here.
        - If per-tenant route-level gates are ever needed: resolve the professional from `$request->attributes->get('professional')` (for authenticated routes) and pass it to `enabled()`. Do not attempt to resolve a brand at the middleware layer — brand context requires a loaded model.
    - **Technical:** `FeatureFlagService::enabled()` accepts `?Professional $pro` and `?BrandProfile $brand`. The middleware calls `enabled($flag)` with no arguments, so `$pro` and `$brand` are both null. `resolveFromArrays()` skips brand overrides, skips pro overrides, skips the rollout path (requires `$pro !== null`), and falls through to the global `default_enabled` value. Per-tenant overrides are fully invisible to this code path. The current usage in `routes/api/staff.php` — gating `square_sync` and `fresha_sync` — affects staff-side inspector mirrors of dropped integrations, so the practical impact is low today. The gap becomes a real footgun the moment a per-tenant override is created for any flag that is also used as a route gate.
    - **Plain English:** The feature flag system can be told "turn this feature on for Brand X specifically." But the door lock on each route only checks the global on/off switch and ignores those tenant-specific exceptions. A staff member could override the flag on for a brand, the brand would see it enabled on API responses that call the service correctly, but the route itself would still block them because the lock never checks the exceptions list. A comment documenting this limitation would stop future developers from creating an override and wondering why it doesn't work.
    - **Evidence:**
        ```php
        // FeatureGate::handle — no professional/brand context passed to enabled():
        public function handle(Request $request, Closure $next, string $flag): mixed
        {
            if (! app(FeatureFlagService::class)->enabled($flag)) {
                return response()->json([
                    'message' => 'Feature not available',
                    'feature' => $flag,
                ], 503);
            }

            return $next($request);
        }
        ```
        ```php
        // resolveFromArrays — rollout + override branches both require $pro:
        if (isset($brandOverrides[$key])) {
            return $brandOverrides[$key];
        }
        if (isset($proOverrides[$key])) {
            return $proOverrides[$key];
        }
        // ... percentage rollout: skipped when $pro === null
        if ($flag !== null && $pro !== null && $flag['rollout_percent'] > 0) { ... }
        ```

- [ ] **#FF-3** · P2 — DB fallback paths skip soft-delete filtering on parent entities
    - **Where:** app/Services/FeatureFlags/FeatureFlagService.php:248-295 (`allForFromDb`), :304-338 (`resolveFromDb`)
    - **Affects:** The degraded path when Redis is unavailable. A professional or brand that has been soft-deleted could have their overrides resolved and served, while the happy (cache) path would exclude them via `whereExists` subqueries.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Mirror the `whereExists` soft-delete guards from `loadProOverrides`/`loadBrandOverrides` into `allForFromDb` and `resolveFromDb`, wrapped in `if (DB::getDriverName() === 'pgsql')` to preserve SQLite test compatibility.
        - Alternatively, add guard clauses at the top of `allForFromDb` and `resolveFromDb` asserting `$pro->deleted_at === null` and `$brand->deleted_at === null` when the arguments are non-null. Callers already hold live model instances, but an explicit check is defense-in-depth and makes the contract obvious.
    - **Technical:** The cache path (`loadProOverrides`, `loadBrandOverrides`) applies `whereExists` subqueries against `core.professionals` and `brand.brand_profiles` with `whereNull('deleted_at')`. The direct-DB fallback paths (`allForFromDb`, `resolveFromDb`) query `FeatureFlagOverride` without any parent soft-delete check. During a Redis outage — the only time these paths execute — a soft-deleted professional's overrides could be resolved and returned. The window is narrow (Redis outages are exceptional) but the cache and non-cache code paths have different filtering semantics, which violates the invariant that the fallback is a slower, faithful replica of the happy path.
    - **Plain English:** When the quick-access memory (cache) is working, the system double-checks that the person tied to an override hasn't been deleted from the platform before serving it. When the cache is down and it goes directly to the database, that check is skipped. The risk is small because the cache rarely goes down, but "rarely" isn't "never" — and this is the kind of gap that shows up in an incident at the worst possible time.
    - **Evidence:**
        ```php
        // allForFromDb — no soft-delete guard on professional or brand:
        $proOverrides = [];
        if ($pro !== null) {
            $proOverrides = FeatureFlagOverride::query()
                ->where('professional_id', $pro->id)
                ->whereNull('brand_id')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->get()
                ->mapWithKeys(fn ($o) => [$o->flag_key => (bool) $o->enabled])
                ->all();
        }
        ```
        ```php
        // loadProOverrides (cache path) — has whereExists soft-delete guard:
        if (DB::getDriverName() === 'pgsql') {
            $query->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('core.professionals')
                ->whereColumn('core.professionals.id', 'core.feature_flag_overrides.professional_id')
                ->whereNull('core.professionals.deleted_at'));
        }
        ```

---

## P3 — Nice to have

- [ ] **#FF-4** · P3 — Expired overrides remain active for up to full cache TTL after expiry
    - **Where:** app/Services/FeatureFlags/FeatureFlagService.php, `loadProOverrides` and `loadBrandOverrides`
    - **Affects:** Overrides with a short `expires_at` window. An override that expires mid-TTL stays in the cached result set until the key naturally expires (~5 minutes) or is push-invalidated. The `prune-expired` command runs daily (commit `2ac51683`), so it doesn't help here.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Document the TTL-staleness behaviour in the class docblock so future operators know that short-lived overrides carry up to `BASE_TTL + TTL_JITTER` seconds of post-expiry activation.
        - Optionally: set the cache TTL for a key to `min(jitteredTtl(), $nearestExpiresAt - now())` when any override in the set expires sooner than the TTL. This caps the staleness window to the nearest expiry rather than the full 5 minutes.
    - **Technical:** The expiry filter (`orWhere('expires_at', '>', now())`) is evaluated once at cache-build time and baked into the cached array. `now()` is not re-evaluated on reads from the cache. For an override set to expire 60 seconds from the cache-build moment, the override will be returned as active for up to 360 seconds (300s base + 60s jitter). This contradicts the semantic contract of `expires_at`. The risk is low because short-lived overrides are an edge case for admin-only tooling, but the behavior is surprising.
    - **Plain English:** The system refreshes its memory of active overrides every 5 minutes or so. If an override expires at 2:03 PM and the memory was last refreshed at 2:00 PM, the override will still appear active until 2:05 PM — 2 minutes after it was supposed to end. It's like a parking meter that's already expired but the enforcement officer only checks the clock when they first walk the block, not on every car they pass. For most uses this is fine, but for time-sensitive toggles it could matter.
    - **Evidence:**
        ```php
        // Expiry check is evaluated once at cache-build time:
        ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))

        // Cache lives for BASE_TTL ± jitter — no early eviction on near-term expiry:
        private const BASE_TTL_SECONDS = 300;
        private const TTL_JITTER_SECONDS = 60;
        ```
