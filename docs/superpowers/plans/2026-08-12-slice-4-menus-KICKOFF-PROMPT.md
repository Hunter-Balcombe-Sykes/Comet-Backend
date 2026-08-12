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
categories. `content.collections` / `collection_items` are your target and are still
empty unless slice 3 or 5 populated them — check, and follow their shape rather than
inventing a second one.

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

## Non-negotiables

- **The 301s are public URLs.** Breaking them is a customer-visible regression and an SEO one. Treat slug migration as blocker-gate work.
- **Cache invalidation is three lanes** — `BuildState::bump($siteId)`, touch `site.sites.updated_at`, dispatch `CloudflareCachePurgeJob`. No CI check enforces this despite the docblock claiming one.
- **`mergeInto()` hard-deletes uncurated merged-away items** (parent §8.3). With cross-source unioning this is *expected* behaviour here — but owner-authored dishes must survive. Assert with a real menu scrape after backfill.
- **Backfill is production code** under `app/Services/Migration/`, artisan command, `--dry-run`, idempotent, counts reported.
- **Schema changes are raw SQL** under `supabase/migrations/`. Pre-assigned prefix block: `20260813120000`–`20260813129999`.
- **Tests run SQLite, production is Postgres.** The `menus.dining_modes` shape CHECK and `offers_qualifier_check` must be verified against the DDL.
- **The k6 harness hard-codes menu invariants** (`scripts/launch-check/k6/`). If this slice changes menu shape, re-check `seed.sql` and `jobs.js`.

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
