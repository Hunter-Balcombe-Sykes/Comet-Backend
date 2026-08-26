# RESULT — Pre-launch hardening PART 3 of 3: bounded reads & scale

**Branch:** `audit-fix/pre-launch-hardening-2026-08-25` · **Commit range:** `effb42ac2..8d48f5739` (7 commits)
**Run:** 2026-08-26, unattended, no sign-off gates fired (§1.1 held — nothing here touched auth, money,
a schema change, or the public wire's shape).
**Nothing pushed. No PR opened.**

**All eight sub-units worked. Zero deferrals.** §1.2 warned to "expect to defer more here than in
PART 1 or 2"; in the event nothing hit a DEFER trigger. One finding closed WONTFIX on a measurement
that refuted its own suggested remedy, which is a §5 outcome, not a deferral.

---

## 1. Ledger

| Sub-unit | Findings (ID + source sweep) | Disposition |
|---|---|---|
| **13a** | `#SCALE-15` (overnight) | **ALREADY FIXED — `9a84b3721`.** Box was already ticked by that commit. Verified against the code, not just the message. No change. |
| **13b** | `#SCALE-13`, `#SCALE-14` (overnight) | **DONE** — `baa54b91e` |
| **13c** | `SCALE-6`, `SCALE-11` (remainder) | **DONE** — `112343929` |
| | `SCALE-12` (remainder) | **WONTFIX — premise refuted by measurement** (§5). The suggested remedy is a regression. |
| **13d** | `#SCALE-16`, `#SCALE-17` (overnight) | **DONE** — `246c634d0` |
| **13e** | `SCALE-5` (remainder) | **DONE** — `b9077c9da` (one half surfaced, not built — see §5) |
| **13f** | `SCALE-8` (remainder) | **DONE** — `8604e44ec` |
| **13g** | `#CACHE-1` ≡ `#SCALE-11`, `#SCALE-10` ≡ `#CACHE-6` (all overnight) | **DONE** — `8d48f5739`. All four ticked. |
| **13h** | `#API-7` (delta) ≡ `SCALE-9` (remainder) | **DONE** — `9207587a5`. Pairing confirmed before working; both ticked. |

**Every finding ID accounted for: 14 closed (13 fixed / already-fixed, 1 WONTFIX), 0 open, 0 deferred.**

### ID-collision handling (§4)

The warning was load-bearing and it cut both ways.

- `SCALE-6/11/12` in 13c are the **remainder** twins. The overnight sweep's `SCALE-11` is a *different*
  finding and is part of 13g; the overnight `SCALE-12` was already WONTFIX from a prior tranche.
- `SCALE-13`/`SCALE-14` also have remainder twins (a migration-timeout finding and a CSV-upload
  finding) — **not** worked here, correctly.
- Running the other way: **`SCALE-6` (remainder) turned out to be the same defect as `#SCALE-13`
  (overnight)** — the same three `site.section_items` reads. 13b fixed it and 13c ticked it with that
  evidence. Two sweeps, two IDs, one bug.
- `#API-7` ≡ `SCALE-9` (**remainder**) was confirmed by reading both entries — same file, same lines,
  same query, same remedy — before any code was written, per 13h step 2.

---

## 2. Measurement table

Every figure is from a real run on a fixture held identical before and after. Queue driver `sync`
throughout (noted per §3, since queued work runs inline and inflates query counts).

| Sub-unit | Metric | Before | After |
|---|---|---|---|
| 13a | — | *(no change made; verification only)* | — |
| 13b `#SCALE-14` | payload bytes materialised, 200 items / 1 connection / ~230 KB payload | **46,065,400 B** in 1 query | **230,327 B** in 2 queries |
| 13b `#SCALE-13` | bytes returned, 2000 `section_items` rows | 432,000 B / 2000 rows | 274,000 B / 2000 rows (−37%) |
| 13c `SCALE-11` | rows returned, 300 items × 3 sources | 900 rows | **300 rows** (1 query either way) |
| 13c `SCALE-11` | heap delta at 3300 items | 6,384,040 B | **375,472 B** (~17×) |
| 13c `SCALE-12` | *(refutation)* N=20,000 rows | `->get()`: 1 query / ~10 MB | `->lazy(1000)`: **21 queries** / ~0–2 MB |
| 13d `#SCALE-16` | heap delta, N=2000 | 12,081,056 B / 1 query | **4,163,168 B** / 21 queries; 1800 jobs both |
| 13d `#SCALE-17` | queries / heap, N=2000 | 2,441 / 4,378,848 B | **2,461 (+0.8%)** / 3,354,216 B |
| 13d `#SCALE-17` | queries / heap, N=20,000 | 20,881 / 40,491,432 B | **20,941 (+0.3%)** / 30,541,928 B |
| 13e `SCALE-5` | pacing, 50-page import @250 ms | 0 sleeps (burst) | **49 sleeps × 250 ms = 12,250 ms** simulated |
| 13e `SCALE-5` | 1-page import / real-clock 3 pages @50 ms | 0 sleeps / n/a | 0 sleeps / **135.5 ms actual** |
| 13f `SCALE-8` | 100-intent inbox GET | 83 queries = 43 reads + **40 WRITES** | **4 queries = 4 reads + 0 writes** |
| 13g `#SCALE-10` | pairs generated, one shared key @ 50 / 200 / 1000 members | 1,225 / 19,900 / **499,500** (1.2 / 6.0 / 122.5 ms) | **200 / 200 / 200** (0.2 / 0.5 / 2.3 ms) |
| 13g `#CACHE-1` | insert statements, same three sizes | 1,225 / 19,900 / **499,500** | **1 / 1 / 1** |
| 13h `#API-7` | public profile render | 82 queries (`identity_candidates` 1) | **81 queries** (`identity_candidates` **0**) |
| 13h | dashboard render (must not change) | 25 queries | **25 queries** — unchanged |

Raw scripts and captured output: `.audit-work/part3/measure-13{b,c,d,e,f,g,h}.php` + `-output.txt`
(untracked, deliberately not committed).

**Two numbers stated honestly rather than flatteringly.** 13h removes **one query per render**, not an
N+1 — the join was already batched by `whereIn($ids)`. And 13d `#SCALE-17`'s memory win is ~24%, which
is exactly the row set that used to be materialised whole; the residual is `ProjectionWriter`'s own
per-batch footprint, which this work does not touch and does not claim to.

---

## 3. The 2026-08-26 identity-scope work is UNDISTURBED

Required by §7, with evidence.

```
$ git diff --stat origin/development...HEAD -- app/Content/Identity/IdentityScope.php
(empty — no changes)

$ git diff origin/development...HEAD -- config/partna.php | grep identity_scope
(no matches — partna.content.identity_scope and identity_scope_max unchanged)

$ git diff --stat origin/development...HEAD -- app/Content/Identity/ app/Ingest/Projection/ProjectionWriter.php
 app/Content/Identity/Resolution.php        | 10 ++++
 app/Content/Identity/Resolver.php          | 54 ++++++++++++++++++--
 app/Ingest/Projection/ProjectionWriter.php | 82 ++++++++++++++++++++++++++++--
 3 files changed, 138 insertions(+), 8 deletions(-)
```

`Resolution.php`, `Resolver.php` and `ProjectionWriter.php` **do** change — that is 13g, which the file
scoped in. What did **not** change: `IdentityScope.php`, the kill switch, the closure bound, the
advisory lock, the transaction boundary, and the resolve scope. `git diff … ProjectionWriter.php |
grep -iE "lock|transaction|advisory"` returns nothing; the only hunks are the `resolve()` call site,
the new cap warning, the `recordCandidates()` rewrite, and a private `identityCap()` helper.

**`5556f0b78`'s PG differential test passes.** `tests/Postgres/ProjectionWriterScopedResolveTest > it
produces the same item mapping scoped as it does whole-kind` — run three times independently (by the
implementer, by the reviewer, and by me directly), green each time.

---

## 4. Suite counts

| Lane | Result |
|---|---|
| Baseline at start (`php artisan test --parallel`) | **9307 passed, 3 skipped, 0 failed** (77 s) — green |
| Final (`php artisan test --parallel`) | **9325 passed, 3 skipped, 0 failed** (exit 0) |
| Postgres lane (`tests/Postgres`, run by me directly) | **249 passed, 3 skipped, 2 failed** |
| `./vendor/bin/pint --test` | clean on every touched file |
| `composer analyse` | `[OK] No errors` (1413 files) |
| `php artisan checkpoint:scan` | 21 passed, 4 warnings, **0 failed** |

**+18 tests.** No pre-existing red carried in on the SQLite lane — the baseline was green.

**The 2 Postgres failures are pre-existing and unrelated.** Both are
`Tests\Postgres\LanderFoldAtomicityTest` at `:32`, `SQLSTATE[42P01] relation "ingest.record_state" does
not exist` — `DROP TRIGGER IF EXISTS … ON ingest.record_state` errors when the *table* is absent, and a
sibling file's `afterAll` drops it. That is the known first-creator-wins DDL-ordering fragility in that
lane. Verified pre-existing the right way: the changed test files were restored from `HEAD` with `cp`
and the lane re-run to **the same two failures** (244 passed there vs 249 now, the delta being this
unit's new tests).

`composer test` (serial) was **not** run: it hits composer's own 300 s process timeout on this machine.
`php artisan test --parallel` was used throughout, as §2.3 specifies.

---

## 5. Surfaced, not worked

### Indexes I wanted and did NOT create (§1.2 trigger 1)

**None.** No sub-unit's fix needed one. Recording the nil return explicitly so the absence reads as a
finding rather than an omission.

### Real work deliberately left undone

1. **Nothing prunes accumulated `site.section_items` rows in `state = 'excluded'`** (`#SCALE-13`'s
   second bullet). They grow for the lifetime of a site and are re-read on every pool resolution. The
   column narrowing shrank the row; nothing bounds the row *count*. A retention rule is a write path
   and a product decision (how long is an exclusion honoured?), not an unattended change.
   **Recommendation:** a `content:prune-section-exclusions` command dropping `excluded` rows whose
   `item_id` no longer exists in `content.items`, which is provably safe — an exclusion for a deleted
   item can never affect a resolution.

2. **`SCALE-5`'s shared per-host delay budget** — the finding's second bullet asks that a user-triggered
   `LinkInBioScanJob` and the scheduled `integrations:refresh` batch not amplify each other against the
   same host. 13e paces *within* one import only. A shared budget would be a per-host token bucket in
   Redis keyed on the **registrable** host (matching this file's existing `$probedHosts` idiom),
   consulted by `LinkInBioImporter::paceNextFetch()` and by whatever issues the batch refresh's
   outbound fetches. That is shared cross-cutting state with its own config surface and coordination
   semantics — a plan-first change under `fix-flow.md` §1a, not an unattended one.

3. **`SCALE-6`'s early-exit query shape for `hasSelection()`** — deliberately refused, not forgotten.
   The comment above that method's review-kind branch records that answering from `site.section_items`
   **alone** is what lets the presence probe succeed where `content.*` is absent, pinned by
   `PresenceProbeEscalationTest` / `PresenceProbeLoggingTest`. A `LIMIT 1` does not drop in there.

4. **`#SCALE-18`** (`ReshapePoolSectionsCommand` loads every matching section unbounded) is the
   identical pattern to 13d's two commands, in a third file, and is **assigned to no unit in this
   file**. It is a one-line `chunkById(500)` drop-in. Not absorbed, because it is a P2 in a different
   file and widening scope in an unattended run is what §1.2 exists to prevent.
   **Recommendation:** fold it into the next tranche as a 10-minute unit alongside a test, since 13d
   has now established both the pattern and the coverage idiom.

### Process defects found in this repo's own audit records

5. **The overnight sweep's lens file and its `CONSOLIDATED.md` disagree about what is ticked.**
   Before this run, `audit-2026-08-18-database-and-queue-scaling.md` read `P1 High: 2 of 6` while
   `CONSOLIDATED.md`'s corresponding section read `P1 High: 6 of 6`; P2 read `1 of 16` vs `7 of 16`.
   Both were internally consistent with their own checkboxes, so a prior fix ticked one file and not
   the other. I ticked both and bumped both, but **the pre-existing divergence is untouched** — a
   reconciliation pass is needed before `archive-done.sh` can be trusted on this sweep, because it
   judges `CONSOLIDATED.md` only.

6. **This tranche's own measurement scripts left artifacts that turned the suite red — twice.**
   `tests/Feature/Scratch/` (an empty directory) and later `tests/Feature/Scratch2Test.php` both tripped
   `AuditPipelineIntegrityTest`, which enforces the CLAUDE.md rule that a new directory under
   `tests/Feature/` must be wired into the audit pipeline's scope groups. The guard worked exactly as
   designed and caught process litter both times. Worth noting for the next unattended run: the
   throwaway-measurement convention needs to name an existing directory, never a new one.

---

## 6. Where I stopped

Nowhere — all eight sub-units were worked to a committed close. §1.5 anticipated stopping partway
("prefer three sub-units done properly with measurements to seven done on vibes"); that trade-off did
not have to be made.

---

## 7. Questions for Josh, with my recommended answers

1. **`SCALE-12` is closed WONTFIX rather than fixed. Is that the disposition you want?**
   *Recommended: yes.* `lazy()` pages with plain LIMIT/OFFSET, and the table's only uniqueness is
   `UNIQUE(site_id, content_type, content_key)` while these queries order by `(content_type, rank)`,
   which has no unique constraint. A demonstration deleted 7 already-visited rows mid-scan and the 7
   rows at ranks 1001–1007 were **never visited** — no error, no log. `ComputeContentPopularityScores`
   upserts that table on a schedule while `PoolResolver::popularityRanks()` reads it, so the race is
   live. Trading a silent-row-loss hazard and 21× the round-trips for ~8 MB is a bad deal. If memory
   ever bites at higher N, the answer is `chunkById()` on the `id` PK, which IS unique.

2. **13f stops persisting the swap resolution during the inbox GET. A cap-lifted intent now stays
   `blocked` in `routing.source_intents` until `accept()` re-resolves it. Comfortable?**
   *Recommended: yes.* The persistence was a pre-warm, never a correctness requirement — `accept()`
   re-resolves before acting, `SuggestionApplier` reads the value off the in-memory object,
   `findIntent()` matches both states, and `CheckStuckSourceIntentsCommand` counts both states together
   so the LIFE-19 backlog alarm does not move. The inbox JSON is byte-identical. If you would rather the
   row self-heal, the clean version is a queued reconciliation pass, not a write on a GET.

3. **13g caps identity-candidate generation at 200 pairs / 100 members per key value. Are those the
   right numbers for your data?** *Recommended: ship as-is and watch the log.* Both are env-tunable
   (`PARTNA_INGEST_MAX_CANDIDATES_PER_KEY`, `PARTNA_INGEST_MAX_MEMBERS_PER_KEY`) and the cap emits one
   `Log::warning` per run naming the user and kind when it bites. If Nightwatch shows it firing for real
   accounts, raise it; if it never fires, the ceiling is comfortably above real catalogues.

---

## 8. Tranche-level summary — what the whole branch now contains

`audit-fix/pre-launch-hardening-2026-08-25` is **29 commits ahead of `development`**, 87 files changed,
+5400 / −271.

| Part | Outcome |
|---|---|
| pre-pilot blockers | (ran first, see its own record) |
| **PART 1** | 13 of 16 findings closed, 3 deferred — `6e457093d` |
| **PART 2** | 13 of 16 findings closed, 3 deferred — `effb42ac2` |
| **PART 3** | **14 of 14 findings closed, 0 deferred** — this file |

**40 of 46 findings closed across the tranche; 6 deferred, each with a written plan** beside this file:
`DEFERRED-part1-unit-3-life-13-observer-swallow.md`,
`DEFERRED-part1-unit-11d-site-id-validity-oracle.md`,
`DEFERRED-part2-unit-10a-sec-6.md` (+ its rejected patch),
`DEFERRED-part2-unit-12-test-20.md`,
`DEFERRED-part2-unit-14g-priv2.md`.

**The branch is green:** 9325 passed, 3 skipped, 0 failed; pint clean; `composer analyse` clean;
`checkpoint:scan` 0 failed. The Postgres lane carries 2 pre-existing failures documented in §4.

**Do not push or open a PR without Josh's say-so.** Note that `development` has not moved since this
branch was cut, so it should fast-forward cleanly — but re-check before merging, and remember the prod
env has `usesPushToDeploy: true` with **no CI gate**, so `development` is where the real gate runs.

### What this part actually bought

The public sitepage read path lost a multi-megabyte JSONB fan-out, a dashboard-only join and 37% of its
curation bytes. The suggestions inbox stopped writing to the database on a GET. The identity path
stopped being quadratic. Two nightly repair commands stopped loading their whole backlog, and got their
first tests. An importer stopped hammering one host 50 times in a row.

**And four separate places were found where a 9,300-test green suite proved nothing:** `MIN` vs `MAX`
was indistinguishable to every test in the repo; two scheduled commands had no test that called
`handle()`; the inbox's hidden write was masked by `accept()`'s own write; and the batch write's chunk
boundary was uncovered. Each is now pinned by a test that was **mutation-verified to fail** when the
behaviour it protects is broken. That, more than any single query count, is what changed here.
