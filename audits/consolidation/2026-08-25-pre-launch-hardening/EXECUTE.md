# EXECUTE — Pre-launch hardening (2026-08-25)

**Run this by saying:** `execute audit audits/consolidation/2026-08-25-pre-launch-hardening/EXECUTE.md`

~47 distinct defects across ~52 IDs, grouped into **14 units**. None of these blocks a small pilot —
they bite at volume, at cost, or by hiding a failure nobody can see. Sourced from
`audits/consolidation/2026-08-25-pre-pilot-p2-promotion/BACKLOG-TRIAGE.md`, verified against
`development` at `d52a604c5`.

**Run the pre-pilot tranche first** (`audits/consolidation/2026-08-25-pre-pilot-blockers/EXECUTE.md`).
It shares files with units 3 and 9 here, and a merge conflict between two audit-fix branches is
avoidable work.

> **This is a LARGE tranche and it is explicitly OK to stop partway.** `CLAUDE.md` warns that recall
> degrades past ~100K tokens and forbids "clear the backlog" campaigns. Units are ordered so that
> stopping after any unit leaves the branch coherent. **Prefer finishing 6 units properly to
> half-doing 14.**

---

## 0. Execution policy

- **Plan:** Opus 5 · **Implement:** Sonnet 5 · **Review:** Sonnet 5 — a *separate, independent*
  instance, never the implementer.
- **Combine plan+impl:** YES for S units · NO for units 1, 2, 6, 12 (M/L).
- **Per-item override:** escalate implement → Opus 5 for unit 1.

## 1. Gate overrides

`fix-flow.md` §1a gates L-effort and auth work. **Not waived** — units 1 (L, architectural) and
9 (`#SEC-10`, authorization) produce a plan and **wait for sign-off**.

**Must NOT halt the run:** a unit failing review twice → mark `BLOCKED` in §7, move on. A refuted
premise → close per §5, move on. Pre-existing red → record, not yours. Out-of-scope → §7, don't chase.

## 2. Setup + preconditions

1. `git fetch && git pull` on `development`.
2. Branch `audit-fix/pre-launch-hardening-2026-08-25` off freshly-pulled `development`.
3. Baseline: `php artisan test --parallel` (NOT `composer test --parallel`). Record counts.
4. **Landed-work probes:**

   | Probe | Expected | Used by |
   |---|---|---|
   | `grep -n "writeWithJitter\|applyJitter" app/Services/Cache/CacheLockService.php` | both present | unit 2 |
   | `grep -n "EscalatesRepeatedFaults" app/Services/Analytics/ContentPopularityReader.php` | present — the precedent unit 3 copies | unit 3 |
   | `grep -n "wasDisconnected" app/Services/Platforms/Concerns/BuildsAutoSyncFindings.php` | present — landed 2026-08-25 | unit 12 |

## 3. House rules that bite in this tranche

- **⚠️ IDs COLLIDE ACROSS SWEEPS.** `SCALE-7` in the overnight run and `SCALE-7` in the remainder are
  **different findings**; so are the two `SCALE-1`s and `SCALE-3`s. Key every finding by ID **plus
  its source file**.
- **Six duplicate ID pairs — fix once, tick BOTH boxes:** `#SEC-12` ≡ `SEM-6` · `CACHE-2` ≡ `SCALE-7`
  (remainder) · `SCALE-9` ≡ `#API-7` · `#LIFE-16` ≡ `#SCALE-20` · `#LIFE-17` ≡ `#SCALE-21` ·
  `#CACHE-1` ≡ `#SCALE-11`.
- **Audit line numbers are STALE.** Match by symbol.
- **Verify the premise first.** ~40% already-fixed rate in this backlog; the last tranche closed 10
  findings with no code change.
- **Every new assertion mutation-proved**; restore via `cp`, never `git checkout`. A mutation that
  does not go red is a finding about your test.
- **Chained `expect()` aborts at the first failure** — separate statements.
- **`pint --test` is the gate, not `pint`.**
- **A green pre-push hook is NOT a green CI** — `php artisan checkpoint:scan` runs only in CI's
  `test` job. This tranche touches a lot of raw SQL: run it locally before pushing.
- **Performance claims need evidence.** Several units here are scale fixes. Do not claim an
  improvement you have not measured — a query count (`DB::getQueryLog`) or an `EXPLAIN` is the
  minimum. "Should be faster" is not a result.
- **Tests run SQLite, prod is Postgres.** For `ProjectionWriter` changes, `composer test:pg` is
  MANDATORY, not optional (`CLAUDE.md`) — see §8 for running it locally.

---

## 4. Units — work in order

Ordered so the cheap, high-certainty wins land first and the architectural change last.

### Unit 1 — `ProjectionWriter`: scope identity resolution to what changed · L · **GATED**
**Findings:** `#CACHE-2`, `#CACHE-4`, `#SCALE-8`, `#SCALE-9`, `#SCALE-12` (all overnight-run).
**This is ONE architectural change, not five tickets.** Plan it as a unit before touching anything.

Today `projectStream()` calls `resolveItems($userId, $projector::kind())`, which resolves the user's
**entire kind** — so one changed connector record rebuilds the whole identity graph and every cache
for that kind. `#CACHE-2` is the same defect reached from the owner's manual write path. `#SCALE-8`
(whole stream accumulated in memory), `#SCALE-9` (per-item `ensureCurrent()` inside the batch loop)
and `#SCALE-12` (one mirror job dispatched per asset, synchronously, inside the projection loop) are
consequences of the same shape.

- The plan must state the intended boundary — "resolve only the coords touched by this run" — and
  what invariant guarantees correctness when a *neighbouring* item's identity depends on a changed one.
  That is the hard part; get it settled in the plan, not in the implementation.
- **`composer test:pg` is mandatory here** — the PG stand-in DDL is hand-written and drifts silently
  from writer changes. A green SQLite run says nothing.
- Measure: query count and peak memory for a representative stream, before and after.

### Unit 2 — `CacheLockService`: one missing jitter call · S
**Findings:** `CCH-3` + `CCH-6` (remainder). **Re-diagnosed — the finding text is wrong about the cause.**

The findings blame hardcoded TTLs at two call sites. The real bug is one line:
`rememberLockedNullable` calls `writeOrDegrade($key, $value, $ttl)` **directly**, while its sibling
`rememberLocked` routes through `writeWithJitter()` (~:262-277). So every `rememberLockedNullable`
entry expires in fleet-wide lockstep → a synchronised refetch burst at upstream hosts.

**One fix in `CacheLockService` covers every caller.**

- `CCH-6`'s stated premise is already stale: `AppleSearch::itunes()` now reads
  `config('partna.refresh.host_limits.itunes.cache_ttl_seconds')`. Note that when ticking.
- ⚠️ Do **not** "upgrade" `rememberLockedNullable` to `rememberLocked` — its own inline comment
  forbids it, and `CCH-4`/`CCH-7` were both refuted on exactly that point: feeding a null through the
  SWR path poisons the stale twin. Add jitter, keep the nullable semantics.
- Test: assert two entries written in the same tick get different TTLs. Mutation-prove by removing
  the jitter call.

### Unit 3 — Silent-swallow family · S×3
**Findings:** `#LIFE-15` (overnight, `PoolResolver`), `CCH-5` (remainder, `ShortLinkExpander`),
`#LIFE-13` (overnight) **— unless unit 6 of the pre-pilot tranche already fixed `#LIFE-13`; check
first and tick it as already-done if so.**

Same shape as `CCH-11`, closed 2026-08-25: `catch (QueryException) { $x = []; }` or `catch (\Throwable) {}`
with no log, on paths where the failure is invisible.

- `PoolResolver` ~:812 — ingest badges blank silently; same query is on the public hot path.
- `ShortLinkExpander::resolveFinal()` — empty catch body, comment only. A defect or budget exhaustion
  is cached as "not expandable" for 1h with nothing reaching Nightwatch. **The negative TTL is
  deliberate and documented ("Do NOT change these TTLs") — the fix is the log line, not the TTL.**
- **Follow `EscalatesRepeatedFaults`**, the existing precedent — do not invent a new convention, and
  do not turn these into fail-closed. The page must still render.
- Mutation-prove each report assertion with a multi-argument matcher.

### Unit 4 — Scheduler hygiene · S×2
**Findings:** `#LIFE-16` ≡ `#SCALE-20`; `#LIFE-17` ≡ `#SCALE-21` (overnight). Two IDs each — tick all four.
**File:** `routes/console.php` (~:499-502 `platforms:enrich-pending-cards`; ~:506-509 `content:refresh-item-caches`)

Both use `->withoutOverlapping()` with **no expiry argument**, no `runInBackground()`, no `onFailure()`.
After a crash the lock persists for the default 24h and the command silently stops running.
`compute-popularity` (~:154-159) already has the correct shape — copy it.

- Test: assert the schedule definitions carry an expiry and a failure handler. This is a
  configuration assertion; make sure it would actually fail if the arguments were removed.

### Unit 5 — `#CFG-3`: `MAX_BRANDS` disagrees with itself · S–M
**Finding:** `#CFG-3` (delta)

`StoreBrandSeeder.php:53` says `MAX_BRANDS = 5`. `ShopController.php:105` and
`ConnectStoreFromProductJob.php:56` both say `10`, and seven `app/Catalog/Definitions/*.php` comments
say "MAX_BRANDS (10, T9)". **5 is the lone outlier.** A user with 5 brands pastes a 6th store link:
the connection is placed but the brand row is capped (`outcome: capped`), so the store **half-exists
and never renders**.

- Settle which number is intended before changing anything — check the T9 decision record. Then put
  it in **one** place the other two read from, so it cannot drift again.
- Test: the 6th store either fully connects or is cleanly refused — never half.

### Unit 6 — Staff batch onboarding must not time out silently · M
**Findings:** `CACHE-2` ≡ `SCALE-7` (remainder). **One defect, two IDs.**
**File:** `StaffPreAccountBuildController::batch()`

`$cap = 500;` then a bare `foreach ($rows …) { $this->builds->requestBuild(…) }` — 500 synchronous
builds in one HTTP request. **This is the tool you will onboard the pilot cohort with.** On timeout
staff get no response and no `failed[]` list, so they cannot tell which rows landed.

- Mitigating fact: re-uploading is safe — `requestBuild` dedupes and re-serves the live build as
  `reused`. So the fix is about **observability and completion**, not idempotency.
- Options: queue the batch and return a job id; or chunk with a partial-success response listing
  `created`/`reused`/`failed` per row. Either changes the staff wire — check `docs/wire-changes/`
  convention and whether the staff UI consumes the current shape.
- ⚠️ `PreAccountBuildService::requestBuild` dedupes **before** the pairing map — `CLAUDE.md` pins that
  order as deliberate (spec §4.1). **Do not reorder it.**

### Unit 7 — `#JOB-3`: a failed approval must fail the job · S
**Finding:** `#JOB-3` (remainder) — `ApproveEarlyAccessBuildJob`

Four `return` paths after `report($e)`/`Log::warning` (`build_failed`, `build_collision`,
`scrape_failed`, generic `Throwable`) with no `$this->fail()`. Staff approve an early-access signup,
the job hits a failure, and **Horizon shows it processed** — the invitee is never invited.

- Partly mitigated: `build_state` → `FAILED` and `report()` reaches Nightwatch, so a signal exists —
  the *Horizon* one does not. Decide whether `fail()` is right for each of the four paths (a
  collision may legitimately be a no-op) rather than blanket-failing.
- Test: each failure path marks the job failed where intended.

### Unit 8 — `#RANK-2`: colliding pins are silently discarded · M
**Finding:** `#RANK-2` (correctness/actions-ordering-math)

Owner pins two menu/service items to the **same position in the same category**; validation accepts
it (`poolLockPositionsRule()` early-returns on `ItemFamily::CATEGORY_FAMILIES`) and
`PoolOrdering::applyLocks()` drops the collider via `! isset($placed[$lock['position']])`, returning
a bare item list with **no `unavailable` channel** — unlike `ActionSlots::resolve()`, which has one.

- Either validate the collision at the request layer, or surface it through an `unavailable` channel
  the way `ActionSlots` does. Prefer matching the existing pattern.
- Test: two pins at the same position produce a visible outcome, not a silent drop.

### Unit 9 — `#SEC-10`: defense-in-depth authz on `ShopController` · M · **GATED**
**Finding:** `#SEC-10` (unified-actions-security)

Five methods (`updateBrand`, `catalog`, `setProducts`, `addProduct`, `removeProduct`) lack the
`authorizeForUser` second lock. **Verified: all five resolve via `$this->shop->store($user, …)` or
`brandMap($user)` — structurally user-scoped, no cross-tenant reach.** This is the same class as
`#SEC-14`, fixed 2026-08-25, and it is defense-in-depth, not a live hole. **Do not overclaim.**

- The pattern is already applied in the same file at ~:790 and ~:1016 — copy it.
- `authorizeForUser($user, …)`, never `authorize()`.
- Be honest in the tests: if no denial is reachable (because the resolver already scopes), say so
  rather than writing a test that can never fail. That was the correct outcome for `#SEC-14`.

### Unit 10 — Small security hardening · S×4
**Findings:** `#SEC-6` (`ShortLinkExpander` — expanded URL cached 24h with no `SecretParams` pass),
`#SEC-8` (`IriCanonicalizer` — unanchored regex runs *before* the 2048 cap; only scraper callers are
exposed, the user path is bounded by `RouteLinkRequest max:2048`), `#SEC-13` (reorder Form Requests
have no `max:` on `categories`/`service_ids`; `PoolController::reorder` already caps at `max:200` —
copy it), `#SEC-11` (`AnalyticsController::pageview` has no bot filter or dedup — a deliberate
carry-forward; confirm that is still the intent before changing it).

### Unit 11 — Small correctness · S×4
**Findings:** `SEM-8` (`is_int()` gate lets `"1"` skip the contiguity check entirely — the sibling
rule uses Laravel's non-strict `integer`), `SEM-14` (`ConnectionIdentity::matchExisting` folds case
for **every** surface, ignoring the `$foldable` allowlist computed two lines above — collapses two
distinct Discord invite codes; **within one tenant**, no cross-tenant reach), `SEM-17` (`PoolResolver`
sources panel emits timezone-naive timestamps → +10h error for an AEST reader; the correct `->utc()`
is in the same file), `#SEC-12` ≡ `SEM-6` (nonexistent `site_id` → 422 vs real-but-unpublished → 404,
a validity oracle needing a guessed UUID — **tick both IDs**).

### Unit 12 — `#TEST-20`: the single-slot family has no DB backstop · M
**Finding:** `#TEST-20` (delta). **Promoted off the deferred pile — the reasoning changed.**

`seedReservation()`/`seedOnlineOrdering()` read the whole family, then write, with no lock. Two
workers auto-routing the same user concurrently (IG harvest racing GBP enrich) both see an empty
`routing_class='reservations'` family and both write.

**Why this is not the same as `#TEST-19`** (which was closed as DB-backstopped):
`idx_platform_connections_unique_active` keys on `(user_id, surface_key, resource_id)`, so two
*different brands* (OpenTable + Resy) both insert cleanly into a family that is supposed to hold one.
The index saves `#TEST-19`; it does **not** save this.

- `withReservationsXorLock()` already exists in `BuildsAutoSyncFindings` (~:481) — but `applyFinding()`
  takes it too, so wrapping these seeders in it is a real concurrency change. Plan the lock ordering
  explicitly; `LinkRouter`'s comment about keeping the ordering acyclic is load-bearing.
- Harm today is a duplicate button, self-healable by disconnecting — so this is hardening, not a
  data-loss fix. Do not overclaim.

### Unit 13 — Scale: bounded reads · S/M
**Findings:** `#SCALE-15` (`MediaMirror` downloads at the 80 MB *video* cap before the 15 MB image cap
rejects — ~5× wasted egress), `#SCALE-13` (`PoolResolver` reads every `site.section_items` row per
section), `#SCALE-14` (full JSONB `platform_connections.payload` selected per source row on the public
hot path), `SCALE-6`/`SCALE-11`/`SCALE-12` (remainder — unbounded `->get()`s, all per-site-scoped so
growth is bounded by one user's catalogue), `#SCALE-16`/`#SCALE-17` (whole backlog into memory in two
commands), `SCALE-5` (`LinkInBioImporter` — 50 sequential fetches at one host with no delay → WAF block),
`SCALE-8` (remainder — per-intent `resolveSwapIncumbent()`, *and an occasional write*, for up to 100
rows on every inbox GET), `#CACHE-1` ≡ `#SCALE-11` (overnight — one `insertOrIgnore` round-trip per
identity candidate), `#SCALE-10` ≡ `#CACHE-6` (uncapped O(m²) candidate generation, which directly
amplifies it).

**Do these last and do them with measurements.** Several are cheap; a few (`SCALE-8`'s hidden write)
deserve their own scrutiny. Split into sub-units freely.

### Unit 14 — Remainder · S each
`#SEC-4` (unified — Fresha vendor `name`/`description` copied unbounded into `content.f_text`; DB bloat,
dev-only since prod lacks `content`), `#SEC-9` (inline `throw new AuthorizationException` outside the
Policy framework — fails **closed**, doctrine drift only), `#CCH-1` (raw `DB::table` update with no
following refresh, so the edge purge reflects pre-strip settings), `CCH-10` (`brandProducts` has jitter
but **no single-flight** — owner-scoped, one store), `#LIFE-9` (Horizon "Retry" re-bills Mistral OCR;
auto-retry already closed by `$tries = 1`, manual retry is not), `#LIFE-10` (rotated persisted-query
hash indistinguishable from any other GraphQL rejection — `errors` discarded at ~:426), `#PRIV-2`
(a moderation case that never resolves keeps a non-account reporter's PII forever; only resolved cases
prune — won't bite for ~12 months at pilot volume), `#SEC-5` (claim-gate — fresh-AAL2 off pending TOTP
enrolment; **a staged-rollout checklist item, not a defect** — tick it as such with the rollout state).

---

## 5. Protocol for a refuted premise

As the pre-pilot file: write the disproof, tick `WONTFIX — premise refuted` with evidence inline,
land a pin if a residual survives, move on. **Expect several here** — this tranche already contains
two findings (`CCH-3`/`CCH-6`) whose stated cause is wrong, and one (`#SEC-5`) that is a checklist
item rather than a bug.

## 6. Per-unit close-out

1. Independent reviewer PASS (fresh instance, never the implementer).
2. Targeted tests, then `php artisan test --parallel`. **`composer test:pg` for unit 1.**
3. `pint --test` clean; phpstan clean; `checkpoint:scan` clean if you touched raw SQL.
4. Tick boxes in the **source** audit file(s) — **including both IDs of every duplicate pair** — and
   bump each file's `## Progress`. A Progress block can sit ~80 lines below its section header; find
   it by content.
5. Commit code + ticked audit files together: `fix(audit): <unit> — <ids>`.

## 7. Final report — write `RESULT.md` beside this file

Units done / blocked / **not reached** (this tranche is expected to stop partway — say where and why).
Disposition of every finding touched. Measurements for the scale units. Surfaced-not-worked. Suite
counts. Branch name. **Do not push to `development`/`production` without Josh's say-so.**

## 8. Notes carried in

- **Postgres lane, locally:** `CREATE DATABASE partna_pg_lane_scratch` on `127.0.0.1:54322`, then
  `PG_LANE_DISPOSABLE=1 DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=54322 DB_DATABASE=partna_pg_lane_scratch DB_USERNAME=postgres DB_PASSWORD=postgres DB_SSLMODE=disable ./vendor/bin/pest -c phpunit.pg.xml <path>`,
  then drop it. **Never** override the guard against the `postgres` database itself.
- **Back up before mutating** — a mutation script hitting a tool timeout can leave a production file
  dirty. `git diff` after every mutation round.
- **`archive-done.sh` will not archive these sweeps** — they carry findings outside this tranche.
- **Prod facts** (verified 2026-08-25): env stopped; 0 of `content`/`ingest`/`routing`/`catalog`;
  ledger 4 rows; `core.users` = 0. Several findings here **cannot fire on prod** until the schema
  reconciliation lands — say so when ticking rather than implying a live prod risk.
