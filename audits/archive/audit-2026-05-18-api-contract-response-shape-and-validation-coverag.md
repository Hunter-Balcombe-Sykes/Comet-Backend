`★ Insight ─────────────────────────────────────`
- **API-2 is a false positive**: `FeatureFlag` sets `$primaryKey = 'key'` (a string slug), so `findOrFail($key)` works exactly as intended — Eloquent uses whatever column is declared as the primary key for all `find*` lookups.
- **API-1 is confirmed real**: `OverrideScope` lives in `App\Services\FeatureFlags\OverrideScope` but the controller has no `use` statement for it. PHP will try to resolve it in the controller's own namespace and crash.
- **`whenCounted` pattern** (API-3) is a deliberate Laravel escape hatch for conditional field inclusion — but using it creates an implicit contract breach when some write paths don't load the count.
`─────────────────────────────────────────────────`

# API Contract — Response Shape & Validation Coverage Audit — 2026-05-18

**Branch:** development
**Lens:** api contract response shape and validation coverage
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagController.php
- app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagOverrideController.php
- app/Http/Requests/Api/Staff/FeatureFlag/CreateFeatureFlagRequest.php
- app/Http/Requests/Api/Staff/FeatureFlag/CreateOverrideRequest.php
- app/Http/Requests/Api/Staff/FeatureFlag/UpdateFeatureFlagRequest.php
- app/Http/Resources/FeatureFlagResource.php
- app/Http/Resources/FeatureFlagOverrideResource.php
- app/Models/Core/FeatureFlag.php
- routes/api/staff.php

## Progress

- P0 Blockers: 0 of 1 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 1 complete

---

## P0 — Must fix before any real user touches the system

- [ ] **#API-1** · P0 — Fatal crash on override creation: `OverrideScope` class not imported
    - **Where:** app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagOverrideController.php:48–51
    - **Affects:** All staff POST requests to `/staff/feature-flags/{key}/overrides` — the endpoint throws a fatal `Class "App\Http\Controllers\Api\Staff\FeatureFlag\OverrideScope" not found` before any business logic executes. No override can ever be created.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `use App\Services\FeatureFlags\OverrideScope;` to the import block of `StaffFeatureFlagOverrideController.php`. The class lives at `app/Services/FeatureFlags/OverrideScope.php`.
        - Add a smoke test that POSTs to `POST /staff/feature-flags/{key}/overrides` and asserts a 201 response, so a missing-import regression fails CI immediately.
    - **Technical:** The `store()` method calls `OverrideScope::forBrand($data['brand_id'])` and `OverrideScope::forProfessional($data['professional_id'])`, but the controller's import block contains no `use` statement for `OverrideScope`. PHP resolves unqualified class names relative to the current namespace (`App\Http\Controllers\Api\Staff\FeatureFlag`), which has no `OverrideScope`, producing a fatal error on every invocation. The class was introduced in commit `0ab28705` (OverrideScope value object) and the controller was added in `0617c67b`, but the import was never wired between the two commits.
    - **Plain English:** The code tells the server "go grab the special scope tool" but never unpacked that tool into the kitchen. Every time staff tries to create an override, the server crashes before it does anything — like a recipe that references an ingredient that was never stocked on the shelf.
    - **Evidence:**
        ```php
        // StaffFeatureFlagOverrideController.php — import block (no OverrideScope use statement)
        use App\Http\Controllers\Api\ApiController;
        use App\Http\Requests\Api\Staff\FeatureFlag\CreateOverrideRequest;
        use App\Http\Resources\FeatureFlagOverrideResource;
        use App\Models\Core\FeatureFlag;
        use App\Models\Core\FeatureFlagOverride;
        use App\Services\FeatureFlags\FeatureFlagService;
        use Carbon\Carbon;
        use Illuminate\Http\JsonResponse;
        use Illuminate\Http\Request;

        // store() method — uses OverrideScope with no import:
        $scope = ($data['brand_id'] ?? null)
            ? OverrideScope::forBrand($data['brand_id'])
            : OverrideScope::forProfessional($data['professional_id']);
        ```

---

## P2 — Should fix

- [ ] **#API-2** · P2 — Response shape inconsistency: `override_count` present in index but absent from store/update responses
    - **Where:** app/Http/Resources/FeatureFlagResource.php:10; app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagController.php:24 (index), :35 (store), :49 (update)
    - **Affects:** API consumers that parse `FeatureFlagResource` — a client rendering the flag card after creating or updating a flag will find `override_count` missing from the 201/200 response, while the same field is present in the list view. Any client that reads `override_count` to update local state will silently get `undefined`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `store()`, call `$flag->loadCount('overrides')` before wrapping in the resource. Since a freshly-created flag has zero overrides, this is a trivial single-query no-op.
        - In `update()`, do the same: `$flag->loadCount('overrides')` after `$flag->update(...)`.
        - Alternatively, change `FeatureFlagResource` to always emit `override_count` with a fallback: `'override_count' => $this->whenCounted('overrides', fn () => 0)` — this guarantees a stable integer shape regardless of which controller path produces the resource.
    - **Technical:** `FeatureFlagResource::toArray()` uses `$this->whenCounted('overrides')`, which emits the field only when the relationship count was loaded via `withCount()` or `loadCount()`. The `index()` action uses `FeatureFlag::withCount('overrides')` so the field is present there. The `store()` and `update()` actions return a bare model instance without calling `loadCount()`, so `whenCounted` returns `\Illuminate\Http\Resources\MissingValue` and the field is silently dropped from the serialised JSON. Same resource class, different wire shape depending on which HTTP verb was used.
    - **Plain English:** Asking for a list of feature flags gives you each flag with a count of how many overrides it has. But creating or editing a flag returns the same flag card with that count silently missing. It's like a shop receipt that shows the item total at checkout but not when you return an item — same receipt template, one field falls off.
    - **Evidence:**
        ```php
        // FeatureFlagResource.php:10 — conditional field
        'override_count' => $this->whenCounted('overrides'),

        // StaffFeatureFlagController.php — index loads count, store/update do not
        $flags = FeatureFlag::withCount('overrides')->orderBy('key')->get(); // index ✓

        $flag = FeatureFlag::create($request->validated()); // store — no loadCount ✗
        return (new FeatureFlagResource($flag))->response()->setStatusCode(201);

        $flag = FeatureFlag::findOrFail($key);
        $flag->update($request->validated()); // update — no loadCount ✗
        return (new FeatureFlagResource($flag))->response();
        ```

---

## P3 — Nice to have

- [ ] **#API-3** · P3 — No pagination on feature flag index; response grows unbounded
    - **Where:** app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagController.php:24
    - **Affects:** Staff admin dashboard loading the flags list — fine for pilot scale (~10 flags), but inconsistent with the override sub-resource which already paginates at 50.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `->get()` with `->paginate(50)` to match the pattern used in `StaffFeatureFlagOverrideController::index()`.
        - Return the result via `FeatureFlagResource::collection($flags)->response()` — Laravel's paginator integrates with resource collections automatically, adding `meta.total`, `meta.per_page`, and `links` to the response envelope.
    - **Technical:** `StaffFeatureFlagOverrideController::index()` returns a paginated collection (`->paginate(50)`), producing a standard Laravel paginated envelope with `data`, `links`, and `meta`. The parent `StaffFeatureFlagController::index()` uses a bare `->get()`, returning a flat `data` array with no pagination metadata. Feature flags are bounded in practice (pilot has ~10), but the asymmetry between the parent resource and its sub-resource creates an inconsistent API surface. Pagination also gives the staff UI a standard envelope it can reuse for filtering and sorting without a breaking API change.
    - **Plain English:** The overrides list comes back in tidy pages of 50. The flags list dumps everything in one go with no page structure. Right now the list is short so nobody notices, but the two endpoints speak slightly different dialects of the same language — aligning them now costs almost nothing and avoids a breaking change later.
    - **Evidence:**
        ```php
        // StaffFeatureFlagController.php:24 — bare get, no pagination
        $flags = FeatureFlag::withCount('overrides')->orderBy('key')->get();

        // StaffFeatureFlagOverrideController.php:26 — paginated (for comparison)
        $overrides = $flag->overrides()->orderBy('created_at', 'desc')->paginate(50);
        ```
