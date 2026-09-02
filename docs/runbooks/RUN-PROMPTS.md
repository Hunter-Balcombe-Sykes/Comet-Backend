# Run prompts — k6 · DAST · failure drills

One copy-pasteable prompt per runnable part. Paste the block below a `---` into a fresh
Claude Code session in this repo. Each prompt is self-contained: it names its own
preconditions, pass criteria, traps, and what to record.

These are **run** prompts. The *build* prompts (which wrote the harnesses) are separate and
live in `docs/superpowers/plans/*-EXECUTE-PROMPT.md`; don't confuse the two.

| # | Part | Model | Solo? | Target | Time |
|---|------|-------|-------|--------|------|
| [K0](#k0--k6-setup--reseed) | k6 setup / re-seed | Sonnet | solo | dev | 10 min |
| [K1](#k1--k6-phase-1-baseline) | k6 Phase 1 — baseline | Opus | **joint** | dev origin | 10 min |
| [K2](#k2--k6-phase-2a-edge-spike) | k6 Phase 2a — edge spike | Opus | **joint** | prod edge (cache-HITs) | 10 min |
| [K3](#k3--k6-phase-2b-origin-cache-buster) | k6 Phase 2b — origin cache-buster | Opus | **joint** | dev origin | 10 min |
| [K4](#k4--k6-phase-3-jobs--gated) | k6 Phase 3 — jobs (**gated**) | Opus | **joint** | dev origin + Horizon | 30 min |
| [D1](#d1--dast-edge-lane) | DAST edge lane | Sonnet | solo | dev or prod | 15 min |
| [D2](#d2--dast-active-lane) | DAST active lane | Sonnet | solo | local throwaway stack | 20 min |
| [D3](#d3--dast-baseline-triage) | DAST baseline triage | Opus | **needs Josh** | n/a | 30 min |
| [D4](#d4--dast-self-test) | DAST self-test | Sonnet | solo | local | 15 min |
| [F1](#f1--drill-01-worker-kill) | Drill 01 — worker kill | Opus | solo-ish | **local only** | 60–90 min |
| [F2](#f2--drill-02-vendor-outage) | Drill 02 — vendor outage | Opus | solo-ish | **local only** | 45 min |
| [F3](#f3--drill-03-redis-down) | Drill 03 — Redis down | Opus | solo-ish | **local only** | 45–60 min |
| [F4](#f4--drill-04-backup--restore) | Drill 04 — backup / restore | Opus | solo | prod read-only → scratch | 1–2 h |

## Cross-cutting rules that apply to every prompt here

- **`/api/health` is a liveness probe that never touches the database.** It stayed 200
  through a real ~5-minute DB outage. To check an environment is actually healthy, probe a
  DB-touching route: `curl -so /dev/null -w '%{http_code}\n' https://dev-api.partna.au/api/public/profiles/loadtest`.
- **Logs for deployed envs come from `cloud env:logs partna <env>`**, never the local files
  or the boost log tools (which show stale test output). For **local** drills, the reverse:
  `storage/logs/laravel.log` is the real log.
- **Prod is live** as of the 2026-07-26 cutover. `api.partna.au` and `dev-api.partna.au` are
  separate environments now. Any runbook text claiming "development *is* production" is
  stale — treat prod as untouchable unless the prompt explicitly says otherwise.
- **Never run two of these at once.** k6's origin/jobs phases, the DAST edge lane, and the
  drills all share rate-limiter buckets or the local stack.

---

## K0 — k6 setup / re-seed

Use when: first-ever run, or after a `teardown.sql` (i.e. after every Phase 3 write run), or
when the seed verification in any k6 prompt below fails.

---

Set up the k6 load-test fixture on **dev**. Read `scripts/launch-check/k6/README.md`
("Setup") first — that's the source of truth; this prompt is the checklist.

1. `k6 version` — needs ≥ v0.50. Install with `brew install k6` if missing.
2. Apply `scripts/launch-check/k6/seed.sql` against **dev only** (Supabase ref
   `glncumufgaqcmqhzwrxm`). The script is idempotent (deletes-then-seeds) and scoped
   entirely to the fixed IDs `00000000-0000-4000-a000-00000000000{1,2}`. Use the Supabase
   MCP `execute_sql` against the dev ref, or `psql "$DEV_DB_URL" -f seed.sql`.
   **Never against prod** (`edplucmvkcnokyygxqsb`).
3. Sync the handle into Cloudflare KV — `SyncSubdomainToKvJob` is the only KV writer:
   ```bash
   cloud tinker development
   # \App\Jobs\Cloudflare\SyncSubdomainToKvJob::dispatchSync('00000000-0000-4000-a000-000000000001');
   ```
4. Verify the fixture:
   ```bash
   curl -s "https://dev-api.partna.au/api/public/profiles/loadtest" \
     | python3 -c "import sys,json; d=json.load(sys.stdin)['data']['profile']; print('links',len(d['links']),'gallery',len(d['gallery']),'services',len(d['services']))"
   ```
   Expect exactly `links 10 gallery 6 services 15`.
5. Verify the edge route: `curl -sI https://loadtest.partna.au/ | grep -i '^HTTP'` → `200`.

**Traps:**
- **Media is 6, not more — that is a historical constant, not a seed shortfall.** It used
  to be the `core.enforce_site_gallery_max6` ceiling; that trigger and the `gallery` pool
  were dropped 2026-09-02 (migration `20260902170000`) and the seed moved to the `content`
  pool, capped at 20. 6 is kept so earlier baseline results stay comparable — don't "fix"
  the seed to insert more without re-baselining.
- **Expect `pools.media`=6, `pools.services`=15, `pools.custom_links`=10** at
  `data.profile.pools.<pool>.items`. `profile.gallery`/`services`/`links` no longer exist.
  After re-seeding, **fetch the profile twice** — the payload is SWR-cached off
  `site.sites.updated_at` and the rebuild is deferred until after the response, so the
  first request serves the stale body and will look under-seeded.
- **Each media item needs a matching `site.media_variants` (webp) row** or its URL
  resolves empty and the profile looks under-seeded. `seed.sql` already does this — if you
  edit the seed, keep it.
- The variants invariant is guarded by `IndividualProfileControllerTest`'s gallery-engine
  tests. If you change `seed.sql`, re-check those.

Report: the verified counts, whether KV synced, and whether the edge route is live.

---

## K1 — k6 Phase 1 (baseline)

**This is the number future releases regress against.** Get a clean run.

---

Run k6 **Phase 1 — baseline** against dev. Harness: `scripts/launch-check/k6/`. Plan:
`docs/superpowers/plans/2026-07-26-k6-load-testing.md` (Task 4 Step 4).

**This is a joint session, not solo work** (plan §8):
- You drive k6 and narrate what the metrics mean in real time. Also start
  `cloud env:logs partna development --live` in the background.
- Josh watches Horizon (queue depth, worker memory), Supabase connections (Supavisor
  headroom vs the free-tier ceiling), and Nightwatch — on his own screen, in parallel.
- Run the phase, stop, both review, then decide together: escalate / move on / abort.
- **Kill switch:** if Josh says connections or queue depth are climbing toward a ceiling,
  Ctrl-C immediately. Do not finish the scheduled duration first.

**Pre-flight:**
1. Dev is *actually* healthy — probe a DB-touching route, not `/api/health` (which never
   touches the DB and stayed 200 through a real 5-minute outage):
   `curl -so /dev/null -w '%{http_code}\n' https://dev-api.partna.au/api/public/profiles/loadtest` → `200`.
   On `500`, **stop** and read `cloud env:logs partna development --minutes 10`. Never
   load-test a broken environment.
2. Seed intact — the `links 10 gallery 6 services 15` check from prompt K0. If it fails, run K0.
3. Limiter still on: `cloud tinker development --code="echo config('partna.public_profile.rate_limit_per_minute');"` → `60`.
4. Nothing else is hitting dev from this machine — the 60/min-per-IP bucket is shared.

**Run** (from `scripts/launch-check/k6/`):
```bash
k6 run --out json=results/baseline-run1.json baseline.js
```
Bump `run2`, `run3`, … on a retry rather than overwriting; `results/` is gitignored.

**Pass criteria:** `http_req_duration p(95) < 500ms` **and** `http_req_failed < 1%`. The
script's own thresholds encode these, so k6's exit code is the pass/fail signal.

**Note on pacing:** the scenario is a constant-arrival-rate at 45/min — deliberately *under*
the 60/min limiter so the recorded latency reflects real work, not 429 rejections. If you
see 429s, something else is consuming the bucket; find it rather than raising `RATE`.

**After the run:** fill in the "Baseline reference" table in
`scripts/launch-check/k6/README.md` (p50 / p95 / p99 / error rate / date / target / env),
then stop and review with Josh before moving to K2.

---

## K2 — k6 Phase 2a (edge spike)

---

Run k6 **Phase 2a — edge spike** (Task 5 Step 4 of
`docs/superpowers/plans/2026-07-26-k6-load-testing.md`). This is the viral-traffic path:
the zone-wide Cloudflare Worker at `https://loadtest.partna.au/`.

Same joint-session contract and kill switch as prompt K1 — re-read it if you haven't.

**Understand the target before you run:** there is no dev edge. There is exactly one
zone-wide Worker, so this unavoidably hits the **prod** `partna.au` zone. That's acceptable
only because the scenario is cache-HITs on one throwaway handle. **Never point the
cache-buster (K3) or `jobs.js` (K4) at the prod zone or `api.partna.au`.**

**Pre-flight:** `curl -sI https://loadtest.partna.au/ | grep -i '^HTTP'` → `200`. If not, run K0.

**Run:**
```bash
k6 run --out json=results/spike-edge-run1.json spike-edge.js
```
The script warms the cache for 15s with 5 VUs (metric not recorded during warmup), then
measures with `SPIKE_VUS` (default 50) for 2m.

**Pass criteria — both, not just the first:**
1. `edge_cache_hit > 0.9` (the script's threshold), and
2. **origin request count stays ~flat during the sustained phase** — Josh checks Cloudflare
   analytics / origin logs. This is the half that actually proves the edge is absorbing
   load rather than just self-reporting a good ratio.

**Trap:** a sustained MISS/DYNAMIC, or a real origin-traffic bump, is a **Worker cache bug**
(spec §7). The Worker must explicitly call `caches.default.put` — the Cache API is *not*
auto-populated from `Cache-Control` headers. Flag it. Do **not** tune the threshold down.

**Escalation:** the named target is 50 concurrent. `SPIKE_VUS=200` steps to the ceiling with
no code change — **only** after a joint review of the 50-VU results and only if Josh wants
that data point. Never escalate solo.

Report the metrics, then stop for joint review.

---

## K3 — k6 Phase 2b (origin cache-buster)

---

Run k6 **Phase 2b — origin cache-buster** (Task 6 Step 5 of
`docs/superpowers/plans/2026-07-26-k6-load-testing.md`). This simulates a single attacker
defeating the edge cache with a unique query string per request, hammering dev origin
directly. The question it answers: **does the rate limiter shield Postgres?**

Same joint-session contract and kill switch as prompt K1.

**Pre-flight:** dev healthy via a DB-touching route (not `/api/health`); seed intact;
limiter confirmed at 60/min; nothing else hitting dev from this IP.

**Run:**
```bash
k6 run --out json=results/spike-origin-run1.json spike-origin.js
```

**Pass criteria:**
- `origin_5xx == 0` — the limiter must shield PG completely.
- `origin_429 > 0` — proof the limiter actually engaged. Without this the run is a *silent*
  pass: zero 5xx could just mean the load never arrived.
- Supabase connection count stays **flat** (Josh watches). A climb means requests are
  reaching Postgres past the limiter.

**Read the metrics correctly:** 200 and 429 are **both expected outcomes**. The script calls
`http.setResponseCallback(http.expectedStatuses(200, 429))` precisely so throttled requests
don't inflate `http_req_failed`. Only 5xx is failure.

**Optional Option B** (spec §4): temporarily raising the limiter for a tight watched window
to measure true PG capacity. This is a **separate conscious checkpoint** — only if Josh
decides PG capacity is genuinely unclear, and the limiter gets restored immediately after.
Never automatic, never your call alone.

Report metrics + whether Josh saw connection movement, then stop for joint review.

---

## K4 — k6 Phase 3 (jobs) — GATED

**Do not run this prompt unless both gates below are satisfied.** It is the only k6 phase
that writes.

---

Run k6 **Phase 3 — job / upload saturation** (Task 7 Step 5 of
`docs/superpowers/plans/2026-07-26-k6-load-testing.md`).

**Two gates — confirm both before running anything:**
1. Phases 1, 2a and 2b (prompts K1–K3) have all **passed**.
2. Josh has made the **Phase 3 limiter decision live** (README "Phase 3 limiter decision").
   The `analytics` limiter is 120/min per IP; to actually saturate the queue from one IP the
   options are (a) temporarily raise the limiter for a tight watched window and restore
   after, or (b) drive jobs via a tinker loop instead. **Ask Josh which. Do not pick.**

Same joint-session contract and kill switch as prompt K1.

**Run:**
```bash
k6 run --out json=results/jobs-run1.json jobs.js
```
Analytics pageview writes feed `QueuedIngestor` → Horizon on dev.

**Trap — the `Origin` header is load-bearing.** `AnalyticsController::originAllowed()`
(SEC-1, 2026-07-24) fails **closed** with a 404 "Site not found" on any pageview POST with
no `Origin` matching the site's subdomain — `site_id` and `subdomain` are public
values and can't authenticate a caller on their own. Referer is no longer accepted
(#SEC-3, 2026-08-24). `jobs.js` already sends
`Origin: ${EDGE_HOST}` (= `https://loadtest.partna.au`). If you write any new write-path
scenario, it must too. This invariant is guarded by
`tests/Feature/Security/TenantIsolation/PublicAnalyticsIdorTest.php`.

**Pass criteria:** `jobs_5xx == 0`, `jobs_accepted > 0` (proof writes actually landed —
without it the run is a silent pass). 201 and 429 are both expected; only 5xx fails.

**What to watch (this is the real point of the phase):**
- Horizon queue depth — does it drain after the run, or plateau?
- Worker memory — climbs and **recovers**, or climbs and doesn't? (The second is the finding.)
- Job failure rate.
- **Cross-queue starvation** — `redis_video` vs `default`/`analytics`. Note that every
  Horizon supervisor runs in every environment and `simple` balancing ignores
  `maxProcesses`, which is the known dev OOM cause. Watch for it.

**Mandatory teardown — this is a write scenario:**
```bash
psql "$DEV_DB_URL" -f scripts/launch-check/k6/teardown.sql
```
Then re-run prompt K0 (re-seed + re-sync KV) before any further read-path work. Leaving the
write residue in place corrupts every later baseline.

---

## D1 — DAST edge lane

---

Run the **DAST edge lane**. Tool: `scripts/dast/` (read its README first). This lane is
Nuclei (curated tags + 5 custom assert templates) + wcvs (cache deception) + an OWASP ZAP
**passive** baseline — all non-destructive.

**Setup, once ever:** `cp scripts/dast/.env.example scripts/dast/.env` and fill it in
(`EDGE_TARGET`, `EDGE_SITEPAGE_TARGET`, `DAST_EDGE_RATE_LIMIT`, `DAST_FAIL_ON`). Never commit it.

**Run:**
```bash
scripts/dast/run.sh --only edge
```
Add `--target <URL>` to override the lane default. Safe against dev or prod — it's
read-only HTTP. This same lane runs automatically every Sunday via
`.github/workflows/dast-edge.yml`.

**Read the output, don't just read the exit code:**
- `audits/dast/<date>/REPORT.md` — the merged human view (scope, per-lane counts,
  NEW-vs-baselined table).
- `new-findings.txt` — the findings not in the triaged baseline. **This is the thing that
  matters.** The exit code comes from `lib/diff-baseline.sh` comparing against the baseline
  at the `--fail-on` severity floor (default `high`), so a green exit with new mediums is
  entirely possible.

**Do NOT run `--update-baseline` in this prompt.** Accepting findings into the baseline is a
separate human decision — that's prompt D3.

**Report:** every new finding with scanner / key / severity, your read on whether each is
real, and the exit code. If any of the 5 custom templates
(`edge/templates/*.yaml`) errored rather than passed/failed, say so — each asserts against a
specific hardcoded path (e.g. `/api/customers/{id}`) and a route rename silently turns it
into a no-op test.

---

## D2 — DAST active lane

Run before a release, and after **any** change to auth / authorization / policy code — the
cross-identity IDOR pass is built exactly for that bug class.

---

Run the **DAST active lane**. Tool: `scripts/dast/` (read its README first). ZAP fuzzes an
isolated, runner-owned local Supabase stack (port-offset, torn down via `trap` on every
exit), driven by two freshly seeded identities plus an unauth pass.

**Preconditions:**
- Docker running.
- `scripts/dast/.env` populated (incl. `SUPABASE_LOCAL_JWT_SECRET`, `DAST_SUPABASE_PORT_OFFSET`).
- No sibling Supabase stack holding the ports — the Comet sibling stack shares 54321–54327;
  stop it first.
- **Manual only.** This lane is never in cron and never in `ci.yml`.

**Run:**
```bash
scripts/dast/run.sh --only active
```
Takes several minutes: isolated bring-up, route surface re-derived from
`php artisan route:list --json`, ~250 routes across two identities + unauth, curated ZAP
scan (5 rule IDs: SQLi, reflected XSS, persistent XSS, path traversal, command injection).

**Trap — the exclusion list does not auto-update.** `active/zap-context.yaml`'s
`excludePaths` is a **hardcoded** set of routes whose handlers reach past the local box
(vendor API calls, real email/notification sends, Cloudflare KV writes). A new route doing
any of those is **not** auto-excluded, so a "local" scan can fire a real external side
effect. Before running, grep the diff since the last run for `SyncSubdomainToKvJob::dispatch`,
`Mail::`, `Notification::send`, and new entries in `routes/api/platforms.php`; if any new
route matches, add it to `excludePaths` first.

**If `active/seed-identities.php` fails**, that's usually schema drift, not a scanner bug —
it hardcodes the exact fields needed to build a full identity (User → Site → SiteMedia →
Customer → Enquiry). A new required column or renamed relation breaks it until updated.

**Report:** new findings from `new-findings.txt` + `REPORT.md`, exit code, and explicitly
state this limitation in your summary: the local stack does **not** reproduce prod's
`app_backend` restricted role + RLS via Supavisor, so a green active lane means "no
injection/authz class found against app logic", **not** "prod RLS proven". That remains a
post-launch human-pentest gap.

Do not run `--update-baseline` here — see prompt D3.

---

## D3 — DAST baseline triage

---

Triage DAST findings into the baseline. **This needs Josh's sign-off on every accepted
finding — you propose, he decides.**

Context: `scripts/dast/baseline/*` starts **empty on purpose** and is populated only by
reviewed triage. Pre-seeding it would bury real bugs. First-run triage process is Phase 10
of `docs/superpowers/plans/2026-07-17-dast-security-implementation.md`.

**Do this:**
1. Read the latest run's `audits/dast/<date>/REPORT.md` and `new-findings.txt` for the lane
   being triaged.
2. For each finding, produce: scanner · key · severity · **Plain English** (what an attacker
   could actually do) · **Technical** (the mechanism) · your recommendation — *fix now*,
   *accept into baseline* (with the reason it's not exploitable here), or *needs more info*.
3. Present the table to Josh and **wait**. Do not proceed on your own judgment.
4. Only for the findings Josh accepts:
   ```bash
   scripts/dast/run.sh --only edge   --update-baseline
   scripts/dast/run.sh --only active --update-baseline
   ```
   Note this **re-runs the lane** and appends that run's findings — so make sure the run you
   triaged is still representative, and don't invoke it for a lane you didn't review.
5. Findings Josh wants fixed become normal fix work. Follow `scripts/audit/fix-flow.md` if
   there are several.

**Report:** what went into the baseline and why, and what became fix work.

---

## D4 — DAST self-test

Run after changing anything under `scripts/dast/` itself. **Not** a routine security check.

---

Run the DAST tool's own acceptance gate:
```bash
scripts/dast/tests/dast-selftest.sh
```

What it proves: it plants known-vulnerable canaries in both lanes, asserts they're flagged
**and** that they fail the build, then asserts a clean target passes and a baselined finding
is suppressed. In other words it proves the scanner isn't silently broken — which a normal
green run cannot distinguish from a scanner that found nothing because it scanned nothing.

**Known build gotchas in this tool** (bit us during implementation): bash 3.2 on macOS (no
associative arrays, no `${var,,}`), `ServeCommand` needs `--no-reload`, and YAML boolean
coercion silently changes template semantics. If the self-test fails, suspect these before
suspecting the assertions.

Report: pass/fail per canary, and whether the suppression assertion held.

---

## F1 — Drill 01 (worker kill)

---

Run **failure drill 01 — worker kill mid-job**. Runbook: `docs/runbooks/drills/01-worker-kill.md`
(follow it; this prompt is the framing + the traps). Overview: `docs/runbooks/drills/README.md`.

**Question the drill answers:** when a queue worker dies while `SyncSubdomainToKvJob` is
executing, do the DB and Cloudflare KV diverge — and does retry converge them without help?

**Where it runs — non-negotiable: the LOCAL stack only** (Herd + local Horizon + local
Redis). Never against a deployed environment. *Note: the runbook justifies this by saying
`development` "is production right now" — that premise died at the 2026-07-26 cutover, but
the local-only rule stands on its own: you cannot SIGKILL a managed worker or stop managed
Redis in Laravel Cloud.*

**Structure — four phases.** ARRANGE (put the system in the interesting state) → INJECT
(cause the failure) → OBSERVE (collect evidence) → VERDICT + RESTORE. Phases 1–3 are
copy-pasteable; **phase 4 is judgment — that's yours to reason about, not to script.**

**Before you start:**
- Verify the target-job facts at the top of the runbook are **still true** in the code
  (`$tries`, `$backoff`, `$timeout`, `$uniqueFor`, `retry_after`). A drill against stale
  facts proves nothing.
- **Decide the KV mode up front and record it in the log:** real KV (dev-namespace creds in
  local `.env` — the only mode that can show real divergence) vs unconfigured
  (`CloudflareKvService` no-ops via `guardUnconfigured`, so you get queue semantics only).
- Use a **dedicated drill user** with handle prefix `drill-`. Never a real user's row.

**Traps:**
- **Redis DB numbering.** Queue + Horizon both live on **DB 0** (the connection named
  `default`) — *not* DB 3 despite that connection being named `queue`, and not DB 2 which is
  sessions. Check `config/queue.php` + `config/horizon.php`. Inspecting the wrong DB
  produces a confident, wrong verdict.
- **`ShouldBeUnique` with `$uniqueFor` 45s means a SIGKILL leaves the unique lock held until
  it expires** — a re-dispatch within ~45s of the kill is *silently dropped*. That's a real
  behavior to observe, not a bug in your procedure.
- **Local logs are correct here.** `storage/logs/laravel.log` is the real log for local
  drills; the "logs live in Laravel Cloud" rule applies to deployed envs only.

**Deliverable:** a log at `docs/runbooks/drills/logs/<YYYY-MM-DD>-worker-kill.md`, copied
from `logs/TEMPLATE.md`, with date, observed state, verdict against the runbook's pass
criteria, and findings. **A drill without a written log didn't happen.** Findings needing
code changes become normal fix work — link them, don't track them in the log.

Restore fully (delete the drill user, revert any `REDIS_QUEUE_RETRY_AFTER` override +
`config:clear`) before you finish.

---

## F2 — Drill 02 (vendor outage)

---

Run **failure drill 02 — vendor outage during platform refresh**. Runbook:
`docs/runbooks/drills/02-vendor-outage.md`. Overview: `docs/runbooks/drills/README.md`.

**Question:** when a platform's API 500s or is unreachable, does `RefreshConnectionJob` fail
*quietly and boundedly* — or retry-storm Horizon? And do the circuit breaker and the
user-facing health notification actually fire?

**LOCAL stack only** (Herd + Redis + Horizon; local `supervisor-1` covers the
`platform_refresh` queue). See the note in prompt F1 about why.

**The hypothesis you're testing** (verify each is still what the code says before running):
`FetchUnavailableException` → `recordFailure(status:'unavailable')` and the job **completes
successfully**, no retry; `FetchShapeException` → same bookkeeping plus a loud `report()`;
only an *unexpected* exception escapes to the job, capped at `$maxExceptions` 3 with backoff
[30,120,300]. `$tries` 0 is deliberate — unbounded attempts apply only to
`RateLimited('platform-refresh')` releases, wall-clock-capped by `retryUntil()` at 2h.

**The core judgment — this is the whole drill:** which path does a *real* outage take? A
vendor hard-down (connection refused) may surface as a raw `ConnectionException` rather than
a translated `FetchUnavailableException`. **Bounded (≤3 attempts) is acceptable; unbounded
or rate-limit-amplified retries are the FAIL condition.** Both INJECT variants (hard outage
via host→localhost, and a 500-returning stub) are worth running if time allows.

**Precondition care:** you need at least one active refreshable `IntegrationConnection`.
Prefer one on a **drill user** — `PlatformHealthNotifier::connectionRefreshFailing()` emails
the connection's owner, and you do not want that going to a real person. Note the baseline
row values (`last_refresh_status`, `consecutive_failures`, `last_refreshed_at`) so you can
diff.

Also verify the circuit breaker: the `integrations:refresh` dispatcher must skip connections
at `consecutive_failures >= config('partna.refresh.max_consecutive_failures')` (10).

**Deliverable:** `docs/runbooks/drills/logs/<YYYY-MM-DD>-vendor-outage.md` from
`logs/TEMPLATE.md`. Restore the vendor host and reset the connection's failure counters
before finishing.

---

## F3 — Drill 03 (Redis down)

---

Run **failure drill 03 — Redis down**. Runbook: `docs/runbooks/drills/03-redis-down.md`.
Overview: `docs/runbooks/drills/README.md`.

**Question:** do public reads and analytics beacons degrade gracefully, or 500-cascade? And
does recovery leave stuck state behind?

**LOCAL stack only** — you cannot stop managed Redis in Laravel Cloud. See prompt F1's note.

**Preconditions that change the result if wrong:**
- Local `.env`: `CACHE_STORE=redis` — **not** a failover store, which silently breaks the
  escalation invariant this drill is testing. `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=cookie`.
- A drill user with an active site (reuse F1's pattern); note its `handle` and `site_id`.
- Horizon running — watching it die and come back is part of the drill.
- `export BASE=<local site URL>` from `herd links`.

**Record the BASELINE before injecting** — the runbook's three probes: public profile read
(edge-uncached, straight to Laravel), analytics beacon, and an authed dashboard read. Without
a before, the after means nothing.

**Trap on the beacon probe:** the analytics write needs an `Origin` header matching
the site's subdomain (Referer is no longer accepted, #SEC-3). SEC-1 fails **closed** with a
404 "Site not found" otherwise — you'd misread your own malformed request as a Redis-down
failure.

**Also worth running:** optional Scenario C — Redis *hung*, not down. Hung is the nastier
production failure (connections block rather than refuse) and often behaves differently from
a clean connection-refused.

**On recovery, check for stuck state** — not just that requests succeed again. Static-counter
fail-open behavior and `EscalatesRepeatedFaults` are the paths to watch; note that static
counters don't survive FPM process boundaries, so absence of escalation may be an artifact.

**Deliverable:** `docs/runbooks/drills/logs/<YYYY-MM-DD>-redis-down.md` from
`logs/TEMPLATE.md`, with the baseline-vs-injected-vs-recovered comparison and a verdict
against the runbook's pass criteria. Restart Redis and confirm full recovery before finishing.

---

## F4 — Drill 04 (backup / restore)

Launch-checklist **TECH-3 (P0)**, then quarterly (TECH-S3-7 / OPS-S4-4). Independent of all
code work — runnable any time.

---

Run **failure drill 04 — Supabase backup / restore**. Runbook:
`docs/runbooks/drills/04-backup-restore.md`.

**Question:** can we actually get the database back — how fresh is the freshest backup (RPO),
how long does a restore take (RTO), and what manual steps stand between "restored" and "app
connects"?

**Ground rules — read these before touching anything:**
- **Source: prod** Supabase `edplucmvkcnokyygxqsb` (live since the 2026-07-26 cutover).
  **Read-only against it. Never restore INTO it.** Older runbook revisions named the dev ref;
  that premise is dead.
- **Destination: a throwaway scratch project, deleted at the end.** Confirm cost before
  creating it and note the figure in the log.
- **Time every step.** RTO is the deliverable, not a vibe.

**Fastest path (preferred):**
```bash
gh workflow run restore-drill.yml --repo Hunter-Balcombe-Sykes/partna-db-backup
```
That workflow does the whole fallback path — pull the newest R2 object, decrypt, restore into
a throwaway `postgres:17` service container, assert per-schema table counts, print row
counts. It runs where the four secrets already live, so **neither the encrypted dump nor its
passphrase ever reaches a laptop.** Prefer it. The runbook's manual Phases 1–2 remain the
reference for *what* is being checked.

**Constraints you must not design around:**
- **"Restore to a new project" is UNAVAILABLE** — paid-tier only, and the org is on **Free**.
  Which also means **no PITR and no managed daily backups**. The weekly R2 dump is the only
  backup that exists, so **worst-case RPO ≈ 7 days**. State this in the log.
- **Prime any non-Supabase destination first, or RLS comes back silently missing.** Roles are
  cluster-global and `pg_dump` doesn't carry them; extensions live outside the dumped
  schemas. Restoring without creating the nine roles + `pg_trgm` drops **every RLS policy and
  all 14 trigram indexes** while still reporting a mostly-successful restore. The runbook has
  the exact SQL. Restoring into a real Supabase project needs none of it.
- Dump from the source via the **pooler** connection string, session mode, port 5432.
- Also note: `pg_dump` **omits invalid indexes** — a from-dump restore can silently lose an
  index that exists (invalid) upstream.

**Phases:** 1 Reconnaissance (read-only) → 2 Restore (start the clock) → 3 the gap between
"restored" and "working" → 4 Integrity verification → 5 Teardown + verdict.

**Deliverable:** `docs/runbooks/drills/logs/<YYYY-MM-DD>-backup-restore.md` from
`logs/TEMPLATE.md`, carrying the **measured RTO and RPO** — those numbers are what the
launch-check manual checklist points at. Confirm the scratch project is deleted before
finishing.
