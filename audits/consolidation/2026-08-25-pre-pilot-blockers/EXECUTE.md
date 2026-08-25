# EXECUTE — Pre-pilot P2 blockers (2026-08-25)

**Run this by saying:** `execute audit audits/consolidation/2026-08-25-pre-pilot-blockers/EXECUTE.md`

Eleven findings, nine units. Every one clears the pre-pilot bar: it loses user data, leaks a secret
or another tenant's data, breaks the public sitepage, or hands a business's site to a stranger.
**Ten of the eleven are S.** Sourced from `audits/consolidation/2026-08-25-pre-pilot-p2-promotion/BACKLOG-TRIAGE.md`,
where each was verified against the code as it stands on `development` at `d52a604c5`.

Follow `scripts/audit/fix-flow.md` for the per-unit plan → implement → **independent** review loop,
with the overrides in §1.

---

## 0. Execution policy

- **Plan:** Opus 5 · **Implement:** Sonnet 5 · **Review:** Sonnet 5 — a *separate, independent*
  instance, never the implementer.
- **Combine plan+impl:** YES for S/XS units · NO for units 6 and 9.
- **Per-item override:** escalate implement → Opus 5 where a unit says so.

> This header names Opus 5 / Sonnet 5, not the `Opus 4.8 / Sonnet 4.6` that `scripts/audit/audit.sh:174`
> stamps into generated files — that template string is stale, and `fix-flow.md` makes THIS file's
> policy authoritative.

## 1. Gate overrides — what stops and what does not

`fix-flow.md` §1a pauses for sign-off on auth/authorization work. **Three units genuinely need that
sign-off and this file does NOT waive it** — no one has pre-authorised them:

| Unit | Why it gates | What to do |
|---|---|---|
| **8 — `#SEM-3`** | claim-path authorization | Produce the plan, present it, **wait** |
| **9 — `#SEC-16`** | changes deployed security posture | Produce the plan, present it, **wait** |
| **6 — `#LIFE-13`/`#LIFE-14`** | M-effort, touches an observer + ingest provisioning | Plan separately, then implement |

Everything else proceeds without asking.

**Things that must NOT halt the run:**

- **A unit that fails review twice** → mark it `BLOCKED` in §7's ledger with the reviewer's defects,
  leave its boxes unticked, **move to the next unit**. No third attempt.
- **A refuted premise** → close it per §5 and move on. Do not escalate.
- **A test already red on `development`** → record it as pre-existing with the commit that broke it;
  it is not yours.
- **Anything outside this file's scope** → note it in §7 under "Surfaced, not worked". Do not chase it.

The only legitimate stop is: `development` won't check out, or the suite is red before you start.

## 2. Setup + preconditions

1. `git fetch && git pull` on `development`.
2. Branch `audit-fix/pre-pilot-blockers-2026-08-25` off freshly-pulled `development`.
3. Baseline the suite: `php artisan test --parallel` (NOT `composer test --parallel` — the flag is
   passed to `config:clear` and errors). Record counts. Anything already red is pre-existing.
4. **Landed-work check — by content, not by branch name.** Confirm each directly:

   | Probe | Expected |
   |---|---|
   | `grep -n "lastReadFailed" app/Services/Analytics/ContentPopularityReader.php` | non-empty — `CCH-11` landed; unit 2 mirrors its shape |
   | `grep -n "SecretParams" app/Http/Controllers/Api/Routing/RoutingController.php` | non-empty — the redaction helper unit 1 reuses exists |
   | `grep -rn "isVisibleWhileUnclaimed" app/ tests/` | **empty** — Dark Until Claimed was reverted; unit 8 depends on this |

   If a probe fails, still work the unit — re-read the finding against the code as it stands.

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
- **A chained `expect()` aborts at the first failure**, so one run proves one link. Use separate
  statements, or mutate once per assertion.
- **`pint --test` is the CI gate, not `pint`** (`pint` silently fixes then reports "passed").
- **A green pre-push hook is NOT a green CI.** The hook runs pint + 3 composer guards + phpstan + 3
  worker checks. **`php artisan checkpoint:scan` runs ONLY in CI's `test` job** and broke the last
  push. If you touch raw SQL (`selectRaw`/`groupByRaw`/`whereRaw`), run `php artisan checkpoint:scan`
  locally before pushing; suppress false positives by hash in `config/checkpoint.php` with a
  justification comment, and confirm `CheckpointSuppressionStalenessTest` still passes.
- **Tests run SQLite, prod is Postgres.** Check constraint-bound writes against `supabase/migrations/`.
  The Postgres lane IS runnable locally against a scratch DB — see §8.
- **No Laravel migrations.** Nothing here needs a schema change; verify before writing one.
- **Authorization via Policies**, never inline `abort_unless`. `authorizeForUser($user, …)`, never
  `authorize()` — `Auth::user()` is always null under Supabase JWT.

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

- Read `SecretParams` first and use its existing API; do not write a second redactor.
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
  trait plus a caller-visible flag. Read that first and mirror it.
- The page must still render — this is fail-open-**and-log**, not fail-closed.
- Test: force a `QueryException`, assert the page still 200s AND the fault is reported. Mutation-prove
  the report assertion with a multi-argument matcher (a single-arg `shouldNotHaveReceived` is a
  documented vacuous shape here).

**2b — `SEM-16`.** ~:210 computes `$fallback ??= $cid;` and **never reads it again** — a dead
variable. ~:216 branches on `$home === null` only. Consequence: a sidecar-only item (an Uber-Eats-only
dish with no owner-authored category) renders as a loose standalone action instead of grouping under
its ordering platform.

- Work out the intended semantics from the surrounding code before wiring `$fallback` in — do not
  assume the obvious reading is right. If the variable is genuinely vestigial and the grouping is
  correct as-is, that is a legitimate WONTFIX with evidence.
- Test: a sidecar-only item groups under its platform; an item with a real category still uses that.

### Unit 3 — Pool reorder must not strand un-listed pins · P2 · S
**Finding:** `SEM-7` (unified-actions-security)
**File:** `app/Http/Controllers/Api/Content/PoolController.php` (~:196-199, `reorder`)

`->whereIn('item_id', $ids)->delete()` touches only the rows the client **listed**, then `insert()`
assigns `(float)($index + 1)`. An existing pinned item the client omitted keeps its old `sort_key`
and interleaves the fresh 1..N sequence — so the **public sitepage renders an order nobody chose**.
The method's own comment (~:169-171) says half-applying "would scramble the order it shows".

- Decide deliberately: reject a partial list (422), or renumber the omitted survivors after the
  listed ones. State which and why — this is a wire-behaviour decision, so if you choose 422, check
  whether the dashboard ever sends a partial list before breaking it.
- **Three-lane cache contract applies** (`CLAUDE.md`): this is an owner-initiated pool mutation.
  Route through `App\Site\Documents\SiteCacheLanes::bust()`, never `BuildState::bump()` alone.
- Test: pin A, B, C; reorder sending only A and C; assert B's position is coherent, not interleaved.

### Unit 4 — Fresha reconnect must not serve the old salon's roster · P2 · S
**Finding:** `#CCH-2` (overnight-run)
**File:** `app/Http/Controllers/Api/Platforms/FreshaController.php`

Owner reconnects to a **different salon** via the deferred flow; the staff picker keeps offering the
**previous salon's roster** for up to 24h. Mechanism: `connectDeferred()` (~:275) writes `pending: true`,
and `ManagesIntegrationConnection` (~:205) merges `[...$existing->payload, ...$values['payload']]`.
The deferred payload clears `teamMenu` but carries no `teamMenuCache`/`teamMenuCachedAt` key, so both
**survive the merge**. `team()` (~:379-382) then serves `teamMenuCache` on freshness alone — it never
compares the URL.

- Fix at whichever seam is right: clear the cache keys on a URL change, or key the cache on the
  salon URL so a different salon cannot hit it. Prefer the one that cannot be forgotten by a future
  write path.
- Test: connect salon A, cache its team, reconnect to salon B, assert `team()` does **not** return
  A's roster. Mutation-prove by restoring the merge.
- ⚠️ `reference_fresha_slug_rotation_auto_lane` — Fresha slugs rotate. Make sure your fix does not
  treat a rotated slug for the *same* salon as a different salon and needlessly drop a warm cache.

### Unit 5 — Google Business enrich must not clobber owner-typed text · P2 · S
**Finding:** `#LIFE-11` (overnight-run)
**File:** `app/Services/Platforms/GoogleBusinessAutoSync.php` (~:429, `seedWorkplace()`)

`Workplace::firstOrNew(...)` → per-field `blank()` check → `save()`, with **no lock**. An owner typing
a workplace description/category while a GB enrich job runs loses what they just typed to a
read-modify-write. The class's own docblocks say "never overwrite user data".

- Note the finding's headline is half wrong: it says "unlike every sibling", but
  `grep 'Cache::lock\|withLock'` over the whole file returns **nothing** — no method there locks. So
  there is no in-file pattern to copy; find the lock idiom used elsewhere for
  `platform_connection`-scoped writes (`CacheKeyGenerator::platformConnectionLock`) and follow that.
- The check-then-write is the real defect regardless.
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
- `#LIFE-13`: stop swallowing at `debug` — a real DB fault must reach Nightwatch.
- Test both: force the race, assert the source **is** provisioned; force a genuine fault, assert it
  is reported.

### Unit 7 — Square's `order.` pattern must anchor · P2 · S
**Finding:** `#SEC-3` (overnight-run) ⚠️ **not** the claim-gate `#SEC-3` (that one is a P1 and is
**out of scope here**).
**File:** `config/partna.php` (~:993, Square menu host pattern)

`^order\.(?!online$|toasttab\.com$|…)` — the lookahead is **zero-width and there is no terminal
anchor**, so any host starting `order.` (e.g. `order.attacker.example`) is accepted and rendered on
a public sitepage **under Square's brand identity**.

- One character-class/anchor fix. Do not widen the allowlist.
- **Use the repo's differential convention** (`feedback_differential_test_for_keyword_map_changes`):
  diff the OLD vs NEW pattern over a generated corpus of hosts, so a legitimate Square host silently
  dropped shows as a diff line. A lost match here is invisible otherwise.
- Test: `order.attacker.example` rejected; every genuine Square ordering host still accepted.

### Unit 8 — Close the claim enumeration oracle · P2 · S · **GATED, wait for sign-off**
**Finding:** `#SEM-3` (claim-gate-security)
**File:** `app/Http/Controllers/Api/PublicSite/ClaimController.php` (~:60 vs ~:71-76)

`CLAIM_NOT_FOUND` → bare **404**. `CLAIM_NOT_INVITED` → **409 with a distinct `code` and message**.
Any free Supabase account can sweep public handles and separate "nothing here" from "a staff-groomed
outreach site awaiting invite" — a target list of exactly the sites worth squatting. The branch's own
comment says it must not become this oracle.

- Make it byte-for-byte indistinguishable from the 404: same status, same body, same `code`.
- If staff need invite status, expose it on the authenticated staff surface
  (`StaffPreAccountBuildResource`), not the public endpoint.
- **This compounds the still-open P1 `#SEC-3`** (first-come claim with no ownership proof). Note in
  the report that closing this narrows discovery but does **not** close that P1.
- Test: both branches return an identical response; assert on the full body, not just the status.

### Unit 9 — Bot protection actually verifies something · P2 · S(env)/M(code) · **GATED, wait for sign-off**
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
- Add a **boot guard** so the deployed combination is asserted rather than assumed: fail loudly (or
  log at warning) if `mode=enforce` with `driver=null`, and surface the current mode somewhere
  observable. Follow the fail-closed throttle precedent at `app/Providers/AppServiceProvider.php:292`.
- Cover the ten wired `bot.token` routes with a test that the middleware is actually attached
  (`routes/api.php:156,159,166,171,202,205`; `routes/api/publicSite.php:31,35,38,50`).

**Explicitly OUT of scope for the agent — put these in §7 as runbook steps for Josh:**
- Flipping prod `BOT_PROTECTION_MODE` to `enforce`. **Gate that on the `bot_protection.shadow_reject`
  logs showing the frontend actually mints tokens** — enforcing before that locks out every real user.
  Prod is currently stopped, so no such logs exist yet.
- Wiring dev at all.
- **Do not read, print, or commit the Turnstile secret.**

---

## 5. Protocol for a refuted premise

1. Write the disproof down — the config line, the passing test path + its output, the framework
   source, whatever settles it.
2. Tick the box as `WONTFIX — premise refuted` with that evidence inline. A ticked box means
   *resolved as an open question*, not *the code changed*.
3. If a real residual survives, land a test pinning the behaviour so the next sweep does not re-file it.
4. Move on. Do not escalate.

## 6. Per-unit close-out

1. Independent reviewer returns PASS (a fresh instance, never the implementer).
2. Targeted tests, then `php artisan test --parallel` for the branch.
3. `./vendor/bin/pint --test` clean; phpstan clean; **`php artisan checkpoint:scan` clean if you
   touched raw SQL**.
4. Tick the boxes in the **source** audit file(s) and bump that file's `## Progress`. Note that a
   finding's Progress block can be ~80 lines below its section header — find it by content, not offset.
5. Commit code + ticked audit file together: `fix(audit): <unit> — <ids>`. Include the mutation proof
   for every new assertion.

## 7. Final report — write `RESULT.md` beside this file

- Units done / blocked (with the reviewer's defects). State the disposition of all 11 findings.
- **Runbook steps for Josh**, in runnable form — at minimum unit 9's env decisions.
- **Surfaced, not worked** — anything adjacent you deliberately left alone.
- Suite status with counts, and any pre-existing red carried in.
- Branch name. **Do not push to `development` or `production` without Josh's say-so.**

## 8. Notes carried in from the last tranche

- **The Postgres lane runs locally** against a scratch DB on the existing Supabase container:
  `CREATE DATABASE partna_pg_lane_scratch` on `127.0.0.1:54322`, then `PG_LANE_DISPOSABLE=1 … ./vendor/bin/pest -c phpunit.pg.xml <path>`, then drop it.
  **Never** override `PG_LANE_DISPOSABLE` against the `postgres` database itself — that guard exists
  to stop the lane provisioning over local dev.
- **Back up before mutating.** A mutation script that hits a tool timeout can leave a production file
  dirty; `git diff` after every mutation round, and restore from a `cp` backup.
- **`archive-done.sh` will NOT archive these sweeps** — they carry many findings outside this tranche.
  Do not force it.
