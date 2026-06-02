`★ Insight ─────────────────────────────────────`
Two verification patterns applied here: `function theme` grep across `app/Models` returned zero hits — confirming `site.theme` is a dangling eager-load that will throw `BadMethodCallException` at runtime. The services/blocks dead-weight was confirmed by cross-referencing `UserStaffResource::toArray()` (no services/blocks fields) against the `load(['site.theme', 'services', 'blocks'])` call. Both are independently verifiable from source alone without executing code.
`─────────────────────────────────────────────────`

# N+1 / Unbounded Result Sets Audit — 2026-05-31

**Branch:** development
**Lens:** N+1 queries, unbounded result sets, missing eager-loading, missing pagination on hot read paths, queries whose row count grows with sites/customers/enquiries/visits at 10k scale
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/Http/Controllers/Api/User/Notifications/UserEmailSubscriptionController.php`
- `app/Http/Controllers/Api/Staff/StaffSite/StaffEmailSubscriberController.php`
- `app/Http/Controllers/Api/User/SiteManagement/UserServiceController.php`
- `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffServiceManagementController.php`
- `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php`
- `app/Http/Resources/UserStaffResource.php`
- `app/Models/Core/Site/Site.php`
- `app/Services/PublicSite/SitepageDataResolverService.php`
- `app/Services/PublicSite/IndividualProfilePayloadBuilder.php`
- `app/Services/Analytics/AnalyticsQueryService.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

- [ ] **#NPL-1** · P2 — Subscriber CSV exports stream an unbounded cursor with no row cap
    - **Where:** `app/Http/Controllers/Api/User/Notifications/UserEmailSubscriptionController.php` (`export` method) and `app/Http/Controllers/Api/Staff/StaffSite/StaffEmailSubscriberController.php` (`export` method)
    - **Affects:** Professionals exporting their marketing lists; staff performing GDPR Article 15/20 data-access exports. At 50k+ subscribers the response holds a PHP-FPM worker slot for tens of seconds per export. A small number of concurrent exports exhausts the pool and delays every other request on the host.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Before building the cursor, add `->limit(config('partna.export.max_rows', 50000))` to both export queries (the cap must be applied pre-stream because HTTP headers are already flushed once streaming begins — aborting inside the callback does not work).
        - Set a `X-Export-Truncated: 1` response header (computable before the stream starts) when the result set equals the cap, so the client can surface "Export capped at 50,000 rows — refine your filters to get the rest."
        - For full unlimited exports at scale, gate behind a background job that emails the CSV rather than streaming inline.
    - **Technical:** Both controllers build a `EmailSubscription` query scoped to `(user_id, list_key, status)` then pass it to `$query->cursor()` inside `response()->streamDownload()`. `cursor()` is memory-efficient (one PDO row at a time) but the total iteration count is unbounded — the loop runs until every matching row is consumed. No `->limit()`, no timeout inside the closure. The headers-already-sent constraint means the only safe cap point is before the closure is entered: `->limit(50000)` on the builder, evaluated before `streamDownload()` is called.
    - **Plain English:** When a professional clicks "export subscribers," the system hands a warehouse picker a clipboard that says "pull every folder — all of them." If the warehouse holds 80,000 folders, that picker is tied up for the rest of the afternoon and nobody else can get served. The fix is to put a number on the clipboard: "pull the first 50,000 and stick a note on top saying there are more."
    - **Evidence:**
        ```php
        // UserEmailSubscriptionController.php — export()
        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['email', 'full_name', 'status', 'subscribed_at', 'unsubscribed_at']);

            foreach ($query->cursor() as $row) {
                fputcsv($out, [
                    $row->email,
                    $row->full_name,
                    $row->status,
                    optional($row->subscribed_at)->toISOString(),
                    optional($row->unsubscribed_at)->toISOString(),
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
        ```

---

## P3 — Nice to have

- [ ] **#NPL-2** · P3 — Grouped service list fetches all rows with no hard cap
    - **Where:** `app/Http/Controllers/Api/User/SiteManagement/UserServiceController.php` (grouped path, `index` method) and `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffServiceManagementController.php` (grouped path, `index` method)
    - **Affects:** Professionals with unusually large service catalogues (bulk-imported or via future API integration) viewing the `?grouped=true` dashboard layout; staff inspecting those accounts. The cached hot path (`grouped=false`, no archive filter) is unaffected.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a hard cap before calling `->get()`: `$servicesQuery->limit(config('partna.limits.services_grouped_max', 500))`. Do the same for the categories query.
        - When the cap is reached, include `truncated: true` in the response payload so the frontend can surface "Showing first 500 services."
        - Pagination is not practical here because the drag-and-drop grouped UI requires the full ordered list in memory — a hard cap is the right guardrail for outlier accounts.
    - **Technical:** When `?grouped=true`, both controllers call `->get()` unconditionally on both the services query and the categories query. The grouped payload is then assembled entirely in PHP memory (PHP-level `groupBy`, two nested resource collection resolutions, nested array building). Most professionals have fewer than 30 services, so this is invisible at current scale. The data model places no hard limit, and a future bulk-import path or API integration could produce thousands of rows. The hot path (`UserCacheService::getDashboardServices`) is already protected by caching and bypasses this code entirely.
    - **Plain English:** The "group by category" view loads the entire service list before sorting it into buckets on screen. For a salon with 25 services this is invisible. If someone ever uploads a 3,000-item catalogue, the page tries to lay out all 3,000 items at once. Capping at 500 keeps the grouped view fast for outlier accounts while a "showing first 500" banner tells the professional to refine their view.
    - **Evidence:**
        ```php
        // UserServiceController.php — grouped path
        $services = $servicesQuery->orderBy('sort_order')->orderBy('created_at')->get();

        // ...

        $categories = $catQuery->orderBy('sort_order')->orderBy('created_at')->get();
        $servicesByCategory = $services->groupBy(fn (Service $s) => $s->category_id ?? '__uncategorised__');
        ```

- [ ] **#NPL-3** · P3 — StaffUserController::show() eagerly loads services and blocks that are never serialised, and references a theme relationship that no longer exists
    - **Where:** `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php` (`show` method, line 97)
    - **Affects:** Staff members opening an individual professional's detail page. Two unbounded eager-load queries (`SELECT * FROM site.services WHERE user_id = ?` and `SELECT * FROM site.blocks WHERE user_id = ?`) are issued and the resulting collections are discarded without being read. Additionally, `site.theme` references a relationship that is defined nowhere in `app/Models` (confirmed: `grep -r "function theme" app/Models` returns no matches), which will throw `BadMethodCallException` when the themes table is dropped during the skeleton-system cleanup.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `'services', 'blocks'` from the `load()` call — neither is consumed by `UserStaffResource::toArray()` or the inline site block in the response.
        - Replace the `site.theme` eager-load and the `theme` key in the response with `site.skeleton_id`. `Site::$fillable` already includes `skeleton_id`; there is no `theme()` relationship on the `Site` model. The `StaffUserController::index()` method has the same `with(['site.theme'])` problem and should be updated at the same time.
        - If services and blocks are ever needed on the staff detail view, introduce a separate sub-resource endpoint (e.g. `GET /staff/professionals/{professional}/services`) rather than bundling them into the single-account response.
    - **Technical:** `$professional->load(['site.theme', 'services', 'blocks'])` issues three eager-load queries beyond the initial user load. `UserStaffResource::toArray()` emits `id, auth_user_id, account_type, display_name, …, admin_notes, parent_status` — no services field, no blocks field. The inline `site` block in the response returns only `id`, `subdomain`, `is_published`, and `theme`. Both the `services` and `blocks` collections are fully hydrated Eloquent objects that are garbage-collected without a single property being read. For an account with 300 services and 60 blocks this issues two `SELECT *` queries loading tens of kilobytes of data per page load with zero return. The `site.theme` path additionally has no backing relationship in the codebase; the skeleton-system cleanup that drops `site.themes` will surface this as a hard crash on this endpoint.
    - **Plain English:** When a support team member opens a professional's account page, the backend silently pulls down their entire service list and every content block before building the response — even though neither list appears anywhere on the page. It's like printing two full filing cabinets just to answer "what's their subdomain?" Removing those two unnecessary pulls makes the page faster and eliminates the memory waste. The theme reference is a second issue: the part of the code that reads "show the old theme name" points to a table that's about to be deleted — it needs to be updated to say "show the skeleton choice" instead.
    - **Evidence:**
        ```php
        // StaffUserController.php — show(), line 97
        $professional->load(['site.theme', 'services', 'blocks']); // services & blocks never read below

        return $this->success([
            'professional' => new UserStaffResource($professional), // toArray() has no services or blocks key
            'site' => $professional->site ? [
                'id' => $professional->site->id,
                'subdomain' => $professional->site->subdomain,
                'is_published' => (bool) $professional->site->is_published,
                'theme' => $professional->site->theme ? [   // no theme() on Site model
                    'id' => $professional->site->theme->id,
                    'key' => $professional->site->theme->key ?? null,
                    'name' => $professional->site->theme->name ?? null,
                ] : null,
            ] : null,
        ]);
        ```
        ```php
        // UserStaffResource.php — toArray() — services and blocks are absent
        return [
            'id' => (string) $this->id,
            'auth_user_id' => $this->auth_user_id,
            'account_type' => $this->account_type?->value,
            'display_name' => $this->display_name,
            // ... (partna_url, first_name, last_name, bio, about, phone, primary_email,
            //      country_code, timezone, status, onboarding_step, public_contact_*,
            //      location_*, stripe_connect_status, admin_notes)
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'parent_status' => $this->trashed() ? 'soft_deleted' : 'active',
        ];
        ```

`★ Insight ─────────────────────────────────────`
The `site.theme` dead-reference in `StaffUserController` is a skeleton-cleanup tracking failure: the CLAUDE.md records the `site.themes` table as "DROPPED entirely" in progress, but no grep of `app/Models` turns up a `theme()` relationship — meaning the staff detail page is likely already broken in the development environment and will throw `BadMethodCallException` under any code path that reaches `$professional->site->theme` with a non-null site. Cross-referencing planned schema changes against controller code during cleanup is worth adding to the skeleton-system checklist.
`─────────────────────────────────────────────────`
