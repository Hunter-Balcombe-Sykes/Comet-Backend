# KICKOFF PROMPT — Slice 7: Legacy teardown

Part of `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` §7
"Slice 7". **Runs alone, last, and is irreversible.**

## ⛔ Two gates, both hard

**Gate 1 — every migrated type has its assertions on record.** Parent invariant #3:
legacy deletion is gated on the replacement being *live-verified*, never on a claim
that it is. Slices 1b, 3, 4, 5 and 6 must each have a checkpoint in the parent spec
with SQL output pasted in, and the §8.4 coverage gate green.

**Gate 2 — frontend, for the media half only.** Slice 1a deliberately kept the
legacy `gallery` and `designMedia` wire keys because Partna-App and partna-monorepo
still read them. `site_media` pools `gallery` and `content` cannot retire until both
frontends consume `pools.media`. **Confirm this by inspecting the frontend repos, not
by asking whether it "should" be done.**

**Supabase is on the Free plan: no PITR, no managed backups.** A dropped table is
gone. The only backup is the `partna-db-backup` R2 dump.

Paste everything below the line into a fresh session. It is self-contained.

---

**First action: rename this session to `slice-7-teardown`** so it is identifiable in
Remote Control instead of appearing as a machine name.

Read, in full, before touching anything:

1. `CLAUDE.md`
2. `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` — §1.4 the
   ten tables, §3 **Invariants**, §7 "Slice 7" **including the second gate**, §8.4 the
   coverage gate, **§9 in its entirety** — that section is this slice's work list.
3. Every slice checkpoint in the parent spec (§12 onward).
4. `docs/deploy/routine-deploy.md`.

## Rule zero — verify, do not trust

Parent invariant #5: **no slice may cite another slice's checkpoint as evidence for
its own claims.** That bites hardest here, because this slice's entire justification
is other slices' checkpoints. **Re-run each coverage assertion yourself.** A
checkpoint records what someone believed on the day.

### Entry gate — every one of these must pass before a single DROP

```sql
-- 1. Every legacy row has a live coord in content.*  (parent §8.4)
--    Run the per-type coverage query each slice defined. Zero uncovered rows.

-- 2. The content side is populated
SELECT kind, count(*) FROM content.items WHERE removed_at IS NULL GROUP BY 1 ORDER BY 2 DESC;
SELECT count(*) FROM content.offers;
SELECT count(*) FROM content.collections;
SELECT count(*) FROM content.collection_items;
SELECT count(*) FROM content.item_slugs;     -- MUST be >= site.item_slugs for menu_item

-- 3. Nothing still reads the legacy tables
--    grep the codebase; a green suite is not evidence.

-- 4. The slug 301 lane survived slice 4
SELECT item_type, count(*), count(*) FILTER (WHERE retired_at IS NOT NULL) FROM site.item_slugs GROUP BY 1;
SELECT count(*) FROM content.item_slugs;
```

## Scope — §9 is the work list, the DROPs are the easy part

### Unit 1 — Re-home the orphaned observers (§9.4)
These key off tables you are about to drop, and their side-effects die silently with
them: `MenuItemObserver`, `Core\MenuObserver`, `Core\ServiceObserver`,
`Core\ServiceCategoryObserver`, `Core\SiteMediaObserver`.

For each, enumerate its side-effects — slug bookkeeping, cache invalidation,
`BuildState` bumps — and confirm each is already re-homed onto the `content.*` write
path by its owning slice, or do it here. **Event discovery is disabled in this
codebase**; a replacement listener must be explicitly registered or it never fires.

### Unit 2 — Policies (§9.6)
`ServicePolicy` and `ContentSelectionPolicy` become orphaned. `PolicyCoverageTest`
asserts every model has a policy or a justified `POLICY_EXEMPT`, so it **will** trip.
`ContentItemPolicy` is kind-agnostic (authorises on `user_id`), so new kinds need no
new policy — confirm that still holds for every kind now in play.

### Unit 3 — GDPR / DSAR (§9.5)
`DataExportPayloadBuilder` streams `site.services` as a named export section and
pins `services` / `service_categories` in its declared return shape. Dropping those
tables breaks the export outright. Re-source from `content.*` and decide the section
keys — noting the 2026-08-05 precedent that DSAR allowlists deliberately **retain**
legacy keys so previously-stored payloads stay disclosable.

### Unit 4 — Analytics continuity (§9.7) — DECIDED, accept as lost
Owner decision 2026-08-12: historical `analytics.item_views` and
`content_popularity_scores` rows referencing merged-away or deleted item ids are
**accepted as lost**. Prod carries no customer data (`core.users` = 0) and dev's
analytics are test noise, so there is nothing of value to preserve and no repoint
work is warranted.

Unit 4 is therefore **documentation, not code**: record the decision in the
checkpoint, state the approximate row count affected, and confirm nothing *breaks* —
there is no FK, so orphaned analytics rows are inert rather than dangerous.

**One thing to verify rather than assume:** that no query joins analytics to
`content.items` with an inner join that would silently drop rows, or worse, error.
If one exists, it needs a null-safe path before the teardown, and that *is* code.

### Unit 5 — The DROPs, in dependency order
The ten tables from parent §1.4:

```
site.menu_item_categories, site.menu_item_platforms, site.menu_items,
site.menu_categories, site.service_category_assignments, site.services,
site.service_categories, site.shop_products, site.shop_brands,
site.content_selection
```

Children before parents. Raw SQL under `supabase/migrations/`, one statement
concern per file. Check for FK dependents, views, triggers and RLS policies on each
before dropping — `pg_depend` will tell you what the table list will not.

### Unit 6 — The media half, only if gate 2 passes
`site_media` pools `gallery` and `content` retire, and the `gallery` / `designMedia`
wire keys leave the payload. `site_media` and `media_variants` **survive** as the
byte and processing layer (parent §2.1), as do pools `design` and `documents`.

**If gate 2 fails, split the slice.** Parent §7 explicitly permits this: drop the
nine commerce tables on the coverage gate alone and leave the media lane for a
follow-up. A blocked frontend must not hold the whole teardown hostage — but neither
may it be worked around by retiring keys the frontends still read.

## What slice 1b settled for you (merged 2026-08-13)

Verify each yourself — this is a pointer, not evidence (rule zero still applies).

- **The `gallery` / `designMedia` retirement boundary has NOT moved.** 1b kept
  both keys live, exactly as 1a did. Your gate 2 is unchanged: both frontends
  must read `pools.media` before `site_media` pools `gallery` and `content` can
  retire.
- **`site.content_selection` still holds every row.** 1b's migration is
  ADDITIVE: 3 upload picks became `pool:media` pins, and 91 rows
  (85 `google-photo` + 6 `ig-*`) were recorded as dropped across 11 sites
  **without being deleted**. Dropping the table is still yours, and the decision
  not to carry those 91 is already on the record in checkpoint §15 — you do not
  need to re-litigate it, only to confirm nothing new has appeared.
- **Two items from 1b's spec §8 land in your scope, both cost issues:**
  `carryForwardPhotoUrls()` has never worked (it matches on `ref`, and refs
  rotate every fetch), and Place Details is billed twice per place — once by
  `integrations:refresh` for the legacy payload, once by the ingest lane.
  Collapsing them is the natural end of the legacy payload's life. At 500
  connected users the carry-forward defect alone is ~$600/mo.
- **`BorrowedAssetPruner` already exists** (`content:prune-borrowed-assets`,
  daily 03:50) and deletes unreferenced borrowed assets past the 30-day Places
  url expiry. It spares anything with `storage_path` OR `site_media_id` — an
  upload's asset row legitimately has no `storage_path`, so pruning on that
  alone would delete owner data. Keep both conditions if you touch it.
- **A new observer-orphan candidate:** `MirrorMediaAssetJob` fires off
  `ProjectionWriter`, not an observer, so it is not in your §9.4 list — but any
  teardown that changes how media assets are minted needs to keep it dispatching.

## Non-negotiables

- **Take a backup first.** Free plan, no PITR. Dump the ten tables to the `partna-db-backup` R2 bucket and verify the dump is readable **before** the first DROP. Record its location in the checkpoint.
- **DEV ONLY — production is out of scope for this slice** (owner decision, 2026-08-12). Apply on dev, verify, merge to `development`, and stop. Do **not** apply migrations to the prod ref, and do **not** `git push origin development:production`. See "Production is deferred" below.
- **One migration concern per file**, raw SQL, never a Laravel migration.
- **Cache invalidation** — dropping the tables changes every affected public payload. `BuildState::bump`, `site.sites.updated_at`, `CloudflareCachePurgeJob`.
- **Post-deploy:** `cloud env:logs partna development --minutes 10` **and** a Nightwatch scan, on both environments.
## Production is deferred — read this before planning

Owner decision, 2026-08-12: **this slice ships to dev and stops there.**

The reasoning, so nobody "helpfully" completes it:

- `production` is **777 commits behind `development`**, so the code for every slice
  in this programme is absent there regardless.
- `CLAUDE.md` still claims prod and dev schemas are identical on the 2026-07-26
  baseline. Dev has taken **14 migrations** since, three from this programme. Unless
  someone applied them to prod in parallel, **the schemas have diverged and that
  claim is stale.** Verify it before believing it.
- Prod database access could not be confirmed on 2026-08-12 (the Supabase MCP
  returned `password authentication failed`; the project itself is
  `ACTIVE_HEALTHY`, so it is a credentials matter, not an outage).

An irreversible teardown must not be the operation that discovers a 14-migration
gap on a database you could not read. Reconciling prod is **separate work, ordered
before any prod teardown**, and it needs its own plan.

If your recon finds prod already reconciled and readable, say so — but do not act
on it. That is a change to this slice's scope and it is the owner's call.

## If reality diverges, the direction reverses — you raise, you do not edit

Every other slice propagates discoveries **forward** to the next session. You are
last: there is no downstream prompt to edit, and nothing you find can be handed on.

So the rule inverts. **Anything that contradicts a gate is a stop, not a note.**

| You discover | Do |
|---|---|
| A coverage assertion an earlier slice recorded does not actually hold | **STOP.** Report it. That slice reopens; you do not "fix it while you are here" |
| Something still reads a table you are about to drop | **STOP.** The owning slice repoints it |
| An observer's behaviour was moved but never re-registered — **event discovery is disabled in this codebase** | **STOP.** A listener that was never registered has been silently dead since the slice that "moved" it |
| A parent-spec fact is wrong | Correct the parent spec in place. It outlives this programme and is what a future session will read |
| The frontend gate has not been met | Propose the split (parent §7 permits it) rather than proceeding or waiting indefinitely |

The one thing you **do** own propagating: this programme's closing state. Whatever
the outcome, the parent spec's final checkpoint and
`docs/2026-08-05-platforms-as-sources.md` must both end up saying what is actually
true — including what was deliberately left behind. A document claiming completion is
what started this whole programme.

## Process — stop at every gate

1. **Verify both gates.** Re-run every coverage assertion; inspect the frontend repos for `pools.media` consumption. **STOP — sign-off on the gate report.** If gate 2 fails, the deliverable is a split proposal.
2. **Backup.** Dump, verify readable, record location. **STOP — sign-off.**
3. **Spec** → `docs/superpowers/specs/2026-08-12-slice-7-teardown-design.md`, covering §9 units 1–4 before any DROP. **STOP — sign-off. Irreversible + migrations; the blocker gate applies.**
4. **Plan** (`superpowers:writing-plans`) → `docs/superpowers/plans/2026-08-12-slice-7-teardown.md`. **STOP — sign-off.**
5. **Implement** in a dedicated worktree: units 1–4 first, DROPs last. Independent review per unit.
6. **Independent review** of the whole diff. **STOP — sign-off.**
7. **Verify on dev.** Assertions pasted into the parent-spec checkpoint. Wire manifest at `docs/wire-changes/2026-08-12-slice-7-teardown.md`.
8. **Merge + push to `development`.** Full suite + PHPStan + Pint green. **STOP — explicit sign-off.** This is where the slice ends.
9. **Do not proceed to production.** Instead, write up what a prod teardown would require — the migration gap, the access question, the ordering — as a follow-up under `docs/superpowers/plans/`. That document is the slice's final deliverable.

## Definition of done

The ten tables (or nine, if split) are gone **from dev**; every observer side-effect
is re-homed and registered; `PolicyCoverageTest` green; DSAR export works from
`content.*`; the analytics decision is recorded; a verified backup exists and its
location is in the checkpoint; a prod-reconciliation follow-up is written; and the
programme's closing checkpoint states plainly what shipped, what was dropped, what
was deliberately left behind, **and that production still carries the legacy
schema.**

**Then, and only then:** amend `docs/2026-08-05-platforms-as-sources.md`, whose
closing line still reads *"The program is complete"* (parent §11). That sentence has
already mis-scoped downstream work once.
