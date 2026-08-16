# KICKOFF PROMPT — Slice 7, final phase: the backup gate and the DROPs

Everything below the line is self-contained. Paste it into a fresh session.

---

Rename this session to `slice-7-drops`.

You are executing the **final phase of slice 7** of the Content Pool Convergence
programme: the backup gate, the dependency re-check, the DROP migrations and the
programme's closing checkpoint. **Dev only. Production is out of scope and no
tool call may name it.**

Read, in full, before touching anything:

1. `CLAUDE.md`
2. `docs/superpowers/specs/2026-08-12-slice-7-teardown-design.md` — the whole
   thing, especially **Unit K** and decisions **D1, D2, D3, D3a**
3. `docs/superpowers/plans/2026-08-12-slice-7-teardown.md` — **Phase 6**
   (Tasks 23–28), plus **Task 26a**, which is a blocker with a migration already
   written for it
4. `docs/wire-changes/2026-08-12-slice-7-teardown.md` — six sections, one per unit
5. `docs/superpowers/plans/2026-08-16-slice-7-entry-gate-report.md` — the coverage
   evidence, and *why* this slice turned out to be XL

## What is already done

Every write-lane cutover. What remains is this phase: the observers and policies
(they die WITH the models, so they could not go earlier), the backup, the DROPs
and the close-out. On branch `feat/slice-7-teardown`, merged and green:

| Unit | State |
|---|---|
| Phase 1 — `ManualPoolWriter` / `ManualMenuWriter` / `ManualMenuItems` | done |
| Menu write lane — controller (10 verbs), `MenuFetchJob`, scan appliers | done |
| Public `/menu` endpoint | deleted |
| Fresha `payload.selection` off `site.services` | done |
| Standalone events — all THREE writers | done |
| `site.content_selection` code | retired |
| DSAR re-sourced; analytics verified | done |
| Services residuals | done |
| `ShopContentWriter` off the `ShopBrand` model | done |
| `CloudflarePurgeService`, `legacyIdsFor()`, gallery guard | done |

**The branch is pushed: `origin/feat/slice-7-teardown`** (51 commits ahead of
`origin/development`). It is NOT merged to `development` and dev is NOT deployed —
that is step 8, after the drops. Start by taking your own worktree off the pushed
branch:

```bash
git fetch origin
git worktree add .worktrees/slice-7-drops -b feat/slice-7-drops origin/feat/slice-7-teardown
cp -a <main>/vendor .worktrees/slice-7-drops/vendor     # a REAL vendor, never a symlink
ln -sf <main>/.env .worktrees/slice-7-drops/.env
```

Final state of that work, verified at `7ea2762b1`:

```
pest --parallel   8422 passed / 2 skipped / 0 failed
composer test:pg   205 passed / 2 skipped   (PG_LANE_REQUIRED=1)
phpstan analyse app                [OK] No errors
pint                                   passed
```

**Nothing has been dropped, deployed or pushed.**

## The law this phase runs on

> **Every `content.*` write path lands and is live-verified BEFORE the DROP that
> removes its legacy twin.**

That work is complete — which is what makes this phase safe to run. Do not
re-order it. The DROPs are one unit, executed last.

## Standing owner rulings — do NOT re-litigate

- **Nine tables, not ten** (2026-08-17). `site.shop_brands` is **deferred** to its
  own follow-up: 17 production files still read or write it, and the blocker is
  the READ lane (`ShopConnections::brands()` is a live `ShopBrand::query()`, and
  five `ShopController` endpoints resolve stores through it). The full file
  inventory is in the spec's Unit K. **Do not drop `site.shop_brands`. Do not
  delete the `ShopBrand` model.**
- **The DROPs are pre-authorised if every gate is green** (2026-08-17). Run them
  and report after. **STOP on any failure or surprise** — a count mismatch, an
  unexpected `pg_depend` dependent, a reopened coverage assertion.
- **Push to `development` and deploy dev is authorised.** Production is not.
- Owner-edited Fresha prices are unsupported (spec **D3a**).
- The public `/menu` endpoint is deleted outright, not repointed (spec **D2**).

## The nine tables

Children before parents, raw SQL under `supabase/migrations/`, one concern per
file, at most one `CONCURRENTLY` per file. **Never a Laravel migration** — a
composer guard rejects them.

```
site.menu_item_categories, site.menu_item_platforms, site.menu_items,
site.menu_categories, site.service_category_assignments, site.services,
site.service_categories, site.shop_products, site.content_selection
```

Plus: delete the 11 legacy `item_type='event'` rows from `site.item_slugs`.

## Order of work

### 1. Re-run the coverage gate yourself. Cite nothing.

Parent invariant #5: no slice may cite another's checkpoint, and that applies to
the entry-gate report too. Re-derive every per-type coord assertion against dev
(`glncumufgaqcmqhzwrxm`). The figures in the report are a 2026-08-16 snapshot and
will be stale.

**The gate is coord coverage, not row counts** (parent §8.4) — item-level collapse
is expected and visible, not a failure.

### 2. Re-run BOTH `pg_depend` queries — inbound FKs and rewrite rules.

Last run: zero inbound foreign keys from outside the drop set, and exactly **one**
dependent object.

**That one is Task 26a and it is already solved, but NOT applied.**
`site.public_site_payload` is a live VIEW whose `services` key selects from
`site.services`, `site.service_category_assignments` and `site.service_categories`.
It is backed by `app/Models/Views/PublicSitePayload.php` and read by
`SyncSubdomainToKvJob` — the **only writer of `SUBDOMAIN_KV`**, i.e. the payload
the Cloudflare Worker serves for every sitepage. A bare `DROP` fails on it; a
`DROP … CASCADE` silently deletes the view and every KV write with it.

The replacement migration is written and committed but **applied nowhere**:
`supabase/migrations/20260817000000_public_site_payload_services_from_content.sql`.
It was diffed old-vs-new across all 22 published sites — zero differences, ordering
proven. **Apply it BEFORE the DROPs.** One known consequence, already in the
manifest: `services[].id` changes from `site.services.id` to `content.items.id`,
which makes the KV payload agree with the public API rather than diverge from it.

### 3. The backup gate. This is the hard stop.

`pg_dump` the nine tables to the `partna-db-backup` R2 bucket. **Verify the dump
is readable** — restore it into a scratch schema, not just `ls` the object. Then
assert **dumped row counts exactly match live counts, per table**.

**If one table disagrees, NOTHING is dropped.** Stop and raise.

Take it immediately before the first DROP, against the schema as it stands then —
not earlier, or it is stale. Record the bucket path and per-table counts in the
checkpoint. Supabase is on Pro (daily managed backups exist); this per-table
exact-count gate is the surgical control and stays mandatory regardless.

### 4. Three integrity-oracle readers must die WITH the tables

The recurring bug shape in this slice: **a legacy table consulted to answer "is
this id real?" rather than for its data.** Grepping for readers finds these;
reasoning about "what data do we still need?" does not, because the answer is
*none*. Two are fixed; confirm the third.

- `ManualServiceItems::legacyIdsFor()` — **fixed**, recovery deleted, `id` is now
  `content.items.id`. Verify it is gone.
- `LegacyServiceSortOrder.php:139` — `Service::query()->…->exists()`, reached live
  from `UserServiceController::renumber()` at `:890` and `:1160`. **Delete the
  class and both call sites together**, or both service-reorder verbs 42P01.
- Re-grep for others before dropping. This shape appeared three times; assume a
  fourth.

### 4b. A KNOWN, DEFERRED BUG you will meet — do not "fix" it in passing

The menu scrape still overwrites dishes the owner has edited by hand. When an
owner edits a dish through the dashboard,
`MenuContentController::recordOwnerEdits()`
(`app/Http/Controllers/Api/Platforms/MenuContentController.php:759`) records the
change as `content.manual_overrides` rows — the content-lane replacement for the
old `is_manual` flag, per that table's own DDL comment at
`supabase/migrations/20260727140000_content_schema.sql:169`. `MenuFetchJob` never
reads them on the write side: `ownerAuthoredNames()['skip_write']`
(`app/Jobs/Platforms/MenuFetchJob.php:848`) is built purely from
`menus.suppressed_items`, which the controller writes only on its two DELETE
paths (`:329`, `:596`) and never on an edit, so `mergedDishes()` (`:491`)
re-projects the vendor's values straight over the owner's on the next scrape.

What the owner sees depends on which column they touched, and both outcomes are
bad in different ways. `PoolResolver` (`app/Site/Pools/PoolResolver.php:436-442`)
overlays exactly one override at read time — `f_text`/`headline` — so a **rename**
survives on the public site but not in the dashboard or the slug, two surfaces
disagreeing about the dish's name. And `recordOwnerEdits()` never records price at
all, so a **price** edit silently reverts to the vendor's figure publicly while
`ManualMenuItems` still reports `is_manual = true`, telling the owner their edit
held when it did not. No layer below protects the write either:
`ValueResolver::resolve()` (`app/Content/Values/ValueResolver.php:59`) accepts an
`?Override` parameter built for precisely this, and `ProjectionWriter:1614` calls
it with three arguments, never passing one.

**It was deferred deliberately, and the obvious fix is wrong.** The skip must key
on the **coord**, not the dish name — after a rename `headline_cache` holds the
owner's name while the coord still hashes the vendor's. And skipping the write
means the coord never enters `persist()`'s `$coords` set (`:404`), which makes the
dish a retirement candidate at `:683` — so the one-line fix deletes exactly the
dishes it was meant to protect. There is also an unsettled product question code
cannot answer: `MenuScanApplier::lockedItemIds()` locks the **whole dish** the
moment any override exists, whereas `ManualOverride` is **per-column** by design.
Pick one of those semantics before writing the skip.

**The retirement half is already done and is NOT part of this phase.**
`ownerLockedCoords()` (`MenuFetchJob.php:902`) exempts override-carrying coords
from `absentDishIds()`, so a vendor dropping an owner-edited dish no longer marks
it removed. That half was fixed first because `items.removed_at` is one-way and
the loss was unrecoverable. Give the write-side lock its own branch.

### 4c. The five remaining query sites — swept 2026-08-17, delete with the tables

A grep for real query sites (`DB::table('site.<t>')`, comment lines excluded)
across the nine drop-list tables returns exactly **five**. Every one is a
deliberate transitional fallback that dies with its table; none is an unmigrated
write lane. **Re-run this sweep yourself before dropping** — this is a snapshot,
and the sweep is one command:

```bash
grep -rn "table('site\.<table>'" app/ | grep -v Migration
```

| Site | Nature |
|---|---|
| `StaffServiceCategoryManagementController.php:441` | `destroyLegacy()`'s raw detach. Left by Task 12 on purpose — removing it early orphans pivot rows ahead of the retirement |
| `MenuFetchJob.php:1094` | `hasOwnerContent()`'s legacy arm. Its own docblock says it dies with the tables |
| `MenuFetchJob.php:1121` | `remainingContentSource()`'s legacy half. Degrades to `scan > website-scan > manual` while legacy rows exist |
| `MenuDashboardPayload.php:189` | The legacy-fallback `itemCount`, paired with the composer's content-lane-with-legacy-fallback gate |
| `FoodContentProbe.php:36` | Probes whether a menu exists at all — **check this one first**, it gates sector/capability behaviour, not just a payload |

The Eloquent-model reads are separate and larger: `Service`, `ServiceCategory`,
`MenuItem`, `MenuCategory`, `MenuItemPlatform`, `ShopProduct` and their relations,
plus `->categories()->sync()` in two service controllers and both grouped
`index()` methods. Those go with the models in step 7.

### 5. Retire the five observers — the highest-risk step in the phase

`MenuItemObserver` (via `#[ObservedBy]` on the model) and `Core\MenuObserver` /
`Core\ServiceObserver` / `Core\ServiceCategoryObserver` / `Core\SiteMediaObserver`
(registered in `app/Providers/EventServiceProvider.php:39-45`). All five key off
tables you are dropping.

**Event discovery is DISABLED in this codebase.** A replacement listener that is
not explicitly registered is silently dead — it will not fail, it will simply
never fire. That is why this is the riskiest step and not the most mechanical.

For each observer: enumerate its side-effects (slug bookkeeping, cache
invalidation, `BuildState` bumps) and name where each now lives. **If a duty has
no home, STOP and raise — do not invent one here.** Known already re-homed:
`MenuItemObserver`'s three duties (spec §23.5) and
`ServiceObserver::reevaluateBooking()` (mirrored at `UserServiceController:1444`
and `StaffServiceManagementController:927`).

`MirrorMediaAssetJob` fires off `ProjectionWriter`, not an observer, so it is not
in the §9.4 list — but confirm it still dispatches.

### 6. Policies

`ServicePolicy` and `ContentSelectionPolicy` orphan when their models go;
`PolicyCoverageTest` asserts every model has a policy or a justified
`POLICY_EXEMPT` and **will** trip. Delete both policies and their `Gate::policy()`
registrations. Do NOT add a bogus exemption for a class that no longer exists.

`ContentItemPolicy` is kind-agnostic (authorises on `user_id`) — assert that still
holds for every kind in `PoolRegistry::POOLS`, as a test enumerating them rather
than an assumption.

### 7. The DROPs, then the deletions

After the migrations: delete the nine dropped tables' models, the four migration
services (`MenuBackfiller`, `ShopBackfiller`, `ServiceBackfiller`,
`ContentSelectionMigrator`) and their commands, and
`BackfillClaimedGoogleBusinessReviewsCommand` (owner ruling 2026-08-14).

**`ShopBrand` survives.** So do `ShopBackfiller`'s shop-brand paths if they are
still reachable — check rather than assume.

### 8. Deploy and verify live

Merge `feat/slice-7-teardown` → `development`, push (Laravel Cloud auto-deploys
dev). Then, on dev:

- Exercise the menu editing verbs, a Fresha refresh, an event add, a gallery read.
- `cloud env:logs partna development --minutes 10` **and** a Nightwatch scan.
- **Run `content:backfill-standalone-events`** — it has only ever been dry-run
  (2 rows would land). Until it runs, dev's two standalone events are dark on the
  public sitepage.
- **Re-run `content:backfill-menus`** — a mapper bug fix landed after the original
  backfill (`MenuProjectionMapper::badges()` was dropping every map-shaped badge;
  all 318 dishes have zero `tag_type='badge'` rows on dev). It is idempotent by
  coord.

### 9. Close the slice

- Re-run every coverage assertion post-DROP and **paste the output** into the
  parent spec's checkpoint (invariant #1).
- Finish `docs/wire-changes/2026-08-12-slice-7-teardown.md`.
- Write the **prod-reconciliation follow-up** under `docs/superpowers/plans/` —
  the migration gap, the access question, the ordering. Production is hundreds of
  commits behind, its schema has diverged from the 2026-07-26 baseline, and prod
  DB access was unconfirmed on 2026-08-12. An irreversible teardown must not be
  the operation that discovers a migration gap on a database nobody could read.
- Write the **`site.shop_brands` follow-up plan** from the spec's inventory.
- Amend `docs/2026-08-05-platforms-as-sources.md`, whose closing line still reads
  *"The program is complete"* (parent §11). That sentence has already mis-scoped
  downstream work once.
- Parent spec closing checkpoint: what shipped, what was dropped, what was
  deliberately left behind, **and that production still carries the legacy
  schema.**

## Environment hazards — these cost real time to discover

- **Other Claude sessions are live in this repo.** One holds the main checkout on
  a feature branch with uncommitted work; another is queued for prompt 8. Work in
  your OWN `git worktree`. A peer's `git reset` already silently unstaged two
  edits in this programme, leaving them on no branch at all.
- **A worktree needs a REAL vendor.** `git worktree add`, then `cp -a <main>/vendor
  ./vendor` and symlink `.env`. A symlinked `vendor` makes Composer resolve
  `$baseDir` to the main checkout, Pest's `->in('Feature')` binding never applies
  `TestCase`, and you get ~1100 fake failures with zero real signal.
- **`composer dump-autoload -o` after deleting classes**, or `class_exists()`
  resolves a stale classmap entry to a deleted file.
- **paratest takes at most ONE path argument.** `pest a/ b/ --parallel` errors.
  Use `./vendor/bin/pest --parallel` (whole suite, ~63s).
- **PHPStan:** `php -d memory_limit=1G ./vendor/bin/phpstan analyse app --no-progress`.
  The default invocation OOMs and misreports it as "severe errors". It **fails
  hard on an unmatched baseline ignore** — prune entries your deletions orphan.
- **Tests run SQLite; prod is Postgres.** Anything touching `ProjectionWriter`
  requires `composer test:pg` on a throwaway `postgres:16` container. The schema
  lane (`composer test:schema`) covers the DROPs and is NOT in `composer test`.
- **`git commit -m "…" -- <paths>`** if you dispatch concurrent agents; a bare
  commit sweeps up a peer's staged files even after an explicit `git add`.

## Coordination

A peer session is queued to run **prompt 8** (`phase-8-review-and-docs`) and is
watching `origin/development`. When you are genuinely done — drops applied,
`development` pushed, CI complete — `SendMessage` it "slice 7 done" with the merge
SHA and CI conclusion. If you stop at a hard stop instead, send a one-liner so it
is not waiting on a merge that will not come.

## If reality diverges

You are last. There is no downstream prompt to edit, so **anything contradicting a
gate is a STOP, not a note**:

| You discover | Do |
|---|---|
| A dumped row count does not match live | **STOP.** Nothing is dropped |
| An unexpected `pg_depend` dependent | **STOP.** Report it |
| A coverage assertion no longer holds | **STOP.** The owning slice reopens |
| Something still writes a table you are about to drop | **STOP.** Report the file and line |
| A parent-spec fact is wrong | Correct the spec in place — it outlives this programme |
