# Feature Flag Overrides (FF-1) — Plan Review Audit — 2026-05-17

**Branch:** development
**Lens:** design + implementation plan review: security, data integrity, auth, concurrency, rollback, test gaps, blast radius
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- docs/superpowers/specs/2026-05-17-feature-flag-overrides-design.md
- docs/superpowers/plans/2026-05-17-feature-flag-overrides.md
- app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffCommissionAdjustmentController.php (verification)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 4 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [ ] **#FF-4** · P2 — PruneExpiredCommandTest inserts a brand-scoped override with a hard-coded non-existent brand UUID
    - **Where:** docs/superpowers/plans/2026-05-17-feature-flag-overrides.md (Task 13, `PruneExpiredCommandTest`)
    - **Affects:** Test correctness — the fixture uses a synthetic `brand_id` with no corresponding `brand.brands` row. SQLite (used in tests) ignores foreign keys by default, so the test passes; anyone who runs the suite against PostgreSQL or enables SQLite FK mode will get a constraint violation, not a meaningful assertion failure.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Create a real `Brand` via factory before inserting the active override: `$brand = Brand::factory()->create(); 'brand_id' => $brand->id`.
        - While in the file, also verify that the professional-scoped permanent override uses `Professional::factory()->create()->id` — the snippet does this correctly but review the whole test for synthetic UUIDs.
    - **Technical:** The migration in Task 1 declares `brand_id uuid NULL REFERENCES brand.brands(id) ON DELETE CASCADE`. The test inserts `'brand_id' => '00000000-0000-0000-0000-000000000001'` without creating the parent row. CLAUDE.md confirms tests run SQLite in-memory, which doesn't enforce FK constraints by default, so the CI suite goes green. If the project ever toggles `PRAGMA foreign_keys = ON` for SQLite (Pest 4 can do this) or the test is extracted to a PostgreSQL test environment, it breaks. More importantly, the test is testing the wrong thing — it should assert that a valid non-expired brand-scoped override survives pruning, but a constraint violation means the row was never inserted, so the assertion is vacuous.
    - **Plain English:** Imagine the test that checks a parking attendant only removes expired tickets accidentally uses a fake parking spot number that doesn't exist in the system. The test might still show a green checkmark because the tester's computer doesn't validate spot numbers — but you haven't actually proven anything works. Fix it by creating a real parking spot (brand) before checking the ticket (override).
    - **Evidence:**
        ```php
        // From the plan's PruneExpiredCommandTest (Task 13):
        $active = FeatureFlagOverride::create([
            'flag_key' => 'x', 'brand_id' => '00000000-0000-0000-0000-000000000001',
            'enabled' => true, 'expires_at' => now()->addHour(),
        ]);
        ```

- [ ] **#FF-3** · P2 — `allFor()` defined in the approved design spec has no corresponding implementation task in the plan
    - **Where:** docs/superpowers/specs/2026-05-17-feature-flag-overrides-design.md (Public API block) vs. docs/superpowers/plans/2026-05-17-feature-flag-overrides.md (no task for `allFor`)
    - **Affects:** Any future consumer that needs to bulk-check all enabled flags for a tenant (e.g., a `/me` endpoint that returns flag state, client-side hydration). The advertised `FeatureFlagService` contract is broken on delivery.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a task (after Task 5) implementing `allFor(?Professional $pro = null, ?Brand $brand = null): array` in `FeatureFlagService`. The implementation is straightforward: load registry + relevant override cache keys, resolve each flag key in memory using the same precedence logic as `enabled()`, return `['key' => bool, ...]`.
        - Add the `feature_all()` global helper mentioned in the spec.
        - Write a `AllForTest` that asserts the returned map matches expected state for a tenant with mixed overrides, and that a `null` pro returns the global defaults map.
    - **Technical:** The spec explicitly lists `public function allFor(?Professional $pro = null, ?Brand $brand = null): array // ['key' => bool, ...]` as part of the service's public API. The plan builds `enabled()`, `setOverride()`, and `clearOverride()` across Tasks 4 and 5 but never mentions `allFor`. The method is architecturally cheap — the cache warm-path already loads the full registry and per-tenant override maps — so its omission isn't a performance concern. It's a shipped API surface gap that downstream code cannot work around without N calls to `enabled()`.
    - **Plain English:** The approved blueprint says this system should offer a single call that answers "which features are turned on for this user?" as a list. The construction plan builds all the individual light switches but forgets to wire the master panel that shows all of them at once. The system will still work for checking one flag at a time, but anyone who needs the full picture has to knock on every door individually — slower and error-prone.
    - **Evidence:**
        ```php
        // From the design spec (Public API block):
        public function allFor(?Professional $pro = null, ?Brand $brand = null): array  // ['key' => bool, ...]
        ```
        ```markdown
        // The implementation plan tasks: Task 4 (resolver core), Task 5 (caching),
        // Task 6 (feature() helper) — no mention of allFor() or feature_all() anywhere.
        ```

- [ ] **#FF-2** · P2 — Overrides for soft-deleted professionals/brands persist and remain active in the resolver
    - **Where:** docs/superpowers/plans/2026-05-17-feature-flag-overrides.md (Task 1 Step 1 migration FKs; Task 5 `loadProOverrides`/`loadBrandOverrides` queries)
    - **Affects:** Churned or archived tenants whose accounts are soft-deleted; their overrides silently survive and continue influencing feature flag resolution for as long as the soft-delete retention window lasts (30 days by default). Staff auditors see an active override for an "deleted" account and can't explain why.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add soft-delete filtering to `loadProOverrides` and `loadBrandOverrides`: join with `core.professionals` / `brand.brands` and filter `WHERE deleted_at IS NULL`, or scope the Eloquent queries with a `whereHas`/`whereNull('professionals.deleted_at')` join.
        - Alternatively, register an Observer on `Professional` and `Brand` that calls `FeatureFlagService::forgetPro()` / `forgetBrand()` and hard-deletes orphan overrides on soft-delete — but document why immediate hard-delete (vs. letting the 30-day window pass) was chosen.
        - Add a test: soft-delete a professional with an active override, assert `enabled()` falls through to the global default.
    - **Technical:** The migration sets `REFERENCES core.professionals(id) ON DELETE CASCADE` and `REFERENCES brand.brands(id) ON DELETE CASCADE`. `ON DELETE CASCADE` fires only on a hard `DELETE`. Partna uses soft deletes (30-day retention, CLAUDE.md). A soft-deleted professional's row remains in `core.professionals` with `deleted_at` set; the FK is still valid; the override row survives. The resolver's `loadProOverrides` query filters only on `expires_at` — it has no `deleted_at` awareness. Until the retention window expires and a hard-delete runs (if one ever does), a deactivated tenant's override stays live.
    - **Plain English:** When a customer cancels their account, it's like "pausing" them rather than immediately removing them. Any special settings you gave them (overrides) stay active during that pause window. That means a feature you turned on just for them keeps working even after they've left. It's a data-cleanliness issue — staff looking at the override list will see entries for people who don't exist anymore, with no obvious way to know the account is deactivated.
    - **Evidence:**
        ```sql
        -- Task 1 Step 1 migration — CASCADE only fires on hard DELETE:
        professional_id uuid NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
        brand_id uuid NULL REFERENCES brand.brands(id) ON DELETE CASCADE,
        ```
        ```php
        // Task 5 loadProOverrides — no deleted_at filter:
        FeatureFlagOverride::query()
            ->where('professional_id', $proId)
            ->whereNull('brand_id')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get()
        ```

- [ ] **#FF-1** · P2 — Plan's `resolveStaff` stub uses a wrong attribute key; if implemented verbatim the controllers crash on every request
    - **Where:** docs/superpowers/plans/2026-05-17-feature-flag-overrides.md (Task 10, `resolveStaff` method in both controllers)
    - **Affects:** All seven staff admin endpoints (`GET/POST /staff/feature-flags`, `PATCH/DELETE /staff/feature-flags/{key}`, `GET/POST /staff/feature-flags/{key}/overrides`, `DELETE /staff/feature-flags/overrides/{id}`); every request would resolve a null actor, causing `authorizeForUser(null, ...)` to throw a `TypeError`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `$request->attributes->get('professional') ?? $request->user()` with `$request->attributes->get('partna_staff')` — the exact key used by every other staff controller (verified in `StaffCommissionAdjustmentController`, `StaffShopifyEventReplayController`, `StaffBrandAffiliateLinkController`, and ~8 others).
        - Remove the `$request->user()` fallback entirely — under Supabase JWT auth, `Auth::user()` is always `null` (CLAUDE.md, canonical doctrine). The fallback provides false safety.
        - Update both controllers (`StaffFeatureFlagController` and `StaffFeatureFlagOverrideController`) before committing Task 10.
    - **Technical:** The plan's Task 10 Step 2 provides a `resolveStaff` stub with the comment "Adjust this line after reading StaffCommissionAdjustmentController in Step 1." Verification against the actual repo confirms the canonical key is `partna_staff`, not `professional`. An implementer who reads the stub but not the note — or who reads the wrong controller — will ship code where `$request->attributes->get('professional')` returns `null` (that key is never set by any staff middleware), the `$request->user()` fallback also returns `null` (Supabase JWT), and the subsequent `$this->authorizeForUser(null, 'viewAny', FeatureFlag::class)` either type-errors or opens a silent pass depending on the policy's null handling. The plan flags this as a "stand-in" but provides no guidance on the correct key name — closing that gap here.
    - **Plain English:** The construction plan leaves a "fill this in later" note on the section that decides who's allowed to use the admin panel. The placeholder uses the wrong door label — instead of looking up the admin by their actual badge (`partna_staff`), it looks for a label that doesn't exist and then falls back to "ask the front desk," which is always empty in this building's security setup. The result is a crash or, worse, an open door. The fix is a one-line change to use the right badge label, which every other admin corridor in the building already uses.
    - **Evidence:**
        ```php
        // Plan's Task 10 stub (wrong attribute key + unsafe fallback):
        private function resolveStaff(Request $request)
        {
            // Match the existing pattern used in staff controllers.
            // Most staff controllers use $request->user('professional') or a context middleware.
            // Adjust this line after reading StaffCommissionAdjustmentController in Step 1.
            return $request->attributes->get('professional') ?? $request->user();
        }
        ```
        ```php
        // Actual pattern in StaffCommissionAdjustmentController.php:34 (correct):
        $staff = $request->attributes->get('partna_staff');
        ```

`★ Insight ─────────────────────────────────────`
The `partna_staff` vs `professional` attribute key distinction matters precisely because staff controllers serve a different authentication middleware path than professional controllers. Staff routes set `partna_staff` on the request attributes; professional routes set `professional`. These are parallel contexts — a staff member acting on behalf of a brand still resolves their own identity via `partna_staff`, then resolves the target brand/professional separately from route parameters. Conflating the two keys is a common mistake when scaffolding new staff controllers without reading an existing one carefully.
`─────────────────────────────────────────────────`
