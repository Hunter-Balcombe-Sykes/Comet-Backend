# KICKOFF PROMPT — Slice 3: Services → `content.*`

Part of `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` §7
"Slice 3". Runs concurrently with 1b and slice 5 (parent §4.3 rule 1 — distinct
kinds). Blocked by nothing; 0b is merged.

**This slice opens on a diagnosed defect.** `App\Ingest\Projection\FreshaServiceProjector`
has landed **zero records** (parent §1.6). The cause was established against dev on
2026-08-12: `FreshaConnector` asks Fresha for the wrong screen, so every run yields
`Unavailable` and the projector is never reached. Unit 1 is a one-variable fix plus a
corrected error message.

**F7 is closed and is not this defect.** The `selection: null` / 304 mechanism handed
over by `docs/reviews/2026-08-12-instagram-build-wave-DEFERRED.md` was fixed at
`2ca21904e` on 2026-08-11 and governs the **legacy** `site.services` lane. That review
has been corrected in place; read it for the record, not for a task.

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

-- Expect 0 (unit 1). The sources are NOT healthy: health='unavailable',
-- consecutive_failures=3, services stream failing every run since 2026-07-28.
SELECT count(*) FROM content.source_items WHERE kind = 'service';
SELECT s.source_key, st.stream_name, s.last_run_at, s.health, s.consecutive_failures
FROM ingest.sources s LEFT JOIN ingest.streams st ON st.source_id = s.id
WHERE s.source_key = 'fresha';

-- The failure itself. Expect every 'services' entry 'unavailable', and 'profile'
-- 'ok' with a no_profile_fields note — the signature of a 200 response carrying
-- neither screenServices.categories nor location.
SELECT r.source_id, r.outcome, r.started_at, r.detail
FROM ingest.runs r JOIN ingest.sources s ON s.id = r.source_id
WHERE s.source_key = 'fresha' ORDER BY r.started_at DESC LIMIT 8;

-- Legacy-lane state. The two rows with a selection hold mode='employee' and
-- 23 + 36 services = the 59 live source='fresha' site.services rows.
-- NOTE: test connectMode, NOT payload->>'source' (which is routing provenance),
-- and do NOT test jsonb_typeof(...) <> 'array' — FreshaFetch's guard is
-- is_array(), which a decoded JSON OBJECT satisfies. Both real selections are
-- objects, so that predicate reports live rows as blocked.
SELECT payload->>'connectMode' AS connect_mode,
       jsonb_typeof(payload->'selection') AS selection_type,
       payload->'selection'->>'mode' AS selection_mode,
       (payload ? 'teamMenu') AS has_team_menu,
       count(*)
FROM site.platform_connections
WHERE platform = 'fresha' AND deleted_at IS NULL
GROUP BY 1,2,3,4 ORDER BY 5 DESC;

-- The manual lane 0b built — you write through it, you do not reinvent it.
SELECT kind, count(*) FROM content.sources GROUP BY 1;
```

### The two-surface rule — DECIDED 2026-08-12, do not re-open

Services render on **two different public surfaces** today.
`SitepageDataResolverService::buildServicesData()` (`:930`) filters
`->whereNull('source')`. **It is five call sites, not one** — re-express all of them:
`SitepageDataResolverService:930` and `:289`, `ServicesVisibility:27`,
`BookingVisibility:30`, `PurgeSoftDeleted:107`. The two visibility rules decide
whether the section appears at all, so missing them hides or reveals a whole surface:

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

### Unit 1 — Make the Fresha ingest connector return services

**Mind the name collision before reading anything else.** Two current classes are
called `FreshaServiceProjector`:

| Class | Fed by | Writes | State |
|---|---|---|---|
| `App\Services\Platforms\FreshaServiceProjector` | `FreshaFetch` | `site.services` | working — the 59 live `source='fresha'` rows |
| `App\Ingest\Projection\FreshaServiceProjector` | `FreshaConnector::pull()` | `content.*` | 0 records — this unit |

`ProjectorRegistry.php:28` maps `fresha/services` to the ingest class, which never
reads `payload.selection`. Any reasoning about "the projector" that does not
fully-qualify it is reasoning about an ambiguity.

**The defect.** `FreshaConnector.php:239` sends `shouldShowAllEmployees: true`.
Fresha answers with its employee-picker screen, whose `screenServices` is `{}`, so
`servicesMessages()` finds no `screenServices.categories` and yields `Unavailable`
on every run. Verified live against three real dev slugs on 2026-08-12:

```
allEmployees=true   -> screen=BookingFlowScreenAllEmployees  screenServices={}  categories=None
allEmployees=false  -> screen=BookingFlowScreenServices      categories=5/12/7  services=25/40/22
```

All 87 parse through the existing `mapServiceItem()` regex unchanged.

**The error message is the second half of the fix.** The connector attributes the
empty response to a rotated persisted-query hash and points at a re-pin runbook. The
hash is valid — every probe returned HTTP 200 with a well-formed
`bookingFlowInitialize` and no `errors`. Leaving that message in place sends the next
reader to re-pin a hash that is fine.

**Do confirm it yourself** (invariant #5): re-run the probe before changing the
variable. `location` is null in the corrected response too, so `profile` keeps
degrading to its `no_profile_fields` Note — expected, and out of scope here.

**The decision this unit forces.** The ingest lane returns the **storewide** menu;
the legacy lane stores one employee's filtered menu. On dev that is 87 vs 59, so the
two lanes are not row-for-row equivalent and the surplus 28 would become publicly
visible services nobody selected.

**Owner decision 2026-08-12 — the connector fetches storewide, and the selection is
expressed as pool excludes**, seeded during backfill from the existing
`payload.selection` so a visitor sees the same services as today. This is the same
mechanism unit 6 already mandates for `hiddenServiceIds`, so the slice carries one
exclusion mechanism rather than two.

The rejected option was plumbing the selected employee through to the connector via
the source row. It was rejected because connectors take `Pull` + `Io` and read no
user data — verified, not one of them touches the database — and `Pull.config` today
carries only `scope`/`scope_n`. Widening it would change shared ingest plumbing that
slices 4–7 inherit. **Do not re-open this by "just adding the employee id to the
source row".**

Consequence to design for: `content.*` holds MORE services than render (87 vs 59 on
dev). The coverage gate is coord coverage (parent §8.4), so a surplus is expected —
but a test must fail if an unselected service reaches the booking surface.

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
- **Excludes carry unit 1's decision too.** The connector lands the storewide menu,
  so every service NOT in the connection's `payload.selection` is seeded as an
  exclude by the same backfill pass. Two inputs (`hiddenServiceIds`, and
  not-in-selection), one exclusion mechanism — do not build a second.
- The pool holds **both** source kinds. The two-surface rule above still governs
  what renders where — the pool is eligibility, not placement.

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

- **Cache invalidation is three lanes.** Every raw-write seam must `BuildState::bump($siteId)`, touch `site.sites.updated_at` (the payload cache key composes from it), and dispatch `CloudflareCachePurgeJob`. There is **no CI check** enforcing this despite `BuildState`'s docblock claiming one. Assert it directly.
- **`mergeInto()` hard-deletes uncurated merged-away items** (parent §8.3). A Fresha run landing after your backfill must leave owner-authored services alive. `preferOwnerAnchored()` exists — verify it covers `service`, and assert it with a real connector run, not a unit test.
- **Backfill is production code** under `app/Services/Migration/`, artisan command, `--dry-run`, idempotent, counts reported. Wire the directory into the audit lens scope map if 1a has not already.
- **Schema changes are raw SQL** under `supabase/migrations/`, never Laravel migrations. Pre-assigned prefix block for this slice: `20260813090000`–`20260813099999`.
- **Tests run SQLite, production is Postgres.** Verify `offers_qualifier_check` and every constraint-bound write against the DDL.
- **`UserServiceController` is ~14 endpoints** (`/services` CRUD, `/reorder`, `/resync`, `/restore`, `/service-categories/*`, `/{id}/category`, `/reorder-layout`). Every one is a wire contract. Changes go in the manifest.
- **DSAR:** `DataExportPayloadBuilder` streams `site.services` as a named export section and pins `services` / `service_categories` in its declared return shape. Do not break it; slice 7 drops the tables, so the export must read `content.*` by then.

## If reality diverges, update the downstream prompts — do not just note it

**A checkpoint is not a communication channel.** Parent invariant #5 forbids any
slice citing another's checkpoint as evidence, so writing a discovery only into your
own checkpoint guarantees the next session never acts on it. **Edit their prompt.**

When something in this slice turns out different from what was written — a figure
that has moved, a premise that was wrong, a shared file you changed, a convention you
established — you own propagating it **before you merge**:

| You discover / change | Update |
|---|---|
| A parent-spec fact is now wrong (a count, a claim, a constraint) | The parent spec's §1 and its revision note, in place |
| You changed `ProjectionWriter`, `PoolResolver`, `PoolRegistry` or the manual write lane | Every remaining prompt that builds on it — `slice-4-menus`, `slice-5-shop`, `slice-6-reviews`, `slice-7-teardown`, `media-pool-slice-1b` |
| You settled a `SECTION_SHAPE` for priced, undated items | **`slice-4-menus` and `slice-5-shop` explicitly** — slice 4 is told to reuse your convention rather than invent a third |
| You added or reshaped anything under `app/Services/Migration/` | The other backfill prompts — 4, 5, 7 |
| You consumed migration filename prefixes outside your block | Whichever slice owns the block you took from |
| You found a new hazard in shared code | Every remaining prompt, under its non-negotiables |

Two rules for the edit itself:

- **Edit in place; do not append a "correction" section.** A prompt read top to
  bottom must be true. A stale statement with a correction 80 lines later will be
  acted on before the correction is reached.
- **Say the fact, not the story.** "`content.collections` now uses `{shape}`; follow
  it" beats "during unit 3 we discovered that…".

If you find something that invalidates another slice's *approach* rather than a
detail — stop and raise it rather than rewriting their scope unilaterally.

## Process — stop at every gate

1. **Recon + entry gate.** Reproduce the unit 1 failure against dev yourself rather than taking this prompt's word for it — invariant #5. **STOP — sign-off** on the unit 1 fix before designing the rest; a services backfill has nothing to migrate until the ingest lane actually lands records.
2. **Brainstorm** (`superpowers:brainstorming`) — the two-surface rule's mechanism and the three-state mapping are genuine decisions.
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
