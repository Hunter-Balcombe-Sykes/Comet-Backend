Good. The Glob confirms no `UserStaffPolicy` or equivalent exists. The write-method Grep confirms zero `authorizeForUser` / `abort_if` / `abort_unless` calls in `StaffUserController` write paths. The `authorize()` grep confirms no P0-level plain-authorize misuse anywhere in controllers. Both evidence blocks are verbatim.

`★ Insight ─────────────────────────────────────`
The Partna always-drop rule 7 is the decisive filter here: AUTH-2 through AUTH-6 (feature flag inline checks, staff impersonation, link block inline abort, section controller zero-auth, notification creation) all live on routes already guarded by `staff.admin` middleware and none of the drafts identify a path around that gate. AUTH-7 (visibility controller 403 vs 404) is unreachable dead code: `LoadCurrentUser` blocks non-`active`/`pending_deletion` accounts at 403 and `EnforcePendingDeletionReadOnly` blocks writes for `pending_deletion` at 423 — the inline check can never fire. What remains: one real doctrine violation on a consequential code path (AUTH-1, re-tiered P0→P2) and one semantic status-code mismatch (AUTH-8, re-tiered P2→P3).
`─────────────────────────────────────────────────`

# Policy & Authorization Audit — 2026-05-31

**Branch:** development
**Lens:** Policy coverage gaps, 403 vs 404 leakage, route middleware gaps, missing Gate::policy registrations, inline-403 aborts bypassing BasePolicy, public-endpoint enumeration via 403, staff vs user route guard drift
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php`
- `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffSectionManagementController.php`
- `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffCustomerManagementController.php`
- `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffServiceManagementController.php`
- `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffServiceCategoryManagementController.php`
- `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffLinkBlockManagementController.php`
- `app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationController.php`
- `app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagController.php`
- `app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagOverrideController.php`
- `app/Http/Controllers/Api/PublicSite/SiteVisibilityController.php`
- `app/Http/Controllers/Api/User/SiteManagement/UserSectionBlockController.php`
- `app/Policies/BasePolicy.php`
- `app/Policies/CasePolicy.php`
- `app/Policies/CustomerPolicy.php`
- `app/Policies/ServicePolicy.php`
- `app/Policies/SitePolicy.php`
- `app/Policies/UserSelfPolicy.php`
- `app/Policies/PartnaStaffPolicy.php`
- `app/Policies/NotificationPolicy.php`
- `app/Policies/FeatureFlagPolicy.php`
- `app/Http/Middleware/Auth/EnsurePartnaAdmin.php`
- `app/Http/Middleware/Auth/EnsurePartnaStaff.php`
- `app/Http/Middleware/Context/EnforcePendingDeletionReadOnly.php`
- `app/Http/Middleware/Context/LoadCurrentUser.php`
- `routes/api/staff.php`
- `routes/api/user.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 1 complete

---

## P2 — Should fix

- [ ] **#AUTH-1** · P2 — StaffUserController destructive write operations have no policy authorization layer
    - **Where:** `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php` — `updateStatus()`, `update()`, `destroy()`, `restore()`, `forceDestroy()`, `bulkUpdateStatus()`
    - **Affects:** Every staff write path against professional accounts: suspend/unsuspend, profile edits, soft-delete, hard-delete, restore, and bulk compliance sweeps affecting up to 100 accounts per call. Any admin staff member who reaches these routes has unconditional write access to every professional record in the system.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extend `UserSelfPolicy` to add dual-typed abilities for staff operations, mirroring the `CasePolicy` pattern: `public function manage(User|PartnaStaff $actor, User $target): bool { return $actor instanceof PartnaStaff; }`. Add corresponding abilities for `updateStatus`, `forceDelete`, and `restore` as needed.
        - In each write method, resolve the staff actor and gate the action: `$staff = $request->attributes->get('partna_staff'); $this->authorizeForUser($staff, 'manage', $professional);`.
        - For `bulkUpdateStatus`, add a per-ID authorization pass or a `bulkManage` ability that validates the actor is PartnaStaff before batch update. Do not add a per-record loop — batch authority check at the top is sufficient.
        - Do not register a *second* `Gate::policy(User::class, ...)` entry. Only one policy class can be registered per model. Extend `UserSelfPolicy` or consolidate into a single policy with union actor types.
    - **Technical:** The staff admin route group applies `['supabase.jwt', 'require.email_verified', 'staff', 'require.aal2', 'staff.admin', 'throttle:staff', 'staff.audit']`. This chain is the only authorization control for all six write methods. `EnsurePartnaAdmin` confirms the JWT belongs to a `PartnaStaff` record with `role=admin` — but once past that gate, no policy method is ever invoked. The Authorization Doctrine mandates `authorizeForUser` for every resource-level mutation; the absence here means: (a) there is no centralized location to add per-ability restrictions later (e.g., "support can suspend but not hard-delete"), (b) the `RecordStaffAuditEntry` middleware fires on `terminate()` *after* the DB write has committed — a misuse window exists between middleware pass and write commit, and (c) `forceDestroy` — a permanent, irreversible operation — has zero defense-in-depth outside the route middleware. The `CasePolicy` pattern (`User|PartnaStaff $actor` union type, `return $actor instanceof PartnaStaff`) is already established in this codebase and is the correct template to follow.
    - **Plain English:** Picture a secure building where the front door has a keycard reader that only lets in senior staff. Once they're inside, every filing cabinet — including the one that permanently destroys records — is completely unlocked. That's fine today while the "is this person a senior staff member?" check at the door is the only access control you need. But it means you can never add a rule like "senior staff can suspend an account, but only the security director can permanently delete it" without rewriting all the cabinet logic from scratch. Adding the per-cabinet lock now costs one afternoon and means future permission changes take minutes, not days.
    - **Evidence:**
        ```php
        // StaffUserController::updateStatus — route in staff.admin group, zero authorizeForUser call
        public function updateStatus(Request $request, User $professional): JsonResponse
        {
            $data = $request->validate([
                'status' => ['required', 'string', 'in:active,suspended'],
            ]);

            $professional->status = $data['status'];
            $professional->save();

            return $this->success([
                'professional' => new UserStaffResource($professional->fresh()),
            ]);
        }
        ```
        ```php
        // StaffUserController::bulkUpdateStatus — modifies up to 100 accounts, no policy gate
        DB::transaction(function () use ($ids, $status, &$updated, &$missing): void {
            $existing = User::query()->whereIn('id', $ids)->get(['id'])->pluck('id')->all();
            $missing = array_values(array_diff($ids, $existing));

            if (! empty($existing)) {
                User::query()
                    ->whereIn('id', $existing)
                    ->update(['status' => $status]);
                $updated = $existing;
            }
        });
        ```
        ```php
        // CasePolicy — the correct dual-audience pattern this policy should follow
        public function view(User|PartnaStaff $actor, ModerationCase $case): bool
        {
            return $actor instanceof PartnaStaff;
        }
        ```

---

## P3 — Nice to have

- [ ] **#AUTH-2** · P3 — `UserSectionBlockController` returns 403 (Forbidden) instead of 422 (Unprocessable) for an unavailable block type
    - **Where:** `app/Http/Controllers/Api/User/SiteManagement/UserSectionBlockController.php:119` (`upsert`) and `:302` (`remove`)
    - **Affects:** Authenticated professionals who send a `blockType` not in `config('partna.section_block_types')`. The 403 response implies a permission failure ("you are not allowed"), while the correct signal is 422 ("the value you sent is not valid").
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change both `return $this->error('This section is not available for your account type.', 403)` calls to status 422.
        - Optionally move the allowlist check into `UpsertSectionBlockRequest::authorize()` or a dedicated route constraint so it fails at the validation layer before the controller runs. A custom `Rule::in(config('partna.section_block_types'))` rule on `blockType` would produce a standard 422 validation response with a consistent error envelope.
    - **Technical:** The Partna 403-vs-404 doctrine reserves 403 for role or type restrictions ("brand-only", "staff-only", policy gate failures). An invalid `blockType` value is not a role restriction — it is an input validation failure: the submitted parameter is not a member of the configured allowlist. Because the platform currently has only one account type (`individual`) and `section_block_types` is the universal config, every user's blocked sections are simply non-existent section keys, not sections withheld by role. A 422 with a descriptive message communicates the correct client action ("fix your input"), whereas 403 communicates "you need a different permission" — misleading for frontend error handling and logging.
    - **Plain English:** When a waiter says "sorry, that's not on the menu," they're giving you a menu-error. If instead they say "you're not allowed to order that," it sounds like the dish exists but is being kept from you specifically. The controller is giving the second answer when it should give the first. Changing the number from 403 to 422 makes error logs and frontend responses mean exactly what they say.
    - **Evidence:**
        ```php
        // UserSectionBlockController::upsert — 403 for a validation failure
        $allowedSections = config('partna.section_block_types', []);
        if (! in_array($blockType, $allowedSections, true)) {
            return $this->error('This section is not available for your account type.', 403);
        }
        ```
