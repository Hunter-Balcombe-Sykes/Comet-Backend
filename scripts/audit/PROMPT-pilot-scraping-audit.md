# Pilot-readiness + scale audit — platform integrations / scraping / data-collection / caching

You are auditing the **external-platform integration subsystem** of the Partna backend — the
scraping / data-collection / caching layer largely built by Tobias. The goal is to surface
**everything that must be fixed before pilot launch** and **everything that will break at scale**,
across every relevant audit lens. This is **audit-only**: produce findings, do not fix code, do
not push, work read-only.

## How to run it (non-negotiable)

Use the existing pipeline at `scripts/audit/audit.sh` (DeepSeek scan → Sonnet adjudication →
canonical audit file). **Do not hand-write findings** — the pipeline is the source of truth and
its output format feeds the audit orchestrator. Each scan is ~5–7 min and ~$0.06–0.25.

This is a large run: the three bundles below expand to 14 internal scans, plus 4 loose lenses =
**18 DeepSeek scans + 7 adjudications**. Expect 1–2 hours wall-clock. Run the commands **one at a
time, sequentially** (you may background a single invocation and monitor it, but do not fire all
seven concurrently — DeepSeek rate limits and the local `claude` CLI will choke). Pass
`--keep-drafts` if you want to inspect the per-lens DeepSeek drafts.

## Scope (identical for every command)

```bash
PHASE=phase-pilot-scraping
SCOPE=(
  # --- Integrations / platform-connections subsystem ---
  --scope app/Models/Core/Site/IntegrationConnection.php
  --scope app/Http/Controllers/Api/Platforms                       # Instagram(Apify)/YouTube/Fresha(GraphQL)/Eventbrite/Apple/Shopify/TikTok/Facebook + Concerns/ManagesIntegrationConnection
  --scope app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php
  --scope app/Http/Controllers/Api/PublicSite/PublicConfigController.php
  --scope app/Services/Platforms                                   # scrapers + PlatformRefresher + PlatformScraper base
  --scope app/Services/SmartLinks/SafeUrlFetcher.php               # SSRF boundary used by ALL scrapers — in scope even though rest of SmartLinks isn't
  --scope app/Services/SmartLinks/SafeUrlException.php
  --scope app/Policies/IntegrationConnectionPolicy.php
  --scope app/Observers/Core/IntegrationConnectionObserver.php
  --scope app/Console/Commands/RefreshIntegrationConnectionsCommand.php
  --scope app/Console/Commands/EnforcePlatformLinkCapCommand.php
  --scope routes/api/integrations.php
  --scope supabase/migrations/20260602150238_create_platform_connections.sql
  # --- Caching + Cloudflare KV / edge layer ---
  --scope app/Services/Cache                                       # SiteCacheService, CacheLockService, CacheKeyGenerator, JitteredTtl
  --scope app/Services/Cloudflare                                  # CloudflareKvService, CloudflarePurgeService
  --scope app/Jobs/Cache                                           # WarmPublicSiteCacheJob, AggregateCacheMetricsJob
  --scope app/Jobs/Cloudflare                                      # SyncSubdomainToKvJob, CloudflareCachePurgeJob, RetireSubdomainFromKvJob
  --scope app/Http/Middleware/AddPublicCacheHeaders.php
  --scope app/Listeners/RecordCacheMetrics.php
  # --- Tests (so the test-coverage lens can measure the safety net) ---
  --scope tests/Feature/Platforms
  --scope tests/Feature/Cache
  --scope tests/Unit/Jobs
  --scope tests/Unit/SmartLinks/SafeUrlFetcherTest.php
)
```

## The runs (7 invocations → 7 files in `audits/phase-pilot-scraping/`)

```bash
# Correctness bundles — each expands to multiple lenses + 1 adjudication → 1 output file
scripts/audit/audit.sh --phase "$PHASE" --bundle core         "${SCOPE[@]}"   # SEC/LIFE/CACHE/SCALE/SCHEMA/CCH/WHK/TXN (8 lenses)
scripts/audit/audit.sh --phase "$PHASE" --bundle pre-merge    "${SCOPE[@]}"   # MIG/API/CFG/TEST (4 lenses)
scripts/audit/audit.sh --phase "$PHASE" --bundle code-quality "${SCOPE[@]}"   # SLOP/SEM (2 lenses)

# Loose lenses (not in any bundle) — one focused scan + adjudication each
scripts/audit/audit.sh --phase "$PHASE" --lens-file scripts/audit/lenses/caching-coverage-gaps.md  "${SCOPE[@]}"
scripts/audit/audit.sh --phase "$PHASE" --lens-file scripts/audit/lenses/data-integrity.md         "${SCOPE[@]}"
scripts/audit/audit.sh --phase "$PHASE" --lens-file scripts/audit/lenses/job-queue-correctness.md  "${SCOPE[@]}"
scripts/audit/audit.sh --phase "$PHASE" --lens-file scripts/audit/lenses/observability.md          "${SCOPE[@]}"
```

> `brand-status-recent-changes.md` is intentionally excluded — it's date-pinned to the
> 2026-04-14→05-06 embedded-Shopify push, not this scope.

## What to weight heavily (pre-pilot / legal context)

- **Security is the highest-priority pre-pilot lens** for this scope: SSRF on `SafeUrlFetcher`,
  tenant isolation on `site.platform_connections` (the `(user_id, platform, resource_id)` uniqueness
  + the `IntegrationConnectionPolicy`), secret handling for the Apify / Cloudflare tokens, and the
  public integration endpoint using **404 not 403** for missing/inaccessible resources.
- **Known legal red-paths** — treat findings here as launch-critical:
  - Instagram **Apify** scrape + image rehost: an SSRF hole here was **fixed 2026-06-03**
    (`SafeUrlFetcher` + host allowlist + image-only content-type). Confirm it's still sound; don't
    re-flag the resolved issue as new.
  - **Fresha GraphQL** uses a persisted-query hash pinned to Fresha's frontend build — flagged as a
    legal/impersonation red-path in the 2026-05-31 review. Note any new exposure here.
- **Scale shape**: the refresh cron (`integrations:refresh` / `PlatformRefresher`) iterating every
  active connection daily, Apify per-user cooldown + global daily cap, N+1 on the public endpoint,
  cache stampede protection (`CacheLockService` single-flight + jittered TTL), and
  `SyncSubdomainToKvJob` / `CloudflareCachePurgeJob` being the **only** KV/edge writers.

## After the runs — consolidate (this is the deliverable)

Synthesize the 7 output files into **one** pilot-readiness ledger at
`audits/phase-pilot-scraping/audit-<today>-pilot-scraping-CONSOLIDATED.md`:

1. **Verify every finding's premise against the current code/schema/git history before keeping it.**
   The auto-generated scans sometimes assume a column, query, or method that doesn't exist. Read the
   cited file; drop or rewrite findings whose premise is false. State which you dropped and why.
2. **Dedupe across lenses** — the same bug often appears under SEC + SCHEMA + DATA, etc. Keep the
   highest-signal instance.
3. **Tier P0–P3 and group**: `## Launch blockers (P0/P1)` → `## Scale risks` → `## Correctness/data
   integrity` → `## Code quality & tests`. Tag each item S/M/L/XL effort.
4. **Use the canonical finding format** (see `pilot-stage-1.md`): top-level `- [ ]` + `**#ID**` +
   tier + effort tag, with `Where:` / `Affects:` / `What to do:` / `Technical:` / `Plain English:` /
   `Evidence:` (verbatim code). The orchestrator parses this — structure is load-bearing.

## Guardrails

- Read-only. Do not edit code, do not run `composer test` against changes, do not commit or push.
- If you need to check runtime behaviour, use `cloud env:logs partna development` — **never** the
  laravel-boost log tools (they show stale test output).
- Always `git fetch && git pull && git log --oneline -10` first; this is a shared repo.
