- [ ] **#SEC-1** · P1 — StaffLinkBlockManagementController uses inline `abort_unless` ownership checks instead of `authorizeForUser` + Policy
    - **Where:** app/Http/Controllers/Api/Staff/UserSiteManagement/StaffLinkBlockManagementController.php:81-85 (update), :90-94 (destroy)
    - **Affects:** Staff users managing link blocks — ownership still enforced but bypasses Policy system (no pending-deletion guard, no audit trail via Policy)
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace `abort_unless($linkBlock->user_id === $professional->id && ..., 404)` with `$this->authorizeForUser($staff, 'update', $linkBlock)`
        - Register or confirm `BlockPolicy` handles `update`/`delete` abilities with ownership + pending-deletion checks via `BasePolicy`
        - Apply same pattern to `destroy()`, `reorder()`
    - **Technical:** Category 2. The doctrine mandates `authorizeForUser` through Policies — never inline `abort_unless` with ownership comparisons. Inline checks skip `denyIfPendingDeletion()` (423 on soft-deleted resources) and central auditability. CI rejects inline 403 aborts in controllers; these 404 aborts are the same anti-pattern under a different status code. The scoped binding comment acknowledges ownership but doesn't replace the Policy call.
    - **Plain English:** The staff dashboard has a shortcut for checking who owns a link block — it checks the ID directly instead of going through the building's security desk. It works, but bypasses the central logbook and the "account pending deletion" lock that the proper Policy system provides. Every staff action on a professional's content should go through the same Policy gate, so we never have to wonder which doors are locked and which have a handwritten note.
    - **Evidence:**
        ```php
        // StaffLinkBlockManagementController::update, line 81-85
        abort_unless(
            $linkBlock->user_id === $professional->id &&
            $linkBlock->block_group === 'links' &&
            $linkBlock->block_type === 'link',
            404
        );
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-2** · P1 — StaffSectionManagementController uses query-scoped ownership without `authorizeForUser` Policy gates
    - **Where:** app/Http/Controllers/Api/Staff/UserSiteManagement/StaffSectionManagementController.php (index, upsert, reorder, remove — all methods)
    - **Affects:** Staff users managing section blocks — every operation scopes by `user_id` in the query but never invokes a Policy
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `$this->authorizeForUser($staff, 'view', $professional)` (or equivalent `staffViewSections` ability) at the top of `index()`
        - For `upsert()`, add a Policy gate on the professional's site or on the block skeleton before the upsert transaction
        - For `reorder()`, add a Policy gate confirming the staff member can manage sections for this professional
    - **Technical:** Category 2. All methods scope queries via `->where('user_id', $professional->id)` — this correctly isolates by tenant, but the architecture requires a Policy call so that `denyIfPendingDeletion()`, role restrictions, and audit coverage apply uniformly. A query scope alone is invisible to `PolicyCoverageTest` and doesn't fire the pending-deletion guard. The `upsert` method additionally creates blocks without a pre-create skeleton-authorization step.
    - **Plain English:** The staff section editor checks "does this section belong to this professional" by filtering the database query, like looking through a keyhole to confirm you're in the right room. But it never badges in at the front desk. The Policy system is the front desk — it checks whether this staff member is allowed to touch this professional's content at all, and whether the account is in a deletion grace period. Every staff operation should badge in first, then look through the keyhole.
    - **Evidence:**
        ```php
        // StaffSectionManagementController::index, line ~32
        $sections = Block::query()
            ->where('user_id', $professional->id)
            ->where('block_group', 'sections')
            ->orderBy('sort_order')
            ->get();
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-3** · P1 — StaffAccountDeletionController missing `authorizeForUser` on professional resource operations
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffAccountDeletionController.php (initiate:22, cancel:52, show:71)
    - **Affects:** Staff-triggered account deletion — any staff member can initiate/cancel/view deletion state for any professional without a per-resource Policy gate
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `$this->authorizeForUser($staff, 'staffManage', $professional)` at the top of `initiate()`, `cancel()`, and `show()`
        - Confirm `UserPolicy::staffManage()` is registered and enforces the correct staff-role gating (admin-only for deletion initiation)
    - **Technical:** Category 2. The staff middleware proves staff identity, but the doctrine requires `authorizeForUser` on each individual resource operation — "the middleware proves staff identity; a Policy proves they can act on this specific resource." Account deletion is the most destructive staff action; a Policy gate here ensures role restrictions (e.g., only admin staff can initiate deletion) are enforced in one central, testable place rather than scattered across route middleware configuration.
    - **Plain English:** The account deletion tool is behind the staff-only door, which is good. But once inside, there's no second lock — any staff member can delete any professional's account. The Policy system is that second lock: it checks "is this specific staff member allowed to delete this specific account?" Right now the answer is always yes for anyone with a staff badge. We need the Policy to say "only senior staff" or "only with a reason logged."
    - **Evidence:**
        ```php
        // StaffAccountDeletionController::initiate — no authorizeForUser call
        public function initiate(
            StaffInitiateDeletionRequest $request,
            User $professional,
        ): JsonResponse {
            /** @var PartnaStaff $staff */
            $staff = $request->attributes->get('partna_staff');

            $result = $this->deletionService->adminInitiate(
                professional: $professional,
                // ... no Policy gate before acting on $professional
            );
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SEC-4** · P2 — Multiple staff controllers operate on professional resources without `authorizeForUser`
    - **Where:**
        - app/Http/Controllers/Api/Staff/StaffSite/StaffAnalyticsController.php:44 (summary)
        - app/Http/Controllers/Api/Staff/StaffSite/StaffEmailSubscriberController.php:29 (index), :91 (export)
        - app/Http/Controllers/Api/Staff/StaffSite/StaffEnquiryController.php:21 (index)
        - app/Http/Controllers/Api/Staff/StaffSite/StaffSiteController.php:20 (showByProfessional)
        - app/Http/Controllers/Api/Staff/StaffSite/StaffWorkplaceController.php:17 (show)
        - app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationController.php:147 (indexForProfessional)
        - app/Http/Controllers/Api/Staff/UserSiteManagement/StaffDataExportController.php:29 (store)
        - app/Http/Controllers/Api/Staff/UserSiteManagement/StaffSiteManagementController.php:17 (update)
    - **Affects:** Staff read/write operations — analytics, email subscribers, enquiries, site data, workplace, notifications, data exports, site management
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `$this->authorizeForUser($staff, 'staffManage', $professional)` (or a narrower per-controller ability like `staffViewAnalytics`, `staffViewSubscribers`) at the top of each affected method
        - Register these abilities on `UserPolicy` or split into dedicated Policies per resource type
        - For read-only endpoints (analytics, subscribers, enquiries, workplace), a single `staffView` ability on the professional may suffice; for write endpoints (site management update, data export), use `staffManage`
    - **Technical:** Category 2. The staff middleware (`staff` / `staff.admin`) proves the requester is a staff member, but the architecture requires a Policy call on every individual resource operation. Without it, there's no central place to add role gating (e.g., "only admin staff can export data"), no `denyIfPendingDeletion()` guard, and no audit trail through Policy events. `StaffCustomerManagementController` and `StaffServiceManagementController` demonstrate the correct pattern — these eight controllers should follow suit.
    - **Plain English:** Eight staff tools open the door with a staff badge but never check the room number. They assume any staff member can look at any professional's analytics, subscribers, enquiries, and site data. The Policy system is the room-key check — it would let us say "support staff can view enquiries but only admins can trigger a data export." Right now every staff member has a master key to every room. Adding `authorizeForUser` puts a policy-check lock on each door that we can configure centrally.
    - **Evidence:**
        ```php
        // StaffAnalyticsController::summary — no authorizeForUser
        public function summary(Request $request, User $professional): JsonResponse
        {
            // ... directly queries analytics for $professional with no Policy gate

        // StaffDataExportController::store — no authorizeForUser
        public function store(
            RequestStaffDataExportRequest $request,
            User $professional,
        ): JsonResponse {
            /** @var PartnaStaff $staff */
            $staff = $request->attributes->get('partna_staff');
            // ... dispatches export for $professional with no Policy gate
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-5** · P2 — SiteVisibilityController uses inline ownership scope instead of Policy gate
    - **Where:** app/Http/Controllers/Api/PublicSite/SiteVisibilityController.php:28-30
    - **Affects:** Professional toggling their own site visibility — ownership checked via query scope, bypassing Policy
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Site::query()->where('user_id', $professional->id)->firstOrFail()` with a `$this->authorizeForUser($professional, 'update', $site)` call after resolving the site
        - Ensure `SitePolicy::update()` is registered and checked
    - **Technical:** Category 2. The controller correctly resolves the actor via `$request->attributes->get('professional')` (doctrine-compliant), but then scopes the site query by `user_id` inline instead of calling `authorizeForUser`. The inline scope works but bypasses `denyIfPendingDeletion()` — if the professional's account is in the 30-day deletion grace period, the Policy would return 423, but the inline query returns the site and allows the toggle.
    - **Plain English:** The publish/unpublish toggle checks "does this site belong to this professional" by filtering the database query. This is like checking a name on a mailbox instead of using the building's access card system. It works day-to-day, but if the professional's account is scheduled for deletion, the Policy system would lock the door — the inline check wouldn't. Every resource operation should go through the Policy card reader.
    - **Evidence:**
        ```php
        // SiteVisibilityController::update, line 28-30
        $site = Site::query()
            ->where('user_id', $professional->id)
            ->firstOrFail();

        $site->published = (bool) $request->validated('published');
        $site->save();
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SEC-6** · P2 — PublicConfigController exposes Google Maps API key on unauthenticated endpoint
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicConfigController.php:58
    - **Affects:** Public visitors — the Google Maps API key is returned to any unauthenticated client
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Verify the Google Maps API key is restricted to HTTP referrers matching `*.partna.au` in the Google Cloud Console
        - If not already restricted, either restrict it or move the key to a server-side proxy endpoint instead of exposing it client-side
        - Document the restriction in the config comment so future key rotations preserve the restriction
    - **Technical:** Category 5. The endpoint returns `config('services.google_maps.api_key')` on `GET /api/public/config/integrations` — an unauthenticated, CDN-cacheable route. The code comment acknowledges this and asserts the key "must be HTTP-referrer-restricted." If that restriction is in place at the Google Cloud Console level, the risk is contained. If the key was provisioned without a referrer restriction, any third party could consume it against the project's quota. This is defense-in-depth: the comment is documentation, not enforcement.
    - **Plain English:** The frontend config endpoint hands out the Google Maps key to anyone who asks, like leaving a spare office key under the public welcome mat. The note on the mat says "this key only works if you're calling from our building" — which is true if Google is enforcing that rule. But if someone forgot to tell Google about the restriction, the key works from anywhere. We should verify the lock is on at Google's end, not just assume the note on the mat is enough.
    - **Evidence:**
        ```php
        // PublicConfigController::integrations, line 58
        return $this->successCached([
            'googleMapsApiKey' => config('services.google_maps.api_key'),
        ]);
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **#SEC-7** · P2 — StaffNotificationController::store() and StaffUserController::updateStatus()/bulkUpdateStatus() use inline `$request->validate()` instead of Form Request classes
    - **Where:**
        - app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationController.php:27-46 (store)
        - app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php:120-124 (updateStatus), :148-157 (bulkUpdateStatus)
    - **Affects:** Staff notification creation and professional status changes — validation rules live in the controller, not in dedicated Form Request classes
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract `store()` validation rules into a `StoreStaffNotificationRequest` Form Request class
        - Extract `updateStatus()` and `bulkUpdateStatus()` validation into dedicated Form Request classes
        - Register them on the route definitions so validation runs before the controller method
    - **Technical:** Category 6. The architecture requires every `POST`/`PATCH`/`PUT` route to resolve a `FormRequest` class. Inline `$request->validate()` skips the Form Request lifecycle — no `authorize()` method, no pre-validation hooks, harder to test in isolation, and invisible to `php artisan route:list` validation column. The notification store has complex rules (category allowlist, date constraints, conditional email fields) that belong in a dedicated class.
    - **Plain English:** Three staff endpoints write their validation rules on a sticky note inside the controller instead of using the standard Form Request filing system. It works, but the rules can't be reused, tested independently, or auto-documented. It's like having building codes written on the wall of each room instead of in the central permit office. Extract them into proper Form Request files so every mutation endpoint follows the same validation pattern.
    - **Evidence:**
        ```php
        // StaffNotificationController::store, line 27
        $data = $request->validate([
            'user_id' => ['nullable', 'uuid'],
            'type' => ['required', 'string', 'max:50'],
            // ... 15 more inline rules
        ]);

        // StaffUserController::updateStatus, line 120
        $data = $request->validate([
            'status' => ['required', 'string', 'in:active,suspended'],
        ]);
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#SEC-8** · P2 — StaffUserController::index() exposes PII (primary_email, phone) for all professionals in list view
    - **Where:** app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php:47-65
    - **Affects:** All staff roles accessing the professional list — every professional's email and phone are returned regardless of staff role
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Either remove `primary_email` and `phone` from the list view (search by email still works server-side without returning it)
        - Or gate these fields behind a staff role check (`$staff->isAdmin()`) so only admin staff see PII in list context
        - Align with `StaffAccountDeletionController::show()` which explicitly selects non-PII columns for support staff
    - **Technical:** Category 10. The index endpoint returns `primary_email` and `phone` for every professional in the paginated list. `StaffAccountDeletionController::show()` explicitly omits PII columns, stating "support staff don't need staff identity; admin investigations can hit the DB directly." The list view should follow the same principle — search and filtering can use email/phone server-side without exposing them in the response payload to every staff role.
    - **Plain English:** The staff directory lists every professional's email and phone number on the main search page — like a reception desk that shows every tenant's personal contact details to anyone with a staff badge. The account deletion tool already hides this info for support staff. The main directory should follow the same rule: use email for searching but don't display it. Admin staff who need the full list can use a dedicated detail view or DB access.
    - **Evidence:**
        ```php
        // StaffUserController::index, line 47-53
        $professionals = $page->getCollection()->map(function (User $p) {
            return [
                'id' => $p->id,
                'handle' => $p->handle,
                'display_name' => $p->display_name,
                'status' => $p->status,
                'primary_email' => $p->primary_email,
                'phone' => $p->phone,
                // ...
            ];
        });
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#SEC-9** · P3 — StaffFeatureFlagController and StaffFeatureFlagOverrideController use inline `abort_if` for staff auth re-check
    - **Where:**
        - app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagController.php:23,33,43,53 (every method)
        - app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagOverrideController.php:21,31,65
    - **Affects:** Staff feature flag management — redundant auth check is defense-in-depth but inconsistent with Policy pattern
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the inline `abort_if($request->attributes->get('partna_staff') === null, 401)` — the staff middleware already guarantees this attribute is set
        - If additional role gating is desired (admin-only), add a Policy ability rather than an inline check
    - **Technical:** Category 2. Feature flags are platform-level models, not tenant-owned, so the strict Policy-per-resource requirement is softer here. The inline `abort_if` is checking authentication (is there a staff actor?) which the `staff` / `staff.admin` middleware already guarantees. It's harmless defense-in-depth but sets a precedent for inline auth checks that could drift into authorization territory. The staff middleware attribute check is sufficient; remove the redundant inline abort or replace with a Policy gate if role restrictions are needed.
    - **Plain English:** The feature flag tools check the staff badge twice — once at the door (middleware) and again at each desk (inline code). The second check doesn't hurt but it's inconsistent with how other staff tools work. It's like having two security guards check the same ID. Pick one — the door guard is enough, and it keeps the pattern consistent across all staff endpoints.
    - **Evidence:**
        ```php
        // StaffFeatureFlagController::index, line 23
        abort_if($request->attributes->get('partna_staff') === null, 401, 'Unauthenticated');
        ```
    - `[DRAFT, confidence: 0.75]`
