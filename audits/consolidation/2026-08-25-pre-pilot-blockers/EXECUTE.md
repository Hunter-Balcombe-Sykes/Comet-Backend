# EXECUTE — Pre-pilot P2 blockers (2026-08-25) · UNATTENDED

**Run this by saying:** `execute audit audits/consolidation/2026-08-25-pre-pilot-blockers/EXECUTE.md`

**This file is written for an UNATTENDED overnight run. Josh is asleep. Nobody will answer a
question.** Every decision this tranche needs has been made in advance and is written inline as a
`**DECIDED:**` line. There are no sign-off gates left. §1 is the no-stop policy; read it before §4.

Eleven findings, nine units. Every one clears the pre-pilot bar: it loses user data, leaks a secret
or another tenant's data, breaks the public sitepage, or hands a business's site to a stranger.
**Ten of the eleven are S.** Sourced from `audits/consolidation/2026-08-25-pre-pilot-p2-promotion/BACKLOG-TRIAGE.md`,
where each was verified against the code as it stands on `development` at `d52a604c5`.

Follow `scripts/audit/fix-flow.md` for the per-unit plan → implement → **independent** review loop,
with the overrides in §1. **Where this file and `fix-flow.md` disagree, THIS FILE WINS** — including
on `fix-flow.md` §1a's blocker gate, which §1 below explicitly discharges.

**Run order.** This tranche runs FIRST, alone, on its own branch. The pre-launch hardening parts
(`../2026-08-25-pre-launch-hardening/`) share files with it and must not run concurrently in the
same checkout. See §1.4.

---

## 0. Execution policy

- **Plan:** Opus 5 · **Implement:** Sonnet 5 · **Review:** Sonnet 5 — a *separate, independent*
  instance, never the implementer.
- **Combine plan+impl:** YES for S/XS units · NO for units 6 and 9.
- **Per-item override:** escalate implement → Opus 5 where a unit says so.

> This header names Opus 5 / Sonnet 5, not the `Opus 4.8 / Sonnet 4.6` that `scripts/audit/audit.sh:174`
> stamps into generated files — that template string is stale, and `fix-flow.md` makes THIS file's
> policy authoritative.

---

## 1. NO-STOP POLICY — read this before anything else

### 1.1 Every gate in this tranche is DISCHARGED

`fix-flow.md` §1a would pause for sign-off on auth/authorization work and on L/XL units. **Josh
pre-authorised all three affected units on 2026-08-25, in writing, before this run.** Do not pause
for any of them:

| Unit | Why it would have gated | Why it is pre-authorised |
|---|---|---|
| **8 — `#SEM-3`** | claim-path authorization | The change only *narrows* — it makes a 409 byte-identical to a 404 that already exists. It removes information; it grants nothing. |
| **9 — `#SEC-16`** | deployed security posture | Scope is code-only: a boot guard that is **inert on both current envs** (verified §4 U9) plus a route-attachment test. All env changes are explicitly out of scope and go to Josh as runbook steps. |
| **6 — `#LIFE-13`/`#LIFE-14`** | M-effort, observer + ingest provisioning | Plan separately from implement (§0), but **do not wait** — proceed straight from plan to implement. |

**There is no remaining reason to stop and ask.** If you find yourself composing a question for
Josh, that is a DEFER (§1.2), not a pause.

### 1.2 The DEFER lane — how a large issue exits without stopping the run

**Defer, never escalate, never grind.** Deferring is a successful outcome, not a failure.

**DEFER a unit the moment ANY of these is true:**

1. The fix would need a **schema change** (a `supabase/migrations/` file). Nothing in this tranche
   should — if one appears, the premise is wrong.
2. The fix would **break the public or dashboard wire** in a way a frontend must be changed to
   absorb (removed field, changed status code on a success path, new required request key).
3. The unit turns out to be **L or XL** once the code is read — materially bigger than the S/M this
   file claims.
4. The **independent review fails twice.** No third attempt, ever.
5. **Three implementation attempts** have not produced a green targeted test run.
6. The fix requires a **live third-party call, a real credential, or a paid API budget** to verify.
7. It would require editing a file that a **different unit in this same run has already changed in a
   conflicting way**, and reconciling them is not obvious.

**What DEFER looks like — do all five, then move on:**

1. Write `DEFERRED-unit-<n>-<slug>.md` beside this file. It must contain: the finding IDs, what you
   found, the DEFER trigger number from the list above, the plan you had got to, and the concrete
   next step for a human. **A deferral with no written plan is a dropped ball, not a deferral.**
2. Leave the source audit checkboxes **unticked**.
3. `git checkout -- <paths>` any half-finished edit for that unit **only** — never a tree-wide reset.
4. Record it in §7's ledger as `DEFERRED — <trigger>`.
5. **Go to the next unit immediately.** Do not revisit. Do not "just try one more thing."

### 1.3 Nothing else halts the run — including a red baseline

- **A red suite at baseline is NOT a stop.** Record the failing test names and the commit that broke
  them, then carry on. Pre-existing red is not yours and never blocks this run.
- **A refuted premise** → close it per §5, tick as `WONTFIX — premise refuted`, move on. Roughly 40%
  of this backlog is already fixed; expect several.
- **Anything outside this file's scope** → note it in §7 under "Surfaced, not worked". Do not chase it.
- **A failing unrelated unit** → does not affect any other unit. Units are independent.

**The ONLY conditions that end the run early** (and even then, you must still write `RESULT.md`
per §7 before stopping):

- `git fetch`/`git pull` fails and cannot be recovered.
- The working tree contains uncommitted changes that are not yours and cannot be safely stashed.
- The machine is out of disk.

### 1.4 Forbidden in an unattended run

- **`cloud env:logs` in any form.** It has no guaranteed exit path; on 2026-08-19 an 11-minute poll
  loop left 70 orphaned PHP processes alive for six hours and drove load average to 45. If you truly
  need a log, use `scripts/env/cloud-logs.sh <env> --minutes 2 --json` (60s bounded) — **once**,
  never in a loop, never in the background. You should not need one at all: this is offline code work.
- **Any interactive command.** No `git rebase -i`, no `git add -i`, nothing that opens `$EDITOR`.
  Set `GIT_EDITOR=true` if a git command might try.
- **Pushing anything.** No `git push` to any remote, any branch, for any reason.
- **`scripts/audit/audit.sh`.** This is a fix run, not a scan run. Never run two audits at once.
- **`scripts/audit/archive-done.sh`.** These sweeps carry findings outside this tranche; it will not
  archive them and forcing it corrupts the record.
- **Touching another branch or another worktree.** Check `git worktree list` before assuming a file
  is free. If a sibling worktree holds this repo, do not write into it.
- **Reading, printing, logging or committing any secret** — the Turnstile secret above all.

### 1.5 Time and cost discipline

- **Full suite: at most twice** — once at baseline (§2.3), once at the end (§6). Per-unit
  verification is a **targeted** test path, never the full suite.
- Per unit, hard ceilings: **3 implementation attempts, 2 review rounds.** Hitting either is a DEFER.
- Work units **in order**. Do not reorder to "get the easy ones done first" — the order already does
  that, and reordering creates file conflicts.

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
2. Branch `audit-fix/pre-pilot-blockers-2026-08-25` off freshly-pulled `development`.
   If that branch already exists, check it out and continue on it — do not create a second one.
3. Baseline the suite: `php artisan test --parallel` (NOT `composer test --parallel` — the flag is
   passed to `config:clear` and errors). **Record counts. A red baseline is recorded, not a stop
   (§1.3).**
4. **Landed-work check — by content, not by branch name.** Confirm each directly:

   | Probe | Expected |
   |---|---|
   | `grep -n "lastReadFailed" app/Services/Analytics/ContentPopularityReader.php` | non-empty — `CCH-11` landed; unit 2 mirrors its shape |
   | `grep -n "SecretParams" app/Http/Controllers/Api/Routing/RoutingController.php` | non-empty — the redaction helper unit 1 reuses exists |
   | `grep -rn "isVisibleWhileUnclaimed" app/ tests/` | **empty** — Dark Until Claimed was reverted; unit 8 depends on this |

   **A failed probe is not a stop.** Work the unit anyway — re-read the finding against the code as
   it stands, and note the discrepancy in §7.

---

## 3. House rules that bite in this tranche

Standing rules are in `CLAUDE.md`. These are the ones these units will trip:

- **⚠️ IDs COLLIDE ACROSS SWEEPS.** There are **two different `#SEC-7`s and two different `#SEC-3`s**
  in this tranche, in different files, and **all four are real**. Always key a finding by ID **plus
  its source file**. Matching by ID alone will merge unrelated defects.
- **Audit line numbers are STALE.** Match by symbol, never by position.
- **Verify the premise before changing anything.** This repo's backlog carries a measured ~40%
  already-fixed rate, and the last tranche closed 10 findings with no code change at all. A `//`
  comment cited as Evidence narrates history, not current state.
- **Every new assertion must be mutation-proved.** Break the code it covers, watch it go RED, restore
  via `cp` from a scratchpad backup — **never `git checkout`**, another agent's work may be in the tree.
  Record the exact mutation and the observed failure text.
  **A mutation that fails to go red is a finding about your TEST, not a formality to wave through.**
  Two specified mutations in the last tranche proved nothing and were caught this way.
  Back up to `<scratchpad>/mutation-backups/` **before** each mutation; `git diff` after every
  mutation round to prove the tree is clean again.
- **A chained `expect()` aborts at the first failure**, so one run proves one link. Use separate
  statements, or mutate once per assertion.
- **`pint --test` is the CI gate, not `pint`** (`pint` silently fixes then reports "passed").
- **A green pre-push hook is NOT a green CI.** The hook runs pint + 3 composer guards + phpstan + 3
  worker checks. **`php artisan checkpoint:scan` runs ONLY in CI's `test` job** and broke the last
  push. If you touch raw SQL (`selectRaw`/`groupByRaw`/`whereRaw`), run `php artisan checkpoint:scan`
  locally before finishing; suppress false positives by hash in `config/checkpoint.php` with a
  justification comment, and confirm `CheckpointSuppressionStalenessTest` still passes.
- **Tests run SQLite, prod is Postgres.** Check constraint-bound writes against `supabase/migrations/`.
  The Postgres lane IS runnable locally against a scratch DB — see §8.
- **No Laravel migrations.** Nothing here needs a schema change; a unit that seems to need one is a
  DEFER (§1.2 trigger 1).
- **Authorization via Policies**, never inline `abort_unless`. `authorizeForUser($user, …)`, never
  `authorize()` — `Auth::user()` is always null under Supabase JWT.
- **Prod context, for honest write-ups:** prod is stopped, `core.users` = 0, and it lacks the
  `content`/`ingest`/`routing`/`catalog` schemas entirely. Several findings here **cannot fire on
  prod today**. Say so when ticking rather than implying a live prod risk.

---

## 4. Units — work in order

### Unit 1 — Stop publishing pasted secrets · P2 · S×2
**Findings:** `#SEC-7` (overnight-run) **and** `#SEC-7` (unified-actions-security).
⚠️ **These are TWO DIFFERENT FINDINGS that share an ID.** Both are real. Fix both.

**1a — `app/Http/Controllers/Api/Routing/RoutingController.php`**
The link-in-bio branch passes the pasted URL **raw**: `$this->links->addManual($user, trim($url))`
(~:95). A URL carrying `?token=`/`?key=` is then persisted as a **public link card** and dispatched
to a scan job verbatim. `CustomLinkSeeder::addManual()` → `LinkCardScraper::normalizeUrl()` only
prepends a scheme and checks the host — zero query filtering.
**The correct pattern is 130 lines below in the same file** (~:227):
`$result['canonicalUrl'] ?? SecretParams::redactUrl($url) ?? ''`, under a comment reading
*"never fall back to the raw, possibly-secret-bearing URL."* Apply it here.

**1b — `app/Routing/Importers/LinkInBioImporter.php`**
Raw URL persisted **durably** to `routing.import_runs.source_url` via `ImportRun::start(..., $pages[0])`
(~:111) and into a queued payload via `CommerceProbeJob::dispatch(..., $url)` (~:645). The same file
already redacts correctly at ~:226 and ~:691 with an explicit "Scope B PII" comment. Two one-line calls.

**DECIDED:** reuse `App\Routing\SecretParams::redactUrl()` (verified present at
`app/Routing/SecretParams.php:176`). **Do not write a second redactor** and do not extend
`SecretParams`' parameter list — if a param you expect is not covered, note it in §7 and move on.

- Tests: paste a URL with a secret-shaped param through each path; assert the persisted/dispatched
  value is redacted and the **card still renders** (do not break the link).
- Mutation-prove: revert each call to the raw value, watch the assertion go red.

### Unit 2 — `ActionCandidates`: stop swallowing, and read the fallback · P2 · S×2
**Findings:** `#TEST-10` (delta) and `SEM-16` (unified-actions-security). **Same file — one unit.**
**File:** `app/Site/Actions/ActionCandidates.php`

**2a — `#TEST-10` (the more urgent of the two).** ~:158-163:
```php
try { $pools = $this->poolWire->forSite($site, $this->resolver); }
catch (QueryException) { $pools = []; }
```
No `report()`, no log. A content-lane fault silently blanks **every pool and action on the public
page** — 200 OK, content gone, nothing in Nightwatch. **Prod has no `content` schema at all
(verified 2026-08-25: 0 of 4 schemas, ledger 4 rows), so this will fire on every public profile
request the moment prod starts, masking the schema gap entirely.**

- **Follow the precedent, do not invent one:** `CCH-11` fixed exactly this shape last tranche in
  `ContentPopularityReader` using the existing `App\Services\Analytics\Concerns\EscalatesRepeatedFaults`
  trait (verified present) plus a caller-visible flag. Read that first and mirror it.
- The page must still render — this is fail-open-**and-log**, not fail-closed.
- Test: force a `QueryException`, assert the page still 200s AND the fault is reported. Mutation-prove
  the report assertion with a multi-argument matcher (`[$message, Mockery::any()]`) — a single-arg
  `shouldNotHaveReceived` is a documented vacuous shape here.

**2b — `SEM-16`.** ~:210 computes `$fallback ??= $cid;` and **never reads it again** — a dead
variable. ~:216 branches on `$home === null` only. Consequence: a sidecar-only item (an Uber-Eats-only
dish with no owner-authored category) renders as a loose standalone action instead of grouping under
its ordering platform.

**DECIDED — the tie-break, so you do not have to ask:** work out the intended semantics from the
surrounding code. **If after reading it you are not confident what the grouping is supposed to be,
close it `WONTFIX — intent unclear, no user-visible defect proven` with your reading written down,
and land a test pinning the CURRENT behaviour so the next sweep does not re-file it.** Do not guess
at grouping semantics on a public rendering path unattended. 2a is the unit's real value; 2b must
not put 2a at risk.

- If you DO wire it in: test that a sidecar-only item groups under its platform, and that an item
  with a real category still uses that category.

### Unit 3 — Pool reorder must not strand un-listed pins · P2 · S
**Finding:** `SEM-7` (unified-actions-security)
**File:** `app/Http/Controllers/Api/Content/PoolController.php` (~:196-199, `reorder`)

`->whereIn('item_id', $ids)->delete()` touches only the rows the client **listed**, then `insert()`
assigns `(float)($index + 1)`. An existing pinned item the client omitted keeps its old `sort_key`
and interleaves the fresh 1..N sequence — so the **public sitepage renders an order nobody chose**.

**DECIDED — renumber the survivors. Do NOT return 422.**
Evidence: the method's own comment (~:169-171) already states the contract — *"the FE sent a list it
believes is the pool, and half-applying it would scramble the order it shows."* A partial list is
therefore a client bug, but **422 is a wire-behaviour change** that could lock out a dashboard nobody
can test overnight, and §1.2 trigger 2 forbids that. Renumbering is behaviour-preserving for the
correct client and repairs the incorrect one.

Implementation shape: inside the existing transaction, after inserting the listed rows at
`1..N`, update the section's remaining `SectionItem` rows (`state = STATE_PINNED`,
`item_id NOT IN $ids`) to `sort_key = N+1, N+2, …` **ordered by their existing `sort_key`** so the
result is deterministic and the survivors keep their relative order.

- **Three-lane cache contract applies** (`CLAUDE.md`): this is an owner-initiated pool mutation.
  Route through `App\Site\Documents\SiteCacheLanes::bust()`, never `BuildState::bump()` alone.
  Check first whether `reorder` already routes through it — if so, do not add a second call.
- Test: pin A, B, C; reorder sending only A and C; assert B lands after them at a coherent position,
  not interleaved. Assert the A, C relative order is what was sent.

### Unit 4 — Fresha reconnect must not serve the old salon's roster · P2 · S
**Finding:** `#CCH-2` (overnight-run)
**File:** `app/Http/Controllers/Api/Platforms/FreshaController.php`

Owner reconnects to a **different salon** via the deferred flow; the staff picker keeps offering the
**previous salon's roster** for up to 24h. Mechanism: `connectDeferred()` (~:275) writes `pending: true`,
and `ManagesIntegrationConnection` (~:205) merges `[...$existing->payload, ...$values['payload']]`.
The deferred payload clears `teamMenu` but carries no `teamMenuCache`/`teamMenuCachedAt` key, so both
**survive the merge**. `team()` (~:379-382) then serves `teamMenuCache` on freshness alone — it never
compares the URL.

**DECIDED — key the cache on the salon identity, do not clear it on write.**
Rationale: the file's own history shows clearing-on-write is exactly what a future write path forgets;
a key that cannot match the wrong salon cannot be forgotten. Store a salon fingerprint alongside the
cache (e.g. `teamMenuCacheFor`) and have `team()` serve the cache **only when the fingerprint equals
the current connection's salon**, in addition to the existing freshness check.

**DECIDED — prefer a false MISS over a false HIT.** ⚠️ `reference_fresha_slug_rotation_auto_lane`:
Fresha slugs rotate. Fingerprint on the most stable salon identifier available; if the only thing you
have is the slug, **accept that a rotation drops a warm cache**. A cold cache costs one refetch;
serving salon A's staff to salon B is the defect being fixed.

- Test: connect salon A, cache its team, reconnect to salon B, assert `team()` does **not** return
  A's roster. Mutation-prove by restoring the unconditional merge.

### Unit 5 — Google Business enrich must not clobber owner-typed text · P2 · S
**Finding:** `#LIFE-11` (overnight-run)
**File:** `app/Services/Platforms/GoogleBusinessAutoSync.php` (~:429, `seedWorkplace()`)

`Workplace::firstOrNew(...)` → per-field `blank()` check → `save()`, with **no lock**. An owner typing
a workplace description/category while a GB enrich job runs loses what they just typed to a
read-modify-write. The class's own docblocks say "never overwrite user data".

**DECIDED — the finding's "unlike every sibling" headline is wrong and there is no in-file pattern.**
`grep 'Cache::lock\|withLock'` over the whole file returns nothing. **Use the repo-wide idiom:**
`Cache::lock(CacheKeyGenerator::platformConnectionLock($platform, $userId), 10)->block(5, …)`.
Live callers to copy from: `app/Jobs/Platforms/ConnectFetchJob.php:211`,
`app/Http/Controllers/Api/Platforms/InstagramController.php:70`,
`app/Http/Controllers/Api/Routing/SuggestionsController.php:354`.
⚠️ Key on **(platform, user_id)**, NOT per-account — `ConnectFetchJob.php:207` records that as a
deliberate 2026-07-21 fix. Do not re-narrow it.

- The check-then-write is the real defect regardless of the lock idiom.
- Test: simulate a concurrent owner write, assert the owner's value survives. Mutation-prove by
  removing the lock.

### Unit 6 — A platform that never syncs must not be invisible · P2 · M
**Findings:** `#LIFE-14` **and** `#LIFE-13` (overnight-run). **Fix as ONE unit — separately they
each look harmless.**
**Files:** `app/Ingest/SourceProvisioner.php`; `app/Observers/Core/IntegrationConnectionObserver.php`

`SourceProvisioner::sync()` does an unguarded find-then-`DB::table('ingest.sources')->insert(...)`.
Two connection saves in close succession (dashboard save racing a scheduled refresh; a deferred-connect
payload-fill racing the insert) collide on `sources_unique_per_connection`
(`supabase/migrations/20260727130000_ingest_schema.sql:57`).

**The finding's "uncaught" premise is wrong — and that is what makes it worse.**
`IntegrationConnectionObserver::syncIngestSource()` wraps it in `catch (QueryException)` → `Log::debug`.
So the user does not see a 500; they see **a platform that silently never syncs**, and nobody can see
why. The sibling `catch (\Throwable)` immediately below correctly does `report()` + `Log::warning`.

- `#LIFE-14`: make the insert idempotent (`insertOrIgnore` / `onConflict`).
- `#LIFE-13`: stop swallowing at `debug` — a real DB fault must reach Nightwatch. **Mirror the sibling
  `catch (\Throwable)` block immediately below it**; that is the in-file precedent, so no new
  convention is needed.
- **DECIDED:** the unique-violation path stays quiet (it is the benign, expected race outcome after
  the insert is made idempotent); every OTHER `QueryException` must `report()` + `Log::warning`.
  Distinguish them by SQLSTATE `23505`, not by message text.
- Test both: force the race, assert the source **is** provisioned; force a genuine non-23505 fault,
  assert it is reported. Mutation-prove the report assertion with a multi-argument matcher.
- **Plan and implement as separate instances** per §0 — but do not wait between them (§1.1).

### Unit 7 — Square's `order.` pattern must anchor · P2 · S
**Finding:** `#SEC-3` (overnight-run) ⚠️ **not** the claim-gate `#SEC-3` (that one is a P1 and is
**out of scope here** — it is deferred, see `project_staff_release_claim_shipped` / §7).
**File:** `config/partna.php` (~:993, Square menu host pattern)

`^order\.(?!online$|toasttab\.com$|…)` — the lookahead is **zero-width and there is no terminal
anchor**, so any host starting `order.` (e.g. `order.attacker.example`) is accepted and rendered on
a public sitepage **under Square's brand identity**.

- **Premise re-verified 2026-08-26: still live, still unanchored**, at `config/partna.php:993`:
  `'~(^|\.)square\.site$|(^|\.)square\.com$|^order\.(?!online$|toasttab\.com$|ubereats\.com$|doordash\.com$|menulog\.com\.au$)~'`.
  The file changed for other reasons since, so **match by symbol, not by that line number.**
- One character-class/anchor fix. **Do not widen the allowlist.**
- **Use the repo's differential convention** (`feedback_differential_test_for_keyword_map_changes`):
  diff the OLD vs NEW pattern over a generated corpus of hosts, so a legitimate Square host silently
  dropped shows as a diff line. A lost match here is invisible otherwise.
- **DECIDED:** if the differential shows the new pattern drops a host the old one accepted and you
  cannot tell whether that host is legitimate, **keep the host accepted** (widen the anchor, not the
  allowlist) and note it in §7. A false reject breaks a real merchant's page; the attack this closes
  needs an attacker-controlled `order.*` host, which is the narrower risk.
- Test: `order.attacker.example` rejected; every genuine Square ordering host still accepted.

### Unit 8 — Close the claim enumeration oracle · P2 · S · **PRE-AUTHORISED, do not wait**
**Finding:** `#SEM-3` (claim-gate-security)
**File:** `app/Http/Controllers/Api/PublicSite/ClaimController.php` (~:60 vs ~:71-76)

`CLAIM_NOT_FOUND` → bare **404**. `CLAIM_NOT_INVITED` → **409 with a distinct `code` and message**.
Any free Supabase account can sweep public handles and separate "nothing here" from "a staff-groomed
outreach site awaiting invite" — a target list of exactly the sites worth squatting. The branch's own
comment says it must not become this oracle.

**⚠️ RE-VERIFIED 2026-08-26 — the premise SURVIVES, but the file changed under it.** PR #310 and the
ManyChat claim-link work landed. `CLAIM_NOT_INVITED` is still a distinct 409 (now ~:77-81) and
`CLAIM_NOT_FOUND` still a bare 404 (~:66), so the finding stands. **But there is now a THIRD branch
the original finding never saw:** `CLAIM_EMAIL_MISMATCH` → **409**, *"This site is reserved for a
different email address"* (~:71). That leaks site existence in exactly the same way.

**DECIDED — collapse `CLAIM_NOT_INVITED` into the existing `CLAIM_NOT_FOUND` response. Leave
`CLAIM_EMAIL_MISMATCH` ALONE and write it up instead.**
Byte-for-byte indistinguishable for the one you change: same status, same `code`, same message, same
body keys, same headers. Do not invent a third shared code — reuse the 404 that already exists, so
nothing new is learnable.

**Why `CLAIM_EMAIL_MISMATCH` is out of scope tonight, despite being the same class of leak:**
`CLAUDE.md` now pins it as **load-bearing for the ManyChat design** — the claim token *"satisfies the
invite-gate only and does NOT override `CLAIM_EMAIL_MISMATCH`."* Collapsing it would silently weaken a
guard that shipped days ago, and it also breaks a real UX path (an invited user who signed up under
the wrong email would get a bare 404 instead of a usable message). That trade — enumeration hardening
versus a documented product guard — is Josh's call, not an unattended one.
**Put it in §7 as a surfaced residual**, naming
`docs/superpowers/specs/2026-08-25-manychat-claim-link-design.md`, with your recommendation.

- **DECIDED — timing:** do not add an artificial delay. A constant-time claim path is out of scope
  and would be a DEFER; note any timing residual in §7 instead.
- If staff need invite status, expose it on the authenticated staff surface
  (`StaffPreAccountBuildResource`), not the public endpoint. **Only do this if it is genuinely
  already consumed** — otherwise skip it and note it; adding an unused staff field is scope creep.
- **This compounds the still-open P1 `#SEC-3`** (first-come claim with no ownership proof, deferred).
  State in `RESULT.md` that closing this narrows discovery but does **not** close that P1.
- Test: both branches return an identical response; assert on the **full body**, not just the status.
  Mutation-prove by restoring the distinct code and watching the equality assertion fail.

### Unit 9 — Bot protection actually verifies something · P2 · S(env)/M(code) · **PRE-AUTHORISED, do not wait**
**Finding:** `#SEC-16` (unified-actions-security) ≡ `#SEC-4` (claim-gate). **One defect, two IDs —
fix once, tick both.**

**⚠️ The finding's evidence is STALE. Corrected against the live environments 2026-08-25:**

| Env | Actual state |
|---|---|
| **development** | No bot vars set → `driver=null`, `mode=off`. `VerifyBotToken:27` short-circuits: *"zero work, zero network, zero Redis call."* Genuinely inert. |
| **production** | `BOT_PROTECTION_DRIVER=turnstile`, `BOT_PROTECTION_MODE=shadow`, `FAIL_OPEN=true`, **real Turnstile keys present.** |

`shadow` calls the real verifier, logs `bot_protection.shadow_reject`, and then **always passes
through** by design (`VerifyBotToken:120, 179-183`). So the conclusion survives — **nothing is
enforced on either env** — but the remedy differs per env and the audit's "unset on dev AND prod"
is wrong.

**Scope for THIS unit — code only:**

**DECIDED — throw, following the established precedent.** `app/Providers/AppServiceProvider.php`
already carries **three** boot guards of exactly this shape (`~:292` throttle, `~:298` public_domain,
`~:307` JWKS fail-closed), each `if (app()->isProduction() && <bad combo>) throw new \RuntimeException(...)`.
Add a fourth for `mode=enforce && driver=null`. Outside production, log at `warning` instead of throwing.
⚠️ `AppServiceProvider.php` and `routes/api.php` both changed on 2026-08-26 (ManyChat webhook + claim
routes), so **those line numbers have moved — find the guards by their `isProduction()` shape.**

**DECIDED — verify the guard is inert on both current envs and say so in `RESULT.md`.** Prod is
`turnstile`/`shadow` and dev is `null`/`off`; neither is `enforce`+`null`, so the guard cannot fire
on either today. **If your reading says otherwise, that is a DEFER — do not ship a guard that could
refuse a production boot.**

- Also surface the current mode somewhere observable (the existing health/config surface, not a new
  endpoint).
- Cover the ten wired `bot.token` routes with a test that the middleware is actually attached
  (`routes/api.php:156,159,166,171,202,205`; `routes/api/publicSite.php:31,35,38,50`). Match by route
  name/URI, not by line number — those line refs are stale by convention.

**Explicitly OUT of scope — put these in §7 as runbook steps for Josh:**
- Flipping prod `BOT_PROTECTION_MODE` to `enforce`. **Gate that on the `bot_protection.shadow_reject`
  logs showing the frontend actually mints tokens** — enforcing before that locks out every real user.
  Prod is currently stopped, so no such logs exist yet.
- Wiring dev at all.
- **Do not read, print, log or commit the Turnstile secret.**

---

## 5. Protocol for a refuted premise

1. Write the disproof down — the config line, the passing test path + its output, the framework
   source, whatever settles it.
2. Tick the box as `WONTFIX — premise refuted` with that evidence inline. A ticked box means
   *resolved as an open question*, not *the code changed*.
3. If a real residual survives, land a test pinning the behaviour so the next sweep does not re-file it.
4. Move on. Do not escalate.

## 6. Per-unit close-out

1. Independent reviewer returns PASS (a fresh instance, never the implementer). **2 rounds max — a
   second FAIL is a DEFER (§1.2).**
2. **Targeted tests only** per unit. The full `php artisan test --parallel` runs **once at the end of
   the whole tranche**, not per unit (§1.5).
3. `./vendor/bin/pint --test` clean; `composer analyse` clean; **`php artisan checkpoint:scan` clean
   if you touched raw SQL**.
4. Tick the boxes in the **source** audit file(s) and bump that file's `## Progress`. Note that a
   finding's Progress block can be ~80 lines below its section header — find it by content, not offset.
5. Commit code + ticked audit file together: `fix(audit): <unit> — <ids>`. Include the mutation proof
   for every new assertion in the commit body. **Commit after every unit** — an uncommitted tree at
   the end of a long run is how work gets lost.

## 7. Final report — write `RESULT.md` beside this file

**Write `RESULT.md` even if the run ends early or badly.** A run that produces no report produced
nothing.

- A ledger with one row per unit: `DONE` / `DEFERRED — <trigger>` / `BLOCKED — <reviewer defects>` /
  `WONTFIX — <reason>`. **State the disposition of all 11 findings**, not just the units.
- **Runbook steps for Josh**, in runnable form — at minimum unit 9's env decisions.
- **Surfaced, not worked** — anything adjacent you deliberately left alone.
- Suite status with counts, and any pre-existing red carried in (with the commit that broke it).
- Branch name and the commit range. **Do not push to `development` or `production`, or open a PR,
  without Josh's say-so.**
- Anything you would have asked Josh, written as a question with your recommended answer.

## 8. Notes carried in from the last tranche

- **The Postgres lane runs locally** against a scratch DB on the existing Supabase container:
  `CREATE DATABASE partna_pg_lane_scratch` on `127.0.0.1:54322`, then
  `PG_LANE_DISPOSABLE=1 DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=54322 DB_DATABASE=partna_pg_lane_scratch DB_USERNAME=postgres DB_PASSWORD=postgres DB_SSLMODE=disable ./vendor/bin/pest -c phpunit.pg.xml <path>`,
  then drop it. **Never** override `PG_LANE_DISPOSABLE` against the `postgres` database itself — that
  guard exists to stop the lane provisioning over local dev. Nothing in this tranche should need it.
- **Back up before mutating.** A mutation script that hits a tool timeout can leave a production file
  dirty; `git diff` after every mutation round, and restore from a `cp` backup.
- **`archive-done.sh` will NOT archive these sweeps** — they carry many findings outside this tranche.
  Do not run it (§1.4).
