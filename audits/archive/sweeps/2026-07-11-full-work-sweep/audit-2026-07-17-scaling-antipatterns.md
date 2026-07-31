# Scaling Antipatterns Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Scaling antipatterns: write amplification, rebuild-on-write, weak caching (chunk: write-paths)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `app/Http/Resources/PublicSite/IndividualProfileResource.php`
- `app/Http/Resources/UserDashboardResource.php`
- `app/Observers/User/UserObserver.php`
- `app/Services/Analytics/ContentFreshness.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 0 complete

---

DeepSeek's null result was independently re-verified against each of the four scoped files:

- **`UserObserver.php`** dispatches a bounded, fixed set of side effects per single model save (`invalidateUser`, a conditional `touchParentSiteIfPublicFieldChanged`, a conditional `SyncSubdomainToKvJob::dispatch`, a conditional `reevaluatePublicContactSection`, a conditional `cleanupLifestyleConnectionsIfBusiness`). None of these scale with data cardinality or event payload size — they are O(1) per user update, not a per-row loop, not a rebuild-on-write, and not a fan-out job that multiplies per recipient. This is normal observer side-effect wiring, not the write-amplification shape the lens targets (N rows per event where N is unbounded). User-profile edits are also not a hot/write-heavy path per the platform's own scale context (that's public sitepage resolution and analytics ingest) — even if this work were synchronous request-thread cost, it wouldn't clear the bar for a scaling finding here.
- **`ContentFreshness::boostsForSite`** issues two bounded, well-scoped read queries (`IntegrationConnection::query()->where('user_id', ...)->active()->get(...)` and `Service::query()->where(...)->max('created_at')`), both scoped to one site's own data (bounded by a single user's connections/services — small cardinality, not a list/analytics sweep). It performs no writes, no DELETE+INSERT rebuild, and no cache access at all — it's a pure compute service consumed by other layers (a console command and two services outside this chunk's scope), so there's nothing here for categories (1)–(6) to attach to.
- **`IndividualProfileResource.php`** and **`UserDashboardResource.php`** are pure array-shaping Resource classes — no DB queries, no cache calls, no loops proportional to unbounded input. `UserDashboardResource` touches at most one lazy relation (`$this->site`) on a single-record "own profile" response, which is explicitly out of scope per the N+1 threshold (single row, not a list/sweep).

No rebuild-on-write, write-amplification, weak-caching, live-query, hot-path fan-out, or append-only/mutable-confusion pattern is present in verbatim code across these four files. This is a legitimate clean result for this chunk, not an under-scan — the higher-risk surfaces named in the lens background (analytics ingestors/writers, notification fan-out jobs, other observers) are covered by separate chunks in this sweep.

## Suggested Bundled Sessions

None.

## Standalone — do NOT bundle

None.
