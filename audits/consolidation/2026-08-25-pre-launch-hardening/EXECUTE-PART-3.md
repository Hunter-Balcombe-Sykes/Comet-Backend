# EXECUTE — Pre-launch hardening PART 3 of 3: bounded reads & scale · UNATTENDED

**Run this by saying:** `execute audit audits/consolidation/2026-08-25-pre-launch-hardening/EXECUTE-PART-3.md`

**This file is written for an UNATTENDED overnight run. Josh is asleep. Nobody will answer a
question.** Every decision this part needs has been made in advance and is written inline as a
`**DECIDED:**` line. There are no sign-off gates in this part. §1 is the no-stop policy; read it
before §4.

**One unit (the original unit 13), split into eight sub-units, ~13 defects.** 13h was an
orphaned finding rescued during the 2026-08-26 refresh. These are the scale
findings: unbounded reads, per-row round-trips, and one quadratic. **Every one of them needs a
measurement, not an opinion** — see §3.
Sourced from `audits/consolidation/2026-08-25-pre-pilot-p2-promotion/BACKLOG-TRIAGE.md`, verified
against `development` at `d52a604c5`.

Follow `scripts/audit/fix-flow.md` for the per-unit plan → implement → **independent** review loop.
**Where this file and `fix-flow.md` disagree, THIS FILE WINS.**

> ### The original unit 1 SHIPPED on 2026-08-26 — do not re-open it, do not disturb it
> `ProjectionWriter`'s identity-resolution scope was deferred out of this tranche, planned, and then
> **implemented and merged to `development`** (`3c88c5925`..`c529f16ac`). Dispositions are final and
> were ticked by **`ad8922d15`**, whose commit body is the authority:
>
> | Finding (overnight-run) | Disposition |
> |---|---|
> | `#CACHE-2`, `#CACHE-4` | **Fixed** — `52dcde93a` resolves only the component a run can change |
> | `#SCALE-8` | **Fixed** — `6099b4571` bounds the accumulator's per-entry size |
> | `#SCALE-9` | **Fixed** — `df2530971` + `fa97b55f7` batch the slug read out of the refresh loop |
> | `#SCALE-12` | **WONTFIX** — recorded as such, with reasoning, in the sweep |
>
> **`#API-7` was NOT closed by that work and is still open.** `ad8922d15` settles the pairing my
> earlier draft got wrong: *"#API-7 is NOT closed by this work and stays open — it pairs with the
> remainder sweep's `SCALE-9`, not ours."* It is now **sub-unit 13h** below.
>
> **What you must not touch.** That work added `app/Content/Identity/IdentityScope.php` (a transitive
> closure of a change) behind a kill switch — `config('partna.content.identity_scope')`, default
> **true**, bounded by `identity_scope_max` (default 2000). `13g` below works inside the same identity
> cycle. **Do not change `IdentityScope`, the kill switch, the closure bound, or the resolve scope.**
> Any of those is a DEFER (§1.2 trigger 3). `5556f0b78` added a PG differential test asserting a
> scoped resolve equals a whole-kind resolve — if your change makes that test fail, your change is
> wrong, not the test.

---

## 0. Run order and git state — READ FIRST

**This part runs LAST.** Required order:

1. `../2026-08-25-pre-pilot-blockers/EXECUTE.md`
2. `EXECUTE-PART-1.md`
3. `EXECUTE-PART-2.md`
4. **This file** — PART 3

**All three PARTs share ONE branch:** `audit-fix/pre-launch-hardening-2026-08-25`.

- If PART 1/2 ran, that branch exists — `git checkout` it and continue. **Do not create a second branch
  and do not rebase it.**
- If it does not exist, create it off freshly-pulled `development` and note the skip in §7.
- **Read `RESULT-PART-1.md` and `RESULT-PART-2.md` if they exist** — their handoff lines name the files
  those parts changed. `PoolResolver.php` in particular is touched by PART 1 unit 3 **and** by 13b
  below; check before editing.

**Never run two PARTs concurrently in the same checkout.** Genuine concurrency would need a
`git worktree` with a **real (non-symlinked) `vendor/` and `.env`** — a symlinked vendor makes every
Feature test fail to bootstrap. Out of scope: **run sequentially.**

Before starting: `git worktree list` and `git status`.

## 0b. Execution policy

- **Plan:** Opus 5 · **Implement:** Sonnet 5 · **Review:** Sonnet 5 — a *separate, independent*
  instance, never the implementer.
- **Combine plan+impl:** YES for S sub-units · **NO for 13f and 13g** (plan and implement as separate
  instances, but do not wait between them).
- **Per-item override:** escalate implement → **Opus 5 for 13g** (identity-adjacent, quadratic).

---

## 1. NO-STOP POLICY — read this before anything else

### 1.1 There are no gates in this part

Nothing here touches auth, money, a schema change, or the public wire's *shape*. `fix-flow.md` §1a
does not fire on any sub-unit in this file. **Proceed through all seven without asking.**

If you find yourself composing a question for Josh, that is a DEFER (§1.2), not a pause.

### 1.2 The DEFER lane — how a large issue exits without stopping the run

**Defer, never escalate, never grind.** Deferring is a successful outcome, not a failure. **Expect to
defer more here than in PART 1 or 2** — scale fixes have a habit of turning out to be architecture.

**DEFER a sub-unit the moment ANY of these is true:**

1. The fix would need a **schema change** — including **adding an index**. An index on a hot table is a
   migration and a lock-risk decision; it is not an unattended change. Several sub-units below will
   *suggest* one: write it down, do not create it.
2. The fix would **change what the endpoint returns** — different items, different order, different
   count. A bounded read that silently drops rows is a correctness regression wearing a performance
   costume. **Bounding is only safe when the bound provably cannot be reached by real data, or when
   the truncation is surfaced.**
3. The fix leads into **`ProjectionWriter`'s identity-resolution scope** (see the box above).
4. The unit turns out to be **L or XL** once the code is read.
5. The **independent review fails twice.** No third attempt, ever.
6. **Three implementation attempts** have not produced a green targeted test run.
7. **You cannot produce a measurement** showing the change helps (§3). An unmeasured scale fix is a
   guess, and a guess that touches a hot path is worse than the finding.
8. It would require editing a file PART 1 or PART 2 changed in a conflicting way.

**What DEFER looks like — do all five, then move on:**

1. Write `DEFERRED-part3-13<x>-<slug>.md` beside this file: finding IDs, what you found, the DEFER
   trigger number, **the measurement you did take**, the plan you had reached, and the concrete next
   step for a human. **A deferral with no written plan is a dropped ball, not a deferral.**
2. Leave the source audit checkboxes **unticked**.
3. `git checkout -- <paths>` any half-finished edit **for that sub-unit only** — never a tree-wide reset.
4. Record it in §7's ledger as `DEFERRED — <trigger>`.
5. **Go to the next sub-unit immediately.** Do not revisit.

### 1.3 Nothing else halts the run — including a red baseline

- **A red suite at baseline is NOT a stop.** Record the failing tests and the commit that broke them,
  carry on.
- **A refuted premise** → close per §5, tick `WONTFIX — premise refuted`, move on.
- **Anything out of scope** → §7 "Surfaced, not worked".
- Sub-units are independent.

**The ONLY conditions that end the run early** (write `RESULT-PART-3.md` first regardless):
`git fetch`/`pull` unrecoverable · foreign uncommitted changes that cannot be safely stashed · disk full.

### 1.4 Forbidden in an unattended run

- **`cloud env:logs` in any form.** No guaranteed exit path; on 2026-08-19 an 11-minute poll loop left
  70 orphaned PHP processes alive for six hours and drove load average to 45. If truly needed:
  `scripts/env/cloud-logs.sh <env> --minutes 2 --json`, **once**, never looped, never backgrounded.
- **Any interactive command.** Set `GIT_EDITOR=true` if a git command might try to open one.
- **Pushing anything.** No `git push`, no PR.
- **`scripts/audit/audit.sh`** and **`scripts/audit/archive-done.sh`**.
- **Touching another branch or worktree.**
- **Any load test against a live environment.** No k6 run, no `dev-api.partna.au` traffic, no
  Supabase-hosted DB benchmark. **All measurement is local** (§3).
- **Making a real third-party or billed call.** 13e touches an importer that fetches — fake upstream
  from `tests/fixtures/recorded/` (`Tests\Support\Fixtures\Recorded`).
- **Reading, printing, logging or committing any secret.**

### 1.5 Time and cost discipline

- **Full suite: at most twice** — baseline (§2.3) and end (§6). Per-sub-unit verification is **targeted**.
- Per sub-unit: **3 implementation attempts, 2 review rounds.** Either ceiling is a DEFER.
- Work sub-units **in order** — 13a is the cheapest and 13g the hardest, deliberately.
- **It is expected and fine to stop partway.** `CLAUDE.md` warns recall degrades past ~100K tokens.
  **Prefer three sub-units done properly with measurements to seven done on vibes.** Record where you
  stopped in §7.

---

## 2. Setup + preconditions

> ### ⚠️ Premise freshness — refreshed 2026-08-26, re-verify anyway
> These findings were originally verified at `d52a604c5`. `development` has since advanced **41
> commits** to **`c529f16ac`**. This file was refreshed against that head on 2026-08-26; the
> per-unit `DECIDED` blocks below reflect it. What landed in between:
>
> - **The whole `ProjectionWriter` identity-scope cluster shipped** (`3c88c5925`..`c529f16ac`) —
>   see each file's own note; `#CACHE-2`, `#CACHE-4`, `#SCALE-8`, `#SCALE-9` are **fixed** and
>   `#SCALE-12` is **WONTFIX**, all ticked by `ad8922d15`. Do not re-open them.
> - **PR #310 `feat/staff-release-claim` + the ManyChat claim-link work** — `ClaimController.php`,
>   `StaffPreAccountBuildController.php`, `PreAccountBuild.php`, `AppServiceProvider.php`,
>   `routes/api.php`, `config/partna.php`, two migrations.
> - `755fdbdbd` storefront `logoMarkSvg` on the public collections wire (`PoolResolver.php`).
> - Earlier: `9a84b3721` (`#SCALE-3`, `#SCALE-15`), `924008fea` (`#SCALE-1`).
>
> **Still treat every premise as unverified until you check it against the code you pulled.** The
> refresh confirmed the units in this file, but `development` moves — this repo's backlog carries a
> measured ~40% already-fixed rate and this tranche has now been overtaken twice.

1. `git fetch && git pull` on `development`.
2. Branch/checkout `audit-fix/pre-launch-hardening-2026-08-25` per §0.
3. Baseline: `php artisan test --parallel` (NOT `composer test --parallel`). Record counts. **Red is
   recorded, not a stop.**
4. **Confirm the deferred-out findings are untouched:**
   `grep -n "resolveItems" app/Ingest/Projection/ProjectionWriter.php` — note the current shape so you
   can prove at the end that this part did not change it.

---

## 3. Measurement is mandatory — read before touching anything

`CLAUDE.md` and this tranche's origin both require it: **do not claim an improvement you have not
measured. "Should be faster" is not a result.**

**The minimum accepted evidence per sub-unit, before and after:**

- **Query count** via `DB::enableQueryLog()` / `DB::getQueryLog()` in a test that exercises the path
  with a realistic fixture — this is the primary metric for every sub-unit except 13a.
- **Peak memory** (`memory_get_peak_usage(true)`) where the finding is "reads everything into memory"
  — 13c, 13d.
- **Bytes fetched** for 13a, where the defect is egress, not time.
- **Item count at which the behaviour changes** for 13g's quadratic — show the growth curve at two or
  three sizes, not one point.

Rules that stop a measurement from lying to you:

- **Measure the same fixture before and after.** A fixture that grew between runs invalidates both.
- **Wall-clock on a laptop is noise** — an N+1 collapsing from 200 queries to 2 is the result; "180ms
  faster" is not.
- **A query count taken under the `sync` queue driver is inflated** — queued work runs inline. Note
  the driver with the number.
- Paste the actual before/after numbers into the commit body and `RESULT-PART-3.md`. A measurement
  nobody can see did not happen.

---

## 4. Sub-units — work in order

> **ID collision warning.** `SCALE-11` appears twice in this file and they are **different findings**:
> `SCALE-11` (remainder) is in 13c; `#SCALE-11` ≡ `#CACHE-1` (overnight) is in 13g. Same for the two
> `SCALE-8`s — the overnight `#SCALE-8` is **deferred out** (unit 1), the remainder `SCALE-8` is 13f.
> **Key every finding by ID plus its source file.** Matching by ID alone will tick the wrong box.
> The tranche also carries two `SCALE-1`s and two `SCALE-3`s. **Neither is a unit in this file, and
> both of the overnight-run ones are already closed on `development`** — `#SCALE-1` by `924008fea`,
> `#SCALE-3` by `9a84b3721`. If you find yourself about to work one, you have matched the wrong twin.
> As of 2026-08-26 the overnight-run `#SCALE-8`, `#SCALE-9` and `#SCALE-12` are **also closed** (box
> above). **Every remaining `SCALE-8`/`SCALE-9` in scope here is the REMAINDER twin** — 13f and 13h.

### 13a — `#SCALE-15`: `MediaMirror` downloads at the wrong cap · S · **EXPECT ALREADY FIXED**

**⚠️ This was almost certainly closed on `development` before this run started.** Commit
**`9a84b3721`** — *"perf(audit): stream mirrored media to disk instead of PHP memory — #SCALE-3,
#SCALE-15"* — states in its own body that the image branch now builds its string *"only after the
15 MB check has bounded it — which also closes #SCALE-15, where an oversized image was buffered in
full at the 80 MB video ceiling before the 15 MB cap rejected it."*

**DECIDED — verify, then tick. Do not re-fix.**

1. `git log --oneline -- app/Services/Media/MediaMirror.php` and read `9a84b3721`.
2. Confirm in the code that the image path is bounded by the **image** cap before the fetch buffers.
3. If confirmed: tick `#SCALE-15` as `ALREADY FIXED — 9a84b3721` with that quote as evidence, record
   it in §7, and **go straight to 13b**. No code change, no test, no measurement.
4. Only if the code does **not** match the commit message: work it as originally specified — select the
   cap from the asset's declared kind **before** the download. In that case measure bytes fetched for
   an oversized image before and after, and note the discrepancy between commit and code in §7, because
   that is a finding in its own right.

⚠️ `reference_getimagesize_is_not_a_gd_decode_gate` — do **not** trust a header-only check to decide
the kind if the decision gates a decode. Choosing a *download cap* from a declared kind is fine; do not
let this drift into changing the decode gate.

### 13b — `#SCALE-13` + `#SCALE-14`: the public hot path reads too much · S/M
- `#SCALE-13`: `PoolResolver` reads **every** `site.section_items` row per section.
- `#SCALE-14`: the full JSONB `platform_connections.payload` is selected per source row on the public
  hot path.

- **DECIDED for `#SCALE-14`:** narrow the `select()` to the columns actually read. This is the safest
  possible scale fix — same rows, fewer bytes. Grep every consumer of the returned rows first; a
  missing column surfaces as a null, not an error.
- **DECIDED for `#SCALE-13`:** if the fix is a `select()` narrowing or a `where` that provably cannot
  drop a row, do it. **If it needs a LIMIT, an index, or a change to which rows come back, DEFER**
  (§1.2 triggers 1 and 2) — this is the public sitepage, and silently dropping a section item is worse
  than reading too many.
- ⚠️ **PART 1 unit 3 also edits `PoolResolver`.** Check `RESULT-PART-1.md` and `git log` for that file
  before editing; reconcile rather than clobber.
- ⚠️ `PoolResolver::resolve()` **provisions a section — it is not a read.** Do not benchmark it in a
  loop against a checkout with no content lane; that 500s via `LinkPoolReader`.
- ⚠️ `reference_live_source_scope_forces_three_tables_into_every_pool_query` — every pool query already
  joins `content.source_items` → `sources` → `platform_connections`. Narrowing a select must not drop a
  column that join needs.
- **Measure:** query count and bytes/row for one public profile render, before and after.

### 13c — `SCALE-6` / `SCALE-11` / `SCALE-12` (remainder): unbounded `->get()`s · S
Three unbounded `->get()` calls. **All three are per-site-scoped, so growth is bounded by one user's
catalogue** — this is hardening, not a live risk. **Do not overclaim.**

- **DECIDED:** prefer `chunk()`/`lazy()` (same rows, bounded memory) over adding a `LIMIT` (fewer rows,
  changed behaviour). A `LIMIT` here is §1.2 trigger 2.
- ⚠️ `->cursor()` does **not** bound memory under `pdo_pgsql` — libpq buffers the whole result set
  client-side regardless of PHP-level iteration. `ProjectionWriter.php:155` records this explicitly.
  **Use `lazy(N)`, which pages with real LIMIT/OFFSET round-trips.**
- ⚠️ If you page with LIMIT/OFFSET, the `orderBy` **must be unique**. A non-unique sort key silently
  skips and duplicates rows across pages — the same trap `ProjectionWriter.php:158-161` documents. Add
  a tiebreak column.
- **Measure:** peak memory over a large fixture, before and after.

### 13d — `#SCALE-16` / `#SCALE-17`: two commands load the whole backlog into memory · S
Same shape as 13c, in two artisan commands.

- **DECIDED:** `chunkById()` or `lazy()`, same rows, bounded memory. Same unique-`orderBy` requirement
  as 13c.
- Commands are the lowest-risk surface in this part — nothing user-facing observes them mid-run.
- **Measure:** peak memory over a large fixture, before and after.

### 13e — `SCALE-5`: `LinkInBioImporter` makes 50 sequential fetches at one host · S
50 sequential fetches at one host with no delay → a WAF block, which presents as an import that
mysteriously returns nothing.

- **DECIDED:** add a small inter-request delay and read it from config — **do not hardcode a sleep**,
  and do not raise `MAX_PAGES`. The T9 grant already set those budgets (`MAX_PAGES 20 → 50`); this
  fix is about pacing, not volume.
- **DECIDED — never sleep on a request-path call.** If this importer can run synchronously in a
  request, gate the delay to the queued path or DEFER. A sleep inside an HTTP request is a worse bug
  than the WAF block.
- ⚠️ **Fake upstream from `tests/fixtures/recorded/`** — no live fetches (§1.4). New fixtures need a
  `MANIFEST.json` row (`RecordedFixtureManifestGuardTest`).
- ⚠️ `reference_recorded_fixtures_vs_gitattributes_eol` — `* text=auto eol=lf` rewrites captures, so a
  MANIFEST hash matches only in the capturing worktree while `git status` stays clean. If a fixture
  hash check fails, suspect this before suspecting the fixture.
- **Measure:** total elapsed and request spacing for a 50-page import against fixtures.

### 13f — `SCALE-8` (remainder): the inbox GET does per-intent work, **and an occasional write** · M
`resolveSwapIncumbent()` runs per intent for up to 100 rows on **every** inbox GET — and sometimes
performs a **write**.

- **DECIDED — the hidden write is the real finding; treat it as the priority.** A GET that writes is a
  correctness and cacheability defect independent of its cost. Establish exactly when it fires before
  optimising anything around it.
- **DECIDED:** if the write can be removed from the read path cleanly (moved to the write path that
  should have done it, or to a queued job), do that. **If removing it changes what the inbox returns,
  DEFER** (§1.2 trigger 2) with the analysis written down.
- The N+1 itself: batch the incumbent resolution into one query keyed by the 100 intents.
- **Plan and implement as separate instances** (§0b) — do not wait between them.
- **Measure:** query count for a 100-row inbox GET, before and after, plus an explicit statement of
  whether any write remains on the read path.

### 13g — `#CACHE-1` ≡ `#SCALE-11` (overnight) + `#SCALE-10` ≡ `#CACHE-6` · M · **implement on Opus 5**
**Four IDs, two defects — tick all four.**
- `#CACHE-1` ≡ `#SCALE-11`: one `insertOrIgnore` round-trip **per identity candidate**.
- `#SCALE-10` ≡ `#CACHE-6`: **uncapped O(m²) candidate generation**, which directly amplifies it.

**Premise re-verified 2026-08-26 — it SURVIVES the identity-scope work.** `recordCandidates()` still
loops `foreach ($candidates as $candidate) { DB::table('content.identity_candidates')->insertOrIgnore([...]) }`
at ~:1587-1594 — one round-trip per candidate, unbatched. Note that a *different* multi-row batching
already landed nearby for `#CACHE-5` (`content.item_anchors`, ~:1161) — **that is not this finding**;
do not tick `#CACHE-1` on the strength of it.

**⚠️ This sub-unit sits inside the identity cycle that shipped on 2026-08-26. Read the box at the top
of this file before touching anything.**
`recordCandidates()` sits inside the identity cycle that `resolveItemsLocked()` holds an advisory lock
across. **You may batch the writes and cap the generation. You may NOT change the resolution scope,
the lock, the transaction boundary, or which candidates are considered.** Any of those is a DEFER
(§1.2 trigger 3).

- **DECIDED for `#SCALE-10`:** cap the candidate generation. **The cap must be surfaced** — log when it
  bites, with the user and kind — because a silent cap on identity candidates means items silently stop
  merging, which is invisible on a green test run. A silent cap here is trigger 2.
- **DECIDED for `#CACHE-1`:** collapse the per-candidate `insertOrIgnore` into one batched
  `insertOrIgnore` per group. Same rows, one round-trip.
- ⚠️ **`composer test:pg` is MANDATORY here, not optional.** `CLAUDE.md`: the PG stand-in DDL is
  hand-written and drifts silently from writer changes, and a green SQLite run says nothing. It turned
  red for 7 tests on slice 5a and two reviews missed it. See §8 for running it locally.
  **If `composer test:pg` cannot be run for any reason, DEFER this sub-unit** — do not ship an
  identity-path change proved only on SQLite.
- ⚠️ `ON CONFLICT` bumps `updated_at` — if anything downstream keys off that timestamp, batching changes
  how many rows get bumped at once.
- **Measure:** query count for a projection run with a realistic candidate set, and the candidate-count
  growth curve at two or three input sizes to show the quadratic is actually capped.
- **Plan and implement as separate instances** (§0b); implement on **Opus 5**.

### 13h — `#API-7` ≡ `SCALE-9` (**remainder** sweep) · S · **NEW — was an orphan**
**⚠️ Read the ID collision warning at the top of §4 first.** This is the **remainder** sweep's
`SCALE-9`, **not** the overnight-run `#SCALE-9` that shipped on 2026-08-26.

**Why it is here.** `#API-7` was listed in the undivided tranche's duplicate-pair table but assigned
to **no unit**, so a unit-by-unit agent would never have reached it. An earlier draft of these files
guessed it paired with the overnight `#SCALE-9`; `ad8922d15` settled that it does not — *"it pairs
with the remainder sweep's `SCALE-9`, not ours"* — and explicitly left it **open**.

**DECIDED — read the finding text before doing anything.** These files do not carry its description,
because the pairing was only settled after they were written.

1. Find both IDs in the source sweeps under `audits/` (grep for `API-7` and for `SCALE-9`, and keep
   the **remainder** sweep's copy — the overnight one is closed).
2. Confirm they are genuinely the same defect. **If they are not, work neither and write it up in §7**
   — a wrong pairing is exactly what produced this orphan.
3. If the premise is already fixed, close it `WONTFIX — premise refuted` per §5 with evidence.
4. Otherwise work it under this file's normal rules, with a measurement (§3), and **tick both IDs**.
5. If it turns out to be M or larger, **DEFER** (§1.2 trigger 4) — it arrived late and is not worth
   blowing the night on.

---

## 5. Protocol for a refuted premise

1. Write the disproof down — the config line, the passing test path + its output, the framework source.
   **For a scale finding, a measurement showing the cost is already bounded IS a disproof.**
2. Tick the box as `WONTFIX — premise refuted` with that evidence inline. A ticked box means *resolved
   as an open question*, not *the code changed*.
3. If a real residual survives, land a test pinning the behaviour so the next sweep does not re-file it.
4. Move on. Do not escalate.

## 6. Per-sub-unit close-out

1. Independent reviewer PASS (fresh instance, never the implementer). **2 rounds max — a second FAIL is
   a DEFER.** Give the reviewer the **measurement** as well as the diff; verifying the number is part of
   the review.
2. **Targeted tests only** per sub-unit. Full `php artisan test --parallel` **once at the end of this
   part**. **`composer test:pg` for 13g.**
3. `./vendor/bin/pint --test` clean; `composer analyse` clean; **`php artisan checkpoint:scan` clean —
   this part touches a lot of raw SQL, so run it.**
4. Tick boxes in the **source** audit file(s) — **all four IDs for 13g** — and bump each file's
   `## Progress`. A Progress block can sit ~80 lines below its section header; find it by content.
5. Commit code + ticked audit files together: `fix(audit): 13<x> — <ids>`. **Before/after numbers and
   the mutation proof go in the commit body.** Commit after every sub-unit.

## 7. Final report — write `RESULT-PART-3.md` beside this file

**Write it even if the run ends early or badly.**

- Ledger, one row per sub-unit: `DONE` / `DEFERRED — <trigger>` / `BLOCKED — <reviewer defects>` /
  `WONTFIX — <reason>`. State the disposition of every finding ID.
- **A measurement table** — before/after per sub-unit, with the metric named. A sub-unit ticked `DONE`
  with no number in this table is not done.
- **Explicit confirmation that the 2026-08-26 identity-scope work is undisturbed**: `IdentityScope.php`
  unchanged, `partna.content.identity_scope` / `identity_scope_max` unchanged, the resolve scope
  unchanged, and `5556f0b78`'s PG differential test still green. Give a `git diff --stat` of
  `app/Content/Identity/` and `app/Ingest/Projection/ProjectionWriter.php` as evidence.
- **Where you stopped**, if you stopped partway, and why.
- **Surfaced, not worked** — including every index you wanted and did not create (§1.2 trigger 1).
  That list is genuinely useful to Josh; write it properly.
- Suite counts (including `composer test:pg` if 13g ran), and any pre-existing red carried in.
- Branch name and commit range. **Do not push or open a PR without Josh's say-so.**
- Anything you would have asked Josh, written as a question with your recommended answer.
- **A tranche-level summary** across all three parts, since this is the last one: what the whole
  `audit-fix/pre-launch-hardening-2026-08-25` branch now contains.

## 8. Notes carried in

- **The Postgres lane runs locally** against a scratch DB on the existing Supabase container:
  `CREATE DATABASE partna_pg_lane_scratch` on `127.0.0.1:54322`, then
  `PG_LANE_DISPOSABLE=1 DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=54322 DB_DATABASE=partna_pg_lane_scratch DB_USERNAME=postgres DB_PASSWORD=postgres DB_SSLMODE=disable ./vendor/bin/pest -c phpunit.pg.xml <path>`,
  then drop it. **Never** override `PG_LANE_DISPOSABLE` against the `postgres` database itself — that
  guard exists to stop the lane provisioning over local dev. **13g requires this.**
- **Back up before mutating** — a mutation script hitting a tool timeout can leave a production file
  dirty. `git diff` after every mutation round.
- **`archive-done.sh` will not archive these sweeps.** Do not run it.
- **Prod facts** (verified 2026-08-25): env stopped; 0 of `content`/`ingest`/`routing`/`catalog`;
  ledger 4 rows; `core.users` = 0. Several findings here **cannot fire on prod** until the schema
  reconciliation lands — say so when ticking rather than implying a live prod risk.
