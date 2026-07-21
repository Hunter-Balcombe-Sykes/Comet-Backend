# Menu & Services Multi-Category Implementation Plan

> Backend is implemented in this session on branch `feat/menu-services-multicategory`.
> The **Frontend** section at the end is a contract for the other chat — DO NOT implement it here until the user gives the go-ahead.

**Goal:** Menu items and services can each belong to multiple categories (scanned/synced once, attached everywhere), Fresha services become editable rows with menu-style detach/revert/resync semantics and serviceId dedup, plus the supporting API surface the new dashboard UI needs.

**Architecture:** Two parallel M2M conversions sharing one pattern. Menu: replace `menu_items.category_id` with a `site.menu_item_categories` pivot (position lives on the pivot), dedupe by normalized name in every writer, backfill-merge existing duplicate rows. Services: project Fresha's payload blob into `site.services` rows (`source='fresha'`, `external_id=serviceId`, deduped), guard owner edits with `is_manual`, write the *effective* services list back into the public blob so partna-pages needs no changes, and convert `services.category_id` to a `site.service_category_assignments` pivot.

**Tech stack:** Laravel 12, Postgres (supabase SQL migrations), Pest with SQLite schema mirrors, TanStack Table v8 on the frontend.

## Global constraints

- Fresha's public CDN payload contract is `payload.{url, selection}` shipped verbatim (`PublicIntegrationConnectionResource` allowlist `'fresha' => ['url','selection']`, [PublicIntegrationConnectionResource.php:105](../../app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php)). Anything private must live at other top-level payload keys (they are filtered out) — we add `payload.raw`.
- `site.services` is a LIVE manual-services feature (dashboard CRUD + public "services" section via `SitepageDataResolverService::buildServicesData()`). Existing manual rows have `source NULL`; nothing public may change for them.
- Fresha projections must NOT leak into the public services section: `buildServicesData()` and the visibility rules gain `whereNull('source')`.
- The scrape→blob pipeline (`FreshaScraper`, `FreshaFetch` timing/etag behavior) keeps its shape; dedup + projection graft onto it.
- `services_professional_sort_order_uq` is a partial unique on `(user_id, sort_order) WHERE deleted_at IS NULL` — any bulk sort_order writes need two-pass temp-parking.
- Tests: every touched behavior gets Pest coverage; SQLite mirror helpers must mirror new/changed DDL.
- No frontend files are modified in this session.

---

## Part 1 — Menu items in multiple categories (backend)

### Current state (grounded)

- `menu_items.category_id uuid NOT NULL REFERENCES menu_categories ON DELETE CASCADE` ([20260619050000:59](../../supabase/migrations/20260619050000_menu_relational_redesign.sql)); `position` orders items within their category.
- A dish under N categories is **N duplicate rows today**: `MenuMerger` builds each category independently (no cross-category dedup), `MenuFetchJob::persist()` keys identity on `(category_id, normalized name)`, `MenuScanApplier` creates one row per (name, category) miss.
- `is_manual` rows survive rebuilds; `menus.suppressed_items` is `[{category, name}]`.

### Target semantics

- **One normalized name = one item** within a menu (among non-manual rows). Categories are memberships on the `site.menu_item_categories` pivot with a per-membership `position`.
- Scan/scrape writers: on seeing a name already materialized, merge data and **attach the category** instead of creating a row.
- Deleting an item deletes it from all categories (+ name-keyed suppression if scraped). Removing from one category = updating its category set. An item must always have ≥1 category.
- Deleting a category detaches members; items left with zero categories are deleted (suppressed by name when scraped).
- `suppressed_items` matching loosens to **name-only** (an item now spans categories; old `{category,name}` records still match by their `name`).

### Task M1 — Migration: pivot + backfill + dedup + drop `category_id`

File: `supabase/migrations/20260721??????_menu_item_multi_category.sql` (follow `CONVENTIONS.md`).

1. Create `site.menu_item_categories`:
   - `menu_item_id uuid NOT NULL REFERENCES site.menu_items(id) ON DELETE CASCADE`
   - `menu_category_id uuid NOT NULL REFERENCES site.menu_categories(id) ON DELETE CASCADE`
   - `position integer NOT NULL DEFAULT 0`
   - `PRIMARY KEY (menu_item_id, menu_category_id)`; index `(menu_category_id, position)`.
   - RLS: enable + force + `app_backend` ALL policy (mirror `menu_items_app_backend_all` from `20260702000000` / `20260710140000` pattern).
2. Backfill pivot from every existing row's `(category_id, position)`.
3. Dedup pass (SQL, non-manual rows only): canonical = earliest `(created_at, id)` per `(menu_id, lower(trim(name)))`; repoint dupes' pivot rows and `menu_item_platforms` rows to the canonical (`ON CONFLICT DO NOTHING` / skip when canonical already has that platform); `COALESCE`-fill canonical's NULL scalars (description, image_url, prices, rating, badges) from dupes; delete dupe rows.
4. Drop `menu_items.category_id` (and `idx_menu_items_category`).

### Task M2 — Models

- `MenuItem::categories()` → `belongsToMany(MenuCategory, 'site.menu_item_categories', 'menu_item_id', 'menu_category_id')->withPivot('position')`; remove `category_id` from `$fillable`, remove `category()`.
- `MenuCategory::items()` → `belongsToMany(MenuItem, ...)->withPivot('position')->orderByPivot('position')`.
- `Menu::items()` (menu_id-keyed flat read) stays.

### Task M3 — `MenuFetchJob::persist()` rewrite

- Identity reuse keyed by normalized name **menu-wide** (was `(category_id, name)`).
- First occurrence of a name creates/reuses the row; later occurrences in other merged categories only attach pivot `(category, position)`.
- Manual collision: scraped name matching a surviving manual item's name → skip entirely (manual wins menu-wide now).
- `suppressed_items` consulted by name only.
- Deletable-rows selection, `clearScrapedContent()`, and item-platform child handling updated for pivot.
- `MenuMerger` stays untouched (pure array transform; dedup happens at persist).

### Task M4 — `MenuScanApplier` rewrite

- Match by normalized name against all items (manual match → skip, as today). The (name, category) disambiguation machinery collapses.
- Matched: update fields per mode **and attach** the scan's category (find-or-create scan-sourced category) when not already a member.
- Miss: create item + attach.
- `resolveMatch()` ambiguity branch removed (post-backfill invariant: ≤1 non-manual candidate per name).

### Task M5 — `MenuContentController`

- `createItem`: accept `category_ids: uuid[]` (plus legacy `category_id` for compat); default = manual `'Menu'` category; attach all; `is_manual=true`.
- `updateItem`: accept `category_ids` to reset memberships (min 1); editing a scraped dish still flips `is_manual`.
- `deleteItem`: delete + detach; scraped → suppress by name.
- `deleteCategory`: detach members; delete now-orphaned items (suppress scraped orphans by name).
- New `POST {base}/menu/items/bulk-delete {ids: uuid[]}` for the table bulk actions (same per-item rules as deleteItem).

### Task M6 — Read paths

- `MenuPayloadComposer`: eager-load pivot; each item additionally emits `categoryIds: string[]` so the flat dashboard table can dedupe/render memberships; nesting stays.
- `PublicMenuController`: same eager-load conversion; per-category duplication in output is now *correct* (one row, many memberships).
- `hasOwnerContent()` and any `where('category_id', …)` reads converted to `whereHas('categories', …)` / pivot joins.

### Task M7 — Tests + SQLite mirrors

- Update the menu table setup helpers (SQLite mirror DDL) for the pivot + dropped column; sweep every raw insert supplying `category_id`.
- New coverage: scan of an item in 3 categories creates 1 row + 3 memberships; rebuild preserves memberships; backfill-style dedup semantics; bulk-delete; orphan-on-category-delete; suppression by name.
- Suites to keep green: `MenuTest`, `ManualMenuContentTest`, `PublicMenuControllerTest`, `MenuScanApplierSourceTest`, `GoogleMenuPhotoScanTest`, `WebsiteMenuPdfScanJobTest`, `ScanPreviousWebsiteContentJobTest`, `MenuFetchJobWebsiteScanProtectionTest`, `PlatformAndMenuRlsTest`.

---

## Part 2 — Booking & services rework (backend)

### Current state (grounded)

- Fresha lives at `platform_connections.payload = {url, selection}`, `selection = {url, storeName, mode, employee, services[], hiddenServiceIds[]}`; `services[]` entries `{serviceId, name, duration, description, price, priceValue, currency, category, hasVariants}`; wholesale replace on connect/saveSelection/refresh; **zero dedup** (same serviceId under 2 Fresha categories = 2 entries — the duplicate bug).
- `site.services` + `site.service_categories`: live manual feature; single nullable `category_id` (composite FK, SET NULL), global per-user `sort_order` with partial unique; `deleted_origin varchar(16)` exists unwritten; both tables soft-delete.

### Target semantics (Architecture A + blob writeback)

- Every effective Fresha service is a `site.services` row: `source='fresha'`, `external_id=serviceId`, deduped by serviceId (first occurrence wins; category labels **unioned** into memberships).
- Fresha category labels find-or-create `service_categories` rows (`source='fresha'`, matched per user by lower(title); trashed row with that title = user deleted it → don't resurrect, leave services uncategorized there).
- Owner edits a synced row → `is_manual=true` (detached; sync no longer overwrites it). Revert (single or bulk) → `is_manual=false` + re-project from raw.
- Owner deletes a synced row → soft delete with `deleted_origin='user'` = suppression (sync skips resurrecting). Service disappears from Fresha → soft delete `deleted_origin='sync'` (auto-restores if it returns).
- Manual services (source NULL) are untouched by sync; users can add services/categories with or without a Fresha connection (existing CRUD).
- **Blob writeback:** after every sync/edit/revert/delete, recompose `payload.selection.services` from the effective projections (verbatim raw entry for synced rows; serialized projection for detached rows) and `hiddenServiceIds` from inactive projections — so partna-pages and the public CDN payload pick up dedup + edits with zero pages changes. Raw scrape persists privately at `payload.raw` (filtered out by the public allowlist).
- Public services section unchanged: `buildServicesData()` + visibility rules add `whereNull('source')`.

### Task S1 — Migration: service provenance + pivot + backfill

File: `supabase/migrations/20260721??????_services_multicategory_fresha_projection.sql`.

1. `site.services`: add `source text NULL CHECK (source IN ('fresha'))`, `is_manual boolean NOT NULL DEFAULT false`, `external_id text NULL`; partial unique `(user_id, external_id) WHERE source = 'fresha' AND deleted_at IS NULL`.
2. `site.service_categories`: add `source text NULL CHECK (source IN ('fresha'))`.
3. Create `site.service_category_assignments`: `(service_id uuid FK CASCADE, service_category_id uuid FK CASCADE, PRIMARY KEY (service_id, service_category_id))` + index on `service_category_id` + RLS parity.
4. Backfill pivot from `services.category_id`; drop `category_id` (and its composite FK).

### Task S2 — Models

- `Service`: add `source/is_manual/external_id` to fillable+casts; `categories()` belongsToMany via the pivot; drop `category()`.
- `ServiceCategory`: `services()` becomes belongsToMany; add `source`.

### Task S3 — `FreshaProjector` service (new, `app/Services/Platforms/FreshaProjector.php`)

- `project(User, array $rawServices): array` — dedup by serviceId; parse `priceValue`→`price_cents`, `currency`→`currency_code`, duration display string→`duration_minutes`; upsert non-detached projections; union category memberships; suppression + departed handling as above; returns effective blob `services[]` + `hiddenServiceIds`.
- Serialization back to blob entry shape for detached rows (format `price`/`duration` display strings; category label = first membership title).
- Ground exact display formats against `FreshaPayloadTest` fixtures before writing the formatter.

### Task S4 — Wire projection into the Fresha pipeline

- `FreshaController::connect` / `saveSelection`: after computing the scraped list, dedup + project + store `payload = {url, selection(effective), raw}`.
- `FreshaFetch::fetch`: same on refresh; not-modified path unchanged. Hidden/visibility curation endpoint writes through projections (`is_active`) and recomposes `hiddenServiceIds`.
- Disconnect: decide + implement (soft-delete `deleted_origin='sync'` for all fresha rows, so reconnect restores).

### Task S5 — Owner CRUD guards + revert/resync endpoints

- `UserServiceController::update`: editing a `source='fresha'` row sets `is_manual=true`.
- `destroy`: synced row → `deleted_origin='user'`.
- `updateCategory` endpoint: accept `category_ids: uuid[]` (multi), keep path.
- New: `POST /services/{service}/resync` (revert one) and `POST /services/resync` (bulk: explicit `ids[]` or all detached) — re-project from `payload.raw`, clear `is_manual`, recompose blob.
- `PurgeSoftDeleted`: exclude `source='fresha' AND deleted_origin='user'` rows (suppressions must not expire).

### Task S6 — Read-path guards + tests

- `buildServicesData()`, `ServicesVisibility`, `BookingVisibility`, `UserCacheService::getActiveServices/getDashboardServices` (dashboard SHOULD include fresha rows — only the public/manual paths filter), `presentPageIds()`: add the `source` filter where public-facing.
- Tests: dedup (same serviceId under 2 categories → 1 row, 2 memberships, blob has 1 entry), detach-on-edit, revert single/bulk, delete-suppression across syncs, departed/returned service, blob writeback shape (public payload contract golden-master must stay green: `FreshaPayloadTest`, `GoldenMaster/IntegrationContractGoldenMasterTest`), sort_order two-pass safety, RLS on the new pivot, `whereNull('source')` public filters.

---

## Part 3 — Frontend contract (DEFERRED — other chat implements after go-ahead)

**Repo:** `/Users/tobiasbalcombeehrlich/Developer/Partna-Frontend`. Everything below is spec, not implementation.

### F1 — Data-table pagination bug
`components/.../data-table-pagination.tsx:37,46` reads `table.getState().pagination.pageIndex` for "Page x of x" but the footer doesn't re-render on page change in server-mode consumers (client-mode menu/booking tables work; 19/19 existing tests pass). Reproduce on an affected table, fix the state feed (likely a consumer not passing `state.pagination` + `onPaginationChange` back into `useReactTable`, or the separate `StaffPagination` footer), add a test asserting the label updates across page changes.

### F2 — Menu: filter by categories
Next to the existing sort control: a Filter popover (multi-select of the menu's categories, from `categories[].{id,name}` already in the menu payload). Filters the flat items view by `item.categoryIds` (new backend field). Badge with active-filter count; clearable.

### F3 — Menu: categories table rework
Categories view becomes a real DataTable: bulk-selectable category rows; each row expandable (chevron) showing its member items as selectable sub-rows (use TanStack `getExpandedRowModel` + `row.subRows`; selection state must aggregate — selecting a category selects its items; indeterminate states). Bulk actions: delete (use new `POST …/menu/items/bulk-delete` for items; loop existing category DELETE for categories). Items shown under a category come from the nested `categories[].items` payload; an item in multiple categories appears under each but shares its `id` — bulk-delete sends unique ids.

### F4 — Booking & services page
- Rename nav/page "Booking" → "Booking & services".
- Services listed like menu items (DataTable): editable rows (PATCH `/services/{id}`), category multi-select (PATCH `/services/{id}/category` with `category_ids[]`), add service/category (existing POST endpoints), active toggle.
- A `source='fresha' && is_manual` row shows a "sync broken" warning chip + revert button (`POST /services/{id}/resync`) with confirm popup; bulk "Resync selected/all" (`POST /services/resync`).
- Filter-by-categories control identical to F2.
- Fresha-connected and non-connected states both support manual add.

### F5 — Menu item multi-category editing
Item edit dialog: category multi-select (submit `category_ids[]`), min 1 enforced client-side.

---

## Verification

- Backend: `php artisan test` full suite green (baseline 4583 passed); targeted new tests listed per task; `vendor/bin/pint --dirty`.
- Live-risk review before merge: blob writeback output diffed against a captured real Fresha payload; public golden-master tests unchanged.

## Out of scope

- partna-pages changes (blob contract preserved instead).
- Google Business changes; menu drag-reordering; per-category service ordering (`sort_order` stays user-global).
- Any frontend file edits (Part 3) until user go-ahead.
