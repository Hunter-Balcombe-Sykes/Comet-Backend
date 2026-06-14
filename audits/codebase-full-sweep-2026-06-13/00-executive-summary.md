# Full-Sweep Codebase Audit — Executive Summary — 2026-06-13

**Stage:** `full-sweep` (all 20 lenses)
**Date:** 2026-06-13
**Branch:** `development` · **HEAD:** `c7a016f4` (`c7a016f4de0e15b4127d70eb0cb3b5d4c5857523`)
**Working tree:** DIRTY at audit time — the audit ran against uncommitted changes. Modified-but-uncommitted: all of `scripts/audit/` (lenses, `system-prompt.md`, `adjudicate-prompt.md`) + `CLAUDE.md`; untracked: `docs/legal/2026-06-13-integrations-legal-review.md`, `docs/superpowers/plans/2026-06-11-gs1-cache-service-refactor.md`, two new lens files (`edge-worker.md`, `privacy-compliance.md`). No application code was dirty — only audit machinery.
**Fresh-merge context:** This run is the first audit after merge `08af1719` (account types Partna + Business, Square booking, custom domains) landed on `development`. Several findings correctly cite the just-merged code (MIG-1 → `20260612140000_site_custom_domain.sql`, EDGE-3 → custom-domain/moderation routing, TEST-1 → commit `c7a016f4`), confirming the scan saw the new architecture. Caveat: the lens/system-prompt ground truth may still describe the older "individual-only / booking-dropped" model, so spot-check any *tier* assignment that hinges on account-type, Square, or custom-domain assumptions.

## Run stats

| Metric | Value |
|---|---|
| Lenses run / failed | 20 / 0 |
| Chunk scans (DeepSeek, 6-wide) | 37 / 37 |
| Adjudications (Claude Sonnet, sequential) | 20 / 20 |
| Wall time | ~3.0h (adjudication 152 min) |
| UNRECOVERED lenses | 0 |
| Total findings | **169** — P0: **5** · P1: **24** · P2: **87** · P3: **53** |

## Per-lens breakdown

| Lens | P0 | P1 | P2 | P3 | Tot | Top finding |
|---|---|---|---|---|---|---|
| security | 0 | 1 | 10 | 3 | 14 | Analytics ingest IDOR — `site_id`-only requests bypass subdomain cross-check (cross-tenant injection) |
| lifecycle-correctness | 0 | 3 | 3 | 6 | 12 | `InstagramConnectJob` missing `ShouldBeUnique` → double-billed Apify scrapes on retry |
| scaling-antipatterns | 0 | 0 | 6 | 1 | 7 | `PostgresEventWriter::writeMany()` breaks batch contract for session pings |
| database-and-queue-scaling | 0 | 0 | 8 | 1 | 9 | `EnforcePlatformLinkCapCommand` — one Eloquent query per user (N+1) |
| schema-rls | 0 | 1 | 5 | 2 | 8 | `site.smart_links` has no Row Level Security |
| caching-gold-standard | 0 | 0 | 0 | 3 | 3 | Bare `Cache::put` in auth-ID mismatch repair bypasses `rememberLockedNullable` |
| webhook-idempotency | **1** | 0 | 4 | 0 | 5 | **Auth hook dedup anchor not reverted on `record()` failure → reject flips to allow** |
| transaction-boundaries | 0 | 1 | 1 | 2 | 4 | `ExportUserDataJob` dispatched inside tx without `afterCommit` |
| data-integrity | 0 | 0 | 5 | 9 | 14 | `notifications.email_subscriptions` retains PII indefinitely after unsubscribe |
| job-queue-correctness | 0 | 2 | 8 | 1 | 11 | `DispatchEnquiryNotificationsJob` has no idempotency guard → duplicate notifications |
| observability | 0 | 6 | 4 | 4 | 14 | `RefreshSmartLinksCommand` swallows all per-link exceptions without alerting |
| caching-coverage-gaps | 0 | 0 | 0 | 0 | 0 | (clean — 0 findings, valid result) |
| privacy-compliance | 0 | 4 | 4 | 0 | 8 | Data export includes staff member's email in the professional's package |
| edge-worker | **3** | 3 | 4 | 3 | 13 | **`Set-Cookie` from Astro origin cached at edge and replayed to every visitor** |
| configuration-hygiene | 0 | 0 | 5 | 7 | 12 | `FRONTEND_URL` required by code but absent from `.env.example` |
| migration-safety | 0 | 1 | 4 | 1 | 6 | `CREATE UNIQUE INDEX` without `CONCURRENTLY` on hot table `site.sites` |
| api-contract | 0 | 0 | 2 | 7 | 9 | `UserResource` is dead code carrying unconditional PII |
| test-coverage | **1** | 1 | 11 | 0 | 13 | **`IndividualProfileController` (primary public-profile API) has zero tests** |
| code-quality-slop | 0 | 0 | 1 | 3 | 4 | `cancel()`/`adminCancel()` duplicate the same DB-locking site-restore block |
| semantic-correctness | 0 | 1 | 2 | 0 | 3 | GDPR Art. 15: `reporter_email` stored un-normalised but queried lowercased |

## P0 blockers (verbatim — 5)

1. **#EDGE-1** · edge-worker — `Set-Cookie` headers from the Astro origin are stored in the edge cache and replayed to every visitor.
   *Where:* `cloudflare-worker/src/index.js:79–101` (`withCacheTtl`) and `153–163` (`fetchAndCache`).
2. **#EDGE-2** · edge-worker — Cache purge covers only the root URL; deep-linked paths and their stale shadows are never cleared.
   *Where:* `app/Services/Cloudflare/CloudflarePurgeService.php:100–109` (`purgeHandle`) vs `cloudflare-worker/src/index.js:153–162`.
3. **#EDGE-3** · edge-worker — Moderation enforcement bypasses edge cache purge entirely; taken-down content (incl. CSAM auto-suspend) survives in the stale shadow for up to 7 days.
   *Where:* `app/Services/Moderation/ModerationActionDispatcher.php:26–44` · `app/Jobs/Moderation/PurgeModerationCacheJob.php:39–50` · `app/Jobs/Moderation/SuspendSiteJob.php:56–58` · `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:96–100`.
4. **#WHK-1** · webhook-idempotency — Auth hook dedup anchor not reverted on `repo->record()` failure; rejection silently flips to "allow" on Supabase's retry (MFA brute-force lockout bypass).
   *Where:* `app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php` — `mfaVerification()`, all three `record()` call sites (lines 73, 92, 108).
5. **#TEST-1** · test-coverage — `IndividualProfileController` (the primary public-profile API, the Astro Worker's subrequest target) has zero tests.
   *Where:* `app/Http/Controllers/Api/PublicSite/IndividualProfileController.php:56`; no `tests/Feature/PublicSite/` host exists.

## Top P1 themes (grouped, 24 total)

- **Silent failure / swallowed exceptions (observability, 6 P1):** vendor/transport failures (`RefreshSmartLinksCommand`, `InstagramScraper`, `TwitchApiClient`, `KickApiClient`, `StreamingTokenManager`) are caught as `Log::warning`, never `report()`-ed → no Nightwatch alert, jobs report "succeeded." Most are ~1-line `report($e)` fixes.
- **Instagram auto-connect is silently broken (cross-lens):** `InstagramConnectJob` dispatches to a `scraping` queue with **no Horizon supervisor in any environment** (JOB-2, OBS-6) and lacks `ShouldBeUnique`, double-billing Apify on retry (LIFE-1).
- **GDPR / retention enforcement gaps (privacy + semantic):** staff email leaks into pro's export (PRIV-1); export artifacts never pruned (PRIV-2); handle-audit 7-yr retention unenforced (PRIV-3); reporter PII survives account deletion (PRIV-4); `reporter_email` normalisation miss (SEM-1).
- **Transaction atomicity / `afterCommit`:** `ExportUserDataJob` dispatched inside a DB transaction without `afterCommit` → silent GDPR export loss on fast worker pickup (LIFE-3 / TXN-2); `cancel()`/`adminCancel()` restore primary email outside the transaction (LIFE-2).
- **Edge-cache purge gaps (Cloudflare, edge-worker P1s):** `SiteObserver::deleted` never purges the edge (EDGE-4); custom-domain cache never purged after mutations (EDGE-5); Worker `RESERVED` set (18) diverges from `config('partna.reserved_subdomains')` (~200) (EDGE-6).
- **Schema / migration:** `site.smart_links` has no RLS (SCHEMA-1); `CREATE UNIQUE INDEX` without `CONCURRENTLY` on hot `site.sites` (MIG-1).
- **Tenant isolation:** analytics ingest IDOR via `site_id`-only payloads (SEC-1).

## Verification

- **Count check:** 20/20 audit files present, 0 unrecovered. ✅
- **Format check:** every file has a `# … Audit — 2026-06-13` title + `## Progress` block; findings intact. ✅
- **Hallucination spot-check:** all **5 P0s + 3 P1s** (SEC-1, MIG-1, OBS-2) verified — every `Evidence:` excerpt's distinctive code appears in the cited file; all cited files exist; TEST-1's "no test exists" premise confirmed. **8/8 clean, 0 spot-check failures.** ✅
- **Output-style contamination (remediated):** all 20 files were generated with `★ Insight` blocks (and, in 6 files, pre-title adjudicator narration) because the orchestrating session's *explanatory output style* leaked into the `claude -p` adjudicator subprocess. These non-finding blocks were stripped with a finding-count-invariant transform (verified 169→169, every `- [ ]` preserved, files now start at the title). **Process fix for next time: run the stage-audit runbook from a default-output-style session** (the adjudicator's own system prompt already says "no preamble"; the style override defeats it).

## Suggested fix order

**Tier A — P0-bearing files first**
1. `webhook-idempotency` (WHK-1, P0, **S** ~0.5–1h) — MFA lockout bypass; quick, security-critical, bundle-safe.
2. `edge-worker` (EDGE-1 P0 **S** pure-Worker; EDGE-2/EDGE-3 P0 **M**; EDGE-4/5/6 P1) — heaviest cluster. EDGE-1 is a clean quick win. **EDGE-2 & EDGE-3 should run STANDALONE** (Cloudflare purge-API + moderation/CSAM correctness + edge infra — not unattended-safe and legally sensitive).
3. `test-coverage` (TEST-1, P0, **L** ~1–2d) — **STANDALONE** (large test authoring).

**Tier B — P1-dense, mostly bundle-able**
- `observability` (6 P1, mostly ~1-line `report($e)` — high value, low effort).
- `lifecycle-correctness` + `job-queue-correctness` (Instagram `scraping`-queue fix, `ShouldBeUnique`, `afterCommit`).
- `security` (SEC-1 analytics IDOR, **S** form-request change — bundle-safe).
- `privacy-compliance` (GDPR retention — but the retention/pruning items need scheduled jobs + migrations; see "park" list).

**Tier C — P2/P3 bulk:** config-hygiene, data-integrity, database-and-queue-scaling, api-contract, caching, scaling, code-quality, semantic.

### Findings the fix runbook will park anyway (schedule these yourself)

- **DB-touching (raw `supabase/migrations/` SQL):** all of `migration-safety` (MIG-1 CONCURRENTLY, MIG-2 unbatched scrubs), `schema-rls` (SCHEMA-1 RLS), and the privacy/data-integrity retention items that need new columns, retention rules, or pruning migrations.
- **L/XL effort:** TEST-1 (test authoring, ~1–2d).
- **Standalone — do NOT bundle:** EDGE-2, EDGE-3 (edge infra + moderation/CSAM + external Cloudflare API behavior).

---

*Generated by `scripts/audit/audit.sh --codebase --bundle full-sweep` (DeepSeek V4 Pro scan + Claude Sonnet adjudication). Audit files + this summary are UNCOMMITTED — review and commit at your discretion.*
