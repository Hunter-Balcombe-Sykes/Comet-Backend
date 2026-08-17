# Slice 7, drop phase — gate report (2026-08-17)

Kickoff: `2026-08-17-slice-7-drops-KICKOFF-PROMPT.md`, order of work steps 1–2.
Run against **dev** (`glncumufgaqcmqhzwrxm`). Production not touched, not named in
any tool call.

**VERDICT: STOP. The coverage gate is RED and the DROPs are therefore not
pre-authorised.** Nothing has been dropped, dumped, deployed or pushed. No
migration applied. Work done is read-only verification plus this document.

The owner ruling was *"the DROPs are pre-authorised **if every gate is green**;
STOP on any failure or surprise — a count mismatch, an unexpected `pg_depend`
dependent, a reopened coverage assertion."* A coverage assertion has reopened.

---

## 1. Why the gate reopened — dev kept writing

Every figure in the 2026-08-16 entry-gate report is stale, exactly as the kickoff
warned. The cause is structural, not incidental: **the slice-7 cutover lives on an
unmerged branch, so dev is still running pre-cutover code, and its legacy write
lanes are still live.** `MenuFetchJob` ran a scrape on `ollies` at
`2026-08-16 23:03:42+00` — after the gate was taken — and minted legacy rows that
the content lane has never seen.

| Table | Gate 08-16 | Live 08-17 | Δ |
|---|---|---|---|
| `site.menu_items` | 318 | 293 | −25 |
| `site.menu_item_categories` | 402 | 358 | −44 |
| `site.menu_item_platforms` | 318 | 310 | −8 |
| `site.menu_categories` | 44 | 40 | −4 |
| `site.services` (live) | 77 | 54 | −23 |
| `site.service_categories` (live) | 16 | 16 | — |
| `site.service_category_assignments` | 61 | 61 | — |
| `site.shop_products` | 51 | 51 | — |
| `site.content_selection` | 95 | 95 | — |
| `site.shop_brands` *(deferred)* | 9 | 10 | +1 |
| `site.item_slugs` `item_type='event'` | 11 | 6 | −5 |

A scrape rebuild deletes and re-inserts, so the net counts fell while **new,
uncovered rows appeared**. Net movement hid the hole; only the per-row coord
derivation exposes it.

## 2. Coverage, re-derived here — not cited

Every assertion below was re-run against dev by this session (invariant #5).
Menu coords re-derived in SQL with `pgcrypto`, mirroring
`MenuProjectionMapper::coordFor()` = `manual:menu:{menu_id}:{sha1(normalizeName)}`
and `NormalizesMenuItemNames::normalizeName()` (lowercase → non-alphanumerics to
single spaces → trim).

| Assertion | Legacy live | Covered | Uncovered | Verdict |
|---|---|---|---|---|
| Menu dishes | 293 | 283 | **10** | 🔴 **RED** |
| Menu categories | 40 | 38 | **2** | 🔴 **RED** |
| Menu memberships (item×category) | 358 | 347 | **11** | 🔴 **RED** |
| Menu platforms | 310 | 318 landed | 0 | 🟢 superset |
| Services — owner half (`source IS NULL`) | 18 | 18 | 0 | 🟢 |
| Services — Fresha half | 36 | 36 | 0 | 🟢 |
| Service categories | 16 | 16 | 0 | 🟢 |
| Service category assignments | 36 live (25 on deleted services) | 36 | 0 | 🟢 |
| Shop products | 51 | 47 | 4 | 🟡 **benign — see §2.2** |
| Content selection — uploads | 3 | 3 | 0 | 🟢 |
| `content.item_merges` | 0 | — | — | still unexercised (§25.6) |

### 2.1 The 23 rows that exist ONLY in legacy — the actual blocker

All 23 are on `ollies`, all stamped `2026-08-16 23:03:42–43+00`, all
`is_manual = false`, and their coords **do not exist in `content.source_items` at
all** (`coord_exists_at_all = false` — not removed, never written):

- **10 dishes** — `STRAWBERRY`, `BISCOFF & CHOCOLATE`, `BLUEBERRY`,
  `FILLET OF FISH`, `LAMB RAGU`, `CHICKEN SCHNITZEL`, `EGGPLANT SCHNITZEL`,
  `'23 DEEP WOODS CHARDONNAY WA`, `'23 MULLINE PINOT NOIR VIC`,
  `MOONDOG OLD MATE PALE ALE`
- **2 categories** — `Menu`, `EXPRESS LUNCH`
- **11 memberships** binding those dishes to those categories

**Dropping `site.menu_items` today destroys these 23 rows with no recovery
path.** That is precisely the unrecoverable, silent loss the slice's governing
law exists to prevent — and it is the same failure mode the spec §1 describes,
arriving through a door nobody had modelled: not "the backfill was never run",
but "the backfill was run, and then dev wrote more".

### 2.2 The 4 shop products are the OPPOSITE case, and are benign

Worth stating explicitly so it is not conflated with §2.1. The 4 uncovered
`site.shop_products` rows collapse to **2 distinct coords** (three rows share
`.../products/explorer-cap-copy` across three duplicate `shop_brands` rows for the
same store — the coord is per-user, not per-brand). Both coords **do exist** in
`content.source_items` with `removed_at IS NULL`, but their `content.items` rows
carry `removed_at` set (2026-08-14 and 2026-08-16).

So here the **content lane is AHEAD of legacy**: it deliberately retired those
products and `site.shop_products` simply has no delete path to follow. Dropping
loses nothing. This is legacy residue, not a coverage hole.

The distinction is the whole point: §2.1 is content *behind* legacy (data loss on
drop); §2.2 is content *ahead* of legacy (no loss). A raw "47/51" number cannot
tell the two apart.

## 3. `pg_depend` — both queries re-run, and they are CLEAN

Exactly as recorded, no surprises:

- **Inbound foreign keys from outside the drop set: ZERO.**
- **Dependent objects: exactly ONE** — `site.public_site_payload`, a VIEW,
  depending on `site.services`, `site.service_category_assignments` and
  `site.service_categories`. This is the known Task 26a blocker.

The replacement migration
`supabase/migrations/20260817000000_public_site_payload_services_from_content.sql`
exists on the branch and is **applied nowhere**. It remains correct and must land
before any DROP — but it is not what stops this phase.

## 4. A second, independent defect — the kickoff's own step ordering

Found by following the steps rather than reading them. It would have fired even
had the coverage gate been green.

**Step 7 deletes `MenuBackfiller` and its `content:backfill-menus` command, and
drops `site.menu_items`. Step 8 then instructs: "Re-run `content:backfill-menus`".**

`MenuBackfiller` reads `site.menu_items` (`:67`, `:316`),
`site.menu_item_categories` (`:340`) and `site.menu_item_platforms` (`:361`) —
three drop-list tables. After step 7 the command is both **deleted** and would
**42P01** on a dropped table. The same applies to the step-8 instruction to run
`content:backfill-standalone-events`, which step 7 leaves in place but which runs
against a schema step 7 has already changed.

This is not pedantry. The 10 uncovered dishes in §2.1 are **exactly what that
re-run was supposed to land** — the step-8 backfill is the mechanism that would
have closed the hole, and step 7 removes it first.

## 5. A third finding — the 4c sweep is blind to Eloquent

The kickoff's sweep command is `grep -rn "table('site\.<table>'" app/`. Re-run
verbatim, it returns **exactly the five sites** the kickoff names, unchanged:

| Site | Status |
|---|---|
| `StaffServiceCategoryManagementController.php:441` | confirmed |
| `MenuFetchJob.php:1094` | confirmed |
| `MenuFetchJob.php:1121` | confirmed |
| `MenuDashboardPayload.php:189` | confirmed |
| `FoodContentProbe.php:36` | confirmed |

**But that grep only matches raw query-builder calls.** It cannot see Eloquent
model reads, and those are neither few nor inert:

| Model | Files | Static-call refs |
|---|---|---|
| `Service` | 10 | 26 |
| `ServiceCategory` | 8 | 12 |
| `MenuItem` | 4 | 3 |
| `MenuCategory` | 1 | 2 |
| `ShopProduct` | 1 | 4 |
| `MenuItemPlatform` | 1 | 0 |

The kickoff does acknowledge these ("the Eloquent-model reads are separate and
larger … those go with the models in step 7"), but frames them as deletions that
accompany model removal. At least one is a **live read lane that has never been
cut over**:

> `app/Services/Cache/UserCacheService.php:230` and `:279` —
> `professionalServices()` composes the services list from `ManualServiceItems`
> (`content.*`) for the owner half and a live `Service::query()` against
> `site.services` for the **Fresha half**, including
> `->with('categories:id')`, which reads `site.service_category_assignments` and
> `site.service_categories`.

The kickoff's status table records *"Fresha `payload.selection` off
`site.services` — done"*. That is the **blob** (Unit C / spec D3). The Fresha
half's **reads** are a different lane, left live on purpose by slice 3a's manifest
*"the Fresha half's reads/writes … continue against the live table"* until slice
7. Deleting the model is not a cutover; the same ordering law applies to a read
path as to a write path.

Scope, not a blocker in itself — but it means step 7 is materially larger than
"delete the models", and it must be sized before it is executed.

## 6. Recommendation — reorder, do not force

The cleanest resolution honours the slice's own law and needs no new design. It
inverts the last two steps:

1. **Land the view migration** (`20260817000000_…`) on dev.
2. **Merge → `development`, deploy dev.** The cutover code goes live; the legacy
   write lanes stop minting uncovered rows. *This is the step that stops the hole
   growing — every day dev scrapes, §2.1 gets bigger.*
3. **Cut over the remaining Fresha read lane** (§5) if the deploy does not already
   cover it.
4. **Run `content:backfill-menus`** — still present, tables still present.
   Idempotent by coord; lands the 10 dishes, 2 categories, 11 memberships.
5. **Run `content:backfill-standalone-events`** (dry-run only to date, 2 rows).
6. **Re-derive the full coverage gate. Require green.**
7. **Backup gate** — `pg_dump`, restore-verify, exact per-table counts, taken
   against the schema as it stands at that moment.
8. **Then** observers, policies, DROPs, model/command deletions — as one unit.
9. Second deploy for the drop-phase code, then close-out.

Cost: two deploys instead of one. That is the whole price, and it buys back the
ordering guarantee the programme has held for seven slices.

**What must NOT happen:** dropping now and backfilling after. The backfiller reads
the dropped tables, and 23 rows have no other writer.

## 7. State at the end of this report

- No `DROP` written, no migration applied, no `pg_dump` taken (correctly — it
  would be stale by the time it is spent), no branch merged, no push.
- Branch `feat/slice-7-drops` cut from `origin/feat/slice-7-teardown` at
  `8e4823c9a` in worktree `.worktrees/slice-7-drops`. Contains this document only.
- `legacyIdsFor()` confirmed **gone** from `app/` (kickoff §4, first oracle).
- `LegacyServiceSortOrder` confirmed **still present**, reached from
  `UserServiceController:890` and `:1139` — the second oracle, still to do, and
  correctly still to do, because it must die *with* the table.
- All five observers confirmed present and registered
  (`EventServiceProvider:39-45` + `#[ObservedBy]`). `ContentSelectionPolicy`,
  the `ContentSelection` model and `ContentSelectionService` are already gone;
  `ServicePolicy` remains.
- No session named `phase-8-review-and-docs` was visible to `ListAgents`, so no
  peer was notified. Whoever is waiting on `origin/development` should be told
  that the merge is **not** coming today.

## 8. Corrections owed to the parent documents

Per the kickoff's *"a parent-spec fact is wrong → correct the spec in place"*:

- The entry-gate report's closing line — *"they will be stale by the time the
  DROPs run"* — was right, and should be promoted from a footnote to the
  governing constraint: **on a live dev environment, a coverage gate is valid
  only until the next scrape.** The gate must be taken inside the same window as
  the DROP, after the write lanes are dead.
- The kickoff's status table conflates *"the Fresha blob is composed from
  `content.*`"* with *"the Fresha lane is off `site.services`"*. It is the former
  only. See §5.
