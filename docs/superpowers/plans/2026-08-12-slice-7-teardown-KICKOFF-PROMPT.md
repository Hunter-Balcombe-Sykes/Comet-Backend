# KICKOFF PROMPT — Slice 7: Legacy teardown

Part of `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` §7
"Slice 7". **Runs alone, last, and is irreversible.**

## ⛔ Two gates, both hard

**Gate 1 — every migrated type has its assertions on record.** Parent invariant #3:
legacy deletion is gated on the replacement being *live-verified*, never on a claim
that it is. Slices 1b, 3, 4, 5 and 6 must each have a checkpoint in the parent spec
with SQL output pasted in, and the §8.4 coverage gate green.

**Gate 2 — REPLACED by owner override (2026-08-14).** The original gate (both
frontends consuming `pools.media` before the media half retires) was verified
genuinely unmet — `apps/pages` still reads `designMedia`, nothing reads
`pools.media` (convergence-log F17). The owner has ruled the **frontend may break
and will be REBUILT afterwards, not repaired**. There is therefore no wire
compatibility to preserve: `designMedia`, `gallery`, `siteImages` and the rest of
slice 1a's compatibility surface are **deleted outright, not dual-served**. No
frontend inspection gates this slice any more.

**Supabase is on the Pro plan (upgraded 2026-08-14, verified): daily managed
backups exist.** The `pg_dump` per-table **exact-count** gate below stays mandatory
regardless — it is the surgical control that proves what is being dropped, and a
daily backup is not a substitute for it.

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

**Added by slice 6:** the export now also streams `content.source_stats`, with
`summary_text` omitted from the select — Google's prose summary is third-party derived and
is withheld, while `rating_avg` / `rating_count` are business facts about the account
holder and are included. `DsarPayloadFilter::WITHHELD_DISCLOSURE` names both halves. If you
move or drop either table, the disclosure string and the omission must move with it.

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

**`site.shop_brands` is a live write target, not inert — corrected 2026-08-13 by
slice 5b's entry gate, and this is different from `site.shop_products`.**
`ShopController` still writes it directly (`updateOrCreate` at `:317`,
`firstOrCreate` at `:929`, `delete` at `:869`), and
`ShopContentWriter::upsertStore()` takes the `ShopBrand` model as its identity
anchor — it is not merely a legacy table nothing touches. Dropping it under that
writer breaks every subsequent shop write, not just old reads. **Re-homing
`ShopContentWriter` off the `ShopBrand` model is part of this unit, not a
follow-up** — do it in the same window as the DROP, the same discipline this
unit already applies to the `CloudflarePurgeService` lookups below.

**`CloudflarePurgeService::purgeHandle()` will page you 12 times per site save if
a DROP lands under it.** Verified 2026-08-13. That method runs three independent
lookups — shop product handles (`content.collection_items`/`collections`/
`f_catalog`, repointed by 5a), menu items (`site.menu_items`, dropped by this
unit), and event ids — and each `catch` calls a **raw `report($e)`** with no
dedup (OBS-101, `cdf6f9eaf`). `CloudflareCachePurgeJob::handle()` then
self-dispatches three delayed follow-ups (`partna.cache.purge_followup_schedule`
= `[120, 300, 900]`), so one site save runs `purgeHandle()` four times.

Three raw reports × four invocations = **up to 12 un-deduped Nightwatch reports
per site save, per site**. Dropping `site.menu_items` trips the menu lookup on
every purge until the lookup is repointed, and a save-storm turns that into a
monitoring flood that reads as an infrastructure outage rather than a code
defect.

Repoint or delete each lookup **in the same migration window as its DROP**, and
wrap the catches in `App\Services\Analytics\Concerns\EscalatesRepeatedFaults`
(used by ten services already) rather than leaving the raw `report()`. Neither
5a nor 5b touched this file; it is named here because this unit is what makes it
fire.

### Unit 6 — The media half (gate 2 replaced by the owner override, 2026-08-14)
`site_media` pools `gallery` and `content` retire, and the `gallery` / `designMedia`
/ `siteImages` wire keys leave the payload — deleted outright, not dual-served
(owner override: the frontend will be rebuilt, not repaired). `site_media` and
`media_variants` **survive** as the byte and processing layer (parent §2.1), as do
pools `design` and `documents`. No split is needed for the media lane; the
coverage gate (gate 1) is the only gate on this unit.

## What slice 1b settled for you (merged 2026-08-13)

Verify each yourself — this is a pointer, not evidence (rule zero still applies).

- **The `gallery` / `designMedia` retirement boundary has NOT moved.** 1b kept
  both keys live, exactly as 1a did. (Gate 2 has since been replaced by the
  2026-08-14 owner override — the keys are deleted outright; see the gates
  section above.)
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

## What slice 3b settled for you (verified on dev 2026-08-13)

Verify each yourself — this is a pointer, not evidence (rule zero still applies).

- **There are 18 service routes on the owner surface, not 17.**
  `UserServiceCategoryController` has **seven**, not six: `index`, `store`, `show`,
  `update`, `destroy`, `reorder`, `restore`. Any inventory that says 17 inherits an
  undercount, and a teardown that works from it leaves a live route pointed at a
  dropped table.
- **The staff surface is two controllers, not one.**
  `StaffServiceManagementController` (9 methods) and
  `StaffServiceCategoryManagementController` (7 routes, split across **two** staff
  middleware groups — `index`/`show`/`restore` for any role, the five write verbs
  under `staff.admin`). Both were cut onto `content.*` by 3b. The category controller
  is at `app/Http/Controllers/Api/Staff/UserSiteManagement/`, which is not where
  anything predicted.
- **`StaffServiceCategoryManagementController::index()` deliberately merges both id
  spaces** — `content.collections` plus `site.service_categories` — because Fresha
  services still file into the legacy category table until you drop it. **That merge
  is yours to remove**, and removing it is not optional cleanup: left in place it
  queries a dropped table.
- **`anseo-studio`'s Fresha connection has no ingest source at all.** Confirmed live
  on dev during 3b's verification. `SourceProvisioner::freshaSlug()` matches only
  `fresha.com/…/a/<slug>`, and that connection's row is a `book-now/…?pId=` URL. So a
  coverage assertion that assumes one source per connection will show a hole there
  which is **not** a backfill failure. Decide whether the matcher widens or the row is
  written off, and say which — do not treat it as noise.
- **`Pull.config` carries a third key: `selection_ref` (`string|null`)**, beside
  `scope` and `scope_n`. A connector treats `null` as **land-nothing**, never
  fetch-everything. `'storewide'` is a reserved token, not an id. Relevant to you
  because a source with `selection_ref = NULL` legitimately has `run_seq = 0` and
  zero landed rows — that is the design working, not an uncovered type.
- **`ProjectionWriter` owns the shared `collections` writer.** `position` on a
  collection is a **seed** — written on insert, never updated, because an owner can
  reorder categories and a scheduled run must not snap that back. Anything you
  re-home must preserve that.
- **There is no observer on `content.collections` and no CI check for cache
  invalidation**, so it is a hand-maintained caller obligation across every slice.
  Your §9.4 observer re-homing must not assume the `content.*` side already has an
  observer to inherit from — it does not.
- **Legacy row counts on dev, unchanged by 3b:** 18 live / 3 deleted
  `site.services WHERE source IS NULL`, 59 live / 2 deleted `source = 'fresha'`.
  The `source IS NULL` rows are the shadow of the 18 owner services that now live
  authoritatively in `content.*`; nothing public reads them.

### DEPLOY STEP — nothing lands until sync has run

After a slice that adds a provisioning field like `selection_ref` deploys to an
environment, **nothing lands until `SourceProvisioner::sync()` has run for each
affected connection.** On dev, every Fresha source had `selection_ref = NULL` after
3b's migration and the first scheduled run would have landed nothing, everywhere,
while reporting success. For you this is a **gate-1 hazard**: a coverage query run
before that sync reads as an empty replacement and would either block the teardown
falsely or, worse, be explained away.

**Do not reach for `ingest:backfill-sources` unqualified.** Its dry-run on dev showed
it would process **80 connections across every connector**, bumping `next_attempt_at`
on unrelated sources. Scope the sync to the connections that actually need it.

### For whoever runs your merge gate

`./vendor/bin/phpstan analyse` in a worktree dies with
`Child process error (exit code 255): while running parallel worker` and reports
"Result is incomplete because of severe errors", and it OOMs at the default 128M.
**Neither failure looks like what it is.** Use:

```bash
php -d memory_limit=1G ./vendor/bin/phpstan analyse <path> --no-progress --debug
```

`--debug` disables parallelism. Under that invocation the real errors print normally.
## What slice 6 leaves you (reviews, 2026-08-13 — dev only, not yet live-verified)

Verify each yourself — rule zero still applies.

- **`content.source_stats` is new, and the public wire depends on it.** PK `source_id`,
  FK → `content.sources` CASCADE. It carries the Google place's `rating_avg`,
  `rating_count` and `summary_text`, and `pools.reviews.stats` is now the **only** place
  those publish: slice 6 removed `reviews`, `reviewSummary`, `rating` and `reviewCount`
  from `PublicIntegrationConnectionResource`'s `google-business` allowlist. It is also the
  first **source-level** fact in `content.*` — `ProjectionWriter` gained a source-scoped
  write path that upserts one row per source, with no `item_id`. Any teardown reasoning
  about what a projection writes must account for it.
- **The `google_business` `profile` stream is redundant, and "unused" is the wrong word.**
  It has 3 `record_versions` and 0 `source_items`. Its intended consumer was the
  field-bindings lane, created by `20260728150000` and deliberately dropped by
  `20260805110000`; the identity fold it existed for runs via `IdentitySync` off
  `IntegrationConnectionObserver::saved`. **Retire it or justify keeping it — do not drop
  it silently**, and do not "tidy up" by moving `source_stats` onto it. Slice 6 put the
  aggregates on the `reviews` stream specifically to avoid a public-wire dependency on
  this stream.
- **`PruneOrphanedReviewPiiCommand` still depends on `content.f_review`**, and is now the
  only retention mechanism for reviewer identity. Slice 6 removed the two ungoverned
  copies (`content.items.headline_cache`, `content.f_text.headline`) and the projector no
  longer writes them. A teardown that changes how review items are written or retired must
  keep that command's guarantee true — its docblock now says so explicitly.
- **`BackfillClaimedGoogleBusinessReviewsCommand` is vestigial** (slice 6 spec §5.4). The
  lane it backfills is retired. Slice 6 did not delete it; deciding its fate is yours.

### Two findings slice 6 found and deliberately did NOT fix

Both are real. Neither is a leftover to tidy — read the reasoning before acting.

- **A fourth pin path exists on exclusion-only pools, and it is currently inert.** The
  `EXCLUDE_ONLY_POOLS` guard fires only when `PoolRegistry::poolForSectionKey()` resolves,
  i.e. only for `pool:`-prefixed section keys. A **custom `collection` section** whose rule
  is `[{op:'kind_is', values:['review']}]` can therefore have review items pinned into it —
  reachable via `POST /api/site/sections` then `PUT /api/site/sections/{id}/items/{item}`,
  and by `PATCH`-renaming a section's key off `pool:reviews`. It was left ungated **because
  no public controller reads `site.documents`**, so nothing renders it. If your teardown
  makes a custom-section lane publicly readable, the guard has to move from the section key
  to the item's kind, and that is a code change with a legal edge (§4.3 of slice 6's spec:
  owner curation of third-party reviews is the thing the privacy clause leans on).
- **Doc-hash churn on the reviews stream is accepted, not a bug.** The place aggregates
  ride in every review record's doc while the reviews stream declares `volatile: []`, so
  `place_rating_count` ticking up re-lands every review record. **Do not "fix" this by
  declaring those paths volatile.** `RecordView`'s docblock records why: a path both
  declared volatile AND read by a projector is a silent correctness hole — change
  detection would ignore the aggregates and `content.source_stats` would freeze forever.

## Non-negotiables

- **Take a backup first.** Supabase is Pro (daily backups since 2026-08-14), but the surgical control is still yours: dump the ten tables to the `partna-db-backup` R2 bucket, verify the dump is readable, and assert dumped row counts exactly match live counts per table **before** the first DROP. Record its location in the checkpoint.
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
| A frontend still reads a key you are deleting | Proceed — the 2026-08-14 owner override says the frontend breaks and gets rebuilt; record which keys died in the wire manifest |

The one thing you **do** own propagating: this programme's closing state. Whatever
the outcome, the parent spec's final checkpoint and
`docs/2026-08-05-platforms-as-sources.md` must both end up saying what is actually
true — including what was deliberately left behind. A document claiming completion is
what started this whole programme.

## Process — stop at every gate

1. **Verify gate 1.** Re-run every coverage assertion yourself (gate 2 is replaced by the 2026-08-14 owner override — no frontend inspection required). **STOP — sign-off on the gate report.**
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
