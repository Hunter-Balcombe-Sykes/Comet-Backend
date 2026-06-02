`★ Insight ─────────────────────────────────────`
Two patterns confirmed: `ProfessionalResource` is completely dead (zero `use` statements, zero `new` usages across the entire app) and `ApiController::paginated()` is defined but never called — its `ReturnsPaginatedResponse::paginatedResponse()` trait replaced it. The danger with `paginated()` is architectural: it returns a final `JsonResponse`, making after-the-fact Resource wrapping impossible, unlike the trait which returns an array the caller can still transform.
`─────────────────────────────────────────────────`

# Resource Shape & Model Leakage Audit — 2026-05-25

**Branch:** development
**Lens:** raw model leakage, inconsistent Resource shapes, breaking-change risk, missing pagination/filtering contracts
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffProfessionalController.php
- app/Http/Controllers/Api/Professional/Account/ProfessionalDocumentController.php
- app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php
- app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalGalleryController.php
- app/Http/Controllers/Api/Staff/StaffSite/StaffSiteController.php
- app/Http/Controllers/Api/Staff/StaffSite/StaffAnalyticsController.php
- app/Http/Controllers/Api/ApiController.php
- app/Http/Resources/ProfessionalResource.php
- app/Http/Resources/ProfessionalDashboardResource.php
- app/Http/Resources/SectionBlockResource.php
- app/Http/Resources/GalleryImageResource.php
- app/Models/Core/Staff/PartnaStaff.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 5 complete
- P3 Low: 0 of 4 complete

---

## P2 — Should fix

- [ ] **#RES-4** · P2 — `ProfessionalGalleryController::update` returns a 3-field stub instead of the full `GalleryImageResource` shape, creating a frontend contract break
    - **Where:** app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalGalleryController.php:update (~line 121)
    - **Affects:** Any frontend code that merges the PATCH `/gallery/{image}` response back into the gallery list (a common React/Vue pattern). The response contains only `id`, `alt_text`, and `caption` — the `pool`, `sort_order`, and `variants` fields present in the GET `/gallery` response will be silently overwritten with `undefined`/null depending on how the frontend handles the merge.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the manual 3-field array with `(new GalleryImageResource($image))->resolve()`.
        - The image's `mediaVariants` relation is already loaded at this point (via `setRelation`), so `GalleryImageResource::variantUrls()` will not cause an N+1.
        - If bandwidth is a concern for autosave-on-blur edits, add a `?fields=minimal` opt-in query parameter rather than making the inconsistency the default.
    - **Technical:** The GET `/gallery` index returns `GalleryImageResource` (explicit allowlist including `pool`, `sort_order`, `variants`, `created_at`, `updated_at`). The PATCH `/gallery/{image}` returns only `['id', 'alt_text', 'caption']`. Both endpoints target the same `SiteMedia` row. Any frontend state management that treats the PATCH response as a full replacement of the list item will silently drop four fields. `GalleryImageResource` already exists and the `mediaVariants` relation is available on the model at the point of return, so the fix is a one-line swap.
    - **Plain English:** When you ask to see your gallery, every photo comes with its full details — sort position, size variants, captions, the works. But when you update a caption, the server only sends back the three fields it changed. If the frontend treats that response as a complete description of the photo (a very normal assumption), it accidentally forgets everything else about it. Making the update response return the same full description as the list view fixes the inconsistency with one line of code.
    - **Evidence:**
        ```php
        return $this->success([
            'image' => [
                'id' => $image->id,
                'alt_text' => $image->alt_text,
                'caption' => $image->caption,
            ],
        ]);
        ```

- [ ] **#RES-5** · P2 — `StaffSiteController::show` and `showByProfessional` build response arrays directly from an `AllSiteData` view model, bypassing the Resource allowlist pattern
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffSiteController.php:show (~line 35) and showByProfessional (~line 74)
    - **Affects:** Staff site inspection endpoints. Any column added to the `AllSiteData` view — `core.users`, `site.sites`, `site.themes`, `site.blocks` — requires a manual audit of this controller to determine whether to expose it. Today the mapping is explicit; over time it diverges from what was intended.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create a `StaffSiteResource` (or a `StaffSiteInspectionResource`) that explicitly lists every field the staff UI needs from the view.
        - Both `show` and `showByProfessional` share identical payloads; extract to a private `buildPayload(AllSiteData $row): array` helper and have the Resource call it, or just use a single Resource with both methods pointing to it.
        - Register the Resource pattern (no model gate needed for a view-backed Resource, but consistency with the rest of the staff surface matters).
    - **Technical:** `AllSiteData` is a PostgreSQL view that joins four tables. The controller currently accesses individual `$row->...` properties explicitly, which is itself a form of allowlist — the same as the manual array pattern in RES-1 through RES-3. The risk is future drift: a view column added for another consumer (e.g. `users.admin_notes`, `sites.internal_config`) becomes accessible on `$row` and the next engineer who touches this controller may add it without the Resource gate triggering a review. `ProfessionalStaffResource::toArray` already shows the correct pattern for a staff-facing professional shape and explicitly guards `admin_notes`.
    - **Plain English:** The staff "view site" screen hand-picks which database columns to show, like someone reading from a spreadsheet and deciding what to copy into an email. It works today, but if someone adds a new secret column to the spreadsheet, the next person editing this screen might accidentally copy it too. A Resource class acts like a filtered export template — whatever is in the database, only the approved fields ever leave.
    - **Evidence:**
        ```php
        return $this->success([
            'is_published' => (bool) $row->is_published,
            'site' => [
                'id' => $row->site_id,
                'subdomain' => $row->subdomain,
                'settings' => $siteSettings,
            ],
            'professional' => [
                'id' => $row->professional_id,
                'handle' => $row->professional_handle,
                'display_name' => $row->professional_display_name,
                'account_type' => $row->account_type,
                'bio' => $row->professional_bio,
                'location_street_address' => $row->professional_location_street_address,
                // ...
            ],
            // ...
        ]);
        ```

- [ ] **#RES-3** · P2 — `ProfessionalUploadController::buildMediaPayload` is a 60-line controller method rather than a `MediaResource` class, and the most complex of the three manual-builder anti-patterns in this codebase
    - **Where:** app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php:buildMediaPayload (~line 437)
    - **Affects:** The `upload` response, the `index` response (via `buildIndexPayload` cached closure), and the polling path. New columns on `SiteMedia` or `MediaVariant` — `duration_ms`, `processing_error`, any future moderation flag — auto-include in all three endpoints if a developer adds them to the payload array without realising this method is the sole serialization gate.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create a `MediaResource` extending `ApiResource`. The image/video branching and `includeVariants` logic move into the Resource's `toArray`, gated by `$this->resource->media_type` and a `withVariants()` constructor flag or `when()` conditional.
        - Replace all three call sites (`buildMediaPayload($media, ...)`) with `new MediaResource($media)` or `MediaResource::collection(...)`.
        - The `buildIndexPayload` closure is cached via `rememberLocked` — ensure `MediaResource::resolve()` (not `::collection()->toJson()`) is called inside the closure so the cached value is a plain PHP array, not a serialized Resource object.
    - **Technical:** `buildMediaPayload` branches on `$media->media_type`, `$media->processing_state`, and a `$includeVariants` flag to assemble three distinct shapes: image-without-variants, image-with-variants, and video-with-HLS/poster/mp4. All three shapes share the base fields. A `MediaResource` centralises this branching and provides the same explicit-allowlist guarantee that `GalleryImageResource` already provides for gallery-pool rows — preventing future `SiteMedia` columns from auto-appearing in upload, index, and polling responses.
    - **Plain English:** The upload pipeline builds its responses by hand — a 60-line method that knows whether it's dealing with a photo or a video, and whether processing has finished. There's no locked-down list of what's allowed out; it's more like a developer manually assembling a package every time. A Resource class is the locked-down packing list: whatever ends up in the database, only what's on the list ships to the frontend.
    - **Evidence:**
        ```php
        private function buildMediaPayload(SiteMedia $media, bool $includeVariants = false): array
        {
            $isVideo = $media->media_type === SiteMedia::MEDIA_TYPE_VIDEO;
            $isReady = $media->processing_state === SiteMedia::PROCESSING_STATE_READY;
            $isProcessing = $media->processing_state === SiteMedia::PROCESSING_STATE_PENDING
                || $media->processing_state === SiteMedia::PROCESSING_STATE_PROCESSING;

            $payload = [
                'id' => $media->id,
                'pool' => $media->pool,
                'alt_text' => $media->alt_text,
                'caption' => $media->caption,
                'sort_order' => $media->sort_order,
                'media_type' => $media->media_type,
                'processing_state' => $media->processing_state,
                'processing' => $isProcessing,
                'processing_error' => $media->processing_error,
                'created_at' => $media->created_at,
                'updated_at' => $media->updated_at,
            ];
        ```

- [ ] **#RES-2** · P2 — `ProfessionalDocumentController::buildDocumentPayload` is a manual array builder in the controller rather than a `DocumentResource` class
    - **Where:** app/Http/Controllers/Api/Professional/Account/ProfessionalDocumentController.php:buildDocumentPayload (~line 278)
    - **Affects:** The `index`, `store`, and `update` document endpoints. Any new column added to `site.media` (exif metadata, moderation flags, internal notes) will be available on the `SiteMedia` model and can be added to this response array by a future developer without the Resource pattern triggering a review.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Create a `DocumentResource` extending `ApiResource`. The `preview_url` and `download_url` fields need constructor injection or a computed property (since they require `Storage::disk()` and the hardcoded download path).
        - Replace all three `buildDocumentPayload($media)` call sites with `new DocumentResource($media)`.
        - Cast `id` to string in the Resource (`'id' => (string) $this->id`) — the current manual builder omits this cast that the `ApiResource` contract requires.
    - **Technical:** `GalleryImageResource` was created specifically to replace an equivalent per-row `->map()` closure in `ProfessionalGalleryController` (see the class docblock: "Replaces the per-row inline ->map(...) that ProfessionalGalleryController::index previously used"). `buildDocumentPayload` is the same anti-pattern that `GalleryImageResource` was introduced to fix. The document pool has a 1-per-site constraint so the payload is never paginated, making this an S-effort extraction. Additionally, the current builder emits `'id' => $media->id` without the `(string)` cast that `ApiResource`'s docblock mandates for all `id` fields.
    - **Plain English:** The gallery already has a locked box that controls exactly what photo information gets sent to the frontend. The document section uses a handwritten list in the controller instead. The fix is to give documents the same locked box the gallery has — it takes about an hour.
    - **Evidence:**
        ```php
        private function buildDocumentPayload(SiteMedia $media): array
        {
            $mediaDisk = config('partna.media_disk');
            $previewUrl = Storage::disk($mediaDisk)->url((string) $media->path);

            return [
                'id' => $media->id,
                'title' => $media->alt_text,
                'caption' => $media->caption,
                'is_enabled' => (bool) $media->is_active,
                'original_mime' => $media->original_mime,
                'original_size_bytes' => $media->original_size_bytes,
                'original_filename' => $media->original_filename,
                'preview_url' => $previewUrl,
                'download_url' => '/api/public/documents/'.$media->id.'/download',
                'created_at' => $media->created_at,
                'updated_at' => $media->updated_at,
            ];
        }
        ```

- [ ] **#RES-1** · P2 — `StaffProfessionalController::index` uses a manual `->map()` closure instead of a `ProfessionalStaffListResource`, while the `show` method on the same controller correctly uses `ProfessionalStaffResource`
    - **Where:** app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffProfessionalController.php:index (~line 85)
    - **Affects:** The staff professional list endpoint. The manual map deliberately omits `admin_notes` (present in `ProfessionalStaffResource`) — a sensible optimization for the list view. But because the gate lives in an inline closure rather than a named Resource, a future developer adding an eager-loaded relation (e.g. `->with('latestAuditEntry')`) and then accessing it in the map has no structural reminder that this is a security boundary.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create a `ProfessionalStaffListResource` with only the fields appropriate for the list view (the current map's explicit field set is the right starting point).
        - Replace `$page->getCollection()->map(function (User $p) { ... })` with `ProfessionalStaffListResource::collection($page->getCollection())->resolve()`.
        - Add a docblock to `ProfessionalStaffListResource` explaining that `admin_notes` is intentionally absent (list view, not detail view) — this documents the security boundary for future contributors.
    - **Technical:** `show` uses `ProfessionalStaffResource` (which includes `admin_notes`, `parent_status`, etc.). `index` uses an inline map with a "Keep response light for list-view" comment but no structural enforcement. The current map is an explicit allowlist; the problem is that it lives in a closure rather than a class, making it invisible to `PolicyCoverageTest`-style sweep checks and easy to accidentally expand. `ProfessionalStaffResource::$hidden` on the `User` model is not a substitute — it only gates `toArray()` passthrough, not explicit property access.
    - **Plain English:** The staff "list all users" screen carefully controls what it shows — but the rule exists only as a comment in a code block, not as an enforced template. The staff "view one user" screen uses a proper template. A new developer editing the list screen doesn't get any automated signal that this is a place where sensitive fields must not be added. Creating a named template for the list view gives future developers the same guardrail the detail view already has.
    - **Evidence:**
        ```php
        $professionals = $page->getCollection()->map(function (User $p) {
            $site = $p->site;
            $theme = $site?->theme;

            return [
                'id' => $p->id,
                'handle' => $p->handle,
                'display_name' => $p->display_name,
                'status' => $p->status,
                'primary_email' => $p->primary_email,
                'phone' => $p->phone,
                'created_at' => optional($p->created_at)->toISOString(),
                'updated_at' => optional($p->updated_at)->toISOString(),

                'site' => $site ? [
                    'id' => $site->id,
                    'subdomain' => $site->subdomain,
                    'is_published' => (bool) $site->is_published,
                    'theme' => $theme ? [
                        'id' => $theme->id,
                        'key' => $theme->key ?? null,
                        'name' => $theme->name ?? null,
                    ] : null,
                ] : null,
            ];
        });
        ```

## P3 — Nice to have

- [ ] **#RES-6** · P3 — `ProfessionalResource` is dead code: zero controller consumers, only referenced in comments
    - **Where:** app/Http/Resources/ProfessionalResource.php
    - **Affects:** Maintenance clarity — the class name implies it is the canonical professional shape (vs `ProfessionalDashboardResource` which sounds like a variant), but it is `ProfessionalDashboardResource` that is actually used for `/me`, bootstrap, and update endpoints. A new developer encountering `ProfessionalResource` may wire a new endpoint to it thinking they're following the convention.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Grep for any test files referencing `ProfessionalResource` before deleting.
        - Delete `app/Http/Resources/ProfessionalResource.php`.
        - If a distinct non-dashboard shape is ever needed (e.g. an admin-less staff view that doesn't include `auth_user_id`), create it fresh with a name that makes its audience clear (e.g. `ProfessionalSelfResource`).
    - **Technical:** Confirmed via grep: `new ProfessionalResource` appears zero times across all PHP files in `app/`. The class is mentioned only in two comments — a `StaffUpdateProfessionalRequest` comment and the `ProfessionalStaffResource` docblock — both as contrast examples, not as consumers. `ProfessionalDashboardResource` is the authenticated owner's shape; `ProfessionalStaffResource` is the staff shape; `ProfessionalPublicResource` is the unauthenticated public shape. `ProfessionalResource` occupies a naming slot that implies "the generic one" while actually being unused dead code one field delta away from `ProfessionalDashboardResource` (missing `auth_user_id`).
    - **Plain English:** There are four "professional profile" card templates in the system — one for the owner, one for staff, one for the public, and one that nobody actually uses. The unused one has a name that sounds like the main one, which will confuse the next developer who goes looking for "the standard professional shape." Delete it.
    - **Evidence:**
        ```php
        // ProfessionalResource.php — zero controller consumers
        class ProfessionalResource extends ApiResource
        {
            public function toArray(Request $request): array
            {
                return [
                    'id' => (string) $this->id,
                    'account_type' => $this->account_type?->value,
                    'display_name' => $this->display_name,
                    // ... 20+ fields identical to ProfessionalDashboardResource
                    // Missing only: 'auth_user_id' => $this->auth_user_id
                ];
            }
        }
        ```

- [ ] **#RES-7** · P3 — `SectionBlockResource` emits both `publication_state` and `is_live` as redundant representations of the same boolean
    - **Where:** app/Http/Resources/SectionBlockResource.php:toArray (~line 53)
    - **Affects:** Frontend consumers that check section visibility — two distinct code paths can exist (`is_live === true` vs `publication_state === 'live'`) that behave identically today but could silently diverge if the derivation logic changes.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Designate `publication_state` as canonical (it's the richer representation and can grow to `'scheduled'`, `'archived'`, etc.).
        - Mark `is_live` deprecated in the Resource with a `// @deprecated — use publication_state; remove after frontend confirms` comment.
        - After the frontend confirms `publication_state` is read everywhere, remove `is_live` in a follow-up.
    - **Technical:** Both `publication_state` (`'live'|'draft'`) and `is_live` (`bool`) derive from `$isLive = (bool) ($this->is_active ?? false)`. The string enum is strictly richer — it can accommodate a future `'scheduled'` state with no frontend breaking change, while migrating away from a bool requires a contract bump. Until removal, the duplicate is harmless but creates documentation debt.
    - **Plain English:** The section block sends the same yes/no information twice — once as the word "live" or "draft," and once as the number 1 or 0. Both mean the same thing. Future code that adds a third state (like "scheduled") will break one of those representations, and you won't know which half of the frontend is using which one.
    - **Evidence:**
        ```php
        'publication_state' => $isLive ? 'live' : 'draft',
        'is_live' => $isLive,
        ```

- [ ] **#RES-8** · P3 — `StaffAnalyticsController::summary` returns raw `DB::table()->get()` results for chart data without Resource wrapping
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffAnalyticsController.php:summary (inside `rememberLocked` closure, ~line 90)
    - **Affects:** The `charts.visits_by_day`, `charts.clicks_by_day`, and `top_links` fields in the staff analytics response. Currently returns `stdClass` collections with `day`/`count` and `block_id`/`title`/`url`/`clicks` properties. Any `selectRaw` addition to the queries auto-appears in the cached response.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Create lightweight `AnalyticsDayBucketResource` (`day`, `count`) and `TopLinkResource` (`block_id`, `title`, `url`, `clicks`) classes.
        - Inside the `rememberLocked` closure, wrap: `$visitsByDay->map(fn($r) => (new AnalyticsDayBucketResource($r))->resolve())->all()`.
        - No PII concern here — these are aggregated counts — but the pattern consistency enables future engineers to reason uniformly about what ships.
    - **Technical:** Unlike the other manual-builder findings, there is no model data leakage risk here — the `selectRaw` queries return only aggregate counts and block metadata, not user rows. The finding is about pattern consistency: the rest of the analytics surface (professional-facing analytics in `AnalyticsCacheService`) already uses structured array shapes. The `stdClass` results are harmless for the current queries but become a footgun if a developer ever adds a raw column to the `top_links` join (e.g. `b.settings` — which contains a platform URL and category tag — without realising the select is cached and publicly returned).
    - **Plain English:** The staff analytics chart data comes back from the database as raw unfiltered rows — like handing someone a direct printout from the filing cabinet instead of a formatted report. Today it's just counts (safe), but if someone adds more columns to the database query later, those columns will automatically appear in the response. Lightweight wrappers around these results add a checkpoint without meaningful extra work.
    - **Evidence:**
        ```php
        $visitsByDay = DB::table('analytics.site_visits')
            ->where('professional_id', $professional->id)
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw('DATE(occurred_at) as day, COUNT(*) as count')
            ->groupByRaw('DATE(occurred_at)')
            ->orderBy('day')
            ->get();
        ```

- [ ] **#RES-9** · P3 — `ApiController::paginated()` is dead code that, if used, would bypass the Resource allowlist pattern entirely
    - **Where:** app/Http/Controllers/Api/ApiController.php:paginated (~line 40)
    - **Affects:** Any future controller that calls `$this->paginated($query->paginate(...))` — the method returns a final `JsonResponse` wrapping raw `$paginator->items()` (plain Eloquent model arrays), making after-the-fact Resource wrapping impossible. Every current paginated endpoint uses `ReturnsPaginatedResponse::paginatedResponse()` instead, which returns an array the caller can Resource-wrap before passing to `$this->success()`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Confirm zero test references to `paginated()`, then delete the method from `ApiController`.
        - Update the class docblock to name `ReturnsPaginatedResponse::paginatedResponse()` as the canonical helper.
        - If the method needs to remain for backward compatibility, add a `@deprecated` docblock and a `trigger_error(..., E_USER_DEPRECATED)` call so any future accidental use surfaces immediately in tests.
    - **Technical:** Confirmed via grep: `$this->paginated(` has zero call sites across all controllers. The method signature `paginated($paginator, string $dataKey = 'data'): JsonResponse` returns `$paginator->items()` directly to `response()->json()` — there is no intermediate step where a Resource class can intercept. By contrast, `ReturnsPaginatedResponse::paginatedResponse()` returns a plain PHP array; callers then apply `ResourceClass::collection($paginator->items())->resolve()` to the `$dataKey` key before passing to `$this->success()`. The dead `paginated()` method creates a silent alternative path that contradicts the codebase's Resource-everywhere doctrine.
    - **Plain English:** There's a dusty helper method in the base controller that nobody is using. The problem is that if someone finds it in the future and uses it to build a paginated response, they'll accidentally skip the security check that controls which database fields ship to the frontend — because the helper sends data directly to the response without the filter layer. The safe helpers live elsewhere. Removing or clearly labelling the unsafe one prevents a future mistake.
    - **Evidence:**
        ```php
        // ApiController.php — zero call sites in any controller
        protected function paginated($paginator, string $dataKey = 'data'): JsonResponse
        {
            return response()->json([
                $dataKey => $paginator->items(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                    'next_page_url' => $paginator->nextPageUrl(),
                    'prev_page_url' => $paginator->previousPageUrl(),
                ],
            ]);
        }
        ```
