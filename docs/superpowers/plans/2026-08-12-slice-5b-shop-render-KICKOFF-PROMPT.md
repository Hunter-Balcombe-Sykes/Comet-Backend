# KICKOFF PROMPT — Slice 5b: the shop pool and the public render

Second half of `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md`
§7 "Slice 5". Slice 5 was cut in two on 2026-08-12, the same seam slice 1 took.

- **5a — MERGED FIRST, and this is blocked on it.** The data move: 51 products
  and 9 stores into `content.*`, a `content.storefronts` sidecar, and all 14
  `/platforms/shop/*` endpoints plus `syncLatest()` repointed. Nothing public
  changed. Spec: `docs/superpowers/specs/2026-08-12-slice-5a-shop-data-design.md`.
- **5b (this prompt)** — the render. The `shop` pool, the `shop` page, the
  outbound-URL composition moving to the backend, and retiring the legacy shop
  keys from `/integrations`.

**This is the half that cannot be un-shipped quietly.** 5a was verifiable with
SQL against dev and touched no visitor-facing byte. 5b changes a CDN-cached
public wire that partna-monorepo reads today, and it retires a function
(`productHref()`) that lives in that other repo. Treat the cross-repo
coordination as the slice, not as a follow-up to it.

Paste everything below the line into a fresh session. It is self-contained.

---

**First action: rename this session to `slice-5b-shop-render`** so it is
identifiable in Remote Control instead of appearing as a machine name.

Read, in full, before touching anything:

1. `CLAUDE.md`
2. `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` — §3
   **Invariants**, §4.3 concurrency rules, §8.4, §9.2, §10.
3. `docs/superpowers/specs/2026-08-12-slice-5a-shop-data-design.md` — **all of
   it**, especially §7 "Conventions this slice establishes". 5a decided your
   `SECTION_SHAPE`, your ordering mechanism and your `LATEST_TAG_POOLS` answer,
   and recorded why. Re-derive the figures; do not re-litigate the decisions
   without saying what changed.
4. `docs/superpowers/plans/2026-08-11-content-pool-slice2-events.md` — slice 2 is
   the reference for adopting a pool: registry entry, page provisioning for
   existing users, section re-provisioning, and the payload work.
5. `app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php` — the
   shop branch (`filterPayload`, `SHOP_BRAND_ALLOWLIST`, `SHOP_PRODUCT_ALLOWLIST`)
   is the wire you are replacing.
6. `app/Site/Pools/PoolResolver.php` and `app/Site/Sections/SectionCandidates.php`.

## Rule zero — you may not assume any checkpoint is true

Parent invariant #5: **no slice may cite another slice's checkpoint as evidence
for its own claims.** Invariant #6: **registration is not execution.** 5a's
checkpoint is not proof for you. Re-derive every figure from dev. Where dev and
this prompt disagree, dev wins and you say so.

### Entry gate — run these first, paste your own output into your spec's §1

5a is merged and deployed (`9e5bf3a6a`, 2026-08-13) and its backfill has run,
verified idempotent by a second run with identical counts — checkpoint
`2026-08-11-content-pool-convergence-design.md` §16, which per Rule Zero is not
evidence for you. **Re-run every query below yourself.** The right-hand column
is 5a's own measured output, given so you can tell a stale run from a real
regression — it is not a substitute for measuring dev again.

```sql
-- 5a's output, re-measured. If any of these is 0, 5a did not land and you stop.
SELECT count(*) FROM content.items WHERE kind='product' AND removed_at IS NULL;  -- 51
SELECT count(*) FROM content.collections;                                        -- 9, kind='storefront'
SELECT count(*) FROM content.storefronts;                                        -- 9
SELECT count(*) FROM content.collection_items;                                   -- 51
SELECT count(*) FROM content.item_variants;                                      -- 268
SELECT count(*) FROM content.offers;                                             -- 324 (14 pre-existing + 310 shop)

-- the legacy tables must be inert. If these move between two runs an hour
-- apart, something still writes them and 5a is incomplete.
SELECT max(updated_at) FROM site.shop_products;    -- 2026-08-12 17:24:33+00, frozen since before deploy
SELECT max(updated_at) FROM site.shop_brands;      -- 2026-08-12 10:54:51+00, frozen since before deploy

-- what you are provisioning into. No 'shop' row existed as of 5a.
SELECT key, count(*) FROM site.pages GROUP BY 1 ORDER BY 2 DESC;
SELECT key, count(*) FROM site.sections WHERE key LIKE 'pool:%' GROUP BY 1;

-- store behaviour that must reach the wire
SELECT provider, count(*) FROM content.storefronts GROUP BY 1;
SELECT count(*) FILTER (WHERE referral_query <> '') AS with_referral,     -- 0 of 9
       count(*) FILTER (WHERE coalesce(discount_code,'') <> '') AS with_discount  -- 4 of 9
FROM content.storefronts;

-- the ONE live input to link mode (per-brand link_mode was dropped by 5a)
SELECT shop_link_mode, count(*) FROM site.sites GROUP BY 1;
```

**Schema 5a added that you will need and this prompt did not previously
mention:**

- `content.storefronts.external_ref` — the collection↔storefront identity key
  (`= shop_brands.brand_id`, the provider's own store id). **Not**
  `content.collections.label`, which is a mutable display name — a 5a fix
  round found and closed a bug where keying on the label orphaned a store's
  `referral_query`/`discount_code` on rename. If you write any store-matching
  logic, key on `external_ref`, never on the label.
- `content.f_catalog.handle` / `.vendor` / `.variant_ref` — three columns 5a
  added because `items.facets_cache` turned out to be derived and unwritable.
  `variant_ref` is the one Unit 4 needs: it is the Shopify checkout deep-link
  id, and it is the only place that id survives for the 17-of-51 products
  whose sole variant was a `"Default Title"` placeholder and so was never
  written to `content.item_variants`.
- `content.item_variants.image_url` — exists and round-trips correctly, but
  **zero rows carry a value on dev** (`variant_blobs_with_image = 0` in the
  source blobs). If Unit 5's store-card or product payload surfaces a
  per-variant image, it is exercising a column real data has never hit — treat
  it as unverified until it is.
- `content.collections.kind = 'storefront'` now has exactly 9 live rows —
  `collections` is the shared grouping table slices 3 and 4 will also write
  to, so filter on `kind`, not on presence in the table.

## Scope

### Unit 0 — BLOCKING PREREQUISITE: decide what a re-added product does

**Settle this before Unit 1. It is a decision, not a bug fix, and the pool read
you are about to write is what makes it visible.**

Nothing anywhere clears `content.items.removed_at`.
`ProjectionWriter::upsertSourceItem()` clears only `source_items.removed_at`,
and `resolveItems()` binds by coord without consulting `items.removed_at` — so
a retired item that is re-added resolves to the same, still-retired row.

5a retires items in two places (`ShopContentWriter::retireAbsent()` on every
sync, `retireStore()` on `removeBrand`/`forget`/`removeProduct`-when-empty) and
this is invisible there, because **no shop read in 5a filters `removed_at`** —
deliberately, for lockstep with the legacy path. **Your pool read does filter
it.** So the moment 5b lands:

- a product the owner removes and re-adds is permanently absent from the Shop
  page while showing normally in the dashboard;
- it is trivially reachable, not theoretical — the individual bucket's 20-item
  cap retires the oldest product on **every** add, and `ShopProductSeeder`
  re-adds by URL;
- and the cause sits three layers away from the symptom.

**The conflict is real and you must resolve it explicitly.** One-way retirement
is a parent-programme rule an earlier slice established — *an item whose every
source item is retired is itself retired, and `removed_at` is never cleared by
reappearance*. Shop wants the opposite: a store's catalogue is a set the owner
edits, and re-adding is a normal act, not a resurrection. Both positions are
defensible. Decide which one holds for `kind='product'`, say why, and write it
into the parent spec — do not just clear the flag in `syncStore()` and move on,
and do not silently inherit the current behaviour either.

Paired with it, and biting the same way in 5b: **`retireAbsent()`'s `$absent`
join can row-wise match a stale coord even when a live-coord row exists for the
same item** (`whereNotIn` runs before `->unique()`). An item carrying both a
`pid:`-derived and a URL-derived coord — a product that gains a URL upstream —
has a stale `source_items` row that matches, so the item is treated as absent,
its `collection_items` link is dropped, and if no other store carries it, it is
retired while still in the catalogue. Parked in 5a as pre-existing and
low-probability. Combined with never-cleared `removed_at` it is a one-way
disappearance, so fix or accept it as part of the same decision.

Whatever you decide, note that `retireAbsent()`'s **delete-links-FIRST-then-
requery** ordering is load-bearing: reversed, the synced store's own stale link
satisfies the "still linked to a storefront of this user" test and cross-store
retirement becomes a no-op.

### Unit 1 — Register the pool
`PoolRegistry`: `POOLS['shop'] = ['product']`, `PAGE_KEYS['shop'] = 'shop'`,
`PAGE_LABELS['shop'] = 'Shop'`, and the `SECTION_SHAPE` 5a decided:

```php
'shop' => ['rule' => [['op' => 'kind_is']], 'order_by' => 'recency'],
```

**Shop is NOT in `LATEST_TAG_POOLS`** — 5a §7 argues it both ways and decides.
`buildPools()` loops every `POOLS` key, so the pool reaches
`GET /api/public/profiles/{handle}` with no payload-builder change; that was
verified on 2026-08-12 at `IndividualProfilePayloadBuilder:315`. Verify it again.

### Unit 2 — Provision pages and sections for existing users
**No `shop` page exists on dev.** Slice 2 provisioned events; copy that
implementation, including the artisan command with `--dry-run` and counts, run
against dev with output pasted into the checkpoint.

`PoolSectionProvisioner::ensure()` early-returns on an existing section, so a
constant change does not reshape existing rows. That bites slice 1a and slice 2
both; assume it bites you.

### Unit 3 — Ordering is pins, and pins are also the §8.3 armour
`site.shop_products.position` is the owner's hand-chosen order. The pool's auto
half offers exactly three orderings — `alphabetical`, `occurrence`, `recency`
(`SectionCandidates:105-116`) — and **none is "the order the owner chose"**.

So every migrated product is **pinned** in `site.section_items` at its position.
`SectionCandidates:119` excludes already-pinned ids from the auto half, so there
is no duplication. This also satisfies parent §8.3 for free: `mergeInto()`'s
`hasCuration` check is `exists in site.section_items OR content.manual_overrides`,
so a pinned product cannot be hard-deleted by a merge.

New products arriving from a later `syncLatest()` land unpinned and fall to the
auto half at the end of the list. **Decide and state** whether that is the
intended behaviour or whether the sync should pin them at their catalogue
position too.

### Unit 4 — The outbound URL moves to the backend. This is the money unit.
Owner decision 2026-08-12: `content.f_link.url` holds the **bare** product URL,
and the composition — `site.sites.shop_link_mode` (`checkout|product`) plus the
store's `referral_query`, plus the Shopify checkout deep link built from the
variant id — becomes a **backend read-time** concern. `productHref()` in
partna-monorepo then retires.

Why it moved: referral revenue becomes testable in this repo, and a change to the
site's link mode takes effect on the next payload build with nothing to
re-backfill.

- `referral_query` is affiliate revenue. Treat losing it the way you would treat
  losing a price. It is `''` on all 9 dev stores, so **your tests are the only
  place this behaviour is exercised** — dev data will not catch a regression.
- `link_mode` per-brand is dormant and was dropped by 5a. The **global**
  `site.sites.shop_link_mode` is the live input and must keep working.
- The composition sits behind the 60s payload cache on the public hot path.
  Batch it; do not compose per row inside a loop over items.

### Unit 5 — The wire change, and the other repo
The Shop page today renders from `/api/public/profiles/{handle}/integrations`,
brand-keyed, via `PublicIntegrationConnectionResource`'s shop branch. After this
slice it renders from `profile.pools.shop`.

- The sitepage rebuilds its **store cards** from the collections map — store
  name, logo, favicon, currency, discount code. Decide that map's shape and put
  it in the manifest before writing the resolver, not after.
- `SHOP_PRODUCT_ALLOWLIST` (#API-1) is the enforcement point that keeps
  unvetted scraper keys off a CDN-cached public wire. **The pool payload needs
  an equivalent.** Do not let the item payload become "whatever the projector
  emitted".
- Retiring the legacy shop keys from `/integrations` is a **breaking** change to
  a live consumer. It needs its own wire manifest and partna-monorepo has to
  land its side first. If that repo lags, ship the pool additively and leave the
  legacy keys — and say in the checkpoint that the retirement criterion is
  **unmet** rather than ticking it. Slice 2 set that precedent for standalone
  events; follow it.

## Non-negotiables

- **Cache invalidation is three lanes.** `BuildState::bump($siteId)`, touch `site.sites.updated_at` (the payload cache key composes from it), dispatch `CloudflareCachePurgeJob`. No CI check enforces this despite the docblock claiming one — assert it directly.
- **Schema changes are raw SQL** under `supabase/migrations/`. Pre-assigned prefix block: `20260813110000`–`20260813119999`. 5a owns `20260813100000`–`20260813109999`; do not take from it.
- **Tests run SQLite, production is Postgres.** Verify every constraint-bound write against the DDL, not a green suite.
- **Outbound fetches go through `SafeUrlFetcher`.** Product and store URLs arrive in third-party payloads and are untrusted by definition. A host allowlist entry is not the fix.
- **`site.shop_products` / `site.shop_brands` are not dropped** — that is slice 7. 5a already made them inert.

## If reality diverges, update the downstream prompts — do not just note it

**A checkpoint is not a communication channel.** Parent invariant #5 forbids any
slice citing another's checkpoint as evidence, so writing a discovery only into
your own checkpoint guarantees the next session never acts on it. **Edit their
prompt.**

| You discover / change | Update |
|---|---|
| A parent-spec fact is now wrong (a count, a claim, a constraint) | The parent spec's §1 and its revision note, in place |
| You changed `PoolResolver`, `PoolRegistry`, `PoolSectionProvisioner` or `SectionCandidates` | Every remaining prompt that builds on it — `slice-4-menus`, `slice-6-reviews`, `slice-7-teardown`, `media-pool-slice-1b` |
| You changed the pool item payload shape | `slice-4-menus` explicitly — menus are the next priced kind and are told to reuse your shape |
| You settled how a pool payload carries collections | `slice-4-menus` — menu categories are the same problem |
| You added an allowlist / enforcement point for pool payloads | Every remaining prompt, under its non-negotiables |
| You consumed migration filename prefixes outside your block | Whichever slice owns the block you took from |

Two rules for the edit itself:

- **Edit in place; do not append a "correction" section.** A prompt read top to
  bottom must be true.
- **Say the fact, not the story.**

If you find something that invalidates another slice's *approach* rather than a
detail — stop and raise it rather than rewriting their scope unilaterally.

## Process — stop at every gate

1. **Recon + entry gate.** Confirm 5a landed and the legacy tables are inert. **STOP — sign-off.**
2. **Brainstorm** (`superpowers:brainstorming`) — units 4 and 5 are genuine decisions, and unit 5 is cross-repo.
3. **Spec** → `docs/superpowers/specs/2026-08-12-slice-5b-shop-render-design.md`. **STOP — sign-off.**
4. **Plan** (`superpowers:writing-plans`) → `docs/superpowers/plans/2026-08-12-slice-5b-shop-render.md`. **STOP — sign-off.**
5. **Implement** in a dedicated worktree off `development`, per unit: plan → implement → independent review → tick.
6. **Independent review** of the whole diff. **STOP — sign-off** before verification.
7. **Verify on dev.** Live SQL assertions plus a real `PoolResolver` call — SQL cannot stand in for the resolver. Wire manifest at `docs/wire-changes/2026-08-12-slice-5b-shop-render.md`.
8. **Merge + push.** Rebase onto `development`, full suite + PHPStan + Pint green, **STOP — explicit sign-off**, then merge and push. Never push to `production`.

## Definition of done

A dev account's Shop page renders from `profile.pools.shop` with its products in
the owner's chosen order, grouped into store cards rebuilt from the collections
map; the outbound URL is composed by the backend and a `referral_query` +
`checkout` link-mode combination is proven correct **by test**, since dev data
carries neither; the pool payload has an enforcement point equivalent to
`SHOP_PRODUCT_ALLOWLIST`; every `pool:shop` section carries the corrected rule;
coverage gate green (parent §8.4 — coord coverage, **not** row-count equality);
checkpoint and wire manifest committed.

If the legacy `/integrations` shop keys are still live because partna-monorepo
has not landed its side, **say so and mark that criterion unmet.** Do not tick it.
