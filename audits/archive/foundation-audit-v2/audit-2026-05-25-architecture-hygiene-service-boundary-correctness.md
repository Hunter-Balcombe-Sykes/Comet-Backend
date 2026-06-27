`★ Insight ─────────────────────────────────────`
The **proxy-actor pattern** for staff controllers is subtle but important: `authorizeForUser($professional, 'update', $block)` passes the *professional being managed* as the Gate actor — this lets existing user-typed policies validate child-resource ownership (`block.professional_id === professional.id`) without requiring staff-specific policy methods. It works for any resource that has a `professional_id` FK. It breaks only when the resource being mutated *is* the professional record itself.
`─────────────────────────────────────────────────`

# Architecture Hygiene, Service Boundary Correctness & Dead Code Audit — 2026-05-25

**Branch:** development
**Lens:** Architecture hygiene · service boundary correctness · dead code post-standalone-strip
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffProfessionalController.php`
- `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffSectionManagementController.php`
- `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffCustomerManagementController.php`
- `app/Http/Controllers/Api/Professional/Customers/ProfessionalEnquiryController.php`
- `app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalSectionBlockController.php`
- `app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalServiceController.php`
- `app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalServiceCategoryController.php`
- `app/Http/Controllers/Api/Professional/Account/ProfessionalDocumentController.php`
- `app/Services/Media/PlaceholderLimitExceededException.php`
- `app/Policies/SitePolicy.php`
- `app/Policies/PartnaStaffPolicy.php`
- `app/Policies/ProfessionalSelfPolicy.php`
- `app/Policies/BasePolicy.php`
- `app/Providers/AppServiceProvider.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 2 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 2 complete

---

## P1 — Fix before pilot launch

- [ ] **#HYGN-1** · P1 — Staff professional controller exposes permanent delete and bulk status changes to any authenticated staff member without role check
    - **Where:** `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffProfessionalController.php:118–248`
    - **Affects:** Any `partna_staff`-authenticated user — including support-level staff — can permanently delete professionals (`forceDestroy`), bulk-suspend accounts (`bulkUpdateStatus`), and overwrite profile data (`update`) without admin-role enforcement. `StaffCustomerManagementController` in the same namespace correctly gates every write via `authorizeForUser`.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - **Do not** use `authorizeForUser($professional, 'update', $professional)` as the fix. `ProfessionalSelfPolicy::update` calls `denyIfPendingDeletion($actor)` expecting the professional to be acting on their own account; passing the professional as both actor and resource produces a tautological self-check with no role discrimination, and would block staff from restoring a pending-deletion account.
        - Extend `PartnaStaffPolicy` with methods typed to accept a `User` target (not a `PartnaStaff` target): `manageProfessional(PartnaStaff $staff, User $professional): bool` returning `true` for any staff, and `forceDeleteProfessional(PartnaStaff $staff, User $professional): bool` returning `$staff->isAdmin()` only. Add a matching entry to `AppServiceProvider::boot()`.
        - In the controller, resolve `$staff = $request->attributes->get('partna_staff')` and gate accordingly: `$this->authorizeForUser($staff, 'manageProfessional', $professional)` before `updateStatus`, `update`, `destroy`, and `restore`; `$this->authorizeForUser($staff, 'forceDeleteProfessional', $professional)` before `forceDestroy`; and inline staff-actor resolution before `bulkUpdateStatus` (which has no route-bound `$professional`).
        - `PartnaStaffPolicy` already uses this exact shape for staff-on-staff operations (`view`, `update`, `delete` accepting `PartnaStaff $actor, PartnaStaff $target`) — new methods follow the same convention with `User` as the target type.
    - **Technical:** The `partna_staff` middleware validates that a valid staff JWT is present, but all staff members reach every route regardless of role. `PartnaStaffPolicy` already demonstrates the correct role-checking pattern (`isAdmin()` guards `update` and `delete` for staff-on-staff operations). The gap here is that no equivalent gate exists for staff-on-professional operations. `ProfessionalSelfPolicy` (registered for `User::class`) cannot be reused: its `update` method calls `denyIfPendingDeletion`, which would incorrectly block staff from modifying or restoring pending-deletion accounts — a key staff capability. Verified: zero `authorizeForUser` calls in the controller via grep.
    - **Plain English:** Your staff portal lets two types of employees log in: admins and support staff. Right now there's nothing stopping a support staff member from hitting the "permanently delete this user's account" button — they have the exact same power as an admin. It's like every employee at a bank having access to the "close account permanently" terminal, not just the managers. The fix adds a rule the system enforces automatically: permanent deletion and bulk suspensions require an admin account, not just any staff login.
    - **Evidence:**
        ```php
        public function forceDestroy(User $professional): JsonResponse
        {
            // Hard delete - PERMANENT
            $handle = $professional->handle;

            try {
                $professional->forceDelete();

                return $this->success([
                    'message' => "Professional '{$handle}' permanently deleted",
                    'permanently_deleted' => true,
                ]);
            } catch (Exception $e) {
                return $this->error(
                    'Cannot delete:  Professional has related data that must be removed first.',
                    409
                );
            }
        }
        ```

- [ ] **#HYGN-2** · P1 — Staff section management controller writes site sections with no authorization gate
    - **Where:** `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffSectionManagementController.php:37–end`
    - **Affects:** Any authenticated staff member can upsert, reorder, or remove site sections for any professional without an ownership-validation or role check. `StaffServiceManagementController` in the same namespace correctly calls `authorizeForUser` before every write; this controller is the outlier.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - For `upsert` when creating a new block: `$skeleton = new Block(['professional_id' => $professional->id, 'site_id' => $site->id]); $this->authorizeForUser($professional, 'create', $skeleton);`
        - For `upsert` on an existing block, `reorder`, and `remove`: resolve the block and call `$this->authorizeForUser($professional, 'update', $block)`. `Gate::policy(Block::class, SitePolicy::class)` is already registered; `SitePolicy::update` checks `$block->professional_id === $professional->id`.
        - This follows the identical pattern used by `StaffCustomerManagementController::update` and `StaffServiceManagementController` — pass the professional as the actor, the child resource as the target. The `partna_staff` middleware remains the role gate; the policy call validates resource ownership.
        - **Note:** `SitePolicy::update` calls `denyIfPendingDeletion($professional)`, which would return 423 if the professional is in the deletion window. Decide whether staff should bypass this (if so, add a `before()` hook or a separate `staffUpdate` ability that skips the pending-deletion check).
    - **Technical:** Unlike HYGN-1, this controller operates on child resources (`Block` models) that carry `professional_id` directly, so the existing proxy-actor pattern works without a new policy. The `SitePolicy` is fully wired; the controller simply never calls it. Verified: zero `authorizeForUser` calls in the file via grep. `StaffServiceManagementController` (same namespace) calls `$this->authorizeForUser($professional, 'view', $service)` and `$this->authorizeForUser($professional, 'update', $service)` for every analogous operation — an exact template.
    - **Plain English:** This is the staff panel's "manage this user's website sections" feature. Any support staff member can add, reorder, or remove sections from any user's public site page with no record of who authorized the change and no check that the section actually belongs to the account they're editing. The fix is a one-liner before each write that verifies "this section belongs to the professional we're editing" — the same check every other staff controller already makes.
    - **Evidence:**
        ```php
        public function upsert(UpsertSectionBlockRequest $request, User $professional, string $blockType): JsonResponse
        {
            $site = $this->currentSite($professional);

            $data = $request->validated();

            $block = DB::transaction(function () use ($professional, $site, $data, $blockType) {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ["blocks-sections:{$site->id}"]);

                $block = Block::query()->firstOrNew([
                    'professional_id' => $professional->id,
                    'site_id' => $site->id,
                    'block_group' => 'sections',
                    'block_type' => $blockType,
                ]);
                // ... no authorizeForUser call
                $block->save();

                return $block->fresh();
            });
        ```

---

## P2 — Should fix

- [ ] **#HYGN-3** · P2 — `ProfessionalEnquiryController` uses query-scoping for ownership instead of policy gate
    - **Where:** `app/Http/Controllers/Api/Professional/Customers/ProfessionalEnquiryController.php:36–75`
    - **Affects:** `update` and `destroy` validate ownership via `->where('professional_id', $pro->id)` embedded in the fetch query, not via `authorizeForUser`. Functionally correct today, but: (a) skips the `denyIfPendingDeletion` check in `SitePolicy`, allowing pending-deletion professionals to mark enquiries read or delete them during the 30-day window; (b) couples access control to the retrieval path — a future refactor that changes the query silently removes the ownership check.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - After resolving `$enquiry`, add `$this->authorizeForUser($pro, 'update', $enquiry)` in `update` and `$this->authorizeForUser($pro, 'delete', $enquiry)` in `destroy`. `Gate::policy(Enquiry::class, SitePolicy::class)` is already registered; `SitePolicy` resolves ownership via `$resource->getAttributes()['professional_id']` for `Enquiry` models.
        - The manual `if (! $enquiry) { return $this->error('Enquiry not found.', 404); }` null-guard can be replaced with `findOrFail()` + policy `denyAsNotFound()`, matching the pattern in `ProfessionalCustomerController`.
    - **Technical:** `SitePolicy::update` checks `professional_id` and calls `denyIfPendingDeletion($actor)`. The current query-scoping produces the same ownership result as the policy's ownership check, but it cannot enforce the deletion-state invariant. `ProfessionalCustomerController` (same namespace) uses `authorizeForUser` for every write; the enquiry controller is the only read-then-mutate pattern in the namespace that skips it. The `POLICY_EXEMPT` allowlist in `PolicyCoverageTest` does not cover `Enquiry`, so policy registration is enforced — but the test only asserts registration, not that the controller calls the policy.
    - **Plain English:** Think of the ownership check here like a nightclub that checks your ID when you walk in but not when you try to get backstage. The enquiry controller confirms "this enquiry is yours" by baking it into the database lookup, which works — but it's separate from the standard security layer that also checks "is your account scheduled for deletion?" The fix routes it through the standard layer so both checks happen automatically.
    - **Evidence:**
        ```php
        public function update(Request $request, string $id): JsonResponse
        {
            $pro = $this->currentProfessional($request);

            $enquiry = Enquiry::query()
                ->where('professional_id', $pro->id)
                ->find($id);

            if (! $enquiry) {
                return $this->error('Enquiry not found.', 404);
            }

            $request->validate([
                'read' => ['required', 'boolean'],
            ]);

            $enquiry->read_at = $request->boolean('read') ? now() : null;
            $enquiry->save();
        ```

- [ ] **#HYGN-4** · P2 — `ProfessionalSectionBlockController` write paths skip `authorizeForUser` gate
    - **Where:** `app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalSectionBlockController.php:110–326` (`upsert`, `reorder`, `remove`)
    - **Affects:** Section block mutations rely on implicit multi-field query scoping (`where('professional_id', $pro->id)` + `where('site_id', $site->id)`) rather than `authorizeForUser`. Cross-tenant isolation is intact today, but the `denyIfPendingDeletion` gate in `SitePolicy` is bypassed, so pending-deletion professionals can reorder and toggle sections during the 30-day window. `ProfessionalGalleryController::reorder` (same feature layer) uses the skeleton pattern correctly.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `upsert`: after `currentSite($pro)`, add `$skeleton = new Block(['professional_id' => $pro->id, 'site_id' => $site->id]); $this->authorizeForUser($pro, 'create', $skeleton);`
        - In `reorder` and `remove`: add `$this->authorizeForUser($pro, 'update', $site)` after resolving `$site` — `SitePolicy` covers `Site` directly and is the logical resource being reordered. Alternatively, resolve any block and pass it: both paths route through `SitePolicy::update`.
        - `Gate::policy(Block::class, SitePolicy::class)` is already registered.
    - **Technical:** The existing two-field scope (`professional_id` + `site_id`) is strong enough for cross-tenant isolation. The policy gap is specifically `denyIfPendingDeletion`: `SitePolicy::update` calls it as the first check before any ownership comparison, meaning a professional in the deletion window would correctly receive 423 on any block write — but only if the policy is invoked. Without `authorizeForUser`, the deletion-window invariant is enforced on gallery uploads, service edits, and document updates (all of which call the policy) but not on section reordering. Verified: zero `authorizeForUser` calls in the file via grep.
    - **Plain English:** The section block controller correctly checks "does this block belong to your site" in every query — the ownership check is real. The gap is a separate rule: accounts scheduled for deletion should be read-only. Gallery uploads, service edits, and document changes all enforce this. Section reordering and toggling doesn't. The fix wires the reorder/toggle paths through the same gate the rest of the app uses.
    - **Evidence:**
        ```php
        public function reorder(ReorderBlocksRequest $request)
        {
            $pro = $this->currentProfessional($request);
            $site = $this->currentSite($pro);

            $ids = array_values(array_unique($request->validated()['ids'] ?? []));

            DB::transaction(function () use ($pro, $site, $ids) {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ["blocks-sections:{$site->id}"]);

                $allIds = Block::query()
                    ->where('professional_id', $pro->id)
                    ->where('site_id', $site->id)
                    ->where('block_group', 'sections')
                    // ...
                    ->pluck('id')
                    ->all();
                // no authorizeForUser call
            });
        }
        ```

- [ ] **#HYGN-5** · P2 — Service and ServiceCategory `reorder` endpoints skip `authorizeForUser` while all other mutation methods have it
    - **Where:** `app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalServiceController.php:204` (`reorder`, `reorderLayout`) · `app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalServiceCategoryController.php` (`reorder`)
    - **Affects:** `store`, `update`, `destroy`, and `restore` in both controllers correctly call `authorizeForUser`, enforcing `ServicePolicy::denyIfPendingDeletion`. The `reorder` methods skip it, meaning pending-deletion professionals can still rearrange their service listing during the 30-day window.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `ProfessionalServiceController::reorder` and `reorderLayout`: after resolving `$pro`, add a skeleton gate: `$skeleton = new Service(['professional_id' => $pro->id]); $this->authorizeForUser($pro, 'update', $skeleton);`
        - Same in `ProfessionalServiceCategoryController::reorder` using `ServiceCategory`.
        - `Gate::policy(Service::class, ServicePolicy::class)` and `Gate::policy(ServiceCategory::class, ServicePolicy::class)` are both registered in `AppServiceProvider::boot()`.
    - **Technical:** `ServicePolicy::update` calls `denyIfPendingDeletion` before the ownership check. The reorder paths update `sort_order` via a raw `DB::transaction` that queries by `professional_id` (ownership is implicitly scoped), so the cross-tenant isolation is sound. The missing gate affects only the deletion-state invariant — two parallel write paths (CRUD vs. reorder) that should both enforce the same lifecycle rule but don't. The inconsistency is also a maintenance hazard: `PolicyCoverageTest` asserts policy registration but not invocation, so a future developer seeing that `store`/`update` call the policy may assume `reorder` does too.
    - **Plain English:** Every button that adds, edits, or removes a service on the dashboard correctly checks "has this account requested deletion?" before allowing changes. The drag-to-reorder handle skips that check. It's a small gap, but deletion-window read-only mode should be total — not "you can't add new services but you can rearrange them."
    - **Evidence:**
        ```php
        public function reorder(ReorderServiceRequest $request): JsonResponse
        {
            $pro = $this->currentProfessional($request);

            $ids = array_values(array_unique($request->validated()['ids']));

            DB::transaction(function () use ($pro, $ids) {

                $allIds = Service::query()
                // ... updates sort_order without authorizeForUser
            });
        }
        ```

---

## P3 — Nice to have

- [ ] **#HYGN-6** · P3 — `PlaceholderLimitExceededException` is unreferenced dead code
    - **Where:** `app/Services/Media/PlaceholderLimitExceededException.php`
    - **Affects:** No runtime behavior. Creates ambiguity about which exception governs upload limits — the active exception is `PoolLimitExceededException` at `app/Services/Media/Exceptions/PoolLimitExceededException.php`, thrown by `MediaUploadService` and caught in `ProfessionalUploadController`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Delete `app/Services/Media/PlaceholderLimitExceededException.php`.
        - Grep for test fixtures referencing it before deleting; none found in the codebase scan (grep across `app/**/*.php` returns only the class definition itself).
    - **Technical:** The class pre-dates the standalone strip. Its successor `PoolLimitExceededException` is the v2 pool-limit exception; both use `\DomainException` as their base but `PlaceholderLimitExceededException` is never thrown, caught, or imported anywhere in the codebase. Keeping it risks a future developer writing `catch (PlaceholderLimitExceededException $e)` expecting upload-limit semantics and silently missing `PoolLimitExceededException` instances.
    - **Plain English:** There's a leftover error class from an older version of the codebase that was replaced and never cleaned up. It's like having two manual pages for the same alarm panel when one of them is for the old panel that was removed. Harmless now, but someone will eventually read the wrong one during an incident.
    - **Evidence:**
        ```php
        class PlaceholderLimitExceededException extends \DomainException
        {
            public function __construct(int $max)
            {
                parent::__construct("Placeholder image limit reached (max {$max}).");
            }
        }
        ```

- [ ] **#HYGN-7** · P3 — `ProfessionalDocumentController::store` skips `authorizeForUser` while `update` and `destroy` have it
    - **Where:** `app/Http/Controllers/Api/Professional/Account/ProfessionalDocumentController.php:45–130`
    - **Affects:** Pending-deletion professionals can upload a new document (bypassing `SitePolicy::create`'s `denyIfPendingDeletion` check) even though they cannot update the document's title or delete it on the same controller. The flat-replace semantics mean a successful upload also soft-deletes any previous document — a mutating operation not gated by the deletion-window rule.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - After `$site = $this->currentSite($pro)` and before the `DB::transaction`, add: `$skeleton = (new SiteMedia(['site_id' => $site->id]))->setRelation('site', $site); $this->authorizeForUser($pro, 'create', $skeleton);`
        - This mirrors `ProfessionalUploadController::upload` exactly and invokes `SitePolicy::create`, which calls `denyIfPendingDeletion` before the ownership check.
    - **Technical:** `SitePolicy::create` calls `denyIfPendingDeletion($actor)` as the first check. The `setRelation('site', $site)` call is required because `SiteMedia` resolves ownership through the `site` relation rather than a direct `professional_id` column — omitting it causes `resolveOwnerId` to return `null` and the policy to deny. `update` and `destroy` in the same controller already follow the correct `setRelation` + `authorizeForUser` pattern. `ProfessionalUploadController::upload` uses the same skeleton shape.
    - **Plain English:** When a user schedules their account for deletion, there's a 30-day waiting period where their dashboard should go read-only. The "edit document title" and "delete document" buttons on the document page already enforce this correctly. The "upload a new document" button doesn't — so a user who asked to delete their account can still upload replacement files during that window. This is a minor edge case (someone deleting their account probably isn't uploading documents), but the inconsistency is simple to close and makes the deletion-window guarantee airtight.
    - **Evidence:**
        ```php
        $media = DB::transaction(function () use ($site, $file, $actualMime, $title, $caption, $originalFilename, &$previousPath, &$previousId) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ["site-documents:{$site->id}"]);
            }
            // ... flat-replace logic, then:
            return SiteMedia::create([
                'site_id' => $site->id,
                'pool' => SiteMedia::POOL_DOCUMENTS,
                'path' => '',
                // ...
            ]);
            // no authorizeForUser before SiteMedia::create
        });
        ```
