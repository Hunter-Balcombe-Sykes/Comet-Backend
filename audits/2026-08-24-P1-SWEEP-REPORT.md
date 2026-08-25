# P1 sweep — 2026-08-24 campaign — RESULT

**Branch:** `audit-fix/p1-sweep-2026-08-24` (off `development`, pushed, **not** merged, no PR opened)
**Run:** unattended overnight, 2026-08-24 → 2026-08-25
**Scope:** P1 only. Every P2 and P3 is untouched and unticked — verified: **0** P2/P3 checkboxes ticked.

## Headline

**17 of 19 P1 findings fixed.** 1 skipped by your decision, 1 blocked with reasons.
Every fix went plan → implement → **independent** review; every review ran the CI gates.

One regression was introduced by this run's own work, caught, and fixed — see **Read this first**.

| Outcome | Count |
|---|---|
| Fixed, reviewed, committed | 17 |
| Skipped by owner decision (D2) | 1 |
| Blocked — finding misdiagnosed | 1 |

---

## Read this first — three things that need your judgment

### 1. A fix in this run broke something, and it took three attempts to fix correctly
`#SEC-2` (unit 2) made `PoolResolver` sanitise URLs on the way out. Correct for the public wire — but it **defanged a downstream defence**. `ActionCandidates::itemCandidate()` drops a candidate only when `$item['url']` is a *non-empty string that fails* `safeHref`; it reads the **dirty** value as its signal, precisely so it can tell "URL rejected" from "item legitimately has no outbound URL" (the latter correctly becomes a page anchor). Once the URL was nulled upstream, the guard couldn't tell them apart, and a `javascript:` link stopped being hidden from the action rail and started appearing as a useless anchor button.

Not a security hole — the emitted URL was safe — but a real, user-visible correctness regression. Fixed in `1fa4a0fcd`. **Two wrong conditions were built and rejected by review first**, both of which would have been worse than the bug:
- "raw top-priority URL is unsafe" — deleted items that had a perfectly good lower-priority fallback, because `linkSet()` takes the first *surviving* row, not the first row;
- "composed outbound URL is unsafe" (my own correction) — deleted whole shop products whenever the **store's** fields were poisoned, since `ShopOutboundUrl::compose()` builds the cart URL from a different entity.

**Worth your attention:** it surfaced only because unit 6's reviewer happened to run a test file unit 2's review brief didn't cover. That was my process gap, not the reviewer's.

### 2. `SCALE-1` is blocked because the finding is wrong, not because it was hard
The audit says accepting a synced-platform suggestion holds a worker ~110s on an inline Apify scrape. Tracing it end to end: the only vendor-facing arm **already** ends in `InstagramConnectJob::dispatch(...)` — already a queued job. The ~110s is that job's own scrape budget, and it runs in-request only because prod is on `QUEUE_CONNECTION=sync`. So a new job would *also* run inline under sync, and under Redis the bug doesn't exist.

Landing the prescribed fix would have been a no-op in both queue modes while risking the 403 capability-denial contract two tests pin (inside a job an `AuthorizationException` fails silently — the user would see "pending" forever) and losing the synchronous 423 retry signal. **The real remediation is the Horizon worker cutover** (`docs/deploy/queue-worker-cutover.md` §10). Only the stale code comment was corrected.

### 3. `#SEC-1`'s flag flip is still yours
Per D1, `partna.auth.strong_auth_enforce` stays `false`. The enforced-branch fall-through is fixed — a blank `amr` now denies once enforcement is on — but **until you flip that flag this control still denies nothing in any environment**. The `amr_empty` field in the `auth.strong_auth.would_deny` shadow logs now discriminates the newly-denied cohort; read those, confirm no legitimate cohort appears, then flip.

---

## Per finding

`CG` = claim-gate-security · `UA` = unified-actions-security · `R` = remainder · `D` = delta · `RK` = actions-ordering-math

| Finding | Outcome | Commit |
|---|---|---|
| CG `#SEC-1` — PII-export strong-auth fail-open | **fixed** (flag flip deferred to you, per D1) | `c29fe2829` |
| CG `#SEC-2` — `/api/claim` ignored `email_verified` | **fixed** | `c29fe2829` |
| CG `#SEC-3` — first-come claim squat | **DEFERRED 2026-08-25 by owner, with a revisit trigger.** Code unchanged — the first-come arm is still live. Downgraded on two verified facts: a wrong claim is reversible in one admin action (`.../force` → `adminPurgeNow()`, and a freed handle has NO reclaim cooldown — `AccountDeletionService.php:920`), and prod exposure is nil pre-pilot. ⚠️ Also recorded there: discovery is CHEAPER than the finding claims — the `requestBuild()` dedupe path returns an existing build's `subdomain` to any caller who knows the business's public IG handle. **Revisit when self-serve signup volume becomes non-trivial.** Full disposition on the finding in `audits/sweeps/2026-08-24-claim-gate-security/CONSOLIDATED.md`. | `e0595d23c`, `0e9429313`, revert, disposition-only |
| CG `#SEM-1` — LinkedIn/Spotify shape rewritten to a 404 | **fixed** | `16a90f7dd` |
| UA `#SEC-1` — `reorder()` pinned without the Policy | **fixed** | `b41fed93b` |
| UA `#SEC-2` — public pool wire skipped `UrlSafety` | **fixed** | `b41fed93b` (+ `1fa4a0fcd` regression fix) |
| UA `#SEC-3` — forgeable Referer on analytics ingest | **fixed** | `b41fed93b` |
| UA `SEM-1` — Fresha deleted valid menu items | **fixed** | `56261e8bd` |
| R `SCALE-1` — inline ~110s scrape on the request path | **BLOCKED — misdiagnosed** (see above) | comment only, `c90c6f833` |
| R `SCALE-2` — 4,500-item library hydrated then discarded | **fixed** | `c90c6f833` |
| R `SCALE-3` — unbounded raw-event scan every 15 min | **fixed** | `c90c6f833` |
| R `SCALE-4` — 75s timeout didn't cover the inline fill | **fixed** | `76f4568a5` |
| R `CCH-1` — iTunes cache had no single-flight lock | **fixed** | `35d420b6e` |
| R `CCH-2` — short-link cache had no single-flight lock | **fixed** | `35d420b6e` |
| R `#JOB-1` — fill failures logged, never reported | **fixed** | `76f4568a5` |
| R `#JOB-2` — same, sibling job | **fixed** (absorbed by SCALE-4) | `76f4568a5` |
| D `#MIG-1` — full-table DML bundled with DDL | **fixed, partially by design** (see below) | `3d6b6b33b` |
| D `#TEST-1` — cache test replayed the controller's logic | **fixed** | `3d6b6b33b` |
| RK `#RANK-1` — anti-thrash rank written, never read | **fixed** | `16a90f7dd` |

---

## Where the audit was wrong, and what shipped instead

Six findings were partly or wholly misdiagnosed. Following them literally would have shipped bugs.

1. **UA `#SEC-2` / `sourcePlatforms()`** — the audit called the existing `preg_match('~^https?://~i')` weaker than `safeHref()`. It is **stricter**: `parse_url('https:evil')` reports scheme `https`, so `safeHref` accepts the authority-less opaque form the anchored regex rejects. Substituting would have **loosened** the gate. Both are chained now.
2. **R `CCH-1`/`CCH-2`** — the audit prescribed `rememberLocked`. That maintains a `$key:stale` twin and forbids a null-returning callback; both methods return null on failure, so a transient 429 during an SWR recompute would have written null over the **last-known-good** value. Used `rememberLockedNullable`, which also gives the explicit negative TTL both sites need.
3. **R `SCALE-2`** — the audit said to keep a library-inclusive hydrate "for the dashboard/actions consumer". False: all three `PoolWire` consumers read only the output map, which never contains `library`. The swap picker's library comes from a different entry point entirely.
4. **R `SCALE-3`** — the audit's "re-reads a growing mountain forever" isn't what happens; `PurgeRawAnalyticsEvents` already bounds it at 90 days. That matters: the decay weight at 90 days is **0.5**, not negligible, so a bound *below* retention would have silently re-ranked every site. Sized **above** retention (120 days) — truncates nothing today, and an invariant test goes red on purpose if retention is ever raised past it.
5. **D `#MIG-1`** — two premises failed. The `content_type='page'` DELETE **cannot** be extracted (`NOT VALID` still enforces on new writes and the scoring job re-upserts stored types, so a surviving row makes that job throw), and it can't move to its own file either (no filename sorts between `…100000` and `…100001` without sharing a 14-digit version). And `20260823120000` has **no DDL at all** — plus its DELETE isn't safely re-runnable, because non-UUID keys are *still being written today*. Only the two genuinely extractable statements moved out.
6. **R `SCALE-1`** — see above.

---

## What is deliberately NOT fixed

- **`SEM-4`** (P2, unified-actions-security) is a strict subset of `#RANK-1` and is now substantively resolved — but its box is **left unticked**, because this run was P1-only. Don't let a later P2 pass reopen closed code.
- **`CCH-3`/`CCH-4`/`CCH-6`/`CCH-7`** (jitter, stale-while-revalidate) did **not** fold in with `CCH-1`/`CCH-2` — `rememberLockedNullable` has neither. They are genuinely still open.
- **`CCH-8`/`CCH-9`**'s stated root cause looks **factually wrong**: `Cache::lock()` on the default store already resolves through `lock_connection` to `cache_locks`. Verify before "fixing" a non-bug.
- **booksy** and **apple_podcasts** share `#SEM-1`'s defect class (locale/country branches their templates can't rebuild). Lower severity only because those platforms locale-redirect. **They need their own finding — no one has filed it.**
- **`#SEM-18`** is a genuinely different layer (`app/Catalog/Definitions`), untouched.

## Post-merge addendum (2026-08-25)

`development` moved 22 commits during this run and was merged in (`fe3f4f36d`, full suite 9120 passed). Two consequences for this report:

- **Three claim-gate P2s I'd have called pre-pilot are already fixed** by `b41cfbd71`, under a different campaign's numbering: their `#8` = our `#SEM-2` (staff-deletion un-gates `isOutreach`), `#9`/`#21` = our `#SEM-4` (the dedupe race), `#20` = our `#SEM-3` (the `CLAIM_NOT_INVITED` status oracle). Our boxes still show them open. **Verify before reopening them in a P2 pass.**
- **⚠️ SUPERSEDED 2026-08-25 — the note below is no longer true.** *Dark Until Claimed* (`ee1c22784`) was **reverted** on owner decision: an unvetted self-serve build is publicly routable again, `isVisibleWhileUnclaimed()` no longer exists, and `#SEC-3`'s load-bearing premise (the handle is discoverable pre-claim) holds once more. The finding is **fully live** and must be closed at the claim step — ownership proof — not by making the site dark. `CLAUDE.md`'s pre-account doctrine was corrected again in the revert. The original note is kept below for the trail:
  - **`#SEC-3` now overstates its own risk.** *Dark Until Claimed* (`ee1c22784`) makes an unvetted self-serve build unroutable, which negates the finding's load-bearing premise that the handle isn't a secret. The claim code is unchanged — reachability is what closed. `isVisibleWhileUnclaimed()` is a strict subset of `isOutreach()`, so publicly-visible implies email-gated and first-come implies dark; the intersection the attack needed is empty. Residual: handles are guessable from the business name and `/api/claim` is still a throttled probe oracle, so targeted guessing works where passive discovery no longer does. Full note on the finding itself (`0e9429313`). The D2 deferral is unaffected. `CLAUDE.md`'s pre-account doctrine was corrected in `894de8e0b` — it still asserted the dead rule.

## New follow-ups this run surfaced

- Two dev ledger rows have **no file in the repo or in git history**: `20260824120000`, `20260824122012`. Those version stamps are **burned** for future filenames, and this is `supabase migration repair` territory.
- `tests/Pest.php` declares `occurred_at TEXT NULL` in four SQLite analytics stand-ins while prod is `timestamptz NOT NULL`. Under `SCALE-3`'s new `>=` predicate, a fixture omitting `occurred_at` would silently drop rows in SQLite but not Postgres. Nothing is broken today; every current fixture sets it.
- `MediaUrlResolver` still passes `media_assets.source_url` through ungated, so `thumbnail`/`favicon`/`frames[].url` on the public wire are still raw — the sibling gap `#SEC-2` didn't cover.
- Gating `f_link.url` on the **write** path (`UpsertManualOverrideRequest` validates the value only as `present`) would close `#SEC-2` at source instead of relying on the emit gate alone.
- `CLAUDE.md` and `WriteDesignKitTest` both name a route that doesn't exist (`/api/professional/site`); the real one is `PATCH /api/site`.
- `RunExecutor` could file an `ingest.anomalies` row when a stream reports `unmapped_rows` on N consecutive runs, turning "deletion is quietly frozen" (the `SEM-1` trade-off) into an operator-visible alert.

## Behaviour changes to know about before deploying

- **`#RANK-1`**: page order and the action rail will **reshuffle once** on deploy, to the stored hysteresis order. That's the fix working — worth a release note.
- **`SCALE-4`**: `connect/status` flips to `ready` on the settle write, and the fill now lands a queue hop later, so the window where a poll sees `ready` with `products: []` widens. Response *shape* is identical, and the sibling lane already used this pattern.
- **`UA SEM-1`**: while a Fresha mapper gap persists, a service the salon **genuinely deleted stays published** until the gap is fixed. Deliberate — a stale extra service is recoverable; silently deleting 12% of a paying salon's menu is not.
- **`#MIG-1`**: one post-deploy step moved from automatic to operator-run. It is a **no-op on prod today** (0 rows in all three tables) and is in `docs/deploy/routine-deploy.md`.

## Gates

Every unit's independent review ran `vendor/bin/pint --test` and (from unit 6 onward) `composer analyse`. `composer guard:no-unsafe-migrations` was run for `#MIG-1`. The Postgres lane genuinely executed for the new scrub command against a disposable `postgres:16` container — not reported green off a skip.

One PHPStan regression from unit 2 slipped past its own review (my brief omitted `composer analyse`) and was caught and fixed in `8956839f9`.

**Full-suite:** GREEN — `1 warning, 3 skipped, 9051 passed (32130 assertions)`, 617s.

The FIRST full-suite run was red (1 failure) on a test-helper name collision that unit 7's review had spotted and reasoned away — correctly about runtime, but it missed that `TestSuiteProcessHygieneTest` scans every test file regardless of lane. Fixed, PG lane re-verified, suite re-run clean. Details and the process lesson: `2026-08-24-P1-SWEEP-FULL-SUITE.md`.

## Not done, as instructed

- No PR opened, nothing merged, nothing pushed to `development` or `production`.
- `scripts/audit/archive-done.sh` **not** run — the P2/P3 tail stays open by design, and two P1 boxes are deliberately unticked.
