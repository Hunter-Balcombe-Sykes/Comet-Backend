`★ Insight ─────────────────────────────────────`
`getSiteLinkBlocks()` ends with `->toArray()` — so the `ProfessionalLinkBlockController::index()` path via the cache service is already serialized to plain PHP arrays and is safe. Only `store()` and `update()` on the link-block controller return raw Eloquent models.
`─────────────────────────────────────────────────`

# API Resource Shape Audit — 2026-05-24

**Branch:** development
**Lens:** raw model leakage, inconsistent Resource shapes, breaking-change risk, missing pagination/filtering contracts
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalServiceCategoryController.php
- app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalServiceController.php
- app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalLinkBlockController.php
- app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalSiteController.php
- app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalThemeController.php
- app/Http/Controllers/Api/Professional/Customers/ProfessionalCustomerController.php
- app/Http/Controllers/Api/Professional/Customers/ProfessionalEnquiryController.php
- app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalGalleryController.php
- app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffCustomerManagementController.php
- app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffServiceManagementController.php
- app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffServiceCategoryManagementController.php
- app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffSectionManagementController.php
- app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffLinkBlockManagementController.php
- app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffSiteManagementController.php
- app/Http/Controllers/Api/PublicSite/SiteVisibilityController.php
- app/Http/Resources/ (all files)
- routes/api/professional.php, routes/api/staff.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 4 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [x] **#RES-1** · P1 — Service and service-category controllers return raw Eloquent models on every write and read path
    - **Where:** app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalServiceCategoryController.php:37, 70, 85, 96; ProfessionalServiceController.php:134, 164, 208
    - **Affects:** API consumers of `/api/services`, `/api/services/{service}`, `/api/service-categories`, and their `restore` variants — any column added to `Service` or `ServiceCategory` tables auto-leaks into API responses. Also affects `ProfessionalCustomerController::restore()` which returns raw `$customer->fresh()` instead of `CustomerResource`.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create `ServiceResource` and `ServiceCategoryResource` classes with explicit field allowlists.
        - Replace all raw model returns in `ProfessionalServiceController` and `ProfessionalServiceCategoryController` (including `store`, `show`, `update`, `restore`) with the new Resources.
        - Fix `ProfessionalCustomerController::restore()` to wrap `$customer->fresh()` in `CustomerResource` — currently the only endpoint in that controller not using the Resource.
        - Mirror the same Resources into `StaffServiceManagementController` and `StaffServiceCategoryManagementController` (covered separately in RES-4 but the work is shared).
    - **Technical:** The codebase doctrine is "Resource classes for all API responses; never raw Eloquent." Every write path in these two controllers (`store`, `update`, `restore`) and both read paths (`index` via cache is safe — `getSiteLinkBlocks()` calls `->toArray()` — but `show` returns raw Eloquent) violate this. `ServiceCategory` exposes `deleted_at` directly; `Service` exposes `price_cents` and `currency_code` in whatever shape Eloquent serializes them. When a column is added — e.g. `internal_cost_cents` for margin tracking — it appears in every consumer's response with no gating. The `restore` gap in `ProfessionalCustomerController` is the same root cause: the method was added without the Resource wrap present in every other method of the same class.
    - **Plain English:** These endpoints hand customers the raw contents of the database table rather than a carefully selected view. If the development team later adds a field like "our internal cost" to track margins, it automatically shows up in every API response — no approval, no visibility gate. Resources are the explicit sign-off on what's safe to share.
    - **Evidence:**
        ```php
        // ProfessionalServiceCategoryController::index — raw Eloquent collection
        $categories = $q->orderBy('sort_order')->orderBy('created_at')->get();
        return $this->success([
            'categories' => $categories,
            'filters' => [...]
        ]);

        // ProfessionalServiceController::show — raw model directly
        return $this->success(['service' => $service]);

        // ProfessionalCustomerController::restore — raw model, no CustomerResource
        return $this->success(['restored' => true, 'customer' => $customer->fresh()]);
        ```

- [x] **#RES-2** · P1 — ProfessionalCustomerController renames `meta` to `pagination`, breaking the paginated-response contract
    - **Where:** app/Http/Controllers/Api/Professional/Customers/ProfessionalCustomerController.php:100-102
    - **Affects:** Every frontend consumer of `GET /api/customers` — expects `meta` (the codebase-wide paginated shape) but receives `pagination` instead. Staff mirror (`StaffCustomerManagementController`) uses the standard `meta` key correctly, meaning staff and professional consumers see different envelopes for the same logical resource.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the three lines that rename the key (`$payload['pagination'] = $payload['meta']; unset($payload['meta']);`).
        - Return `$this->success($payload)` directly after `paginatedResponse()` — this is how every other paginated controller in the codebase behaves.
        - Confirm whether any frontend code was written against `pagination` before removing; if so, use a short dual-key transition window (`$payload['pagination'] = $payload['meta']` without the `unset`).
    - **Technical:** `ReturnsPaginatedResponse::paginatedResponse()` emits `{ customers, meta: { current_page, per_page, total, last_page, ... } }`. This controller post-processes that result and renames `meta` to `pagination`, producing a shape that matches no other paginated endpoint. `ApiController::paginated()` also uses `meta`. The rename was likely cargo-culted from an old frontend contract and never removed; it now diverges from every other professional-side paginated list (customers, subscriptions, enquiries all use `meta`).
    - **Plain English:** Every list endpoint in this app delivers its "which page are you on" information in a standard labelled box called `meta`. This one endpoint tears off that label and writes `pagination` on it instead. Any frontend code that knows the standard label now gets nothing — it's looking for the wrong label.
    - **Evidence:**
        ```php
        $payload = $this->paginatedResponse($paginator, 'customers', [
            'filters' => [
                'include_archived' => $includeArchived,
                'only_archived' => $onlyArchived,
                'marketing_opt_in' => $marketingOptIn,
            ],
        ]);
        $payload['pagination'] = $payload['meta'];
        unset($payload['meta']);
        return $this->success($payload);
        ```

- [x] **#RES-3** · P1 — Link-block, site, and theme controllers return raw Eloquent models as API responses
    - **Where:** app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalLinkBlockController.php:148 (store), :200 (update); ProfessionalSiteController.php:30, 42, 108; ProfessionalThemeController.php:38; app/Http/Controllers/Api/PublicSite/SiteVisibilityController.php (update); app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffSiteManagementController.php (update)
    - **Affects:** Dashboard consumers of `/api/links` (writes), `/api/site`, `/api/site/visibility`, `/api/themes`. The `Site` model carries `settings` (JSONB) containing all design tokens — any internal key added there auto-leaks. The `Theme` model's `config` JSONB is also fully exposed.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create `LinkBlockResource`, `SiteResource`, and `ThemeResource` classes with explicit field allowlists.
        - `ProfessionalLinkBlockController::store()` and `update()` return `$linkBlock->fresh()` — wrap in `LinkBlockResource`. (Note: `index()` is safe — it reads from `SiteCacheService::getSiteLinkBlocks()` which already calls `->toArray()`.)
        - `ProfessionalSiteController::show()`, `update()`, and `visibility()` all do `$site->toArray()` — replace with `SiteResource`.
        - `SiteVisibilityController::update()` returns `$site->fresh()` raw — wrap in `SiteResource`.
        - `StaffSiteManagementController::update()` does `$site->toArray()` — same fix.
        - `ProfessionalThemeController::index()` returns raw collection — wrap in `ThemeResource::collection()`.
    - **Technical:** `ProfessionalSiteController::show()` and `update()` both call `$site->toArray()` which serializes every column, including `is_published`, `theme_id`, internal timestamps, and the full `settings` JSONB (which includes design tokens, booking mode, and Google Business Profile data). Resources provide an explicit allowlist so these internal fields require deliberate promotion to the wire format. `Theme::config` is a JSONB column with platform configuration — exposing it wholesale is premature. The `getSiteLinkBlocks()` cache path is safe as it already serializes to plain arrays via `->toArray()` at cache-fill time.
    - **Plain English:** These endpoints hand back the raw database row for the professional's entire site configuration, including internal switches and settings that were never intended to be client-visible. Adding any internal flag to the `Site` table — say, an A/B test bucket — means it silently appears in every dashboard response. Resources let the team control exactly what ships over the wire.
    - **Evidence:**
        ```php
        // ProfessionalSiteController::show — serializes every Site column
        $siteArray = $site->toArray();
        return $this->success(['site' => $siteArray]);

        // ProfessionalLinkBlockController::store — raw Eloquent model
        return $this->success(['block' => $linkBlock], 201);

        // ProfessionalThemeController::index — raw Eloquent collection
        $themes = Theme::query()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'key', 'name', 'description', 'config', 'is_default']);
        return $this->success(['themes' => $themes]);
        ```

- [x] **#RES-4** · P1 — Staff controller read paths return raw Eloquent models without Resource wrapping
    - **Where:** app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffCustomerManagementController.php:105; StaffServiceManagementController.php:94; StaffServiceCategoryManagementController.php:91; StaffSectionManagementController.php:28; StaffLinkBlockManagementController.php:27
    - **Affects:** Staff dashboard consumers of `GET /staff/professionals/{p}/customers/{c}`, `/services/{s}`, `/service-categories/{c}`, `/sections`, `/links`. Same silent-exposure risk as RES-1/RES-3 on the staff surface area.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap `show()` responses in the same Resources created for RES-1/RES-3 (`CustomerResource`, `ServiceResource`, `ServiceCategoryResource`).
        - `StaffSectionManagementController::index()` returns a raw `Block` collection — use the same `serializeSection()` approach used in `ProfessionalSectionBlockController`, or create a `SectionBlockResource`.
        - `StaffLinkBlockManagementController::index()` returns `$professional->linkBlocks()->orderBy('sort_order')->get()` raw — wrap in `LinkBlockResource::collection()`.
    - **Technical:** Staff controllers are meant to be elevated mirrors of professional-side controllers. Where professional-side controllers have Resource wrapping, staff mirrors should use the same (or a staff-specific variant with extra fields like `admin_notes`). The `StaffCustomerManagementController::show()` call is structurally identical to `ProfessionalCustomerController::show()` but the professional-side version uses `CustomerResource` while the staff version does not. This inconsistency means a future column added to `Customer` leaks to staff but not to the professional — an inversion of the intended access model.
    - **Plain English:** The staff tools have the same "raw kitchen window" problem as several professional endpoints. Staff see every database field whether it's relevant to their task or not. This is especially risky because staff views often include PII and internal notes — any new column added to a table automatically appears in staff dashboards without a deliberate decision to surface it.
    - **Evidence:**
        ```php
        // StaffCustomerManagementController::show — raw model
        return $this->success(['customer' => $customer]);

        // StaffServiceCategoryManagementController::show — raw model
        return $this->success(['category' => $category]);

        // StaffSectionManagementController::index — raw Block collection
        $sections = Block::query()
            ->where('professional_id', $professional->id)
            ->where('block_group', 'sections')
            ->orderBy('sort_order')
            ->get();
        return $this->success([
            'professional_id' => $professional->id,
            'sections' => $sections,
        ]);
        ```

---

## P2 — Should fix

- [ ] **#RES-5** · P2 — ID field type inconsistency across Resources: some cast to string, others emit raw UUID
    - **Where:** app/Http/Resources/EnquiryResource.php:18 (`(string) $this->id`); NotificationListingResource.php:24 (`(string) $this->id`); ProfessionalEmailSubscriptionResource.php:33 (`(string) $this->id`) vs. CustomerResource.php:15 (raw `$this->id`); FeatureFlagOverrideResource.php:13 (raw `$this->id`); ProfessionalDashboardResource.php:18 (raw `$this->id`)
    - **Affects:** Frontend developers who cannot assume `id` is always a string — type-checking and comparison logic must handle both forms.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Standardize all Resources to emit `'id' => (string) $this->id` and `'professional_id' => $this->professional_id !== null ? (string) $this->professional_id : null`.
        - Apply the same rule to all foreign-key UUID fields in Resources that currently omit the cast.
        - Consider a PHPStan rule or a base `ApiResource` class that enforces the cast pattern for new Resources created during RES-1 through RES-4 work.
    - **Technical:** When Eloquent models use `HasUuids`, the attribute may be stored as a `Ramsey\Uuid\UuidInterface` object or a string depending on the version and cast configuration. JSON serialization coerces both to strings in practice, but explicit `(string)` casting documents intent and prevents silent regressions if the cast configuration changes. The inconsistency means `EnquiryResource` guarantees `string` while `CustomerResource` does not — defensive frontend code that does `typeof id === 'string'` will find mixed results.
    - **Plain English:** Half the API endpoints label their record IDs as guaranteed strings; the other half just pass them through. Consistent labelling lets the frontend team write one set of rules for handling IDs instead of having to inspect each endpoint individually.
    - **Evidence:**
        ```php
        // EnquiryResource — string-cast (correct)
        'id' => (string) $this->id,

        // CustomerResource — raw, no cast (inconsistent)
        'id' => $this->id,
        'professional_id' => $this->professional_id,

        // NotificationListingResource — string-cast (correct)
        'id' => (string) $this->id,
        'professional_id' => $this->professional_id !== null ? (string) $this->professional_id : null,
        ```

- [ ] **#RES-6** · P2 — ProfessionalEnquiryController hand-builds its paginated envelope; ProfessionalGalleryController hand-builds its image payload
    - **Where:** app/Http/Controllers/Api/Professional/Customers/ProfessionalEnquiryController.php:33-42; ProfessionalGalleryController.php:54-70
    - **Affects:** Maintainability: adding a field to either response requires finding and editing this manual array instead of updating one Resource class. The gallery mapping is not a leakage risk (it's explicit), but it is a parallel maintenance surface.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - The enquiry controller correctly uses `EnquiryResource` per item but hand-builds the paginated envelope. Replace with `$this->paginatedResponse($page, 'data')` from `ReturnsPaginatedResponse`, which produces the standard `meta` shape already in use everywhere else.
        - Create a `GalleryImageResource` that encodes the `id`, `pool`, `alt_text`, `caption`, `sort_order`, `variants`, `created_at`, `updated_at` shape — this gives the gallery response a single authoritative definition instead of an inline `map()`.
    - **Technical:** `ProfessionalEnquiryController::index()` uses `EnquiryResource::collection($page->items())->toArray($request)` correctly for item serialization, then manually reconstructs the pagination envelope that `ReturnsPaginatedResponse::paginatedResponse()` would have produced automatically. This means the gallery and enquiry list responses have their own bespoke `meta` shapes that could drift from the project standard. `StaffEnquiryController::index()` has the same pattern and should be updated in sync.
    - **Plain English:** These two controllers are filling out packing slips by hand when there's a pre-printed template available. Adding a new field means tracking down the handwritten slip instead of updating the template. It's not broken today, but it becomes a source of bugs as the codebase grows.
    - **Evidence:**
        ```php
        // ProfessionalEnquiryController::index — manual envelope
        return $this->success([
            'data' => EnquiryResource::collection($page->items())->toArray($request),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
            ],
        ]);

        // ProfessionalGalleryController::index — manual field mapping
        $result = $images->map(fn (SiteMedia $img) => [
            'id' => $img->id,
            'pool' => $img->pool,
            'alt_text' => $img->alt_text,
            'caption' => $img->caption,
            'sort_order' => $img->sort_order,
            'variants' => $img->variantUrls(),
            'created_at' => $img->created_at,
            'updated_at' => $img->updated_at,
        ]);
        ```

- [x] **#RES-7** · P2 — `ProfessionalSectionBlockController::serializeSection()` uses `$section->toArray()` as its base, exposing all Block columns including internal fields
    - **Where:** app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalSectionBlockController.php (`serializeSection` method)
    - **Affects:** Consumers of `GET /api/sections` — the `publication_state` and `can_publish` overlays land on top of a full `->toArray()` serialization of the `Block` model, meaning every internal Block column (e.g. `professional_id`, raw `settings` JSONB, `is_enabled`) is included in the response.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `$section->toArray()` in `serializeSection()` with an explicit field array that allowlists only the columns the dashboard needs (id, block_type, block_group, sort_order, is_active, settings, is_enabled, plus the overlaid `publication_state`, `is_live`, `can_publish`, `requirement_reason`).
        - Apply the same fix to `StaffSectionManagementController::upsert()` which returns `$block->fresh()` without any serialization filtering.
    - **Technical:** `serializeSection()` starts with `$payload = $section->toArray()` and then merges in computed fields. This means the envelope includes `professional_id`, `site_id`, `deleted_at`, and any future internal Block column without any explicit approval. The method is the closest thing to a Resource for sections — it should become the explicit allowlist rather than a full-table dump with additions.
    - **Plain English:** The sections list starts by dumping the entire database row, then adds a few helpful labels on top. This means internal housekeeping fields — like which professional owns the block and whether it's been soft-deleted — appear in the dashboard response. A proper allowlist would make the team explicitly decide which fields consumers need to see.
    - **Evidence:**
        ```php
        private function serializeSection(Block $section, ?array $visibilityMap = null): array
        {
            $payload = $section->toArray();
            $isLive = (bool) ($section->is_active ?? false);
            $payload['publication_state'] = $isLive ? 'live' : 'draft';
            $payload['is_live'] = $isLive;

            if ($visibilityMap !== null) {
                $type = (string) $section->block_type;
                [$canPublish, $reason] = $visibilityMap[$type] ?? [true, null];
                $payload['can_publish'] = $canPublish;
                $payload['requirement_reason'] = $reason;
            }

            return $payload;
        }
        ```

---

## P3 — Nice to have

- [ ] **#RES-8** · P3 — `IndividualProfileResource` constructor accepts 9 positional `array` parameters — adding a section silently shifts argument order
    - **Where:** app/Http/Resources/PublicSite/IndividualProfileResource.php:57-70
    - **Affects:** Future developers adding a new public-profile section (e.g. `testimonials`). Inserting a parameter in the wrong position passes the wrong array silently — no type error since every parameter is `array`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the long positional constructor with a single `array $sections` parameter and update `IndividualProfilePayloadBuilder::build()` to pass a named associative array.
        - Alternatively, require named arguments at the single call site in `IndividualProfilePayloadBuilder::build()` using PHP 8 named-argument syntax.
        - At minimum, add a comment at the constructor documenting that the call site in `IndividualProfilePayloadBuilder` must use named arguments.
    - **Technical:** The constructor signature is `($resource, array $design, array $contentImages, array $gallery, array $links, array $bio, array $document, array $newsletter, array $services, array $booking)`. All parameters after `$resource` are `array` typed. PHP will not error if two arrays are swapped in positional order. The current single call site in `IndividualProfilePayloadBuilder::build()` is the only risk vector, but that class will grow as new sections are added (testimonials, experience, etc.) and the positional list will grow with it.
    - **Plain English:** This class is like a form with ten slots all labelled "array." If someone adds an eleventh slot in the middle, anyone filling out the form in order will put information in the wrong slot — the form accepts anything in each position and won't complain. Using named slots instead of position would catch any future mis-ordering.
    - **Evidence:**
        ```php
        public function __construct(
            $resource,
            private readonly array $design,
            private readonly array $contentImages,
            private readonly array $gallery,
            private readonly array $links,
            private readonly array $bio,
            private readonly array $document,
            private readonly array $newsletter,
            private readonly array $services,
            private readonly array $booking,
        ) {
            parent::__construct($resource);
        }
        ```
