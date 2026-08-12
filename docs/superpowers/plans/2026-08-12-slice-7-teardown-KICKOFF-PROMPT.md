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

### Unit 4 — Analytics continuity (§9.7)
`mergeInto()` hard-deletes merged-away items, and historical `analytics.item_views` /
`content_popularity_scores` rows reference ids that migration merged or deleted.
State explicitly whether historical analytics are repointed, orphaned, or accepted
as lost. This is a decision to record, not a bug to fix silently.

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

## Non-negotiables

- **Take a backup first.** Free plan, no PITR. Dump the ten tables to the `partna-db-backup` R2 bucket and verify the dump is readable **before** the first DROP. Record its location in the checkpoint.
- **Dev first, then prod.** Apply on dev, verify, merge, deploy, verify again, and only then apply to prod against the ref you mean. `git push origin development:production` **is** the deploy — there is no promote step and no CI gate on that push.
- **One migration concern per file**, raw SQL, never a Laravel migration.
- **Cache invalidation** — dropping the tables changes every affected public payload. `BuildState::bump`, `site.sites.updated_at`, `CloudflareCachePurgeJob`.
- **Post-deploy:** `cloud env:logs partna development --minutes 10` **and** a Nightwatch scan, on both environments.
- **Prod push requires explicit human sign-off**, separately from the dev merge.

## Process — stop at every gate

1. **Verify both gates.** Re-run every coverage assertion; inspect the frontend repos for `pools.media` consumption. **STOP — sign-off on the gate report.** If gate 2 fails, the deliverable is a split proposal.
2. **Backup.** Dump, verify readable, record location. **STOP — sign-off.**
3. **Spec** → `docs/superpowers/specs/2026-08-12-slice-7-teardown-design.md`, covering §9 units 1–4 before any DROP. **STOP — sign-off. Irreversible + migrations; the blocker gate applies.**
4. **Plan** (`superpowers:writing-plans`) → `docs/superpowers/plans/2026-08-12-slice-7-teardown.md`. **STOP — sign-off.**
5. **Implement** in a dedicated worktree: units 1–4 first, DROPs last. Independent review per unit.
6. **Independent review** of the whole diff. **STOP — sign-off.**
7. **Verify on dev.** Assertions pasted into the parent-spec checkpoint. Wire manifest at `docs/wire-changes/2026-08-12-slice-7-teardown.md`.
8. **Merge + push to `development`.** Full suite + PHPStan + Pint green. **STOP — explicit sign-off.**
9. **Production.** Apply migrations to the prod ref, deploy, verify, log + Nightwatch scan. **STOP — separate explicit sign-off before this step.**

## Definition of done

The ten tables (or nine, if split) are gone from dev and prod; every observer
side-effect is re-homed and registered; `PolicyCoverageTest` green; DSAR export
works from `content.*`; the analytics decision is recorded; a verified backup exists
and its location is in the checkpoint; the programme's closing checkpoint states
plainly what shipped, what was dropped, and what — if anything — was deliberately
left behind.

**Then, and only then:** amend `docs/2026-08-05-platforms-as-sources.md`, whose
closing line still reads *"The program is complete"* (parent §11). That sentence has
already mis-scoped downstream work once.
