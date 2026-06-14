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
