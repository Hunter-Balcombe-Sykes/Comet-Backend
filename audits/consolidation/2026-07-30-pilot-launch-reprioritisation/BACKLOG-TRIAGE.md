# Backlog triage — 2026-07-30

Disposition pass over the **239 BACKLOG items** from `CONSOLIDATED.md`. This closes with a **decision
per item, not a fix**. Nothing here is scheduled work.

**Outcome: 8 promoted, 96 opportunistic, 135 wontfix.** The promotions change the bucket counts in
`CONSOLIDATED.md` — P0-LAUNCH 6 → 7, P1-LAUNCH 27 → 34, BACKLOG 239 → 231.

## The three dispositions

| Tag | Meaning | Bookkeeping |
|---|---|---|
| **WONTFIX** | Closed permanently. The defect costs less than the fix, or the audit itself disarmed it. | Tick `[x]` with a one-line reason. Never reopen without new evidence. |
| **OPPORTUNISTIC** | No scheduled work, ever. Fix in-passing when the file is already open for real work. | Tick `[x]`. The standing rule in `CLAUDE.md` is what carries it forward, not the checkbox. |
| **PROMOTE** | Genuinely mis-graded. Moves into a P-bucket. | Leave `[ ]`; it now lives in `CONSOLIDATED.md`. |

**Why ticking a WONTFIX/OPPORTUNISTIC is correct, not dishonest:** the checkbox means *"this finding is
resolved as an open question"*, not *"the code changed"*. Leaving 239 boxes open blocks
`archive-done.sh` forever and makes the audit system permanently red, which destroys its signal. The
reason text on each tick is the honest record.

---

## PROMOTE — 8 findings

### → P0-LAUNCH (1)

**`#50`** (07-11 sweep, was P3) — `PublicMenuController::show()` builds the public menu payload with no
Resource class and therefore **no field allowlist, on an unauthenticated public endpoint.**

> Promoted for consistency: this is the same defect class as `#API-1`, which is already P0-LAUNCH.
> Both hand-build a public payload with no allowlist guardrail, so a future internal column reaches the
> public wire silently. `#API-1` covers shop products; `#50` covers the menu — a core product surface.
> Grading them differently was an inconsistency in the source audits, not a real difference.
> **Work it in the same unit as `#API-1`.** `#51` (the dashboard `MenuController` duplicating the same
> shaping logic under different field names) is its natural OPPORTUNISTIC companion.

### → P1-LAUNCH (7)

| ID | Was | Why it doesn't belong in the backlog |
|---|---|---|
| **`#JOB-6`** | P3 | `EffectLedger::once()`'s catch can mask a non-duplicate-key DB error as a silent `'refused'`, skipping a **billed** effect (actor runs, AI extraction) with no log and no exception. Graded as an error-handling nit; it is silently-skipped paid work. Fix is a `getCode()` check before treating the failure as a collision. |
| **`#SLOP-21`** | P3 | `@`-suppressed `preg_match` on catalog-authored regex in `LinkProjector` (3 call sites) means a typo'd detector pattern **fails closed silently** — links stop routing and nothing reports it. Filed under "AI slop / low-value code"; it is a silent-failure mode in routing. |
| **`#TEST-30`** | P2 | `SafeUrlFetcher` — Partna's own **SSRF-protection boundary** — is replaced with `Mockery::mock()` in the Shop URL validation tests, stripping the allowlist, DNS resolution and redirect limits out of every one of them. The tests assert the wrapper, not the defence. Switch to `Http::fake()` with an RFC 2606 domain. |
| **`#TEST-44`** | P2 | `YoutubeFeed::parse()`'s XXE defence has no regression test. The defence is correct today; the risk is a future "fix" adding entity-loading flags and silently reopening the vector. This is exactly the case where a test earns its keep. |
| **`#CFG-16`** | P3 | `Lander`/`EffectLedger`/`SourceScheduler` hardcode **deletion sensitivity** (`TOMBSTONE_RUNS`, `GUARD_THRESHOLD`), **billed-effect abandonment** (`ABANDON_AFTER_SECONDS`) and **scheduler fairness** (`ALPHA`, `STRANDED_AFTER_SECONDS`). Unlike the rest of `CFG-*`, these are knobs you would want to turn *during* an incident, and today that needs a deploy. |
| **`#CFG-8`** | P3 | Google Places retry count + backoff hardcoded. Places is the **only uncapped paid external API** in the project. |
| **`#CFG-9`** | P3 | Apify `run-sync` 110s timeout hardcoded — the odd one out among Apify budgets, every sibling of which is already env-driven. Pair with `CFG-8`. |

`CFG-8`/`CFG-9`/`CFG-16` are one ~1h unit: extract five-ish constants to `config/partna.php`. They are
promoted **as a group on cost-control and incident-response grounds**, not because config extraction is
inherently valuable — the other 16 `CFG-*` items are WONTFIX precisely because they aren't.

---

## OPPORTUNISTIC — 96 findings

No scheduled work. Fix in-passing, in the same commit, when the file is already open.

### The high-value ones — take these the moment you touch the file

| ID | File | Why it's worth the two minutes |
|---|---|---|
| `#SLOP-1` | `AnalyticsQueryService.php` | `topSections()`'s catch logs `analytics.click_query_failed` — the **wrong subsystem**. During a `section_views` outage the log stream points on-call at link clicks. One word. |
| `#JOB-2` | `RunExecutor.php` | Unknown `Message` subtypes are silently discarded with only a log line. 🔴 **P1-PILOT is editing this exact file for `#JOB-4` right now** — that session should take `JOB-2` in the same pass. |
| `#TEST-40` | `ComputePopularityScoresTest.php` | `app()->instance()` mock is never restored, so **every test appended after it in that file** silently resolves the mock. A live landmine for whoever adds the next test. |
| `#OBS-12` | `LinkObserver.php` | Write failures are logged but never `report()`ed, so a sustained failure (missing partition) silently stops observation recording and `routing:reproject` loses replay data. |
| `#SEM-2` | `RoutingCorpusCommand.php` | `--check` compares only the **case count**, not content — a detector change that swaps two cases passes green. |
| `#DINT-12` | `ItemMerger.php` | Docblock still says the method "fails about half the time" for a bug **fixed the same day**. Actively misleads the next reader into debugging a non-bug. |
| `#SLOP-4` | `WebsiteLinkHarvester.php` | Comment claims "exact same 7 keys" where the constant now holds 21. |
| `#SEC-3` | `SiteMedia.php` | `path` fillable + a force-delete hook that unconditionally `Storage::delete()`s it. No live over-post path, but the blast radius if one ever appears is arbitrary file deletion. |
| `#SEC-10`, `#SEC-11` | two controllers | Add the missing `->where('user_id', …)` to a raw update. Two minutes each, and the adjacent code already does it. |

### The rest, by source

**07-28 sweep (≈52)** — `SEC-2`, `SEC-6`, `SEC-7`, `SEC-9`, `CACHE-4`, `CACHE-5`, `CACHE-6`, `CCH-2`,
`CCH-3`, `SCALE-12`, `SCALE-15`, `SCALE-16`, `SCALE-23`, `SCALE-26`, `LIFE-8`, `LIFE-9`, `LIFE-14`,
`LIFE-21`, `LIFE-24`, `LIFE-31`, `LIFE-32`, `DINT-10`, `DINT-11`, `DINT-13`, `JOB-3`, `JOB-5`, `OBS-9`,
`OBS-10`, `OBS-11`, `CCG-1`, `PRIV-8`, `CFG-19`, `API-2`–`API-7`, `SEM-3`, `SEM-8`, `SEM-11`, `SEM-12`,
`SLOP-3`, `SLOP-8`, `SLOP-16`, plus the `TEST-*` remainder below.

**07-11 sweep (≈17)** — `#17`, `#18`, `#23`, `#24`, `#25`, `#41`, `#42`, `#44`, `#51`, `#52`, `#53`,
`#54`, `#55`, `#56`, `#57`, and two duplicates of promoted items.

**07-24 sweep (≈13)** — `API-2`, `API-3`, `271-DINT-2`, `271-DINT-5`, `TEST-4`–`TEST-13`,
`271-TEST-1`–`271-TEST-9` remainder.

**Inheritance (12)** — `INH-2`, `INH-3`, `INH-5`, `INH-9`, `INH-10`, `INH-11`, `INH-12`, `INH-13`,
`INH-14`, `INH-15`, `INH-16`, `INH-17`. These are the archetype of this disposition: every one is
explicitly "no behaviour change by design", so they are worth exactly what they cost when you are
already in the file and nothing when you are not.

> 🔴 **Two inheritance items are excluded and must NOT be done opportunistically:** `INH-4` (collapses
> six reservation-provider classes into two, rewiring a live third-party connect surface) and `INH-8`
> (a transactional, row-locked `design_kits` write where a "harmless" refactor can silently change lock
> scope or invalidation ordering). Both are listed **standalone** in their source audit. They are
> WONTFIX-unless-planned: if you want them, they get their own branch and their own review.

### ⚠️ Before acting on any `TEST-*` item

Re-verify against the **revised** `scripts/audit/lenses/test-coverage.md` first. That lens produced
**eight** confirmed phantoms (six recorded on 07-28, plus `#TEST-21` and `#TEST-27` confirmed on
07-30). Expect a further ~30% of the `TEST-*` backlog to evaporate on contact. In particular, any
finding phrased "no dedicated test file", "no per-branch test" or "no snapshot test" is now explicitly
**not a coverage gap** under the lens's new mandatory-check step 5.

---

## WONTFIX — 135 findings

### Closed by the audit's own words (≈34)

Every one of these carries an adjudicator caveat that disarms it — quoted from the source files:

- **"No action needed"** — `CCH-1` (the config layer already routes `Cache::lock()` correctly; this was
  filed as a finding and then self-refuted in its own body).
- **"The prescribed fix does not fix anything"** — `SCALE-3` (re-graded P1→P3; `cursor()` is a no-op
  under libpq's client-side materialisation, and ~1.7 MB at 10k sites was never a memory problem).
- **"No functional change required"** — `CACHE-7`.
- **"Already fully backstopped"** — `LIFE-28`, `LIFE-29`.
- **"Conscious tradeoff, not an oversight"** — `SCALE-22` (the `$timeout = 130` + dedicated
  low-concurrency queue mitigation is already in place).
- **"Deliberately-deferred trade-off; self-heals"** — `LIFE-30`.
- **"No currently-reachable path" / "defense-in-depth only"** where the blast radius is also small —
  `SEC-8` (length-bounded, so the ReDoS cannot run away), `SEM-9`, `SEM-10`, `LIFE-17`
  ("low-harm soft cost-tracking cooldown, not security/money"), `#29`, `#30`, `#31`, `#32`.
- **Unreachable code** — `LIFE-2`, `LIFE-25`, `LIFE-26`, `LIFE-27`. `FieldBindingResolver`,
  `FieldBindingSeeder` and `PresetInstantiator` have **no production caller**. Reopen if and when the
  pipeline swap wires them in; auditing dormant code is how a backlog gets to 300.
- **Bounded by construction** — `SCALE-24` (~195 country rows), `SCALE-25` (~50–100 regions),
  `SCALE-27` (low-volume candidates).
- **Not an export or deletion gap** — `PRIV-7` (the field is correctly exported and correctly purged;
  the finding is a minimisation-at-collection preference).
- **Speculative** — `DINT-14`, `DINT-15`, `271-TEST-7`, `SEM-7` (no connector declares a volatile
  wildcard path, so the unimplemented branch is unreachable).

### Superseded by promoted work (≈9)

Closing these avoids doing the same work twice:

- `MIG-5`, `MIG-6`, `MIG-1`, `271-MIG-1`, `#33`, `#34`, `#35`, `#36`, `#47`, `#48` — all migration
  lock-timeout / rollback-comment hygiene. **`LC-ROLLBACK` (P0-LAUNCH) sweeps the entire
  `supabase/migrations/` directory and writes the convention into `CONVENTIONS.md`**, which closes the
  general case these each flag individually. The audit's own note on `MIG-5` — "every table these files
  create is brand new" — already removes the lock risk.
- `271-DINT-6` is a duplicate of `#38` (both ask for the `dining_modes` `jsonb_typeof` CHECK); `#38` is
  promoted, so this one closes as a dedupe. Likewise `SCALE-12` ↔ `JOB-3` are **the same finding filed
  by two different lenses**, and `271-DINT-2` ↔ `271-DINT-5` are **the same missing `updated_at`
  column filed twice**.

### Cosmetic, zero runtime impact (≈45)

Decorative ASCII banners, comment restatements, docblock formatting: `SLOP-5`, `SLOP-6`, `SLOP-7`,
`SLOP-9`–`SLOP-15`, `SLOP-17`–`SLOP-20`, `SLOP-22`, `#26`, `#27`, `#28`, `#55`, and the remaining
`CFG-*` (`CFG-2`–`CFG-7`, `CFG-10`–`CFG-15`, `CFG-17`, `CFG-18`, `CFG-20`).

> **The `CFG-*` reasoning, stated once.** Every one is graded on "operators can't tune this without a
> deploy." That premise assumes an ops function that tunes constants in production. Partna has one
> deploy path, no on-call rotation, and a single engineer. Extracting 16 constants to config buys
> nothing and adds 16 indirections. The three that survived (`CFG-8`, `CFG-9`, `CFG-16`) did so because
> they are specifically *incident* and *paid-API* knobs — a real, named scenario, not a general principle.

> **The banner-vs-comment distinction.** A decorative `// ── Section ──────` costs nothing and is
> WONTFIX forever. A comment that *lies* costs debugging time and is OPPORTUNISTIC — which is why
> `SLOP-4` and `DINT-12` sit in the other list while their fourteen siblings sit here.

### The 07-11 P3 remainder (≈47) — closed en bloc

`#1`, `#2`, `#4`, `#5`, `#6`, `#8`, `#12`–`#16`, `#19`–`#22`, `#45`, `#46`, `#49`, and the rest of the
07-11 tail not otherwise dispositioned above.

**Rationale, and it is the strongest in this file.** These are 19 days stale, uniformly P3, and
uniformly sub-hour. Direct sampling of 14 of them found **6 already fixed — a 43% dead rate.** Under
`fix-flow.md` each one costs verify → plan → implement → independent review, which for a 45-minute fix
means the *process* exceeds the *work*, and at a 43% dead rate nearly half that process discovers
nothing to do. Carrying them costs more than closing them.

If a specific one resurfaces through a real symptom, reopen that one on the evidence. Do not re-derive
the list.

---

## Applying this — sequencing matters

🔴 **Do not tick anything in the source audit files yet.** The live P1-PILOT session
(`audit-fix/p1-pilot-2026-07-30`) and the P0-LAUNCH session both tick boxes in
`audits/sweeps/2026-07-28-.../CONSOLIDATED.md`, `audits/sweeps/2026-07-24-.../CONSOLIDATED.md`,
`audits/sweeps/2026-07-11-.../CONSOLIDATED.md` and `audits/launch-check/2026-07-26/REPORT.md` as they
complete units. Editing the same files now guarantees conflicts on four files for zero benefit — this
document is the decision record and it stands on its own until then.

**After both branches merge**, apply in one mechanical pass:

1. Tick every WONTFIX and OPPORTUNISTIC box in its source file, appending the disposition and reason:
   `- [x] **#SLOP-9** · P3 — … · **WONTFIX (triage 2026-07-30): decorative, zero runtime impact**`
2. Bump each file's `## Progress` counts.
3. Move the 8 PROMOTE findings into their `CONSOLIDATED.md` buckets and leave them `[ ]`.
4. Run `scripts/audit/archive-done.sh` against each folder. `2026-07-11-full-work-sweep`,
   `2026-07-24-pr270-pr271-actions-and-slugs` and `2026-07-25-backend-inheritance` should all archive;
   `2026-07-28-content-platform-rebuild` will not until its promoted items are worked.

That last step is the whole point of this exercise. 239 permanently-open boxes make the audit system
read as red when it isn't, and a signal that is always red is not a signal.
