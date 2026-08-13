# KICKOFF PROMPT — Slice 4: Menus → `content.*`

Part of `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` §7
"Slice 4". **Runs alone** — parent §4.2. It is the hardest remaining slice and it
owns a public-URL migration nothing else touches.

**Do not start until at least one commerce slice has landed.** Slice 5 (shop) is the
rehearsal; menus is the performance. Running menus first throws away the only
cheap opportunity to learn the commerce backfill pattern.

Paste everything below the line into a fresh session. It is self-contained.

---

**First action: rename this session to `slice-4-menus`** so it is identifiable in
Remote Control instead of appearing as a machine name.

Read, in full, before touching anything:

1. `CLAUDE.md`
2. `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` — §3
   **Invariants**, §4.3 concurrency, §7 "Slice 4", §8 backfill, **§9.3 the 301 lane**,
   §9.4 orphaned observers, §10.
3. The slice 5 (shop) spec, plan and checkpoint — your rehearsal. Copy what worked.
4. `app/Observers/MenuItemObserver.php` and `app/Services/Site/ItemSlugAllocator.php`
   in full.
5. `app/Ingest/Projection/MenuItemProjector.php` and
   `app/Ingest/Support/MenuRecords.php` — one projector serves DoorDash, Square and
   Uber Eats, deliberately.

## Rule zero — you may not assume any checkpoint is true

Parent invariants #5 and #6. Re-derive every figure from dev. **The parent spec's
menu counts are already stale** — it records 370 `menu_items`; on 2026-08-12 dev
showed **318**. Something changed (the menu purge #297 is the likely cause). Find out
what, and say so in your spec.

### Entry gate — run these first, paste output into the spec's §1

```sql
SELECT count(*) FROM site.menu_items;                       -- parent says 370; was 318 on 08-12
SELECT count(*) FILTER (WHERE is_manual) AS owner_authored FROM site.menu_items;  -- was 7
SELECT count(*) FROM site.menu_categories;                  -- expect 52
SELECT count(*) FROM site.menu_item_categories;             -- expect 464
SELECT count(*) FROM site.menu_item_platforms;              -- expect 370
SELECT count(*) FROM site.menus;                            -- expect 6
SELECT count(*) FROM site.menu_platform_links;              -- expect 6

-- THE 301 LANE. Every dish has a slug; these are live public URLs.
SELECT item_type, count(*), count(*) FILTER (WHERE retired_at IS NOT NULL) AS retired
FROM site.item_slugs GROUP BY 1;
SELECT count(*) FROM content.item_slugs;                    -- the destination; expect 0

-- Pricing shape you must reproduce in offers
SELECT count(*) FILTER (WHERE base_price     IS NOT NULL) AS base,
       count(*) FILTER (WHERE pickup_price   IS NOT NULL) AS pickup,
       count(*) FILTER (WHERE delivery_price IS NOT NULL) AS delivery,
       count(DISTINCT currency) AS currencies
FROM site.menu_items;

-- Has the projector ever run? Invariant #6.
SELECT count(*) FROM content.source_items WHERE kind = 'menu_item';
```

## Scope

### Unit 1 — The 301 lane is the highest-risk piece. Do it first.
`MenuItemObserver` maintains `site.item_slugs` for `ItemSlugAllocator::TYPE_MENU_ITEM`,
retaining old slugs as **301 redirects** when a dish is renamed. Every one of those
318 rows is a live public URL. `content.item_slugs` exists as the destination and
holds **0 rows**.

Parent §9.3: dropping `site.menu_items` without migrating slugs breaks every dish
permalink *and its redirect history*. Slice 4 owns this, not slice 7.

Design it explicitly:
- retired slugs migrate too, with their `retired_at`, or the 301s die
- slug **uniqueness scope** — verify whether it is per-site or global, and whether
  `content.item_slugs` enforces the same. A scope mismatch silently drops rows on
  insert.
- after the cutover, who allocates a slug for a new dish? `MenuItemObserver` dies
  with the table (§9.4), so the behaviour must be re-homed onto the `content.*` write
  path **in this slice**, not deferred.

### Unit 2 — Per-platform pricing → offers
This is the mapping the parent spec has cited from the start as proof menus fit:

| Legacy column | Becomes |
|---|---|
| `base_price` | `offers` row, `channel='base'` |
| `pickup_price` + `pickup_source` | `offers` row, `channel='pickup'`, `source_id` from the source |
| `delivery_price` + `delivery_source` | `offers` row, `channel='delivery'` |
| `currency` | `offers.currency` — nullable in legacy ("NULL for DoorDash-only dishes"), so decide the default |

`content.offers`' own DDL comment is load-bearing: *"Offers are a SET and are never
resolved to a winner: two platforms selling the same dish at different prices are
both true, and hiding one would be a lie about where the user can buy."* Do not
collapse them.

### Unit 3 — Multi-category → collections
464 `menu_item_categories` rows over 318 dishes: a dish belongs to **several**
categories. `content.collections` / `collection_items` are your target. **Slice 3a
does NOT populate them** — every one of the 61 service-category assignments belongs
to Fresha, so collections moved wholly to slice 3b. Check whether 3b or 5 has landed
first and follow their shape rather than inventing a second one; if neither has, you
are the first user and must read the DDL rather than assume.

### Unit 4 — Identity across three platforms
`MenuItemProjector` serves DoorDash, Square and Uber Eats because they land the same
doc shape. The identity resolver **unions across sources by design**, so the same
dish sold on two platforms collapses to one `content.item` with two `offers`.

**This is why parent §8.4 replaced row-count equality with coord coverage.** 318
legacy rows will legitimately become fewer items. Assert coord coverage; a
count-equality assertion here is wrong and will either fail forever or be weakened
exactly when it should bite.

### Unit 5 — `is_manual` and the rest of the payload
`is_manual` = "owner-authored dish, preserved across scrape rebuilds; a colliding
scraped dish is skipped in its favour" → manual source, and the collision rule must
survive. Also map: `badges` jsonb → `item_tags`; `rating` / `rating_count` →
`f_rated`; `images` jsonb + `image_url` → `item_media`; `description` → `f_text`;
`dd_external_id` → coord component.

### Unit 6 — Menus, dining modes, platform links
`site.menus` (6) carries a `dining_modes` shape CHECK, and `menu_platform_links` (6)
holds per-platform store URLs. Decide where each lands — collections, `f_link`,
`display_settings`, or explicitly nowhere. Anything dropped is a product regression;
name it as one.

### Unit 7 — The pool — DECIDED, menus get one
Owner decision 2026-08-12: **all four remaining commerce types get pools.** Add a
`PoolRegistry` entry (`POOLS`, `PAGE_KEYS`, `PAGE_LABELS`, plus a `SECTION_SHAPE`
block) and provision sections for existing users. `buildPools()` loops all `POOLS`,
so it ships publicly with no payload-builder change.

- **Not** in `LATEST_TAG_POOLS` — a "latest dish" is meaningless.
- Existing menu curation migrates into pool pins/excludes, the way slice 2 migrated
  `hiddenEventIds`.
- **Interaction with unit 3:** a dish belongs to several categories, and categories
  become collections. Be explicit about whether the pool selects *dishes* while
  collections group them for display, or whether categories are themselves
  selectable. The first is almost certainly right; say so rather than leaving it
  implied.
- **The `SECTION_SHAPE` for priced, undated items is settled — reuse it, do not
  invent a third.** Slice 5a decided it on 2026-08-12
  (`2026-08-12-slice-5a-shop-data-design.md` §7):

  ```php
  'menus' => ['rule' => [['op' => 'kind_is']], 'order_by' => 'recency'],
  ```

  `latest_per_auto_source` emits exactly ONE item per connection source, which
  for a menu means one dish visible and the rest hidden — the same pathology
  slice 2 hit with events and slice 1a with media.
- **Owner-chosen ordering lives in pins, not in the rule.** `SectionCandidates`
  (`:105-116`) offers exactly three orderings — `alphabetical`, `occurrence`,
  `recency` — and **none of them is "the order the owner chose"**. A hand-ordered
  list is expressed by pinning each item in `site.section_items` at its position;
  `SectionCandidates:119` excludes already-pinned ids from the auto half, so
  there is no duplication. This also satisfies parent §8.3 for free —
  `mergeInto()`'s `hasCuration` check reads `site.section_items`, so a pinned
  dish cannot be hard-deleted by a merge.

## What slice 1b changed under you (merged 2026-08-13)

Rebase onto `origin/development` before doing anything — 1b touched files this
slice builds on. Verify each claim yourself; this is a pointer, not evidence.

- **`ProjectionWriter::resolveMediaAssets()` gained two behaviours.** It now
  writes a `content.media_assets.attribution` column, and it dispatches
  `MirrorMediaAssetJob` for owned-class media. Both are **media-kind only** and
  guarded by an explicit ref-namespace allowlist (`MediaMirror::isOwnedEntry()`),
  so a `service` / `product` / `menu_item` projection is unaffected. The insert
  array in that method changed shape — if your slice touches it, rebase carefully.
- **Three stand-in schemas now carry `attribution`** and must stay in step:
  `tests/Pest.php`, `tests/Postgres/ProjectionWriterBatchingTest.php`, and
  `tests/Postgres/ProjectionIdentityKeyAtomicityTest.php`. Adding a column to
  `content.media_assets` means editing all three or your tests fail on
  `Undefined property` rather than on their assertion.
- **`app/Services/Migration/` gained two services + two commands** —
  `ContentSelectionMigrator` and `BorrowedAssetPruner`, modelled on
  `MediaUploadBackfiller`. Same `run(bool $dryRun, ?string $siteId): array`
  shape, same three-lane `invalidate()`.
- **Put migration tests in `tests/Feature/Content/`, NOT a new
  `tests/Feature/Migration/`.** A new directory under `tests/Feature/` fails
  `AuditPipelineIntegrityTest` on two counts until it is wired into
  `codebase_chunks()` in `scripts/audit/audit.sh` plus a lens scope-group. 1b hit
  this and moved rather than expanding shared audit config mid-wave.
- **Migration filename prefix `20260813000000` is consumed** by
  `content_media_assets_attribution.sql`. Pick a later prefix.
- **Every migration needs a `-- ROLLBACK:` header** (`CONVENTIONS.md` §10),
  enforced by `tests/Feature/Database/MigrationTransactionBoundaryTest.php` — not
  by the composer guards, so it only surfaces in a full run.
- **A queued job needs `$tries`, `$backoff`, `$timeout`, `failed()` AND
  `$uniqueFor`** if it is `ShouldBeUnique`, or `JobHygienePolicyTest` and
  `HorizonQueueCoverageTest` fail. `ShouldBeUnique` with no `$uniqueFor` takes a
  PERMANENT lock that a killed worker strands forever. Never redeclare
  `$afterCommit` as a typed property — `Queueable` declares it untyped and the
  clash is a fatal at class-load, which shows up as the runner exiting 2 with
  **zero output**, not as a red test.
- **Do NOT add a `position` ordering operator to solve hand-ordering.** Slices 3a
  and 5a independently reached the pins answer above and reconciled on it, and both
  explicitly rejected a new operator. The section rule DSL spans FOUR registries —
  the operator enum, `phrase()`, `EXECUTED_OPERATORS` and `ORDER_BY` — and missing
  one is a runtime 500, not a red test. The curation half already expresses
  hand-ordering, so an operator buys nothing and costs a four-place edit.


## Non-negotiables

- **The 301s are public URLs.** Breaking them is a customer-visible regression and an SEO one. Treat slug migration as blocker-gate work.
- **Cache invalidation is three lanes** — `BuildState::bump($siteId)`, touch `site.sites.updated_at`, dispatch `CloudflareCachePurgeJob`. No CI check enforces this despite the docblock claiming one.
- **`mergeInto()` hard-deletes uncurated merged-away items** (parent §8.3). With cross-source unioning this is *expected* behaviour here — but owner-authored dishes must survive. Assert with a real menu scrape after backfill.
- **Backfill is production code** under `app/Services/Migration/`, artisan command, `--dry-run`, idempotent, counts reported.
- **Schema changes are raw SQL** under `supabase/migrations/`. Pre-assigned prefix block: `20260813120000`–`20260813129999`.
- **Tests run SQLite, production is Postgres.** The `menus.dining_modes` shape CHECK and `offers_qualifier_check` must be verified against the DDL.
- **`content.offers.availability` has a vocabulary now.** It was NULL on all 14 rows and carries no CHECK; slice 5a established `in_stock` / `out_of_stock` (schema.org ItemAvailability shorthand). Use it rather than minting a second spelling.
- **The coord rule depends on whether the legacy writer rewrites rows.** Parent §8.1 prescribes `manual:{legacy_uuid}`, and that holds only where the legacy id is stable. Slice 5a had to diverge: `ShopCatalog::syncLatest()` DELETEs and re-INSERTs every row each sync, so the uuid is a fresh value per cycle and a uuid-keyed coord would mint a new item every run. **Check your writers before choosing.** Where a platform scrape rewrites `site.menu_items` rather than updating in place, key on the canonical URL — `manual:{sha1(url)}` — which also satisfies §1.7's one-coord-per-URL rule by construction.
- **The k6 harness hard-codes menu invariants** (`scripts/launch-check/k6/`). If this slice changes menu shape, re-check `seed.sql` and `jobs.js`.
- **`CloudflarePurgeService::purgeHandle()` reads `site.menu_items` directly, and its error path is 4x-amplified and un-deduped.** Verified 2026-08-13. The lookup at `:267` joins `site.menu_items`/`site.menus`/`core.users` to build `/menu/<uuid>` purge targets; when menus move to `content.*` this lookup must move with them in the same window, or every purge silently degrades to page-only and leaves dish pages stale at the edge for the full 24h TTL. Its `catch` calls a **raw `report($e)`** with no dedup (OBS-101, `cdf6f9eaf`), and `CloudflareCachePurgeJob` self-dispatches three delayed follow-ups (`partna.cache.purge_followup_schedule`), so one site save runs it four times. Wrap the catch in `App\Services\Analytics\Concerns\EscalatesRepeatedFaults` rather than leaving the raw `report()`.

## If reality diverges, update the downstream prompts — do not just note it

**A checkpoint is not a communication channel.** Parent invariant #5 forbids any
slice citing another's checkpoint as evidence, so writing a discovery only into your
own checkpoint guarantees the next session never acts on it. **Edit their prompt.**

You run late, so most of what you find is a correction to what earlier slices left
behind rather than news for a peer. Propagate it **before you merge**:

| You discover / change | Update |
|---|---|
| A parent-spec fact is now wrong — and one already is: §1.4 records 370 `menu_items`, dev showed 318 on 2026-08-12 | The parent spec's §1.4 and its revision note, in place, with what caused the change |
| An earlier slice's `SECTION_SHAPE` or collections convention did not survive contact with multi-category data | The parent spec, and `slice-7-teardown` if it changes what must be verified before dropping |
| You changed `ProjectionWriter`, `PoolResolver`, `PoolRegistry`, or `ItemSlugAllocator` | `slice-7-teardown`, plus any of 3 / 5 / 6 still unmerged |
| The slug lane needed behaviour re-homed off `MenuItemObserver` | **`slice-7-teardown` §9.4 explicitly** — it lists that observer as one to retire, and must not retire behaviour you moved rather than replaced |
| You found that a coverage assertion an earlier slice wrote does not actually hold | Raise it. Do not silently re-run it — that is slice 7's gate |
| You consumed migration filename prefixes outside your block | Whichever slice owns the block you took from |

Two rules for the edit itself:

- **Edit in place; do not append a "correction" section.** A prompt read top to
  bottom must be true.
- **Say the fact, not the story.**

If you find something that invalidates slice 7's teardown gate — a table still read,
a slug not migrated, an observer whose behaviour has no new home — that is a **stop
and raise**, not an edit. Slice 7 is irreversible and must not inherit an
optimistic gate.

## Process — stop at every gate

1. **Confirm a commerce slice has landed.** If slice 5 has not merged, stop and say so.
2. **Recon + entry gate**, including reconciling the 370 → 318 discrepancy. **STOP — sign-off.**
3. **Brainstorm** (`superpowers:brainstorming`) — slug migration and unit 6 are genuine decisions.
4. **Spec** → `docs/superpowers/specs/2026-08-12-slice-4-menus-design.md`, with the slug lane designed in full. **STOP — sign-off. Public URLs + a migration; the blocker gate applies.**
5. **Plan** (`superpowers:writing-plans`) → `docs/superpowers/plans/2026-08-12-slice-4-menus.md`. **STOP — sign-off.**
6. **Implement** in a dedicated worktree, per unit: plan → implement → independent review → tick. Slug lane first.
7. **Independent review** of the whole diff. **STOP — sign-off.**
8. **Verify on dev.** Live SQL assertions pasted into a parent-spec checkpoint, including a proven 301 for a renamed dish. Wire manifest at `docs/wire-changes/2026-08-12-slice-4-menus.md`.
9. **Merge + push.** Rebase onto `development`, full suite + PHPStan + Pint green, **STOP — explicit sign-off**, then merge and push. Never push to `production`.

## Definition of done

Every live dish is represented in `content.*` with its multi-category membership,
its full offer set across channels and platforms, its badges, rating and images;
every slug **including retired ones** lives in `content.item_slugs` and a renamed
dish still 301s; slug allocation for new dishes is re-homed off `MenuItemObserver`;
owner-authored dishes survive a subsequent scrape; coverage gate green (coord
coverage, **not** row counts); checkpoint and wire manifest committed. The menu
tables are **not** dropped — that is slice 7.
