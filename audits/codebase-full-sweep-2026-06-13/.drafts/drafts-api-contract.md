
<!-- ═══ LENS: api-contract | CHUNK: user-api ═══ -->

- [ ] **#API-1** · P2 — `UserResource` exposes full PII with no clear audience, alongside three already-separated audience-specific Resources
    - **Where:** app/Http/Resources/UserResource.php:12-35
    - **Affects:** Any endpoint (existing or future) that resolves `UserResource` — if used outside an authenticated-owner or staff context, `phone`, `primary_email`, `location_street_address`, and `location_postcode` ship to the wrong caller.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - grep for `UserResource` usage across all controllers; if none found, delete the file (dead code carrying PII is a loaded gun).
        - If still used, confirm the call site’s audience. If it's the owner/Staff surface, redirect to `UserDashboardResource` or `UserStaffResource`. If it's public, replace with `UserPublicResource`.
        - Consider adding a CI guard (e.g. a PHPStan rule) that fails on `new UserResource` to prevent accidental reintroduction.
    - **Technical:** The project already ships three audience-specific Resources — `UserDashboardResource` (owner), `UserPublicResource` (unauthenticated), and `UserStaffResource` (staff) — which strongly suggests `UserResource` is a legacy artifact. It exposes `phone`, `primary_email`, `location_street_address`, and `location_postcode` unconditionally. If any controller or service instantiates it for a PublicSite or cross-tenant response, PII leaks. The file is not imported by any controller in the provided audit scope, but its mere existence in `app/Http/Resources/` means a developer adding a quick endpoint could reach for it without realising the audience-specific alternatives exist.
    - **Plain English:** Imagine three mailboxes: one for your personal letters, one for public newsletters, and one for the post office inspector. But there's also a fourth unlabelled mailbox that dumps everything — phone number, home address, private email — into whichever bin someone tosses it in. The labelled mailboxes already exist and work correctly. The unlabelled one just needs to be removed before someone accidentally uses it.
    - **Evidence:**
        ```php
        public function toArray(Request $request): array
        {
            return [
                'id' => (string) $this->id,
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
    - `[DRAFT, confidence: 0.7]`

- [ ] **#API-2** · P2 — `UserSelfController::show()` builds the site payload as a raw array instead of using `SiteResource`, creating a divergent contract from `UserSiteController::show()`
    - **Where:** app/Http/Controllers/Api/User/Account/UserSelfController.php:37-53
    - **Affects:** The dashboard bootstrap payload (`GET /api/me`). Clients consuming the site shape get different keys depending on which endpoint they call.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the manual `'site' => [...]` array with `'site' => $pro->site ? new SiteResource($pro->site) : null`.
        - If `subdomain_change_available_at` (a computed value not in `SiteResource`) is still needed by the dashboard, add it as a conditional field on `SiteResource` via `$this->when(...)` or add it to the `UserSelfController` response as a separate top-level key.
    - **Technical:** `UserSiteController::show()` returns `new SiteResource($site)`, which produces a shape with `id`, `user_id`, `subdomain`, `skeleton_id`, `is_published`, `subdomain_changed_at`, `unpublished_at`, `settings`, `created_at`, `updated_at`, plus conditional `booking_mode`/`manual_booking_url`. `UserSelfController::show()` hand-rolls a site array with `id`, `subdomain`, `subdomain_change_available_at`, `is_published`, `skeleton_id`, `settings` — a completely different key set. The manual array is a category-(1) raw return and a category-(5) shape inconsistency in one. If `SiteResource` gains a new field (e.g. `custom_domain_status`), the dashboard bootstrap silently misses it while the dedicated site endpoint gets it, leading to subtle UI bugs.
    - **Plain English:** There are two doors into the same room — one through the site settings page and one through the main dashboard. The settings page gives you a full inventory list. The dashboard gives you a handwritten sticky note with only some of the items. If the inventory list gets updated, the sticky note falls out of date and nobody notices until something breaks. Use the same inventory list at both doors.
    - **Evidence:**
        ```php
        'site' => $pro->site ? [
            'id' => $pro->site->id,
            'subdomain' => $pro->site->subdomain,
            'subdomain_change_available_at' => $pro->site->subdomain_changed_at
                ? $pro->site->subdomain_changed_at->copy()->addDays(...)->toIso8601String()
                : null,
            'is_published' => (bool) $pro->site->is_published,
            'skeleton_id' => $pro->site->skeleton_id,
            'settings' => $siteSettings,
        ] : null,
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#API-3** · P3 — `UserServiceController::index()`, `UserSmartLinkController::index()`, and `UserServiceCategoryController::index()` return unbounded collections without pagination
    - **Where:**
        - app/Http/Controllers/Api/User/SiteManagement/UserServiceController.php:46-48 (`->get()` on hot-path services list)
        - app/Http/Controllers/Api/User/SiteManagement/UserSmartLinkController.php:42-46 (`->get()` on smart links)
        - app/Http/Controllers/Api/User/SiteManagement/UserServiceCategoryController.php:36 (`->get()` on categories)
    - **Affects:** Professionals with many services, smart links, or categories. Response size grows linearly with no bounds; large payloads slow the dashboard and waste bandwidth.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `->paginate(50)` (or an appropriate `per_page`) to each of the three index queries.
        - Use `ReturnsPaginatedResponse` trait (already imported in `UserServiceController` and `UserServiceCategoryController`) for consistent `meta`/`pagination` envelopes.
        - For the cached fast-path in `UserServiceController::index()`, either paginate at the cache layer or cap the cached result set and append `has_more`.
    - **Technical:** Three list endpoints use Eloquent `->get()` with no limit: `UserServiceController::index()` (both cached and fallback paths), `UserSmartLinkController::index()`, and `UserServiceCategoryController::index()`. While service categories are typically few per professional, services and smart links are unbounded. The platform currently has individual-only accounts, so one professional could accumulate hundreds of smart links (every Shopify product is a link). `->get()` with no `->limit()` or `->paginate()` means the full result set is hydrated every request. A professional with 300 smart links gets a 300-row JSON payload on every dashboard load.
    - **Plain English:** Imagine a filing cabinet that, every time you open it, dumps every single document onto your desk — even if you only need the top five. Most people have a few dozen documents and barely notice. But someone with a thousand documents gets buried every time they open the drawer. Adding pagination is like giving them folders: "here's the first 50, ask for the next 50 when you're ready."
    - **Evidence:**
        ```php
        // UserServiceController::index() — cached fast-path returns all services
        return $this->success([
            'services' => app(UserCacheService::class)->getDashboardServices($pro->id),
        ]);
        // ...and the non-cached fallback:
        $services = $servicesQuery->orderBy('sort_order')->orderBy('created_at')->get();
        ```
        ```php
        // UserSmartLinkController::index()
        $links = SmartLink::query()
            ->where('site_id', $site->id)
            ->orderBy('family')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#API-4** · P3 — Three controllers return media/document payloads via hand-rolled arrays instead of Resource classes, violating the "all API responses through Resources" policy
    - **Where:**
        - app/Http/Controllers/Api/User/SiteManagement/UserDocumentController.php:216-231 (`buildDocumentPayload`)
        - app/Http/Controllers/Api/User/Uploads/UserUploadController.php:231-285 (`buildMediaPayload`)
        - app/Http/Controllers/Api/User/Uploads/UserDesignMediaController.php:101-119 (`payload`)
    - **Affects:** Future `SiteMedia` column additions (e.g. moderation flags, new processing states) won't auto-leak (the arrays are explicit allowlists), but the pattern makes the codebase inconsistent — every new media field requires hunting down these private methods alongside the Resource directory.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract `buildMediaPayload` into a `SiteMediaResource` (or `MediaResource`) class covering the shared fields.
        - For `buildDocumentPayload`, create a `DocumentResource` (or extend `SiteMediaResource` with document-specific fields like `preview_url` / `download_url`).
        - For `UserDesignMediaController::payload()`, create a `DesignMediaResource`.
        - Wire each controller to return `new XResource($media)` instead of calling the private method.
    - **Technical:** Category (1) policy violation. `UserDocumentController`, `UserUploadController`, and `UserDesignMediaController` all build response payloads by calling private methods that assemble arrays manually. The project standard after P2-35 (referenced in `GalleryImageResource` and `SectionBlockResource` docblocks) is that every API response goes through an `ApiResource` subclass with an explicit allowlist `toArray()`. These three controllers predate that cleanup. The arrays are well-constructed (no raw model passthrough), but a developer adding a `SiteMedia` column must know to touch three separate private methods plus any Resource classes — a maintenance hazard.
    - **Plain English:** Three different workshops in the same factory each have their own hand-drawn shipping checklist taped to the wall. The checklists are accurate today, but the factory's official inventory system lives in a central computer. When a new product attribute gets added, someone has to walk around and update all three checklists by hand — and miss one, and that workshop starts shipping incomplete boxes. Moving everyone to the central computer makes updates automatic.
    - **Evidence:**
        ```php
        // UserDocumentController — private array builder, not a Resource
        private function buildDocumentPayload(SiteMedia $media): array
        {
            return [
                'id' => $media->id,
                'title' => $media->alt_text,
                'caption' => $media->caption,
                'is_enabled' => (bool) $media->is_active,
                'original_mime' => $media->original_mime,
                // ... 7 more hand-rolled keys
            ];
        }
        ```
        ```php
        // UserUploadController — same pattern
        private function buildMediaPayload(SiteMedia $media, bool $includeVariants = false): array
        {
            $payload = [
                'id' => $media->id,
                'pool' => $media->pool,
                // ... 10+ hand-rolled keys + conditional variant logic inline
            ];
            // ...
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#API-5** · P3 — Inconsistent error response shapes across account/auth controllers vs standard `ApiController::error()` envelope
    - **Where:**
        - app/Http/Controllers/Api/User/Account/MfaController.php:48-54
        - app/Http/Controllers/Api/User/Account/SessionController.php:41,51,65-68,89-92
        - app/Http/Controllers/Api/User/Site/HandleReclaimController.php:23
    - **Affects:** Frontend error-handling code. Clients must parse `{'message': ..., 'code': ...}` (MFA), `{'message': ...}` (Session), and `{'message': ..., 'errors': {...}}` (standard) with different shape-aware branches.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Switch `MfaController` 401/502 responses to use `$this->error('message', status, ['code' => 'mfa_fresh_required'])` so the `'code'` detail goes into the `errors` bag alongside the standard `message` key.
        - Switch `SessionController::destroy()` 400 response to `$this->error(...)`.
        - Switch `HandleReclaimController::store()` to `$this->success(['status' => 'ok'])`.
        - Document the canonical error envelope shape (`message` + optional `errors` object) in `ApiController`'s docblock so future controllers follow it.
    - **Technical:** Category (5) response shape inconsistency. The project standard error envelope, defined by `ApiController::error()`, is `{'message': string, 'errors'?: {...}}`. `MfaController` returns `{'message': string, 'code': string}` — a sibling `code` key that clients won't find if they only look in `errors`. `SessionController::destroy()` returns `{'message': string}` directly via `response()->json(...)`. `HandleReclaimController::store()` returns `{'status': 'ok'}` via raw `response()->json()` instead of `$this->success()`. Each deviation forces the frontend to maintain per-endpoint error-shape awareness instead of a single `ApiError` type.
    - **Plain English:** Three different cash registers in the same store print receipts in three different formats. The accounting team has to read each format differently to find the total. Standardising on one receipt format means accounting writes one parser and never thinks about it again.
    - **Evidence:**
        ```php
        // MfaController — flat 'code' key, no $this->error()
        return response()->json([
            'message' => $gate->message() ?: 'Recent MFA verification required',
            'code' => 'mfa_fresh_required',
        ], $gate->status() ?? 401);
        ```
        ```php
        // HandleReclaimController — raw json, no $this->success()
        return response()->json(['status' => 'ok']);
        ```
    - `[DRAFT, confidence: 0.8]`

<!-- ═══ LENS: api-contract | CHUNK: other-api ═══ -->

- [ ] **#API-1** · P2 — StaffUserController::index() bypasses Resource classes with inline array map
    - **Where:** app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php:115-135
    - **Affects:** Staff dashboard list view; every professional row returned to staff operators.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace the inline `$page->getCollection()->map(...)` with `UserStaffResource::collection($page->items())`.
        - Remove the manual `$payload['professionals'] = $professionals` overwrite — let `paginatedResponse` + Resource collection handle the serialisation.
        - Ensure `UserStaffResource` (or a lighter list-view variant) includes the site sub-object, or lift it to a dedicated `StaffUserListResource`.
    - **Technical:** The `ApiResource` contract requires every API response to flow through an explicit allowlist Resource class. The inline map constructs a raw array directly from the Eloquent model — any future column added to `core.users` (e.g. `internal_flags`, `admin_notes`) auto-ships to the wire without a conscious allowlist decision. It also bypasses the `(string) $this->id` cast convention, returning UUIDs as raw objects (Laravel casts them, but the contract guarantees string stability for Zod/TS consumers).
    - **Plain English:** Imagine every staff member's dashboard pulls a spreadsheet of professionals. Right now that spreadsheet is built by hand-picking columns from the database — if someone adds a new column tomorrow (like an internal fraud flag), it silently appears in the spreadsheet without anyone reviewing whether staff should see it. Using a Resource class is like having a pre-approved form: new columns only show up when someone deliberately adds them.
    - **Evidence:**
        ```php
        $professionals = $page->getCollection()->map(function (User $p) {
            $site = $p->site;

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
                    'skeleton_id' => $site->skeleton_id,
                ] : null,
            ];
        });

        $payload = $this->paginatedResponse($page, 'professionals');
        $payload['professionals'] = $professionals;
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#API-2** · P2 — StaffSiteResource passes raw blocks from AllSiteData view without a block Resource wrapper
    - **Where:** app/Http/Resources/Staff/StaffSiteResource.php:53
    - **Affects:** Staff site-inspection endpoint; all block data (links + sections, including soft-deleted/inactive) for the viewed professional.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap `$this->blocks` in a dedicated `StaffBlockResource` (or reuse `LinkBlockResource` / `SectionBlockResource` based on `block_group`).
        - If the blocks array comes from the `AllSiteData` view as raw JSONB or stdClass rows, hydrate them through a collection and apply the appropriate Resource per row.
        - Keep the full-visibility requirement (soft-deleted + inactive rows) but gate the fields through an explicit allowlist so future view columns don't auto-ship.
    - **Technical:** `AllSiteData` is a DB view whose columns are the union of the current schema. Adding a column to that view automatically adds it to `$this->blocks` and therefore to the staff API response — no Resource allowlist stands in the way. The staff site detail endpoint is the highest-sensitivity staff read path (it sees every block a professional has ever created), so it benefits most from the explicit-allowlist defence that every other endpoint already uses.
    - **Plain English:** The staff "view site" screen shows every content block a professional has on their page. Right now those blocks are dumped onto the screen exactly as they come from the database view. If the database view gains a new column next sprint — maybe an internal risk score — it quietly appears on the staff screen. A Resource class acts like a bouncer: only columns on the guest list get through.
    - **Evidence:**
        ```php
        'blocks' => $this->blocks ?? [],
        ```
        The `$this->blocks` value originates from the `AllSiteData` DB view (`app/Models/Views/AllSiteData`) which joins blocks via a raw SQL view definition — no Eloquent API Resource gate exists between the view row and the JSON response.
    - `[DRAFT, confidence: 0.9]`

- [ ] **#API-3** · P3 — StaffAnalyticsController returns raw stdClass DB results without Resource transformation; UUID IDs not string-cast
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffAnalyticsController.php:108-138
    - **Affects:** Staff analytics dashboard; chart data and top-links list.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create a lightweight `StaffAnalyticsResource` that wraps the summary payload and casts `professional.id`, `site.id`, and `top_links.*.block_id` to strings.
        - Alternatively, map `top_links` through a minimal Resource so `block_id` (a UUID) is consistently a string across all API surfaces.
    - **Technical:** The analytics endpoint assembles its response from raw `DB::table()->selectRaw()->get()` calls — `visits_by_day`, `clicks_by_day`, and `top_links` are all `Illuminate\Support\Collection` of `stdClass` objects. The `ApiResource` contract requires `id` fields to be string-cast for strict-typed consumers; `$professional->id`, `$site->id`, and `b.id as block_id` all ship as native types (UUID objects or strings depending on the driver). No PII is exposed (staff-only endpoint), but the pattern silently introduces contract drift.
    - **Plain English:** The analytics page on the staff dashboard receives its numbers and charts as raw database rows — like receiving a printed SQL query result instead of a formatted report. The IDs in that data can arrive in different formats depending on the database driver, which means the frontend chart code has to handle both "string" and "object" versions of the same ID. Resource classes standardise this so the frontend only ever sees one format.
    - **Evidence:**
        ```php
        'charts' => [
            'visits_by_day' => $visitsByDay,
            'clicks_by_day' => $clicksByDay,
        ],
        'top_links' => $topLinks,
        ```
        Where `$visitsByDay` and `$clicksByDay` come from `DB::table(...)->selectRaw(...)->get()`, and `$topLinks` comes from a joined `DB::table(...)->selectRaw('b.id as block_id, b.title, b.url, COUNT(*) as clicks')` — all raw stdClass collections with no Resource transformation.
    - `[DRAFT, confidence: 0.85]`

- [ ] **#API-4** · P3 — PublicReportController uses inconsistent error response shape vs the rest of the API
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicReportController.php:32-46
    - **Affects:** Public report-submission endpoint; any client parsing error responses from the PublicSite surface.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extend `ApiController` instead of `Controller` and use `$this->error(...)` for the 422 and 409 paths.
        - Or, if the `error`/`message` shape is intentional for this endpoint, document the divergence explicitly in a class-level docblock and ensure the frontend report-form handler expects both shapes.
    - **Technical:** `PublicReportController` extends `Controller` (not `ApiController`) and returns errors via `response()->json(['error' => '...', 'message' => '...'], status)`. Every other PublicSite controller returns errors via `$this->error('message', status)` which wraps through `ApiController`'s standard envelope (typically `{'message': '...', 'errors': {...}}`). Clients that built error-handling against the standard shape will misparse the `error`+`message` shape, showing a generic fallback instead of the specific "already reported" or "invalid target" copy.
    - **Plain English:** Every API endpoint in the app sends error messages in the same envelope — like every letter from the company uses the same letterhead. The report-submission endpoint uses a different envelope. When the frontend opens it looking for the standard format, it can't find the specific error message and falls back to a generic "something went wrong." The user sees "Something went wrong" instead of "You've already reported this."
    - **Evidence:**
        ```php
        return response()->json([
            'error' => 'INVALID_TARGET',
            'message' => "We couldn't find that page.",
        ], 422);
        ```
        Compare with the standard pattern used elsewhere in PublicSite controllers:
        ```php
        return $this->error('Site not found.', 404);
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#API-5** · P3 — Pagination per_page defaults vary across staff list endpoints (20, 25, 50)
    - **Where:** app/Http/Controllers/Api/Staff/ (multiple controllers)
    - **Affects:** Staff dashboard consumers that paginate through professionals, enquiries, customers, subscribers, and cases — each with a different default page size.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Pick a single staff default (e.g. 25) and apply it consistently across all staff index endpoints.
        - Document the chosen default in `config/partna.php` as `staff.pagination.per_page` so it's a single source of truth.
    - **Technical:** `StaffUserController` defaults to 25, `StaffEnquiryController` defaults to 20, `StaffEmailSubscriberController` defaults to 50, `StaffFeatureFlagController` hardcodes 50, `StaffCustomerManagementController` defaults to 25, `StaffCaseController` hardcodes 25. Clients that reuse a pagination wrapper across staff screens will see different page sizes and must either detect the inconsistency or hardcode per-endpoint — both brittle. There's no security or correctness impact; it's pure client-ergonomic drift.
    - **Plain English:** Imagine flipping through pages in a binder where some tabs hold 20 sheets, some 25, and some 50. Every time you switch tabs you have to remember how many sheets are in each one. Standardising the page size means every tab flips the same way.
    - **Evidence:**
        ```php
        // StaffEnquiryController — 20
        ->paginate((int) $request->integer('per_page', 20));
        
        // StaffUserController — 25
        $perPage = $this->normalizePerPage($request, 25, 100);
        
        // StaffEmailSubscriberController — 50
        $perPage = $this->normalizePerPage($request, 50, 200);
        
        // StaffFeatureFlagController — 50 (hardcoded)
        $flags = FeatureFlag::withCount('overrides')->orderBy('key')->paginate(50);
        
        // StaffCaseController — 25 (hardcoded)
        return CaseResource::collection($query->paginate(25));
        ```
    - `[DRAFT, confidence: 0.95]`
