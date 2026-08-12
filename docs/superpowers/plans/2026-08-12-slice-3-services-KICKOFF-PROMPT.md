# KICKOFF PROMPT — Slice 3: Services → `content.*`

Part of `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` §7
"Slice 3". Runs concurrently with 1b and slice 5 (parent §4.3 rule 1 — distinct
kinds). Blocked by nothing; 0b is merged.

**This slice carries the programme's biggest unknown.** `FreshaServiceProjector` is
registered but has landed **zero records** (parent §1.6). Whether it is merely
unexercised or actually broken decides whether this is an L or a 2×L. Find out in
the first hour, not the third day.

Paste everything below the line into a fresh session. It is self-contained.

---

**First action: rename this session to `slice-3-services`** so it is identifiable in
Remote Control instead of appearing as a machine name.

Read, in full, before touching anything:

1. `CLAUDE.md`
2. `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` — the parent
   programme. §1.6, §1.7, §3 **Invariants**, §4.3 concurrency rules, §7 "Slice 3",
   §8 backfill architecture, §9 integration surface, §10.
3. `docs/superpowers/specs/2026-08-12-media-pool-slice-1a-design.md` — the reference
   implementation for a backfiller through the manual write lane.
4. The slice 0b checkpoint (parent §12) — the manual lane you will write through.

## Rule zero — you may not assume any checkpoint is true

Parent invariant #5: **no slice may cite another slice's checkpoint as evidence for
its own claims.** Invariant #6: **registration is not execution.** Re-derive every
figure below from dev before building on it. Where dev and this prompt disagree, dev
wins and you say so in the spec.

### Entry gate — run these first, paste output into the spec's §1

```sql
-- The shape of what you are migrating. At 2026-08-12: fresha/live 59,
-- owner-authored/live 18, owner-authored/deleted 3, fresha/deleted 2 = 82.
SELECT source, is_manual, (deleted_at IS NOT NULL) AS deleted, count(*)
FROM site.services GROUP BY 1,2,3 ORDER BY 4 DESC;

SELECT count(*) FROM site.service_categories;              -- expect 18
SELECT count(*) FROM site.service_category_assignments;    -- expect 61

-- THE UNKNOWN. Expect 0. If it is still 0, unit 1 is a diagnosis, not a backfill.
SELECT count(*) FROM content.source_items WHERE kind = 'service';
SELECT s.source_key, st.stream_name, s.last_run_at, s.health, s.consecutive_failures
FROM ingest.sources s LEFT JOIN ingest.streams st ON st.source_id = s.id
WHERE s.source_key = 'fresha';

-- The manual lane 0b built — you write through it, you do not reinvent it.
SELECT kind, count(*) FROM content.sources GROUP BY 1;
```

### The two-surface rule — DECIDED 2026-08-12, do not re-open

Services render on **two different public surfaces** today.
`SitepageDataResolverService::buildServicesData()` filters `->whereNull('source')`:

```php
// Manual services only — Fresha projections belong to the booking
// surface (the Fresha selection blob), never the services section.
->whereNull('source')
```

So the 18 owner-authored services render in the **services section**, and the 59
Fresha-projected ones render on the **booking surface**. Neither set is hidden.

**Owner decision: preserve the split.** Both sets become `content.items`, but the
two public surfaces stay distinct. After convergence the filter is on the item's
**source kind** (`manual` vs the Fresha connection source) instead of on a
`source` column — same outcome, different mechanism.

**This is the trap in this slice.** Services also get a pool (below), and the naive
implementation puts every service in one pool and renders them in one place — which
is precisely what "preserve the split" forbids. The pool governs *which items are
eligible*; the surface filter governs *which eligible items render where*. Write a
test that fails if a Fresha-sourced service appears in the services section, and one
that fails if an owner-authored service appears on the booking surface.

## Scope

### Unit 1 — Prove or fix the projector
`FreshaServiceProjector` exists and is registered (`ProjectorRegistry`), and
`fresha/services` streams are provisioned on 4 sources with recent `last_run_at` —
yet zero records landed. Determine which:

- the connector fetches but the projector returns null (shape drift), or
- the stream lands `record_versions` but projection skips it, or
- the fetch itself returns nothing.

`ingest.anomalies` and `ingest.record_versions` for those sources are where the
answer lives. **The deliverable of unit 1 is the diagnosis**, and if the projector
needs repair, that is a re-scoped slice — say so before writing a backfiller.

### Unit 2 — Identity and coords
`site.services.external_id` is the Fresha `serviceId` (`s:…`) and is described as
"projection identity; duplicate ids in one scrape collapse to one row". That is your
coord component. Owner-authored rows have no `external_id` and take
`manual:{legacy_uuid}` per parent §8.1.

Three states to preserve, not two:
| `source` | `is_manual` | Meaning |
|---|---|---|
| NULL | false | owner-authored from scratch |
| `fresha` | false | projected, untouched |
| `fresha` | true | projected then **owner-edited — the re-scrape must never overwrite it** |

The third state is the trap. `is_manual` on a Fresha row means "sync broken
deliberately"; `/services/{id}/resync` reverts it. Whatever you map it to must
survive a subsequent Fresha run.

### Unit 3 — Categories → collections
`service_categories` (18) → `content.collections`; `service_category_assignments`
(61) → `collection_items`. Both are empty today, so you are the first user of them —
read their DDL rather than assuming a shape.

### Unit 4 — Pricing → offers
`price_cents` + `currency_code` + `duration_minutes` → `content.offers`
(`amount_minor`, `currency`, `qualifier`) plus `f_duration`. Note
`services_price_cents_check CHECK (price_cents >= 0)` — a zero price is legal and
means free, which maps to `qualifier='free'`, not `'exact'` with 0.

### Unit 5 — Soft delete
Legacy `deleted_at` (+ `deleted_origin`) → `content.items.removed_at`. **Never write
`content.source_items.removed_at` for a user deletion** — that column is cleared on
reappearance, so a subsequent Fresha run would resurrect a service the owner
deleted. Parent §7 slice 3 states this; assert it in a test.

### Unit 6 — The pool — DECIDED, services get one
Owner decision 2026-08-12: **all four remaining commerce types get pools.** Add a
`PoolRegistry` entry (`POOLS`, `PAGE_KEYS`, `PAGE_LABELS`, and a `SECTION_SHAPE`
block if the default rule does not fit), and provision sections for existing users.
`buildPools()` loops all `POOLS`, so this ships on the public wire with no
payload-builder change.

- **Not** in `LATEST_TAG_POOLS` — a "latest service" is meaningless.
- Existing `hiddenServiceIds` curation migrates into pool **excludes**, exactly the
  way slice 2 migrated `hiddenEventIds`. Read that implementation before writing
  yours; it is the reference.
- The pool holds **both** source kinds. The two-surface rule above still governs
  what renders where — the pool is eligibility, not placement.

## Non-negotiables

- **Cache invalidation is three lanes.** Every raw-write seam must `BuildState::bump($siteId)`, touch `site.sites.updated_at` (the payload cache key composes from it), and dispatch `CloudflareCachePurgeJob`. There is **no CI check** enforcing this despite `BuildState`'s docblock claiming one. Assert it directly.
- **`mergeInto()` hard-deletes uncurated merged-away items** (parent §8.3). A Fresha run landing after your backfill must leave owner-authored services alive. `preferOwnerAnchored()` exists — verify it covers `service`, and assert it with a real connector run, not a unit test.
- **Backfill is production code** under `app/Services/Migration/`, artisan command, `--dry-run`, idempotent, counts reported. Wire the directory into the audit lens scope map if 1a has not already.
- **Schema changes are raw SQL** under `supabase/migrations/`, never Laravel migrations. Pre-assigned prefix block for this slice: `20260813090000`–`20260813099999`.
- **Tests run SQLite, production is Postgres.** Verify `offers_qualifier_check` and every constraint-bound write against the DDL.
- **`UserServiceController` is ~14 endpoints** (`/services` CRUD, `/reorder`, `/resync`, `/restore`, `/service-categories/*`, `/{id}/category`, `/reorder-layout`). Every one is a wire contract. Changes go in the manifest.
- **DSAR:** `DataExportPayloadBuilder` streams `site.services` as a named export section and pins `services` / `service_categories` in its declared return shape. Do not break it; slice 7 drops the tables, so the export must read `content.*` by then.

## Process — stop at every gate

1. **Recon + entry gate.** Unit 1's diagnosis. **STOP — sign-off** on whether this is still an L.
2. **Brainstorm** (`superpowers:brainstorming`) — the scope question and the three-state mapping are genuine decisions.
3. **Spec** → `docs/superpowers/specs/2026-08-12-slice-3-services-design.md`. **STOP — sign-off.**
4. **Plan** (`superpowers:writing-plans`) → `docs/superpowers/plans/2026-08-12-slice-3-services.md`. **STOP — sign-off.**
5. **Implement** in a dedicated worktree, per unit: plan → implement → independent review → tick.
6. **Independent review** of the whole diff. **STOP — sign-off** before verification.
7. **Verify on dev.** Live SQL assertions, output pasted into a parent-spec checkpoint. Wire manifest at `docs/wire-changes/2026-08-12-slice-3-services.md`.
8. **Merge + push.** Rebase onto `development`, full suite + PHPStan + Pint green, **STOP — explicit sign-off**, then merge and push. Never push to `production`.

## Definition of done

`site.services`' 82 rows are represented in `content.*` with all three source/manual
states preserved, categories in collections, prices in offers, owner deletions in
`removed_at`; a Fresha run after the backfill destroys nothing; the coverage gate
(parent §8.4 — coord coverage, **not** row-count equality) is green; checkpoint and
wire manifest committed. `site.services` is **not** dropped — that is slice 7.
