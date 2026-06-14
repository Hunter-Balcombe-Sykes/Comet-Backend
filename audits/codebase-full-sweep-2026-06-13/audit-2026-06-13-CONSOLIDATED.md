# Full-Sweep Codebase Audit — Consolidated Fix Plan — 2026-06-13

Branch: `development` · HEAD: `c7a016f4` · Sources: 20 lens audits · Raw findings: 169 · After dedup: 165 · Bundles: 27 · Standalone: 8

**Read before fixing.** Every finding keeps its original lens ID (e.g. `EDGE-2`, `SEC-1`) — globally unique across the 20 lenses, so no renumbering. Each finding is a checkbox **inside exactly one bundle or standalone block**; tick the finding when its fix lands and the bundle box when the whole bundle is done. The one-line `→ audit-2026-06-13-<lens>.md` backref points at the source file, which carries the full **Technical / Plain-English / Evidence** for that finding — read it before implementing. Drive the `audit` orchestrator off **this file**, not the whole directory (the 20 per-lens files contain the same findings and would double-count). Four cross-lens duplicates were merged — see `## Deduplication notes`.

## Model selection — read once

Every finding and bundle ends with a `Models: plan=<x> · impl=<y> · review=<z>` line. Spawn each session with the named model.

- **haiku** — trivial mechanical changes: delete a file/line, add a config default, a single-line `report($e)`, drop a log key. No architectural judgment.
- **sonnet** — standard implementation: refactors, new Resource/Service classes, observer changes, queue/config swaps, scheduler entries, most migrations. The default.
- **opus** — load-bearing invariants with asymmetric blast radius: auth gates, RLS policies, transaction boundaries, the single-writer KV contract, GDPR/PII flows, risky schema migrations — where a wrong call propagates silently.

**Plan model** (`plan=`): set only when the work needs design judgment before coding (new schema/RLS shape, transaction redesign, cross-file contract change, every Standalone item). `opus` for irreversible/load-bearing design, `sonnet` for moderate. `plan=—` means skip planning, go straight to implementation.

**Review model** (`review=`): **opus** for anything touching auth, RLS, the KV writer contract, transactions, GDPR/PII, schema migrations, or mail. **sonnet** elsewhere. Never review with haiku.

**Two reviews per fix.** After a fix lands, run BOTH review prompts with the `review` model:
1. *Review (learning)* — a short, plain-language pass for whoever is newer to the code: what the fix was for, what to look at, what "good" looks like, and *why*. It teaches the pattern, it doesn't just gate.
2. *Review (technical)* — the rigorous, skeptical pass: invariants, regressions, edge cases, test evidence.

**Workflow per session:** read the bundle's `Models:` line → (if `plan=` set) run the plan session → run the implementation session with the `impl` model, pasting the bundle block → run both review sessions with the `review` model, pasting the bundle + the changed files.

## Cross-lens high-confidence findings

Themes that surfaced under 2+ lenses (high confidence — converged independently):

- **Silent vendor/stream failures** — exceptions swallowed to `Log::warning`/`return null`, no Nightwatch, jobs report "succeeded": `OBS-1..5`, `OBS-7/9/10/11/13/14`, `LIFE-8/9/10`, `SCALE-8`, `JOB-3/4/10`. Largest single theme. → Bundles B3, B4, B12.
- **InstagramConnectJob is broken end-to-end** — dispatches to a `scraping` queue no Horizon supervisor consumes (`JOB-2`≡`OBS-6`), missing `ShouldBeUnique` double-bills Apify (`LIFE-1`), swallows R2 write + scrape failures (`OBS-7`, `JOB-4`). → Bundle B4.
- **`AccountDeletionService` cancel/adminCancel** — bare `DB::transaction()` off the pgsql contract (`SEM-2`≡`LIFE-7`≡`TXN-4`), email restore outside the transaction (`LIFE-2`), duplicated locking block (`SLOP-1`), silent `purge()` failure (`LIFE-6`). → Bundle B5.
- **GDPR/PII retention declared but unenforced** — export artifacts, handle-audit, moderation evidence, email subscriptions, waitlist all retain PII with no prune job (`PRIV-2/3/4/7/8`, `DINT-1/2/3/6`, `SEM-1`). → Standalone S6.
- **`site.smart_links` under-constrained** — no RLS (`SCHEMA-1`), no unique/CHECK/defaults/trigger (`DINT-4/5`, `SCHEMA-3/4/5`, `DINT-9`). → Standalone S3.
- **Staff controllers bypass `authorizeForUser`** — query-scoped ownership instead of Policy gates, skipping `denyIfPendingDeletion()` (`SEC-8/9/10`, `SEC-13`), plus PII over-exposure in staff lists (`SEC-7`). → Bundle B9.
- **`.env.example` drift** — keys consumed by live code but undocumented or wrong (`CFG-1..12`). → Bundle B10.
- **Observer cache-invalidation fan-out at scale** — per-row full Redis invalidation on bulk ops (`CACHE-1..7`). → Bundle B11.

---

## Suggested bundled fix sessions

### Bundle B1: Edge-cache purge completeness (3 items) — Effort: M
- [ ] **Bundle B1 complete**
- Models: plan=sonnet · impl=sonnet · review=opus
- Findings:
    - [ ] **EDGE-2** · P0 — Cache purge covers only the root URL; deep paths + stale shadows never cleared — `app/Services/Cloudflare/CloudflarePurgeService.php:100` → `audit-2026-06-13-edge-worker.md` — _deferred 2026-06-14 (Josh): sitepages are effectively single-route, so the live impact is minimal. The root + `_swr-shadow/` + API + custom-domain URLs are already purged. Full deep-path coverage needs Cloudflare **prefix-purge** (Enterprise plan) — enumerated `files` purge is capped at 30 URLs and can't cover dynamic routes. REVISIT if real/dynamic sub-routes (e.g. product pages) are added._
    - [x] **EDGE-4** · P1 — `SiteObserver::deleted` invalidates Redis but never purges the Cloudflare edge — `app/Observers/Core/SiteObserver.php:83` → `audit-2026-06-13-edge-worker.md` — _fixed 2026-06-14: `SiteObserver::deleted` now dispatches `CloudflareCachePurgeJob` (handle + active custom domain), mirroring `saved()`; regression test added. (Account-purge cascades via DB FK and bypasses the observer, invalidating the edge separately — this covers the direct-delete path.)_
    - [ ] **EDGE-5** · P1 — Custom-domain cache never purged after site mutations — `app/Services/Cloudflare/CloudflarePurgeService.php:100` → `audit-2026-06-13-edge-worker.md` — _premise stale, skipped: already implemented in commit `ace42baa` — `purgeHandle($handle, $customDomain)` purges the custom-domain URLs incl. shadow, and `SiteObserver::saved` passes the active custom domain._
- Rationale: All three are the same gap — the purge path doesn't cover the full cache keyspace the Worker writes (deep paths, stale shadows, custom-domain host, delete path). One coherent change to `purgeHandle` + the delete observer fixes the family.
- Suggested approach: Switch `purgeHandle` from `{"files":[…]}` exact-URL purge to prefix purge (`{"prefixes":["https://{handle}.{domain}/","…/_swr-shadow/"]}`); add the custom-domain host as a second prefix; dispatch `CloudflareCachePurgeJob` from `SiteObserver::deleted`. Verify zone plan supports prefix purge.
- Dependencies: Independent. Pairs naturally with S2 (moderation purge) since both touch `CloudflarePurgeService`.

**Session prompts:**

*Plan:*
> Read EDGE-2/4/5 in `audit-2026-06-13-edge-worker.md` and the Worker's cache-put paths in `cloudflare-worker/src/index.js`. Confirm whether the Cloudflare zone plan supports prefix purge; if not, plan the enumerated-paths fallback. Produce a short step list covering `purgeHandle`, the custom-domain prefix, and the `SiteObserver::deleted` dispatch, and note the ordering vs S2.

*Implementation:*
> Implement Bundle B1 (EDGE-2, EDGE-4, EDGE-5 — read each in `audit-2026-06-13-edge-worker.md`). Apply each finding's What-to-do: prefix-purge in `CloudflarePurgeService::purgeHandle`, custom-domain prefix coverage, and a `CloudflareCachePurgeJob` dispatch from `SiteObserver::deleted`. Run `composer test`. Summarise the diff.

*Review (learning):*
> Background: our pages are cached at Cloudflare's edge under many URLs (`/`, `/gallery`, a 7-day "stale shadow", and custom domains). When a user edits their page we must clear ALL of those, or visitors keep seeing the old version. Check: 1) Does the purge now cover sub-paths and the `_swr-shadow` space, not just `/`? 2) Does deleting a site now trigger an edge purge (it only cleared Redis before)? 3) Is the custom-domain host purged too? Ask: "after an edit, is there any cached URL we forgot to clear?" — that's the whole point.

*Review (technical):*
> Skeptical review of B1. 1) Prefix-purge payload covers root, sub-paths, `_swr-shadow`, and custom-domain host; no path left on exact-URL purge. 2) `SiteObserver::deleted` dispatches `CloudflareCachePurgeJob` with the correct handle (use `withTrashed` if the row is soft-deleted). 3) No double-purge storms on bulk ops. 4) `composer test` green. Confirm against a manual purge-then-fetch if possible.

### Bundle B2: Cloudflare Worker response hardening (7 items) — Effort: M
- [ ] **Bundle B2 complete**
- Models: plan=sonnet · impl=sonnet · review=opus
- Findings:
    - [ ] **EDGE-1** · P0 — `Set-Cookie` from the Astro origin is edge-cached and replayed to every visitor — `cloudflare-worker/src/index.js:79` → `audit-2026-06-13-edge-worker.md`
    - [ ] **EDGE-7** · P2 — Security headers absent on preview / non-GET / 503 / pass-through paths — `cloudflare-worker/src/index.js:207` → `audit-2026-06-13-edge-worker.md`
    - [ ] **EDGE-8** · P2 — No `Content-Security-Policy` header — `cloudflare-worker/src/index.js:118` → `audit-2026-06-13-edge-worker.md`
    - [ ] **EDGE-9** · P2 — Query-string variants mint unlimited cache entries; no normalisation/allowlist — `cloudflare-worker/src/index.js:221` → `audit-2026-06-13-edge-worker.md`
    - [ ] **SEC-5** · P2 — 301 alias redirect uses the KV value with no URL validation (open-redirect if KV poisoned) — `cloudflare-worker/src/index.js:306` → `audit-2026-06-13-security.md`
    - [ ] **EDGE-12** · P3 — Non-OK origin responses pass through with original `Cache-Control` (error-page caching) — `cloudflare-worker/src/index.js:148` → `audit-2026-06-13-edge-worker.md`
    - [ ] **EDGE-13** · P3 — The two `ctx.waitUntil(cache.put(...))` calls fail silently — `cloudflare-worker/src/index.js:154` → `audit-2026-06-13-edge-worker.md`
- Rationale: All seven live in `cloudflare-worker/src/index.js` and concern what the Worker stores/returns. One Worker PR with a re-test against a deployed preview is far safer than seven drip changes to edge code.
- Suggested approach: Strip `Set-Cookie` before every `cache.put`; centralise `applySecurityHeaders` (+ CSP) and apply it to ALL return paths; normalise/allowlist the query string before cache key; validate the KV `redirect` value is a same-site absolute URL; set `no-store` on non-OK origin responses; log on `waitUntil` cache-put rejection. Deploy to a preview and re-test.
- Dependencies: Independent. Coordinate the deploy with B1 (same file family) to avoid two Worker rollouts.

**Session prompts:**

*Plan:*
> Read EDGE-1/7/8/9/12/13 and SEC-5 in their lens files and `cloudflare-worker/src/index.js`. Map every response return path in the Worker and decide where the shared header/cookie-strip/cache-key logic should sit so no path is missed. Produce a step list + a preview-deploy test checklist. Flag CSP as the one item needing a real-content test (it can break the Astro page).

*Implementation:*
> Implement Bundle B2 (EDGE-1/7/8/9/12/13, SEC-5 — read each lens file). Centralise security-header + `Set-Cookie`-strip logic and apply it to every Worker return path; add CSP; normalise the cache-key query string; validate the alias redirect target; `no-store` non-OK responses; log `waitUntil` put failures. Deploy to a preview and confirm a real page still renders. Summarise the diff.

*Review (learning):*
> Background: the Worker is the front door — it stores a copy of each page and serves it to the next visitor. Two risks: it might store something private (a cookie) and hand it to everyone, or fail to add safety headers. Check: 1) Is `Set-Cookie` removed before EVERY `cache.put`? One missed path = leak. 2) Do security headers + CSP appear on all responses, including previews and errors? 3) Does the page still load with CSP on (CSP often blocks scripts/styles — test a real page)? Ask: "could one visitor's data reach another visitor through the cache?"

*Review (technical):*
> Skeptical review of B2. 1) `Set-Cookie` stripped on primary + stale-shadow puts; no path caches it. 2) `applySecurityHeaders` (incl. CSP) hits preview, non-GET, 503, and all pass-through returns. 3) Cache key normalised/allowlisted — no unbounded variant explosion; legitimate query params preserved. 4) Alias redirect validated as same-site absolute. 5) CSP verified against a live Astro render (no console blocks). 6) `wrangler` deploy/preview clean.

### Bundle B3: Vendor/stream exception-reporting sweep (8 items) — Effort: S
- [x] **Bundle B3 complete**
- Models: plan=— · impl=haiku · review=sonnet
- Findings:
    - [x] **OBS-1** · P1 — `RefreshSmartLinksCommand` swallows per-link exceptions, no alert — `app/Console/Commands/RefreshSmartLinksCommand.php:51` → `audit-2026-06-13-observability.md`
    - [x] **OBS-3** · P1 — `TwitchApiClient::getLiveHandles` swallows transport exceptions — `app/Services/Streaming/TwitchApiClient.php:71` → `audit-2026-06-13-observability.md`
    - [x] **OBS-4** · P1 — `KickApiClient::getLiveHandles` swallows non-rate-limit exceptions — `app/Services/Streaming/KickApiClient.php:98` → `audit-2026-06-13-observability.md`
    - [x] **OBS-5** · P1 — `StreamingTokenManager::refreshToken` swallows auth-credential failures — `app/Services/Streaming/StreamingTokenManager.php:81` → `audit-2026-06-13-observability.md`
    - [x] **LIFE-8** · P3 — `SectionVisibilityService::reevaluateEnabled` swallows `\Throwable` — `app/Services/User/SectionVisibilityService.php:392` → `audit-2026-06-13-lifecycle-correctness.md`
    - [x] **LIFE-9** · P3 — `GoogleBusinessService::streetViewPano` catches `\Throwable` with no log — `app/Services/Platforms/GoogleBusinessService.php:355` → `audit-2026-06-13-lifecycle-correctness.md`
    - [x] **LIFE-10** · P3 — `GoogleBusinessService::resolvePhotoUrls` logs without `placeId` — `app/Services/Platforms/GoogleBusinessService.php:322` → `audit-2026-06-13-lifecycle-correctness.md`
    - [x] **OBS-14** · P3 — `Log::critical` severity inflation on recoverable streaming auth paths — `app/Services/Streaming/KickApiClient.php:45` → `audit-2026-06-13-observability.md`
- Rationale: Identical mechanical pattern — a swallowed/under-reported exception in a service method. Adding `report($e)` (and fixing one log severity / one missing context key) across eight call sites is one focused sweep with no interaction effects.
- Suggested approach: For each cited catch, add `report($e)` as the first statement before the existing log/return. OBS-14: downgrade `Log::critical`→`Log::warning`. LIFE-10: add `placeId` to the log context. Run `composer test`.
- Dependencies: None.

**Session prompts:**

*Plan:* Not needed — go straight to implementation.

*Implementation:*
> Implement Bundle B3 (OBS-1/3/4/5/14, LIFE-8/9/10 — read each in its lens file). Add `report($e)` as the first line of each cited `catch` (keep existing log + return). Downgrade the OBS-14 `Log::critical` calls to `warning`. Add `placeId` to the LIFE-10 log context. Touch only these files. Run `composer test`. Summarise per finding.

*Review (learning):*
> Background: when code catches an error and only does `Log::warning`, nothing alerts us — Nightwatch only fires on `report($e)` / unhandled exceptions (see the project's Nightwatch notes). We're making silent failures page-able. Check: 1) Every changed `catch` now calls `report($e)` first? 2) Existing behaviour (log line, return value) unchanged — we ADD an alert, not change flow? 3) OBS-14: is `critical` now `warning` (critical should mean "wake someone up", not a routine token refresh)? Ask: "if this breaks at 2am, do we find out?"

*Review (technical):*
> Skeptical review of B3. 1) `report($e)` first statement in every cited catch; no double-report up the stack. 2) Caller contracts unchanged. 3) No `report()` added to normal control-flow catches. 4) OBS-14 severity corrected without losing the message. 5) `composer test` green.

### Bundle B4: InstagramConnectJob — fix the broken connect pipeline (4 items) — Effort: M
- [ ] **Bundle B4 complete**
- Models: plan=sonnet · impl=sonnet · review=sonnet
- Findings:
    - [ ] **JOB-2** (≡ **OBS-6**) · P1 — dispatches to a `scraping` queue no Horizon supervisor consumes; auto-connect silently broken in all envs — `app/Jobs/Platforms/InstagramConnectJob.php:64`, `config/horizon.php` → `audit-2026-06-13-job-queue-correctness.md`
    - [ ] **LIFE-1** · P1 — missing `ShouldBeUnique` → double-billed Apify scrapes on retry — `app/Jobs/Platforms/InstagramConnectJob.php:31` → `audit-2026-06-13-lifecycle-correctness.md`
    - [ ] **JOB-4** · P2 — `markFailed()` + return without `$this->fail()`; Horizon marks it "succeeded" on empty scrape — `app/Jobs/Platforms/InstagramConnectJob.php:76` → `audit-2026-06-13-job-queue-correctness.md`
    - [ ] **OBS-7** · P2 — per-image R2 write failures dropped with empty catch blocks — `app/Jobs/Platforms/InstagramConnectJob.php:178` → `audit-2026-06-13-observability.md`
- Rationale: One job class is broken four ways — it never runs (no supervisor), would double-bill if it did (no uniqueness), reports false success, and hides partial failures. Fix as one PR so the whole connect path is correct and observable.
- Suggested approach: Add a `scraping` supervisor in `config/horizon.php` (all envs) OR repoint the job to an existing consumed queue per JOB-2's What-to-do; add `ShouldBeUnique` + `uniqueId()`; replace the silent `markFailed`+return with `$this->fail(...)` on hard failures; add `report($e)` in the mirror catches. Run `composer test`.
- Dependencies: Touches `config/horizon.php` (coordinate with any queue-config bundle). Restart Horizon after deploy.

**Session prompts:**

*Plan:*
> Read JOB-2(≡OBS-6), LIFE-1, JOB-4, OBS-7 in their lens files plus `InstagramConnectJob.php` and `config/horizon.php`. Decide: add a `scraping` supervisor vs repoint to an existing queue (check what other scraping jobs use — DeleteMirroredMediaJob references `scraping` too). Plan the `ShouldBeUnique` key and the fail-vs-markFailed semantics. Produce a step list; note the Horizon restart.

*Implementation:*
> Implement Bundle B4 (JOB-2/OBS-6, LIFE-1, JOB-4, OBS-7). Make the queue actually consumed (supervisor or repoint per the plan); add `ShouldBeUnique`; convert silent failure to `$this->fail()`; `report($e)` the mirror failures. Run `composer test`. Summarise the diff and state which queue the job now lands on.

*Review (learning):*
> Background: this job auto-connects a user's Instagram by paying Apify to scrape it. Today it's queued to `scraping`, which NO worker is watching — so it never runs (and if it did, retries would pay Apify twice, and failures would look like success). Check: 1) Is the job's queue now actually consumed by a Horizon supervisor? 2) Does `ShouldBeUnique` stop a retry from launching a second paid scrape? 3) On an empty/failed scrape, does the job now FAIL (not silently succeed)? Ask: "if Apify is down, does Horizon show red and does it stop spending money?"

*Review (technical):*
> Skeptical review of B4. 1) The job's queue name matches a supervisor in `defaults` + all env blocks of `config/horizon.php` (or is repointed to a consumed queue). 2) `ShouldBeUnique` + `uniqueId()` keyed so concurrent/retry dispatches dedupe; lock released appropriately. 3) Hard-failure path calls `$this->fail()` and engages backoff; doesn't burn Apify on a dead credential. 4) Mirror catches `report($e)`. 5) `composer test` green; a test asserts the queue assignment.

### Bundle B5: AccountDeletionService correctness (5 items) — Effort: M
- [x] **Bundle B5 complete**
- Models: plan=sonnet · impl=sonnet · review=opus
- Findings:
    - [x] **LIFE-2** · P1 — cancel/adminCancel restore primary email OUTSIDE the transaction (torn state on rollback) — `app/Services/User/AccountDeletionService.php:428,355` → `audit-2026-06-13-lifecycle-correctness.md`
    - [x] **LIFE-6** · P2 — `purge()` returns false on every failure with only `Log::error`; users stuck in `pending_deletion`, no alert — `app/Services/User/AccountDeletionService.php:488` → `audit-2026-06-13-lifecycle-correctness.md`
    - [x] **SLOP-1** · P2 — cancel/adminCancel duplicate the same DB-locking site-restore block — `app/Services/User/AccountDeletionService.php:357,430` → `audit-2026-06-13-code-quality-slop.md`
    - [x] **SEM-2** (≡ **LIFE-7**, **TXN-4**) · P2 — cancel/adminCancel use bare `DB::transaction()` off the pgsql contract — `app/Services/User/AccountDeletionService.php:357,430` → `audit-2026-06-13-semantic-correctness.md`
    - [x] **TXN-2** · P2 — cache invalidation inside the bootstrap transaction → stale-cache window — `app/Services/User/UserBootstrapService.php:101` → `audit-2026-06-13-transaction-boundaries.md`
- Rationale: Four of the five are the same two methods (`cancel`/`adminCancel`); fixing transaction boundaries, the bare connection, the duplicated block, and the email-restore-in-tx together avoids re-touching the same code five times. LIFE-6 (`purge`) and TXN-2 (bootstrap) ride along as the same file's transaction-hygiene theme.
- Suggested approach: Extract the shared site-restore block to one private method (SLOP-1); pin to `DB::connection('pgsql')->transaction()` (SEM-2/LIFE-7/TXN-4); move the email restore INSIDE the transaction (LIFE-2); add `report()` + a typed exception on `purge()` failure (LIFE-6); move the cache bust to `afterCommit` in bootstrap (TXN-2). Run `composer test`.
- Dependencies: None, but it's GDPR-adjacent (account deletion) → review with opus.

**Session prompts:**

*Plan:*
> Read LIFE-2/6, SLOP-1, SEM-2(≡LIFE-7/TXN-4), TXN-2 and `AccountDeletionService.php` + `UserBootstrapService.php`. Plan the single shared site-restore helper, the transaction-boundary changes (what must be inside the tx, which connection), and the failure-reporting for `purge()`. Call out any ordering risk between the email restore and the site restore. Produce a step list.

*Implementation:*
> Implement Bundle B5. Extract the duplicated site-restore block to one pgsql-pinned transactional helper used by both `cancel` and `adminCancel`; move the email restore inside that transaction; make `purge()` `report()` + throw on failure instead of returning false silently; move the bootstrap cache bust to `afterCommit`. Run `composer test`. Summarise.

*Review (learning):*
> Background: a database "transaction" means all-or-nothing. If we restore a user's email OUTSIDE the transaction but the transaction rolls back, the email change sticks while everything else reverts — "torn" state. Also, this app pins transactions to the `pgsql` connection (the test DB is SQLite — bare `DB::transaction()` behaves differently). Check: 1) Is the email restore now INSIDE the transaction? 2) Is the transaction on the `pgsql` connection? 3) When account purge fails, do we now get a Nightwatch alert instead of a user silently stuck? Ask: "if this rolls back halfway, is the account left consistent?"

*Review (technical):*
> Skeptical review of B5. 1) Both methods use `DB::connection('pgsql')->transaction()`; email + site restore are atomic within it. 2) Shared helper is genuinely shared (no residual copy). 3) `purge()` failure path `report()`s and surfaces; no silent `pending_deletion` trap. 4) Bootstrap cache invalidation is `afterCommit`. 5) `composer test` green incl. the SQLite suite (the pgsql pin must not break tests). GDPR flow — verify no data is left undeleted on the failure path.

### Bundle B6: GDPR export dispatch + missing-row visibility (2 items) — Effort: S
- [x] **Bundle B6 complete**
- Models: plan=— · impl=sonnet · review=opus
- Findings:
    - [x] **LIFE-3** (≡ **TXN-1**) · P1 — `ExportUserDataJob` dispatched inside a transaction without `$afterCommit=true`; GDPR exports silently lost on fast worker pickup — `app/Services/User/DataExport/DataExportService.php:59`, `app/Jobs/Gdpr/ExportUserDataJob.php:31` → `audit-2026-06-13-lifecycle-correctness.md`
    - [x] **JOB-3** · P2 — `ExportUserDataJob` silently succeeds when the audit row is missing; lost GDPR request invisible to ops — `app/Jobs/Gdpr/ExportUserDataJob.php:45` → `audit-2026-06-13-job-queue-correctness.md`
- Rationale: Both are the GDPR export job's reliability — one loses the job to a transaction race, the other hides a lost job. Same file, same session.
- Suggested approach: Add `public $afterCommit = true;` to `ExportUserDataJob` (or `->afterCommit()` on dispatch); on missing audit row, `report()` a domain exception instead of returning. Run `composer test`.
- Dependencies: None.

**Session prompts:**

*Plan:* Not needed — go straight to implementation.

*Implementation:*
> Implement Bundle B6 (LIFE-3/TXN-1, JOB-3). Add `afterCommit` to `ExportUserDataJob` so it isn't dispatched before its transaction commits; make the missing-audit-row path `report()` instead of silently returning. Run `composer test`. Summarise.

*Review (learning):*
> Background: if you `dispatch()` a job inside a DB transaction, a fast worker can pick it up BEFORE the transaction commits — so the job looks for data that isn't there yet and quietly does nothing. `afterCommit` delays pickup until commit. This is a GDPR export — losing it means a legal request silently fails. Check: 1) Does the job now wait for commit? 2) If the expected row is missing, do we alert instead of pretend-success? Ask: "could a user's data-export request vanish with no trace?"

*Review (technical):*
> Skeptical review of B6. 1) `afterCommit` set on the job (constructor property) or dispatch; confirm the dispatch site is inside a transaction. 2) Missing-row path `report()`s a typed exception; no silent return. 3) `composer test` green; ideally a test simulating the missing-row path. GDPR — confirm a real export still completes end-to-end.

### Bundle B7: Public-endpoint tenant isolation + bot/PII hardening (4 items) — Effort: S
- [ ] **Bundle B7 complete**
- Models: plan=sonnet · impl=sonnet · review=opus
- Findings:
    - [ ] **SEC-1** · P1 — Analytics ingest IDOR: `site_id`-only requests bypass the subdomain cross-check (cross-tenant injection) — `app/Http/Controllers/Concerns/ResolvesSiteFromRequest.php:22`, analytics Form Requests → `audit-2026-06-13-security.md` — _deferred 2026-06-14 (Josh): the prescribed fix (make `subdomain` required) breaks the **documented** analytics contract — `docs/api.md:452,700` intentionally accept "either `site_id` OR `X-Site-Subdomain`", which the real beacon SDK uses, and broke 26 analytics tests. The existing both-present cross-check already blocks the spoofed-subdomain case; the residual is unauthenticated public-analytics write-poisoning by site UUID (count integrity, not data leak). Full closure is an API-contract design call (accept the risk / beacon-signing or Origin-binding / versioned contract requiring subdomain + SDK change) — NOT a code tweak._
    - [x] **SEC-6** · P2 — Waitlist + email-subscribe forms lack the honeypot/timing bot fields other public forms have — `app/Http/Requests/Api/PublicSite/PublicEmailSubscribeRequest.php:26` → `audit-2026-06-13-security.md` — _done: `website` honeypot + `form_started_at_ms` timing added to both requests + controller enforcement, mirroring PublicCustomerLead. Timing field is **nullable** (non-breaking) — tighten to required once the frontend sends it._
    - [x] **SEC-3** · P2 — `MetadataParser` loads third-party HTML without `LIBXML_NONET` (parser SSRF) — `app/Services/SmartLinks/MetadataParser.php:17` → `audit-2026-06-13-security.md`
    - [x] **SEC-11** · P2 — Bot-protection `mode=off` in prod only logs a warning, silently disabling protection if `.env.example` is copied verbatim — `app/Providers/BotProtectionServiceProvider.php:62` → `audit-2026-06-13-security.md`
- Rationale: Four public-attack-surface hardenings — close the analytics cross-tenant IDOR, add bot fields to the two unprotected public forms, stop the metadata parser from making network calls, and fail-closed bot protection in prod. Grouped because they're all "lock the front door" and all small.
- Suggested approach: Make `subdomain` unconditionally required (or never resolve by `site_id` alone) in `ResolvesSiteFromRequest` + the four analytics requests (SEC-1); add honeypot/timing to the two public requests (SEC-6); add `LIBXML_NONET` to the parser (SEC-3); make prod boot refuse when bot-protection `mode=off` (SEC-11). Run `composer test`.
- Dependencies: None.

**Session prompts:**

*Plan:*
> Read SEC-1/3/6/11 in `audit-2026-06-13-security.md`. SEC-1 is the load-bearing one (cross-tenant data injection) — decide between "require subdomain always" vs "never resolve by UUID alone" and check the four analytics Form Requests + the beacon SDK contract. Plan SEC-11's fail-closed boot carefully (don't brick prod boot on a legitimate config). Produce a step list.

*Implementation:*
> Implement Bundle B7 (SEC-1/3/6/11). Close the analytics IDOR in `ResolvesSiteFromRequest` + the four analytics requests; add honeypot/timing fields to the waitlist + email-subscribe requests; add `LIBXML_NONET` to `MetadataParser`; make `BotProtectionServiceProvider` refuse boot (not just warn) when `mode=off` in production. Run `composer test`. Summarise.

*Review (learning):*
> Background: SEC-1 is the important one — our analytics endpoint lets a caller say "record this event for site X". It checks tenancy only when BOTH a `site_id` and a `subdomain` are sent; send just a `site_id` and the check is skipped, so anyone can poison anyone's analytics. Check: 1) Is a `site_id`-only request now rejected (or always cross-checked against subdomain)? 2) Do the two public forms now have the same bot fields as the others? 3) In prod, does bot-protection `mode=off` now stop the boot instead of quietly disabling itself? Ask of SEC-1: "can I write to a site I don't own?"

*Review (technical):*
> Skeptical review of B7. 1) SEC-1: a `site_id`-only payload no longer resolves to any tenant's site; all four analytics requests enforce it; the route/header subdomain merge still works for legit beacons. 2) SEC-6: honeypot + timing present and validated. 3) SEC-3: `LIBXML_NONET` set; parser can't make network calls. 4) SEC-11: prod boot fails closed on `mode=off`; non-prod still permissive. 5) `composer test` green; a test asserts the IDOR is closed. Review with opus (tenant isolation).

### Bundle B8: Webhook & MFA idempotency hardening (3 items) — Effort: S
- [x] **Bundle B8 complete**
- Models: plan=sonnet · impl=sonnet · review=opus
- Findings:
    - [x] **WHK-1** · P0 — Auth-hook dedup anchor not reverted on `record()` failure; a brute-force reject silently flips to "allow" on Supabase's retry — `app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php:73,92,108` → `audit-2026-06-13-webhook-idempotency.md`
    - [x] **WHK-2** · P2 — Auth-hook dedup conditional on a non-empty `webhook-id`; not hardened against a missing header — `app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php:44` → `audit-2026-06-13-webhook-idempotency.md`
    - [x] **WHK-3** · P2 — Email-hook has the same conditional-dedup latent gap — `app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php:72` → `audit-2026-06-13-webhook-idempotency.md`
- Rationale: All three are the Supabase hook idempotency contract. WHK-1 is a security bypass (MFA lockout → allow); WHK-2/3 are the latent missing-header variant of the same dedup logic. Fixing together brings both hooks to parity.
- Suggested approach: Wrap the three `record()` calls in `try/catch`, `Cache::forget` the anchor + return 500 on failure (mirror the email hook's existing pattern); treat a missing `webhook-id` as fail-closed (reject) rather than skipping dedup. Add a test that mocks `record()` throwing and asserts the anchor is cleared.
- Dependencies: None. Security-critical — sequence early.

**Session prompts:**

*Plan:*
> Read WHK-1/2/3 and both hook controllers. WHK-1 is the security one (a thrown `record()` leaves the dedup anchor set, so Supabase's retry short-circuits to "continue" = allow). Decide the fail-closed behaviour for a missing `webhook-id`. Produce a step list + the test to prove the anchor is reverted on failure.

*Implementation:*
> Implement Bundle B8. In `SupabaseAuthHookController::mfaVerification`, wrap each `record()` in try/catch that `Cache::forget`s the dedup key and returns 500 (parity with `SupabaseEmailHookController`); make a missing `webhook-id` fail closed. Add a test mocking `record()` to throw and asserting `Cache::has(...) === false` + 500. Run `composer test`. Summarise.

*Review (learning):*
> Background: to avoid processing the same webhook twice, we set a "seen" marker before doing the work. Bug: if the work throws, the marker stays — so Supabase's automatic retry sees "already handled" and returns the default "allow", turning a brute-force REJECT into an allow. Check: 1) On a `record()` failure, is the marker now removed so the retry re-evaluates? 2) Does a missing `webhook-id` now reject rather than silently skip the check? Ask: "can a failure during a rejection turn it into an approval?"

*Review (technical):*
> Skeptical review of B8 (opus — MFA/auth). 1) Every `record()` is in try/catch that forgets the anchor + returns 500 before the retry can short-circuit. 2) Reject path can't degrade to continue on retry. 3) Missing-header path fails closed. 4) Email + auth hooks are at parity. 5) Test proves anchor reversion. `composer test` green.

### Bundle B9: Staff-controller authorization via Policies (6 items) — Effort: M
- [x] **Bundle B9 complete**
- Models: plan=sonnet · impl=sonnet · review=opus
- Findings:
    - [x] **SEC-7** · P2 — `StaffUserController::index` returns `primary_email`+`phone` for every professional to all staff roles — `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php:62` → `audit-2026-06-13-security.md`
    - [x] **SEC-8** · P2 — `StaffSectionManagementController` query-scoped ownership, no Policy, bypasses `denyIfPendingDeletion()` — `…/StaffSectionManagementController.php:23` → `audit-2026-06-13-security.md`
    - [x] **SEC-9** · P2 — `StaffLinkBlockManagementController` uses inline `abort_unless` instead of `authorizeForUser` — `…/StaffLinkBlockManagementController.php:70` → `audit-2026-06-13-security.md`
    - [x] **SEC-10** · P2 — `SiteVisibilityController` resolves via query scope, bypassing `denyIfPendingDeletion()` — `app/Http/Controllers/Api/PublicSite/SiteVisibilityController.php:25` → `audit-2026-06-13-security.md`
    - [x] **SEC-12** · P3 — Inline `$request->validate()` in staff mutations instead of Form Requests — `…/StaffNotificationController.php:25`, `…/StaffUserController.php:125` → `audit-2026-06-13-security.md`
    - [x] **SEC-13** · P3 — `StaffFeatureFlagController` redundant `abort_if(...===null,401)` already guaranteed by middleware — `…/StaffFeatureFlagController.php:26` → `audit-2026-06-13-security.md`
- Rationale: The staff surface repeatedly bypasses the project's Policy/Form-Request mandate (per CLAUDE.md), which also skips `denyIfPendingDeletion()`. One pass through the staff controllers brings them to the standard and removes the PII over-exposure.
- Suggested approach: Route each mutation through `authorizeForUser($user, 'manage', $resource)` (register policies as needed per CLAUDE.md); gate `StaffUserController::index` PII behind role; extract inline validation to Form Requests (SEC-12); drop redundant middleware re-checks (SEC-13).
- Dependencies: May need new Policy registrations in `AppServiceProvider::boot()`; `PolicyCoverageTest` will enforce. Review with opus (authz).

**Session prompts:**

*Plan:*
> Read SEC-7/8/9/10/12/13 and the cited staff controllers. For each, identify the resource + the right Policy ability (some policies may need creating + registering — see CLAUDE.md authorization rules). Decide the role gate for PII in `StaffUserController::index`. Produce a step list and the list of Policy registrations to add.

*Implementation:*
> Implement Bundle B9. Replace query-scoped/inline ownership with `authorizeForUser(...)` (creating + registering Policies where needed); ensure `denyIfPendingDeletion()` runs; gate staff-list PII by role; move inline `validate()` to Form Requests; drop the redundant `abort_if` re-checks. Run `composer test` (incl. `PolicyCoverageTest`). Summarise.

*Review (learning):*
> Background: this app does authorization through Policies (`authorizeForUser`), never inline `abort_unless` (CLAUDE.md). Query-scoped "where user_id = ..." looks safe but skips `denyIfPendingDeletion()` and the Policy gate. Check: 1) Does each endpoint now call `authorizeForUser`? 2) Are pending-deletion accounts blocked? 3) Is the staff list no longer handing every professional's email/phone to read-only staff? Ask: "is authorization a Policy decision here, or hand-rolled?"

*Review (technical):*
> Skeptical review of B9 (opus — authz). 1) Every cited endpoint uses `authorizeForUser` against a registered Policy; `PolicyCoverageTest` green. 2) `denyIfPendingDeletion()` enforced on all four. 3) PII in `StaffUserController::index` role-gated; 404-not-403 semantics preserved. 4) Form Requests replace inline validation. 5) `composer test` green.

### Bundle B10: Observer cache-invalidation fan-out [@10k] (7 items) — Effort: M
- [ ] **Bundle B10 complete** — _deferred 2026-06-14: the suggested fire-on-first Redis dirty-flag debounce introduces a stale-cache correctness regression — `SiteObserver::saved` dispatches `WarmPublicSiteCacheJob` (ShouldBeUnique 120s) which repopulates the cache, so suppressing later writes in a burst leaves write-1 data cached until the flag TTL. A correct trailing-coalesce (end-of-request flush) is L-effort hot-path work, out of scope for this [@10k] post-launch M-bundle. No customers pre-beta; not a correctness bug today._
- Models: plan=sonnet · impl=sonnet · review=sonnet
- Findings:
    - [ ] **CACHE-1** · P2 — `PostgresEventWriter::writeMany()` breaks batch contract for session pings — `app/Services/Analytics/Writers/PostgresEventWriter.php:66` → `audit-2026-06-13-scaling-antipatterns.md`
    - [ ] **CACHE-2** · P2 — `SiteMediaObserver` cascades full Redis invalidation per image on bulk uploads — `app/Observers/Core/SiteMediaObserver.php:53` → `audit-2026-06-13-scaling-antipatterns.md`
    - [ ] **CACHE-3** · P2 — `BlockObserver` full invalidation per block on bulk reorders — `app/Observers/Core/BlockObserver.php:44` → `audit-2026-06-13-scaling-antipatterns.md`
    - [ ] **CACHE-4** · P2 — `ServiceObserver` user+site invalidation per service on batch mutations — `app/Observers/Core/ServiceObserver.php:68` → `audit-2026-06-13-scaling-antipatterns.md`
    - [ ] **CACHE-5** · P2 — `ServiceCategoryObserver` DB SELECT + multi-key sweep per category on reorder — `app/Observers/Core/ServiceCategoryObserver.php:40` → `audit-2026-06-13-scaling-antipatterns.md`
    - [ ] **CACHE-7** · P2 — `CustomerObserver` two Redis DELs per row on bulk imports — `app/Observers/Core/CustomerObserver.php:34` → `audit-2026-06-13-scaling-antipatterns.md`
    - [ ] **CACHE-6** · P3 — `SmartLinkObserver` unchecked per-link full invalidation — `app/Observers/Core/SmartLinkObserver.php:37` → `audit-2026-06-13-caching-gold-standard.md`
- Rationale: Same antipattern across seven observers — a bulk operation (N rows) triggers N full cache invalidations instead of one coalesced bust. Harmless at 100 users, a Redis-storm at 10k. One pass establishing a debounce/coalesce helper fixes the family.
- Suggested approach: Introduce a per-request coalescing bust (collect dirty site/user IDs, flush once at the end of the request/batch) and route all seven observers through it; or guard each observer to skip redundant busts within a batch. Benchmark a 100-row bulk op before/after.
- Dependencies: None. `[@10k]` — a scale fix, not a correctness bug; safe to schedule after launch-blockers.

**Session prompts:**

*Plan:*
> Read CACHE-1..7 and the seven observers. Design ONE coalescing-invalidation mechanism (collect-then-flush per request, or a short-window debounce) that all observers use, rather than seven ad-hoc guards. Confirm it doesn't weaken correctness (a bust must still happen once). Produce a step list + a before/after benchmark plan for a bulk reorder.

*Implementation:*
> Implement Bundle B10. Add the coalescing-invalidation helper and route CACHE-1..7's observers through it so a bulk op busts each affected key once, not per row. Keep single-row behaviour identical. Run `composer test` + a bulk-op benchmark. Summarise the Redis-op reduction.

*Review (learning):*
> Background: when you upload 6 images, an "observer" fires once per image and each one wipes the whole site's cache — 6× the work, and at 10k users a reorder of many items can hammer Redis. We want one cache-clear per bulk operation. Check: 1) Does a bulk op now bust each key once (not per row)? 2) Is single-item behaviour unchanged? 3) Is the cache still actually cleared (we want fewer busts, not zero)? Ask: "if I change 50 things at once, how many Redis calls fire — 50 or 1?"

*Review (technical):*
> Skeptical review of B10. 1) Coalescing flushes once per request/batch; no missed invalidation (stale cache) on any path. 2) Single-row writes still bust correctly. 3) No cross-request leakage of the dirty set. 4) Benchmark shows the op-count drop. 5) `composer test` green.

### Bundle B11: Notification-job idempotency (6 items) — Effort: S
- [x] **Bundle B11 complete**
- Models: plan=— · impl=sonnet · review=sonnet
- Findings:
    - [x] **JOB-1** · P1 — `DispatchEnquiryNotificationsJob` has no idempotency guard; retries duplicate notifications — `app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php:33` → `audit-2026-06-13-job-queue-correctness.md`
    - [x] **JOB-5** · P2 — `NotifyOnCallStaffJob` missing `ShouldBeUnique`/completion guard; duplicate on-call alerts — `app/Jobs/Moderation/NotifyOnCallStaffJob.php:20` → `audit-2026-06-13-job-queue-correctness.md`
    - [x] **JOB-6** · P2 — `NotifyReportedUserJob` missing guard; duplicate "content hidden"/"suspended" notices — `app/Jobs/Moderation/NotifyReportedUserJob.php:21` → `audit-2026-06-13-job-queue-correctness.md`
    - [x] **JOB-7** · P2 — `NotifyReporterJob` missing guard; duplicate "we reviewed your report" emails — `app/Jobs/Moderation/NotifyReporterJob.php:19` → `audit-2026-06-13-job-queue-correctness.md`
    - [x] **JOB-8** · P2 — `NotifyStaffOfCaseUpdateJob` missing `ShouldBeUnique`; duplicate staff threshold alerts — `app/Jobs/Moderation/NotifyStaffOfCaseUpdateJob.php:26` → `audit-2026-06-13-job-queue-correctness.md`
    - [x] **JOB-9** · P2 — `CheckStreamingLiveStatusJob` missing `WithoutOverlapping`; concurrent live-status writes — `app/Jobs/Streaming/CheckStreamingLiveStatusJob.php:17` → `audit-2026-06-13-job-queue-correctness.md`
- Rationale: Same class of bug across six jobs — a retry or concurrent dispatch produces duplicate user-facing notifications (or overlapping writes). All fixed with `ShouldBeUnique`/`WithoutOverlapping` + a completion guard; one focused session.
- Suggested approach: Add `ShouldBeUnique` + `uniqueId()` (keyed on the case/enquiry/recipient) and a "bail if already sent" completion check to the five notify jobs; add `WithoutOverlapping` to the streaming poller. Mind the `HasActionLogLifecycle` trait — it's an audit trail, not an idempotency guard.
- Dependencies: None.

**Session prompts:**

*Plan:* Not needed — go straight to implementation (per-job `uniqueId` keys are obvious from each finding).

*Implementation:*
> Implement Bundle B11 (JOB-1/5/6/7/8/9). Add `ShouldBeUnique` + `uniqueId()` + a completion guard to the five notify jobs (key on case/enquiry/recipient); add `WithoutOverlapping` to `CheckStreamingLiveStatusJob`. Don't rely on `HasActionLogLifecycle` for idempotency. Run `composer test`. Summarise per job.

*Review (learning):*
> Background: queued jobs retry on failure and can be dispatched twice. Without a uniqueness guard, a retry re-sends the same email/alert. `ShouldBeUnique` makes Laravel skip a duplicate while one is in flight; a completion check skips one that already finished. Check: 1) Does each notify job have `ShouldBeUnique` keyed to its target? 2) Is there a guard so a completed job doesn't re-send on a later retry? 3) Does the poller use `WithoutOverlapping`? Ask: "if this job runs twice, does the user get two emails?"

*Review (technical):*
> Skeptical review of B11. 1) `uniqueId()` keys are specific enough to dedupe but not so broad they suppress legitimate distinct sends. 2) Completion guard handles the retry-after-success case (not just in-flight). 3) `WithoutOverlapping` on the poller with a sane expiry. 4) No reliance on the audit-trail trait for idempotency. 5) `composer test` green.

### Bundle B12: Silent-success job/service observability (7 items) — Effort: S
- [x] **Bundle B12 complete**
- Models: plan=— · impl=sonnet · review=sonnet
- Findings:
    - [x] **OBS-2** · P1 — `InstagramScraper::fetchProfile` swallows transport exceptions; paid Apify failures silent, job "succeeds" — `app/Services/Platforms/InstagramScraper.php:43` → `audit-2026-06-13-observability.md`
    - [x] **OBS-8** · P2 — All 18 `onFailure()` scheduler callbacks use `Log::error` only; crashes invisible to Nightwatch — `routes/console.php:36` → `audit-2026-06-13-observability.md`
    - [x] **OBS-9** · P2 — `MediaUploadService::dispatchImageJob` swallows the sync-fallback failure; SiteMedia stays `PENDING` — `app/Services/Media/MediaUploadService.php:413` → `audit-2026-06-13-observability.md`
    - [x] **OBS-10** · P2 — `StaffAuditService::record` swallows all Throwable; audit-write failures invisible — `app/Services/Audit/StaffAuditService.php:46` → `audit-2026-06-13-observability.md`
    - [x] **OBS-11** · P3 — `CheckStreamingLiveStatusJob` "succeeds" when Redis is unavailable — `app/Jobs/Streaming/CheckStreamingLiveStatusJob.php:39` → `audit-2026-06-13-observability.md`
    - [x] **OBS-13** · P3 — `WarmPublicSiteCacheJob` warm swallows all failures — `app/Jobs/Cache/WarmPublicSiteCacheJob.php:80` → `audit-2026-06-13-observability.md`
    - [x] **SCALE-8** · P2 — `TwitchApiClient` no 429 detection; rate-limit silently marks every handle offline — `app/Services/Streaming/TwitchApiClient.php:53` → `audit-2026-06-13-database-and-queue-scaling.md`
- Rationale: Same theme as B3 but on jobs/scheduler/services that report false success. OBS-2 (paid Apify) and OBS-8 (all scheduled commands) are the high-value ones. Adding `report()`/`fail()` + 429 handling makes these failures visible.
- Suggested approach: `report($e)` + `$this->fail()` where a job currently fakes success (OBS-2/11/13); wrap the 18 `onFailure` callbacks with `report()` (a shared closure); `report()` on audit-write + media-fallback failures; add 429 detection to `TwitchApiClient` (back off, don't mark offline). Run `composer test`.
- Dependencies: OBS-2 overlaps B4's Instagram theme — fine to do here. None blocking.

**Session prompts:**

*Plan:* Not needed — go straight to implementation.

*Implementation:*
> Implement Bundle B12. Make the cited jobs `report()`/`fail()` instead of faking success; wrap all 18 `routes/console.php` `onFailure` callbacks with a shared `report()` helper; `report()` audit-write + media sync-fallback failures; add HTTP 429 handling to `TwitchApiClient::getLiveHandles` (back off / preserve last state rather than marking offline). Run `composer test`. Summarise per finding.

*Review (learning):*
> Background: a queued job that catches an error and returns normally shows GREEN in Horizon — it "succeeded" while doing nothing. For paid scrapes (OBS-2) and scheduled jobs (OBS-8) that's dangerous: money burned / tasks skipped with no alert. Check: 1) Do these now `report()` and, where appropriate, `fail()`? 2) Do all 18 scheduled-command failures reach Nightwatch? 3) Does a Twitch 429 back off instead of declaring everyone offline? Ask: "does green-in-Horizon actually mean the work happened?"

*Review (technical):*
> Skeptical review of B12. 1) Faked-success paths now `report()` + `fail()` (engaging retry) without breaking legitimate empty results. 2) All 18 `onFailure` callbacks report; the shared helper is actually wired to each. 3) 429 path backs off and preserves prior live state; no false-offline. 4) `composer test` green.

### Bundle B13: Fail-open middleware + log hygiene (4 items) — Effort: S
- [x] **Bundle B13 complete**
- Models: plan=— · impl=sonnet · review=sonnet
- Findings:
    - [x] **WHK-4** · P2 — `IdempotencyKey` fail-open invisible to Nightwatch; Redis degradation silently removes idempotency from all mutating endpoints — `app/Http/Middleware/IdempotencyKey.php` → `audit-2026-06-13-webhook-idempotency.md`
    - [x] **WHK-5** · P2 — `VerifyBotToken` circuit-open/unreachable use `Log::warning`; bot-protection degradation invisible — `app/Http/Middleware/VerifyBotToken.php:187` → `audit-2026-06-13-webhook-idempotency.md`
    - [x] **JOB-10** · P2 — `SendFeedbackEmailJob` silently discards when the row is deleted — `app/Jobs/Notifications/SendFeedbackEmailJob.php:53` → `audit-2026-06-13-job-queue-correctness.md`
    - [x] **OBS-12** · P3 — `SendTransactionalNotificationEmailJob` emits `Log::debug` on prod control-flow paths without an env guard — `app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php:76` → `audit-2026-06-13-observability.md`
- Rationale: Fail-open guards (idempotency, bot-protection) that degrade silently, plus two log-hygiene items. When Redis/circuit degrade, ops should know — these add the missing `report()` and clean up noisy debug logs.
- Suggested approach: `report()` (rate-limited) on the fail-open branches of `IdempotencyKey` and `VerifyBotToken`; `report()` the deleted-row discard in `SendFeedbackEmailJob`; gate the OBS-12 `Log::debug` calls behind an env check. Run `composer test`.
- Dependencies: None.

**Session prompts:**

*Plan:* Not needed — go straight to implementation.

*Implementation:*
> Implement Bundle B13. Add a (rate-limited) `report()` to the fail-open catch branches in `IdempotencyKey` and `VerifyBotToken` so silent degradation is visible; `report()` the `SendFeedbackEmailJob` deleted-row discard; wrap the OBS-12 `Log::debug` calls in an environment guard. Run `composer test`. Summarise.

*Review (learning):*
> Background: "fail-open" means if Redis dies, the idempotency/bot-protection check just lets requests through — safer than blocking everyone, but if it's silent we never know protection is off. Check: 1) Do the fail-open paths now alert (without log-spamming on sustained outage — rate-limited)? 2) Is the feedback-job discard now visible? 3) Are prod debug logs gated? Ask: "if a safety net silently disengages, would we find out?"

*Review (technical):*
> Skeptical review of B13. 1) Fail-open branches `report()` but rate-limited (no Nightwatch flood under sustained Redis outage). 2) `SendFeedbackEmailJob` discard reported. 3) `Log::debug` calls env-guarded; no behaviour change. 4) `composer test` green.

### Bundle B14: .env.example documentation drift (5 items) — Effort: S
- [x] **Bundle B14 complete**
- Models: plan=— · impl=haiku · review=sonnet
- Findings:
    - [x] **CFG-1** · P2 — `FRONTEND_URL` consumed by code + `EnvCheckService::REQUIRED` but absent from `.env.example` — `config/app.php:18`, `app/Services/Diagnostics/EnvCheckService.php:29` → `audit-2026-06-13-configuration-hygiene.md`
    - [x] **CFG-2** · P2 — Five `config/services.php` keys used by active features absent from `.env.example` — `config/services.php:18,59` → `audit-2026-06-13-configuration-hygiene.md`
    - [x] **CFG-3** · P2 — Fourteen operational-tuning env keys absent from `.env.example` — `config/partna.php`, `config/queue.php:101` → `audit-2026-06-13-configuration-hygiene.md`
    - [x] **CFG-7** · P3 — `.env.example` has `GDPR_REDACT_PLACEHOLDER_DOMAIN=gdpr.sidest.io`; config default is `gdpr.partna.au` — `.env.example:292` → `audit-2026-06-13-configuration-hygiene.md`
    - [x] **CFG-8** · P3 — Four queue-connection tuning vars absent from `.env.example` — `config/queue.php:72` → `audit-2026-06-13-configuration-hygiene.md`
- Rationale: Pure documentation drift — keys the code reads but a new deploy can't discover from `.env.example` (or has the wrong stale value). Zero logic change; one edit to `.env.example` (do NOT touch `.env`).
- Suggested approach: Add every cited key to `.env.example` with a safe default + a one-line comment; fix the stale `GDPR_REDACT_PLACEHOLDER_DOMAIN` value to `gdpr.partna.au`. Confirm against `EnvCheckService::REQUIRED`.
- Dependencies: None. (CLAUDE.md: never modify `.env` directly — only `.env.example`.)

**Session prompts:**

*Plan:* Not needed — go straight to implementation.

*Implementation:*
> Implement Bundle B14. Add the CFG-1/2/3/8 keys to `.env.example` (safe defaults + one-line comments) and correct the CFG-7 stale value to `gdpr.partna.au`. Do NOT edit `.env`. Cross-check against `EnvCheckService::REQUIRED`. Run `composer test`. Summarise the keys added.

*Review (learning):*
> Background: `.env.example` is the map of every config a deploy needs. If code reads `FRONTEND_URL` but it's not in the example, a new environment silently runs without it. Check: 1) Is every cited key now in `.env.example` with a sensible default + comment? 2) Was the wrong `sidest.io` value corrected? 3) Was `.env` left untouched (we never edit it)? Ask: "could someone set up a fresh environment from `.env.example` alone and have it work?"

*Review (technical):*
> Skeptical review of B14. 1) All cited keys present in `.env.example`; none missing vs `EnvCheckService::REQUIRED`. 2) Defaults are safe (no secrets, no prod values). 3) `GDPR_REDACT_PLACEHOLDER_DOMAIN` matches the config default. 4) `.env` unmodified. 5) `composer test` green.

### Bundle B15: Config fail-safe + hardcoded→config (7 items) — Effort: M
- [x] **Bundle B15 complete** — _CFG-6 resolved by comment, NOT default-flip: throttle is a protective security control; defaulting it false would be a fail-open regression (per the backref's own recommendation)._
- Models: plan=sonnet · impl=sonnet · review=sonnet
- Findings:
    - [x] **CFG-4** · P2 — Supabase webhook HMAC secrets absent from `EnvCheckService`; missing secrets give a false `status: ok` while hooks 503 — `app/Services/Diagnostics/EnvCheckService.php:23` → `audit-2026-06-13-configuration-hygiene.md`
    - [x] **CFG-5** · P2 — `MediaDiskResolver` superglobal probe invisible to `EnvCheckService`; cached-vs-runtime disk split — `app/Services/Media/MediaDiskResolver.php:33` → `audit-2026-06-13-configuration-hygiene.md`
    - [x] **CFG-6** · P3 — `PARTNA_THROTTLE_ENABLED` defaults `true`, inconsistent with the `false` baseline of other flags — `config/partna.php:797` → `audit-2026-06-13-configuration-hygiene.md`
    - [x] **CFG-9** · P3 — Queue names hardcoded in 13 jobs while 4 use `config()`; an env rename strands the hardcoded ones — multiple jobs → `audit-2026-06-13-configuration-hygiene.md`
    - [x] **CFG-10** · P3 — `CircuitBreaker` constructor defaults ignore `config/partna.php` circuit-breaker values — `app/Services/BotProtection/CircuitBreaker.php:10` → `audit-2026-06-13-configuration-hygiene.md`
    - [x] **CFG-11** · P3 — `LiveStatusPoller` cold-demotion TTLs hardcoded, no config path — `app/Services/Streaming/LiveStatusPoller.php:26` → `audit-2026-06-13-configuration-hygiene.md`
    - [x] **CFG-12** · P3 — Twitch/Kick API base URLs hardcoded as class constants — `app/Services/Streaming/KickApiClient.php:20` → `audit-2026-06-13-configuration-hygiene.md`
- Rationale: Two are fail-safe gaps (a missing secret reports healthy; CFG-4 in particular masks total hook failure), the rest are hardcoded values that should read `config()` so they're tunable without a deploy. Grouped as "make config honest".
- Suggested approach: Add the HMAC secrets + media-disk probe to `EnvCheckService::REQUIRED` (CFG-4/5); flip `PARTNA_THROTTLE_ENABLED` default to `false` (CFG-6); route the 13 hardcoded queue names + CircuitBreaker/poller/base-URL constants through `config()` (CFG-9/10/11/12). Run `composer test`.
- Dependencies: CFG-9 overlaps B4 (InstagramConnectJob queue) — coordinate so the queue name resolves consistently.

**Session prompts:**

*Plan:*
> Read CFG-4/5/6/9/10/11/12. CFG-4 is the important one — a missing webhook HMAC secret currently reports `status: ok` while every hook 503s. Decide the config keys for the hardcoded values and confirm CFG-9's queue names line up with B4's queue decision. Produce a step list.

*Implementation:*
> Implement Bundle B15. Add the webhook HMAC secrets + media-disk probe to `EnvCheckService` so missing config fails the health check (CFG-4/5); default `PARTNA_THROTTLE_ENABLED` to `false`; move hardcoded queue names + circuit-breaker/poller/base-URL constants to `config()` (CFG-9/10/11/12). Run `composer test`. Summarise.

*Review (learning):*
> Background: a health check that reports "ok" while webhooks are 503-ing (because a secret is missing) is worse than no check — it hides the problem. And values hardcoded in classes can't be tuned without a code deploy. Check: 1) Does a missing HMAC secret now fail the health check? 2) Do the hardcoded queue names / URLs / TTLs now come from `config()`? 3) Is the throttle default consistent with other flags? Ask: "if a required secret is missing, does the app admit it's broken?"

*Review (technical):*
> Skeptical review of B15. 1) `EnvCheckService` now fails closed on missing HMAC/media-disk config; CFG-4 no longer reports false-ok. 2) Hardcoded values read `config()` with sane fallbacks; CFG-9 queue names match B4. 3) Throttle default flipped without disabling it where intentionally on. 4) `composer test` green.

### Bundle B16: Memory-unbounded commands + bulk erasure [@10k] (7 items) — Effort: M
- [ ] **Bundle B16 complete**
- Models: plan=sonnet · impl=sonnet · review=sonnet
- Findings:
    - [ ] **SCALE-1** · P2 — `EnforcePlatformLinkCapCommand` plucks all user IDs then one query per user — `app/Console/Commands/EnforcePlatformLinkCapCommand.php:64` → `audit-2026-06-13-database-and-queue-scaling.md`
    - [ ] **SCALE-2** · P2 — `BackfillSubdomainKvCommand --all` plucks every user ID into memory — `app/Console/Commands/BackfillSubdomainKvCommand.php:40` → `audit-2026-06-13-database-and-queue-scaling.md`
    - [ ] **SCALE-3** · P2 — `GcOrphanedVideoArtifactsCommand` loads the entire `videos/` R2 listing into a PHP array — `app/Console/Commands/GcOrphanedVideoArtifactsCommand.php:43` → `audit-2026-06-13-database-and-queue-scaling.md`
    - [ ] **SCALE-4** · P2 — `Customer::redact()` issues N UPDATEs per enquiry instead of a bulk erasure — `app/Models/Core/User/Customer.php:113` → `audit-2026-06-13-database-and-queue-scaling.md`
    - [ ] **SCALE-5** · P2 — `ProcessImageVariantsJob` loads the full original into PHP memory instead of streaming — `app/Jobs/ProcessImageVariantsJob.php:148` → `audit-2026-06-13-database-and-queue-scaling.md`
    - [ ] **SCALE-7** · P2 — `DeleteMirroredMediaJob` has no `onQueue()` despite its docblock promising `scraping` — `app/Jobs/Platforms/DeleteMirroredMediaJob.php:47` → `audit-2026-06-13-database-and-queue-scaling.md`
    - [ ] **SCALE-9** · P3 — `PruneExpiredHandleAliases` plucks expired IDs before deletion rather than deleting in-place — `app/Console/Commands/PruneExpiredHandleAliases.php:23` → `audit-2026-06-13-database-and-queue-scaling.md`
- Rationale: Console commands and an erasure path that load unbounded result sets into memory — fine at 100 users, OOM/slow at 10k. All fixed with `chunkById`/`cursor`/bulk-UPDATE/streaming. SCALE-7 (missing `onQueue`) rides along as a same-area job fix.
- Suggested approach: Replace `pluck`-then-loop with `chunkById`/`cursor` (SCALE-1/2/3/9); convert `Customer::redact()` to a single bulk UPDATE (SCALE-4); stream the image original instead of full-read (SCALE-5); add the missing `onQueue('scraping')` (SCALE-7). Run `composer test`.
- Dependencies: SCALE-7 queue name should match B4/B15's queue decision. `[@10k]` — schedule after launch-blockers.

**Session prompts:**

*Plan:*
> Read SCALE-1/2/3/4/5/7/9. These all load too much into memory at scale. For each, pick the streaming primitive (`chunkById` vs `cursor` vs `LazyCollection`) and confirm the bulk-UPDATE for `Customer::redact()` preserves the GDPR-erasure semantics. Produce a step list.

*Implementation:*
> Implement Bundle B16. Convert pluck-then-loop commands to `chunkById`/`cursor`; make `Customer::redact()` a single bulk UPDATE; stream the image original in `ProcessImageVariantsJob`; add `onQueue('scraping')` to `DeleteMirroredMediaJob`. Run `composer test`. Summarise memory/query reductions.

*Review (learning):*
> Background: `User::pluck('id')` loads every ID into one PHP array — at 10k rows it's fine, at 1M it's an out-of-memory crash. `chunkById`/`cursor` stream in batches. Check: 1) Do these commands now process in chunks, not one giant array? 2) Does `redact()` do one bulk UPDATE instead of N? 3) Same behaviour, just bounded memory? Ask: "what happens to this command at 1M rows?"

*Review (technical):*
> Skeptical review of B16. 1) No remaining unbounded `pluck`/`get` on user-scale tables; `chunkById` (not `chunk`, to avoid pagination drift under concurrent writes). 2) `Customer::redact()` bulk UPDATE erases the same columns as before. 3) Image streaming doesn't break variant output. 4) SCALE-7 queue matches the supervisor. 5) `composer test` green.

### Bundle B17: API Resource discipline (5 items) — Effort: M
- [x] **Bundle B17 complete** — _API-6 implemented as a dedicated light `StaffUserListResource` (preserving B9's admin PII gate), NOT the heavy `UserStaffResource` which would reshape the list. API-2 uses intersect-allowlist semantics (drops unknown keys, never pads). UserResource tests repointed to the real /me resource `UserDashboardResource`; its dead-code-only shape test deleted._
- Models: plan=sonnet · impl=sonnet · review=sonnet
- Findings:
    - [x] **API-1** · P2 — `UserResource` is dead code carrying unconditional PII — `app/Http/Resources/UserResource.php:10` → `audit-2026-06-13-api-contract.md`
    - [x] **API-2** · P2 — `StaffSiteResource` passes blocks as a raw JSONB array with no field gate — `app/Http/Resources/Staff/StaffSiteResource.php:43` → `audit-2026-06-13-api-contract.md`
    - [x] **API-5** · P3 — Three controllers build media/document payloads via private helpers instead of Resources — `audit-2026-06-13-api-contract.md`
    - [x] **API-6** · P3 — `StaffUserController::index` hand-rolls the professional list instead of `UserStaffResource` — `…/StaffUserController.php:60` → `audit-2026-06-13-api-contract.md`
    - [x] **API-7** · P3 — `StaffAnalyticsController` returns raw `stdClass`; model IDs lack explicit string casts — `…/StaffAnalyticsController.php:161` → `audit-2026-06-13-api-contract.md`
- Rationale: All five are the same architectural rule (CLAUDE.md: never return raw models/arrays — use Resource classes). Dead `UserResource` carrying PII (API-1) and ungated JSONB (API-2) are the risk-bearing ones; the rest are consistency.
- Suggested approach: Delete or gate `UserResource` (API-1); add a field allowlist to `StaffSiteResource` blocks (API-2); replace the private-helper payloads with Resource classes (API-5/6/7); add explicit string casts on IDs. Run `composer test`. Coordinate with B9 (`StaffUserController`).
- Dependencies: API-6 touches `StaffUserController::index` — coordinate with B9 (PII gate) so they don't conflict.

**Session prompts:**

*Plan:*
> Read API-1/2/5/6/7. Decide whether `UserResource` is truly dead (delete) or needs `$this->when()` gating. Define the `StaffSiteResource` block field allowlist. Note the overlap with B9 on `StaffUserController`. Produce a step list.

*Implementation:*
> Implement Bundle B17. Remove/gate the dead `UserResource`; allowlist `StaffSiteResource` block fields; convert the private-helper payloads (API-5/6/7) to Resource classes with explicit ID string casts. Run `composer test`. Summarise.

*Review (learning):*
> Background: this app must wrap every API response in a Resource class (CLAUDE.md) — raw models/arrays leak whatever columns exist (PII, internal JSONB) and drift the contract. Check: 1) Is the dead PII-carrying `UserResource` gone or gated? 2) Are staff `blocks` now an explicit field list, not a raw JSONB dump? 3) Do the hand-rolled payloads now go through Resources? Ask: "is any endpoint returning a raw model or array?"

*Review (technical):*
> Skeptical review of B17. 1) No raw-model/array returns remain in the cited controllers. 2) `StaffSiteResource` block allowlist drops unknown keys. 3) IDs cast to string explicitly. 4) No contract change beyond the intended PII/field tightening (characterization test if a shape changed). 5) `composer test` green.

### Bundle B18: API payload shape, pagination & error contract (4 items) — Effort: M
- [ ] **Bundle B18 complete** — _API-3/4/9 done (non-breaking). API-8 PARTIAL: Session + HandleReclaim standardized (byte-identical output); Mfa + PublicReport DEFERRED 2026-06-14 — moving `code: mfa_fresh_required` into the errors bag risks the frontend MFA re-prompt flow, and PublicReportController is a public endpoint — both are client-visible error-shape changes needing frontend coordination. API-4 addressed via non-breaking `->limit()` caps (500/500/200); a true pagination envelope would break bare-array consumers and is deferred._
- Models: plan=sonnet · impl=sonnet · review=sonnet
- Findings:
    - [x] **API-3** · P3 — `UserSelfController::show` hand-rolls the site payload instead of `SiteResource` — `app/Http/Controllers/Api/User/Account/UserSelfController.php:36` → `audit-2026-06-13-api-contract.md`
    - [x] **API-4** · P3 — Three user-facing list endpoints return unbounded collections with no pagination — `audit-2026-06-13-api-contract.md` — _non-breaking limit caps; pagination envelope deferred (frontend-coordinated)_
    - [ ] **API-8** · P3 — Four controllers return non-standard error shapes diverging from `ApiController::error()` — `audit-2026-06-13-api-contract.md` — _Session + HandleReclaim done; Mfa + PublicReport deferred (client-visible auth/public contract)_
    - [x] **API-9** · P3 — Staff list endpoints use four different `per_page` defaults — `audit-2026-06-13-api-contract.md`
- Rationale: API contract consistency — pagination (also a scale concern), standardized error envelope, and a consistent `per_page`. Low-risk but reduce client-side surprise and unbounded payloads.
- Suggested approach: Add pagination to the three unbounded list endpoints (API-4); route the four divergent error returns through `ApiController::error()` (API-8); standardize `per_page` defaults (API-9); use `SiteResource` in `UserSelfController::show` (API-3). Run `composer test`.
- Dependencies: None.

**Session prompts:**

*Plan:*
> Read API-3/4/8/9. Pick the standard `per_page` default + pagination envelope (match existing `meta`/`pagination` convention — check for envelope-key drift). Confirm adding pagination won't break existing clients (it may be a breaking change — note it). Produce a step list.

*Implementation:*
> Implement Bundle B18. Paginate the three unbounded list endpoints; route the four error returns through `ApiController::error()`; unify `per_page`; use `SiteResource` in `UserSelfController::show`. Run `composer test`. Summarise, flagging any client-visible contract change.

*Review (learning):*
> Background: list endpoints that return everything will eventually return thousands of rows in one response; and four different error shapes make clients write four parsers. Check: 1) Are the list endpoints paginated with the standard envelope? 2) Do errors all use `ApiController::error()`? 3) Is `per_page` consistent? Ask: "would a frontend dev be surprised by any response shape here?"

*Review (technical):*
> Skeptical review of B18. 1) Pagination uses the project's envelope (no `meta` vs `pagination` drift); breaking-change flagged if so. 2) All four error paths standardized. 3) `per_page` default consistent + capped. 4) `composer test` green.

### Bundle B19: CORS configuration fixes (2 items) — Effort: S
- [x] **Bundle B19 complete**
- Models: plan=— · impl=sonnet · review=sonnet
- Findings:
    - [x] **SEC-2** · P2 — CORS regex excludes the apex `partna.au`, silently denying CORS from the marketing site — `config/cors.php:21` → `audit-2026-06-13-security.md`
    - [x] **SEC-4** · P2 — `config/cors.php` calls `config('partna.frontend_origins')` at require-time before `partna.php` loads → `allowed_origins` resolves to `[]` — `config/cors.php:14` → `audit-2026-06-13-security.md`
- Rationale: Both are `config/cors.php` correctness — one regex misses the apex domain, the other evaluates config before it's loaded (alphabetical config order), zeroing allowed origins. Same file, one fix.
- Suggested approach: Fix the regex to include the apex label (SEC-2); defer the `frontend_origins` resolution to a closure/runtime so it isn't empty at require-time (SEC-4). Verify with an actual cross-origin request from the marketing apex + a subdomain.
- Dependencies: None.

**Session prompts:**

*Plan:* Not needed — go straight to implementation.

*Implementation:*
> Implement Bundle B19. Fix the `config/cors.php` regex to match the apex `partna.au` (SEC-2); make `allowed_origins` resolve at runtime, not require-time, so `partna.frontend_origins` is populated (SEC-4). Run `composer test`. Confirm a cross-origin request from both apex and a subdomain succeeds.

*Review (learning):*
> Background: config files load alphabetically — `cors.php` before `partna.php` — so reading `partna.*` at the top of `cors.php` gets an empty value. And a regex needing a subdomain label silently rejects the bare apex domain. Both quietly break CORS. Check: 1) Does the apex `partna.au` now pass CORS? 2) Is `allowed_origins` populated at runtime (not `[]`)? Ask: "does the marketing site (apex) actually get CORS access?"

*Review (technical):*
> Skeptical review of B19. 1) Regex matches apex + subdomains; no over-broad match (no `*.evil.com`). 2) `frontend_origins` resolved at runtime; `allowed_origins` non-empty. 3) Verified with a real preflight from apex + subdomain. 4) `composer test` green.

### Bundle B20: Caching jitter/lock hygiene (3 items) — Effort: S
- [x] **Bundle B20 complete**
- Models: plan=— · impl=sonnet · review=sonnet
- Findings:
    - [x] **CCH-1** · P3 — Bare `Cache::put` in the auth-ID mismatch repair path bypasses `rememberLockedNullable` — `app/Services/Cache/UserCacheService.php:186` → `audit-2026-06-13-caching-gold-standard.md`
    - [x] **CCH-2** · P3 — Double-jitter on the `FeatureFlagService` integer TTL path — `app/Services/FeatureFlags/FeatureFlagService.php:262` → `audit-2026-06-13-caching-gold-standard.md`
    - [x] **CCH-3** · P3 — Unjittered 24h literal TTL on the idempotency response cache — `app/Http/Middleware/IdempotencyKey.php:16` → `audit-2026-06-13-caching-gold-standard.md`
- Rationale: Three small deviations from the project's caching gold standard (locked writes + single jitter). Low-risk consistency fixes that prevent thundering-herd and cache-stampede edge cases.
- Suggested approach: Route the repair-path write through `rememberLockedNullable` (CCH-1); remove the double-jitter (CCH-2); add jitter to the 24h idempotency TTL (CCH-3). Run `composer test`.
- Dependencies: None. (Relates to the GS-1 no-raw-`Cache::` standard — see project notes.)

**Session prompts:**

*Plan:* Not needed — go straight to implementation.

*Implementation:*
> Implement Bundle B20. Replace the bare `Cache::put` repair-path write with `rememberLockedNullable` (CCH-1); remove the double jitter in `FeatureFlagService::jitteredTtl` (CCH-2); add jitter to the `IdempotencyKey` 24h TTL (CCH-3). Run `composer test`. Summarise.

*Review (learning):*
> Background: this app caches through locked helpers (so two requests don't both rebuild the same key) and adds a little random "jitter" to TTLs (so keys don't all expire at once → a stampede). These three spots deviate. Check: 1) Does the repair write now go through the locked helper? 2) Is the TTL jittered exactly once (not zero, not twice)? Ask: "could many keys expire at the same instant and stampede the DB?"

*Review (technical):*
> Skeptical review of B20. 1) No bare `Cache::put` remains on that path (GS-1 guard). 2) Single jitter applied; CCH-2 no longer double-jitters. 3) Idempotency TTL jittered without weakening the idempotency window. 4) `composer test` green.

### Bundle B21: Transaction pgsql-contract + concurrency counters (6 items) — Effort: M
- [x] **Bundle B21 complete** — _opus-reviewed. LIFE-5 race closed via a same-connection `lockForUpdate` re-read of `subdomain_changed_at` inside the pgsql tx + a CI-runnable regression test. LIFE-11/12 use atomic `increment()`; LIFE-12 drops the failure-path observer (safe — no public data changes on a failed refresh)._
- Models: plan=sonnet · impl=sonnet · review=opus
- Findings:
    - [x] **SEM-3** · P2 — `SiteProvisioningService::tryCreateSite` uses bare `DB::transaction()` for a savepoint that must be on pgsql — `app/Services/User/SiteProvisioningService.php:100` → `audit-2026-06-13-semantic-correctness.md`
    - [x] **LIFE-4** · P2 — `SiteProvisioningService` SQLSTATE-matches `'23505'` instead of the typed `UniqueConstraintViolationException` — `app/Services/User/SiteProvisioningService.php:115` → `audit-2026-06-13-lifecycle-correctness.md`
    - [x] **LIFE-5** · P2 — `UpdateSiteAction` reads `subdomain_changed_at` from a stale pre-transaction snapshot; two concurrent renames both bypass the 30-day cooldown — `app/Services/Site/UpdateSiteAction.php:28` → `audit-2026-06-13-lifecycle-correctness.md`
    - [x] **TXN-3** · P3 — Nested `DB::transaction` in `ReclaimHandleAction` is correct but undocumented — `app/Services/Site/ReclaimHandleAction.php:25` → `audit-2026-06-13-transaction-boundaries.md`
    - [x] **LIFE-11** · P3 — `PlatformRefresher` read-modify-write on `consecutive_failures` loses increments under concurrency — `app/Services/Platforms/PlatformRefresher.php:92` → `audit-2026-06-13-lifecycle-correctness.md`
    - [x] **LIFE-12** · P3 — `SmartLinkRefresher` same lost-increment race — `app/Services/SmartLinks/SmartLinkRefresher.php:33` → `audit-2026-06-13-lifecycle-correctness.md`
    - Note: SEM-3 and LIFE-4 are the same `SiteProvisioningService` method — fix together.
- Rationale: Transaction-correctness cluster — bare `DB::transaction()` off the pgsql pin (breaks the SQLite test suite + savepoint semantics), a stale-snapshot cooldown bypass (LIFE-5 is a real concurrency hole), lost-increment counters, and one doc gap. Grouped because they share the transaction/concurrency theme.
- Suggested approach: Pin to `DB::connection('pgsql')->transaction()` (SEM-3); catch the typed `UniqueConstraintViolationException` (LIFE-4); re-read `subdomain_changed_at` inside the transaction with a row lock (LIFE-5); convert counter writes to atomic `increment()` (LIFE-11/12); add the doc comment (TXN-3). Run `composer test`.
- Dependencies: None. Review with opus (transaction boundaries + a real concurrency bug in LIFE-5).

**Session prompts:**

*Plan:*
> Read SEM-3/LIFE-4 (same method), LIFE-5, LIFE-11/12, TXN-3. LIFE-5 is the real bug — two concurrent rename requests both read a stale `subdomain_changed_at` and both pass the cooldown. Decide the locking approach (re-read inside tx with `lockForUpdate`). Produce a step list; flag LIFE-5 as the one needing a concurrency test.

*Implementation:*
> Implement Bundle B21. Pin the provisioning transaction to pgsql + catch the typed unique-violation (SEM-3/LIFE-4); re-read + row-lock `subdomain_changed_at` inside the transaction (LIFE-5); use atomic `increment()` for the failure counters (LIFE-11/12); document the nested transaction (TXN-3). Run `composer test`. Summarise.

*Review (learning):*
> Background: "read-modify-write" (`$x = $row->n; $row->n = $x+1; save()`) loses updates when two processes run it at once — both read the same `n`. And reading a value BEFORE a transaction then deciding inside it lets two requests both pass a "once per 30 days" check. Check: 1) Is the cooldown value re-read + locked inside the transaction (LIFE-5)? 2) Do counters use atomic `increment()`? 3) Is the transaction on `pgsql`? Ask: "if two requests hit this at the exact same time, can both win?"

*Review (technical):*
> Skeptical review of B21 (opus). 1) LIFE-5: `subdomain_changed_at` re-read under `lockForUpdate` inside the tx; concurrency test proves two simultaneous renames can't both bypass. 2) Counters use DB-atomic `increment`. 3) `DB::connection('pgsql')->transaction()`; typed exception caught. 4) `composer test` green incl. SQLite suite.

### Bundle B22: Code dedup + IP-hash consistency (4 items) — Effort: S
- [x] **Bundle B22 complete**
- Models: plan=— · impl=sonnet · review=sonnet
- Findings:
    - [x] **SLOP-2** · P3 — `formatPrice`/`normalizeAvailability` copy-pasted across two event scrapers — `app/Services/Platforms/EventbriteScraper.php:203`, `HumanitixScraper.php:219` → `audit-2026-06-13-code-quality-slop.md`
    - [x] **SLOP-3** · P3 — `shouldRememberConfirmationPreference` copy-pasted across three controllers — `audit-2026-06-13-code-quality-slop.md`
    - [x] **SLOP-4** · P3 — `normaliseOptionalString` copy-pasted across two controllers — `audit-2026-06-13-code-quality-slop.md` — _(actually 3 sites incl. MediaUploadService; all consolidated to App\Support\Concerns\NormalisesOptionalString)_
    - [x] **SEC-14** · P3 — `PerTargetReportThrottle` uses plain `hash('sha256', ip|key)` while analytics uses `hash_hmac` — different hashes for the same IP — `app/Http/Middleware/Moderation/PerTargetReportThrottle.php:28` → `audit-2026-06-13-security.md`
- Rationale: Three verbatim-duplication cleanups plus one consistency fix (the report throttle should hash IPs the same way the analytics path does). Low-risk; extract to shared helpers/traits.
- Suggested approach: Extract the duplicated scraper helpers into the shared `PlatformScraper` base (SLOP-2); move `shouldRememberConfirmationPreference` to the existing `ConfirmationPreferenceService` (SLOP-3); extract `normaliseOptionalString` to a shared helper/trait (SLOP-4); switch `PerTargetReportThrottle` to `hash_hmac` (SEC-14). Run `composer test`.
- Dependencies: None.

**Session prompts:**

*Plan:* Not needed — go straight to implementation.

*Implementation:*
> Implement Bundle B22. Extract SLOP-2 helpers to `PlatformScraper`; move SLOP-3 to `ConfirmationPreferenceService`; extract SLOP-4 to a shared helper; switch `PerTargetReportThrottle` to `hash_hmac('sha256', $ip, $key)` (SEC-14). Run `composer test`. Summarise.

*Review (learning):*
> Background: copy-pasted logic drifts — fix a bug in one copy, miss the others. And two systems hashing the same IP differently (plain hash vs HMAC) means they can't correlate. Check: 1) Is each duplicated function now a single shared definition both callers use? 2) Does the report throttle hash IPs the same way analytics does? Ask: "if I change this helper, do all call sites get the change?"

*Review (technical):*
> Skeptical review of B22. 1) No verbatim copies remain; shared definitions are behaviour-identical to the originals. 2) `PerTargetReportThrottle` uses `hash_hmac` matching the analytics path. 3) No behaviour change beyond consolidation. 4) `composer test` green.

### Bundle B23: Untested policies — coverage (5 items) — Effort: M
- [x] **Bundle B23 complete** — _25 new Gate-based tests (allow/deny/404). FeatureFlagPolicy deny-all tests prove the contract but not registration (Gate defaults deny); PolicyCoverageTest already enforces registration._
- Models: plan=— · impl=sonnet · review=sonnet
- Findings:
    - [x] **TEST-5** · P2 — `CasePolicy` staff-only gate has no functional test — `app/Policies/CasePolicy.php:16` → `audit-2026-06-13-test-coverage.md`
    - [x] **TEST-6** · P2 — `DecisionPolicy` abilities untested — `app/Policies/DecisionPolicy.php:14` → `audit-2026-06-13-test-coverage.md`
    - [x] **TEST-7** · P2 — `FeatureFlagPolicy` deny-all for `User` actors untested — `app/Policies/FeatureFlagPolicy.php:21` → `audit-2026-06-13-test-coverage.md`
    - [x] **TEST-8** · P2 — `GdprPolicy::view` ownership gate + 404-not-403 untested — `app/Policies/GdprPolicy.php:18` → `audit-2026-06-13-test-coverage.md`
    - [x] **TEST-9** · P2 — `FeedbackPolicy` capability gate + owner-isolation untested — `app/Policies/FeedbackPolicy.php:20` → `audit-2026-06-13-test-coverage.md`
- Rationale: Five policies that gate staff/GDPR/feedback access have zero functional tests — exactly the auth surface a regression would silently open. One session writing `tests/Feature/Security/PolicyEnforcement/` tests for all five.
- Suggested approach: For each policy, write allow + deny cases (owner vs non-owner, staff vs user, the 404-not-403 path). Use `authorizeForUser` against real model skeletons. Run `composer test`.
- Dependencies: None.

**Session prompts:**

*Plan:* Not needed — go straight to implementation (mirror the existing `*PolicyEnforcementTest` pattern).

*Implementation:*
> Implement Bundle B23. Add functional tests for `CasePolicy`, `DecisionPolicy`, `FeatureFlagPolicy`, `GdprPolicy`, `FeedbackPolicy` covering allow/deny + the 404-not-403 denials, mirroring existing `tests/Feature/Security/PolicyEnforcement/` tests. Run `composer test`. Summarise coverage added.

*Review (learning):*
> Background: a Policy decides who can do what. Untested, a refactor can flip an allow/deny and nothing catches it. Good policy tests assert BOTH the allow case and the deny case (and that denial is 404, not 403, when the resource shouldn't be revealed). Check: 1) Does each policy now have allow + deny tests? 2) Are the 404-vs-403 rules asserted? Ask: "if someone loosened this gate, would a test go red?"

*Review (technical):*
> Skeptical review of B23. 1) Each policy has positive + negative cases, not just happy-path. 2) 404-not-403 asserted where the standard requires it. 3) Tests use `authorizeForUser` (Auth::user() is null here). 4) `composer test` green; coverage genuinely exercises the gate (not a tautology).

### Bundle B24: Policy edge-cases + job-path tests (6 items) — Effort: M
- [x] **Bundle B24 complete**
- Models: plan=— · impl=sonnet · review=sonnet
- Findings:
    - [x] **TEST-3** · P2 — `SendFeedbackEmailJob` per-recipient cache idempotency guard untested — `app/Jobs/Notifications/SendFeedbackEmailJob.php:80` → `audit-2026-06-13-test-coverage.md`
    - [x] **TEST-4** · P2 — `ProcessImageVariantsJob` lock-acquire gate (concurrent-worker guard) untested — `app/Jobs/ProcessImageVariantsJob.php:69` → `audit-2026-06-13-test-coverage.md`
    - [x] **TEST-10** · P2 — `NotificationPolicy::view` global-broadcast edge case untested — `app/Policies/NotificationPolicy.php:22` → `audit-2026-06-13-test-coverage.md`
    - [x] **TEST-11** · P2 — `PartnaStaffPolicy` self-edit/self-delete guards untested — `app/Policies/PartnaStaffPolicy.php:31` → `audit-2026-06-13-test-coverage.md`
    - [x] **TEST-12** · P2 — `UserSelfPolicy` staff-actor abilities untested — `app/Policies/UserSelfPolicy.php:85` → `audit-2026-06-13-test-coverage.md`
    - [x] **TEST-13** · P2 — `IntegrationConnectionPolicy` owner-isolation + 404-on-not-yours untested — `app/Policies/IntegrationConnectionPolicy.php:14` → `audit-2026-06-13-test-coverage.md`
- Rationale: Edge-case coverage on existing-but-partially-tested policies/jobs — the idempotency guard, the concurrency lock gate, and the staff/self/broadcast policy branches. Pairs naturally after B23.
- Suggested approach: Add the missing branch tests to the existing test files where they exist; create files for `PartnaStaffPolicy`/`IntegrationConnectionPolicy`. Cover the concurrency lock gate (TEST-4) and per-recipient idempotency (TEST-3). Run `composer test`.
- Dependencies: B23 (same area) — do consecutively.

**Session prompts:**

*Plan:* Not needed — go straight to implementation.

*Implementation:*
> Implement Bundle B24. Add the missing branch/edge tests for TEST-3/4/10/11/12/13 (extend existing test files; create for `PartnaStaffPolicy`/`IntegrationConnectionPolicy`). Run `composer test`. Summarise.

*Review (learning):*
> Background: a policy or job can be "tested" but only on its happy path — the dangerous branches (self-delete, global broadcast, concurrent-worker lock, idempotency skip) stay uncovered. Check: 1) Does each finding's specific branch now have a test? 2) Are deny + edge cases asserted, not just the obvious allow? Ask: "which branch of this gate would a regression slip through?"

*Review (technical):*
> Skeptical review of B24. 1) Each cited branch exercised (self-edit/self-delete, broadcast, lock-acquire, idempotency). 2) New policy test files follow the enforcement-test convention. 3) `composer test` green; assertions are meaningful.

### Bundle B25: GDPR export content + analytics PII minimization (4 items) — Effort: S
- [ ] **Bundle B25 complete**
- Models: plan=sonnet · impl=sonnet · review=opus
- Findings:
    - [ ] **PRIV-1** · P1 — Data export includes a staff member's email in the professional's package — `app/Services/User/DataExport/DataExportPayloadBuilder.php:610` → `audit-2026-06-13-privacy-compliance.md`
    - [ ] **SEM-1** · P1 — GDPR Art. 15: `reporter_email` stored un-normalised but queried lowercased (export misses rows) — `app/Services/User/DataExport/DataExportPayloadBuilder.php:462`, `app/Services/Moderation/ContentReportService.php:79` → `audit-2026-06-13-semantic-correctness.md`
    - [ ] **PRIV-5** · P2 — Analytics referrer stored with full query string — UTM-embedded emails land in the warehouse — `app/Services/Analytics/Writers/PostgresEventWriter.php` → `audit-2026-06-13-privacy-compliance.md`
    - [ ] **PRIV-6** · P2 — User-agent strings stored verbatim across all analytics tables — `app/Services/Analytics/Writers/PostgresEventWriter.php` → `audit-2026-06-13-privacy-compliance.md`
    - Note (SEM-1): normalise `reporter_email` on write AND backfill, so export queries match.
- Rationale: GDPR data-quality + minimization — the export leaks a third party's email (PRIV-1) and misses rows due to a normalization mismatch (SEM-1), and analytics over-collects PII (referrer query strings, raw UA). All are code-level (no schema migration), grouped as the "export correctness + collection minimization" PR.
- Suggested approach: Strip/omit the staff email from the export package (PRIV-1); normalise `reporter_email` on write + match the export query (SEM-1); strip query strings from stored referrers + truncate/parse UA (PRIV-5/6). Run `composer test`.
- Dependencies: None. Review with opus (GDPR/PII).

**Session prompts:**

*Plan:*
> Read PRIV-1, SEM-1, PRIV-5/6. SEM-1 needs care — normalising `reporter_email` on write means existing rows must be backfilled or the export query must match both forms. Decide normalise-on-write + backfill vs query-both. Decide referrer/UA minimization (strip query string, parse UA to family). Produce a step list.

*Implementation:*
> Implement Bundle B25. Remove the staff email from the export package (PRIV-1); normalise `reporter_email` consistently on write + export-read, backfilling as needed (SEM-1); strip query strings from stored analytics referrers and minimise UA (PRIV-5/6). Run `composer test`. Summarise.

*Review (learning):*
> Background: GDPR says a user's data export must contain THEIR data — not a staff member's email (PRIV-1) — and must contain ALL of it. SEM-1 is subtle: we store `reporter_email` as typed but query it lowercased, so an export silently misses rows. And we shouldn't hoard PII we don't need (emails hidden in UTM links, full device fingerprints). Check: 1) Is the staff email gone from the export? 2) Does the email export now match regardless of case? 3) Are referrer query strings + raw UAs no longer stored? Ask: "does this export contain exactly the user's data — all of it, and nothing that isn't theirs?"

*Review (technical):*
> Skeptical review of B25 (opus — GDPR/PII). 1) Export no longer contains the staff email; no other third-party PII leaks. 2) `reporter_email` normalization consistent write↔read; backfill verified so existing rows export. 3) Referrer stored without query string; UA minimised. 4) `composer test` green; ideally an export-completeness test for the email-case path.

### Bundle B26: Cloudflare Worker config/constants sync (3 items) — Effort: M
- [ ] **Bundle B26 complete**
- Models: plan=sonnet · impl=sonnet · review=opus
- Findings:
    - [ ] **EDGE-6** · P1 — Worker `RESERVED` set (18) diverges from `config('partna.reserved_subdomains')` (~200); infra subdomains 404 instead of passing through — `cloudflare-worker/src/index.js:36`, `config/partna.php:53` → `audit-2026-06-13-edge-worker.md`
    - [ ] **EDGE-10** · P2 — Staging Worker shares the production `SUBDOMAIN_KV`; a staging deploy can poison prod routing — `cloudflare-worker/wrangler.toml:20` → `audit-2026-06-13-edge-worker.md`
    - [ ] **EDGE-11** · P3 — Hardcoded Worker constants lack cross-reference comments to their backend mirrors — `cloudflare-worker/src/index.js:33` → `audit-2026-06-13-edge-worker.md`
- Rationale: The Worker and backend hold parallel copies of routing config that have drifted (reserved subdomains), share a KV namespace across environments (a real prod-poisoning risk), and lack the comments that would prevent the next drift. Grouped as "make the Worker/backend config agree".
- Suggested approach: Reconcile the Worker `RESERVED` set with `config('partna.reserved_subdomains')` — ideally generate it from one source (EDGE-6); give staging its own KV namespace in `wrangler.toml` (EDGE-10); add cross-reference comments (EDGE-11). Deploy + smoke-test routing.
- Dependencies: EDGE-10 touches KV namespace binding (not the single-writer job) — safe, but verify the staging Laravel `CLOUDFLARE_KV_NAMESPACE_ID` matches. Review with opus (routing/KV).

**Session prompts:**

*Plan:*
> Read EDGE-6/10/11. EDGE-10 is the risk one — staging and prod share `SUBDOMAIN_KV`, so a staging backfill can overwrite prod routing. Decide: generate the Worker `RESERVED` list from the backend config (build step) vs a documented manual mirror. Plan the staging KV namespace + the matching Laravel env. Produce a step list + a routing smoke test.

*Implementation:*
> Implement Bundle B26. Reconcile the Worker `RESERVED` set with `config('partna.reserved_subdomains')` (EDGE-6); add a dedicated staging KV namespace in `wrangler.toml` and point staging Laravel at it (EDGE-10); add cross-reference comments (EDGE-11). Deploy to preview, smoke-test routing for a reserved subdomain + a normal handle. Summarise.

*Review (learning):*
> Background: the Worker decides routing from a list of "reserved" subdomains and a KV store. Two copies of the reserved list (Worker vs backend) drifted, so some infra subdomains 404. Worse, staging and prod write to the SAME KV — a staging job can corrupt prod routing. Check: 1) Do the two reserved lists now agree (or come from one source)? 2) Does staging have its OWN KV namespace? 3) Are the mirrored constants commented so they don't drift again? Ask: "can a staging deploy change what prod serves?"

*Review (technical):*
> Skeptical review of B26 (opus — routing/KV). 1) Worker `RESERVED` matches backend config (or is generated from it); reserved subdomains pass through, not 404. 2) Staging binds a distinct `SUBDOMAIN_KV`; prod routing can't be poisoned from staging; Laravel staging env matches. 3) Smoke test covers reserved + normal + alias. 4) `wrangler` deploy clean.

### Bundle B27: Model cleanup + job backoff (4 items) — Effort: S
- [x] **Bundle B27 complete** — _DINT-13: GdprRequest was test-referenced (not fully dead); deleted the model + its 3 redundant direct-policy-call tests (DataExportAudit coverage preserved) + the PolicyCoverageTest exempt entry. DINT-12's DB unique constraint stays deferred to S8._
- Models: plan=— · impl=sonnet · review=sonnet
- Findings:
    - [x] **DINT-12** · P3 — `UserBootstrapService::createWelcomeNotification` uses `firstOrCreate` without a DB unique constraint on `(user_id,type,title)` — `app/Services/User/UserBootstrapService.php:156` → `audit-2026-06-13-data-integrity.md`
    - [x] **DINT-13** · P3 — `GdprRequest` model references `core.gdpr_requests`, which doesn't exist in the standalone schema — `app/Models/Core/Gdpr/GdprRequest.php:33` → `audit-2026-06-13-data-integrity.md`
    - [x] **DINT-14** · P3 — `FeatureFlagOverride` hand-rolls UUID generation instead of `HasUuids` — `app/Models/Core/FeatureFlagOverride.php:32` → `audit-2026-06-13-data-integrity.md`
    - [x] **JOB-11** · P3 — `ProcessImageVariantsJob`/`ProcessVideoVariantsJob` use flat backoff where exponential is warranted — `app/Jobs/ProcessImageVariantsJob.php:35` → `audit-2026-06-13-job-queue-correctness.md`
- Rationale: Small model/job hygiene — a dead model referencing a non-existent table (DINT-13, a standalone-strip leftover), a hand-rolled UUID that should use the trait (DINT-14), an app-only uniqueness assumption (DINT-12), and a backoff tweak. Code-only (DINT-12's DB constraint is deferred to S-schema if wanted).
- Suggested approach: Remove/repoint the dead `GdprRequest` model (DINT-13 — verify nothing uses it); switch `FeatureFlagOverride` to `HasUuids` (DINT-14); document/guard the `firstOrCreate` assumption (DINT-12 — note the DB constraint belongs with the schema standalone if added); change flat→exponential backoff (JOB-11). Run `composer test`.
- Dependencies: DINT-12's optional DB unique constraint → see Standalone S8 if you want it enforced at the DB level.

**Session prompts:**

*Plan:* Not needed — go straight to implementation.

*Implementation:*
> Implement Bundle B27. Remove or repoint the dead `GdprRequest` model after confirming no references (DINT-13); switch `FeatureFlagOverride` to the `HasUuids` trait (DINT-14); add a guard/comment around the welcome-notification `firstOrCreate` (DINT-12); use exponential backoff on the variant jobs (JOB-11). Run `composer test`. Summarise.

*Review (learning):*
> Background: leftover code from the standalone strip (a model pointing at a table that no longer exists) is a trap — it works until someone calls it. And hand-rolling UUIDs when a trait does it is drift. Check: 1) Is the dead `GdprRequest` gone (and truly unused)? 2) Does `FeatureFlagOverride` use `HasUuids`? 3) Is the backoff now exponential? Ask: "would calling this model blow up because its table doesn't exist?"

*Review (technical):*
> Skeptical review of B27. 1) `GdprRequest` removed/repointed with zero remaining references (grep). 2) `HasUuids` produces the same UUID behaviour. 3) `firstOrCreate` race acknowledged (DB constraint deferred to S8). 4) Exponential backoff sane (cap + jitter). 5) `composer test` green.

---

## Standalone — do NOT bundle

These touch the single-writer KV contract, schema-wide RLS, raw `supabase/migrations/` DDL, large test authoring, or need a human design decision. Each gets its own PR + dedicated review. **DB-DDL items are also a prod-deploy concern** — prod is still on the pre-standalone schema (a gated re-baseline), so apply on dev first and sequence prod carefully.

### S1: Public-profile API test coverage (2 items) — Effort: L
- [ ] **S1 complete**
- Models: plan=sonnet · impl=sonnet · review=sonnet
- Findings:
    - [ ] **TEST-1** · P0 — `IndividualProfileController` (the primary public-profile API, the Astro Worker's subrequest target) has zero tests — `app/Http/Controllers/Api/PublicSite/IndividualProfileController.php:56` → `audit-2026-06-13-test-coverage.md`
    - [ ] **TEST-2** · P1 — `PublicSiteController::show` (domain-routing alias redirect) untested; diverges from the tested `showByHeader` — `app/Http/Controllers/Api/PublicSite/PublicSiteController.php:23` → `audit-2026-06-13-test-coverage.md`
- Why standalone: L-effort test authoring against the two-level cache + deleted-race + alias-redirect paths; its own PR and careful review (this endpoint serves every public page).
- Dependencies: None, but high value — the recent custom-domains merge routes more traffic here.

**Session prompts:**

*Plan:*
> Read TEST-1/2 and `IndividualProfileController` + `PublicSiteController`. Plan the test matrix: unknown handle → 404, deleted-between-resolve-and-build race, 200 cache-miss path (mock `CacheLockService`), blank-handle guard, and the alias-redirect 301. Decide how to avoid the full `site.public_site_payload` view in tests. Produce the test list.

*Implementation:*
> Implement S1. Create `tests/Feature/PublicSite/IndividualProfileControllerTest.php` (+ alias-redirect coverage for `PublicSiteController::show`) covering the matrix from the plan, mocking `CacheLockService`. Run `composer test`. Summarise coverage.

*Review (learning):*
> Background: this controller is the engine room — every public page hits it, and it has a tricky two-level cache and a "deleted between resolve and build" race, none of it tested. Check: 1) Are the 404 paths (unknown + blank handle) covered? 2) Is the deleted-race path tested? 3) Is the alias 301 redirect tested? Ask: "if someone refactors the cache keys, does a test catch a broken public page?"

*Review (technical):*
> Skeptical review of S1. 1) Tests cover unknown/blank handle 404, deleted-race double-key bust, 200 cache-miss, alias 301. 2) `CacheLockService` mocked; no dependency on the DB view. 3) Tests fail if the resolve/deleted-race logic regresses. 4) `composer test` green.

### S2: Moderation/CSAM edge-cache purge (1 item) — Effort: M
- [x] **S2 complete**
- Models: plan=opus · impl=sonnet · review=opus
- Findings:
    - [x] **EDGE-3** · P0 — Moderation enforcement bypasses edge purge; taken-down content (incl. CSAM auto-suspend) survives in the stale shadow up to 7 days — `app/Services/Moderation/ModerationActionDispatcher.php:26`, `app/Jobs/Moderation/PurgeModerationCacheJob.php:39`, `app/Jobs/Moderation/SuspendSiteJob.php:56`, `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:96` → `audit-2026-06-13-edge-worker.md`
- Why standalone: touches the single-writer KV contract (`SyncSubdomainToKvJob`) AND the moderation/CSAM enforcement path — a safety- and legal-critical flow that must not run unattended. Opus plan + review.
- Dependencies: Pairs with B1 (`CloudflarePurgeService`). Do AFTER B1 lands the prefix-purge so the moderation path can dispatch a real purge.

**Session prompts:**

*Plan:*
> Read EDGE-3 fully. Three bugs combine: mass-update jobs bypass the observer (no purge dispatched), `sync_subdomain_kv` and `purge_cloudflare_cache` both map to a job that only syncs KV, and `SyncSubdomainToKvJob` upserts a live entry for a suspended (non-trashed) user. Design: add a real `CloudflareCachePurgeJob` dispatch on every enforcement decision (`hide_site`/`suspend_user`/`ban_user`/`csam_auto_suspend`), and make KV retire the entry for suspended users. This is CSAM-critical — plan carefully, with opus.

*Implementation:*
> Implement S2 per the plan: dispatch a real edge purge from `ModerationActionDispatcher` for every enforcement decision (not just KV sync), look up the owner via `reportable_owner_user_id` (`withTrashed`), and make `SyncSubdomainToKvJob` retire KV for suspended (non-trashed) users. Run `composer test`. Summarise; confirm a taken-down page is purged.

*Review (learning):*
> Background: when moderation takes a page down, three things must happen — DB update, KV routing removed, edge cache purged. Today only the DB update works; the page stays served from the edge for up to 7 days. For CSAM, that's illegal content reachable for a week. Check: 1) Does every enforcement action now dispatch a real edge purge? 2) Is a suspended user removed from KV routing? 3) Does this cover the mass-update paths that skip observers? Ask: "after a takedown, is the content actually gone from the edge?"

*Review (technical):*
> Skeptical review of S2 (opus — moderation/CSAM/KV). 1) Every enforcement decision dispatches `CloudflareCachePurgeJob` for the owner's handle; mass-update paths covered (not observer-dependent). 2) `SyncSubdomainToKvJob` retires KV for suspended users; no live upsert. 3) Owner lookup uses `withTrashed`. 4) Manual takedown→fetch confirms purge. 5) `composer test` green. CSAM path verified end-to-end.

### S3: `site.smart_links` schema hardening (7 items) — Effort: M
- [ ] **S3 complete**
- Models: plan=opus · impl=sonnet · review=opus
- Findings:
    - [ ] **SCHEMA-1** · P1 — `site.smart_links` has no Row Level Security — `supabase/migrations/20260531000000_create_smart_links.sql` → `audit-2026-06-13-schema-rls.md`
    - [ ] **SCHEMA-3** · P2 — missing `id`/timestamp DB defaults + `updated_at` trigger → `audit-2026-06-13-schema-rls.md`
    - [ ] **SCHEMA-4** · P2 — no dedup key; duplicate canonical URLs per site → `audit-2026-06-13-schema-rls.md`
    - [ ] **SCHEMA-5** · P2 — `type`/`platform` have no CHECK constraints → `audit-2026-06-13-schema-rls.md`
    - [ ] **DINT-4** · P2 — no UNIQUE on `(site_id, canonical_url)` → `audit-2026-06-13-data-integrity.md`
    - [ ] **DINT-5** · P2 — `type`/`platform` bare `text NOT NULL`, no CHECK → `audit-2026-06-13-data-integrity.md`
    - [ ] **DINT-9** · P3 — no `BEFORE UPDATE` trigger; `updated_at` stalls → `audit-2026-06-13-data-integrity.md`
- Why standalone: raw `supabase/migrations/` DDL adding RLS + constraints to a tenant table; converges 7 findings (SCHEMA-4≡DINT-4, SCHEMA-5≡DINT-5) onto one new migration. DB change → dev-first, gated prod.
- Dependencies: None. One migration covers all seven.

**Session prompts:**

*Plan:*
> Read SCHEMA-1/3/4/5 + DINT-4/5/9 (note SCHEMA-4≡DINT-4, SCHEMA-5≡DINT-5). Design ONE migration: enable + FORCE RLS with owner-scoped policies, add `id`/timestamp defaults + `updated_at` trigger, a UNIQUE `(site_id, canonical_url)`, and CHECK constraints on `type`/`platform`. Use `CONCURRENTLY` for the index. Plan dev-apply then the gated prod sequence. Produce the migration outline.

*Implementation:*
> Implement S3 as one raw SQL migration in `supabase/migrations/` (NOT a Laravel migration): RLS enable+force + owner policies, DB-side defaults + `updated_at` trigger, `CREATE UNIQUE INDEX CONCURRENTLY` on `(site_id, lower(canonical_url))`, CHECK constraints on `type`/`platform`. Push to dev (`db push --dry-run` first). Summarise; do NOT push prod without confirmation.

*Review (learning):*
> Background: `smart_links` is tenant data but has no RLS (a Postgres-level guard that a row belongs to its owner) and no DB constraints — so duplicates and invalid `type` values can be inserted, and the DB doesn't enforce ownership. App code is the only guard. Check: 1) Is RLS enabled AND forced with owner-scoped policies? 2) Is there a UNIQUE preventing duplicate canonical URLs per site? 3) Are `type`/`platform` constrained? 4) Defaults + `updated_at` trigger present? Ask: "if app code had a bug, would the database still protect this table?"

*Review (technical):*
> Skeptical review of S3 (opus — RLS/schema). 1) RLS `ENABLE` + `FORCE` with correct owner policies (matches the baseline pattern). 2) UNIQUE built `CONCURRENTLY`; CHECK constraints added `NOT VALID` + validated if data exists. 3) Defaults + trigger mirror sibling tables. 4) `db push --dry-run` clean on dev. 5) Prod sequencing noted, not executed.

### S4: Migration-safety hot-table DDL (8 items) — Effort: M
- [ ] **S4 complete**
- Models: plan=opus · impl=sonnet · review=opus
- Findings:
    - [ ] **MIG-1** · P1 — `CREATE UNIQUE INDEX` without `CONCURRENTLY` on hot `site.sites` — `supabase/migrations/20260612140000_site_custom_domain.sql:26` → `audit-2026-06-13-migration-safety.md`
    - [ ] **MIG-2** · P2 — Unbatched inline `UPDATE` data-scrubs on `site.sites` inside migration transactions → `audit-2026-06-13-migration-safety.md`
    - [ ] **MIG-3** · P2 — Unbatched `UPDATE core.users` in the account-type backfill → `audit-2026-06-13-migration-safety.md`
    - [ ] **MIG-4** · P2 — `ADD CONSTRAINT … CHECK` on `core.users` without `NOT VALID` → `audit-2026-06-13-migration-safety.md`
    - [ ] **MIG-5** · P2 — `DROP INDEX` without `CONCURRENTLY` in the sort-order migration → `audit-2026-06-13-migration-safety.md`
    - [ ] **MIG-6** · P3 — Missing `SET LOCAL lock_timeout`/`statement_timeout` guards on hot-table DDL → `audit-2026-06-13-migration-safety.md`
    - [ ] **SCHEMA-7** · P3 — Two trigger functions use `SET search_path TO 'pg_catalog'` instead of `''` → `audit-2026-06-13-schema-rls.md`
    - [ ] **SCHEMA-8** · P3 — Two recent migrations add CHECK inline without `NOT VALID`+`VALIDATE` → `audit-2026-06-13-schema-rls.md`
- Why standalone: raw migration DDL on the hottest tables (`site.sites`, `core.users`); these are unapplied on prod, so this is the moment to make them lock-safe before the gated re-baseline. Per CLAUDE.md, editing already-applied-on-dev migrations is safe (they won't re-run) and the prod deploy executes the corrected version.
- Dependencies: None, but coordinate with the eventual prod re-baseline (prod is on the pre-standalone schema).

**Session prompts:**

*Plan:*
> Read MIG-1..6 + SCHEMA-7/8. Plan the lock-safe rewrites: `CONCURRENTLY` for index create/drop (outside any tx block), `NOT VALID`+companion `VALIDATE` for CHECKs, `SET LOCAL lock_timeout/statement_timeout` guards, batched/extracted data-scrubs, and the `search_path = ''` fix. Note which files are already applied on dev (edit-in-place is safe) vs new. Produce the per-file change list + prod sequencing.

*Implementation:*
> Implement S4: make each cited migration lock-safe (`CONCURRENTLY`, `NOT VALID`+`VALIDATE`, `SET LOCAL` guards, batched scrubs, `search_path=''`). Edit already-applied-on-dev files in place per CLAUDE.md; add companion validate migrations where needed. `db push --dry-run` on dev. Summarise; do NOT push prod without confirmation.

*Review (learning):*
> Background: adding an index or constraint without `CONCURRENTLY`/`NOT VALID` locks the whole table for the build — on `site.sites` (every page read) that's an outage. These tables are empty-ish now but prod hasn't run these migrations yet, so fixing now is free. Check: 1) Do hot-table index ops use `CONCURRENTLY` (and sit outside a transaction)? 2) Do CHECKs use `NOT VALID`+`VALIDATE`? 3) Are there `SET LOCAL` timeouts so a stuck DDL aborts instead of hanging? Ask: "would this migration lock a busy table at prod scale?"

*Review (technical):*
> Skeptical review of S4 (opus — migrations). 1) Every hot-table index create/drop is `CONCURRENTLY` and not inside a `BEGIN/COMMIT`. 2) CHECKs split `NOT VALID`+`VALIDATE`. 3) `SET LOCAL lock_timeout/statement_timeout` present. 4) Data-scrubs batched/extracted. 5) `search_path=''` on the two functions. 6) `db push --dry-run` clean on dev; prod sequencing documented, not run.

### S5: RLS FORCE + baseline schema defaults (2 items) — Effort: M
- [ ] **S5 complete**
- Models: plan=opus · impl=sonnet · review=opus
- Findings:
    - [ ] **SCHEMA-6** · P2 — Baseline tenant tables have RLS `ENABLE` but not `FORCE` — the owner role bypasses policies — `supabase/migrations/20260526000000_baseline_standalone_user.sql` (+ two later migrations) → `audit-2026-06-13-schema-rls.md`
    - [ ] **SCHEMA-2** · P3 — `site.site_subdomain_aliases.id` lacks `DEFAULT gen_random_uuid()` — baseline DDL line 864 → `audit-2026-06-13-schema-rls.md`
- Why standalone: schema-wide RLS posture change across many tenant tables — exactly the "wrong call propagates silently" category. Needs opus plan + review and careful dev verification that `app_backend` still functions under `FORCE`.
- Dependencies: None, but verify against S3/S4 RLS work so policies are consistent.

**Session prompts:**

*Plan:*
> Read SCHEMA-6/2. `FORCE ROW LEVEL SECURITY` makes policies apply even to the table owner role — confirm the `app_backend` role's access still works (it connects as a non-superuser, so verify it isn't the table owner, or it'll be locked out). Enumerate the tenant tables needing `FORCE`. Plan the default-UUID fix. Produce the migration + a verification query proving `app_backend` can still read/write.

*Implementation:*
> Implement S5: add `FORCE ROW LEVEL SECURITY` to the cited tenant tables and `DEFAULT gen_random_uuid()` to `site_subdomain_aliases.id`, as a raw migration. Verify `app_backend` CRUD still works under FORCE on dev. `db push --dry-run`. Summarise; do NOT push prod without confirmation.

*Review (learning):*
> Background: RLS `ENABLE` turns on row policies, but the table OWNER bypasses them unless you also `FORCE`. If our app role happens to own the table, that's a silent tenant-isolation hole. Check: 1) Is FORCE added to the tenant tables? 2) Does the app role STILL work (FORCE can lock out a misconfigured role)? 3) Is the missing UUID default added? Ask: "can the app's DB role see another tenant's rows?"

*Review (technical):*
> Skeptical review of S5 (opus — RLS). 1) `FORCE` on all intended tenant tables; `app_backend` CRUD verified under FORCE (not locked out, not bypassing). 2) UUID default added. 3) Policies consistent with S3. 4) `db push --dry-run` clean on dev; prod gated.

### S6: GDPR/PII retention machinery (9 items) — Effort: L
- [ ] **S6 complete**
- Models: plan=opus · impl=sonnet · review=opus
- Findings:
    - [ ] **PRIV-2** · P1 — GDPR export artifacts never pruned; 30-day retention declared, unenforced → `audit-2026-06-13-privacy-compliance.md`
    - [ ] **PRIV-3** · P1 — Handle-audit 7-year retention declared, no enforcement job → `audit-2026-06-13-privacy-compliance.md`
    - [ ] **PRIV-4** · P1 — Reported users' PII in moderation evidence survives deletion, no retention rule → `audit-2026-06-13-privacy-compliance.md`
    - [ ] **PRIV-7** · P2 — Cross-tenant + unsubscribed email subscriptions never purged → `audit-2026-06-13-privacy-compliance.md`
    - [ ] **PRIV-8** · P2 — Waitlist signups retain full PII indefinitely → `audit-2026-06-13-privacy-compliance.md`
    - [ ] **DINT-1** · P2 — `notifications.email_subscriptions` retains PII indefinitely after unsubscribe → `audit-2026-06-13-data-integrity.md`
    - [ ] **DINT-2** · P2 — `broadcast_email_receipts.subscription_id` no FK; orphans accumulate → `audit-2026-06-13-data-integrity.md`
    - [ ] **DINT-3** · P2 — `audit.auth_factor_events.user_id` no FK; orphans after auth deletion → `audit-2026-06-13-data-integrity.md`
    - [ ] **DINT-6** · P3 — `moderation.case_signals.reporter_email` no timed retention for non-account reporters → `audit-2026-06-13-data-integrity.md`
- Why standalone: a retention SUBSYSTEM, not a patch — needs schema (`deleted_at`/FKs/retention columns), scheduled prune commands, and a policy decision on retention windows per data class. L-effort, GDPR-critical, opus plan + review.
- Dependencies: Touches schema (raw migrations) + `routes/console.php` scheduling + `AccountDeletionService`. Sequence its migration parts with S4.

**Session prompts:**

*Plan:*
> Read PRIV-2/3/4/7/8 + DINT-1/2/3/6. This is a retention SYSTEM: decide the retention window per data class (exports 30d, handle-audit 7y, moderation evidence, email subs, waitlist), the schema changes (add `deleted_at`/FKs/retention columns), and the scheduled prune commands. Produce the design: schema migrations + the `routes/console.php` schedule + the deletion-cascade additions. Flag the policy decisions Josh must confirm (retention durations).

*Implementation:*
> Implement S6 per the approved design: schema migrations for the missing FKs/retention columns; scheduled prune commands (`handles:prune-audit-logs`, completed-export prune, evidence/email-sub/waitlist retention) in `routes/console.php`; wire cross-tenant email-sub + evidence redaction into `AccountDeletionService`. `db push --dry-run` on dev. Summarise; do NOT push prod without confirmation.

*Review (learning):*
> Background: GDPR requires we DON'T keep personal data forever — we declared retention windows (in config) but never built the jobs to actually delete old data. So exports, audit logs, moderation evidence, and email subscriptions pile up forever. Check: 1) Is there now a scheduled job enforcing each declared window? 2) Do FKs/`deleted_at` exist so data CAN be pruned/cascaded? 3) Does account deletion now reach the cross-tenant/evidence PII? Ask: "for each kind of personal data, what actually deletes it, and when?"

*Review (technical):*
> Skeptical review of S6 (opus — GDPR/schema). 1) Each declared retention window has a scheduled prune that actually deletes (verify on dev). 2) FKs added with correct `ON DELETE` behaviour; orphans cascade. 3) Account deletion reaches cross-tenant email subs + moderation evidence. 4) Migrations lock-safe (coordinate w/ S4). 5) `db push --dry-run` clean on dev; prod gated; retention durations confirmed with Josh.

### S7: `SyncSubdomainToKvJob` KV batching [@10k] (1 item) — Effort: M
- [ ] **S7 complete**
- Models: plan=sonnet · impl=sonnet · review=opus
- Findings:
    - [ ] **SCALE-6** · P2 — `SyncSubdomainToKvJob` issues one Cloudflare KV HTTP call per alias instead of batching — `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:167` → `audit-2026-06-13-database-and-queue-scaling.md`
- Why standalone: this is the SINGLE WRITER to `SUBDOMAIN_KV` (a hard architectural rule) — any change to it is reviewed in isolation with opus, never bundled.
- Dependencies: None. `[@10k]` scale fix.

**Session prompts:**

*Plan:*
> Read SCALE-6 and `SyncSubdomainToKvJob`. This job is the ONLY writer to `SUBDOMAIN_KV` (architectural invariant). Plan a batched KV write (Cloudflare bulk KV API) that preserves exactly the same key/value/TTL semantics and the retire-on-trashed behaviour. Produce a step list; flag the single-writer invariant as the review focus.

*Implementation:*
> Implement S7: batch the per-alias KV writes in `SyncSubdomainToKvJob` via Cloudflare's bulk KV API, preserving key/value/`expirationTtl` semantics and the retire path. Run `composer test`. Summarise; confirm no other code writes KV.

*Review (learning):*
> Background: every `<handle>.partna.au` routes through one Worker reading `SUBDOMAIN_KV`, and exactly ONE job writes that store — so it's safety-critical. Today it makes one HTTP call per alias; at 10k users with many aliases that's slow and rate-limit-prone. Batching fixes that WITHOUT changing what's written. Check: 1) Same keys/values/TTLs as before, just batched? 2) Still the only writer? 3) Retire-on-delete still works? Ask: "does the routing table end up identical, just written in fewer calls?"

*Review (technical):*
> Skeptical review of S7 (opus — single-writer KV). 1) Batched writes produce byte-identical keys/values/TTLs vs the per-alias path. 2) Retire/remove path preserved. 3) Still the sole KV writer (grep confirms). 4) Partial-batch-failure handling sane. 5) `composer test` green.

### S8: Schema FK indexes + triggers + dead values (4 items) — Effort: S
- [ ] **S8 complete**
- Models: plan=sonnet · impl=sonnet · review=opus
- Findings:
    - [ ] **DINT-7** · P3 — `analytics.section_views.block_id` FK has no index → `audit-2026-06-13-data-integrity.md`
    - [ ] **DINT-8** · P3 — `core.feature_flag_overrides.created_by` FK has no index → `audit-2026-06-13-data-integrity.md`
    - [ ] **DINT-10** · P3 — `site.platform_connections` has no `BEFORE UPDATE` trigger (DEFAULT added, trigger not) → `audit-2026-06-13-data-integrity.md`
    - [ ] **DINT-11** · P3 — `site.site_media.pool` CHECK includes the dead `'brand_gallery'` value from the pre-standalone era → `audit-2026-06-13-data-integrity.md`
- Why standalone: raw `supabase/migrations/` DDL (indexes, trigger, CHECK rewrite). Small, but DB-touching → dev-first, gated prod; review with opus for migration safety.
- Dependencies: Can ride with S4's migration batch if you prefer one DDL PR.

**Session prompts:**

*Plan:*
> Read DINT-7/8/10/11. Plan a small migration: `CREATE INDEX CONCURRENTLY` on the two FK columns, add the missing `BEFORE UPDATE` trigger, rewrite the `site_media.pool` CHECK to drop `'brand_gallery'` (verify no rows use it first). Produce the migration outline + the data-check query.

*Implementation:*
> Implement S8 as one raw migration: `CREATE INDEX CONCURRENTLY` on `section_views.block_id` + `feature_flag_overrides.created_by`; add the `platform_connections` `updated_at` trigger; drop `'brand_gallery'` from the `site_media.pool` CHECK (after confirming zero rows use it). `db push --dry-run` on dev. Summarise; do NOT push prod without confirmation.

*Review (learning):*
> Background: a foreign-key column without an index makes joins/deletes on it slow (Postgres doesn't auto-index FKs). And a CHECK constraint still listing a dead value (`brand_gallery`, gone since the standalone strip) is misleading. Check: 1) Are the two FK columns now indexed (`CONCURRENTLY`)? 2) Is the trigger added? 3) Is the dead CHECK value removed (and no row used it)? Ask: "do our foreign keys have the indexes they need?"

*Review (technical):*
> Skeptical review of S8 (opus — migration). 1) Indexes built `CONCURRENTLY` (outside a tx). 2) Trigger matches sibling-table pattern. 3) `brand_gallery` removed only after a zero-row check. 4) `db push --dry-run` clean on dev; prod gated.

---

## Deduplication notes

Four cross-lens duplicates merged (169 raw → 165 canonical); the merged IDs are retained in their canonical finding's `Lens:`/title for traceability:

- **OBS-6 → JOB-2** — both report `InstagramConnectJob` dispatching to a `scraping` queue with no Horizon supervisor (`InstagramConnectJob.php:64` + `config/horizon.php`). Canonical in **B4** as JOB-2 (≡OBS-6).
- **TXN-1 → LIFE-3** — both report `ExportUserDataJob` dispatched inside a transaction without `afterCommit` (`DataExportService.php:59`). Canonical in **B6** as LIFE-3 (≡TXN-1).
- **LIFE-7 → SEM-2** and **TXN-4 → SEM-2** — three reports of `cancel()`/`adminCancel()` using bare `DB::transaction()` off the pgsql contract (`AccountDeletionService.php:357,430`). Canonical in **B5** as SEM-2 (≡LIFE-7, TXN-4).

Near-duplicates kept separate (related but distinct fixes): SCHEMA-4≡DINT-4 and SCHEMA-5≡DINT-5 are the same constraints viewed from two lenses — both folded into the single S3 migration (counted once there). LIFE-1 shares `InstagramConnectJob.php` with B4 but is a distinct fix (`ShouldBeUnique`).

## Coverage report

### Findings by lens (raw counts → placement)

| Lens | P0 | P1 | P2 | P3 | Placed in |
|------|----|----|----|----|-----------|
| security | 0 | 1 | 10 | 3 | B2, B7, B9, B19, B22 |
| lifecycle-correctness | 0 | 3 | 3 | 6 | B3, B4, B5, B6, B21, B25 |
| scaling-antipatterns | 0 | 0 | 6 | 1 | B10 |
| database-and-queue-scaling | 0 | 0 | 8 | 1 | B12, B16, S7 |
| schema-rls | 0 | 1 | 5 | 2 | S3, S4, S5 |
| caching-gold-standard | 0 | 0 | 0 | 3 | B10, B20 |
| webhook-idempotency | 1 | 0 | 4 | 0 | B8, B13 |
| transaction-boundaries | 0 | 1 | 1 | 2 | B5, B6, B21 |
| data-integrity | 0 | 0 | 5 | 9 | B27, S3, S6, S8 |
| job-queue-correctness | 0 | 2 | 8 | 1 | B4, B6, B11, B12, B13, B27 |
| observability | 0 | 6 | 4 | 4 | B3, B12, B13 |
| caching-coverage-gaps | 0 | 0 | 0 | 0 | — (clean lens, 0 findings) |
| privacy-compliance | 0 | 4 | 4 | 0 | B25, S6 |
| edge-worker | 3 | 3 | 4 | 3 | B1, B2, B26, S2 |
| configuration-hygiene | 0 | 0 | 5 | 7 | B14, B15 |
| migration-safety | 0 | 1 | 4 | 1 | S4 |
| api-contract | 0 | 0 | 2 | 7 | B17, B18 |
| test-coverage | 1 | 1 | 11 | 0 | B23, B24, S1 |
| code-quality-slop | 0 | 0 | 1 | 3 | B5, B22 |
| semantic-correctness | 0 | 1 | 2 | 0 | B5, B21, B25 |
| **Total** | **5** | **24** | **87** | **53** | 27 bundles + 8 standalone |

### Notes

- **All 169 findings placed exactly once** across 27 bundles + 8 standalone blocks (165 canonical after the 4 merges above). `caching-coverage-gaps` is a valid clean lens (0 findings).
- **P0 routing:** EDGE-1→B2, EDGE-2→B1, EDGE-3→S2, WHK-1→B8, TEST-1→S1. Three of five P0s are standalone (edge/KV/moderation + L-effort tests); two (EDGE-1 Set-Cookie, WHK-1 MFA) are quick bundle wins — sequence B2 and B8 first.
- **Hallucination spot-check (from VERIFY):** all 5 P0s + 3 P1s (SEC-1, MIG-1, OBS-2) confirmed against source — 0 failures.
- **Fresh-merge caveat:** this audit ran right after merge `08af1719` (account types Partna+Business, Square booking, custom domains). Findings cite the new code correctly (MIG-1, EDGE-3/5/6, TEST-1), but any *tier* that hinges on individual-only assumptions deserves a human sanity-check before fixing.
- **Prod-schema caveat:** all DB-DDL standalones (S3–S8) land on dev first; prod is still on the pre-standalone schema (a gated re-baseline), so prod sequencing is a separate, confirmed step — never an unattended `db push`.

*Generated 2026-06-13 by the full-sweep consolidator over 20 adjudicated lens audits (hand-assembled after the `claude -p` consolidation subprocess stalled; bundling, models, and prompts are authored, finding data is verbatim from the lens files).*
