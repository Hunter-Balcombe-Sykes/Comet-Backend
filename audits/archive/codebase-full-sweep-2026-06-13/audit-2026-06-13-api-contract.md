# API Contract & Resource Leakage Audit — 2026-06-13

**Branch:** development
**Lens:** API Contract & Resource Leakage: raw model fields bleeding through, over-fetching, inconsistent pagination
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-5`
**Source files audited:**
- `app/Http/Resources/UserResource.php`
- `app/Http/Resources/UserDashboardResource.php`
- `app/Http/Resources/UserPublicResource.php`
- `app/Http/Resources/Staff/StaffSiteResource.php`
- `app/Http/Resources/PublicSite/IndividualProfileResource.php`
- `app/Http/Resources/SiteResource.php`
- `app/Http/Controllers/Api/ApiController.php`
- `app/Http/Controllers/Api/User/Account/UserSelfController.php`
- `app/Http/Controllers/Api/User/Account/MfaController.php`
- `app/Http/Controllers/Api/User/Account/SessionController.php`
- `app/Http/Controllers/Api/User/Account/UserDocumentController.php`
- `app/Http/Controllers/Api/User/SiteManagement/UserServiceController.php`
- `app/Http/Controllers/Api/User/SiteManagement/UserSmartLinkController.php`
- `app/Http/Controllers/Api/User/SiteManagement/UserServiceCategoryController.php`
- `app/Http/Controllers/Api/User/Uploads/UserUploadController.php`
- `app/Http/Controllers/Api/User/Uploads/UserDesignMediaController.php`
- `app/Http/Controllers/Api/User/Site/HandleReclaimController.php`
- `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php`
- `app/Http/Controllers/Api/Staff/StaffSite/StaffAnalyticsController.php`
- `app/Http/Controllers/Api/Staff/StaffSite/StaffEmailSubscriberController.php`
- `app/Http/Controllers/Api/Staff/StaffCaseController.php`
- `app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagController.php`
- `app/Http/Controllers/Api/Staff/StaffSite/StaffEnquiryController.php`
- `app/Http/Controllers/Api/PublicSite/PublicReportController.php`
- `app/Models/Views/AllSiteData.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 7 complete

---

## P2 — Should fix

- [ ] **#API-1** · P2 — `UserResource` is dead code carrying unconditional PII
    - **Where:** `app/Http/Resources/UserResource.php:10–37`
    - **Affects:** Not currently exposed — confirmed zero import sites. The risk is latent: any developer adding a quick endpoint could reach for the unlabelled `UserResource` instead of the three audience-specific Resources already in scope, silently shipping `phone`, `primary_email`, and `location_street_address`/`location_postcode` to the wrong caller.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Delete `app/Http/Resources/UserResource.php` — it has no import sites and all its legitimate callers already use `UserDashboardResource` (owner), `UserPublicResource` (unauthenticated), or `UserStaffResource` (staff).
        - While deleting: also delete `app/Http/Resources/UserPublicResource.php`, which is similarly orphaned (zero imports). It is PII-safe so it carries no immediate risk, but a second unlabelled Resource in the same directory adds to the confusion.
        - If either file must be kept, add a `@internal` docblock warning and enforce with a `grep -r "new UserResource"` CI check that fails on any use.
    - **Technical:** `grep -r "use App\Http\Resources\UserResource"` returns no matches across the entire `app/` tree. The class is declared but never instantiated. `UserStaffResource` and `UserDocumentController` contain comments referencing `UserResource` by name as a contrast note (e.g. "must NEVER appear in UserResource (/me)"), confirming it is recognised as a legacy concept. The project already ships the correct three-way split (`UserDashboardResource`, `UserPublicResource`, `UserStaffResource`). Leaving a fourth unlabelled class that dumps `phone`, `primary_email`, and street address unconditionally is a maintenance hazard that costs nothing to remove.
    - **Plain English:** Imagine three clearly-labelled filing trays on a desk — "for the owner," "for the public," "for the admin team." Then there is a fourth unlabelled tray that contains everything including someone's private phone number and home address. Nobody is using the fourth tray right now, but it is sitting there ready to be grabbed by accident. Throwing it away costs five seconds and prevents an embarrassing mistake.
    - **Evidence:**
        ```php
        // app/Http/Resources/UserResource.php — zero import sites, never instantiated
        public function toArray(Request $request): array
        {
            return [
                'id' => (string) $this->id,
                'account_type' => $this->account_type?->value,
                // ...
                'phone' => $this->phone,
                'primary_email' => $this->primary_email,
                // ...
                'location_street_address' => $this->location_street_address,
                'location_city' => $this->location_city,
                'location_state' => $this->location_state,
                'location_postcode' => $this->location_postcode,
                'location_country' => $this->location_country,
                // ...
            ];
        }
        ```

- [ ] **#API-2** · P2 — `StaffSiteResource` passes blocks as a raw JSONB array with no field gate
    - **Where:** `app/Http/Resources/Staff/StaffSiteResource.php:43`
    - **Affects:** The staff "view professional's site" endpoint. Every block column that the `site.all_site_data` view aggregates into its `blocks` JSONB arrives at the staff API without passing through an explicit Resource allowlist.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create a `StaffBlockResource` (or two: one for `links`-group blocks and one for `sections`-group blocks) that enumerates only the fields staff dashboards consume.
        - In `StaffSiteResource::toArray()`, replace `$this->blocks ?? []` with `StaffBlockResource::collection(collect($this->blocks ?? []))` (or equivalent mapping over the decoded JSONB array).
        - Gate the permitted set to: `id`, `block_group`, `block_type`, `title`, `url`, `content`, `is_active`, `is_deleted` (soft-delete visibility is the staff requirement), `sort_order`, `created_at`.
    - **Technical:** `AllSiteData` (`app/Models/Views/AllSiteData.php`) casts `blocks` as `'array'` — a decoded aggregate of every block column in the DB view. `StaffSiteResource` correctly gates the site and professional fields with explicit key allowlists but passes `$this->blocks` through verbatim. The class-level comment reads "any future column on that view must be consciously added here to reach the staff API" — this is true for the `site` and `professional` keys, but the `blocks` bypass silently contradicts the same comment. If the `site.all_site_data` view gains a column in the block aggregate (e.g. a moderation risk score, a `processing_state`, a Cloudflare KV key), it ships immediately to the staff API without review.
    - **Plain English:** The staff dashboard that shows a professional's content has a doorman checking IDs for almost everything — the profile fields, the site settings, and the design info all go through a list of approved fields. But the content blocks section sneaks past the doorman entirely. If the database ever adds a new internal column to blocks (like a fraud-detection score), it would appear on the staff screen the moment it landed — no one would have decided whether staff should see it.
    - **Evidence:**
        ```php
        // app/Http/Resources/Staff/StaffSiteResource.php
        // Comment above reads "any future column on that view must be consciously added here"
        // — but blocks bypass the allowlist entirely:
        'blocks' => $this->blocks ?? [],
        ```
        ```php
        // app/Models/Views/AllSiteData.php — blocks decoded as a PHP array
        protected $casts = [
            'site_settings' => 'array',
            'blocks' => 'array',   // decoded JSONB aggregate of all block columns
            'is_published' => 'boolean',
            // ...
        ];
        ```

---

## P3 — Nice to have

- [ ] **#API-3** · P3 — `UserSelfController::show()` hand-rolls the site payload instead of using `SiteResource`
    - **Where:** `app/Http/Controllers/Api/User/Account/UserSelfController.php:36–53`
    - **Affects:** The dashboard bootstrap payload (`GET /api/me`). Clients consuming the site shape get a different key set from this endpoint than from `UserSiteController` (`GET /api/site`), which uses `SiteResource`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the manual site array with `new SiteResource($pro->site)` and add `subdomain_change_available_at` as a separate top-level key (it is the only field the bootstrap needs that `SiteResource` does not expose): `'subdomain_change_available_at' => $pro->site?->subdomain_changed_at?->copy()->addDays(...)->toIso8601String()`.
        - This means the dashboard response structure is `{'professional': ..., 'site': <SiteResource>, 'subdomain_change_available_at': ..., 'blocks': ..., 'services': ..., 'customers_count': ...}`.
    - **Technical:** `SiteResource` exposes `id`, `user_id`, `subdomain`, `skeleton_id`, `is_published`, `subdomain_changed_at`, `unpublished_at`, `settings`, `created_at`, `updated_at`, `booking_mode`, `manual_booking_url`. The manual array omits `user_id`, `unpublished_at`, `created_at`, `updated_at`, `booking_mode`, `manual_booking_url`, and uses a different key for the cooldown date. Any future `SiteResource` field (e.g. `custom_domain`, `custom_domain_status`) auto-appears in `UserSiteController` but silently misses the bootstrap payload, leading to "why doesn't the dashboard show my custom domain?" bugs.
    - **Plain English:** There are two ways to look at your site settings in the dashboard: the dedicated settings page, and the main landing screen when you first log in. The settings page and the landing screen were built by different people at different times, so they show slightly different sets of fields. When a new setting is added, the settings page gets it automatically, but someone has to remember to manually update the landing screen too — and they often don't. Using the same template at both screens solves this permanently.
    - **Evidence:**
        ```php
        // app/Http/Controllers/Api/User/Account/UserSelfController.php:36–53
        'site' => $pro->site ? [
            'id' => $pro->site->id,
            'subdomain' => $pro->site->subdomain,
            'subdomain_change_available_at' => $pro->site->subdomain_changed_at
                ? $pro->site->subdomain_changed_at->copy()->addDays((int) config('partna.handle.subdomain_cooldown_days', 30))->toIso8601String()
                : null,
            'is_published' => (bool) $pro->site->is_published,
            'skeleton_id' => $pro->site->skeleton_id,
            'settings' => $siteSettings,
        ] : null,
        // vs. UserSiteController::show() which returns: new SiteResource($site)
        ```

- [ ] **#API-4** · P3 — Three user-facing list endpoints return unbounded collections with no pagination
    - **Where:**
        - `app/Http/Controllers/Api/User/SiteManagement/UserSmartLinkController.php:44–49`
        - `app/Http/Controllers/Api/User/SiteManagement/UserServiceController.php:59` (non-cached fallback path)
        - `app/Http/Controllers/Api/User/SiteManagement/UserServiceCategoryController.php:38`
    - **Affects:** Professionals with large catalogues. Smart links in particular have no natural upper bound — a professional linking their full product catalogue (Shopify or otherwise) accumulates links linearly. Service categories are bounded in practice; services and smart links are not.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - `UserSmartLinkController::index()`: add `->paginate(50)` and return a paginated envelope. Smart links are ordered by `family` then `sort_order` — natural page boundaries.
        - `UserServiceController::index()`: the hot-path cache already returns a capped active-service list; ensure the non-cached fallback (archive/filter views) caps with `->paginate(100)` or `->limit(500)`.
        - `UserServiceCategoryController::index()`: categories are few in practice, but add `->limit(200)` as a safety cap to bound the query under adversarial conditions.
    - **Technical:** All three endpoints call Eloquent `->get()` with no `LIMIT`. For `UserSmartLinkController` the full table-scan result for a site with 500+ smart links would produce a 500-row JSON payload on every dashboard load. The hot-path in `UserServiceController` is already cached via `UserCacheService`; the finding applies only to the less-common filtered/archived fallback. Categories are bounded by user behaviour but have no DB-level cap.
    - **Plain English:** When you open your dashboard, it asks the database "give me all my smart links" — and the database hands back every single one, no matter how many there are. For most people with a handful of links this is fine, but for someone with hundreds it means downloading a large document every time the page loads. Pagination is like saying "just give me the first 50, and I'll ask for more if I need them."
    - **Evidence:**
        ```php
        // UserSmartLinkController::index() — no limit
        $links = SmartLink::query()
            ->where('site_id', $site->id)
            ->orderBy('family')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();
        ```
        ```php
        // UserServiceController::index() — non-cached fallback path, no limit
        $services = $servicesQuery->orderBy('sort_order')->orderBy('created_at')->get();
        ```

- [ ] **#API-5** · P3 — Three controllers build media/document response payloads via private helper methods instead of Resource classes
    - **Where:**
        - `app/Http/Controllers/Api/User/Account/UserDocumentController.php:279–298` (`buildDocumentPayload`)
        - `app/Http/Controllers/Api/User/Uploads/UserUploadController.php:351–404` (`buildMediaPayload`)
        - `app/Http/Controllers/Api/User/Uploads/UserDesignMediaController.php:92–108` (`payload`)
    - **Affects:** Future `SiteMedia` column additions. The private helpers are explicit allowlists today, so there is no current leakage — but when a developer adds a new `SiteMedia` column they must know to touch three private methods scattered across separate controllers as well as any Resource classes, rather than updating one canonical Resource.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create `app/Http/Resources/SiteMediaResource.php` covering the common fields (`id`, `pool`, `alt_text`, `caption`, `media_type`, `processing_state`, `processing`, `processing_error`, `sort_order`, `created_at`, `updated_at`) plus conditional variant/stream maps via `$this->when(...)`.
        - Create `app/Http/Resources/DocumentMediaResource.php` extending `SiteMediaResource` with the document-specific keys (`title` alias, `is_enabled`, `original_mime`, `original_size_bytes`, `original_filename`, `preview_url`, `download_url`).
        - Replace all three `buildXPayload` / `payload` private methods with `new SiteMediaResource($media)` / `new DocumentMediaResource($media)` returns.
    - **Technical:** Category-(1) policy violation — the project standard is that all API responses flow through a Resource class with an explicit allowlist `toArray()`. The three private helpers are well-constructed and do not leak internal fields; the violation is structural (maintainability), not a data exposure risk at this moment. The comment in `UserServiceController`'s grouped payload notes "Hand-rolled arrays previously leaked raw model fields (audit P1-05)," confirming that this pattern has caused real past leakage and is being actively cleaned up.
    - **Plain English:** Three separate workshops each have their own handwritten shipping checklist for the same type of product (media files). The checklists are accurate today, but the company's official inventory system lives in a central computer. Every time a new product attribute gets added, someone has to walk between all three workshops and update each checklist by hand. Moving everyone to the central computer means one update, everywhere, automatically.
    - **Evidence:**
        ```php
        // UserDocumentController::buildDocumentPayload — private helper, not a Resource
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
                'created_at' => $media->created_at?->toIso8601String(),
                'updated_at' => $media->updated_at?->toIso8601String(),
            ];
        }
        ```
        ```php
        // UserUploadController::buildMediaPayload — same pattern
        private function buildMediaPayload(SiteMedia $media, bool $includeVariants = false): array
        {
            $payload = [
                'id' => $media->id,
                'pool' => $media->pool,
                'alt_text' => $media->alt_text,
                'caption' => $media->caption,
                'sort_order' => $media->sort_order,
                'media_type' => $media->media_type,
                'processing_state' => $media->processing_state,
                'processing' => $isProcessing,
                // ...
            ];
        }
        ```

- [ ] **#API-6** · P3 — `StaffUserController::index()` hand-rolls the professional list instead of using `UserStaffResource`
    - **Where:** `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php:60–84`
    - **Affects:** Staff dashboard list view. The `show()` method in the same controller correctly uses `new UserStaffResource($professional)`, creating a divergent shape between the list and detail views for the same resource.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace the inline `$page->getCollection()->map(...)` closure with `UserStaffResource::collection($page->getCollection())`.
        - Remove the manual `$payload['professionals'] = $professionals` overwrite — `paginatedResponse` + the collection handles serialisation.
        - If the list view should be lighter than the full `UserStaffResource` (no relations, no computed fields), create a `UserStaffListResource` that mirrors the current map's seven-field allowlist, and use that for the index only.
    - **Technical:** The `map()` closure is an explicit allowlist (it only emits the seven listed fields, so new model columns do not auto-appear), which means this is not an active leakage risk. The issue is contract drift between endpoints: `show()` returns `UserStaffResource` which includes `status`, `account_type`, staff-only flags (per `UserStaffResource::toArray`), and the full site sub-object; `index()` returns a flat map with a stripped site sub-object and no staff-specific fields. Clients building a staff UI must maintain per-endpoint shape awareness instead of reusing one type. Additionally, `$p->id` in the map is an un-cast UUID object — the explicit `(string)` cast in `UserStaffResource` and in `ApiController`-based responses is absent here.
    - **Plain English:** On the staff dashboard, clicking into an individual professional's record shows all their details in one format. The list view that shows all professionals uses a different format. Staff-facing code that reads from both views has to handle two different shapes for the same thing — like two different forms asking for your name but with the fields in different order and some missing. Standardising both to the same Resource class means one shape, everywhere.
    - **Evidence:**
        ```php
        // StaffUserController::index() — inline map, not UserStaffResource
        $professionals = $page->getCollection()->map(function (User $p) {
            $site = $p->site;
            return [
                'id' => $p->id,          // no (string) cast; UserStaffResource casts explicitly
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
                    'skeleton_id' => $site->skeleton_id,
                ] : null,
            ];
        });
        $payload = $this->paginatedResponse($page, 'professionals');
        $payload['professionals'] = $professionals;   // overwrites the paginated collection
        ```

- [ ] **#API-7** · P3 — `StaffAnalyticsController` returns raw `stdClass` DB results; model IDs lack explicit string casts
    - **Where:** `app/Http/Controllers/Api/Staff/StaffSite/StaffAnalyticsController.php:161–190`
    - **Affects:** Staff analytics dashboard. `visits_by_day`, `clicks_by_day`, and `top_links` are `Illuminate\Support\Collection` of `stdClass` objects from `DB::table()->selectRaw()->get()`; `professional.id` and `site.id` are Eloquent UUID objects without `(string)` cast.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Cast `$professional->id` and `$site->id` to string at their use sites (lines 167, 172).
        - Map `$topLinks` through a minimal closure or lightweight Resource that casts `block_id` to string and normalises `day` field types on `$visitsByDay`/`$clicksByDay`.
        - Optionally wrap the whole response in a `StaffAnalyticsResource` to enforce the shape contract and prevent future raw-DB leakage when queries are extended.
    - **Technical:** Chart data (`visits_by_day`, `clicks_by_day`) is raw `Collection<stdClass>` from `DB::table(...)->selectRaw('DATE(occurred_at) as day, COUNT(*) as count')->get()`. The pgsql driver returns `day` as a string (`'2026-06-13'`), but this is a driver implementation detail — not guaranteed by the interface. `top_links` carries `block_id` (PostgreSQL UUID) which the pgsql driver returns as a plain string, but frontend strict-type consumers (Zod) that depend on the `(string)` cast convention will flag it if the pattern is applied inconsistently. Staff-only surface; no PII risk. The main risk is contract drift as the query is extended.
    - **Plain English:** The analytics page on the staff dashboard fetches its chart data straight from the database like a raw spreadsheet printout. The IDs in that data come out in whatever format the database uses at that moment, which can vary between environments. The rest of the codebase explicitly converts IDs to strings so the frontend always sees the same type. Applying the same conversion here means no surprises when someone extends the query.
    - **Evidence:**
        ```php
        // StaffAnalyticsController — raw DB collections, un-cast IDs
        'professional' => [
            'id' => $professional->id,    // Eloquent UUID object, no (string) cast
            'handle' => $professional->handle,
            'display_name' => $professional->display_name,
        ],
        'site' => [
            'id' => $site->id,            // same
            'subdomain' => $site->subdomain,
            'published' => (bool) $site->is_published,
        ],
        'charts' => [
            'visits_by_day' => $visitsByDay,     // Collection<stdClass> from DB::table()
            'clicks_by_day' => $clicksByDay,
        ],
        'top_links' => $topLinks,                // Collection<stdClass>, block_id is un-cast UUID
        ```

- [ ] **#API-8** · P3 — Four controllers return non-standard error shapes, diverging from `ApiController::error()`
    - **Where:**
        - `app/Http/Controllers/Api/User/Account/MfaController.php:40–43` — `{'message': ..., 'code': 'mfa_fresh_required'}` flat sibling key
        - `app/Http/Controllers/Api/User/Account/SessionController.php:90–93` — raw `response()->json(['message' => ...], 400)` outside `ApiController`
        - `app/Http/Controllers/Api/User/Site/HandleReclaimController.php:26` — raw `response()->json(['status' => 'ok'])` instead of `$this->success()`
        - `app/Http/Controllers/Api/PublicSite/PublicReportController.php:30–33` — `{'error': 'INVALID_TARGET', 'message': ...}` extra non-standard `error` key
    - **Affects:** Frontend error-handling code. Clients must maintain per-endpoint shape-awareness instead of parsing one `ApiError` type; the `'code'` key in the MFA response and the `'error'` key in the report response will be silently missed by handlers reading only the standard `'errors'` bag.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `MfaController`: move the `'code'` value into the `errors` bag: `$this->error('Recent MFA verification required', 401, ['code' => 'mfa_fresh_required'])`. Note `MfaController` already extends `Controller`, not `ApiController` — extend `ApiController` or inject `ApiController::error()` equivalence via a trait.
        - `SessionController`: same — extend `ApiController` and replace `response()->json(['message' => ...], 400)` with `$this->error(...)`.
        - `HandleReclaimController`: replace `response()->json(['status' => 'ok'])` with `$this->success(['status' => 'ok'])` (it already extends `ApiController`).
        - `PublicReportController`: extend `ApiController` and replace both error returns with `$this->error('...', 422)` / `$this->error('...', 409)`.
    - **Technical:** `ApiController::error()` produces `{'message': string, 'errors'?: object}`. The four controllers diverge: `MfaController` adds a top-level `'code'` sibling; `SessionController` and `PublicReportController` return raw `response()->json()` shapes; `HandleReclaimController` uses `response()->json()` for a success path it has access to via `$this->success()`. The MFA code deviation is the most impactful: the `'code': 'mfa_fresh_required'` value is semantically important (the frontend needs it to distinguish a fresh-MFA gate from a generic 401), and if a client's error handler normalises to `errors.code` it will not find it.
    - **Plain English:** Every door in the building has the same sign format for emergencies: "Problem: [description]." But four doors use different sign formats — one says "Problem:" on one line and "Code: fire" on another line; one puts the problem description in the wrong box; one uses a completely different sign style. When the emergency response team runs their standard checklist, they can't find the information they expect because it is in a non-standard place.
    - **Evidence:**
        ```php
        // MfaController::destroy() — flat 'code' key, not in 'errors' bag
        return response()->json([
            'message' => $gate->message() ?: 'Recent MFA verification required',
            'code' => 'mfa_fresh_required',
        ], $gate->status() ?? 401);
        ```
        ```php
        // SessionController::destroy() — raw response()->json(), not $this->error()
        return response()->json([
            'message' => 'Use /sessions/logout to end the current session.',
        ], 400);
        ```
        ```php
        // HandleReclaimController::store() — raw response()->json() for a success path
        // (extends ApiController, $this->success() is available)
        return response()->json(['status' => 'ok']);
        ```
        ```php
        // PublicReportController::submit() — non-standard 'error' key alongside 'message'
        return response()->json([
            'error' => 'INVALID_TARGET',
            'message' => "We couldn't find that page.",
        ], 422);
        ```

- [ ] **#API-9** · P3 — Staff list endpoints use four different `per_page` defaults (20 / 25 / 50 / hardcoded)
    - **Where:**
        - `app/Http/Controllers/Api/Staff/StaffSite/StaffEnquiryController.php` — default 20
        - `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php` — default 25
        - `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffCustomerManagementController.php` — default 25
        - `app/Http/Controllers/Api/Staff/StaffCaseController.php` — hardcoded 25
        - `app/Http/Controllers/Api/Staff/StaffSite/StaffEmailSubscriberController.php` — default 50
        - `app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagController.php` — hardcoded 50
    - **Affects:** Staff dashboard consumers that paginate across multiple screens. Page-size inconsistency means reusable pagination components must detect per-endpoint page size instead of relying on a single default.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `staff.pagination.per_page` and `staff.pagination.per_page_max` to `config/partna.php` (e.g. 25 and 100 respectively).
        - Update the six controllers to read from config. For `StaffFeatureFlagController` and `StaffCaseController` which hardcode the value, pass it through `normalizePerPage($request, config('partna.staff.pagination.per_page'), config('partna.staff.pagination.per_page_max'))`.
        - Exceptions are acceptable where resource scale genuinely warrants a different default (email subscribers being 50 is defensible); document any intentional deviation in the controller's docblock.
    - **Technical:** Category-(4) pagination inconsistency. No security or correctness impact; purely a client-ergonomic maintenance issue. The `NormalizesPerPage` trait is already present in `StaffUserController` and `StaffEmailSubscriberController`; the other four controllers use `->paginate((int) $request->integer('per_page', N))` inline. Centralising to config gives one place to adjust and makes the deviation between, for example, subscribers (50) and enquiries (20) a visible, documented choice.
    - **Plain English:** The staff dashboard has multiple pages — one for professionals, one for enquiries, one for email subscribers. Each page shows a different number of items before asking "next page?" Some show 20, some 25, some 50. Every time a developer builds a new staff screen or a new navigation component, they have to look up which magic number applies to that page. Putting the default in one config file is like posting the house rules on the fridge: everyone can find them in the same place.
    - **Evidence:**
        ```php
        // StaffEnquiryController — 20
        ->paginate((int) $request->integer('per_page', 20));
        
        // StaffUserController — 25
        $perPage = $this->normalizePerPage($request, 25, 100);
        
        // StaffEmailSubscriberController — 50
        $perPage = $this->normalizePerPage($request, 50, 200);
        
        // StaffFeatureFlagController — 50 hardcoded (no per_page override supported)
        $flags = FeatureFlag::withCount('overrides')->orderBy('key')->paginate(50);
        
        // StaffCaseController — 25 hardcoded
        return CaseResource::collection($query->paginate(25));
        ```

