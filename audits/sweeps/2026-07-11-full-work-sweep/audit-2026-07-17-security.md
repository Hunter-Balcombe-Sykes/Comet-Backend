# Security Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Security: auth boundaries, tenant isolation, mass assignment, inbound callbacks, secrets, injection, SSRF, upload safety, PII exposure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- `app/Policies/EarlyAccessSignupPolicy.php`, `FeatureAvailabilityPolicy.php`, `FeedbackPolicy.php`, `UserSegmentPolicy.php`, `UserSelfPolicy.php`, `IntegrationConnectionPolicy.php`, `SitePolicy.php`
- `app/Providers/AppServiceProvider.php`, `app/Providers/PlatformRegistryServiceProvider.php`
- `app/Http/Controllers/Api/User/Account/UserAccountDeletionController.php`, `UserSelfController.php`
- `app/Http/Controllers/Api/User/Profile/SectorController.php`
- `app/Http/Controllers/Api/User/SiteManagement/CustomDomainController.php`
- `app/Http/Requests/Api/Staff/Segments/StoreSegmentRequest.php`, `Api/Staff/UserSite/StaffUpdateSiteRequest.php`, `Api/User/Site/UpdateSiteRequest.php`, `Api/User/Site/UpsertWorkplaceRequest.php`, `Api/User/UpdateUserRequest.php`, `Concerns/DesignKitValidationRules.php`, `Concerns/SiteOrderingValidationRules.php`, `Platforms/ApplyMenuScanRequest.php`
- `app/Http/Controllers/Api/Platforms/{Booking,DisplaySettings,Fresha,GoogleBusiness,Instagram,Menu,OnlineOrdering,Reservations,Square}Controller.php`
- `app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php`, `PublicMenuController.php`
- `app/Http/Controllers/Api/Staff/Analytics/StaffAggregateAnalyticsController.php`, `Api/Staff/Feedback/StaffFeedbackController.php`, `Api/Staff/UserSiteManagement/StaffUserController.php`
- `app/Http/Resources/PublicSite/IndividualProfileResource.php`, `UserDashboardResource.php`, `Staff/StaffUserListResource.php`, `UserStaffResource.php`
- `app/Services/Design/**` (DesignRationaleService, Presets/*, Scan/EvidenceConclusions, ThemeModePalettes)
- `app/Services/Profile/SectorTaxonomy.php`
- `app/Services/Platforms/{BigCartelScraper,DoorDashMenuDriver,GenericShopScraper,GoogleBusinessAutoSync,IdentitySync,InstagramAutoSync,InstagramScraper,MenuMerger,MenuScanApplier,ShopifyScraper,UberEatsMenuDriver,WebsiteLinkHarvester,WooCommerceScraper}.php`, `Normalizers/FacebookNormalizer.php`, `Payloads/InstagramPayload.php`, `PlatformScraper.php`, `Registry/PlatformDescriptor.php`
- `app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php` (read for cross-file verification)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 7 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

- [ ] **#SEC-1** · P1 — `StaffUserController::show()` leaks full PII + admin notes to non-admin staff, bypassing the file's own documented PII gate
    - **Where:** app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php:96-138 (`show()`), contrasted with `index()`:76-89; leaked fields in app/Http/Resources/UserStaffResource.php:13-42
    - **Affects:** Every professional's `primary_email`, `phone`, `public_contact_number`, full location, `auth_user_id`, and `admin_notes` — exposed to any authenticated staff account (support-tier included), not just admins.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Give `UserStaffResource` the same audience-split constructor `StaffUserListResource` already uses: `new UserStaffResource($professional, bool $showPii)`, redacting `primary_email`, `phone`, `location_*`, `auth_user_id`, and `admin_notes` when `$showPii` is false.
        - In `show()`, derive `$showPii` from `$request->attributes->get('partna_staff')->isAdmin()` exactly as `index()` already does, and pass it through.
        - Add an explicit `authorizeForUser($staff, 'staffManage', $professional)`-style gate is not appropriate for a *read* (that ability is admin-only and would 403 support staff out of a page they're meant to use) — instead add a new `staffView(PartnaStaff $actor, User $target): bool { return true; }` ability on `UserSelfPolicy` (mirrors the `staffManage`/`staffForceDelete` staff-actor pattern already in that file) and call `authorizeForUser($staff, 'staffView', $professional)` in both `index()` and `show()`, so the read path is structurally covered per the audit doctrine and every future read-path addition to this controller inherits the same seam.
    - **Technical:** `index()` explicitly derives `$showPii = $staff && $staff->isAdmin();` and threads it into `StaffUserListResource($p, $showPii)` — the code comment states the intent plainly: *"PII gate: only admin staff may see raw email + phone in the list view."* `show()`, reached via the identical `staff` middleware group (not `staff.admin` — see `routes/api/staff.php:35-67`), has no such gate: it calls `new UserStaffResource($professional)` unconditionally, and `UserStaffResource::toArray()` unconditionally includes `phone`, `primary_email`, `auth_user_id`, full location fields, and `admin_notes` (explicitly commented "Staff-only tribal knowledge"). A support-tier staff account that is deliberately blocked from seeing raw PII on the list view can trivially pivot to `GET /staff/professionals/{id}` and get everything, including internal `auth_user_id` and admin notes never intended for non-admin eyes. Neither `index()` nor `show()` calls `authorizeForUser` at all — all seven of this controller's *mutating* methods (`updateStatus`, `update`, `destroy`, `restore`, `forceDestroy`, `bulkUpdateStatus`) do, several explicitly as "defence-in-depth ... even if the route group ever grants access to support staff" — the same reasoning applies to reads and was simply missed here.
    - **Plain English:** The staff dashboard has a list page that deliberately hides a professional's email and phone number from regular support staff — only admins see that. But the single-professional detail page (one click away from that same list) hands over the email, phone, home address, and private internal staff notes to ANY staff member, admin or not. It's like a filing cabinet where the drawer labelled "summary" is locked for junior staff, but the drawer right next to it labelled "full file" isn't locked at all — and it has the exact same key. Any support agent can open a professional's full record today.
    - **Evidence:**
        ```php
        // index() — the intended PII boundary:
        $staff = $request->attributes->get('partna_staff');
        $showPii = $staff && $staff->isAdmin();
        $professionals = $page->getCollection()->map(
            fn (User $p) => (new StaffUserListResource($p, $showPii))->toArray($request)
        );
        ```
        ```php
        // show() — no gate, no PII split:
        public function show(User $professional): JsonResponse
        {
            $professional->load(['site']);
            $integrations = $professional->integrationConnections()
                ->orderBy('platform')
                ->get(['id', 'platform', 'is_active', 'last_refreshed_at', 'last_refresh_status'])
            // ...
            return $this->success([
                'professional' => new UserStaffResource($professional),
        ```
        ```php
        // UserStaffResource::toArray() — unconditional PII + admin_notes
        'phone' => $this->phone,
        'primary_email' => $this->primary_email,
        // ...
        // Staff-only tribal knowledge — must NEVER appear in UserDashboardResource (/me).
        'admin_notes' => $this->admin_notes,
        ```

## P2 — Should fix

- [ ] **#SEC-2** · P2 — `InstagramScraper` logs Instagram usernames alongside internal `user_id` in warning/info breadcrumbs
    - **Where:** app/Services/Platforms/InstagramScraper.php:45, 57-61, 68-73, 210-216
    - **Affects:** Nightwatch/log-aggregator storage builds a persistent, plaintext join between a public Instagram handle and an internal Partna user UUID.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `'username' => $username` with `'username_hash' => hash('sha256', $username)` in all four `Log::warning`/`Log::info` calls in this file.
        - Keep `user_id` as-is (internal UUID, not third-party PII).
        - Consider extending `PiiLogHygieneSweepTest` to assert scraper log payloads never carry a raw third-party handle.
    - **Technical:** `fetchProfile()` and `latestMedia()` emit `Log::warning`/`Log::info` with both the raw Instagram `username` and internal `user_id` in the same payload. Instagram usernames are public on Instagram, but pairing one with a Partna account UUID inside long-retained log storage creates a durable, joinable identity record that doesn't exist anywhere else in the product. `PiiLogHygieneSweepTest` is the house pattern for this category and currently has no assertion covering this file (`grep` for `username`/`instagram` in that test returns no matches) — this is a genuine, uncovered gap, not a duplicate of an existing check.
    - **Plain English:** When the Instagram-scraping code hits an error, it writes both the person's Instagram handle and their internal Partna account ID into the server logs together. Instagram handles are public, so this isn't leaking a secret — but it quietly builds a permanent record tying "this Instagram account" to "this Partna account" inside a system built for debugging, not for storing identity links. Hashing the handle before logging keeps the logs just as useful for spotting patterns without keeping the plaintext link around.
    - **Evidence:**
        ```php
        Log::warning('instagram.apify.threw', ['username' => $username, 'user_id' => $userId, 'error' => $e->getMessage()]);
        ```
        ```php
        Log::warning('instagram.apify.not_ok', [
            'username' => $username,
            'user_id' => $userId,
            'status' => $response->status(),
        ]);
        ```
        ```php
        Log::info('instagram.latest_media', [
            'user_id' => $userId,
            'posts' => count($posts),
        ```

- [ ] **#SEC-3** · P2 — `GoogleBusinessAutoSync::seed()` / `InstagramAutoSync::seed()` accept a bare `$userId` string with no internal tenant guard
    - **Where:** app/Services/Platforms/GoogleBusinessAutoSync.php:57 (`seed()`), app/Services/Platforms/InstagramAutoSync.php:63 (`seed()`)
    - **Affects:** Currently safe — both call sites (`GoogleBusinessEnrichJob.php:137`, `InstagramConnectJob.php:250`) derive `$userId` from the queued job's own stored state, never from request input. No confirmed exploit path exists today.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - No signature change needed (a `User`-typed parameter would ripple through ~15 test call sites for no live benefit). Instead, add a one-line docblock/assert on `seed()` stating it must only be called from trusted server-derived `$userId` values (job payloads), never from request input, so a future controller wiring doesn't silently inherit the gap.
        - If a controller ever needs to call `seed()` directly, require it to resolve `$userId` via `$this->currentUser($request)->id` and add an explicit `authorizeForUser` check at that call site — not inside the service.
    - **Technical:** `applyFinding()` on both classes (the only other public entry point that mutates `IntegrationConnection` rows by `$userId`) is exclusively reached from `InstagramController::applySync()` and `GoogleBusinessController::applySync()`, both of which pass `(string) $this->currentUser($request)->id` — JWT-derived, not client-supplied — and look up the finding to apply from server-stored `syncFindings` on the connection's own payload (the client only supplies a `platform` string, validated against `PlatformInRegistry`, never the finding object itself). `seed()` is dispatched only from `GoogleBusinessEnrichJob`/`InstagramConnectJob`, whose `$userId` comes from the job's own constructor arguments set at dispatch time from a trusted context. There is no reachable path today where an attacker controls `$userId` into either service. This is a defense-in-depth note, not a live vulnerability — the same category as a cache key that would only collide on an input nothing currently produces.
    - **Plain English:** These two "seed the connection" functions trust whatever user ID they're handed, without double-checking it themselves — they rely entirely on their callers being trustworthy. Today, both callers ARE trustworthy (background jobs reading from their own database records, never from a web request). This is a "wear a seatbelt even though you haven't crashed yet" fix: cheap insurance against a future change accidentally wiring one of these functions up to a request instead of a job.
    - **Evidence:**
        ```php
        public function seed(string $userId, array $enrichment, ?string $businessName, ?array $gbPayload = null): array
        {
            $findings = [];
            $user = User::find($userId);
        ```
        ```php
        public function seed(string $userId, array $bioLinks): array
        {
            if ($bioLinks === []) {
                return ['findings' => [], 'unmatched' => []];
            }
            $user = User::find($userId);
        ```

- [ ] **#SEC-4** · P2 — `StaffUserController::index()` queries and returns every professional with no `authorizeForUser` call
    - **Where:** app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php:35-89
    - **Affects:** Structural doctrine gap only — the list endpoint already gates raw PII behind `isAdmin()` inline (see #SEC-1); this finding is the missing formal Policy seam underneath that inline check.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add the new `staffView` ability proposed in #SEC-1 to `UserSelfPolicy`, and call `$this->authorizeForUser($staff, 'staffView', User::class);` at the top of `index()`.
        - Fix alongside #SEC-1 in the same change — they share the new Policy method.
    - **Technical:** Every mutating method on this controller (`updateStatus`, `update`, `destroy`, `restore`, `forceDestroy`, `bulkUpdateStatus`) calls `authorizeForUser($staff, ...)` against `UserSelfPolicy`; `index()` is the one read path with zero Policy involvement — it relies solely on the inline `$staff->isAdmin()` check for PII redaction, with no structural seam for `PolicyCoverageTest`-style sweeps to catch a future role model change (e.g., a "read-only" staff tier that shouldn't list professionals at all). Lower severity than #SEC-1 because the actual sensitive fields are already redacted for non-admins today.
    - **Plain English:** The list of all professionals doesn't run through the same formal security checkpoint the edit/delete actions do — it only has one hand-written "is this an admin?" check baked into the code for hiding emails and phone numbers. That inline check happens to work today, but it's not connected to the platform's standard permission system, so it's easy to accidentally break in a future change without anyone noticing.
    - **Evidence:**
        ```php
        public function index(Request $request): JsonResponse
        {
            $status = $request->query('status'); // optional: active|suspended
            $perPage = $this->normalizePerPage(/*...*/);
            $searchLike = $this->prepareSearchLike($request, 'q');

            $query = User::query()
                ->with(['site'])
                ->orderByDesc('created_at');
        ```

- [ ] **#SEC-5** · P2 — `SectorController::update()` mutates the User model without an `authorizeForUser` gate
    - **Where:** app/Http/Controllers/Api/User/Profile/SectorController.php:22-38
    - **Affects:** Authenticated user updating their own sector — tenant-safe today (actor resolved via `currentUser`), but bypasses `UserSelfPolicy`'s pending-deletion block and (if `partna.mfa.require_fresh_aal2_for_profile_update` is ever enabled) its fresh-AAL2 requirement.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `$this->authorizeForUser($user, 'update', $user);` immediately after resolving `$user`, matching `UserSelfController::update()`'s established pattern for the same ability on the same model.
    - **Technical:** `UserSelfPolicy::update()` (registered for `User::class` in `AppServiceProvider::boot()`) blocks pending-deletion actors and conditionally requires fresh AAL2. `SectorController::update()` resolves the actor via `ResolveCurrentUser` correctly but assigns `sector`/`sector_source` and calls `$user->save()` with no Policy check at all — the one deviation from the pattern `UserSelfController::update()` establishes for the identical `'update'` ability on the identical model.
    - **Plain English:** Every other place a user edits their own profile checks "is this account allowed to make changes right now?" before saving — this one skips that check. It doesn't let anyone touch someone else's account, but if the account is mid-deletion or (in the future) needs a fresh login-verification step before sensitive changes, this endpoint wouldn't enforce either rule.
    - **Evidence:**
        ```php
        public function update(UpdateSectorRequest $request): JsonResponse
        {
            $user = $this->currentUser($request);
            $sector = $request->validated()['sector']; // null or a valid slug

            // sector_source is not fillable (service-written) — assign directly.
            $changed = $user->sector !== $sector;
            $user->sector = $sector;
            $user->sector_source = $sector === null ? null : 'manual';
            $user->save();
        ```

- [ ] **#SEC-6** · P2 — `MenuController::refresh()` and `applyScan()` mutate the Menu model without an `authorizeForUser` gate
    - **Where:** app/Http/Controllers/Api/Platforms/MenuController.php:93-115 (`refresh()`), :121-133 (`applyScan()`); write path app/Services/Platforms/MenuScanApplier.php:166-182 (`resolveMenu()`)
    - **Affects:** Authenticated user re-triggering a menu scrape or applying an AI-scanned menu — tenant-safe today via inline `where('user_id', $user->id)`, but bypasses `SitePolicy`'s pending-deletion block on the `Menu` model.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `AppServiceProvider::boot()` already registers `Gate::policy(Menu::class, SitePolicy::class);` — load the user's `Menu` (or a skeleton when absent) in both `refresh()` and `applyScan()` and call `$this->authorizeForUser($user, 'update', $menu)` before the mutation, mirroring `ManagesIntegrationConnection::writeConnection()`'s create-vs-update resolution pattern used elsewhere in this same directory.
    - **Technical:** `refresh()` runs `Menu::query()->where('user_id', $user->id)->update(['fetch_status' => 'pending'])` and `applyScan()` delegates to `MenuScanApplier::apply()`, whose `resolveMenu()` does `Menu::query()->where('user_id', $user->id)->first()` (or creates one) — neither path ever resolves through `SitePolicy`, even though `Menu::class` is a registered, policed model. The inline `user_id` scope is currently the only protection; it's correct today but not the doctrine-mandated Policy gate, and it silently skips the pending-deletion block `SitePolicy::update()` would otherwise enforce.
    - **Plain English:** Clicking "refresh menu" or applying a scanned menu photo updates the database directly, filtered only by "does this row belong to me" written inline in the query — not through the platform's standard permission check. It only affects your own menu today because of that inline filter, but the formal security check that would also stop someone mid-account-deletion from triggering this is being skipped.
    - **Evidence:**
        ```php
        // MenuController::refresh()
        // Flip to pending immediately for instant UI feedback; the job also sets it.
        Menu::query()->where('user_id', $user->id)->update(['fetch_status' => 'pending']);

        MenuFetchJob::dispatch((string) $user->id, true);
        ```
        ```php
        // MenuScanApplier::resolveMenu()
        private function resolveMenu(User $user): Menu
        {
            $menu = Menu::query()->where('user_id', $user->id)->first();
            if ($menu !== null) {
                $menu->forceFill(['last_fetched_at' => now()])->save();

                return $menu;
            }
        ```

- [ ] **#SEC-7** · P2 — `DisplaySettingsController::update()` mutates `IntegrationConnection` and `Site` rows without an `authorizeForUser` gate
    - **Where:** app/Http/Controllers/Api/Platforms/DisplaySettingsController.php:64-143
    - **Affects:** Authenticated user toggling public-display switches for their own platform connections — tenant-safe today via inline `where('user_id', $user->id)`, but bypasses both `IntegrationConnectionPolicy` and `SitePolicy`'s pending-deletion blocks.
    - **Evidence:**
        ```php
        $connections = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('platform', $platform)
            ->where('is_active', true)
            ->get();
        // ...
        $connection->display_settings = $current === [] ? null : $current;
        $connection->save(); // observer → cache purge + payload rebuild
        ```
        ```php
        if ($site !== null && $site->isDirty()) {
            $site->save();
        }
        ```
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Both `IntegrationConnection::class` and `Site::class` are registered (`IntegrationConnectionPolicy`, `SitePolicy`) in `AppServiceProvider::boot()`. Before the `foreach` that saves each connection, call `$this->authorizeForUser($user, 'update', $connection)` per row; before `$site->save()`, call `$this->authorizeForUser($user, 'update', $site)`.
        - This controller doesn't use the `ManagesIntegrationConnection` trait (unlike every other platform controller) because it operates on multiple connections/a raw query rather than one resource per `platform()` — that's why it's the one controller in this family that fell outside the trait's built-in authorization (confirmed by reading `ManagesIntegrationConnection`: `writeConnection`/`forgetConnection`/`connectionFor` all call `authorizeForUser` internally, which is why the sibling `Booking`/`Fresha`/`Square`/`Reservations`/`GoogleBusiness`/`OnlineOrdering` controllers do NOT need a separate finding here).
    - **Technical:** `update()` fetches `IntegrationConnection` rows and (conditionally) a `Site` row scoped by `where('user_id', $user->id)`, then saves both directly — neither path routes through the registered Policy for either model. This is the one platform controller that bypasses `ManagesIntegrationConnection`'s built-in authorization (verified by reading the trait: every `writeConnection`/`writePendingLinkCard`/`forgetConnection`/`forgetAllConnections` call already resolves create-vs-update and calls `authorizeForUser` before touching the row), because it manages toggle state across multiple connections at once rather than one row via the trait's helpers.
    - **Plain English:** Flipping a "show this on my public page" switch updates the database directly, protected only by a filter baked into the query ("only rows that belong to me") rather than the platform's standard permission check. Every sibling integration controller in this same folder DOES go through that standard check via a shared helper — this is the one exception, likely missed because it works with several connections at once instead of the usual "one connection" pattern the helper was built for.

- [ ] **#SEC-8** · P2 — `CustomDomainController` mutates the `Site` model on all four write paths without an `authorizeForUser` gate
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/CustomDomainController.php:38-104 (`store()`), :106-144 (`verify()`), :150-163 (`setPrimary()`), :165-190 (`destroy()`); shared resolver :192-198 (`siteOrFail()`)
    - **Affects:** Authenticated user configuring their own custom domain (Cloudflare for SaaS) — tenant-safe today (site resolved via the `$user->site` Eloquent relationship), but bypasses `SitePolicy`'s pending-deletion block on every domain-configuration write, including CNAME/hostname creation and destruction.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Mirror the exact precedent already established by `ManagesIntegrationConnection::connectionFor()`: add `$this->authorizeForUser($this->currentUser($request), 'view', $site)` inside `siteOrFail()` (covers `show()` too), then add `$this->authorizeForUser($this->currentUser($request), 'update', $site)` immediately before `$site->save()` in each of `store()`, `verify()`, `setPrimary()`, and `destroy()`.
        - Keep 'view' and 'update' as separate calls (don't fold 'update' into `siteOrFail()`) so `show()` stays a pure read and isn't incorrectly blocked by the pending-deletion guard on `update`.
    - **Technical:** `siteOrFail()` resolves the site via `$this->currentUser($request)->site` and 404s when absent — correct anti-enumeration behavior with no IDOR risk, since the relationship itself is tenant-scoped. But none of the four mutating methods call `authorizeForUser` before `$site->save()`, so `SitePolicy::update()`'s `denyIfPendingDeletion()` guard never runs — a professional in a pending-deletion grace period could still create/verify/promote/tear down a custom Cloudflare hostname through this controller today, which is inconsistent with every other site-mutation surface in the codebase (`UserSelfController::update()`, the platform connection controllers via `ManagesIntegrationConnection`) all gating on the same ability.
    - **Plain English:** Connecting or removing a custom domain updates the site record directly, relying only on "this profile's site is the one I loaded" rather than the platform's standard permission check. Because domain changes also create and delete real Cloudflare configuration, the missing check matters slightly more here: an account mid-deletion could still spin up or tear down live DNS/certificate configuration through this path, something the standard check would otherwise block.
    - **Evidence:**
        ```php
        private function siteOrFail(Request $request): Site
        {
            $site = $this->currentUser($request)->site;
            abort_unless($site !== null, 404, 'No site to configure.');

            return $site;
        }
        ```
        ```php
        // destroy() — representative of all four mutation methods:
        $site->custom_domain = null;
        $site->custom_domain_cf_id = null;
        $site->custom_domain_status = null;
        $site->custom_domain_verified_at = null;
        $site->custom_domain_primary = false;
        $site->save();
        ```

## Suggested Bundled Sessions

None.

## Standalone — do NOT bundle

Every finding in this audit is an authorization-boundary or PII-exposure fix — per policy, all run standalone with their own plan + sign-off, never bundled.

- **#SEC-1 — StaffUserController::show() PII/admin_notes leak** · auth/authorization + PII exposure; touches a shared Resource class used elsewhere.
- **#SEC-2 — InstagramScraper log hygiene** · touches log payloads correlating user identity; run alone to keep the diff auditable against `PiiLogHygieneSweepTest`.
- **#SEC-3 — GoogleBusinessAutoSync/InstagramAutoSync seed() tenant-guard note** · auth/authorization (tenant-boundary defense-in-depth).
- **#SEC-4 — StaffUserController::index() missing Policy gate** · auth/authorization; shares the new `staffView` Policy method with #SEC-1 but is its own sign-off.
- **#SEC-5 — SectorController missing Policy gate** · auth/authorization.
- **#SEC-6 — MenuController missing Policy gate** · auth/authorization.
- **#SEC-7 — DisplaySettingsController missing Policy gate** · auth/authorization.
- **#SEC-8 — CustomDomainController missing Policy gate** · auth/authorization; also touches live Cloudflare DNS/certificate state.
