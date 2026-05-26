# Authorization Audit — 2026-05-24

**Branch:** `development`
**Lens:** Authorization gaps, policy coverage, 403/404 leakage, input validation, JWT/AAL handling, IDOR, route middleware gaps, internal-API auth weakness
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `routes/api/professional.php`
- `routes/api/staff.php`
- `app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalLinkBlockController.php`
- `app/Http/Controllers/Api/Professional/Account/ProfessionalController.php`
- `app/Http/Controllers/Api/Professional/Account/ProfessionalAccountDeletionController.php`
- `app/Policies/BasePolicy.php`
- `app/Policies/ProfessionalSelfPolicy.php`
- `app/Http/Middleware/Auth/RequireAal2.php`
- `app/Http/Middleware/Auth/VerifySupabaseJwt.php`
- `app/Http/Middleware/Context/EnforcePendingDeletionReadOnly.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 1 complete

---

## P2 — Should fix

- [ ] **#AUTH-1** · P2 — Professional routes have no AAL2 enforcement; sensitive mutations (account deletion, profile update) execute on single-factor sessions
    - **Where:** `routes/api/professional.php` (route group middleware stack)
    - **Affects:** All authenticated professionals. Any session with a valid single-factor JWT can mutate destructive endpoints including account deletion and profile data.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wire `BasePolicy::requiresFreshAal2()` into sensitive policy methods on `ProfessionalSelfPolicy` (e.g., `update`, `delete`), following the roadmap pattern in `docs/auth/mfa-foundation.md`: "for user-facing routes that should require MFA later, add `$this->requiresFreshAal2()` to the relevant policy method."
        - Do **not** add `require.aal2` as a blanket route middleware to the professional group — it would lock out users who have never enrolled TOTP, which is intentional today.
        - Target the minimum surface first: the account-deletion `confirm` path and profile `update`. Other write endpoints can follow in Phase 2 as TOTP enrollment becomes widespread.
        - When adding `requiresFreshAal2()` to a policy, document the MFA window used (default is `config('partna.auth.aal2_fresh_seconds')`).
    - **Technical:** `routes/api/professional.php` applies `['supabase.jwt', 'require.email_verified', 'current.pro', EnforcePendingDeletionReadOnly::class, 'throttle:authenticated']` — there is no AAL2 check in this stack. The `RequireAal2` middleware is available and used on staff routes. `BasePolicy::requiresFreshAal2()` exists as the per-method hook for professional routes per the MFA foundation spec. The account-deletion `confirm` action does require an emailed token as secondary verification, which partially mitigates stolen-JWT risk on that specific action; however, `update()` on `ProfessionalController` has no such secondary check. The MFA Phase 2/3 plan explicitly defers this, but the infrastructure is in place and should be wired at the policy layer now so it activates automatically as users enroll TOTP without a future code change.
    - **Plain English:** Right now, anyone who steals a user's login token can change their profile or trigger account actions using only that one token — no second factor required. We've already built the "require a second factor" check and it's used for staff logins, but it's not yet turned on for regular user actions. For most writes this is acceptable while MFA enrollment is low. But we should at least wire the switch so it flips automatically when a user has MFA set up, without needing another code deploy later. The account-deletion flow does require a separate email confirmation, so that specific action has a partial safeguard — but profile updates don't.
    - **Evidence:**
        ```php
        Route::middleware([
            'supabase.jwt',
            'require.email_verified',
            'current.pro',
            EnforcePendingDeletionReadOnly::class,
            'throttle:authenticated',
        ])->group(function () {
            // ... all professional endpoints — no require.aal2
        ```

- [ ] **#AUTH-2** · P2 — `ProfessionalLinkBlockController::store()` and `reorder()` skip `authorizeForUser`; the empty `authorizeCustomLinks` gate provides no policy enforcement
    - **Where:** `app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalLinkBlockController.php:42–44, 70, 255`
    - **Affects:** Authenticated professionals. A `store()` request creates a block without a policy gate verifying the target site belongs to the requesting professional. `reorder()` operates on arbitrary site IDs without ownership verification through a policy.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `store()`, replace the `authorizeCustomLinks` call with a skeleton-pattern `authorizeForUser` check before creating the block:
          ```php
          $skeleton = new Block(['site_id' => $site->id]);
          $skeleton->setRelation('site', $site);
          $this->authorizeForUser($pro, 'create', $skeleton);
          ```
        - In `reorder()`, add an `authorizeForUser($pro, 'reorder', $site)` call (or use the site-level `update` policy) after resolving `$site`.
        - Remove the `authorizeCustomLinks` private method entirely — it is a false security gate that creates misleading confidence. The class docblock already states: *"Authorization: ownership on write actions is enforced via SitePolicy (authorizeForUser)"*.
        - Confirm `SitePolicy` has a `create` and/or `reorder`/`update` method covering the `Block` model, or delegate via `BlockPolicy`.
    - **Technical:** `update()` (line 118) and `destroy()` (line 245) both call `$this->authorizeForUser($pro, 'update'/$'delete', $linkBlock)` correctly. `store()` (line 70) and `reorder()` (line 255) call only `$this->authorizeCustomLinks($pro)`, which is an empty method. The class docblock explicitly documents the intent to use `authorizeForUser`, making this an incomplete implementation, not a deliberate bypass. Because `current.pro` resolves the professional from the JWT sub claim (not a user-supplied ID), IDOR on read is prevented — but the missing policy gate on write means `EnforcePendingDeletionReadOnly` is the only layer guarding these mutations for pending-deletion accounts, and no ownership check exists at the policy layer.
    - **Plain English:** When a user creates a new link or reorders their links, the code skips the standard ownership check it uses everywhere else — it calls an empty placeholder method instead of the real security check. The "real" check is there for editing and deleting individual links, just not for creating them or changing their order. This is like a door with a lock on the handle but no lock on the deadbolt: it looks secure, but one path through is unguarded. Fix is small — add two lines using the same pattern already used in sibling methods.
    - **Evidence:**
        ```php
        private function authorizeCustomLinks(User $pro): void
        {
            // All individual users can manage custom links — no capability gate needed.
        }

        // store() — line 70:
        $this->authorizeCustomLinks($pro);   // empty — no authorizeForUser

        // reorder() — line 255:
        $this->authorizeCustomLinks($pro);   // empty — no authorizeForUser

        // compare: update() — line 118:
        $this->authorizeForUser($pro, 'update', $linkBlock);  // ✓ correct pattern
        ```

---

## P3 — Nice to have

- [ ] **#AUTH-3** · P3 — Self-service controllers (`ProfessionalController::update`, `ProfessionalAccountDeletionController`) omit `authorizeForUser` calls — doctrine deviation, not a security gap
    - **Where:** `app/Http/Controllers/Api/Professional/Account/ProfessionalController.php:update()`
    - **Affects:** Code maintainability and doctrine consistency. No user data is at risk because `current.pro` resolves the professional exclusively from the JWT `sub` claim — there is no user-supplied ID that could be substituted.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `$this->authorizeForUser($professional, 'update', $professional)` in `ProfessionalController::update()` after resolving `$professional`, using `ProfessionalSelfPolicy`.
        - Add equivalent calls in `ProfessionalAccountDeletionController::request()` and `confirm()`.
        - This is a doctrine hygiene fix; it makes the authorization path explicit and ensures `ProfessionalSelfPolicy` methods (including any future `requiresFreshAal2()` hooks wired per AUTH-1) are always called through the standard gate.
    - **Technical:** `ProfessionalController::update()` calls `$this->currentProfessional($request)` which resolves by `auth_user_id` (the Supabase JWT `sub`), not any request parameter — eliminating IDOR. `EnforcePendingDeletionReadOnly` middleware blocks non-GET writes for `pending_deletion` accounts at the route layer. The missing `authorizeForUser` call means `ProfessionalSelfPolicy` is bypassed entirely; if `requiresFreshAal2()` is added to that policy (per AUTH-1), it will silently not run on this controller until this is also fixed.
    - **Plain English:** The "who is allowed to edit this profile?" policy class exists and works, but the profile-update controller doesn't actually ask it for permission — it just assumes the logged-in user can always update their own profile. That assumption is correct today. But when we later add the "require a second login factor" check to that policy class (the AUTH-1 fix), this controller will silently bypass it. It's a ten-minute fix now that prevents a confusing gap later.
    - **Evidence:**
        ```php
        public function update(UpdateProfessionalRequest $request)
        {
            $professional = $this->currentProfessional($request);
            DB::transaction(function () use ($professional, $request): void {
                $professional->fill($request->validated());
                $professional->save();
            });
            return $this->success([
                'professional' => new ProfessionalDashboardResource($professional->fresh()),
            ]);
        }
        ```
