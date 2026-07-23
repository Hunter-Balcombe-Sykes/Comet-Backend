# Staff Ops & Observability Surface — Design

**Date:** 2026-07-23
**Status:** Approved (design), not yet planned
**Scope:** Backend (`partna-backend`), with one cross-repo slice into `partna-frontend`

---

## 1. Problem

The staff dashboard has user management, moderation cases, segments, feedback and
feature flags — but no operational visibility. `GET /api/staff/stats` is the only
"platform health" endpoint and it returns a single number (count of non-deleted
users).

Meanwhile the app already computes real health signals that no human ever sees:

- `HealthController::scheduler()` — a careful scheduler-liveness probe whose only
  consumer is a load balancer reading the HTTP status code.
- `HealthController::check()` — DB and cache reachability with latency.
- `EnvCheckService` — config-path verification, wired to `env:check` and
  `/api/internal/env-check`.
- Horizon — a full queue telemetry set sitting in Redis, reachable only through a
  separate HTTP-basic-auth UI at `/horizon`.

Operational state is therefore spread across four places (Horizon UI, Nightwatch
SaaS, Laravel Cloud logs, and unread HTTP probes), none of which is the staff
dashboard, and one of which (routing integrity) nothing checks at all.

## 2. Goals

1. **A global status board** — one staff page answering "is anything broken right
   now?", with deep-links out for drill-down.
2. **Per-user ops context** — when viewing a professional, show *their* failed
   jobs, build errors and delivery failures, so support can answer "why did this
   pro's site not generate?" without escalating.

## 3. Non-goals

Stated explicitly to prevent scope creep:

- **Not rebuilding Horizon's UI.** Retrying jobs, live throughput charts and job
  payload inspection stay in `/horizon`. The board links to it.
- **Not proxying Nightwatch.** Nightwatch has no documented public read API — its
  only inbound surface is an OAuth MCP server built for AI agents, not for
  server-to-server fetches. Nightwatch appears as a deep-link.
- **Not building alerting.** The board reports state when a human looks at it.
  Paging is Better Stack's and Nightwatch's job.
- **Not persisting history.** No `ops_snapshots` table in this design. Considered
  and deferred (see §11).

## 4. Key constraint: first-party vs third-party data

The four sources named in the request are not the same kind of thing, and the
design turns on the difference:

| Source | Where data lives | Access path |
|---|---|---|
| **Horizon** | This app's own Redis | Injectable contracts. No network. |
| **Nightwatch** | Laravel SaaS | Write-only from here. Deep-link. |
| **Better Stack** | Their SaaS — **not currently integrated** | `GET https://uptime.betterstack.com/api/v3/{monitors,incidents}`, Bearer token. |
| **Cloudflare edge** | Cloudflare | GraphQL Analytics API, reusing the `CLOUDFLARE_API_TOKEN` this app already holds. |

**Design consequence:** a page whose job is "tell me when things are broken" must
have the fewest possible reasons to break. Every external call is a new way for
it to hang at exactly the wrong moment. Therefore all external probes are
**cached, hard-timeout-bounded, and fail-soft** — an unreachable third party
renders one grey tile, never a spinner or a 500.

## 5. Architecture

```
app/Services/Ops/
  HealthProbe.php          interface: key(), label(), group(), check(): ProbeResult
  ProbeResult.php          readonly VO: status, summary, metrics[], links[], checkedAt
  ProbeStatus.php          enum: Ok | Warn | Critical | Unknown
  OpsProbeRegistry.php     keyed collection; runs probes with per-probe isolation
  Probes/                  one class per signal
```

Registration lives in a new `OpsServiceProvider`, mirroring how
`PlatformRegistryServiceProvider` wires `PlatformRegistry`. Adding a signal is
one class plus one registration line — no controller edits.

**Per-probe isolation.** `OpsProbeRegistry` wraps every `check()` in its own
`try/catch(Throwable)`. A throwing probe yields `ProbeStatus::Unknown` with a
truncated message (no stack trace, even though the surface is staff-only). The
board endpoint therefore **never returns 5xx** — overall status travels in the
payload, not the HTTP code. This is deliberate: the board is a human surface, and
uptime monitoring of it belongs to Better Stack.

**Rollup.** Overall status is the worst tile, ranked
`Critical > Warn > Unknown > Ok`. `Unknown` sits below `Warn` because "we could
not measure this" is less actionable than "we measured this and it is degraded".

### 5.1 Probe contract

```php
interface HealthProbe
{
    public function key(): string;      // 'queue.depth'
    public function label(): string;    // 'Queue depth'
    public function group(): string;    // 'queues' | 'platform' | 'edge' | 'external'
    public function check(): ProbeResult;
}
```

`ProbeResult` is readonly and always non-null — required, because
`CacheLockService::rememberLocked()` documents that its callback must not return
null.

## 6. Slice 1 — Ops spine and first-party probes

| Key | Source | Warn | Critical |
|---|---|---|---|
| `horizon.supervisor` | `MasterSupervisorRepository::all()` | supervisor paused | no master / inactive |
| `queue.depth` | `WorkloadRepository::get()` | wait > threshold | wait > 2× threshold |
| `queue.failed` | `JobRepository::countRecentlyFailed()` | any in last hour | > N in last hour |
| `queue.throughput` | `MetricsRepository::throughput()` | — | — (informational tile) |
| `scheduler` | extracted `SchedulerHealthService` | any task stale | runner not proven alive |
| `database` | extracted `DatabaseHealthService` | latency > threshold | unreachable |
| `cache` | extracted `CacheHealthService` | latency > threshold | read-back mismatch |
| `env.config` | existing `EnvCheckService` | missing RECOMMENDED | missing REQUIRED |
| `routing.kv` | `site.sites` vs `SUBDOMAIN_KV` | drift > 0 | drift > N |

All thresholds live in `config/partna.php` under `ops.thresholds.*`, per the
existing convention that all limits are config-driven.

### 6.1 Included refactor: extract Diagnostics services

`HealthController` currently holds its DB, cache and scheduler logic inline, so
the probes cannot reuse it without duplication. Slice 1 extracts it:

```
app/Services/Diagnostics/
  EnvCheckService.php        (exists)
  DatabaseHealthService.php  (new — from HealthController::checkDatabase)
  CacheHealthService.php     (new — from HealthController::checkCache)
  SchedulerHealthService.php (new — from HealthController::scheduler)
```

`HealthController` becomes a thin caller. **`/api/ready` and
`/api/health/scheduler` must remain byte-identical in both body and status code**
— they are consumed by deployment health checks.

**Coverage asymmetry, verified 2026-07-23.** `tests/Feature/Health/SchedulerHealthTest.php`
guards the scheduler half. `/api/ready` has **no dedicated test** — the only test
touching it is `tests/Feature/Security/SecureHeadersTest.php`, which asserts
response headers, not payload shape. The DB/cache extraction is therefore
currently unguarded and is the riskier half of this refactor. **Characterisation
tests for `/api/ready` (healthy 200 shape, unhealthy 503 shape, `ms` key present)
must be written and passing against the pre-refactor code before the extraction
begins**, so they prove behaviour was preserved rather than merely describing
whatever the new code does.

The scheduler extraction must preserve two subtleties that are easy to lose:

- It lazily `require`s `routes/console.php`, because the `Schedule` singleton is
  empty over HTTP (bootstrap wires it via `withRouting(commands:)`, gated on
  `runningInConsole()`). Without this, every check passes **vacuously**.
- It distinguishes "never ran because cron is dead" from "never ran because it
  is not due yet", via the `$schedulerProvenAlive` second pass.

### 6.2 `routing.kv` — the probe worth building this for

Every other tile has an off-the-shelf equivalent. This one does not, because only
the database knows which sites *should* be routable.

**Failure it catches:** a published site whose `SyncSubdomainToKvJob` silently
failed, so `<handle>.partna.au` 404s while everything else reports green. This is
invisible to Horizon (the job may have succeeded on a later retry), invisible to
Nightwatch (no exception is thrown), and invisible to Better Stack (which
monitors handles you already know about, not the one that just broke).

**Implementation.** Diff two sets:

- Expected: published, non-deleted `site.sites` subdomains + active
  `site.site_subdomain_aliases`.
- Actual: keys present in `SUBDOMAIN_KV`.

**New capability required.** `CloudflareKvService` currently exposes only `put`,
`bulkPut` and `delete` — there is **no list operation**. This slice adds
`list(?string $cursor = null): array` wrapping Cloudflare's
`GET /accounts/{account}/storage/kv/namespaces/{ns}/keys` with cursor pagination.

A naive per-site `GET` would issue hundreds of Cloudflare calls per board load.
The bulk list plus an in-memory diff issues a handful. Cached for 300s — routing
drift is a slow-moving condition, not a live metric.

### 6.3 Endpoints

```
GET /api/staff/ops/health           all probes, grouped by group()
GET /api/staff/ops/health/{probe}   one probe, full detail
GET /api/staff/ops/queues           per-queue workload + recent failures
GET /api/staff/ops/routing          the drifted handles themselves, not just a count
```

Registered inside the existing `staff` group in `routes/api/staff.php`, so they
inherit `supabase.jwt → require.email_verified → staff → require.aal2 →
throttle:staff → staff.audit` unchanged.

Responses go through `OpsHealthResource` / `ProbeResultResource` — no raw arrays,
per the project rule against returning raw Eloquent/arrays from endpoints.

**Caching.** Follows the `StaffStatsController` precedent:
`CacheLockService::rememberLocked('staff:ops:health', 30, …)`. Expensive probes
carry their own longer per-probe TTL (`routing.kv` 300s). Note that
`rememberLocked` provides stale-while-revalidate — a stale board is strictly
better than a slow one here.

## 7. Slice 2 — External probes

### 7.1 `BetterStackProbe`

- `GET https://uptime.betterstack.com/api/v3/monitors` — per-monitor up/down.
- `GET https://uptime.betterstack.com/api/v3/incidents?resolved=false` — open
  incidents only.
- Auth: `Authorization: Bearer $TOKEN`, a **team-scoped Uptime API token** (not a
  global token — least privilege).
- New config: `services.betterstack.uptime_token` ← `BETTERSTACK_UPTIME_TOKEN`.
- Added to `EnvCheckService::RECOMMENDED` so a missing token surfaces as a
  visible config gap rather than a mysteriously grey tile.
- Cached 60s.

**Prerequisite:** a Better Stack account with monitors configured. If the token
is absent the probe returns `Unknown` with "not configured" — it must not warn or
crit, because unconfigured is not unhealthy.

### 7.2 `CloudflareEdgeProbe`

- Cloudflare GraphQL Analytics API, reusing the existing `CLOUDFLARE_API_TOKEN`,
  `CLOUDFLARE_ACCOUNT_ID` and `CLOUDFLARE_ZONE_ID`. **No new secret.**
- Surfaces: Worker invocations and error rate for `partna-subdomain-router` and
  `partna-pages`; zone cache hit ratio; 5xx rate.
- Cached 300s — Cloudflare's analytics pipeline lags by minutes, so a shorter TTL
  would burn quota for no extra freshness.

**Note:** the existing token may lack the Analytics read scope. Verify during
planning; if so, the token needs its permissions widened rather than a new token
minted.

### 7.3 Shared external-call policy

Both probes use `Http::timeout(3)->retry(1, 200)` with a hard total budget, and
catch `Throwable` → `Unknown`. Both are tested with `Http::fake()`, including the
timeout and non-200 paths.

## 8. Slice 3 — Per-user ops context

### 8.1 Prerequisite: job tagging

**Finding: 0 of 49 job classes currently implement `tags()`.**

Horizon maintains a Redis-side tag index (`TagRepository`, with `count($tag)`,
`jobs($tag)` and `paginate($tag, $from, $limit)`). A job declaring:

```php
public function tags(): array
{
    return ['user:'.$this->userId];
}
```

becomes retrievable by that tag with no extra storage — exactly the primitive
per-user ops context needs.

**Without tags the alternatives are both bad:** Horizon stores failed jobs as
PHP-serialized payloads, so per-user lookup means either unserializing every
failed job (slow, and fragile across deploys that change job class shape) or
`LIKE '%uuid%'` against the payload blob (a full scan).

Add `tags()` to the user-scoped jobs — `GeneratePreAccountSiteJob`,
`SyncSubdomainToKvJob`, the platform refresh/scrape jobs, and the notification
dispatchers. Enumerate the exact list during planning.

**Documented limitation:** tags apply only to jobs dispatched *after* this ships.
Historical failures remain invisible. This is stated here so it is discovered in
the spec rather than mid-debug.

### 8.2 Endpoint

```
GET /api/staff/professionals/{professional}/ops
```

Composes, all scoped to that user:

- Recent job outcomes via `TagRepository::paginate('user:'.$id, …)`
- Pre-account build state (`core.pre_account_builds`) and failure reason
- Platform connection sync failures and last-refresh timestamps
- `EmailSuppression` status (bounced/complained → why their mail stopped)
- Their KV routing state (present? alias? stale?)

Placed in the existing `staff` group alongside the other
`/professionals/{professional}/…` routes.

## 9. Authorization

**Decision: all staff roles (`support` and `admin`) may view both surfaces.**

Justified while staff ≈ one person. Implemented so that tightening is trivial:

- Add `viewOps(PartnaStaff $actor): bool` to the existing `PartnaStaffPolicy`.
- Controllers call `$this->authorizeForUser($staff, 'viewOps', $staff)` — the
  same shape `StaffMeController` already uses.
- Restricting to admin later is a one-line change inside the policy method, not a
  refactor of every controller.

**Why not a standalone `OpsPolicy` or `Gate::define`:** there are zero
`Gate::define` calls in this codebase — authorization is entirely model-policy
based, and `PolicyCoverageTest` enforces that every model has a policy. An
ability hung off the already-policied `PartnaStaff` model fits the grain;
a model-less gate would be the first of its kind.

**Noted risk:** `env.config` reports which config paths are populated. It never
returns values, but presence-mapping is still infrastructure reconnaissance
(which storage provider, which integrations hold credentials, which secrets are
unset). This is the tile most likely to warrant admin-only gating first.

### 9.1 Horizon UI: unchanged, with a known gap

`/horizon` keeps its HTTP basic auth (`AppServiceProvider:267`,
`HORIZON_DASHBOARD_USERNAME`/`PASSWORD`) and the board deep-links to it.

**Known gap, deliberately accepted and recorded here:** that is a second
credential with a second revocation path. Deleting someone's `core.partna_staff`
row does **not** revoke their Horizon access. Closing this (by extending the
`Horizon::auth` closure to also accept a staff-admin session) is deferred to its
own change, because Horizon's UI is session/cookie-based rather than
bearer-token-based and conflating the two auth models carries lockout risk.

## 10. Slice 4 — Frontend SPA health (cross-repo)

Requires coordinated work in `partna-frontend` and is therefore specced, not
built, by this backend change.

- **Deploy version:** the frontend exposes its build commit; the board displays it
  alongside the backend's `LARAVEL_CLOUD_COMMIT` so version skew is visible.
- **Client-side JS errors:** a new public, tightly-throttled, bot-protected
  ingest endpoint here, plus a matching error handler in the frontend.

**Open question for planning:** whether JS error ingest duplicates something
Nightwatch or Better Stack already offers for browser telemetry. Resolve before
building — this slice has the worst effort-to-value ratio of the four and should
only proceed if the answer is no.

## 11. Alternatives considered

**Fat `StaffOpsController` with a method per source.** Rejected: every new signal
edits the same file, and per-probe error isolation would be hand-rolled in each
method rather than guaranteed once by the registry.

**Snapshot table (`core.ops_snapshots`) written by a scheduled command.** Would
give instant board loads and trend history, and has one elegant property — if the
scheduler dies the board visibly goes stale, which is itself the alert. Deferred:
it costs a Supabase migration and couples the board to the very scheduler it
monitors. The probe registry is a compatible spine — snapshotting can be layered
on later by persisting `ProbeResult`s, once real usage shows which tiles deserve
history.

**Proxying Nightwatch.** Rejected: no public read API. The only options would be
scraping an undocumented endpoint (fragile, likely against terms) or waiting for
one to ship.

## 12. Testing

- **Unit, per probe:** mocked Horizon repositories / Diagnostics services; assert
  the status boundaries at, above and below each threshold.
- **Unit, registry:** a deliberately-throwing probe yields `Unknown`, not an
  exception; rollup ordering is `Critical > Warn > Unknown > Ok`.
- **Feature, board:** returns 200 with every registered key; still 200 when a
  probe is `Critical`; non-staff → 401/403; missing AAL2 → `mfa_required`.
- **Feature, external:** `Http::fake()` covering success, non-200, and timeout;
  assert fail-soft to `Unknown` and that no exception escapes.
- **Regression:** `/api/ready` and `/api/health/scheduler` bodies and status codes
  unchanged after the Diagnostics extraction.

**SQLite caveat.** Tests run SQLite while production is Postgres. `routing.kv`
queries `site.sites` and `site.site_subdomain_aliases`; any constraint-bound or
schema-shaped assertion must be verified against the DDL in
`supabase/migrations/`, not merely against a green suite.

## 13. Delivery order

| Slice | Ships | Blocked by |
|---|---|---|
| 1 — Ops spine + first-party probes + Diagnostics extraction | independently | — |
| 2 — Better Stack + Cloudflare edge | after 1 | Better Stack account + token |
| 3 — Job tagging + per-user ops | after 1 | tagging change to ~15 jobs |
| 4 — Frontend SPA health | after 1 | cross-repo work; §10 open question |

No database migration is required for slices 1–3.
