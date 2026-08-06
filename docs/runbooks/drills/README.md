# Failure-Mode Drills

Runbooks for deliberately breaking the system and watching how it behaves. These fill the
assurance gap none of the other flows covers (CI / audit pipeline / k6 / launch-check all
verify *written* or *configured* behavior — a drill verifies *runtime* behavior under a real
failure: an actual SIGKILL racing an actual half-finished job, a real connection-refused
from a dead Redis socket).

## The drills

| # | Drill | What it answers | Time | Cadence |
|---|-------|-----------------|------|---------|
| [01](01-worker-kill.md) | Worker kill | Does a worker death mid-job leave KV/DB diverged? Does retry converge? | 60–90 min | Once pre-pilot; re-run if job/queue code changes materially |
| [02](02-vendor-outage.md) | Vendor outage | Does a vendor 500/timeout cause a retry storm? Does the circuit breaker + health notifier work? | ~45 min | Once pre-pilot; re-run after refresh-path changes |
| [03](03-redis-down.md) | Redis down | Do public reads and beacons degrade gracefully or 500-cascade? Does recovery leave stuck state? | 45–60 min | Once pre-pilot; re-run after cache/queue-path changes |
| [04](04-backup-restore.md) | Backup / restore | Can we actually restore a Supabase snapshot, and how long does it take (RTO/RPO)? | 1–2 h first run | Once pre-launch (TECH-3, P0), then **quarterly** (TECH-S3-7 / OPS-S4-4) |

01–03 form the half-day **pre-pilot session**. 04 is independent of all code work and can be
run any time.

## Anatomy of a drill

Every runbook follows the same four phases:

1. **ARRANGE** — put the system into the interesting state (scripted).
2. **INJECT** — cause the failure (scripted, usually one command).
3. **OBSERVE** — collect evidence (scripted queries; a human reads them).
4. **VERDICT + RESTORE** — decide pass/fail against the runbook's criteria, undo the
   damage, record the result.

Phases 1–3 are copy-pasteable commands. Phase 4 is judgment — that's why drills are
runbooks, not CI scripts.

## Where drills run — non-negotiable

- **01–03 run on the LOCAL stack only** (Herd + local Horizon + local Redis). Never against
  the deployed `development` env: it cannot express these drills — it runs
  `QUEUE_CONNECTION=sync` with zero Horizon masters and managed Redis you can't stop.
  (Until the 2026-07-26 cutover this rule was also justified by "deployed development serves
  BOTH `dev-api.partna.au` and `api.partna.au` — it *is* production right now". That premise
  is dead: prod is `edplucmvkcnokyygxqsb` and dev is a genuinely separate env. **The rule is
  unchanged** — the reason is now solely the sync-queue / managed-Redis one above.)
- **04 never restores INTO a live project.** It restores the dev Supabase's backup into a
  throwaway scratch project, verifies it, and deletes the scratch project.
- **Drill data uses dedicated drill users** (handle prefix `drill-`), created at the start
  and deleted at the end. Never drill against a real user's row.
- **Local logs are correct for local drills.** The CLAUDE.md "logs live in Laravel Cloud"
  rule applies to the deployed envs; when the failing process is on this machine,
  `storage/logs/laravel.log` is the real log.

## Recording results

Each run appends a log at `logs/<YYYY-MM-DD>-<drill-slug>.md`, copied from
[`logs/TEMPLATE.md`](logs/TEMPLATE.md). The log — date, observed state, verdict, findings —
is the deliverable: it's what the launch-check manual checklist points at to say "this drill
has been done, here's the evidence." A drill without a written log didn't happen.

Findings that need code changes become normal fix work (or an audit finding) — the drill log
links to them but doesn't track them.

## Staleness rule

A drill result describes the code that was running when it ran. Re-run a drill when its
target path changes materially:

- 01 → job/queue plumbing, `SyncSubdomainToKvJob`, media jobs, Horizon config
- 02 → `RefreshConnectionJob`, `PlatformRefresher`, rate-limiter / circuit-breaker config
- 03 → cache/queue wiring, analytics ingest, throttle middleware, `EscalatesRepeatedFaults`
- 04 → never goes stale from code; re-run quarterly because *backups* rot, not code.
  Off-platform weekly dumps run from the `Hunter-Balcombe-Sykes/partna-db-backup`
  repo (GitHub Actions → Cloudflare R2); this drill also restores one of those.
