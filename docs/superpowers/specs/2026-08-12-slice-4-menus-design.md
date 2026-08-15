# Slice 4 — Menus → `content.*` (design)

Parent: `2026-08-11-content-pool-convergence-design.md` §7 "Slice 4".
Kickoff: `docs/superpowers/plans/2026-08-12-slice-4-menus-KICKOFF-PROMPT.md`.
Runs alone (parent §4.2). Dev only; production is out of scope for every unit.

This slice also carries **Phase 5's live menu proof** (convergence session prompt 4):
provision `uber_eats` / `doordash` / `square`, run them, and prove
`content.source_items` of kind `menu_item` land and surface on the wire.

---

## 1. Entry gate — measured on dev, 2026-08-15

Every figure below was read off `glncumufgaqcmqhzwrxm` at slice entry. Parent
invariant #5: nothing here is cited from another slice's checkpoint.

```sql
SELECT count(*) FROM site.menu_items;                      -- 318
SELECT count(*) FILTER (WHERE is_manual) FROM site.menu_items;  -- 7
SELECT count(*) FROM site.menu_categories;                 -- 44
SELECT count(*) FROM site.menu_item_categories;            -- 402
SELECT count(*) FROM site.menu_item_platforms;             -- 318
SELECT count(*) FROM site.menus;                           -- 5
SELECT count(*) FROM site.menu_platform_links;             -- 5
SELECT count(*) FROM content.item_slugs;                   -- 16 (all current, 0 retired)
SELECT count(*) FROM content.source_items WHERE kind='menu_item';  -- 0
SELECT count(*) FROM content.items;                        -- 749
```

The 301 lane, by type:

```
item_type   total  retired
event         11        0
menu_item    318        0
```

Pricing shape:

```
base 317 | pickup 34 | delivery 315 | distinct currencies 1 | currency NULL 93
```

Per menu:

| handle | items | categories | platform links | dining_modes | content_source |
|---|---|---|---|---|---|
| fable-sevenrun | 156 | 15 | 1 | `["DELIVERY","PICKUP"]` | uber-eats |
| ollies | 65 | 14 | 2 | `["DELIVERY"]` | uber-eats |
| fred-sarson | 62 | 6 | 1 | NULL | uber-eats |
| broken-oven | 32 | 8 | 1 | `["DELIVERY","PICKUP"]` | uber-eats |
| doc-pizza-…-carlton | 3 | 1 | 0 | NULL | scan |

Sums to 318 / 44 / 5 exactly.

Slug integrity, verified rather than assumed:

```
orphan menu_item slugs (item_key with no live dish)   0
live dishes with no slug                              0
max slug rows per dish                                1
soft-deleted menus                                    0
```

### 1.1 The 370 → 318 reconciliation

The kickoff asked for this explicitly. **It is one whole menu tree, deleted
between 2026-08-11 and 2026-08-12** — not a scrape shrinking:

| Table | 2026-08-11 (spec §1.4, commit `8fb83f998`) | now | delta |
|---|---|---|---|
| `site.menu_items` | 370 | 318 | −52 |
| `site.menu_categories` | 52 | 44 | −8 |
| `site.menu_item_categories` | 464 | 402 | −62 |
| `site.menu_item_platforms` | 370 | 318 | −52 |
| `site.menus` | 6 | 5 | −1 |
| `site.menu_platform_links` | 6 | 5 | −1 |

**It cannot have been a scrape.** `max(last_fetched_at)` across all five
surviving menus is 2026-08-06, five days before the 370 was measured.

**The account is `showcase-eats`** (created 2026-07-27). It still holds four
live order-surface connections — `doordash.order`, `menulog.order`,
`square.order`, `uber_eats.order` — and now has no menu, no `site.item_slugs`
rows of any type, and no menu section.

**The delete went through application code, not raw SQL.** Zero orphaned slug
rows survive, and `site.item_slugs` has no FK to `site.menu_items` — its only
FK is to `core.users`. So nothing at the database level would have cleaned them
up. Every application path that removes dishes
(`MenuFetchJob::persist()`, `::clearScrapedContent()`, `MenuItemObserver::deleted`)
calls `ItemSlugAllocator::forget*()`; a raw `DELETE` would have stranded 52
rows. The shape matches `clearScrapedContent()` (items → categories → platform
links → soft-delete the menu, slugs reconciled post-commit) followed by
`PurgeSoftDeleted`, which carries `Menu::class` in `PURGE_HANDLED`.

**What is not recoverable:** which of those two steps was triggered by what.
There is no audit table covering menus (`audit` holds only auth-factor,
export, handle-change, moderation, staff and user-deletion logs) and Cloud logs
do not reach back that far. Recorded as "cause not determined" rather than
guessed at.

**Consequence for this slice:** the entry figures in §1 are the ones to migrate.
The parent spec's §1.4 is corrected in place by this slice (see §13).

**The latent hazard is real and stays.** The missing FK means any future
non-Eloquent delete of `site.menu_items` strands slug rows permanently
(`slugs:prune-retired` only sweeps rows already marked retired). It cost
nothing here because nothing took that path. Slice 4 does not add the FK —
the table is dropped in slice 7 — but §3.4 pins the invariant on the
`content.*` side, where it will matter for the next decade.

---

## 2. Four kickoff premises the code refutes

Rule zero cuts both ways: the kickoff's own claims get re-derived too.

### 2.1 `content.item_slugs` already has an allocator, and it already anticipates menus

The kickoff (and `ItemSlugAllocator`'s docblock) say `content.item_slugs` has
"same table shape, no allocator today". **False since slice 2.**
`app/Services/Content/ContentItemSlugAllocator.php` (306 lines) implements
`ensureCurrent()`, `lookupCurrent()`, `forget()`, retire-and-promote, the
rename-back arm and the collision-suffix guard. `PruneRetiredItemSlugs` sweeps
it, `BackfillContentItemSlugs` seeds it, `PoolResolver` reads it.

Its gate is one const:

```php
public const SLUGGED_KINDS = ['event'];
```

whose own docblock says: *"the lane this registry replaced (site.item_slugs)
covered exactly events and menu items… widening it is one edit and the two
cannot disagree."*

And `ProjectionWriter::refreshItemCaches()` (`:1650`) already mints a slug for
every landed item whose kind is in that list.

**So "re-home slug allocation off `MenuItemObserver`" is one const edit, not a
new subsystem.** The observer's three duties map exactly:

| `MenuItemObserver` | Replacement | Already exists? |
|---|---|---|
| `created` → mint | `ProjectionWriter:1650` on every write | yes |
| `updated` (name) → re-slug, retire old as 301 | same call; `ensureCurrent()` retires + promotes | yes |
| `deleted` → free the slug | `ContentItemSlugAllocator::forget()` | yes, uncalled |

Only the third needs wiring, and only where a `menu_item` item is genuinely
removed rather than retired.

### 2.2 The 301 history is empty, and the scrape path is why

`retired_at` is NULL on all 329 `site.item_slugs` rows. Not an accident:

`MenuFetchJob::persist()` deletes and re-inserts every scraped dish each run,
then reuses the old uuid through `takeReusedId()` — **keyed on the normalised
name**. An unchanged dish keeps its id and its slug (`ensureCurrentMany()`
short-circuits). A dish the *vendor* renames gets a fresh uuid, so
`reconcileItemSlugs()` puts the old id in `$vanishedItemIds` and
`forgetMany()` **hard-deletes** its slug rows — current and retired alike.

A 301 is therefore only ever minted by a **dashboard** rename
(`MenuItemObserver::updated` → `ensureCurrent()`), and only survives while the
dish keeps its id.

**What this changes:** "migrate the retired slugs or the 301s die" is a true
mechanism over an empty set. The migration still carries `retired_at`,
`is_current` and `created_at` faithfully — the code must be right for the day
there is history — but the *proof* cannot be "a retired row moved". The
definition of done's "a renamed dish still 301s" is proven by **creating** a
rename on dev after the cutover and following the redirect. §12.3.

**And it exposes a real improvement.** On the `content.*` side the identity is
the coord, not a row id, so a vendor rename no longer destroys the slug — it
retires it into a 301. Slice 4 makes vendor renames redirect for the first
time. Named as a behaviour change in the wire manifest, not smuggled in.

### 2.3 Slice 4 needs no DDL

The kickoff pre-assigns migration prefixes `20260813120000`–`20260813129999`.
Nothing needs them:

- `menu_item` is already in `items_kind_check` and `source_items_kind_check`
  (both list 14 kinds including it).
- `content.offers` already carries `channel`, `url`, `variant_label`,
  `amount_max_minor`, `availability`.
- `content.collections.kind` has **no CHECK constraint** — live values today
  are `service_category` (16) and `storefront` (9). `menu_category` and
  `order_platform` need no widening.
- The pool is registry config (verified against the reviews template
  `8dd1ff989` and the `custom_links` entry that shipped yesterday).
- The slug lane is a const edit.

**The block is left unconsumed and returns to the pool**, the same way slice
5b's did. If implementation turns up a genuine need, it takes
`20260813120000` and this section is corrected in place.

### 2.4 The pool `collections` map is storefront-only, and menus break it

`PoolResolver::itemPayloads()` (`:366-383`) reads collections through a
**join onto `content.storefronts`**, gated on `where('c.kind','storefront')`
and on the selection containing a `product`. `collectionsFor()` (`:824`) then
emits only entries it found a storefront row for.

A menu category is a collection with no storefront sidecar. Left alone, every
dish would publish `collectionIds` pointing at collections **absent from the
map** — the frontend would get dangling ids. This is a required resolver
change, not an optional one, and it is the reason Unit 3 and Unit 7 land
together. Design in §6.2.

---

## 3. Unit 1 — the slug lane (blocker gate)

Highest risk, done first. 318 live public URLs.

### 3.1 The mapping

| `site.item_slugs` | `content.item_slugs` |
|---|---|
| `user_id` | `user_id` (unchanged) |
| `item_type` = `'menu_item'` | *(dropped — kind lives on `content.items`)* |
| `item_key` = legacy dish uuid (text) | `item_id` = the **content item uuid** the dish became |
| `slug` | `slug` |
| `is_current` | `is_current` |
| `created_at` | `created_at` (preserved — `lookupCurrent()` orders stranded rows by it) |
| `retired_at` | `retired_at` |

The join is `legacy uuid → coord → content item id`, which only exists once the
backfill (§9) has run. **Slug migration therefore runs after the item backfill,
in the same command, as a second phase** — not as a separate command someone
can forget.

### 3.2 Collision safety — settled, and re-verified

Both tables enforce UNIQUE `(user_id, slug)`, non-partial, so a retired slug
still squats its name. The nine cross-table collisions recorded in
convergence-log F11 are **all `item_type='event'`** — re-verified at entry:
`site.item_slugs` holds 11 event rows, `content.item_slugs` holds 16, and the
overlap is slice 2's dual-write. **Zero are `menu_item`.** They are slice 7's to
delete; this slice neither migrates nor touches them.

The migration is therefore collision-free **by measurement**, and the backfill
asserts it rather than trusting this paragraph: any `(user_id, slug)` already
present in `content.item_slugs` for a *different* `item_id` is a hard failure
that aborts the run.

### 3.3 Ongoing allocation

```php
public const SLUGGED_KINDS = ['event', 'menu_item'];
```

That single edit routes every landed dish through
`ProjectionWriter::refreshItemCaches()`'s existing mint, including the ones
Phase 5's connectors will land. No new call site, no new observer.

`MenuItemObserver` keeps working against `site.menu_items` until slice 7 drops
the table. The two lanes run side by side, exactly as slice 2's events did —
the legacy table still exists, so its observer still has a job.

### 3.4 Removal — the third duty

`ContentItemSlugAllocator::forget()` exists and has no caller. A `menu_item`
item that is genuinely gone must free its slug, or the name is squatted
forever (the unique index is non-partial).

The seam is `content.items.removed_at` — the owner-delete marker — **not**
`source_items.removed_at`, which is cleared on reappearance and would resurrect
a deleted dish (slice 3's rule, parent §9.8). Removal calls `forget()`;
retirement does not.

This is the invariant §1.1's missing FK failed to provide, moved to the side
that survives the teardown.

---

## 4. Unit 2 — per-platform pricing → offers

`content.offers` is a SET, never resolved to a winner (its own DDL comment).
The legacy shape decomposes cleanly because `offers` carries both `channel` and
`url`.

**Per dish, from `site.menu_items`** — the merged aggregate:

| Legacy | Offer |
|---|---|
| `base_price` | `channel='base'`, `qualifier='exact'` (`'free'` at 0) |
| `pickup_price` + `pickup_source` | `channel='pickup'`, `source_id` from the platform named in `pickup_source` |
| `delivery_price` + `delivery_source` | `channel='delivery'`, likewise |

**Per `site.menu_item_platforms` row** — the honest per-platform truth, which
is what the "never resolve to a winner" rule exists for:

| Legacy | Offer |
|---|---|
| `pickup_price` + `pickup_url` | `channel='pickup'`, `url=pickup_url`, `source_id` = that platform's source |
| `delivery_price` + `delivery_url` | `channel='delivery'`, `url=delivery_url` |

Two platforms selling the same dish at different prices produce two offer rows
per channel, distinguished by `source_id`. That is the intended shape.

**`currency` is NULL on 93 of 318 rows.** The default is the **owning menu's**
`currency` (`site.menus.currency`, `'AUD'` on all five), not a global constant
— a menu is a single vendor in a single country, and the legacy writer already
falls back to `$item['currency'] ?? null` off a store-level value. A dish whose
menu also has no currency gets a NULL `offers.currency`, which the column
permits; it is not invented.

`availability` uses slice 5a's vocabulary (`in_stock` / `out_of_stock`) or stays
NULL. Menus carry no stock signal today, so **NULL** — no second spelling
minted.

`qualifier` stays inside `offers_qualifier_check`
(`exact|from|upto|range|free|variable|on_request`); menus only ever produce
`exact` and `free`.

---

## 5. Unit 3 — multi-category → collections

402 memberships over 318 dishes. Target: `content.collections` (kind
`menu_category`) + `content.collection_items`, written **through
`ProjectionWriter`'s `collections` key** (`:1054-1180`), never a second writer.

The three rules 3b established come with it and are inherited, not re-derived:

- **`position` is INSERT-ONLY.** It is absent from the upsert's update list
  (`:1308-1312`) so a scheduled run cannot snap an owner's reorder back.
- **`label` IS updated** — a vendor rename is followed, not duplicated.
- **`removed_at` is in neither list** — a re-listed category cannot resurrect
  one the owner deleted.

**`external_ref` is the natural key** (`collections_user_kind_external_ref_uq`
on `user_id, kind, external_ref`), and a label is never a key. For a backfilled
menu category the ref is `menu:{legacy_category_uuid}` — stable, because unlike
dishes, categories are only deleted when they empty out, and the identity-reuse
pool covers them too (`$previousCategoryIds`, `MenuFetchJob:415`).

`collection_items.position` stays recomputed from array order, per 3b. Menu
membership order is not owner-editable today, so the seed rule does not apply
to it; if that changes, the rule follows.

---

## 6. Unit 4 — identity, and Unit 7 — the pool

### 6.1 Identity across three platforms

Phase 2 shipped the emission, so the union genuinely fires now. The key that
does the work for menus is **`offering_name_in_category`** —
`norm(category)|norm(dish)`, minLength 5 — because bare `offering_name` has
minLength 8 and drops "Fries" and "Cola".

**It is derived from the projection's `collections` entries.** Today
`MenuItemProjector` emits `tags` with `tag_type='category'` and **no
`collections` key at all** (`app/Ingest/Projection/MenuItemProjector.php:44`).
Left unchanged, short dish names would silently stop merging. Unit 4 therefore
requires the projector to emit `collections` — which is the same change Unit 3
needs, verified on real scraped output rather than a fixture (§12).

Expect **fewer merges than a naive name match predicts**: corroborating unions
are cross-source only and skip poisoned values, so a platform listing the same
normalised name twice (a size variant) discards that value for the whole run.
`content.identity_candidates` is where those land, and it gets read.

This slice produces the **first real merges** in the programme
(`content.item_merges` is 0 today, and slice 6's checkpoint says that is
correct, not a defect). §8.3's hard-delete of uncurated losers is exercised for
the first time here. Every merge row is inspected individually; any merge that
cannot be defended is a **HALT**, per the standing rule.

Owner-authored dishes (`is_manual`, 7 of them) must survive a subsequent
scrape. Proven by running a connector *after* the backfill and asserting the
rows live — the test §8.3 requires of every backfill slice.

### 6.2 The pool

Registry config only, on the reviews/custom-links template:

```php
'menus' => ['menu_item'],                       // POOLS
'menus' => 'menu',                              // PAGE_KEYS
'menus' => 'Menu',                              // PAGE_LABELS
'menus' => ['rule' => [['op' => 'kind_is']], 'order_by' => 'recency'],  // SECTION_SHAPE
```

- **Not** in `LATEST_TAG_POOLS` — a "latest dish" is meaningless.
- **Not** in `EXCLUDE_ONLY_POOLS` / `MANUAL_ADD_FORBIDDEN_POOLS` /
  `SOURCE_STATS_POOLS` — a dish is the owner's own content.
- The shape is the settled priced-undated one that services and shop share.
  `latest_per_auto_source` would publish one dish and hide 155.
- Owner ordering rides **pins**, seeded once by a provisioner modelled on
  `content:provision-shop-pins`. No `position` operator: the rule DSL spans
  four registries and the curation half already expresses hand-ordering.
- Tests live in `tests/Feature/Content/` or `AuditPipelineIntegrityTest` fails
  on the unmapped directory.

**The pool selects dishes; collections group them for display.** Categories are
not themselves selectable. Stated explicitly because Unit 3 makes the other
reading available.

**The resolver change §2.4 requires:**

1. Widen the storefront join off `where('c.kind','storefront')` to *any*
   collection carrying a storefront sidecar — which is how `order_platform`
   entries (§7) reach the map for free.
2. Add a second read for collections **without** a sidecar
   (`menu_category`), merged into the same map.
3. Gate both on the selection containing a `product` **or** a `menu_item`, so
   watch/listen/media/events still add no queries.
4. Every entry keeps the full `STORE_KEYS` key set, nulls where inapplicable —
   the "wire shape does not change with kind" contract `price`, `startsAt` and
   `review` already keep. `PoolWireShapeTest` fails on additions as well as
   removals, so any new key is a deliberate edit to the const.

---

## 7. Unit 6 — dining modes and platform links (owner ruling 2026-08-15)

Both are kept. Neither is a regression.

**`site.menus.dining_modes`** (`["DELIVERY","PICKUP"]` on 3 of 5 menus;
CHECK `menus_dining_modes_is_array` allows NULL or a jsonb array) rides the
**menus pool envelope** as store-level metadata — the same additive,
null-when-absent contract `stats` (slice 6) and `collections` (slice 5b) keep.
It is not per-item content and does not belong on an item.

**The 5 `site.menu_platform_links`** become one `content.collections` row of
kind **`order_platform`** each, with a `content.storefronts` sidecar carrying
`provider` (the platform slug), `url` (`store_url`) and the logo/favicon
columns. They surface through the pool's existing `collections` map because
§6.2's widened join includes any sidecar-bearing collection.

Why this shape rather than a new mechanism: `content.storefronts` already
models "a place you can transact, belonging to a collection", already has the
columns, and 5b already publishes it. A dish links to the platforms it is sold
on via `collection_items`, which is the same edge `menu_item_platforms`
expresses.

**`menu_platform_links.status`** (`pending|ok|unavailable`) does not migrate —
it is scrape health, `connect_status` on the sidecar is the nearest column but
means something else, and no public surface reads it. Named here as
deliberately dropped rather than silently lost.

---

## 8. Unit 5 — the rest of the payload

| Legacy | Target |
|---|---|
| `description` | `f_text.body` |
| `badges` jsonb | `item_tags`, `tag_type='badge'` |
| `rating` / `rating_count` | `f_rated.rating` / `.ratings_count` |
| `image_url` + `images` jsonb | `item_media`, hero first (`role='cover'`, then `position`) |
| `dd_external_id` | coord component (§9) and `f_catalog` is **not** used — it is a music facet (parent §7 slice 5) |
| `is_manual` | the manual source; the collision rule survives (§6.1) |

Media goes through `ProjectionWriter::resolveMediaAssets()` unchanged. Slice 1b
made `attribution` and `MirrorMediaAssetJob` **media-kind only**, guarded by
`MediaMirror::isOwnedEntry()`, so a `menu_item` projection is unaffected — but
the three stand-in schemas (`tests/Pest.php`,
`tests/Postgres/ProjectionWriterBatchingTest.php`,
`tests/Postgres/ProjectionIdentityKeyAtomicityTest.php`) stay in step if that
insert array moves.

---

## 9. The backfill and the coord rule

Production code under `app/Services/Migration/` (`MenuBackfiller`), an artisan
command with `--dry-run` and `--user=`, idempotent, counts reported —
modelled on `ServiceBackfiller` / `CustomLinkBackfiller`. Tests in
`tests/Feature/Content/`.

### 9.1 The coord

Parent §8.1 prescribes `manual:{legacy_uuid}` and says it holds "only where the
legacy id is stable". **Checked, per the kickoff's non-negotiable.**

`MenuFetchJob::persist()` DELETEs and re-INSERTs every scraped dish
(`:406-424`, `:597-599`) — but then reuses the old uuid through
`takeReusedId()`, **keyed on the normalised dish name** (`:540`). So:

- a dish whose name is unchanged keeps its uuid across every scrape;
- a dish the vendor **renames** gets a fresh uuid.

`manual:{legacy_uuid}` would therefore mint a **second content item** on every
vendor rename and strand the first. Not the slice 5a failure (a fresh uuid
every cycle), but the same class of bug on a slower clock.

Dishes have no canonical URL — only per-platform deep links composed at read
time by `MenuItemDeepLinks` — so §1.7's `manual:{sha1(url)}` has nothing to
hash.

**The rule: `manual:menu:{menu_uuid}:{sha1(normalised_name)}`.**

It is the legacy writer's *own* identity key, which is what actually survives a
rebuild; it is menu-scoped, so two vendors' "Garlic Bread" stay distinct; and it
aligns with `MenuRecords::flatten()`'s `sha1(category|name)` fallback that
Phase 5's real sources emit, so the backfilled row and the scraped row are
describing identity the same way.

`normalised_name` reuses `NormalizesMenuItemNames` — the trait the legacy
identity-reuse pool already uses — so the two cannot drift.

**Consequence, stated plainly:** a dish the vendor renames folds onto a *new*
coord and the old one goes stale. That is the same behaviour the legacy lane
has today (fresh uuid, slug forgotten), except the slug now 301s instead of
vanishing (§2.2). It is a strict improvement, not a regression.

### 9.2 Coverage gate

Parent §8.4: **coord coverage, not row counts.** 318 legacy dishes legitimately
become fewer items once cross-platform identity fires.

Assertion: for every live `site.menu_items` row, a live
`content.source_items.coord` exists that is traceable to it, and every field in
§4/§5/§7 is asserted per-coord. Derived twice — once in PHP with the app's own
`sha1`, once in SQL with `pgcrypto` — the way Phase 3's gate was, so an
agreement failure is caught rather than a shared bug being confirmed.

Item-level collapse is then expected and visible.

### 9.3 The connector must not destroy backfilled rows

§8.3's hazard, and this is the slice that exercises it. The backfill writes
through the manual lane; the test runs `MenuItemProjector` *after* the backfill
and asserts the 7 `is_manual` dishes survive with their curation.

---

## 10. Cache, and the rest of the integration surface

**Three lanes, all of them, per §9.2** — `BuildState::bump($siteId)`, touch
`site.sites.updated_at`, dispatch `CloudflareCachePurgeJob`. No CI check
enforces this despite the docblock claiming one. `PoolController::poolChanged()`
is the reference implementation.

**`CloudflarePurgeService::purgeHandle()` must move in the same window.**
Verified at `:266-274`: it joins `site.menu_items` → `site.menus` →
`core.users` on `handle_lc`, capped at
`config('partna.cloudflare_purge.menu_items_limit', 150)`, to build
`/menu/<uuid>` purge targets. When dishes live in `content.*` this lookup
repoints, or every purge silently degrades to page-only and dish pages stay
stale at the edge for the full 24h TTL.

Its `catch` already calls `report($e)` (OBS-101 raised it from a filtered
`Log::info`), and `CloudflareCachePurgeJob` self-dispatches three delayed
follow-ups, so one save runs it four times un-deduped. **Wrap the catch in
`EscalatesRepeatedFaults`** rather than leaving the raw `report()`.

**k6.** `scripts/launch-check/k6/seed.sql` and `jobs.js` hard-code menu
invariants. This slice changes menu shape, so both get re-checked — the
harness has silently broken on exactly this before.

**DSAR.** `DataExportPayloadBuilder` pins `services` / `service_categories`;
menus are not currently a named export section, so nothing breaks here. Stated
so slice 7 does not have to re-derive it.

**Analytics (§9.7).** `analytics.item_views` rows reference legacy dish uuids.
Menus are the first kind where merges will actually delete items. Decision:
historical menu analytics are **orphaned, not repointed** — dev-only data, no
customers, and repointing across a merge would attribute one dish's history to
another. Recorded as a deliberate loss.

---

## 11. Phase 5 — the live menu proof

Folded in from the convergence plan. Provision `uber_eats` / `doordash` /
`square` via `ingest:backfill-sources`, dispatch, project.

**Gate:** `content.source_items` of kind `menu_item` > 0 **AND** the menus pool
returning them on the wire.

**Scope the sync.** `ingest:backfill-sources` unqualified showed on its dry-run
that it would process **80 connections across every connector**, bumping
`next_attempt_at` on unrelated sources including other sessions' in-flight
work. It is scoped to the connections that need it.

**`Pull.config.selection_ref` ships NULL** and a connector must treat NULL as
land-nothing, never fetch-everything. `'storewide'` is the reserved token for
the store-wide menu. Nothing lands until `SourceProvisioner::sync()` has run
per affected connection.

**Apify spend is capped at US$18.** Remaining credit is re-checked before
anything is spent; **exceeding the cap is a STOP, not a spend.**

---

## 12. Verification

1. **`composer test`** green, plus **`composer test:pg`** — this slice touches
   `ProjectionWriter`, and that lane's stand-in DDL drifts silently from writer
   changes. A green SQLite run says nothing.
2. **PHPStan** at `php -d memory_limit=1G ./vendor/bin/phpstan analyse <path>
   --no-progress --debug` — in a worktree the default invocation dies with
   "Child process error (exit code 255)" and OOMs at 128M, and neither failure
   looks like what it is.
3. **Pint.**
4. **Live on dev:** coverage gate output pasted into a parent-spec checkpoint;
   `content.item_merges` inspected row by row; **a proven 301** — rename a dish
   through the dashboard after cutover and follow the redirect; the menus pool
   read back off `dev-api.partna.au`.
5. **`cloud env:logs partna development --minutes 10`** plus a Nightwatch scan.
6. **Wire manifest** at `docs/wire-changes/2026-08-12-slice-4-menus.md`.

---

## 13. Downstream edits this slice owes (before merge, not after)

A checkpoint is not a communication channel — parent invariant #5 forbids the
next session citing it.

| Discovery | Edit |
|---|---|
| §1 entry figures | Parent spec §1.4 table + a revision note saying what changed and that the cause is undetermined |
| §2.1 — the allocator exists | `ItemSlugAllocator`'s docblock ("no allocator today") and the slice-7 kickoff's §9.4 list |
| §3.3 — allocation re-homed onto `SLUGGED_KINDS` | **`slice-7-teardown` §9.4 explicitly** — it must retire `MenuItemObserver` knowing the behaviour moved rather than vanished |
| §6.2 — `PoolResolver` collections map widened | `slice-7-teardown`; also `phase-6-pseudo-platforms`, which promotes the ordering brands this slice models as `order_platform` collections |
| §7 — `menu_platform_links.status` dropped | The scope doc's decisions section |
| §10 — `CloudflarePurgeService` repointed | `slice-7-teardown` (it lists the three lookups) |
| §2.3 — prefix block unused | Parent §4.3 rule 5's block list |

Anything that invalidates slice 7's gate — a table still read, a slug not
migrated, an observer whose behaviour has no home — is a **stop and raise**,
never an edit.

---

## 14. Out of scope

- **Dropping any legacy table.** Slice 7 owns that; the menu tables stay.
- **Production.** No tool call names it.
- **Standalone events.** Owner ruling 2026-08-15: slice 4 widens the slug lane
  so any kind can hold permalinks, and migrates only the 318 `menu_item` rows.
  Standalone events adopt the lane when slice 7 step 1 writes them into
  `content.items`. The 11 legacy `event` rows in `site.item_slugs` are slice
  7's to delete.
- **The missing `site.item_slugs` FK.** The table is dropped in slice 7;
  §3.4 moves the invariant to the `content.*` side instead.
