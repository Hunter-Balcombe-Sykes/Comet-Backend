# KICKOFF PROMPT — Slice 3b: Fresha services → `content.*`

Part of `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` §7
"Slice 3". Slice 3 was split on 2026-08-13; **3a is merged and live on dev**
(`2d54c0438`). This is the second half.

3a moved the 21 owner-authored services onto `content.*` and switched the public
services section, 8 dashboard endpoints and the DSAR export to read them there.
3b does the Fresha half: fix the connector, land the 61 scraped services
natively, migrate categories, and cut over the 9 remaining endpoints.

Paste everything below the line into a fresh session. It is self-contained.

---

**First action: rename this session to `slice-3b-fresha`** so it is identifiable
in Remote Control instead of appearing as a machine name.

Read, in full, before touching anything:

1. `CLAUDE.md`
2. `docs/superpowers/specs/2026-08-12-slice-3a-services-owner-authored-design.md`
   — what 3a shipped, and §7 "Out of scope — carried to 3b", which is your scope
   statement.
3. `docs/wire-changes/2026-08-13-slice-3a-services.md` — the contract 3a left.
4. `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` — §3
   **Invariants**, §4.3 concurrency, §8 backfill, §9 integration surface, §10.
5. `app/Services/Content/ManualServiceItems.php` and `ManualServiceWriter.php` —
   the shared read/write collaborators 3a extracted. **Use them. Do not write a
   fourth copy of their predicates**; a fourth copy is what caused three of 3a's
   final-review blockers.

## Rule zero — you may not assume any checkpoint is true

Parent invariant #5: no slice may cite another's checkpoint as evidence.
Invariant #6: registration is not execution. Re-derive every figure below from
dev. Where dev and this prompt disagree, dev wins and you say so in your spec.

### Entry gate — run these first, paste output into your spec's §1

```sql
-- The shape of what you are migrating. At 2026-08-13: fresha live 59, deleted 2.
SELECT source, is_manual, (deleted_at IS NOT NULL) AS deleted, count(*)
FROM site.services GROUP BY 1,2,3 ORDER BY 4 DESC;

-- 3a's output. Expect ~21 service items on the MANUAL source. If this is 0,
-- the backfill never ran on dev — stop and find out why before building on it.
SELECT cs.kind, count(*) FROM content.source_items si
JOIN content.sources cs ON cs.id = si.source_id
WHERE si.kind = 'service' GROUP BY 1;

-- Your targets. 3a populated NEITHER — every live service category is Fresha's.
SELECT count(*) FROM site.service_categories WHERE deleted_at IS NULL;  -- expect 16
SELECT count(*) FROM site.service_category_assignments;                 -- expect 61
SELECT count(*) FROM content.collections;
SELECT count(*) FROM content.collection_items;

-- Unit 1's defect. Expect health='unavailable' and services failing every run.
SELECT s.source_key, st.stream_name, s.last_run_at, s.health, s.consecutive_failures
FROM ingest.sources s LEFT JOIN ingest.streams st ON st.source_id = s.id
WHERE s.source_key = 'fresha';

-- The stored selections. mode='employee' rows carry an employeeId you will need.
SELECT payload->'selection'->>'mode' AS mode,
       payload->'selection'->'employee'->>'employeeId' AS employee_id,
       jsonb_array_length(COALESCE(payload->'selection'->'services','[]'::jsonb)) AS n,
       count(*)
FROM site.platform_connections
WHERE platform='fresha' AND deleted_at IS NULL GROUP BY 1,2,3;
```

## Scope

### Unit 1 — Make the Fresha connector return services

**Mind the name collision first.** Two current classes are called
`FreshaServiceProjector`: `App\Services\Platforms\FreshaServiceProjector` (fed by
`FreshaFetch`, writes `site.services`, works — it produced the 59 live rows) and
`App\Ingest\Projection\FreshaServiceProjector` (fed by `FreshaConnector::pull()`,
writes `content.*`, has landed **zero** records). `ProjectorRegistry.php:28` maps
`fresha/services` to the ingest one. Reasoning about "the projector" without
fully qualifying it is reasoning about an ambiguity.

**The defect.** `FreshaConnector.php:239` sends `shouldShowAllEmployees: true`.
Fresha returns its employee-picker screen, whose `screenServices` is `{}`, so
`servicesMessages()` finds no `screenServices.categories` and yields
`Unavailable` every run. Verified live against three real dev slugs 2026-08-12:

```
allEmployees=true   -> screen=BookingFlowScreenAllEmployees  screenServices={}  categories=None
allEmployees=false  -> screen=BookingFlowScreenServices      categories=5/12/7  services=25/40/22
```

The connector's `Unavailable` message blames a rotated persisted-query hash and
points at a re-pin runbook. **The hash is valid** — every probe returned HTTP 200
with a well-formed `bookingFlowInitialize` and no `errors`. Correcting that
message is half the fix; left alone it sends the next reader to re-pin a hash
that is fine.

Confirm the probe yourself before changing the variable (invariant #5).
`location` is null in the corrected response too, so the `profile` stream keeps
degrading to its `no_profile_fields` Note — expected, out of scope.

### Unit 2 — The connector follows `selection.mode` — DECIDED 2026-08-13, do not re-open

| Stored selection | Connector fetches |
|---|---|
| `mode: 'employee'` + employee id | that employee's menu, at **their** prices |
| `mode: 'storewide'` | the location menu, at store prices |

**An earlier decision (2026-08-12) said fetch storewide always and reduce to the
selection with pool excludes. Live data disproved it and it is reversed.**
Fresha quotes the storewide menu as `from <cheapest staff member>`. Measured on
`brotherwolf-south-melbourne…`, comparing the stored employee selection (23
services) against a live storewide fetch (25):

```
Premium Haircut & Beard Trim   employee $120   storewide "from $108"
Men's Cut                      employee  $70   storewide "from $63"
Clipper Cut                    employee  $35   storewide "from $31.50"
identical: 1   DIFFER: 22   absent from storewide: 0
```

Excludes govern **which** items render; they cannot change what a rendered item
costs. Publishing storewide on an individual's booking page understates 22 of 23
prices. Membership divergence is the half excludes DO fix and it is small: 2 of
the 25 storewide rows are another barber's `$80 Barber Membership` tier.

**The plumbing, and why it does not breach the connector boundary.** Connectors
take `Pull` + `Io` and read no user data — verified, not one touches the database
— and that must stay true. The **provisioner** writes the employee id onto the
ingest source at connect time; `RunExecutor` passes it in `Pull.config` beside the
existing `scope`/`scope_n` (`RunExecutor:76-82`); the connector stays a pure
function of (identifier, config, Io). It is a config widening at that seam, not a
connector reading a connection. **Slices 4–7 inherit the widened config — tell
them.**

**There is no undecided state to design for.** `FreshaStaffMatcher::matchWithTier()`
returns no match on a blank name, no match, or a TIE, and `FreshaAutoSelector`
collapses all three into `mode: 'storewide'` before anything is stored. So the
connector never sees "we could not tell who this is" — it sees a storewide
selection, which is exactly right for a pre-account or unmatched site. Store
prices on a store menu were never the defect; store prices on an *individual's*
menu were.

### Unit 3 — The zero-price trap. Read this before writing any offer mapper.

Slice 3's original kickoff said *"a zero price is legal and means free, which
maps to `qualifier='free'`"*. **All 61 Fresha rows carry `price_cents = 0` and
not one of them is free.** `App\Services\Platforms\FreshaServiceProjector:374`
reads `is_numeric($priceValue) ? (int) round($priceValue * 100) : 0` — zero is
the *unparsed* fallback, not a price. Applying that rule would publish **Free** on
61 real salon services.

The rule is right for hand-entered data and wrong for scraped data, and the
discriminator is the **source**, not the integer. 3a's mapper keeps it for
owner-authored rows, where a typed 0 does mean free. **Do not reuse 3a's mapper
for Fresha rows.** Their honest price is the connector's own display string
(`"from $108"`, `"free"`, `"$120"`), which maps to `qualifier` `from` / `free` /
`exact` respectively. Durations arrive as ranges too (`"25 mins - 50 mins"`).

Slice 5a verified the mirror image for shop: `ShopifyScraper:201` leaves an
unknown price NULL with no `: 0` fallback, so there a zero genuinely means free.
Two writers, same column, opposite meanings.

### Unit 4 — Categories → collections
`site.service_categories` (16 live, **all Fresha**) → `content.collections`;
`site.service_category_assignments` (61, all Fresha) → `collection_items`.

**Slice 5a is probably there before you** — it populates both tables (9
storefront rows, 51 links). Two conventions it establishes; match them or reject
them deliberately, do not arrive at a third by accident:

- `collections.kind` is **free text with no CHECK constraint** (verified on dev).
  5a uses `kind = 'storefront'`, `is_user_created = false` for machine-derived
  groups. Fresha categories are machine-derived too.
- **Per-collection behaviour goes in a 1:1 sidecar table, not new columns on
  `collections`.** 5a put its 15 storefront fields in `content.storefronts` rather
  than widening the shared table, precisely so service and menu categories do not
  carry them empty.

### Unit 5 — The 9 remaining endpoints
3a cut over 8 of the 17 service routes. Yours are the rest, all in
`routes/api/user.php`:

`POST /services/resync` (320), `POST /services/{service}/resync` (321),
`PATCH /services/{service}/category` (345), and the six `/service-categories/*`
routes (328–338).

**`updateCategory` is currently gated to Fresha-sourced services** by
`ServicePolicy::updateCategory()`, which denies-as-404 for anything else. That
gate is 3a's, is deliberate, and is documented — owner-authored category
assignment is yours to enable, and the gate comes off when you do.

### Unit 6 — `StaffServiceManagementController` — the whole controller
Nine methods (`index/store/show/update/destroy/reorder/reorderLayout/restore/forceDestroy`)
against `site.services` with **no `source` filtering at any of them**.

**It is worse than "stale reads".** Post-3a, an owner-authored service created
through the user endpoints has **no `site.services` row at all**, so staff cannot
see it, edit it, or delete it — not merely see it stale. And a staff edit to a
row that does exist writes a lane nothing public reads.

Bounded while it stands (staff-only surface, production carries zero customer
accounts) but silent — a staff edit returns 200 and changes nothing. **3b must
not close without this.**

## Non-negotiables

- **Cache invalidation is three lanes**, all independent: `BuildState::bump($siteId)`,
  `UPDATE site.sites SET updated_at`, `CloudflareCachePurgeJob::dispatch($subdomain)`.
  There is **no CI check** enforcing this despite `BuildState`'s docblock claiming
  one. `ManualServiceWriter::invalidate()` is the reference.
- **Never write `content.source_items.removed_at` for a user deletion** — it is
  cleared on reappearance and would resurrect a deleted service.
  `content.items.removed_at` is one-way and is the correct home. `restore` may
  clear it, but only from the explicit endpoint, never from the projection path.
- **The two-surface rule still holds.** Owner services render in the services
  section, Fresha ones on the booking surface. 3a enforces it via
  `content.sources.kind = 'manual'`. Write a test that fails if a Fresha service
  reaches the services section.
- **`deleted_origin` is load-bearing.** `FreshaServiceProjector:180-195` reads it
  to decide whether a returning service is restored or stays suppressed. The 2
  soft-deleted Fresha rows are `deleted_origin='sync'` — scrape departures, which
  current behaviour restores on reappearance. **Zero** carry `'user'`.
- **Run the Postgres lane.** `composer test:pg` (`tests/Postgres/`,
  `phpunit.pg.xml`). CLAUDE.md now mandates it for `ProjectionWriter` changes.
  Slice 5a shipped two bugs through a green 7830-test SQLite suite that Postgres
  rejected — a bare column in `ON CONFLICT DO UPDATE` (42702) and a timestamptz
  format assertion. That lane's stand-in DDL is hand-written and drifts.
- **`composer test` needs `COMPOSER_PROCESS_TIMEOUT=0`** — the suite now exceeds
  composer's 300s default and the timeout looks like a hang.
- Schema changes are raw SQL under `supabase/migrations/`. **3a consumed no
  prefix**, so the block `20260813090000`–`20260813099999` is free.
- Tests run SQLite, production is Postgres. Verify constraint-bound writes against
  the DDL, not a green suite.

## Inherited hazards — 3a paid for these, do not rediscover them

- **`services_user_sort_order_uq` is `UNIQUE (user_id, sort_order) WHERE deleted_at IS NULL`
  — global per user, NOT scoped by `source`.** Renumbering only one half collides
  with the other and 500s. 3a renumbers the user's whole live legacy set in one
  pass. The test harness now carries this index (`setupServicesTable()`); before
  3a added it, the defect could not fail a test.
- **A test passing is not a test biting.** 3a found four separate cases —
  including a three-lane cache assertion that stayed green with the lane deleted,
  and four validation tests each tripping a different guard than the one they
  named. Mutation-check anything load-bearing: break it, watch the test go red,
  restore.
- **Grepping one predicate does not find every reader.** 3a's inventory was built
  from `whereNull('source')` — five hits, all handled. Two further owner-services
  readers carry no source filter at all and were invisible to it:
  `UserCacheService::getActiveServices()` (serves `GET /api/me`; caught only by
  the final whole-branch review) and `ContentFreshness:89-98` (still
  un-migrated — **yours if you touch freshness**).
- **An inactive service is a pool EXCLUDE**, not a column. Both the write side
  (`ManualServiceWriter::exclude()`) and every read gate must honour it — 3a
  fixed the write side in one task and missed the read side until the final
  review.

## Known open items you inherit

- `ContentFreshness:89-98` still reads `site.services` for the Services-page
  freshness boost, so new owner services never move it. Analytics ranking only.
- The 3 retired owner services carry no `section_items` pin, so
  `mergeInto()`'s curation check does not protect them.
- `ProjectionWriter` resolves through bare unscoped `DB::table()` calls
  throughout. Production is safe (`DB_CONNECTION=pgsql`) but `DataExportTestCase`
  already diverges `database.default` within this repo's own suite.
- `CloudflarePurgeService::purgeHandle()` carries three raw un-deduped `report($e)`
  calls; `CloudflareCachePurgeJob` self-dispatches three follow-ups, so one site
  save can produce up to **12** reports. Recorded in the slice-7 and slice-4
  prompts; remedy is `EscalatesRepeatedFaults`.
- `FreshaAutoSelector` warns that storewide is the common outcome for non-person
  handles, so a large salon can exceed `config('partna.limits.pagination.services_max')`
  (500) — past which the dashboard truncates and the owner cannot reach the tail
  to delete it. The pool inherits this.

## If reality diverges, update the downstream prompts — do not just note it

A checkpoint is not a communication channel. Parent invariant #5 forbids citing
one as evidence, so a discovery written only into your own checkpoint guarantees
the next session never acts on it. **Edit their prompt**, in place, before you
merge — say the fact, not the story.

Owed on merge: `PoolRegistry`'s four const arrays collide with slice 5b's `shop`
entry and both edit the same docblock sentence. It is a union, not a design
conflict. Whoever merges second re-runs `PoolRegistryTest` **and** the pool
provisioning tests **after** resolving — a union merge that drops half a const
array still passes every test written by the branch that added the other half.

## Process — stop at every gate

1. **Recon + entry gate.** Confirm the Unit 1 probe against the live vendor.
   **STOP — sign-off.**
2. **Brainstorm** (`superpowers:brainstorming`).
3. **Spec** → `docs/superpowers/specs/2026-08-13-slice-3b-services-fresha-design.md`.
   **STOP — sign-off.**
4. **Plan** (`superpowers:writing-plans`). **STOP — sign-off.**
5. **Implement** in a dedicated worktree, per unit: plan → implement →
   independent review → tick.
6. **Independent whole-branch review.** 3a's final review found three blockers no
   per-task review could see. **STOP — sign-off.**
7. **Verify on dev.** Live SQL assertions pasted into a parent-spec checkpoint,
   plus a wire manifest.
8. **Merge + push.** Rebase onto `development`, full suite + PG lane + PHPStan +
   Pint green, **STOP — explicit sign-off**, then merge. Never push to
   `production`.

## Definition of done

The 61 Fresha services are represented in `content.*` with honest per-staff
prices, categories in `content.collections` and memberships in `collection_items`,
the 9 remaining endpoints reading and writing `content.*`,
`StaffServiceManagementController` cut over, the booking surface rendering from
the pool, and a connector run after the backfill destroying nothing. The two
surfaces stay distinct. `site.services` is **not** dropped — that is slice 7.
