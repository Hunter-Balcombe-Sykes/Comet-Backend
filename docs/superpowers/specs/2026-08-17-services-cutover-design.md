# Services cutover — the last three legacy tables

Parent: `2026-08-11-content-pool-convergence-design.md` §7 (Slice 3, Slice 7).
Kickoff: `plans/2026-08-17-services-cutover-KICKOFF-PROMPT.md`. Scope handoff:
`2026-08-17-shop-brands-rehome-design.md` §11. Predecessors:
`2026-08-12-slice-3a-services-owner-authored-design.md`,
`2026-08-13-slice-3b-services-fresha-design.md`,
`2026-08-12-slice-7-teardown-design.md` (Units C, F, I, K).

**Dev only. Production is out of scope for the whole programme.** Size: **XL**.
This project ends the dual-id-space era for services: cut the Fresha half's
management surface over to `content.*`, repoint every remaining reader and
writer, then drop `site.services`, `site.service_categories` and
`site.service_category_assignments`.

---

## 1. Verified state — dev (`glncumufgaqcmqhzwrxm`) and `origin/development`, 2026-08-17

Every figure re-derived per rule zero. Where this section disagrees with the
kickoff prompt, this section wins and the disagreement is recorded (§1.5).

### 1.1 The branch picture moved under the kickoff

`feat/slice-7-teardown` **and** `feat/slice-7-drops` are both merged into
`origin/development`. Consequences the kickoff predates:

- **Slice 7 Task 11 SHIPPED** (`6d67274aa` oracle, `eb45b9f1c` compose,
  `da99b28b4` class-name correction). `FreshaServiceProjector::sync()` writes
  NOTHING (`:100` docblock); `compose()` reads
  `FreshaServiceItems::selectionServices()`; the advisory-lock narrowing (plan
  Task 11 Step 5) is done — callers back to the 10s default. The public booking
  surface is already off `site.services`.
- **Slice 7 Task 12 SHIPPED** (s7-h, `e2c66f476`): the reorderLayout
  assignment-sync blocks and the staff category index's two-id-space merge are
  gone. Its residual note stands: `categories()->sync()` in both controllers'
  legacy branches and `destroyLegacy()`'s raw detach still write the pivot,
  deliberately, until the branches themselves retire (here).
- **Task 18 (DSAR) SHIPPED AND WENT FURTHER THAN PLANNED.** All three export
  sections re-sourced from `content.*`, and a post-plan commit **deleted**
  `ManualServiceItems::legacyIdsFor()`'s legacy-id recovery outright —
  reasoning recorded in `exportRows()`'s docblock (coord shape has no oracle
  but the dropped table; GDPR accuracy outranks a continuity promise that
  protected a population of zero). `DataExportPayloadBuilder`'s remaining
  `site.service*` strings are docblock text. **Kickoff question 4 is closed:
  the DSAR export does not read these tables.**
- **The armed migration is on `development` itself now**, not only the feature
  branch: `supabase/migrations/20260817000000_public_site_payload_services_from_content.sql`
  (19,404 bytes), **unapplied** on dev (verified against
  `supabase_migrations.schema_migrations`). Any `supabase db push` against the
  dev ref from a current checkout applies it. Six sibling `20260817*` drop
  migrations (four menu tables, `content_selection`, event-slug delete) ARE
  applied and recorded.

### 1.2 Live data

```
site.services                    82 rows: 61 sourced (Fresha), 21 superseded owner rows
                                 28 soft-deleted = 25 deleted_origin='sync' + 3 NULL
                                 0 rows carry deleted_origin='user'  (code path real, data empty)
site.service_categories          18 (2 soft-deleted; live rows all source='fresha')
site.service_category_assignments 61
content.* services               connection 59 + manual 21 source_items; 77 live / 3 retired items
```

### 1.3 The catalog's answer (kickoff question 5)

`pg_depend` on dev: **`site.public_site_payload` is the ONLY view** (no
matviews) over the three tables. Also on them, and dying with them: the two
internal FKs (`service_category_assignments` → services / service_categories,
both CASCADE), two `updated_at` triggers, and **seven RLS policies**
(`services_anon_select`/`pro_all`/`staff_all`,
`service_categories_pro_all`/`public_read`/`staff_all`,
`service_category_assignments_app_backend_all`). No external FK points in.

### 1.4 Who still writes the three tables (kickoff question 2)

The answer is not "none" — it is this list, every entry a §C2 legacy branch or
transitional bookkeeping, and every entry retired by §3 before the DROP:

| Writer | What it writes |
|---|---|
| `UserServiceController` `:359` save, `:685` pivot sync, `:692` update, `:788-792` delete + `deleted_origin='user'`, `:1197-1201` restore | Fresha row CRUD by legacy id |
| `StaffServiceManagementController` `:369` delete, `:696` restore, `:754` pivot sync, `:767` save | staff twin of the above |
| `StaffServiceCategoryManagementController` legacy by-id branches, incl. `destroyLegacy()`'s raw detach (`:441`) | category CRUD + pivot delete |
| `FreshaController::disconnect()` (~`:681`) | soft-deletes every synced row with `deleted_origin='sync'` — **not flagged by the kickoff** |
| `FreshaServiceProjector::revert()` | forceFill-save on one row, for `resync()`'s legacy branch only; its docblock already says "it goes with the table" |
| `App\Services\Site\LegacyServiceSortOrder` `:112`/`:115` | `sort_order` renumbering across the WHOLE live set — **not in the kickoff's file list at all** |
| `ServiceBackfiller` / `BackfillOwnerServices` | one-shot 3a tools, already run on dev; not a live lane |

### 1.5 Corrections to the kickoff's figures

| Kickoff | Re-derived |
|---|---|
| ~30 live query sites, five files | **37** across **seven** files — add `LegacyServiceSortOrder.php` (5, incl. writes) and `ContentFreshness.php:89` |
| 77 raw references | **69**, across 22 files |
| `UserCacheService` `Service::query()` at `:230` | `:230` **and `:279`** — two merge reads: `:230` the `/me` "active services" list, `:279` the dashboard list |
| per-file ref counts (43/18/9/3) | not reproducible with the stated greps (33/16/6/2 combined); every **line number** matched exactly |
| "the seven category routes" | **eight**, split 3/5 across the staff groups: `index`/`show`/`restore` (`staff.php:122-127`) vs `store`/`update`/`destroy`/`forceDestroy`/`reorder` (`:239-248`) |
| "§C2, slice 3a" | dangling citation — the behaviour is real and documented in `UserServiceController`'s docblocks; no spec/plan contains a §C2. This spec is now the citable home (§3.2) |
| "3b … projector writes nothing" | the writes-nothing state landed with slice 7 Task 11 (D3a), not 3b; 3b built the read it repointed onto |

---

## 2. Owner rulings — 2026-08-17

Recorded so they are not silently re-opened.

1. **Legacy `site.services` ids break, deliberately.** No mapping is minted and
   nothing carries them forward. The management surface (owner + staff)
   addresses Fresha services by `content.items.id` after the cutover, exactly
   as it has addressed owner-authored services since 3a. A recorded wire-manifest
   entry covers the break. Grounds: the public wire is already single-id —
   `payload.selection` composes from `content.*` (Task 11) and the armed view
   migration's own header verified KV/API id agreement element-for-element
   across all 22 published dev sites — so legacy ids survive only in
   authenticated management URLs, and the frontend is under the
   rebuild-not-repair override.
2. **Deleted-state semantics re-home per Task 11 Step 4's mapping** (§3.3):
   owner-delete → `content.items.removed_at` (one-way, projection never touches
   it — never resurrected); vendor-removal → `content.source_items.removed_at`
   (cleared on reappearance — restore-on-return preserved natively); hidden
   stays on the blob (`hiddenServiceIds`, shipped with Task 11).
3. **The armed view migration applies whenever** — this spec schedules it
   deliberately as the FIRST unit (§3.1), removing the standing accidental-push
   hazard instead of living with it.
4. **`anseo-studio`'s unprovisionable `book-now/…?pId=` URL is deferred until
   after this project** (§7). Consequence accepted: that connection publishes
   nothing until the matcher is widened — unchanged from today.
5. **The no-selection dashboard prompt stays deferred** (§7). Three connections
   publish nothing by 3b's deliberate decision; prompting is dashboard work.
6. **`LegacyServiceSortOrder` and `ContentFreshness` are re-homed, not
   deleted-and-lost.** The *functions* survive on `content.*` (§3.4, §3.5); the
   legacy class retires with the table exactly as its own docblock plans.

---

## 3. The change

Six units, ordered. The programme law holds: **every `content.*` write path
lands BEFORE the DROP that removes its legacy twin**, and the DROPs are one
unit, last, after live verification on dev.

### 3.1 Unit 1 — apply the armed view migration · S

`20260817000000_public_site_payload_services_from_content.sql` is applied to
dev **deliberately**, first. It recomposes only the `services` key of
`site.public_site_payload` off `content.*` (manual half), mirroring
`ManualServiceItems` statement-for-statement, and changes the KV payload's
`services[].id` from legacy ids to `content.items` ids — the ruling-1 break,
landing on the KV wire here. The file's header carries its own rollback
(re-run with the pre-image `services` key, transcribed at its foot).

After apply: re-warm KV for the published sites (`SyncSubdomainToKvJob` path),
verify one rendered sitepage's services against the API, and **re-run the
§1.3 `pg_depend` query** — the view must no longer depend on any of the three
tables, or something in it was missed and the DROP is still blocked.

Wire manifest entry: KV `services[].id` domain change (dev sitepages).

### 3.2 Unit 2 — the Fresha management surface → `content.*` · L

The core of the project. Every §C2 legacy branch in `UserServiceController`,
`StaffServiceManagementController` and `StaffServiceCategoryManagementController`
— resolve-by-legacy-id, fall-back reads, and all §1.4 writes — is replaced by
content-side resolution of **connection-sourced** service items.

- **Resolution.** `ManualServiceItems::find()` is manual-scoped by its source
  filter. The cutover adds a connection-scoped sibling on `FreshaServiceItems`
  (same `baseQuery` shape, `cs.kind = 'connection'`), and each verb resolves
  manual-first then connection, 404 on a miss — the same shape the §C2
  branches had, with `content.items.id` as the only addressable id (ruling 1).
- **What a Fresha item permits** is unchanged from the shipped 3b/D3a state:
  title/description/duration edits via `content.manual_overrides`; **no price
  edit** (D3a, owner-ruled 2026-08-16); hide/show via the blob's
  `hiddenServiceIds`; category assignment via `content.collection_items`
  (replace-by-source rule, 3b §3.3); `resync` = delete the item's
  `manual_overrides` rows (already shipped — only its legacy fall-back branch
  and `FreshaServiceProjector::revert()` retire).
- **`ServicePolicy` stays** — it authorizes on `user_id` against the in-memory
  model and is what `authorizeForUser` needs; only its docblock's description
  of the two-store split updates. `updateCategory()`'s source gate came off in
  3b and stays off.
- **The eight staff category routes** (§1.5 — eight, not seven) keep their
  3/5 middleware-group split; only the by-id legacy branches behind five of
  them change. Verify each route's group before moving anything —
  `staff.php` has THREE groups.
- **`FreshaController::disconnect()`** stops writing `site.services`. Its
  legacy behaviour (soft-delete all synced rows, origin `'sync'`, restored on
  reconnect) maps to: the connection teardown it already performs, plus
  `SourceProvisioner` deactivating the ingest source. The booking surface keys
  on the connection payload, so disconnect already stops publishing;
  reconnect re-provisions and the next run re-lands the items. No content-side
  write is needed — assert this with a disconnect→reconnect test rather than
  trusting it.

**This spec is the citable home for the addressability rule the code calls
"§C2":** *during the transition a legacy `site.services` id stayed addressable
on the management surface; this unit ends that, by ruling 1.*

### 3.3 Unit 3 — deleted-state semantics · M (with Unit 2)

The two legacy behaviours named by the kickoff's question 3, re-homed:

| Legacy behaviour | `content.*` home |
|---|---|
| `deleted_origin='user'` — owner deleted; never resurrected by a sync | `content.items.removed_at`, set via the kind-agnostic `ManualServiceItems::markRemoved()`. Projection NEVER touches `items.removed_at` (the parent-spec rule that put legacy `deleted_at` there and never on `source_items`), so a re-listing vendor cannot restore it. `restore` clears it — one-way in each direction, the `collections.removed_at` precedent (3b §3.3) |
| `deleted_origin='sync'` — vendor stopped listing it; restore if it returns | `content.source_items.removed_at` — `ProjectionWriter` already sets it on disappearance and clears it on return. Native behaviour; nothing to build, one test to pin |
| `is_active` hide/show | already rides the blob (`hiddenServiceIds`) — shipped with Task 11, nothing further |

Dev carries **zero** `deleted_origin='user'` rows, so this mapping is proven by
test, not by data migration; the 25 `'sync'`-deleted legacy rows need no
carrying (3a §2's reasoning, re-confirmed: the connector reproduces that state
natively). The 3 NULL-origin soft-deleted rows are pre-`deleted_origin`
history; they drop with the table, recorded in the checkpoint.

### 3.4 Unit 4 — ordering re-home · M

`LegacyServiceSortOrder` exists solely because both halves share one table
under the global partial unique `services_user_sort_order_uq`; its docblock
already declares its writes die with the table. Its **function** — one shared
numeric ordering scale across the merged dashboard list (§NEW-I1) — re-homes:

- **Connection-sourced service items get `site.section_items.sort_key` rows in
  the site's services section** — the same table, section scoping and numeric
  domain the manual half has used since 3a, so the merged list sorts one
  column. The reorder verbs write `sort_key` for Fresha ids where they called
  `renumber()`.
- **Two-surface stays intact by construction:** the public services read
  (`ManualServiceItems`/`publicList()`) filters `cs.kind='manual'`, so a
  section_items row on a connection item is ordering bookkeeping it never
  sees; the booking surface has ordered by `first_seen_at` since Task 11 and
  does not consult `sort_key`. `ServiceTwoSurfaceTest` must stay green
  unmodified, and a new case pins that a pinned-for-ordering Fresha item does
  not surface in the public section.
- Slice 6's exclusion-only-pool landmine is noted and does not fire: the
  services pool is not exclusion-only and no new public read of custom
  sections is introduced.
- `LegacyServiceSortOrder` deletes with the table; the unique index it
  serviced drops with the table too.

### 3.5 Unit 5 — the remaining readers · M

| Reader | Repoint |
|---|---|
| `UserCacheService::getActiveServices` (`:230`, the `/me` list) and `getDashboardServices` (`:279`) | the Fresha half of each merge reads connection-sourced items via `FreshaServiceItems` (§3.2's read), through `toServiceModel`-shaped rows so `ServiceResource` and the cached shape are unchanged. The merge keeps sorting by the shared scale (§3.4) |
| `ContentFreshness:89` | `max(created_at)` over the user's live `content.items` kind `service` — the freshness boost survives, one query swap (carried open since 3a, closed here) |
| `StaffServiceManagementController` grouped index / `:847`/`:858` reads | same §3.2 read |
| `FreshaController:681` read inside `disconnect()` | goes with §3.2's disconnect change |
| Docblock/string stragglers (`ManualServiceWriter`, `ManualPoolWriter`, `AdvisoryLock`, `FreshaFetch`, resources, models) | swept in the same commits that touch each file; no behaviour |

**The `Service` model survives as an in-memory DTO** — `toServiceModel()` and
`ServiceResource` are the dashboard's wire shape and are already fed unsaved
instances for the manual half. Its query surface must go to zero: a guard test
(architecture lane) asserts no `Service::query|where|find` and no
`ServiceCategory::query` remains in `app/` outside the DTO construction sites.
`ServiceCategory` follows the same rule if any mapper still needs its shape;
otherwise it deletes. `ServiceObserver`/`ServiceCategoryObserver` retire with
the last model-layer write (teardown design Unit G: `reevaluateBooking()` is
already mirrored in both controllers; enumerate the remaining side-effects —
cache busts — and confirm each verb's explicit invalidation covers them before
unregistering).

### 3.6 Unit 6 — the DROPs · M, last

Only after Units 1–5 are live-verified on dev.

1. **Backup gate, non-negotiable:** `pg_dump` the three tables to the
   `partna-db-backup` R2 bucket, restore into a scratch schema to prove it
   reads back, assert per-table row counts match live exactly. One table
   disagreeing means nothing is dropped. Taken immediately before the DROPs,
   not earlier.
2. **Re-check the catalog, not the table list:** the §1.3 `pg_depend` query,
   plus RLS/trigger enumeration, re-run at drop time. The view must already be
   content-only (Unit 1).
3. Children before parents, raw SQL, one concern per file, in
   `supabase/migrations/`:
   `drop_site_service_category_assignments` →
   `drop_site_services` → `drop_site_service_categories`.
   Each file names its reverse path (the drops-branch convention,
   `cef89ec5f`). The seven RLS policies, two triggers, and both FKs die with
   their tables — named in the files' headers so the checkpoint can assert
   they are gone rather than assume.
4. Same window: delete `ServiceBackfiller`, `BackfillOwnerServices`,
   `LegacyServiceSortOrder`, `FreshaServiceProjector::revert()` and every §C2
   branch left standing for it; run the schema lane
   (`composer test:schema`) and update any stand-in DDL naming the three
   tables (`tests/Postgres/` hand-written DDL drifts silently — the 5a
   lesson).

---

## 4. Cache invalidation — all three lanes

Every repointed write verb keeps its explicit three-lane obligation
(`BuildState::bump`, `site.sites.updated_at` touch, `CloudflareCachePurgeJob`)
— `ManualServiceWriter::invalidate()` is the reference implementation and is
reused, not copied. Nothing enforces this in CI; the three-lane test asserts an
**exact revision delta**, never `> 0` (3a's deleted-lane false pass). Unit 1's
view apply is itself a raw-write seam: KV re-warm plus edge purge for every
published site, once, recorded in the checkpoint.

## 5. Verification

- **Live dev assertions, output pasted into the parent-spec checkpoint:** the
  §1.2 counts at cutover time; post-Unit-1 view/API/KV agreement; post-drop
  `to_regclass` null for all three tables; `pg_depend` clean; the booking
  surface and dashboard lists rendering for both Fresha salons and a
  manual-only user.
- **Pest:** the §3 units' cases — resolution by content id (owner + staff),
  404 for a legacy uuid (the ruling-1 break, pinned), delete/restore one-way
  semantics both origins, disconnect→reconnect round-trip, ordering interleave
  across halves, two-surface both directions unmodified, the guard test of
  §3.5. Full `--parallel` suite; **helper names prefixed `svcCut`** (cross-file
  helper collisions fatal a parallel run).
- **`composer test:pg` is mandatory** — this touches `ProjectionWriter`
  callers and constraint-bound writes; SQLite has passed what Postgres rejects
  twice in this programme. Verify writes against `supabase/migrations/` DDL.
- PHPStan (worktree invocation: `php -d memory_limit=1G ./vendor/bin/phpstan
  analyse <path> --no-progress --debug`), Pint, wire manifest
  (`docs/wire-changes/2026-08-17-services-cutover.md`: the KV id domain change,
  the management-surface id break, disconnect semantics), post-deploy
  `cloud env:logs partna development --minutes 10` AND a Nightwatch scan.

## 6. Definition of done

One id space: every service surface — public sitepage, KV, booking blob,
dashboard, staff — reads and writes `content.*` only; a legacy `site.services`
uuid resolves nowhere and the break is recorded on the wire manifest; the
deleted-state and ordering semantics demonstrably survive on their new homes;
the three tables, their policies, triggers, FKs, models' query surfaces,
observers, backfillers and `LegacyServiceSortOrder` are gone; the backup gate
ran and matched exactly; suites (SQLite, Postgres, schema), PHPStan, Pint,
logs and Nightwatch are clean.

## 7. Out of scope — carried, with owner rulings

- **Production** — programme-wide (owner, 2026-08-12).
- **`anseo-studio`'s `book-now/…?pId=` URL** (ruling 4): deferred until after
  this project. It publishes nothing meanwhile — unchanged from today.
- **The no-selection dashboard prompt** (ruling 5): three connections publish
  nothing by 3b's decision; prompting is dashboard work, deferred.
- **`item_merges` / cross-platform identity** — still 0, still unexercised;
  nothing here exercises it.
- **The `google_business` `profile` stream** — carried unchanged.

## 8. The kickoff's five questions — index

1. **Dual-id space** → ruling 1: content ids everywhere, legacy ids break
   deliberately, recorded (§2.1, §3.1, §3.2).
2. **Remaining writers** → §1.4 names all of them; §3 retires each before the
   DROP.
3. **Soft-deleted rows** → §3.3: `items.removed_at` / `source_items.removed_at`
   / blob, per origin.
4. **DSAR** → closed before this spec: the cross-check was deliberately
   removed; the export is off the tables (§1.1).
5. **Views** → `site.public_site_payload` only, plus the §1.3 catalog
   inventory; Unit 1 clears it and Unit 6 re-checks.
