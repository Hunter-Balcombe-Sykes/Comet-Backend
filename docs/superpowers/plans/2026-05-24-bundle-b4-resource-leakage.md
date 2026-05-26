# Bundle B4 — Raw Eloquent Leakage (Resource creation pass)

> **For agentic workers:** Steps use checkbox (`- [ ]`) syntax for tracking. Each Resource lands with its unit test in the same commit. Each commit's diff is small enough to review whole.

**Goal:** Close the raw-Eloquent-leakage gap in the site-builder half of the API by introducing 6 Resource classes and routing every read/write through them.

**Source:** `audits/foundation-audit-v1/audit-2026-05-24-CONSOLIDATED.md` Bundle B4, items #P1-05, #P1-06, #P1-07, #P1-08, #P2-35.

**Architecture:** Resources are flat field-allowlists (mirroring `CustomerResource`, `EnquiryResource`). One Resource per domain shape. The two Redis-backed cache builders that serve hot reads (`getDashboardServices`, `getSiteLinkBlocks`) are updated to emit Resource-shaped arrays so the Resource is authoritative on cached responses too.

**Decisions (locked, 2026-05-24):**
- `SiteResource.settings` → pass-through (cast to `(object)`). Tightening to a key-level allowlist is a follow-up audit task.
- `/api/professional/customers` pagination key → dual-key `meta` + `pagination` for one release cycle. TODO marker in code for removal.

---

## File Structure

### New files (12)

```
app/Http/Resources/
  ServiceResource.php
  ServiceCategoryResource.php
  LinkBlockResource.php
  SectionBlockResource.php
  SiteResource.php
  ThemeResource.php

tests/Unit/Resources/
  ServiceResourceTest.php
  ServiceCategoryResourceTest.php
  LinkBlockResourceTest.php
  SectionBlockResourceTest.php
  SiteResourceTest.php
  ThemeResourceTest.php
```

### Modified files (16)

```
app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalServiceController.php
app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalServiceCategoryController.php
app/Http/Controllers/Api/Professional/Customers/ProfessionalCustomerController.php
app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalLinkBlockController.php
app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalSiteController.php
app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalThemeController.php
app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalSectionBlockController.php
app/Http/Controllers/Api/PublicSite/SiteVisibilityController.php
app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffSiteManagementController.php
app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffCustomerManagementController.php
app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffServiceManagementController.php
app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffServiceCategoryManagementController.php
app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffSectionManagementController.php
app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffLinkBlockManagementController.php
app/Services/Cache/ProfessionalCacheService.php
app/Services/Cache/SiteCacheService.php
```

---

## Resource field allowlists

### ServiceResource

```
id (string), professional_id, category_id, title, description,
price_cents, currency_code, duration_minutes, is_active, sort_order,
created_at (iso8601), updated_at (iso8601), deleted_at (iso8601 nullable)
```

Excluded: legacy `category` text column, `deleted_origin`, future `internal_cost_cents`.

### ServiceCategoryResource

```
id (string), professional_id, title, sort_order,
created_at, updated_at, deleted_at
```

### LinkBlockResource (Block where block_group=`links`)

```
id (string), professional_id, site_id, block_type, block_group,
title, url, icon_key, sort_order, is_active, is_enabled, settings (object),
created_at, updated_at
```

### SectionBlockResource (Block where block_group=`sections`)

Constructor: `__construct($resource, ?array $visibility = null)` where `$visibility` is `[bool $canPublish, ?string $reason]`.

```
id (string), professional_id, site_id, block_type, block_group,
title, url, icon_key, sort_order, is_active, is_enabled, settings (object),
created_at, updated_at,
publication_state ('live'|'draft' computed from is_active),
is_live (bool of is_active),
+ can_publish, requirement_reason (only when visibility supplied)
```

`url` and `icon_key` are always null for sections — kept for backward-compat shape.

### SiteResource

```
id (string), professional_id, subdomain, theme_id, is_published,
subdomain_changed_at, unpublished_at, settings (object passthrough),
created_at, updated_at
```

### ThemeResource

```
id (string), key, name, description, config (object), is_default
```

No timestamps — themes are admin-managed.

---

## Tasks

### Task 1: Build Resources + unit tests

**Files:** all 6 Resource files + 6 unit tests above.

- [ ] **Step 1:** Write each Resource (flat `toArray()` returning the allowlist above).
- [ ] **Step 2:** Write each unit test. Each test must hydrate a model with an extra column and assert the Resource output does NOT include it. Also assert `id` is a string.
- [ ] **Step 3:** Run `vendor/bin/pest tests/Unit/Resources/ -v` — all 6 tests pass.
- [ ] **Step 4:** Commit: `feat(B4): add 6 Resource classes for site-builder endpoints`.

### Task 2: Wire Professional service/category controllers + customer endpoint

**Files:**
- `app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalServiceController.php` — wrap `index` (services + categoryPayload paths), `store`, `show`, `update`, `restore`.
- `app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalServiceCategoryController.php` — wrap `index`, `store`, `show`, `update`, `restore`.
- `app/Http/Controllers/Api/Professional/Customers/ProfessionalCustomerController.php` — `index`: wrap collection in `CustomerResource::collection` AND emit dual-key `meta` + `pagination`; `restore`: switch to `CustomerResource`.

- [ ] **Step 1:** Edit each controller.
- [ ] **Step 2:** Run `vendor/bin/pest tests/Feature/Professional --filter=Service` and customer tests.
- [ ] **Step 3:** Commit: `fix(B4): wrap Professional service + customer responses in Resources (P1-05, P1-06)`.

### Task 3: Wire Professional site/theme/link/section + visibility

**Files:**
- `app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalSiteController.php` — `show`, `update`, `visibility` use `SiteResource`.
- `app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalThemeController.php` — `index` → `ThemeResource::collection`; `select` returns a Site → `SiteResource`.
- `app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalLinkBlockController.php` — `store`, `update` use `LinkBlockResource`. `index` is cache-backed (touched in Task 5).
- `app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalSectionBlockController.php` — `index` rebuild map with `SectionBlockResource` + per-element visibility; `upsert`, `remove` use `SectionBlockResource`. Delete the private `serializeSection` method.
- `app/Http/Controllers/Api/PublicSite/SiteVisibilityController.php` — `update` returns `SiteResource`.

- [ ] **Step 1:** Edit each controller.
- [ ] **Step 2:** Run `vendor/bin/pest tests/Feature/Security/TenantIsolation tests/Feature/Security/PolicyEnforcement`.
- [ ] **Step 3:** Commit: `fix(B4): wrap Professional site/theme/link/section responses (P1-07, P2-35)`.

### Task 4: Wire Staff controllers

**Files:**
- `StaffSiteManagementController` — `update` → `SiteResource`.
- `StaffCustomerManagementController` — `index` (wrap), `show`, `update`, `restore` → `CustomerResource`.
- `StaffServiceManagementController` — `index` (services + categoryPayload), `store`, `show`, `update`, `restore` → `ServiceResource` / `ServiceCategoryResource`.
- `StaffServiceCategoryManagementController` — `index`, `store`, `show`, `update`, `restore` → `ServiceCategoryResource`.
- `StaffSectionManagementController` — `index`, `upsert` → `SectionBlockResource` (no visibility passed; staff doesn't need the gate).
- `StaffLinkBlockManagementController` — `index`, `store`, `update` → `LinkBlockResource`.

- [ ] **Step 1:** Edit each controller.
- [ ] **Step 2:** Run staff feature tests.
- [ ] **Step 3:** Commit: `fix(B4): wrap Staff site-builder responses in Resources (P1-08)`.

### Task 5: Update cache builders

**Files:**
- `app/Services/Cache/ProfessionalCacheService.php` — `getDashboardServices`: replace `->get()->toArray()` with `Service::query()->...->get()->map(fn ($s) => (new ServiceResource($s))->resolve())->all()`.
- `app/Services/Cache/SiteCacheService.php` — `getSiteLinkBlocks`: same pattern with `LinkBlockResource`.

Cache invalidation: existing observers (`ServiceObserver`, `BlockObserver`) already bust these keys on writes. No flush needed at deploy time — stale entries expire within their TTL.

- [ ] **Step 1:** Edit each cache method.
- [ ] **Step 2:** Run cache-touching tests: `vendor/bin/pest tests/Feature/Professional --filter=Service` and link tests.
- [ ] **Step 3:** Commit: `fix(B4): cache builders emit Resource shape so reads can't bypass the allowlist`.

### Task 6: Full test sweep + summarise

- [ ] **Step 1:** Run `composer test` end-to-end.
- [ ] **Step 2:** If any failure: stop and re-plan, don't paper over.
- [ ] **Step 3:** Report diff summary: files touched, line counts, frontend follow-ups (the pagination dual-key TODO).

---

## Frontend handoff note

When PR is ready, message frontend dev:

> Bundle B4 wraps service / customer / link / section / site / theme responses in Resource classes. Field shapes are unchanged from today's `toArray()` output **except**:
> - `/api/professional/customers` now emits BOTH `meta` and `pagination` (identical content). Please migrate reads from `pagination` → `meta` at your convenience. We'll drop `pagination` in the next release.
> - All IDs are now string-cast (UUIDs were always strings, but Resource enforces it explicitly).
> - All timestamps are ISO-8601 (`Carbon::toIso8601String()`). Previously some surfaces emitted raw Carbon strings; the dashboard mostly already accepts ISO-8601.

---

## Self-review checklist

- [ ] Every Where-line file from the audit appears in Modified files above
- [ ] Every Resource has a unit test that exercises the "extra column shouldn't ship" path
- [ ] Cache builders updated (the silent bypass otherwise)
- [ ] Pagination dual-key in place with TODO marker
- [ ] No frontend-breaking shape changes beyond the documented ones
