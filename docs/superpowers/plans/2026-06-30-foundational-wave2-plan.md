# Foundational audit — Wave 2 batch plan (gated)

**Branch:** `audit-fix/foundational-2026-06-30` · **Audit:** `audits/sweeps/2026-06-25-foundational/CONSOLIDATED.md`
**Status:** PLAN ONLY — no code lands until Josh signs off (per item or per group).
**Date:** 2026-06-30

## How to read this
Every Wave-2 finding is gated (DB migration / auth / L-XL / standalone). Each section below gives:
premise verdict (vs **current** code, since the audit moved) · migration SQL + rollback · code changes · blast radius · test plan · effort/ordering.

**Standing constraints (apply to all):**
- DB schema = raw SQL in `supabase/migrations/` only (Laravel-migration guard). Apply to **dev Supabase `glncumufgaqcmqhzwrxm`** (serves prod traffic too — so every migration is a real, gated step).
- **Pre-beta, zero rows** → every backfill is a no-op *now*, a locking data-rewrite *later*. This is the argument to do the JSONB→table conversions now.
- **SQLite tests do NOT enforce Postgres CHECK / NOT NULL / partial-unique / FK-cascade.** A constraint added in a migration passes CI green and only bites on real PG. Each migration must update `tests/Pest.php`'s in-memory schema AND be verified against the real DDL.
- Resource classes for responses; logic in Services/; Policies for authz.

**Recommended global sequence:** P1 first (FOUND-2,3,4,5), then P2 (6,10+14,15,16,12,18,19), then P3 (21). Several have intra-dependencies noted per section.

---

## GROUP A — Menu subsystem (FOUND-2, FOUND-6) · one PR, two migration files

### FOUND-2 (P1, L) — `site.menus` per-platform column-triple → `site.menu_platform_links`
**Premise: HOLDS.** The 6 columns (`uber_eats_store_url/synced_at/status`, `doordash_store_url/synced_at/status`) live on `site.menus` from `20260619050000_menu_relational_redesign.sql:34-41` (not baseline). `uber_eats_status`/`doordash_status` carry a CHECK IN ('pending','ok','unavailable').

Consumers (complete grep): `Menu.php:45-50/58-59` (fillable/casts); `MenuFetchJob.php` skip `:90-91`, write `:105-106`, status `:127-132`, `platformSettled` `:162`; **`RetryUnavailableMenusCommand.php:31-32` (consumer the audit missed)**; tests `tests/Pest.php:450-455`, `MenuTest.php` (many). `MenuController` does NOT read them.

**Migration** (`…_menu_platform_links.sql`): `CREATE TABLE site.menu_platform_links (id uuid PK, menu_id uuid NOT NULL REFERENCES site.menus(id) ON DELETE CASCADE, platform text NOT NULL CHECK IN ('uber-eats','doordash'), store_url text, synced_at timestamptz, status text CHECK IN ('pending','ok','unavailable'), created_at, updated_at, UNIQUE(menu_id,platform))` + index on menu_id. Backfill = 2 `INSERT…SELECT` (one per platform, gated on store_url NOT NULL). Then `ALTER TABLE site.menus DROP COLUMN` the 6. Default-privilege grant auto-covers the new table (baseline `:2303`); no RLS (matches `menu_items`).
**Rollback:** re-ADD the 6 columns + CHECKs, `UPDATE…FROM menu_platform_links` pivot back, `DROP TABLE`.
**SQLite:** `tests/Pest.php` needs a `menu_platform_links` TEXT-typed table + removal of the 6 columns from the `site.menus` block (`:450-455`). UNIQUE/CHECK/FK-cascade unenforced there — verify vs real DDL.

**Code:** new model `MenuPlatformLink` (`belongsTo Menu`); `Menu` drops the 6 fillable/casts, adds `platformLinks() hasMany`; `MenuFetchJob` rewrite — skip/status/settled read from `$menu->platformLinks->keyBy('platform')`, writes via `updateOrCreate(['menu_id','platform'], …)`; `RetryUnavailableMenusCommand` → `whereHas('platformLinks', fn($q)=>$q->where('status','unavailable'))`.
**Blast radius:** Menu.php, MenuFetchJob.php, RetryUnavailableMenusCommand.php, new model, tests/Pest.php, MenuTest.php. Missing a consumer → "column does not exist" fatal on next scrape / the 15-min `menu:retry-unavailable` schedule.
**Tests (golden master):** MenuTest "skips paid scrape" `:207`, "re-scrapes when unavailable" `:224`, "menu:retry-unavailable" `:387`, "scrapes and stores" `:182` — all move to seeding/asserting `platformLinks` rows.

### FOUND-6 (P2, M) — `menu_items.platforms` JSONB → `site.menu_item_platforms`
**Premise: CHANGED — the audit's child schema is STALE.** The migration *comment* says `{platform, price, modes, url}`, but the live writer (`MenuMerger::platformEntry` `:205-218`) and reader (`MenuController::platforms()` `:149-181`) use a **per-mode** shape: `{platform, pickupPrice, pickupUrl, deliveryPrice, deliveryUrl}` — TWO prices, TWO urls per platform. The audit's `(platform, price NUMERIC, modes JSONB, order_url TEXT)` **cannot represent this without loss.**
➡️ **Recommended faithful schema:** `(menu_item_id, platform, pickup_price NUMERIC(10,2), pickup_url TEXT, delivery_price NUMERIC(10,2), delivery_url TEXT, UNIQUE(menu_item_id,platform))`. The `{price,modes,url}` branch in `MenuController` is dead legacy back-compat — drop it (no legacy rows pre-beta). **Reviewer decision needed:** faithful per-mode schema (recommended) vs the audit's lossy `(price,modes,url)`.

Consumers of `->platforms`: `MenuController.php:132,151`; `MenuItem.php:46,52`; `MenuFetchJob.php:217` (write). `PublicMenuController` does NOT read it.
**Migration** (`…_menu_item_platforms.sql`): CREATE child table (per-mode cols) + index; backfill via `jsonb_to_recordset(mi.platforms) AS p(platform text, "pickupPrice" numeric, "pickupUrl" text, "deliveryPrice" numeric, "deliveryUrl" text)` (camelCase keys quoted); `ALTER TABLE site.menu_items DROP COLUMN platforms`. **Rollback:** ADD COLUMN + `jsonb_agg(jsonb_build_object(...))` rebuild + DROP TABLE.
**SQLite:** add `menu_item_platforms` table to `tests/Pest.php`, remove `platforms` from `menu_items` block (`:489`). `jsonb_to_recordset`/`LATERAL` absent in SQLite (backfill never runs there).
**Code:** new model `MenuItemPlatform`; `MenuItem` drops `platforms` fillable/cast, adds `platformLinks() hasMany`; `MenuFetchJob::persist` (`:173-229`) — drop the `'platforms'=>json_encode` field, bulk-insert child rows keyed to the pre-generated item id; `MenuController::platforms()` maps `$item->platformLinks` into the existing `{platform,pickupPrice,…}` output, eager-load `categories.items.platformLinks`. `MenuMerger` unchanged (still returns the in-memory array).
**Tests (golden master):** MenuTest "unions both platforms…" `:419-466`, "returns full menu with per-mode prices" `:328-365`, and the `seedMenu` helper `:83-110/338-340` (switch inline `platforms` array → child rows). Backfill fidelity untestable on SQLite (no `jsonb_to_recordset`) — verify vs real PG.

### Group-A deploy guidance
- **One PR** (MenuFetchJob is the single hinge writing both child tables), **two migration files** (granular rollback). Do FOUND-2 first within the PR, then FOUND-6.
- **Expand/contract recommended** even pre-beta (dev DB serves prod): (1) CREATE+backfill only, defer DROP COLUMN; (2) deploy code reading new tables; (3) DROP COLUMN. Or, accepting pre-beta zero-row risk, collapse to create+backfill+drop in one window — but **drain the `scraping` queue first** (an in-flight old worker hits the dropped columns).
- **Effort:** FOUND-2 = L, FOUND-6 = M.

---

## GROUP B — User-profile JSONB → tables (FOUND-4, FOUND-5) · share `SectionVisibilityService` + `SitepageDataResolverService`

### FOUND-4 (P1, L) — Workplace card → `site.workplaces` (1:1 with sites)
**Premise: HOLDS.** 14 named fields at `site.sites.settings['workplace']` (`UpsertWorkplaceRequest:37-57`, `UserWorkplaceController:45-66`). Visibility uses pgsql-only `settings->'workplace'->>'name'` in WHERE (`SectionVisibilityService:228-237` + legacy `:680-699`). Resolver `SitepageDataResolverService::getWorkplace:411-441` emits 11 keys.
**Extra consumers the audit missed (blast-radius critical):** `MenuSource::address():240-248` (DoorDash locale reads settings.workplace), `GoogleBusinessAutoSync::seedWorkplace():300-321` (read-modify-write), `UserWorkplaceController::show/setPreviousWebsite:112-144`, `IndividualProfilePayloadBuilder::buildWorkplace:138-162`.

**Migration** (`…_create_workplaces.sql`): `CREATE TABLE site.workplaces (site_id uuid PK REFERENCES site.sites(id) ON DELETE CASCADE, name text NOT NULL, address, address_line1, city, state, postcode, country, latitude double precision, longitude double precision, phone, website, previous_website, category, description, created_at, updated_at)`. Backfill `INSERT…SELECT` from `settings->'workplace'` gated on non-empty name (`NULLIF(btrim(…),'')` per field, `::double precision` for lat/lng), then `UPDATE site.sites SET settings = settings - 'workplace'`. **Rollback:** `jsonb_set` rebuild + `DROP TABLE`.
**Code:** new `Workplace` model (PK=site_id, no HasUuids) + `Site::workplace() hasOne`; `SectionVisibilityService` `has_workplace` subquery → portable `Workplace::where('site_id',?)->whereNotNull('name')->where('name','<>','')` (no whereRaw); resolver reads the relation (same 11-key output); `UserWorkplaceController`/`StaffWorkplaceController` → `Workplace::updateOrCreate`; `MenuSource::address()` + `GoogleBusinessAutoSync::seedWorkplace()` rewired. **Wire shape unchanged.**
**⚠ Decision to flag:** today visibility is name **OR** address; target is **name-only**. This is actually a *consistency fix* (the resolver already returns null without a name), but it's a behavior change — sign off explicitly.
**Tests:** new `setupWorkplacesTable()` in `tests/Pest.php`; golden-master the public `workplace` envelope. **Net win:** the new column predicate runs on SQLite, so workplace can finally join `BatchCheckQueryCountTest` (currently excludes it for being pgsql-only).

### FOUND-5 (P1, L) — `core.users.about` credentials/experience arrays → child tables
**Premise: HOLDS.** Shapes (`ValidatesUserAbout:23-35`): credentials `{title(req,max120), issuer, year(int 1900..)}` (max 5, no description in validation but resolver reads one); experience `{role(req), place, start(Y-m), end(Y-m|null), description(max1000)}` (max 5). Visibility uses pgsql-only `jsonb_array_length`/`jsonb_array_elements` in WHERE, **two places each** (`:190-195`+`:523-528` credentials; `:199-208`+`:536-541` experience). Resolver `getBio:371-401`→`normaliseCredential:454-474`/`normaliseExperience:476-516` (synthesises `period` = `"{start} – {end|Current}"`).

**Migration** (`…_create_user_credentials_experience.sql`): two tables `core.user_credentials (id uuid PK, user_id uuid FK CASCADE, title text NOT NULL, issuer, year text, description, sort_order int DEFAULT 0, ts)` + `core.user_experience (…, role text NOT NULL, organisation, start_year text, end_year text, description, sort_order, ts)`, index `(user_id, sort_order)` each. Backfill via `jsonb_to_recordset(…) WITH ORDINALITY` (ord-1 → sort_order), then `UPDATE core.users SET about = (about - 'credentials') - 'experience'`. **Rollback:** `jsonb_agg(jsonb_build_object(…))` rebuild + DROP.
**⚠ Naming flag:** `start_year`/`end_year` actually hold `Y-m` month strings (`"2021-03"`), not years — keep `text`, consider renaming `start_month`/`start_period` or document.
**Code:** new `UserCredential`/`UserExperience` models + `User::credentials()/experience() hasMany orderBy(sort_order)`; replace the **4** pgsql blocks in `SectionVisibilityService` with portable `EXISTS` over the child tables; resolver sources from relations (wire shape byte-identical).
**⚠ BIGGEST blast-radius item — the write path.** `UserSelfController::update:68-69` + `StaffUserController` `fill($validated)` persist about JSON. After reads switch to tables, **continuing to write JSON makes every edit invisible.** Required: a new `SyncUserAboutService` (Services/User/) called by both controllers — delete-and-reinsert ≤5 rows with sort_order, strip credentials/experience from `about` before fill, then `reevaluateEnabled(…,'credentials'/'experience')`. The dashboard `about` GET must still round-trip the `{credentials:[…],experience:[…]}` shape rebuilt from the tables. Also check `DataExportPayloadBuilder` (GDPR export) reads the tables.
**Tests:** new `setupUserCredentialsTable()`/`setupUserExperienceTable()`; new write-path test (PATCH about → rows created, JSON stripped, is_enabled flips). Same BatchCheck SQLite-portability net win.

### Group-B ordering
Both **L**, overlap on `SectionVisibilityService` + `SitepageDataResolverService` + Pest scaffolding + `BatchCheckQueryCountTest`. **One combined migration PR** (3 tables) editing the two shared service files together, with workplace and about write-paths as stacked commits. If split: **FOUND-4 first** (self-contained), then FOUND-5 (rebases on the refactored service). FOUND-5's write-path rewire is the single biggest risk — ship its writer + reader together.

---

## GROUP C — `site.blocks` / `site.sites` schema + visibility (FOUND-14, 15, 16, 10) · the audit's Bundles 4+6

**Cluster infra facts:** blocks DDL = baseline `:737-777` (+`20260527040000` block_type extension); sites DDL = baseline `:707-728`; columns are `user_id` (renamed from professional_id). **Two DB VIEWs are the public read path and emit the whole JSONB bag** — `site.all_site_data` + `site.public_site_payload` (in `20260527070000_skeleton_system_cleanup.sql`) — these are the **highest-risk readers** for any column promotion and MUST change in lockstep. SQLite test schema is hand-built in `tests/Pest.php` (`setupBlocksTable():622`, `setupSitesTable():367`) — every promoted column added by hand there, and **no CHECK/partial-index is enforced under SQLite** (validate on dev Supabase via `execute_sql`).

### FOUND-14 (P2, M) — `block_group`/`block_type` magic strings → config map + paired CHECK
**Premise: HOLDS (nuance).** There's already a `block_type` CHECK AND a separate `block_group` CHECK, but **no pair-CHECK** — `('sections','link')` passes today. No `config('partna.block_types')` map yet (only `section_block_types`).
**Migration:** drop the two independent CHECKs, add one `blocks_group_type_check CHECK ((group='links' AND type='link') OR (group='sections' AND type IN (…15 section types…)))` via `NOT VALID`→`VALIDATE`. **Rollback:** re-add the original two.
**Code:** new `config('partna.block_types')` keyed by group (sections list = the CHECK enum, cross-referenced by comment, mirroring the `ALLOWED_SKELETONS`↔CHECK pattern); controllers read config instead of bare `'link'`/`'links'`/`'sections'` literals. **Couples with FOUND-10** (a new section type = config entry + CHECK + visibility contract).

### FOUND-15 (P2, M) — promote `live_check_enabled`/`category`/`platform` from `Block.settings` → columns
**Premise: HOLDS.** Expression index `idx_blocks_live_check_enabled` on `(settings->>'live_check_enabled')` (baseline `:776-777`); predicates in `CheckStreamingLiveStatusJob:71`, `StoreLinkBlockRequest:184/155`, `UpdateLinkBlockRequest:143`, `SectionVisibilityService:174/495`.
**Migration:** `ADD COLUMN live_check_enabled boolean NOT NULL DEFAULT false, category text, platform text`; backfill from `settings->>…` WHERE block_group='links'; strip the 3 keys from settings; **swap** the expression index for a normal partial index on the column. Optional `category` CHECK; **leave `platform` un-CHECKed** (large, frequently-extended registry — a CHECK would force a migration per platform). **Rollback:** recreate expression index, re-inject keys, DROP columns.
**⚠ `handle` stays in JSONB** — the job/injector read `platform` (column) + `handle` (JSONB) together, so they read two sources post-promotion.
**Code:** `Block` model fillable/casts; drop the 3 keys from `link_block_settings_keys`; `LinkBlockFieldBuilder` writes top-level fields; Form Requests move category/live_check rules to columns + rewrite the cap/count queries to column predicates (portability bonus); `CheckStreamingLiveStatusJob`, `LiveStatusInjector`, `SitepageDataResolverService:304-339`, `LinkBlockResource`. **Both DB views must emit `platform`/`category`/`live_check_enabled` (recommend emitting as top-level block keys; injector + resource read them) — ships in the same migration.** `tests/Pest.php setupBlocksTable` + `createLinkBlockFor` updated.

### FOUND-16 (P2, L) — promote 10 `site.sites.settings` sub-keys → typed columns
**Premise: CHANGED — two discrepancies to flag.** (A) `StaffUpdateSiteRequest:76-88` validates only **9** — missing `charlie_enabled`; promotion unifies them. (B) `booking_mode` is `Rule::in(['manual'])` today (not `'none'`); the proposed `CHECK IN ('manual','none')` is *wider* than current validation — decide: widen both requests to `['manual','none']` (recommended, matches finding intent) or narrow the CHECK to `('manual')`. No conflict with the skeleton `settings.design` strip (disjoint keys).
**Migration:** `ADD COLUMN` the 10 (text + 3 boolean); backfill from `settings->>…`; strip the 10 keys; `sites_booking_mode_check CHECK (booking_mode IS NULL OR IN ('manual','none'))` via NOT VALID→VALIDATE. **Rollback:** re-inject + DROP.
**Code:** `Site` model fillable/casts + `BOOKING_MODES` const; **recommend hoisting in `UpdateSiteAction`** (`:51-66`/`:226`) — keep accepting `settings.*` from the client (no frontend change), extract into columns before `fill()`; widen booking_mode + add charlie_enabled to Staff request; `SiteResource:36-37` + `SitepageDataResolverService:593-594` read columns. **Both DB views must emit the 10 columns — same migration.** `tests/Pest.php setupSitesTable` mirrors the 10.

### FOUND-10 (P2, M) — `SectionVisibilityService` 3 entangled blocks → registry (CODE-ONLY, ships WITH FOUND-14)
**Premise: HOLDS.** `checkVisibilityRequirements` match (`:32-45`), `loadVisibilityContext` (N flags + subqueries, `:101-273`), `resolveFromContext` match (`:283-325`) + private helpers. Types with real requirements: gallery, booking, services, documents, countdown, contact, public_contact, workplace, credentials, experience.
**Code:** a `SectionVisibilityContract` (`blockType()`, `contextSubquery(userId,siteId): ?Builder`, `resolve(Block,$context,?$pendingSettings): array`) + a `SectionVisibilityRegistry` keyed by block_type (model on the `PlatformRegistry`/`MenuPlatformDriver` pattern), one impl per type, populated in a provider. The 3 methods collapse to registry iteration (preserve the single-round-trip bundled `SELECT exists(...)` optimisation + booking's post-loop `has_booking_integration=false`).
**⚠ Trickiest correctness point:** `countdown`/`contact` use `pendingSettings` (unsaved) in the single-check path but the loaded block in the batch path — the contract's `resolve()` must accept optional `$pendingSettings`. **Booking needs two subqueries** + a legacy `settings->booking_url` fallback — its contract may return a keyed map or get a small bespoke aggregator.

### Group-C ordering (3 PRs)
1. **PR-A = FOUND-14 + FOUND-10** (Bundle 4): CHECK swap + config map + controller literals + the visibility registry. No column promotion → DB views untouched. Lowest risk; foundation for "single source of truth for section types."
2. **PR-B = FOUND-15**: block column promotion (migration + **both views** + model/FR/job/injector/resolver/resource, atomic). Doing A first means the `settings->category` query is already centralised into `BookingSectionVisibility`.
3. **PR-C = FOUND-16**: site column promotion (migration + **both views** + Site/UpdateSiteAction/both requests/resource, atomic).
**Core risk:** the migration strips promoted keys, so any reader still on the old JSON path gets **NULL silently** (blank hero, lost booking links/category/platform) — not an exception, so only golden-master payload assertions catch it. **De-risk option:** dual-write (keep JSONB mirror) then strip in a follow-up migration, removing the "view must change in lockstep" coupling — recommended if PR-B/PR-C feel too wide.

---

## GROUP D — Platform HTTP layer + auth (FOUND-19, 21, 12) · CODE-ONLY (no migration)

### FOUND-19 (P2, M) — ~24 connect Form Requests → registry-driven
**Premise: HOLDS (corrections).** **22 classes, not 24** (SmartLinks removal). Shapes: 17 `url`-shaped (incl. fresha/square which add a regex), 4 single-field-name (apple-music `artist`, apple-podcast `show`, youtube `channel`, social-link `username`), 1 true outlier (GoogleBusiness multi-field). **Audit wrong:** Youtube is NOT a video-id outlier — it's a plain `channel` field; **only GoogleBusiness is irreducible.** The registry already carries connect metadata (`PlatformDescriptor::connect/connectStrategy/connectErrorMessage:132-154`) but not validation rules.
**Plan (extend the descriptor, NOT a parallel config map):** add `PlatformDescriptor::connectInput($field,$rules)` + `connectField()`/`connectRules()`, populated in `PlatformRegistryServiceProvider`. One base `ResolvesConnectRules` request resolving the descriptor from the `platform` route param (404 fail-closed); recommend a single `PlatformConnectRequest` (the ByUrl/ByUsername split is cosmetic once field is metadata-driven). `GenericPlatformController::connect` reads `$request->validated()[$descriptor->connectField()]` instead of hardcoded `['username']`. **Add `->defaults('platform',$slug)` to the thin connect routes** (the seam FOUND-21 generalises). GoogleBusiness stays standalone. **Frozen contract:** field names/maxes/regex/422 messages are API-visible — reproduce byte-for-byte; **route count locked at 52** (`IntegrationContractGoldenMasterTest:582`).
**Effort M; do before FOUND-21.**

### FOUND-21 (P3, L) — route registration over the registry
**Premise: HOLDS.** `routes/api/integrations.php` statically imports 30 controllers + has 3 partial data-loops already (`$singleSelection`, `$migratedReads`, socials).
**Plan:** add a `routeShape` enum (LinkOnly/SingleSelection/MultiAccount/Bespoke) + optional `connectController` to `PlatformDescriptor`; collapse the 3 loops into ONE loop over the registry filtered to simple archetypes (emit connect/selection/DELETE/accounts); move bespoke-connect controller imports into the provider. Stays standalone: fresha, shop, instagram, youtube, apple, bandcamp, vimeo, youtube-music, events, custom links, category facades, menu, one-off GETs. **Pure refactor — same 52 routes.** Depends on FOUND-19's route defaults + shared request.
**Effort L; strictly after FOUND-19.** Golden master: route-count `toBe(52)` + `route:list` diff empty.

### FOUND-12 (P2, S) — extract `Aal2FreshnessGate` (AUTH-SENSITIVE)
**Premise: HOLDS — two copies, core byte-identical.** `BasePolicy::requiresFreshAal2:69-93` (window default `config('partna.mfa.fresh_window_seconds',300)`, `request()` helper) vs `MfaController::requiresFreshAal2:84-107` (injected `$request`, required window). Both: `$mfaMethods=['totp','phone','webauthn']`, max-timestamp scan, null→`denyWithStatus(401,'Recent MFA verification required')`, `(time()-$ts)<=$max ? allow() : deny`. Return type `Illuminate\Auth\Access\Response` both.
**Plan:** new `App\Services\Auth\Aal2FreshnessGate::check(Request,$maxAgeSeconds): Response` (verbatim body); both call sites delegate keeping their signatures (no caller churn). `MfaController::destroy` passes `config('partna.mfa.unenroll_fresh_window_seconds',60)`; `mfa_fresh_required` shape unchanged.
**⚠ AUTH-SENSITIVE — second consumer is staff policy (`StaffUserControllerFreshAal2Test`).** Drift = wrong AAL2 acceptance (stale-but-aal2 session could unenroll MFA / hit staff actions). Must be byte-identical; **this is the one Wave-2 finding that warrants an explicit security-review step.**
**Effort S; independent; security-review checkpoint required.**

---

## GROUP E — SiteMedia covers + IntegrationConnection async state (FOUND-3, FOUND-18) · standalone, independent

### FOUND-3 (P1, M) — `SiteMedia` cover purposes → registry-derived convention
**Premise: CHANGED (two material ways).** (1) **`cover_shopify` is a DEAD slot** — no `shopify` platform in the registry (`RegistryCoverageTest` freezes the key set; only `shop` exists, a multi-brand resource). Drop it, don't migrate. (2) **Registry keys are hyphenated (`apple-music`), cover purposes underscored (`cover_apple_music`).** `IndividualProfilePayloadBuilder:232` derives the camelCase `siteImages` key by splitting on `_`, so a naive `cover_{key}` would emit `cover_apple-music`→`coverApple-music` and **break the partna-pages wire contract**. The convention MUST be `cover_` + `str_replace('-','_',$key)`. (3) **No `coverable` flag exists** on `PlatformDescriptor` — must be introduced; the de-facto cover-capable set is exactly 4: `youtube`, `apple-music`, `apple-podcast`, `eventbrite`.
**Migration** (index-only, `CONCURRENTLY`, no txn — matches existing cover-index migrations): DROP the 5 per-purpose partial unique indexes (incl. the dead `cover_shopify`), CREATE one composite `site_media_design_singleton_purpose_uq ON site.site_media (site_id, purpose) WHERE pool='design' AND deleted_at IS NULL`. **Provably non-weakening** (strictly stronger — rejects a 2nd row for ANY (site,purpose) pair). **Two caveats:** it subsumes the baseline `logo_full`/`logo_square` indexes (redundant, optionally drop) and adds a (currently dormant — no code creates design-pool placeholders) singleton guard over `placeholder`. **Rollback:** drop composite, recreate the 4 live cover indexes (not shopify).
**Code:** `SiteMedia` — delete the 5 `PURPOSE_COVER_*` consts; replace the `DESIGN_SINGLETON_PURPOSES` **const** with a static **method** `designSingletonPurposes()` reading `app(PlatformRegistry::class)->coverable()` mapped to `cover_`+underscored-key (a const can't call the runtime registry — this is the load-bearing change). Add `coverable()` flag to `PlatformDescriptor` (mirror `refreshable`) + `PlatformRegistry::coverable()` filter + `->coverable()` on the 4 descriptors. `UploadDesignMediaRequest:23`, `UserDesignMediaController:44/51`, `SitepageDataResolverService:257` swap const→method. `MediaUploadService` unchanged (purpose-agnostic; remains the app-side singleton guard).
**Tests:** `DesignSingletonMediaTest` edits (line 78 `cover_shopify` now rejected; the 4 live covers incl. `coverAppleMusic` stay green — proves the underscore convention preserved the contract); new test pinning `designSingletonPurposes()` to the 6-value set. **SQLite can't enforce the partial unique index** — verify the composite on dev Supabase (two `cover_youtube` → violation; `cover_youtube`+`cover_apple_music` → ok).
**Effort M; independent of FOUND-18; ships in any order.**

### FOUND-18 (P2, M) — `IntegrationConnection` payload async state → columns
**Premise: PARTIALLY CHANGED.** `apify_status` HOLDS (genuine hidden state machine: written `pending` by `GoogleBusinessController:70`, flipped `ok`/`unavailable` by `GoogleBusinessEnrichJob:99/147`, read via `GoogleBusinessPayload::apifyStatus`, emitted by the resource). **`place_id` CHANGED — it is NOT hidden state**: it's a first-class selection identifier in the connect request contract (`ConnectGoogleBusinessRequest:20`), the Maps deep-link (`GoogleBusinessController:47`), the public resource (`GoogleBusinessConnectionResource:23`), and the refresh fetch (`GoogleBusinessFetch:23`). **Resources are built from the payload ARRAY, not the model** (`new $resource($payload)`) — so removing a key from payload silently drops it from every response unless re-threaded.
**Recommended ASYMMETRIC treatment (sign off explicitly):**
- **`apify_status`: fully promote.** ADD COLUMN + `CHECK (apify_status IS NULL OR IN ('pending','ok','unavailable'))` (note: adds `pending`, omits `error` vs the existing `last_refresh_status` CHECK — intentional). Write the column, drop from payload, re-inject into the resource. **Requires overriding `selection()` in `GoogleBusinessController`** (it inherits the payload-only `SingleSelectionPlatformController::selection`) so `/selection` emits `apifyStatus` from the column (`GoogleBusinessApifyTest:381`). `GoogleBusinessPayload::apifyStatus()` becomes dead → remove.
- **`place_id`: add an INDEXED column mirror, keep it in payload.** Promoting it out fights the deliberate verbatim-payload design + would re-thread 5+ readers. Write the column alongside the payload write; switch only the reconnect guard `GoogleBusinessEnrichJob::connection():138` from `data_get(...placeId)===` to `->where('place_id',?)` (now indexed `(user_id, place_id) WHERE deleted_at IS NULL`). Payload readers unchanged. (Full payload-purity = a separate larger task.)
- `apifyFetchedAt`: leave in payload (keep migration tight) OR promote for symmetry — pick one (`GoogleBusinessApifyTest:190` asserts the key).
**Migration:** ADD `apify_status text` + `place_id text` + the CHECK + backfill from payload JSON + the partial index. **Rollback:** drop index/constraint/columns.
**⚠ Test-schema break (the Instagram-500 lesson):** `tests/Pest.php:418-434` defines `site.platform_connections` with neither column AND `payload TEXT NULL` while prod is `jsonb NOT NULL` — must add `apify_status TEXT NULL` + `place_id TEXT NULL`, and the CHECK is unenforced on SQLite, so **verify ADD COLUMN + CHECK on dev Supabase** (reject `'queued'`). Golden master: `GoogleBusinessApifyTest` (move `$conn->payload['apifyStatus']` assertions to `$conn->apify_status`; update the `gbApifyConnection` seed helper).
**Effort M; independent of FOUND-3; the apify_status and place_id pieces are separable.**

---

## Recommended global sequencing & PR shape

| # | PR | Findings | Migration? | Notes |
|---|----|----------|-----------|-------|
| 1 | Auth freshness | FOUND-12 | no | **Security-review checkpoint.** Smallest, independent, highest-sensitivity — do first to clear the auth gate. |
| 2 | Menu schema | FOUND-2, 6 | 2 files, 1 PR | Reviewer decision: FOUND-6 per-mode schema (recommended) vs audit's lossy one. Drain `scraping` queue if collapsing expand+contract. |
| 3 | Profile data | FOUND-4, 5 | 3 tables, 1 PR | Shares 2 hot service files. FOUND-5 write-path rewire is the biggest single risk. Flag the workplace name-only visibility change. |
| 4 | Section types | FOUND-14 + 10 | 1 CHECK swap | No column promotion → views untouched. Foundation for the next two. |
| 5 | Block columns | FOUND-15 | yes + **both views** | Dual-write-then-strip option to de-risk the view lockstep. |
| 6 | Site columns | FOUND-16 | yes + **both views** | Flag Discrepancies A (Staff missing charlie_enabled) + B (booking_mode 'none'). |
| 7 | Media covers | FOUND-3 | index-only | Independent; the underscore-normalization is the subtle correctness point. |
| 8 | Connection state | FOUND-18 | yes | Asymmetric apify_status(promote)/place_id(mirror). |
| 9 | Connect requests | FOUND-19 | no | Extend `PlatformDescriptor`; route count frozen at 52. |
| 10 | Route registry | FOUND-21 | no | After FOUND-19. Pure refactor, same 52 routes. |

**Cross-cutting must-dos for every migration:** update `tests/Pest.php`'s hand-built SQLite schema for new columns/tables; remember SQLite enforces NO CHECK/partial-unique/FK-cascade, so validate each constraint directly on dev Supabase `glncumufgaqcmqhzwrxm` via `execute_sql`; the two public-read VIEWs (`all_site_data`, `public_site_payload`) must change in lockstep with FOUND-15/16; apply via `supabase db push` or MCP `apply_migration` (the env's `migrate --force` is commented out).

## Stale-premise corrections caught during planning (do NOT implement the audit verbatim)
- **FOUND-6:** child schema is per-mode (`pickup_*`/`delivery_*`), NOT `(price, modes, url)`.
- **FOUND-3:** `cover_shopify` is dead; use `cover_`+underscore-normalized registry key; introduce a `coverable` flag (4 platforms).
- **FOUND-18:** `place_id` is a real selection identifier, not hidden state — mirror it, don't move it.
- **FOUND-16:** Staff request is missing `charlie_enabled` (9 keys not 10); `booking_mode` doesn't accept `'none'` today.
- **FOUND-19:** 22 requests not 24; Youtube is a plain `channel` field, not a video-id outlier; only GoogleBusiness is irreducible; the registry already carries connect metadata.
- **FOUND-14:** a `block_type` CHECK and a separate `block_group` CHECK already exist — what's missing is the *pair* CHECK.
- **FOUND-5:** the read-side migration is incomplete without rewiring the JSON *write* path (new `SyncUserAboutService`).
