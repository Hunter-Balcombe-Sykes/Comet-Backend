Good. The `['data' => $payload]` wrapper in `IndividualProfileController` is explicitly documented as intentional ("the Astro Worker subrequest expects" it). RES-4 from DeepSeek is dropped. RES-7 is also dropped — it describes frontend verification work, not a backend correctness issue, and the backend side is already correct. Now writing the final audit.

---

# Resource Contract & Response Shape Audit — 2026-05-31

**Branch:** development
**Lens:** Raw Eloquent model leakage, inconsistent Resource shapes, JSONB column leakage, missing pagination/filtering contracts, breaking-change risk, envelope-key drift
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Resources/ApiResource.php
- app/Http/Resources/Moderation/CaseResource.php
- app/Http/Resources/Moderation/CaseSignalResource.php
- app/Http/Resources/Moderation/DecisionResource.php
- app/Http/Resources/Moderation/EvidenceResource.php
- app/Http/Resources/Moderation/CaseDetailResource.php
- app/Http/Resources/NotificationListingResource.php
- app/Http/Resources/SiteResource.php
- app/Http/Controllers/Api/Staff/StaffSite/StaffMeController.php
- app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationController.php
- app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagController.php
- app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagOverrideController.php
- app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php
- app/Http/Controllers/Api/User/Account/UserDocumentController.php
- app/Http/Controllers/Api/User/Uploads/UserUploadController.php
- app/Http/Controllers/Api/User/Customers/UserCustomerController.php
- routes/api/staff.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 5 complete
- P3 Low: 0 of 1 complete

---

## P2 — Should fix

- [ ] **#RES-1** · P2 — Moderation resources extend `JsonResource` instead of `ApiResource`; `id` not cast to string
    - **Where:** app/Http/Resources/Moderation/CaseResource.php, CaseSignalResource.php, DecisionResource.php, EvidenceResource.php, CaseDetailResource.php
    - **Affects:** Staff moderation queue — violates the project Resource contract; bypasses `stringId()` helper and any future `ApiResource` base-class enhancements.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `extends JsonResource` to `extends ApiResource` (from `App\Http\Resources\ApiResource`) in all five moderation resources.
        - Cast `id` to `(string) $this->id` in `CaseResource`, `CaseSignalResource`, `DecisionResource`, and `EvidenceResource`; use `$this->stringId()` for the null-safe variant if needed.
    - **Technical:** `ApiResource` explicitly mandates: "Resources emitting an `id` field MUST cast it to string." All four leaf moderation resources and `CaseDetailResource` extend `JsonResource` directly, bypassing this contract and the `stringId()` helper. UUIDs happen to serialize as strings in JSON regardless — there is no runtime bug today — but the explicit cast is the invariant that survives a future int-keyed table and satisfies strict-typed TS consumers (Zod discriminated-union assertions). Extending `ApiResource` also ensures these resources participate in any future base-class changes applied across the project.
    - **Plain English:** Every result card in the moderation queue is built by one of these files. They're supposed to follow the house rule that says "always label the ID as text." They use an older, lower-level base class that skips that rule. It works today because IDs happen to be text strings anyway, but it's a gap that causes silent breakage if a future database table uses numeric IDs or if the frontend adds a strict type check.
    - **Evidence:**
        ```php
        // CaseResource.php
        class CaseResource extends JsonResource
        {
            public function toArray($request): array
            {
                return [
                    'id' => $this->id,
                    'case_type' => $this->case_type,
        ```
        ```php
        // CaseSignalResource.php
        class CaseSignalResource extends JsonResource
        {
            public function toArray($request): array
            {
                return [
                    'id' => $this->id,
                    'signal_source' => $this->signal_source,
        ```

- [ ] **#RES-2** · P2 — Raw Carbon instances returned from controller-side payload builders
    - **Where:** app/Http/Controllers/Api/User/Account/UserDocumentController.php (`buildDocumentPayload`); app/Http/Controllers/Api/User/Uploads/UserUploadController.php (`buildMediaPayload`)
    - **Affects:** Dashboard document and media upload endpoints — timestamp serialisation format is implementation-dependent; a future config change would silently shift the wire format.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `$media->created_at` / `$media->updated_at` with `$media->created_at?->toIso8601String()` / `$media->updated_at?->toIso8601String()` in both payload builders.
    - **Technical:** Every `ApiResource` subclass in this codebase calls `->toIso8601String()` to pin the timestamp wire format. The two controller-side payload builders (`buildDocumentPayload` in `UserDocumentController` and `buildMediaPayload` in `UserUploadController`) bypass the Resource layer and return Carbon instances directly. Carbon defaults to ISO-8601, but the exact representation — microsecond precision, UTC offset notation — is sensitive to `$dateFormat` on the model cast and `date_serialization_format` in app config. An explicit call removes that dependency and aligns with every other timestamp in every other response.
    - **Plain English:** Most endpoints always send dates in a very specific format (e.g. `2026-05-31T10:00:00+00:00`). The document and media upload endpoints skip the step that locks in that format, so a server-side configuration change could silently send dates in a slightly different format that the dashboard can't parse.
    - **Evidence:**
        ```php
        // UserDocumentController.php buildDocumentPayload()
        return [
            'id' => $media->id,
            'title' => $media->alt_text,
            'caption' => $media->caption,
            'is_enabled' => (bool) $media->is_active,
            ...
            'created_at' => $media->created_at,
            'updated_at' => $media->updated_at,
        ];
        ```
        ```php
        // UserUploadController.php buildMediaPayload()
        $payload = [
            'id' => $media->id,
            'pool' => $media->pool,
            ...
            'created_at' => $media->created_at,
            'updated_at' => $media->updated_at,
        ];
        ```

- [ ] **#RES-3** · P2 — Staff endpoints return raw Eloquent models (`StaffMeController`, `StaffNotificationController::store`)
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffMeController.php:15; app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationController.php:182
    - **Affects:** Staff dashboard session bootstrap (`GET /staff/me`) and notification creation (`POST /staff/notifications`) — any new column added to `PartnaStaff` or `Notification` automatically ships to the staff UI without review.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Create `PartnaStaffResource extends ApiResource` with an explicit allowlist of the fields needed by the staff dashboard (at minimum: `id`, `name`, `primary_email`). Use it in `StaffMeController::show()`.
        - In `StaffNotificationController::store()`, wrap the created `$notification` in `NotificationListingResource` — it already exists at `app/Http/Resources/NotificationListingResource.php` and is used for every other notification response in this codebase.
    - **Technical:** The architecture invariant is explicit: "never return raw Eloquent models from API endpoints; use Resource classes." `StaffMeController::show()` returns `$request->attributes->get('partna_staff')` — the `PartnaStaff` Eloquent model — directly from the request attributes bag. `StaffNotificationController::store()` returns the `Notification::query()->create()` result unfiltered. `NotificationListingResource` already covers the listing path; `store()` is the sole call site that bypasses it. For `PartnaStaff` no resource class exists yet and one must be created.
    - **Plain English:** When staff log in and the app loads their profile, or when they create a notification, the server sends back every single field stored in the database for that record — including any new field added in the future. Industry practice is to explicitly list which fields are safe to send, so a developer adding an "internal access notes" column doesn't accidentally broadcast it to every browser session.
    - **Evidence:**
        ```php
        // StaffMeController.php
        public function show(Request $request)
        {
            return $this->success([
                'uid' => $request->attributes->get('supabase_uid'),
                'staff' => $request->attributes->get('partna_staff'),
            ]);
        }
        ```
        ```php
        // StaffNotificationController.php store()
        $notification = Notification::query()->create([
            ...$data,
            'category' => $data['category'] ?? null,
        ]);
        ...
        return $this->success(['notification' => $notification], 201);
        ```

- [ ] **#RES-4** · P2 — Feature flag list endpoints return non-standard pagination envelope
    - **Where:** app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagController.php:25; StaffFeatureFlagOverrideController.php:24
    - **Affects:** Staff feature-flag admin UI — any frontend reading the project-standard envelope (`flags`, `meta.current_page`, `meta.next_page_url`) will receive an unexpected shape and fail to display page controls or iterate items correctly.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `use ReturnsPaginatedResponse;` to both controllers.
        - Replace `FeatureFlagResource::collection($flags)->response()` with `$this->success($this->paginatedResponse($flags, 'flags'))`.
        - Replace `FeatureFlagOverrideResource::collection($overrides)->response()` with `$this->success($this->paginatedResponse($overrides, 'overrides'))`.
    - **Technical:** `Resource::collection($paginator)->response()` produces Laravel's default envelope: `{ "data": [...], "links": {...}, "meta": { "from": N, "to": N, "path": "...", "links": [...] } }`. Every other paginated staff endpoint uses `ReturnsPaginatedResponse::paginatedResponse()`, producing `{ "<named_key>": [...], "meta": { "current_page": N, "per_page": N, "total": N, "last_page": N, "next_page_url": "...", "prev_page_url": "..." } }`. The divergences are: (1) data appears under `data` not a named key, (2) `meta` contains different fields (`from`/`to`/`path`/nested `links` instead of `next_page_url`/`prev_page_url`), and (3) an extra top-level `links` array appears. A frontend consumer written against the project standard will misread or fail silently on both responses.
    - **Plain English:** Every other "list of items" the staff app requests arrives in the same standard box format — with a named label on the box and page-navigation labels on the side. The feature-flag list and override list arrive in a slightly different box. The navigation labels are present but in different positions with different names. Any frontend code written against the standard format won't find the page controls in the right place.
    - **Evidence:**
        ```php
        // StaffFeatureFlagController.php
        $flags = FeatureFlag::withCount('overrides')->orderBy('key')->paginate(50);
        return FeatureFlagResource::collection($flags)->response();
        ```
        ```php
        // StaffFeatureFlagOverrideController.php
        $overrides = $flag->overrides()->orderBy('created_at', 'desc')->paginate(50);
        return FeatureFlagOverrideResource::collection($overrides)->response();
        ```

- [ ] **#RES-5** · P2 — `StaffUserController` eager-loads deprecated `site.theme` relation (skeleton-system migration time-bomb)
    - **Where:** app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php (`index` line 35, `show` line 97)
    - **Affects:** `GET /staff/professionals` and `GET /staff/professionals/{id}` — both will throw a relation/query error the moment the skeleton-system cleanup migration drops the `site.themes` table.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `'site.theme'` from `->with(['site.theme'])` in `index()` and from `->load(['site.theme', 'services', 'blocks'])` in `show()`.
        - Replace the `'theme' => [...]` key in both response payloads with `'skeleton_id' => $site->skeleton_id`. The column is already on `site.sites` — `SiteResource` shows the pattern: `'skeleton_id' => $this->skeleton_id`.
    - **Technical:** CLAUDE.md skeleton-system section documents that `site.themes` will be "DROPPED entirely. Skeletons are code constants in `partna-pages/src/skeletons/`, not DB records." `StaffUserController::index()` calls `User::query()->with(['site.theme'])` and exposes `$site?->theme->id/key/name` in the collection map. `StaffUserController::show()` calls `$professional->load(['site.theme', 'services', 'blocks'])` and exposes identical theme fields. Every other part of the codebase has already migrated: `SiteResource` emits `skeleton_id`; `IndividualProfileResource` emits `skeletonId`. These two staff routes are the sole remaining callers of `site.theme`. When the cleanup migration runs, the `->with()` and `->load()` calls will attempt to join against a non-existent table before the controller body executes.
    - **Plain English:** The platform is switching from stored "theme" records in the database to "skeleton" templates built directly into the app code. The database table that holds theme records is scheduled for deletion. Two staff pages still try to look up that table on every request. When the deletion happens, those pages will return a server error instead of data. The fix is a small swap — stop looking up the theme, and instead read the skeleton name (already stored on the same site record).
    - **Evidence:**
        ```php
        // StaffUserController::index()
        $query = User::query()
            ->with(['site.theme'])
            ->orderByDesc('created_at');
        ```
        ```php
        // StaffUserController::show()
        $professional->load(['site.theme', 'services', 'blocks']);

        return $this->success([
            'professional' => new UserStaffResource($professional),
            'site' => $professional->site ? [
                'id' => $professional->site->id,
                'subdomain' => $professional->site->subdomain,
                'is_published' => (bool) $professional->site->is_published,
                'theme' => $professional->site->theme ? [
                    'id' => $professional->site->theme->id,
                    'key' => $professional->site->theme->key ?? null,
                    'name' => $professional->site->theme->name ?? null,
                ] : null,
            ] : null,
        ]);
        ```

## P3 — Nice to have

- [ ] **#RES-6** · P3 — Customer list response ships redundant `pagination` key (migration-shim cleanup)
    - **Where:** app/Http/Controllers/Api/User/Customers/UserCustomerController.php:95–96
    - **Affects:** No runtime impact — both keys contain identical data; cleanup removes dead code and the ambiguity it creates for future readers.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Confirm the dashboard reads `meta` exclusively (search for `pagination.current_page`, `pagination.total`, etc. in the frontend).
        - Once confirmed, remove `$payload['pagination'] = $payload['meta'];` and the surrounding TODO comment.
    - **Technical:** The controller's own comment dates this shim and scopes the action: "P1-06: dual-key `meta` + `pagination` for one release cycle. TODO(B4): drop `pagination` key once frontend confirms it reads `meta`." The staff mirror (`StaffCustomerManagementController`) already emits `meta` only. Once frontend adoption is confirmed, the alias is dead code — it doubles the pagination payload on every customer list request and misleads future developers about the canonical envelope key.
    - **Plain English:** The customer list currently sends page-navigation information twice — once labelled `meta` (the new name) and once labelled `pagination` (the old name). This was added deliberately to avoid breaking the old frontend code during a changeover. Once the frontend has fully switched to reading `meta`, the duplicate label can be removed to keep things tidy.
    - **Evidence:**
        ```php
        // UserCustomerController.php
        // P1-06: dual-key `meta` + `pagination` for one release cycle. Staff
        // mirror already uses `meta`; this brings professional in line while
        // keeping current frontend reads working.
        // TODO(B4): drop `pagination` key once frontend confirms it reads `meta`.
        $payload['pagination'] = $payload['meta'];
        ```
