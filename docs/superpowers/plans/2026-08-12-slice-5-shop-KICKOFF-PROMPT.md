# KICKOFF PROMPT — Slice 5: Shop → `content.*`

> **CONSUMED AND SPLIT — 2026-08-12. Do not paste this prompt into a new
> session.** It was run, its recon reached the owner, and slice 5 was cut in two:
>
> - **5a — the data move.** Spec: `docs/superpowers/specs/2026-08-12-slice-5a-shop-data-design.md`
> - **5b — the pool and the public render.** Kickoff: `docs/superpowers/plans/2026-08-12-slice-5b-shop-render-KICKOFF-PROMPT.md`
>
> Several premises below were falsified against dev and are corrected in 5a §1.2
> and §1.4 — in short: `selection_mode` is dead, per-brand `link_mode` has been
> dormant since 2026-07-08, `style_analysis` has no reader anywhere in `app/`,
> `f_catalog` is a music facet that cannot describe a store, and
> `GumroadProductProjector` shares no field name with the blob and has no
> `ingest.sources` row. **Read 5a's §1 before trusting anything here.**

Part of `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` §7
"Slice 5". Runs concurrently with 1b and slice 3 (parent §4.3 rule 1 — distinct
kinds). Blocked by nothing; 0b is merged.

Retained below as the historical record of what the slice was scoped as before
its recon ran.

---

**First action: rename this session to `slice-5-shop`** so it is identifiable in
Remote Control instead of appearing as a machine name.

Read, in full, before touching anything:

1. `CLAUDE.md`
2. `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` — §1.5, §3
   **Invariants**, §4.3 concurrency rules, §7 "Slice 5", §8, §9, §10.
3. `docs/superpowers/specs/2026-08-12-media-pool-slice-1a-design.md` — the reference
   backfiller through the manual write lane.
4. `app/Services/Platforms/ShopCatalog.php`, `ShopProductSeeder.php`,
   `Strategies/Fetch/ShopFetch.php`, `Payloads/ShopPayload.php`,
   `app/Jobs/Platforms/CommerceProbeJob.php` — the existing lane in full. It is
   larger than the two tables suggest.

## Rule zero — you may not assume any checkpoint is true

Parent invariant #5: **no slice may cite another slice's checkpoint as evidence for
its own claims.** Invariant #6: **registration is not execution.** Re-derive every
figure from dev. Where dev and this prompt disagree, dev wins and you say so.

### Entry gate — run these first, paste output into the spec's §1

```sql
SELECT count(*) FROM site.shop_products;        -- expect 51
SELECT count(*) FROM site.shop_brands;          -- expect 9
SELECT count(DISTINCT brand_id) FROM site.shop_products;   -- expect 9

-- The blob you are decomposing. Read real keys; do not assume a shape.
SELECT jsonb_object_keys(data) AS k, count(*)
FROM site.shop_products GROUP BY 1 ORDER BY 2 DESC;

-- Brand behaviour that must survive
SELECT provider, selection_mode, link_mode, fetch_mode, is_individual, count(*)
FROM site.shop_brands GROUP BY 1,2,3,4,5;

-- Has the Gumroad projector ever run? Expect 0 — invariant #6 applies.
SELECT count(*) FROM content.source_items WHERE kind = 'product';
SELECT kind, count(*) FROM content.sources GROUP BY 1;
```

## Scope

### Unit 1 — Decompose the blob
`site.shop_products` is `(brand_id, product_id, position, data jsonb)`. The entry
gate's `jsonb_object_keys` output **is** your field map — write the spec against
real keys, not against `GumroadProductProjector`'s assumptions, because the products
on dev did not necessarily come from Gumroad.

Target shape per product: `content.items` (kind `product`) + `content.offers`
(price, currency, `url`, `availability`) + `content.item_variants` (label/sku/
position — **0 rows today, you are its first user**) + `content.item_media` +
`content.f_link`.

### Unit 2 — Brands are the hard part
`site.shop_brands` carries behaviour, not just labels:

| Column | Why it matters |
|---|---|
| `selection_mode` `manual\|latest` | The per-brand equivalent of `auto_sync_latest`. Parent §1's 2026-08-05 doc says `sites.shop_auto_latest` was meant to migrate into connection `display_settings` — check whether it did |
| `link_mode` `product\|checkout` | Changes the destination URL per product |
| `referral_query` | Appended to outbound URLs — **revenue-affecting**, must not be lost |
| `discount_code` | Displayed to visitors |
| `is_individual` | Single-product brand |
| `fetch_mode` | How the catalogue is retrieved |
| `style_analysis` jsonb | Feeds design, not commerce |
| `connect_status` / `connect_error` | Async connect lifecycle (`pending\|failed`) |

Decide explicitly where each lands: `content.collections`, `f_catalog`, the
`platform_connections.display_settings` blob, or nowhere. Parent §7 leaves this open
("`f_catalog` or collections, decided in that slice's spec") — **decide it, and say
why.** Anything you drop is a product regression; name it as one.

`referral_query` and `link_mode` together mean a product's public URL is *computed*,
not stored. If `content.f_link` holds a bare URL, that computation has to live
somewhere. Say where.

### Unit 3 — The existing lane keeps working
`ShopCatalog::syncLatest()`, `ShopProductSeeder`, `ShopFetch`, `ShopPayload` and
`CommerceProbeJob` all read and write the legacy tables today. Slice 5 does **not**
delete them (that is slice 7), so decide: do they keep writing legacy while
`content.*` is populated by backfill, or are they repointed now? Dual-write is a
correctness hazard; backfill-only means the tables diverge until slice 7. Pick one,
state it, and make the divergence window explicit.

### Unit 4 — The pool — DECIDED, shop gets one
Owner decision 2026-08-12: **all four remaining commerce types get pools.** Add a
`PoolRegistry` entry (`POOLS`, `PAGE_KEYS`, `PAGE_LABELS`, plus a `SECTION_SHAPE`
block if the default rule does not fit) and provision sections for existing users.
`buildPools()` loops all `POOLS`, so this ships publicly with no payload-builder
change.

- Existing shop selection curation migrates into pool **pins/excludes**, the way
  slice 2 migrated `hiddenEventIds`. Read that implementation first.
- `shop_brands.selection_mode` (`manual|latest`) is the per-brand equivalent of
  `auto_sync_latest`. If `latest` maps onto the pool's auto-rule, say so and reuse
  the existing operator rather than inventing a second mechanism.
- Decide whether shop belongs in `LATEST_TAG_POOLS`. "Newest product" is arguably
  meaningful where "latest service" is not — argue it either way, but decide.

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

## Non-negotiables

- **Cache invalidation is three lanes.** `BuildState::bump($siteId)`, touch `site.sites.updated_at` (the payload cache key composes from it), dispatch `CloudflareCachePurgeJob`. No CI check enforces this despite the docblock claiming one — assert it directly.
- **`mergeInto()` hard-deletes uncurated merged-away items** (parent §8.3). Assert with a real connector/seeder run after backfill that nothing vanishes.
- **Backfill is production code** under `app/Services/Migration/`, artisan command, `--dry-run`, idempotent, counts reported.
- **Schema changes are raw SQL** under `supabase/migrations/`. Pre-assigned prefix block: `20260813100000`–`20260813109999`.
- **Tests run SQLite, production is Postgres.** `offers_qualifier_check` is `exact|from|upto|range|free|variable|on_request`; `item_media_role_check` is `cover|gallery|poster|avatar|logo`. Verify against the DDL.
- **Outbound fetches go through `SafeUrlFetcher`.** Product and brand URLs arrive in third-party payloads and are untrusted by definition. A host allowlist entry is not the fix.
- **Money-adjacent:** `referral_query` is affiliate revenue. Treat losing it the way you would treat losing a price.

## If reality diverges, update the downstream prompts — do not just note it

**A checkpoint is not a communication channel.** Parent invariant #5 forbids any
slice citing another's checkpoint as evidence, so writing a discovery only into your
own checkpoint guarantees the next session never acts on it. **Edit their prompt.**

When something turns out different from what was written — a figure that has moved, a
premise that was wrong, a shared file you changed, a convention you established — you
own propagating it **before you merge**:

| You discover / change | Update |
|---|---|
| A parent-spec fact is now wrong (a count, a claim, a constraint) | The parent spec's §1 and its revision note, in place |
| You changed `ProjectionWriter`, `PoolResolver`, `PoolRegistry` or the manual write lane | Every remaining prompt that builds on it — `slice-3-services`, `slice-4-menus`, `slice-6-reviews`, `slice-7-teardown`, `media-pool-slice-1b` |
| You settled a `SECTION_SHAPE` for priced, undated items | **`slice-4-menus` explicitly** — it is told to reuse your convention rather than invent a third. Also tell `slice-3-services` if it has not merged |
| You settled how `selection_mode` maps onto the pool auto-rule | `slice-3-services` and `slice-4-menus` — the same question arrives in both |
| You added or reshaped anything under `app/Services/Migration/` | The other backfill prompts — 3, 4, 7 |
| You consumed migration filename prefixes outside your block | Whichever slice owns the block you took from |
| You found a new hazard in shared code | Every remaining prompt, under its non-negotiables |

Two rules for the edit itself:

- **Edit in place; do not append a "correction" section.** A prompt read top to
  bottom must be true. A stale statement with a correction 80 lines later will be
  acted on before the correction is reached.
- **Say the fact, not the story.** "`content.item_variants` now uses `{shape}`;
  follow it" beats "during unit 1 we discovered that…".

If you find something that invalidates another slice's *approach* rather than a
detail — stop and raise it rather than rewriting their scope unilaterally.

## Process — stop at every gate

1. **Recon + entry gate.** Real jsonb keys, real brand behaviour. **STOP — sign-off** on the field map before designing.
2. **Brainstorm** (`superpowers:brainstorming`) — unit 2 and unit 3 are genuine decisions with no obvious answer.
3. **Spec** → `docs/superpowers/specs/2026-08-12-slice-5-shop-design.md`. **STOP — sign-off.**
4. **Plan** (`superpowers:writing-plans`) → `docs/superpowers/plans/2026-08-12-slice-5-shop.md`. **STOP — sign-off.**
5. **Implement** in a dedicated worktree, per unit: plan → implement → independent review → tick.
6. **Independent review** of the whole diff. **STOP — sign-off** before verification.
7. **Verify on dev.** Live SQL assertions pasted into a parent-spec checkpoint. Wire manifest at `docs/wire-changes/2026-08-12-slice-5-shop.md`.
8. **Merge + push.** Rebase onto `development`, full suite + PHPStan + Pint green, **STOP — explicit sign-off**, then merge and push. Never push to `production`.

## Definition of done

51 products and 9 brands represented in `content.*` with every brand behaviour
either migrated or explicitly recorded as dropped; variants and offers populated;
outbound URL computation (`link_mode` + `referral_query`) demonstrably preserved; a
catalogue sync after backfill destroys nothing; coverage gate green (parent §8.4 —
coord coverage, **not** row-count equality); checkpoint and wire manifest committed.
`site.shop_products` / `site.shop_brands` are **not** dropped — that is slice 7.
