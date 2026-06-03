- [ ] **#SEC-1** · P1 — StaffSiteController has no authorization check; any authenticated staff member can read any site's full data
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffSiteController.php:26-40
    - **Affects:** All site data exposed to staff — display name, bio, location street address, site settings, blocks. Any staff member can hit `GET /api/staff/sites/{subdomain}` or `GET /api/staff/professionals/{professional}/site` and retrieve the full record with no per-resource gate.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `SitePolicy` (extending `BasePolicy`) with a `viewForStaff` ability and register it in `AppServiceProvider::boot()`.
        - In both `show()` and `showByProfessional()`, call `$this->authorizeForUser($request->attributes->get('professional'), 'viewForStaff', $row)` before returning the resource.
        - Ensure missing-or-not-yours returns 404 via `denyAsNotFound()` in the Policy, not 403.
    - **Technical:** The Authorization Doctrine requires Policy-gated access for tenant-owned models. Both methods resolve a site row via `AllSiteData` and return a `StaffSiteResource` with zero authorization checks. The `AllSiteData` view includes PII-adjacent fields (street address, bio, location). Without a Policy, every staff-authenticated request can read any professional's site — no audit log, no least-privilege boundary. The resolved professional from `$request->attributes` (Category 2) must be passed to `authorizeForUser`.
    - **Plain English:** There's a staff-only back door that lists every professional's site data. Anyone with staff credentials can look up any user's subdomain and get their full profile, settings, and address. The fix is to add a locked door — a policy that checks whether this particular staff member should be able to view this particular site — so access is logged and auditable.
    - **Evidence:**
        ```php
        // StaffSiteController.php:26-40
        public function show(string $subdomain): JsonResponse
        {
            $row = AllSiteData::query()
                ->whereRaw('lower(subdomain) = lower(?)', [$subdomain])
                ->first();

            if (! $row) {
                return $this->error('Site not found.', 404);
            }

            return $this->success(new StaffSiteResource($row));
        }

        public function showByProfessional(User $professional): JsonResponse
        {
            $row = AllSiteData::query()
                ->where('user_id', $professional->id)
                ->first();

            if (! $row) {
                return $this->error('Site not found for professional.', 404);
            }

            return $this->success(new StaffSiteResource($row));
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-2** · P2 — UserSiteController::update() and ::visibility() skip Policy authorization; rely on inline relationship ownership in UpdateSiteAction
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:33-55 and app/Services/Site/UpdateSiteAction.php:48-53
    - **Affects:** Site mutations (subdomain, settings, publish state, skeleton) from the professional dashboard. Currently gated only by the professional→site relationship, not a Policy gate.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create a `SitePolicy` extending `BasePolicy` with `update` and `updateVisibility` abilities.
        - In `UserSiteController::update()` and `::visibility()`, call `$this->authorizeForUser($professional, 'update', $site)` before mutating.
        - Move the ownership check from `UpdateSiteAction::execute()` into the Policy's `update()` method so it's centrally testable.
    - **Technical:** The Authorization Doctrine (Category 2) requires `authorizeForUser($pro, 'verb', $resource)` for every mutating endpoint. `UserSiteController::update()` resolves the professional via `currentUser()` but never calls `currentSite()` or any Policy gate before passing to `UpdateSiteAction::execute()`. The action resolves the site through `$professional->site` (a relationship traversal that functions as inline authorization). While this is functionally correct for the one-site-per-professional model, it bypasses the Policy layer — no central audit surface, no `denyIfPendingDeletion()` guard, and no testable authorization boundary. The `::visibility()` method has the same gap.
    - **Plain English:** When a professional edits their site, the system checks "does this person own a site?" and uses whatever site they own. It works today because everyone has exactly one site. But if that ever changes — or if a site is in a weird state like pending deletion — there's no security checkpoint that says "this person is allowed to edit *this specific site*." The fix moves that check into a proper gate that can be audited and tested in one place.
    - **Evidence:**
        ```php
        // UserSiteController.php:33-38
        public function update(UpdateSiteRequest $request, UpdateSiteAction $action)
        {
            $professional = $this->currentUser($request);
            $data = $request->validated();
            // ... design_kit extraction ...
            $site = $action->execute($professional, $data);
            // No authorizeForUser call before mutation
        ```

        ```php
        // UpdateSiteAction.php:48-53
        public function execute(User $professional, array $data, array $options = []): Site
        {
            $professional->loadMissing('site');
            $site = $professional->site;   // inline ownership resolution
            if (! $site) {
                throw ValidationException::withMessages(['site' => ['Professional has no site.']]);
            }
        ```
    - `[DRAFT, confidence: 0.85]`
