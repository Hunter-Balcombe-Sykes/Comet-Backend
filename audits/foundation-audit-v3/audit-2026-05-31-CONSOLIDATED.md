# Consolidated Foundation Audit v3 — 2026-05-31

Branch: `development` · Sources: 26 lens audits + composer CVE scan + outdated scan · Raw: ~140 · Final: 106 (1 P0 · 14 P1 · 59 P2 · 32 P3) · Bundles: 22

Read `#P0-01` before doing anything else — it halts every clean `supabase db push` and must land first. Every other finding uses the canonical `#PN-NN` ID scheme. Bundle numbers cross-reference those IDs. The `Lens:` field on each finding traces back to the adjudication file. Multi-lens duplicates are resolved in **Deduplication notes**; when a finding subsumes others the canonical entry carries the full fix description.

---

## Model selection — read once

Every item and bundle ends with a `Models: impl=<x> · review=<y>` line. Use the **impl** model to spawn the implementation session; spawn a fresh session with the **review** model after the fix lands.

- **haiku** — trivial mechanical changes: delete a file/line, add a config default, single-line `report($e)` or `request_id`, drop a `'body' =>` log key. Few file edits, no architectural judgment. Fast.
- **sonnet** — standard implementation: refactors, new Resource/Service classes, observer changes, queue swaps, transactional unifications, scheduler entries, Resource creation. The default for most items.
- **opus** — load-bearing invariants with asymmetric blast radius: auth gates, RLS policies, transaction boundaries, single-writer KV contract, GDPR/PII flows, schema migrations, decisions where a wrong call propagates silently.

**Review rule of thumb**: use **opus** for review on anything touching auth, RLS, KV writer contract, transactions, GDPR/PII, schema migrations, or the mail-send layer (items where a subtle wrong fix doesn't blow up loudly). Use **sonnet** for everything else. Never review with haiku.

**Workflow per session**:
1. Pick the item or bundle, read its `Models:` line.
2. Spawn the implementation session with the **impl** model (`/model sonnet` in Claude Code, or `claude --model sonnet`).
3. Paste the item/bundle block; the session uses the `Where:` paths to locate the code.
4. After the fix lands, spawn a separate review session with the **review** model. Paste the same item/bundle plus the changed files. Ask it to verify the fix matches the finding and check for regressions.

---

## Dependency advisories

### Security CVEs

Three active CVEs in direct/transitive Symfony deps as of 2026-05-31:

| CVE | Package | Severity | Fix |
|-----|---------|----------|-----|
| CVE-2026-48736 | `symfony/http-foundation` | unrated (SSRF bypass) | `>=7.4.13` |
| CVE-2026-48784 | `symfony/routing` | unrated (URL dot-segment) | `>=7.4.13` |
| CVE-2026-46644 | `symfony/polyfill-intl-idn` | low (Punycode equivalence) | `>=1.38.1` |

All three resolve with a coordinated `symfony/*` bump — see **Bundle B1**.

### Direct dependency drift

| Package | Current | Available | Type |
|---------|---------|-----------|------|
| `laravel/horizon` | 5.47.0 | 5.47.1 | patch ✓ |
| `laravel/pail` | 1.2.6 | 1.2.7 | patch |
| `laravel/sail` | 1.60.0 | 1.61.0 | patch |
| `openspout/openspout` | 5.7.0 | 5.7.2 | patch ✓ |
| `endroid/qr-code` | 5.1.0 | 6.1.3 | major — review changelog |
| `laravel/framework` | 12.60.2 | 13.12.0 | major — review changelog |
| `laravel/tinker` | 2.11.1 | 3.0.2 | major |
| `laravel/boost` | 1.8.13 | 2.4.8 | major |
| `symfony/*` (http-kernel, mailer, mime, routing, yaml) | 7.4.12 | 8.x | major — do NOT chase; 7.4.13 resolves CVEs |

**Recommendation:** patch the three CVE packages to 7.4.13+ and bump `openspout` to 5.7.2 in one PR (see B1). Hold major bumps (`laravel/framework` 13.x, `symfony` 8.x) until post-pilot.

---

## Cross-lens high-confidence findings

Themes that surfaced independently under two or more lens audits:

1. **`StaffUserController` / skeleton-system aftermath** — `account-caps`, `n1`, and `resources` lenses each independently found the `site.theme` eager-load crash, dead `services`/`blocks` load, and the missing `skeleton_id` response field. Canonical fix is #P1-01.

2. **Orphaned CSAM index / migration timestamp collision** — `migration` and `rls` lenses both flagged `20260530000400_add_csam_quarantine_case_id_idx.sql` as broken. `migration` and `rls` lenses also both flagged the `20260530000000_*` timestamp collision. Canonical fixes are #P0-01 and #P3-15.

3. **Silent click-analytics catch blocks** — `observability` and `n1` lenses independently found the `QueryException`/`Throwable` swallow in `AnalyticsQueryService` and `StaffAnalyticsController`. Canonical fixes are #P2-57 and #P2-58.

4. **DSAR missing PII sections** — `gdpr-export` lens found missing `feedback` and `content-report` sections; `gdpr-deletion` lens found `design_kits` missing. All three gaps confirm the export builder's coverage has not kept pace with schema evolution.

5. **Moderation job failure observability** — `queue-jobs` and `gdpr-export` lenses both found `ActionLogEntry` stuck at `dispatched` on permanent job failure with no `report($e)`. Same audit trail gap, different job classes.

6. **Cache double-invalidation** — `write-amp` lens found `ServiceObserver` and `UserObserver` both calling `invalidateSite()` twice per mutation; `cache` lens independently found related jitter and sentinel gaps in the same service layer, confirming the caching path sees more writes than necessary.

7. **KV dead-deletion path** — `subdomain-kv` lens found `RetireSubdomainFromKvJob` is never dispatched (zero call sites); this means every account deletion leaves a live KV routing entry for up to 7 days.

---

## P0

- [x] **#P0-01** Delete orphaned CSAM quarantine index migration — Lens: `migration` · `rls`
    - Where: `supabase/migrations/20260530000400_add_csam_quarantine_case_id_idx.sql` · `supabase/migrations/20260529200000_remove_csam_pipeline_tables.sql`
    - What: `20260529200000` drops `moderation.csam_quarantine` with `DROP TABLE IF EXISTS`. `20260530000400` then issues `CREATE INDEX CONCURRENTLY IF NOT EXISTS … ON moderation.csam_quarantine`. The `IF NOT EXISTS` clause only suppresses "index already exists", not "relation does not exist"; PostgreSQL raises `ERROR 42P01` and halts the migration pipeline. Every subsequent migration in the sequence is never applied, leaving the database in a partially-migrated state. This blocks every clean `supabase db push`, `supabase db reset`, and production promotion.
    - Fix: Delete `supabase/migrations/20260530000400_add_csam_quarantine_case_id_idx.sql` entirely. When CSAM quarantine is reintroduced, create the index in the same migration that recreates the table.
    - Models: impl=haiku · review=sonnet

---

## P1

- [ ] **#P1-01** `StaffUserController` crashes on every request — skeleton-system removed `Site::theme()` — Lens: `account-caps` · `n1` · `resources`
    - Where: `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php:35` (index) · `:61` (theme access) · `:97` (show load)
    - What: The skeleton-system cleanup replaced `site.sites.theme_id` + `site.themes` with `skeleton_id` and removed the `theme()` Eloquent relationship from `Site`. `StaffUserController::index()` still calls `->with(['site.theme'])` and `show()` calls `->load(['site.theme', 'services', 'blocks'])`. Laravel's eager-load mechanism calls `(new Site)->theme()` to resolve relation constraints; with no such method defined, Eloquent throws `BadMethodCallException: Call to undefined method … Builder::theme()` on the first request. Both `GET /api/staff/professionals` and `GET /api/staff/professionals/{id}` return 500. The `services` and `blocks` eager-loads are also wasted — neither appears in `UserStaffResource::toArray()`.
    - Fix: Replace `->with(['site.theme'])` with `->with(['site'])` in `index()`; replace `->load(['site.theme', 'services', 'blocks'])` with `->load(['site'])` in `show()`. In both response payloads swap the `'theme' => [...]` block for `'skeleton_id' => $site->skeleton_id`. The `skeleton_id` column is already in `Site::$fillable`.
    - Models: impl=haiku · review=sonnet

- [ ] **#P1-02** Service binding throw on cold-miss surfaces as Cloudflare 1101 instead of 503 — Lens: `cloudflare`
    - Where: `cloudflare-worker/src/index.js:268` (cold-miss `await fetchAndCache`) · `:246` (non-GET passthrough)
    - What: `fetchAndCache` calls `await env.PARTNA_PAGES.fetch(request)` with no error boundary. When the `PARTNA_PAGES` Worker is transiently unavailable the rejected promise propagates to the top-level `fetch` handler, which Cloudflare converts to a generic 1101 with no body. The guard at line 234 only covers a missing binding at deploy time — not runtime failures. The non-GET passthrough has identical exposure.
    - Fix: Wrap both `fetchAndCache` calls and the non-GET passthrough in `try/catch`; on catch return `new Response("Service Unavailable", { status: 503, headers: { "Content-Type": "text/plain", "Cache-Control": "no-store" } })` with `applySecurityHeaders` applied. The `ctx.waitUntil(fetchAndCache(...))` background-refresh path is already safe — swallowed by `waitUntil`.
    - Models: impl=sonnet · review=sonnet

- [x] **#P1-03** Streaming queue has no Horizon supervisor — live badges never update — Lens: `queue-sat`
    - Where: `config/horizon.php` (all three environment blocks) · `app/Jobs/Streaming/CheckStreamingLiveStatusJob.php:29`
    - What: `CheckStreamingLiveStatusJob` dispatches to the `streaming` queue. No supervisor in `production`, `development`, or `local` references that queue. Jobs accumulate in Redis indefinitely — at one dispatch every 2 minutes that is ~720 unreachable jobs per day. All streaming live-status badges stay permanently dark.
    - Fix: Add `supervisor-streaming` to `defaults` block (`connection: redis`, `queue: ['streaming']`, `balance: false`, `maxProcesses: 1`, `timeout: 120`, `tries: 1`, `nice: 5`). Add it to both `production` and `development` environment blocks. Add `'redis:streaming' => 300` to `waits`.
    - Models: impl=haiku · review=sonnet

- [x] **#P1-04** Broadcast coordinator job timeout (120 s) exceeds supervisor timeout (60 s) — large broadcasts never complete — Lens: `queue-sat`
    - Where: `app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php` (`$timeout = 120`) · `config/horizon.php` (`supervisor-notifications timeout: 60`)
    - What: Horizon kills the worker via SIGKILL at 60 s; the job's `$timeout = 120` PHP alarm never fires. Any broadcast requiring more than 60 s of `chunkById` iteration is killed, retried twice, then permanently fails. The development `supervisor-1` has `timeout: 300` so this is invisible locally. The `ShouldBeUnique` lock (`uniqueFor = 600`) means a killed job blocks re-dispatch for up to 540 additional seconds.
    - Fix: Raise `supervisor-notifications` `timeout` in the `defaults` block from 60 to at least 130. Verify `retry_after` in `config/queue.php` still exceeds the new value (it does at 360 s). Add a comment: "supervisor timeout must always exceed the max job `$timeout` dispatched to its queues."
    - Models: impl=haiku · review=sonnet

- [ ] **#P1-05** `moderation.enabled` kill-switch missing `(bool)` cast — silently non-functional via env — Lens: `config-secret`
    - Where: `config/partna.php` — `moderation.enabled` line
    - What: OS-level env vars arrive as raw strings; `env('PARTNA_MODERATION_ENABLED', true)` returns the string `'false'` which is truthy in PHP. Every other boolean in the same config block uses `(bool)` cast. The CSAM pipeline that reads this key was deferred on 2026-05-29 but is planned to return; shipping with a broken emergency stop is a pre-launch safety gap.
    - Fix: Change to `(bool) env('PARTNA_MODERATION_ENABLED', true)`. Add a unit test asserting `config('partna.moderation.enabled')` is `false` when the env var is `'false'`.
    - Models: impl=haiku · review=sonnet

- [x] **#P1-06** Feedback submissions missing from DSAR export — Lens: `gdpr-export`
    - Where: `app/Services/User/DataExport/DataExportPayloadBuilder.php` — `stream()` method (no `feedback` section) · `app/Models/Core/Feedback.php`
    - What: `DataExportPayloadBuilder::stream()` yields 22 sections but never queries `core.feedback`. The table stores `reply_email` (user's own contact email), `message` (verbatim text), `kind`, `severity`, `page_url`, and `status` — all GDPR Art. 15 personal data of the data subject. The erasure command `mcp__laravel-boost` already acknowledges these as PII; the disclosure path is missing.
    - Fix: Add `streamFeedback(string $userId)` generator querying `core.feedback WHERE user_id = $userId`. Select user-visible columns only; drop `ip_hash` and `user_agent` following the `streamEnquiries()` redaction pattern. Yield from `stream()` with `'csv_columns' => null`.
    - Models: impl=sonnet · review=sonnet

- [x] **#P1-07** Content-report submissions missing from DSAR export — Lens: `gdpr-export`
    - Where: `app/Services/User/DataExport/DataExportPayloadBuilder.php` — no moderation section · `app/Services/Moderation/ContentReportService.php`
    - What: `moderation.case_signals` stores `reporter_email`, `reason_details` (up to 4 000 chars of freetext), and `signal_data` JSONB per report. The erasure command `moderation:redact-reporter-pii` confirms these as PII (it nulls them on Art. 17 requests) — yet the Art. 15 disclosure path is absent. Reports submitted while logged in set `reporter_user_id`; anonymous reports link via `reporter_email`.
    - Fix: Add `streamContentReports(string $userId, ?string $lookupEmail)` generator querying `moderation.case_signals WHERE reporter_user_id = $userId OR (reporter_user_id IS NULL AND reporter_email = $lookupEmail)`. Drop `reporter_ip_hash`. Yield from `stream()` and `build()`.
    - Models: impl=sonnet · review=sonnet

- [ ] **#P1-08** Video R2 files permanently orphaned if cleanup job exhausts retries after account purge — Lens: `gdpr-deletion`
    - Where: `app/Services/User/AccountDeletionService.php:purgeVideoArtifacts()` · `app/Jobs/DeleteMediaArtifactsJob.php` · `app/Services/Media/VideoVariantService.php:deleteVariants()`
    - What: `purgeVideoArtifacts` dispatches `DeleteMediaArtifactsJob` to the `redis_video` connection. The job has 3 retries with 30 s fixed backoff; if R2 is degraded all three exhaust in under 2 minutes. After exhaustion `failed()` logs but takes no further action, and the only remaining record of the path is in `failed_jobs` (routinely pruned). No DB row remains to locate the R2 object. GDPR Art. 17 requires erasure of all personal media.
    - Fix: Capture each video's `$media->path` in `audit.user_deletion_audit` metadata before `forceDelete()` runs. Add a periodic `gdpr:sweep-orphaned-video-artifacts` command that lists the `videos/` prefix on R2 and cross-references against `site.site_media` (with `withTrashed()`). As an immediate fix, increase `$tries` on `DeleteMediaArtifactsJob` and add exponential backoff `[60, 300, 900]`.
    - Models: impl=sonnet · review=opus

- [ ] **#P1-09** Redis exception during revocation check swallowed by outer JWKS catch — returns 401 for valid sessions — Lens: `jwt-mfa`
    - Where: `app/Http/Middleware/Auth/VerifySupabaseJwt.php:83–88` (revocation check) · `:105–129` (outer catch)
    - What: `isRevoked()` calls `Redis::EXISTS`; a `RedisException` propagates to the outer `try` which logs "JWT JWKS verification failed" (factually wrong) and returns 401 "Invalid token". The immediately-following `setSupabaseContext()` — an identical Redis risk — was correctly given its own inner `try/catch` with fail-open. The revocation call was not. Every authenticated user is locked out for the duration of a Redis outage.
    - Fix: Wrap `$this->revocation->isRevoked($sessionId)` in its own `try/catch(\Throwable)`. On catch: log `['kind' => 'revocation_check_failed']` at `warning` and proceed as if not revoked (fail-open: the token is cryptographically valid; revocation is a best-effort oracle). Mirror the pattern used by the `setSupabaseContext` guard three lines below.
    - Models: impl=sonnet · review=opus

- [ ] **#P1-10** Subdomain retry loop aborts outer signup transaction on PostgreSQL — Lens: `transactions`
    - Where: `app/Services/User/SiteProvisioningService.php:84–106` (`tryCreateSite`) · `app/Services/User/UserBootstrapService.php:42` (outer `DB::transaction`)
    - What: PostgreSQL aborts the entire transaction-level state on any SQL error. `tryCreateSite` catches the `23505 QueryException` at the PHP level but the underlying PostgreSQL transaction is now in an aborted state. Every subsequent SQL call inside the outer `DB::transaction()` returns `25P02` ("current transaction is aborted"), which re-throws, rolls back the signup, and surfaces as an unhandled exception to the controller. The SQLite test suite (used by CI) does not reproduce this because SQLite does not abort transactions on statement errors.
    - Fix: Wrap the body of `tryCreateSite()` in a nested `DB::transaction()` — Laravel translates nested calls to `SAVEPOINT` / `ROLLBACK TO SAVEPOINT`, isolating the unique-violation from the outer transaction. Keep the existing `catch (QueryException $e)` to handle the savepoint rollback. Add a Pest feature test against pgsql (or a stub throwing `23505`) to prevent regression.
    - Models: impl=sonnet · review=opus

- [x] **#P1-11** Handle-availability and login-identifier endpoints open to automated enumeration — Lens: `rate-limiting`
    - Where: `routes/api.php` — `POST /public/signup/availability` · `POST /public/auth/resolve-identifier`
    - What: Both POST routes sit behind only `throttle:public-site` (60 req/min per IP). Every other public mutation (enquiry, subscribe, waitlist, leads) already carries `bot.token:*`. The availability endpoint leaks which handles are taken; the identifier resolver leaks which email addresses have registered accounts — both usable for enumeration and spear-phishing targeting at 3,600 probes/hour from a single source.
    - Fix: Add `bot.token:signup` middleware to `POST /public/signup/availability` and `bot.token:login-identifier` to `POST /public/auth/resolve-identifier`. Effective only once `BOT_PROTECTION_MODE` is set to `shadow` or `enforce` (see #P1-12).
    - Models: impl=haiku · review=sonnet

- [ ] **#P1-12** `BOT_PROTECTION_MODE` defaults to `off` with no production boot guard — all bot protection silently disabled — Lens: `rate-limiting`
    - Where: `config/partna.php:1144` · `app/Http/Middleware/VerifyBotToken.php` (early return on `'off'`) · `app/Providers/BotProtectionServiceProvider.php`
    - What: `VerifyBotToken::handle()` returns `$next($request)` immediately when mode is `'off'`. The default in `config/partna.php` is `env('BOT_PROTECTION_MODE', 'off')` and `.env.example` ships `BOT_PROTECTION_MODE=off`. The existing boot guard only catches `enforce + null driver`; it never checks for `mode=off` in production. Every `bot.token:*` endpoint accepts unlimited bot submissions on any deploy that copies `.env.example` verbatim.
    - Fix: Add a boot guard in `BotProtectionServiceProvider::runBootGuards()`: `Log::warning('bot_protection.mode_off_in_production')` (or throw) when `$env === 'production'` and `$mode === 'off'`. Update `.env.example` to show `BOT_PROTECTION_MODE=shadow` as the recommended deployed value with a comment explaining the three modes.
    - Models: impl=haiku · review=sonnet

- [ ] **#P1-13** Alias 301 redirects discard the request path and query string — breaks every deep link through a renamed handle — Lens: `subdomain-kv`
    - Where: `cloudflare-worker/src/index.js` — alias branch (~line 200–210)
    - What: When the Worker matches an alias entry it constructs `Location: entry.redirect` verbatim. `entry.redirect` is always a bare origin (e.g. `https://newhandle.partna.au` — no path). The incoming request's `url.pathname` and `url.search` are discarded. A visitor bookmarking `/gallery` or `/services?category=photography` at an old handle lands on the new homepage instead of the intended page. `url.pathname` is already in scope in the Worker.
    - Fix: Replace `Location: entry.redirect` with `` Location: `${entry.redirect.replace(/\/$/, '')}${url.pathname}${url.search}` ``. No changes to the Laravel backend required — KV entries always store a bare origin.
    - Models: impl=haiku · review=sonnet

- [ ] **#P1-14** `site.design_kits` has no Row-Level Security — any authenticated user can read all design tokens via Supabase API — Lens: `rls`
    - Where: `supabase/migrations/20260527070000_skeleton_system_cleanup.sql` (table created without `ENABLE ROW LEVEL SECURITY`) · `supabase/config.toml` (`api.schemas` includes `site`)
    - What: Every other tenant-bound table in the `site` schema (`sites`, `blocks`, `site_media`, `services`) has RLS enabled and an owner policy. `design_kits` is the sole exception. The `site` schema is in `api.schemas`, so any valid Supabase JWT can `GET /rest/v1/design_kits` and receive every user's color palettes, typography, button colors, and spacing values.
    - Fix: Add a migration enabling RLS and three policies: owner-full-access (JOIN via `site.sites + core.users WHERE auth_user_id = auth.uid()`), staff-full-access, and anon-SELECT for published sites. Mirror the pattern on `site.sites`. Consider adding `FORCE ROW LEVEL SECURITY` as done for `core.feedback`.
    - Models: impl=opus · review=opus

---

## P2

### P2 — Auth & Security

- [x] **#P2-01** Revocation silently skipped for tokens missing `session_id` claim — Lens: `jwt-mfa`
    - Where: `app/Http/Middleware/Auth/VerifySupabaseJwt.php:82–88`
    - What: The guard `if ($sessionId !== '' && $this->revocation->isRevoked($sessionId))` silently bypasses the blocklist when `session_id` is absent from the JWT. `TokenRevocationService::isRevoked()` also returns `false` early for an empty string. A future token type or service token that omits the claim receives a clean pass through the revocation gate.
    - Fix: Add `Log::warning('jwt.missing_session_id', ['uid' => $uid])` when `$sessionId === ''`. Once logs confirm the case never fires in practice, tighten to `return response()->json(['message' => 'Token missing session_id'], 401)`. Apply the same change to the fallback path's `$fallbackSessionId` check.
    - Models: impl=sonnet · review=opus

- [x] **#P2-02** `RequireAal2` middleware checks only session-level AAL; no `amr`-based freshness window for sensitive staff operations — Lens: `jwt-mfa`
    - Where: `app/Http/Middleware/Auth/RequireAal2.php:22–29` · `app/Policies/BasePolicy.php:69–93`
    - What: Supabase sets `aal=aal2` once per session and never downgrades on token refresh (valid for ~30 days). A staff refresh token minted weeks ago continues passing the AAL2 gate. `BasePolicy::requiresFreshAal2()` already implements `amr`-timestamp freshness checking and is used by `UserSelfPolicy` and `MfaController`. It is not yet applied to the highest-risk staff operations (role changes, account deletion, force-delete, bulk status update).
    - Fix: Identify high-risk staff controller actions and add `$aal2Check = $this->requiresFreshAal2(); if ($aal2Check->denied()) return $aal2Check;` to the corresponding policy methods or inline in controllers following the `MfaController` pattern. Tune the freshness window via `config('partna.mfa.fresh_window_seconds', 300)`.
    - Models: impl=sonnet · review=opus

- [ ] **#P2-03** `StaffUserController` destructive write operations have no policy authorization layer — Lens: `policy`
    - Where: `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php` — `updateStatus()`, `update()`, `destroy()`, `restore()`, `forceDestroy()`, `bulkUpdateStatus()`
    - What: The staff admin route group applies a chain of middleware (`supabase.jwt`, `require.email_verified`, `staff`, `require.aal2`, `staff.admin`, `throttle:staff`, `staff.audit`) but once past that gate no policy method is ever invoked on any write action. `forceDestroy` — a permanent, irreversible operation — has zero defense-in-depth beyond the route middleware. There is no centralized location to add per-ability restrictions (e.g. "support can suspend but not hard-delete") without touching every controller method individually. `CasePolicy` already establishes the `User|PartnaStaff $actor` union-type pattern that is the correct template.
    - Fix: Extend `UserSelfPolicy` (or a new `UserStaffPolicy`) to add staff-facing abilities: `manage(PartnaStaff $actor, User $target): bool { return true; }` with the role-restricted variants for `forceDelete`, `restore`, and `bulkManage`. In each write method resolve `$staff = $request->attributes->get('partna_staff')` and call `$this->authorizeForUser($staff, 'manage', $professional)`.
    - Models: impl=sonnet · review=opus

- [ ] **#P2-04** Any staff role (including `support`) can INSERT/UPDATE/DELETE `core.users` and `site.customers` via Supabase API — Lens: `rls`
    - Where: `supabase/migrations/20260526000000_baseline_standalone_user.sql` — `users_all_authenticated` policy · `customers_all_authenticated` policy
    - What: Both policies check staff membership via a bare `EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid())` in the `WITH CHECK` clause — no role filter. A compromised or rogue `support` account with a direct Supabase JWT can UPDATE any user's profile or DELETE any customer record. `core.partna_staff` itself correctly gates writes on `cs.role = 'admin'`; these two tables do not.
    - Fix: Split each policy into a SELECT policy (any staff) and a write policy (admin-only) by adding `AND cs.role = 'admin'` to the `WITH CHECK` expression. Deliver as a migration using `DROP POLICY ... ; CREATE POLICY ...` pattern.
    - Models: impl=opus · review=opus

- [ ] **#P2-05** `app_backend` holds `BYPASSRLS` with full schema-wide CRUD — no least-privilege scoping beyond three append-only tables — Lens: `rls`
    - Where: `supabase/migrations/20260526000000_baseline_standalone_user.sql` (section 12, role permissions)
    - What: If the Laravel database credentials are compromised, an attacker gains complete unfiltered read-write access to every row in every schema. The only exceptions are the three tables where `UPDATE`/`DELETE` were explicitly revoked (`staff_audit_log`, `handle_change_log`, `auth_factor_events`). This was documented as an intentional architectural decision (decision 16 in the baseline) to keep background jobs simple. The moderation grant migration later gave `app_backend` full CRUD on the `moderation` schema without adding similar revocations.
    - Fix: Short-term: revoke `DELETE` from analytics append tables (`analytics.site_visits`, `analytics.link_clicks`, etc.) and from `notifications.broadcast_email_receipts` — `app_backend` has no delete code paths there. Revoke `UPDATE, DELETE` from `moderation.decisions` and `moderation.action_log`. Long-term: revoke `BYPASSRLS` and replace with explicit TO-app_backend policies per table. Note the long-term fix is L-effort and requires auditing every background job query.
    - Models: impl=opus · review=opus

- [x] **#P2-06** Auth hook signature verification lives in the controller, not middleware — Lens: `webhook`
    - Where: `routes/api.php:24–27` · `app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php:42–44`
    - What: `SupabaseAuthHookController::mfaVerification()` calls `verifySignature()` as its first statement — the current route is secure. The email hook equivalent uses `->middleware('supabase.email-hook')` enforced at the route level, making verification automatic for any new action methods. Any developer adding a second method to `SupabaseAuthHookController` (e.g. `enrollmentAuditHook`) won't automatically get signature verification.
    - Fix: Create a `VerifySupabaseAuthHookSignature` middleware (or register `supabase.auth-hook` alias) mirroring `VerifySupabaseEmailHookSignature`. Attach `->middleware('supabase.auth-hook')` to the MFA verification route. Remove the inline `$this->hookService->verifySignature()` call from the controller (it becomes redundant).
    - Models: impl=sonnet · review=sonnet

- [x] **#P2-07** Auth hook signature failures produce no log output — Lens: `webhook`
    - Where: `app/Services/Auth/SupabaseAuthHookService.php:24–44` · `app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php:42–44`
    - What: `verifySignature()` returns `false` from three distinct branches (missing secret, timestamp out of tolerance, signature mismatch) with no log on any of them. The controller returns 401 silently. `VerifySupabaseEmailHookSignature` logs `supabase.email_hook.signature_failed` with `webhook_id` and `webhook_timestamp` on every mismatch. During an incident (failed secret rotation, active probe), the auth hook path is invisible to Nightwatch.
    - Fix: Add `Log::warning('supabase.auth_hook.misconfigured', ['reason' => 'secret_missing'])` when the secret is empty (return 503, matching email hook), and `Log::warning('supabase.auth_hook.signature_failed', ['webhook_id' => $id, 'webhook_timestamp' => $timestamp])` on mismatch (return 401). Deliver alongside #P2-06 in B12.
    - Models: impl=haiku · review=sonnet

---

### P2 — GDPR & Deletion

- [x] **#P2-08** Export ZIP files not deleted when account is purged — Lens: `gdpr-deletion`
    - Where: `app/Services/User/AccountDeletionService.php:purge()` · `app/Jobs/Gdpr/ExportUserDataJob.php`
    - What: `ExportUserDataJob` writes to `exports/{user_id}/{audit_id}.zip`. The `DataExportAudit` FK is `ON DELETE SET NULL`, so after `User::forceDelete()` the `file_path` column retains the full R2 key but `user_id` becomes null. `purge()` has no step reading `file_path` or calling `Storage::disk(…)->delete(…)`. The ZIP — containing every piece of personal data the system holds — remains in R2 indefinitely, contradicting Art. 17 erasure.
    - Fix: In `purge()`, before `forceDelete()`, query `audit.data_export_audit WHERE user_id = $professional->id AND file_path IS NOT NULL` and delete each `file_path`. Also run `Storage::disk(…)->deleteDirectory("exports/{$professional->id}")` as a catch-all.
    - Models: impl=sonnet · review=opus

- [x] **#P2-09** Waitlist signup entries not deleted when the associated account is purged — Lens: `gdpr-deletion`
    - Where: `app/Services/User/AccountDeletionService.php:purge()` · `supabase/migrations/20260526000000_baseline_standalone_user.sql` (`core.waitlist_signups`)
    - What: `core.waitlist_signups` has no `user_id` column and no FK back to `core.users`; rows are linked only by `email_lc`. `purge()` has no step targeting this table. At purge time `primary_email` is already pseudonymised (`deleted+{id}@partna.au`); the original email must be recovered from `audit.user_deletion_audit.professional_email_snapshot`, using the same pattern `DataExportPayloadBuilder::resolveLookupEmail()` already implements.
    - Fix: In `purge()`, resolve the original email from the audit snapshot. Then: `DB::connection('pgsql')->table('core.waitlist_signups')->where('email_lc', mb_strtolower(trim($originalEmail)))->delete()`.
    - Models: impl=sonnet · review=opus

- [x] **#P2-10** Feedback submissions retain message content and reply email after account deletion — Lens: `gdpr-deletion`
    - Where: `supabase/migrations/20260526210001_create_feedback_table.sql` (FK: `ON DELETE SET NULL`) · `app/Services/User/AccountDeletionService.php:purge()`
    - What: `core.feedback(user_id)` is `ON DELETE SET NULL`. After `User::forceDelete()`, `message`, `reply_email`, `page_url`, `user_agent`, and `ip_hash` remain permanently. `purge()` has no step targeting `core.feedback`. `app_backend` has full CRUD on `core`, so no permission change is needed.
    - Fix: Simplest: change the FK to `ON DELETE CASCADE`. Alternative: add `Feedback::where('user_id', $professional->id)->forceDelete()` to `purge()`.
    - Models: impl=sonnet · review=opus

- [x] **#P2-11** Moderation case signal PII not redacted when the reporter deletes their account — Lens: `gdpr-deletion`
    - Where: `supabase/migrations/20260528000000_create_moderation_schema.sql` (FK: `ON DELETE SET NULL`) · `app/Services/User/AccountDeletionService.php:purge()`
    - What: The FK cascade nulls `reporter_user_id` but leaves `reporter_email`, `reason_details` (up to 4 000 chars freetext), and `signal_data` JSONB intact. The erasure command `moderation:redact-reporter-pii` already acknowledges these as PII and nulls them for Art. 17; the deletion path never calls it. `purge()` has no step touching the `moderation` schema at all.
    - Fix: In `purge()`, query `moderation.case_signals WHERE reporter_user_id = $professional->id` and null out `reporter_email`, `reason_details`, and identifying `signal_data` keys. Retain `reason_code`, `signal_source`, `dedup_hash`, and `case_id` for Trust & Safety analytics.
    - Models: impl=sonnet · review=opus

- [x] **#P2-12** Global `sidest_updates` email subscription not removed when account is purged — Lens: `gdpr-deletion`
    - Where: `app/Services/User/UserBootstrapService.php:ensureSidestUpdatesSubscription()` · `app/Services/User/AccountDeletionService.php:purge()`
    - What: Bootstrap inserts `EmailSubscription` rows with `user_id = null`, keyed by `email_lc`. The FK `ON DELETE CASCADE` only fires for rows where `user_id = $professional->id`; `NULL`-keyed rows survive the cascade and retain the professional's real email. Marketing emails may continue to be dispatched to the deleted user's address.
    - Fix: In `purge()`, after resolving the original email (same pattern as #P2-09), add: `EmailSubscription::query()->whereNull('user_id')->where('list_key', 'sidest_updates')->where('email_lc', $originalEmailLc)->delete()`.
    - Models: impl=sonnet · review=opus

- [x] **#P2-13** Orphaned R2 export zip when post-upload DB write fails — Lens: `gdpr-export`
    - Where: `app/Jobs/Gdpr/ExportUserDataJob.php:handle()` — upload-to-DB-write sequence
    - What: The job uploads the zip to R2, optionally sends the export email, then calls `markCompleted()`. If any step after the R2 upload throws, the catch block calls `markFailed()` and re-throws. The FAILED status blocks future retries via the early-return guard. The R2 object at `exports/{userId}/{auditId}.zip` consumes storage indefinitely; `file_path` is never recorded on the audit row.
    - Fix: Track upload success with `$uploaded = false` set `true` after `$disk->put()`. In the `catch` block, if `$uploaded` is true call `$disk->delete($remotePath)` before `markFailed()`. Alternatively record `file_path` immediately after upload so manual re-send is possible.
    - Models: impl=sonnet · review=sonnet

- [x] **#P2-14** Stale `PROCESSING` status if Horizon worker is killed mid-export — Lens: `gdpr-export`
    - Where: `app/Jobs/Gdpr/ExportUserDataJob.php:41–43` (early-return guard) · `:103–111` (`failed()` hook)
    - What: Laravel's `failed()` callback is invoked after retry exhaustion — NOT after SIGKILL. A SIGKILL between `markProcessing()` and completion leaves the audit row stuck in `PROCESSING` indefinitely. Within the 30-minute dedup window new export requests get 409 "already in progress." After expiry the PROCESSING row is permanent.
    - Fix: Add a scheduled command querying `audit.data_export_audit WHERE status = 'processing' AND created_at < now() - interval '1 hour'` and stamping them `failed` with `error_message = 'stale processing — worker death assumed'`. The 1-hour threshold safely exceeds the job's 600 s timeout.
    - Models: impl=sonnet · review=sonnet

---

### P2 — Cache & Queue

- [ ] **#P2-15** `getByAuthId` uses `rememberLocked` for a nullable callback — deleted-user lookups drain Redis locks on every request — Lens: `cache`
    - Where: `app/Services/Cache/UserCacheService.php:156–159`
    - What: `CacheLockService::rememberLocked` fast-paths on `$cached !== null`. `Cache::get()` returns `null` for both a missing key and a stored null, making the fast path structurally unable to detect a cached null. When `User::find($id)` returns null (deleted user), each subsequent authenticated request acquires a 10-second blocking Redis lock, queries Postgres, stores null, and repeats for the full `auth_id_lookup` TTL (~30 min). The correct method `rememberLockedNullable` is already used on the three surrounding lines.
    - Fix: Replace `$this->cacheLock->rememberLocked(…)` at line 156 with `$this->cacheLock->rememberLockedNullable(…)`, passing `nullTtl: now()->addSeconds(30)` to match the pattern in `getIdByAuthId`.
    - Models: impl=haiku · review=sonnet

- [x] **#P2-16** Analytics and image processing share one supervisor lane — image burst can delay visit recording — Lens: `queue-sat`
    - Where: `config/horizon.php` (`supervisor-analytics`: `queue: ['analytics', 'images']`, `maxProcesses: 2`)
    - What: `ProcessImageVariantsJob` can run for up to 120 s. `RecordAnalyticsEventJob` completes in well under its 30 s timeout (single `insertOrIgnore`). When both worker slots are occupied by image jobs during a gallery upload, analytics events queue for up to 120 s. At a visit spike this degrades dashboard freshness visibly.
    - Fix: Add a `supervisor-images` definition (`connection: redis`, `queue: ['images']`, `timeout: 300`, `maxProcesses: 1`, `nice: 15`). Remove `images` from `supervisor-analytics` and reduce its `maxProcesses` to 1. Update both `production` and `development` environment blocks.
    - Models: impl=haiku · review=sonnet

- [x] **#P2-17** 4 moderation enforcement jobs missing `failed()` handlers — Lens: `queue-jobs`
    - Where: `app/Jobs/Moderation/SuspendSiteJob.php` · `SuspendUserJob.php` · `QuarantineMediaJob.php` · `PurgeModerationCacheJob.php`
    - What: All four jobs mark `ActionLogEntry.status = 'dispatched'` at the start of `handle()` but have no terminal-failure write. On retry exhaustion the audit row stays at `dispatched` forever — active enforcement may have never completed, and no Nightwatch exception fires. A banned user could remain `active` with no alert.
    - Fix: Add `failed(Throwable $e): void` to each job. Call `report($e)` first. Stamp `ActionLogEntry` to `failed`: `ActionLogEntry::query()->where('id', $this->actionLogId)->update(['status' => 'failed', 'failed_at' => now()])`. Include structured `Log::error()` with `action_log_id`, `case_id`, `$e->getMessage()`.
    - Models: impl=sonnet · review=sonnet

- [x] **#P2-18** 4 moderation notification jobs missing `failed()` handlers — Lens: `queue-jobs`
    - Where: `app/Jobs/Moderation/NotifyOnCallStaffJob.php` · `NotifyReportedUserJob.php` · `NotifyReporterJob.php` · `NotifyStaffOfCaseUpdateJob.php`
    - What: Same `ActionLogEntry` stuck-at-`dispatched` gap as #P2-17, but for notification jobs. Reporter outcome emails and staff case-update alerts may never be delivered with no Nightwatch signal and no audit trail update. `NotifyStaffOfCaseUpdateJob` has no `actionLogId` (it's case-level) but still needs `report($e)`.
    - Fix: Add `failed(Throwable $e): void` to all four. Call `report($e)` first. Stamp the `ActionLogEntry` to `failed` in the three jobs that carry `$actionLogId`. Add structured `Log::error()` with `case_id`.
    - Models: impl=sonnet · review=sonnet

- [x] **#P2-19** `RecordAnalyticsEventJob` and `DispatchEnquiryNotificationsJob` missing `failed()` handlers — Lens: `queue-jobs`
    - Where: `app/Jobs/Analytics/RecordAnalyticsEventJob.php` · `app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php`
    - What: A permanently failed `RecordAnalyticsEventJob` silently drops a page-view event with no Nightwatch alert. A permanently failed `DispatchEnquiryNotificationsJob` means no enquiry notification is dispatched for that submission — the professional never receives the email and nobody is paged.
    - Fix: Add `failed(Throwable $e): void` to both jobs with `report($e)` and structured `Log::error()`. Include `$this->payload['event_type']` / `$this->enquiryId` as context.
    - Models: impl=haiku · review=sonnet

- [x] **#P2-20** `ExportUserDataJob::failed()` does not call `report($e)` — Lens: `queue-jobs`
    - Where: `app/Jobs/Gdpr/ExportUserDataJob.php:103–111`
    - What: The `failed()` handler correctly transitions the audit row to `STATUS_FAILED`. However without `report($e)` a permanent export failure creates no Nightwatch exception event — only discoverable by manually querying `DataExportAudit` or checking Horizon's failed-jobs list. Every peer job with a `failed()` method calls `report($e)` first.
    - Fix: Add `report($e);` as the first line of `failed()`.
    - Models: impl=haiku · review=sonnet

- [x] **#P2-21** `ProcessImageVariantsJob::failed()` and `ProcessVideoVariantsJob::failed()` do not call `report($e)` — Lens: `queue-jobs`
    - Where: `app/Jobs/ProcessImageVariantsJob.php` · `app/Jobs/ProcessVideoVariantsJob.php`
    - What: Both `failed()` handlers perform correct local recovery (`markFailed()` + `cleanupR2Artifacts()`) but generate no Nightwatch exception event. A class-wide failure mode (codec issue, storage disk outage) won't surface as an aggregated exception trend until users start complaining.
    - Fix: Add `report($e);` as the first line of `failed()` in both jobs.
    - Models: impl=haiku · review=sonnet

- [ ] **#P2-22** Raw deletion token serialised into Redis job payload — Lens: `queue-jobs`
    - Where: `app/Jobs/Account/SendAccountDeletionRequestMailJob.php` (constructor `string $rawToken`)
    - What: The `rawToken` is a bearer credential — possession allows confirming account deletion. It is serialised into the Redis queue payload via constructor property promotion and lives there for up to `$backoff[2]` (300 s) per retry. Horizon job snapshots and Redis backup/monitoring tools may retain the value beyond the job lifecycle.
    - Fix: In `AccountDeletionService::request()`, pre-compute `$confirmationUrl` and `$tokenHash` before dispatch. Change the job constructor to accept `string $confirmationUrl` and `string $tokenHash` instead of `string $rawToken`. Use `$this->confirmationUrl` directly in `handle()`; use `$this->tokenHash` in `failed()`. The raw credential is removed from the payload entirely.
    - Models: impl=sonnet · review=opus

- [x] **#P2-23** 3 moderation notification jobs run on `default` queue instead of `notifications` — Lens: `queue-jobs`
    - Where: `app/Jobs/Moderation/NotifyReportedUserJob.php` · `NotifyReporterJob.php` · `NotifyStaffOfCaseUpdateJob.php` (each constructor)
    - What: These three jobs default to the `default` queue because their constructors contain no queue assignment. During a wave of site edits (`WarmPublicSiteCacheJob`, `CloudflareCachePurgeJob`), moderation outcome emails queue behind unrelated work. `NotifyOnCallStaffJob` already correctly uses `$this->queue = 'moderation_high'`.
    - Fix: Add `$this->onQueue('notifications');` to each constructor. Use `onQueue()` (not direct property assignment) — these jobs do not use `HasCloudflareRetryPolicy` which causes the PHP 8.4 trait conflict that requires direct assignment.
    - Models: impl=haiku · review=sonnet

- [x] **#P2-24** `DispatchEnquiryNotificationsJob` dispatches on `default` queue instead of `notifications` — Lens: `queue-jobs`
    - Where: `app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php` (constructor)
    - What: The coordinator job that fans out to `SendEnquiryNotificationJob` and `SendEnquiryConfirmationJob` (both of which use `notifications`) competes with cache and CDN work in the `default` lane. During a site-edit burst the coordinator may wait several minutes before dispatching leaf jobs, delaying the entire notification pipeline.
    - Fix: Add `$this->onQueue('notifications');` to the constructor.
    - Models: impl=haiku · review=sonnet

---

### P2 — Media Pipeline

- [x] **#P2-25** Video pipeline leaks file handles when S3 `put()` throws — three sites — Lens: `media`
    - Where: `app/Services/Media/MediaUploadService.php:212–217` · `app/Services/Media/VideoVariantService.php:196–199` (MP4 upload) · `:222–225` (poster upload)
    - What: All three video-path call sites open a stream with `fopen`, pass it to Flysystem, then close it with a post-`put` `if (is_resource($stream)) { fclose($stream); }` guard. If `put()` throws, the guard is never reached. Horizon video-processing workers are long-lived; leaked handles accumulate across jobs until the worker exhausts the OS descriptor limit. The image path (`ImageVariantService::storeOriginal`) already uses `try/finally` for this exact reason.
    - Fix: Wrap all three `fopen` / `Storage::put` call sites in `try/finally` blocks closing the stream unconditionally. Add `if ($stream === false) throw new \RuntimeException(…)` before each `put()` call, matching the image path guard.
    - Models: impl=haiku · review=sonnet

- [x] **#P2-26** Image variant re-processing orphans old variant files when output content hash differs — Lens: `media`
    - Where: `app/Services/Media/ImageVariantService.php` — `processVariants()` (`updateOrCreate` path)
    - What: `processVariants` derives a storage path from a content hash and calls `MediaVariant::updateOrCreate` which updates the DB row to point at the new path. The old file at the previous hash-derived path is never deleted. Under current usage the hash is deterministic (same bytes → same hash → overwrite). The hash changes whenever variant encode parameters change — a quality or dimension config update followed by a re-process run would produce new paths for all affected images, silently accumulating old WebP objects on R2.
    - Fix: Before each `MediaVariant::updateOrCreate`, fetch the existing row's `path` for the `(media_id, variant_key, artifact_type)` tuple. If the stored path differs from the new path, delete the old file from the disk after confirming the new one is stored.
    - Models: impl=sonnet · review=sonnet

- [x] **#P2-27** Video variant re-processing orphans old MP4 and poster files when output hash differs — Lens: `media`
    - Where: `app/Services/Media/VideoVariantService.php:192–231` — MP4 loop and poster upload
    - What: Structurally identical to #P2-26 but for videos. A bitrate or resolution config change followed by a re-process run produces new content-hashed paths while old files remain on R2. At 50–200 MB per video, even a small re-processing run over a few dozen users can orphan gigabytes.
    - Fix: Same pattern as #P2-26: query the existing `MediaVariant` row before each `updateOrCreate`, compare stored `path` with new path, delete the old remote file after confirming the new one is stored. Apply to both the MP4 loop and the poster upload within the same method.
    - Models: impl=sonnet · review=sonnet

- [x] **#P2-28** `ImageVariantService::deleteVariants` silently swallows per-file storage failures, orphaning files on R2 — Lens: `media`
    - Where: `app/Services/Media/ImageVariantService.php:263–275`
    - What: The `foreach` loop calls `$disk->delete($variant->path)` without inspecting the return value or wrapping in try/catch, then unconditionally calls `$variant->delete()`. Files accumulate on the bucket with no application-level reference and no Nightwatch alert. `VideoVariantService::deleteVariants` already handles this correctly: it collects `$failures`, logs at `error` level, and still clears DB rows unconditionally.
    - Fix: Mirror the video pattern: collect per-file failures, log at `error` level with path and error detail, keep the unconditional DB-row delete. Do NOT gate the DB delete on storage success — the codebase's explicit design philosophy is "best-effort on storage, unconditional on DB."
    - Models: impl=haiku · review=sonnet

---

### P2 — Config & Env

- [ ] **#P2-29** `analytics_endpoint` defaults to dev API URL — production profile pages silently report analytics to wrong environment — Lens: `config-secret`
    - Where: `config/partna.php` — `public_profile.analytics_endpoint` · `app/Services/PublicSite/IndividualProfilePayloadBuilder.php:460`
    - What: `env('PARTNA_PUBLIC_ANALYTICS_ENDPOINT', 'https://dev-api.partna.au/api/analytics')` is embedded in every `/api/public/profiles/{handle}` response. In any production environment where the env var is absent, all real visitor analytics beacons fire against `dev-api.partna.au`. Production analytics are lost; dev analytics are polluted. The variable is absent from both `EnvCheckService::REQUIRED` and `EnvCheckService::RECOMMENDED`.
    - Fix: Change the default to derive from `config('app.url')`: `rtrim(config('app.url'), '/').'/api/analytics'`. Add `'partna.public_profile.analytics_endpoint' => 'PARTNA_PUBLIC_ANALYTICS_ENDPOINT'` to `EnvCheckService::RECOMMENDED`.
    - Models: impl=haiku · review=sonnet

- [x] **#P2-30** Generic exception fallback suppresses `abort(4xx, message)` messages in production — Lens: `bootstrap`
    - Where: `bootstrap/app.php:117–131` (the `else` branch of the exception renderer)
    - What: The renderer handles `AccessDeniedHttpException` (403 from policies) explicitly and preserves `$e->getMessage()`. A plain `abort(403, 'reason')` throws a base `HttpException(403)` which is NOT an `AccessDeniedHttpException` and falls to the `else` block where `config('app.debug')` gates the message — returning "An error occurred" in production. CI blocks inline `abort()` in controllers, limiting exposure, but the inconsistency is latent.
    - Fix: In the `else` branch, check whether `$e instanceof HttpException` with status < 500 and has a non-empty message; if so, pass the message through rather than substituting "An error occurred." Alternatively, promote call sites to use the `HttpStatusCodeInterface` domain-exception pattern.
    - Models: impl=sonnet · review=sonnet

---

### P2 — CI & Deploy

- [x] **#P2-31** CI workflow has no explicit `permissions` declaration — Lens: `ci-deploy`
    - Where: `.github/workflows/ci.yml:1` (no `permissions:` key)
    - What: GitHub Actions defaults `GITHUB_TOKEN` to broad write scope for private repositories (`contents: write`, `packages: write`, and others). No current CI step uses the token for writes, but a future step or transitive action would silently inherit write scope without any code change. Explicit `permissions: {}` makes the boundary a code-level decision enforced in the workflow file.
    - Fix: Add `permissions: { contents: read }` at the `jobs.test` level. Add an inline comment: `# Locked to least-privilege — add per-step permissions explicitly if a future step needs the token.`
    - Models: impl=haiku · review=sonnet

- [x] **#P2-32** Direct pushes to `development` bypass all CI checks — Lens: `ci-deploy`
    - Where: `.github/workflows/ci.yml:4–7` (`on:` block)
    - What: The workflow triggers on `push: branches: [main]` and `pull_request: branches: [main, development-v2]`. The active integration branch `development` (deployed to `dev-api.partna.au`) appears in neither list. A direct `git push origin development` or a squash-merge bypassing a PR skips Pint, inline-403 detection, the GS-1 lint, migration guard, `composer audit`, and the full Pest test suite.
    - Fix: Add `development` to the `push:` trigger and to the `pull_request: branches:` list. Separately add a GitHub branch protection rule on `development` requiring the `test` status check to pass.
    - Models: impl=haiku · review=sonnet

---

### P2 — Tenant Isolation

- [x] **#P2-33** `QrCodeController` generates QR codes for unpublished or suspended profiles without any publication check — Lens: `idor`
    - Where: `app/Http/Controllers/Api/PublicSite/QrCodeController.php:16–27` · `routes/web.php:6–8`
    - What: The route carries only `throttle:public-site`. `QrCodeController::svg` resolves any `User` by UUID and checks only that `$professional->partna_url` is non-null. It does not check `site->is_published` or `professional->status`. Every other public-facing data endpoint goes through `PublicSiteResolver::resolvePublishedSite` which requires both `is_published = true` and `status = 'active'`.
    - Fix: Load the professional's Site and add `if (!$site || !$site->is_published) { abort(404); }`. Optionally also check `$professional->status === 'active'`.
    - Models: impl=haiku · review=sonnet

- [x] **#P2-34** `PublicSiteController::showByHeader` reads raw `X-Site-Subdomain` header without length or character validation — Lens: `idor`
    - Where: `app/Http/Controllers/Api/PublicSite/PublicSiteController.php:52–76`
    - What: `show()` passes through `PublicSiteShowRequest` which enforces `max:63` and `regex:/^[a-z0-9-]+$/i`. `showByHeader()` accepts a plain `Request` and performs only a null/is-string guard before passing the value directly into `SiteCacheService::getPublicSitePayload()`. A stream of random 4 KB strings produces a cache-key explosion consuming Redis memory. At 10k-scale with the Worker calling this endpoint on every cache miss, even a small proportion of garbage headers causes measurable cache churn.
    - Fix: Before the `strtolower(trim(…))` call, add `strlen($subdomain) > 63 → 400` and `preg_match('/^[a-z0-9-]+$/i', $subdomain) || 400`. Extract the shared validation into a private method reused by both `show()` and `showByHeader()`.
    - Models: impl=haiku · review=sonnet

- [ ] **#P2-35** `ResolvesSubdomainFromHost` trusts `X-Site-Subdomain` first, enabling cross-tenant lead and enquiry injection — Lens: `idor`
    - Where: `app/Http/Controllers/Concerns/ResolvesSubdomainFromHost.php:16–21`
    - What: `resolveSiteSubdomain()` checks `X-Site-Subdomain` first and returns immediately if non-empty, with no cross-check against the Host header. Cloudflare Workers pass through unknown custom headers by default. A direct HTTP request to `api.partna.au` with `X-Site-Subdomain: victim` can inject leads, enquiries, and email subscriptions into any professional's account — the subdomain is public, so no secret knowledge is required.
    - Fix: **Infrastructure fix (preferred):** Configure the Cloudflare Worker to explicitly strip any client-supplied `X-Site-Subdomain` header before forwarding, then inject the Worker-resolved value. **Application-layer fallback:** Prefer host-derived resolution; accept the header only when the Host yields no recognisable subdomain. Add a comment documenting which layer is responsible for stripping.
    - Models: impl=sonnet · review=opus

---

### P2 — Race Conditions & Idempotency

- [x] **#P2-36** Check-then-insert race in bootstrap email subscription creation — Lens: `transactions`
    - Where: `app/Services/User/UserBootstrapService.php:130–153`
    - What: `ensureSidestUpdatesSubscription` SELECT-then-INSERT for the `sidest_updates` subscription. Concurrent bootstraps from the same auth user (two-tab double-submit) can both pass the existence check and collide on the `email_subscriptions_unique_global_list_email_lc` index. The `23505 QueryException` propagates out of the outer `DB::transaction()`, rolls back the entire signup, and surfaces as an unhandled exception to the controller.
    - Fix: Replace the SELECT + INSERT with `DB::table('notifications.email_subscriptions')->insertOrIgnore([…])`, which generates `INSERT … ON CONFLICT DO NOTHING` — single atomic instruction, never errors on a duplicate.
    - Models: impl=sonnet · review=sonnet

- [x] **#P2-37** Race condition in moderation case take/decide — status guard runs outside transaction — Lens: `transactions`
    - Where: `app/Services/Moderation/ModerationCaseService.php:63–65` · `ModerationDecisionService.php:47–49`
    - What: Both `take()` and `decide()` read `$case->status` from the in-memory model before entering the transaction. Two concurrent staff members acting on the same case at the same instant both pass the guard; the second hits the state machine `IllegalCaseTransition` (422) rather than the intended `CaseAlreadyTaken` / `CaseAlreadyResolved` (409).
    - Fix: In both methods, move the status guard inside the `DB::transaction` callback after re-loading with `ModerationCase::query()->lockForUpdate()->findOrFail($case->id)`. The locked re-fetch makes the read-check-transition atomic.
    - Models: impl=sonnet · review=sonnet

- [ ] **#P2-38** Missing idempotency guard in `SendAccountDeletionRequestMailJob` — Lens: `transactions`
    - Where: `app/Jobs/Account/SendAccountDeletionRequestMailJob.php:44–60`
    - What: The job has `$tries = 3`, `$backoff = [30, 120, 300]`. A crash between SMTP acceptance and job completion causes retries that re-send the deletion confirmation email. Every other transactional notification job uses `lockForUpdate + *_sent_at` to guard this path; this job is the sole exception.
    - Fix: Add a `deletion_mail_sent_at TIMESTAMPTZ` column to `core.users` via a Supabase migration. In `handle()`, open a `DB::transaction()`, re-fetch with `lockForUpdate()`, verify `deletion_token_hash` matches and `deletion_mail_sent_at IS NULL`; return early if not. Stamp `deletion_mail_sent_at = now()` after `Mail::to()->send()` succeeds.
    - Models: impl=sonnet · review=opus

---

### P2 — Schema & Migration

- [ ] **#P2-39** `CREATE INDEX` inside `BEGIN/COMMIT` in the enquiry inbox migration — blocks writes for the full build duration on non-empty tables — Lens: `migration`
    - Where: `supabase/migrations/20260527160000_enquiry_inbox.sql:1–4` (guard disabled) · `:76–84` (three `CREATE INDEX` statements)
    - What: The migration disables the unsafe-migrations lint with the rationale that `site.enquiries` was empty at migration time. `CREATE INDEX` without `CONCURRENTLY` acquires `ACCESS EXCLUSIVE` for the full build duration. This is currently safe (pre-beta, empty tables) but becomes a live-traffic hazard on any environment with a production-data snapshot (staging restore, future `db reset` after pilot launch).
    - Fix: Move the three `CREATE INDEX` statements into a sibling file `20260527160001_enquiry_inbox_indexes.sql` with no transaction wrapper. Add `CONCURRENTLY` to each. Leave FK constraints, column additions, and the DML backfill `UPDATE` inside the original `BEGIN/COMMIT`.
    - Models: impl=opus · review=opus

---

### P2 — Security Headers

- [x] **#P2-40** Web-route exception responses bypass `SecureHeaders` — the QR-code 404/500 ships un-headered HTML — Lens: `security-headers`
    - Where: `bootstrap/app.php:88–91` (exception render gate) · `routes/web.php:6–8` · `app/Http/Controllers/Api/PublicSite/QrCodeController.php:25–27`
    - What: The `withExceptions` render closure returns `null` for non-API routes, delegating to Laravel's default HTML renderer which carries no `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, CSP, or HSTS header. The only non-API route is the QR code SVG; every `abort(404)` from that controller produces an un-headered response.
    - Fix: Remove or restructure the `if (! $request->is('api/*')) { return null; }` early return so `SecureHeaders::apply()` is called for non-API error responses. Build a minimal error response and pass it through `SecureHeaders::apply($response, $request)` before returning.
    - Models: impl=sonnet · review=sonnet

- [x] **#P2-41** Exception-path API responses carry no `Cache-Control` — browsers may heuristically cache 4xx errors — Lens: `security-headers`
    - Where: `bootstrap/app.php:87–158` (exception render closure) · `app/Http/Middleware/AddPublicCacheHeaders.php:32–68`
    - What: When a controller throws, `AddPublicCacheHeaders::handle()` post-response logic never executes. The exception handler constructs `response()->json(…)` with no `Cache-Control` header and only calls `SecureHeaders::apply()`. RFC 7234 allows browsers to heuristically cache responses without explicit directives. A 404 from a freshly-created resource, or a 403 from a state that has since changed, could be replayed from browser cache. Authenticated responses are particularly affected: the `private, no-store` guard that `AddPublicCacheHeaders` normally sets for `Authorization`-bearing requests is absent on error responses.
    - Fix: Inside the `withExceptions` render closure, immediately before `SecureHeaders::apply()`, add `$response->headers->set('Cache-Control', 'private, no-store, max-age=0'); $response->headers->set('Pragma', 'no-cache');`. (Note: `AddPublicCacheHeaders::mergeVary()` is `private` and cannot be called from here — use direct `headers->set()`.)
    - Models: impl=haiku · review=sonnet

---

### P2 — Rate Limiting & CORS

- [x] **#P2-42** Public document download throttled only by global IP bucket — no per-document rate control — Lens: `rate-limiting`
    - Where: `routes/api.php` (`GET /public/documents/{document}/download`)
    - What: The route uses `whereUuid('document')` preventing random enumeration, but a legitimately obtained UUID has unlimited download throughput within the 60/min IP window. The controller 302-redirects to a short-TTL R2 presigned URL, so cost is in R2 egress rather than Laravel CPU.
    - Fix: Add a `document-download` rate limiter in `AppServiceProvider::configureRateLimiting()` keyed on `$request->ip().':doc:'.$request->route('document')` (e.g., 10/UUID/IP/hour). Apply `->middleware('throttle:document-download')` alongside `throttle:public-site`.
    - Models: impl=haiku · review=sonnet

- [x] **#P2-43** Dead Shopify CORS patterns remain after the standalone strip — unnecessary allowlist expansion — Lens: `rate-limiting`
    - Where: `config/cors.php` (`allowed_origins_patterns` array)
    - What: The two Shopify patterns (`#^https://admin\.shopify\.com$#i` and `#^https://[a-z0-9-]+\.myshopify\.com$#i`) were not removed during the 2026-05-22 standalone strip. No Shopify webhook handlers or embedded-app controllers exist in `app/`; `supports_credentials: false` prevents credential forwarding but the allowlist still permits Shopify-embedded scripts to read unauthenticated API responses and trigger preflight-free simple requests.
    - Fix: Remove the two Shopify pattern entries from `allowed_origins_patterns`. Verify `SecureHeaders::originAllowed()` (which reads the same array) passes CI after removal.
    - Models: impl=haiku · review=sonnet

- [ ] **#P2-44** [@10k] Shared `public-site` rate limiter creates noisy-neighbour degradation across unrelated endpoints — Lens: `rate-limiting`
    - Where: `app/Providers/AppServiceProvider.php` (`configureRateLimiting()`) · all `throttle:public-site` registrations in `routes/api.php` and `routes/web.php`
    - What: A single 60 req/min IP bucket is shared by `POST /public/signup/availability`, `POST /public/auth/resolve-identifier`, `GET /public/site`, `GET /public/documents/…/download`, config endpoints, and the QR code endpoint. Abusive traffic on any one of those consumes rate-limit budget for all the others from the same corporate NAT or shared-egress IP — at 10k site pages this affects legitimate visitors at much higher frequency.
    - Fix: Create a dedicated `public-signup` limiter (e.g. 10/min/IP) for the two POST endpoints. Create a `public-auth` limiter for the identifier resolver. Keep `public-site` for read-only GETs. Mutation endpoints (enquiry, subscribe, waitlist, leads) already have their own limiters and are unaffected.
    - Models: impl=sonnet · review=sonnet

---

### P2 — KV & Routing

- [ ] **#P2-45** Professional deletion leaves the KV routing entry permanently live — `RetireSubdomainFromKvJob` is a dead class with zero dispatch sites — Lens: `subdomain-kv`
    - Where: `app/Jobs/Cloudflare/RetireSubdomainFromKvJob.php` (dead class) · `app/Observers/User/UserObserver.php:deleted` (missing dispatch) · `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php` (contradictory comment: "genuine deletes go through RetireSubdomainFromKvJob, NOT this job")
    - What: `UserObserver::deleted` calls only `$this->userCache->invalidateUser(…)` — no KV dispatch. `SyncSubdomainToKvJob::handle()` returns early when `User::find()` is null (soft-deleted models excluded). No code path calls `CloudflareKvService::delete()` on a deleted professional's handle. The weekly `backfill-subdomain-kv` cron is the only recovery, giving a worst-case 7-day window of stale KV routing. Old handles also cannot be cleanly reclaimed by new users during this window.
    - Fix: Delete `RetireSubdomainFromKvJob.php` (zero dispatch sites, made obsolete). In `UserObserver::deleted`, dispatch a new `DeleteSubdomainFromKvJob` (or modify `SyncSubdomainToKvJob` to handle null user by calling `$kv->delete($capturedHandle)`). Remove the contradictory comment from `SyncSubdomainToKvJob`.
    - Models: impl=sonnet · review=opus

---

### P2 — Scheduler

- [x] **#P2-46** Five daily `Schedule::command()` tasks missing `->runInBackground()` — block co-scheduled tasks in the same tick — Lens: `scheduler`
    - Where: `routes/console.php:27` (`partna:purge-soft-deletes`) · `:46` (`partna:analytics:purge-raw-events`) · `:54` (`queue:prune-failed`) · `:35` (`partna:prune-notifications`) · `:114` (`feature-flags:prune-expired`)
    - What: Without `->runInBackground()`, `Schedule::command()` runs the child process synchronously inside the per-minute `schedule:run` invocation. The 03:20 `purge-soft-deletes` (with a 600-minute overlap lock) shares a tick with `keep-alive-ping` — a delayed keep-alive could allow a pod park on Laravel Cloud. The project's own scheduler conventions header explicitly requires `->runInBackground()` for daily tasks.
    - Fix: Add `->runInBackground()` to all five entries. Note: `Schedule::job()` entries (`AggregateCacheMetricsJob`, `CheckStreamingLiveStatusJob`) are already exempt — they dispatch to Horizon immediately.
    - Models: impl=haiku · review=sonnet

- [x] **#P2-47** Two daily tasks use bare `withoutOverlapping()` — risk of missed run after a crash — Lens: `scheduler`
    - Where: `routes/console.php:98` (`handles:prune-expired-aliases`) · `:116` (`feature-flags:prune-expired`)
    - What: `withoutOverlapping()` with no argument defaults to 1440-minute TTL in Laravel's `CacheEventMutex`. For a `dailyAt` task, if the job is SIGKILL'd the mutex persists for exactly 24 hours — clock skew of a few seconds or a slightly extended runtime on the following day is enough to find the mutex still held and silently skip the run.
    - Fix: Replace bare `->withoutOverlapping()` with `->withoutOverlapping(120)` on `handles:prune-expired-aliases` (2× expected runtime) and `->withoutOverlapping(30)` on `feature-flags:prune-expired` (the command completes in seconds; 30 min is ample).
    - Models: impl=haiku · review=sonnet

---

### P2 — Write Amplification `[@10k]`

- [x] **#P2-48** [@10k] `ServiceCategoryObserver` touch cascade defeats its own fine-grained key optimization — Lens: `write-amp`
    - Where: `app/Observers/Core/ServiceCategoryObserver.php:bust()`
    - What: `bust()` deliberately deletes only 4 keys (professionalDashboardServices, professionalServices, primary+stale) to avoid the full 29-key `invalidateUser()` sweep. It then calls `$pro?->site?->touch()` to fire `SiteObserver::saved` → `invalidateSite()`. Since commit `a0a35444`, `invalidateSite()` also busts `emailBrand + :stale` and `professionalModel + :stale` — neither stale after a category rename. The optimization is partially undone on every category change.
    - Fix: Replace `$pro?->site?->touch()` with a direct call to `SiteCacheService::invalidateSite($pro->site)` plus `CloudflareCachePurgeJob::dispatch($pro->site->subdomain)`. This gives precise control and prevents future additions to `invalidateSite()` from silently expanding the blast radius.
    - Models: impl=sonnet · review=sonnet

- [x] **#P2-49** [@10k] `UserObserver` double-invalidates site cache when public profile fields change — Lens: `write-amp`
    - Where: `app/Observers/User/UserObserver.php:updated()` · `app/Services/Cache/UserCacheService.php:invalidateUser()`
    - What: `UserObserver::updated` calls `invalidateUser($professional)` unconditionally. At `invalidateUser()`'s tail, a "conservative catch-all" calls `invalidateSite($professional->site)` (~29 Redis DELs). When any `PUBLIC_PROFILE_USER_FIELDS` column changed, `touchParentSiteIfPublicFieldChanged` fires → `site?->touch()` → `SiteObserver::saved` → `invalidateSite()` again. The same ~29 keys are deleted twice in sequence. `ShouldBeUnique` on `CloudflareCachePurgeJob` prevents CF API duplication; the Redis DEL amplification is unmitigated.
    - Fix: Add `bool $bustSite = true` parameter to `invalidateUser()`. In `UserObserver::updated`, pass `bustSite: false` when `wasChanged(PUBLIC_PROFILE_USER_FIELDS)` is true (because `touchParentSite` will handle the site bust), and the default `bustSite: true` otherwise.
    - Models: impl=sonnet · review=sonnet

- [x] **#P2-50** [@10k] `ServiceObserver` always double-invalidates site cache on every service mutation — Lens: `write-amp`
    - Where: `app/Observers/Core/ServiceObserver.php:runHooks()`
    - What: `runHooks` calls `bust($service)` → `invalidateUser($pro)` (including `invalidateSite()` tail, ~29 DELs), then calls `touchParentSite($service, $pro)` → `site?->touch()` → `SiteObserver::saved` → `invalidateSite()` again. This happens on every `saved`, `deleted`, and `restored` event with no conditional gate — toggling `is_active`, updating a price, or changing a title all produce the same double sweep. Service edits are the most frequent mutation in the app.
    - Fix: Using the `bustSite` flag from #P2-49: in `ServiceObserver::bust()`, call `$this->userCache->invalidateUser($pro, bustSite: false)`. The `touchParentSite()` call that always follows handles the single correct site bust. Add an integration test asserting `invalidateSite()` fires exactly once per service save.
    - Models: impl=sonnet · review=sonnet

- [x] **#P2-51** [@10k] All 18 image-view cache variants busted on every `invalidateSite()` regardless of mutation type — Lens: `write-amp`
    - Where: `app/Services/Cache/SiteCacheService.php:invalidateSite()` · `app/Services/Cache/CacheKeyGenerator.php:siteImagesViewVariants()`
    - What: `invalidateSite()` iterates `siteImagesViewVariants()` — 3 pools × 3 media types = 9 combinations × 2 (primary + `:stale`) = 18 Redis DELs. A service title edit, block reorder, bio change, or category rename does not mutate any `site_media` row. Combined with #P2-49 and #P2-50, a single service edit can incur 36 wasted image-view DELs. `SiteMediaObserver`'s path correctly busts image keys (images changed); all other observer paths should not.
    - Fix: Split `invalidateSite()` into `invalidateSitePayload(Site $site)` (payload+stale, blocks+stale, emailBrand+stale, professionalModel+stale) and `invalidateSiteImages(Site $site)` (the 18 image-view keys). Keep `invalidateSite()` as a wrapper calling both. Callers that know images are unaffected call `invalidateSitePayload()` directly.
    - Models: impl=sonnet · review=sonnet

---

### P2 — API Resources

- [x] **#P2-52** Moderation resources extend `JsonResource` instead of `ApiResource` — `id` not cast to string — Lens: `resources`
    - Where: `app/Http/Resources/Moderation/CaseResource.php` · `CaseSignalResource.php` · `DecisionResource.php` · `EvidenceResource.php` · `CaseDetailResource.php`
    - What: All five moderation resources bypass the project's Resource contract: `ApiResource` mandates `id` be cast to string and participates in any future base-class enhancements. UUIDs happen to serialise as strings today, but strict-typed TS consumers (Zod discriminated-union assertions) and any future int-keyed table would break silently.
    - Fix: Change `extends JsonResource` to `extends ApiResource` in all five. Cast `id` to `(string) $this->id` in each `toArray()`.
    - Models: impl=haiku · review=sonnet

- [x] **#P2-53** Raw Carbon instances returned from controller-side payload builders — Lens: `resources`
    - Where: `app/Http/Controllers/Api/User/Account/UserDocumentController.php` (`buildDocumentPayload`) · `app/Http/Controllers/Api/User/Uploads/UserUploadController.php` (`buildMediaPayload`)
    - What: Both controllers return `$media->created_at` and `$media->updated_at` as Carbon instances rather than ISO-8601 strings. Every `ApiResource` subclass calls `->toIso8601String()` to pin the wire format. A future `$dateFormat` model-cast or `date_serialization_format` config change would silently shift the timestamp format for these two endpoints.
    - Fix: Replace `$media->created_at` / `$media->updated_at` with `$media->created_at?->toIso8601String()` / `$media->updated_at?->toIso8601String()` in both payload builders.
    - Models: impl=haiku · review=sonnet

- [x] **#P2-54** Staff endpoints return raw Eloquent models — Lens: `resources`
    - Where: `app/Http/Controllers/Api/Staff/StaffSite/StaffMeController.php:15` · `app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationController.php:182`
    - What: `StaffMeController::show()` returns `$request->attributes->get('partna_staff')` — the `PartnaStaff` Eloquent model — directly. `StaffNotificationController::store()` returns the created `Notification` model unfiltered. Any new column added to either model automatically ships to every staff browser session without review. `NotificationListingResource` already exists and is used for every other notification response.
    - Fix: Create `PartnaStaffResource extends ApiResource` with an explicit `id/name/primary_email` allowlist. Use it in `StaffMeController`. Wrap the `store()` response in `NotificationListingResource`.
    - Models: impl=sonnet · review=sonnet

- [x] **#P2-55** Feature flag list endpoints return non-standard pagination envelope — Lens: `resources`
    - Where: `app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagController.php:25` · `StaffFeatureFlagOverrideController.php:24`
    - What: Both use `Resource::collection($paginator)->response()` which produces Laravel's default envelope (`data`, `links`, `meta.from/to/path/links`). Every other paginated staff endpoint uses `ReturnsPaginatedResponse::paginatedResponse()` producing `{ "<named_key>": [...], "meta": { "current_page", "per_page", "total", "next_page_url", "prev_page_url" } }`. Frontend consumers written against the project standard will misread or fail silently.
    - Fix: Add `use ReturnsPaginatedResponse;` to both controllers. Replace `Resource::collection($paginator)->response()` with `$this->success($this->paginatedResponse($paginator, 'flags'))` / `'overrides'`.
    - Models: impl=haiku · review=sonnet

---

### P2 — Scale & Throughput

- [ ] **#P2-56** [@10k] Subscriber CSV exports stream an unbounded cursor with no row cap — Lens: `n1`
    - Where: `app/Http/Controllers/Api/User/Notifications/UserEmailSubscriptionController.php` (`export`) · `app/Http/Controllers/Api/Staff/StaffSite/StaffEmailSubscriberController.php` (`export`)
    - What: Both controllers build a query and pass it to `$query->cursor()` inside `response()->streamDownload()`. The loop runs until every matching row is consumed with no `->limit()` and no timeout. At 50k+ subscribers a single export holds a PHP-FPM worker slot for tens of seconds; a small number of concurrent exports exhausts the pool and delays every other request on the host.
    - Fix: Add `->limit(config('partna.export.max_rows', 50_000))` before the cursor call. Set a `X-Export-Truncated: 1` response header (computable before streaming begins) when the result set equals the cap.
    - Models: impl=haiku · review=sonnet

---

### P2 — Observability

- [ ] **#P2-57** `AnalyticsQueryService` silently swallows click-table `QueryException` across four sites — Lens: `observability`
    - Where: `app/Services/Analytics/AnalyticsQueryService.php:88` · `:118` · `:223` · `:257`
    - What: Four `catch (QueryException)` blocks return zero/empty defaults with no log. The resilience is intentional (SQLite test environments). However production failures — a missing `analytics.link_clicks` table, a schema mismatch — are indistinguishable from "no clicks today." If the click pipeline breaks overnight, the dashboard shows zero for every user with no Nightwatch signal.
    - Fix: Change to `catch (QueryException $e)`. Add `Log::warning('analytics.click_query_failed', ['method' => __METHOD__, 'user_id' => $userId, 'error' => $e->getMessage()])` before each `return`. Keep the zero/empty defaults — resilience is correct; observability was missing.
    - Models: impl=haiku · review=sonnet

- [ ] **#P2-58** `StaffAnalyticsController` silently swallows the same click-table failures — same root cause, same tier — Lens: `observability`
    - Where: `app/Http/Controllers/Api/Staff/StaffSite/StaffAnalyticsController.php:106` · `:134` · `:151`
    - What: Structurally identical to #P2-57. The three inline click-analytics query blocks duplicate `AnalyticsQueryService` logic (see #P3-24 for the refactor item) but without any logging fix once #P2-57 lands on the service. Staff investigating zero-click analytics for a user cannot distinguish genuine absence from a broken query.
    - Fix: Change `catch (Throwable)` to `catch (Throwable $e)`. Add `Log::warning('staff.analytics.click_query_failed', ['professional_id' => $professional->id, 'error' => $e->getMessage()])` in each block.
    - Models: impl=haiku · review=sonnet

- [ ] **#P2-59** `UserCacheService::getByAuthId` silently repairs a stale auth mapping without logging — Lens: `observability`
    - Where: `app/Services/Cache/UserCacheService.php:166`
    - What: The code correctly self-heals when `$professional->auth_user_id !== $authUserId` (stale or corrupted cache key). However no log is emitted. `auth_user_id` is documented as immutable — any mismatch here is abnormal. A recurrent invalidation bug firing this branch on every request would go undetected until someone manually compares cache state against the database.
    - Fix: Add `Log::warning('cache.auth_id_mismatch', ['cached_user_id' => $id, 'auth_user_id' => $authUserId])` immediately before `Cache::forget($authIdKey)`. IDs are UUIDs — no PII exposure.
    - Models: impl=haiku · review=sonnet

---

## P3

### P3 — Account Capabilities & Bootstrap

- [ ] **#P3-01** `notification_categories` capability declared but enforcement mechanism absent — docblock implies a contract that is never enforced — Lens: `account-caps`
    - Where: `app/Services/Accounts/AccountCapabilitySet.php:18–34` · `app/Services/Accounts/AccountCapabilities.php:44`
    - What: The docblock states `notification_categories: 'profile,platform'` restricts which email categories an account may receive. No code path enforces this. `CAPABILITY_GATE_MAP = []` (intentionally empty for standalone model) is the actual gating mechanism. The field is a forward-declaration for multi-account-type re-integration — risk is a future developer misreading the docblock and assuming enforcement is wired.
    - Fix: Add inline comments clarifying that the enforcement mechanism is `CAPABILITY_GATE_MAP` (not this field), and that `CAPABILITY_GATE_MAP = []` is intentionally empty for the standalone-individual model. Optionally add `@internal` PHPDoc tag.
    - Models: impl=haiku · review=sonnet

- [x] **#P3-02** `AppServiceProvider` boot guards cover six misconfigurations but not `APP_DEBUG=true` — Lens: `bootstrap`
    - Where: `app/Providers/AppServiceProvider.php:94–140`
    - What: The exception renderer gates full exception messages on `config('app.debug')`. If `APP_DEBUG=true` ships to production, every uncaught exception leaks raw Laravel exception text, file paths, and status codes to API consumers until noticed. Six existing guards cover JWKS, JWT, throttle, Nightwatch, email hook secret, and public domain. A seventh for debug mode follows the same one-liner pattern.
    - Fix: Add `if (app()->isProduction() && config('app.debug')) { throw new \RuntimeException('APP_DEBUG must be false in production.'); }` after the existing guard block.
    - Models: impl=haiku · review=sonnet

---

### P3 — Cache Layer

- [x] **#P3-03** `siteImagesViewVariants` hardcodes the filter space with no enforcement link to the controller's allowlists — Lens: `cache`
    - Where: `app/Services/Cache/CacheKeyGenerator.php:110–123` · `app/Http/Controllers/Api/User/Uploads/UserUploadController.php:109,114`
    - What: `siteImagesViewVariants()` hardcodes `[null, 'gallery', 'content'] × ['image', 'video', 'all']`. `UserUploadController::index` validates the same allowlists via inline `in_array`. The two lists currently match but are maintained by comment only. Adding a new pool value or media type to the controller without updating `siteImagesViewVariants` leaves stale gallery data visible until TTL expiry.
    - Fix: Extract the accepted values into shared constants (e.g. `SiteMedia::GALLERY_POOLS`, `SiteMedia::MEDIA_TYPE_FILTERS`) and reference them from both call sites. Add a unit test asserting `siteImagesViewVariants()` returns exactly the Cartesian product of those constants.
    - Models: impl=haiku · review=sonnet

- [x] **#P3-04** `MISS_SENTINEL` TTL writes skip the `JitteredTtl` trait used everywhere else in the cache layer — Lens: `cache`
    - Where: `app/Services/Cache/SiteCacheService.php:93–95`
    - What: The negative-cache path uses bare `now()->addSeconds(self::MISS_PRIMARY_TTL_SECONDS)`, bypassing jitter. `MISS_PRIMARY_TTL_SECONDS = 30` means all MISS_SENTINEL entries written in a burst (e.g., a bot scanner requesting bogus subdomains) expire at exactly the same wall-clock second, producing a synchronised wave of DB lookups. The `JitteredTtl` trait is already on the class; `self::applyJitter()` is callable — the fix is a two-line change.
    - Fix: Replace the two `now()->addSeconds(...)` calls with `self::applyJitter(self::MISS_PRIMARY_TTL_SECONDS)` and `self::applyJitter(self::MISS_PRIMARY_TTL_SECONDS * self::PAYLOAD_STALE_TTL_MULTIPLIER)`.
    - Models: impl=haiku · review=sonnet
    - Resolution (2026-06-03): ALREADY SATISFIED — premise stale. `SiteCacheService` already writes both MISS_SENTINEL keys via `self::applyJitter(...)` (now lines 69–70, not 93–95); zero `now()->addSeconds` remain in the file. Verified by independent sonnet review; no code change made.

---

### P3 — Config & Secret Hygiene

- [ ] **#P3-05** `VideoVariantService::extractPoster()` logs raw ffmpeg stdout+stderr including server temp-file paths — Lens: `config-secret`
    - Where: `app/Services/Media/VideoVariantService.php` — `extractPoster()` method
    - What: `Log::warning('VideoVariantService: could not extract poster frame…', ['error' => $outputStr])` logs the full combined stdout+stderr including temp-file paths (`/tmp/sidest_poster_XXXX.jpg`), revealing the server's temp-file naming convention and directory layout to anyone with log access.
    - Fix: Replace `'error' => $outputStr` with `'exit_code' => $exitCode, 'error_summary' => substr($outputStr, 0, 200)`. Exit code and a truncated first line are sufficient to diagnose failure type.
    - Models: impl=haiku · review=sonnet

---

### P3 — CI & Deploy

- [x] **#P3-06** `--force` in `post-update-cmd` silently overwrites customised published assets on every `composer update` — Lens: `ci-deploy`
    - Where: `composer.json:82–84`
    - What: `post-update-cmd` fires on every `composer update` or `composer require`. `--force` causes `artisan vendor:publish` to silently overwrite any customised published file. Production deploys use `composer install` (not `update`) so production is unaffected. Blast radius is local development: a developer who tunes a published config then adds a new package loses those changes without warning.
    - Fix: Remove `--force` from the `post-update-cmd` entry. Without it, `vendor:publish` skips files that already exist.
    - Models: impl=haiku · review=sonnet

- [x] **#P3-07** `composer.json` PHP floor `^8.2` contradicts the `openspout` production dependency — Lens: `ci-deploy`
    - Where: `composer.json` (`"php": "^8.2"`) · `composer.lock` (`openspout/openspout` v5.7.0 requires `~8.4.0 || ~8.5.0`)
    - What: CI runs PHP 8.4 so the lock is consistent and CI passes. Any developer or future CI matrix runner on PHP 8.2/8.3 hits a platform-requirement error with no explanation from `composer.json`. The manifest's `^8.2` claim is a false promise.
    - Fix: Update `"php": "^8.2"` to `"^8.4"` in `composer.json`'s `require` block. Run `composer update --lock`.
    - Models: impl=haiku · review=sonnet

- [x] **#P3-08** GS-1 cache-discipline allowlist retains 10 stale path exclusions from the standalone strip-down — Lens: `ci-deploy`
    - Where: `.github/workflows/ci.yml` (`No raw Cache::* calls outside cache services (GS-1)` step)
    - What: Ten `:!path` exclusions reference files confirmed deleted in the 2026-05-22 standalone strip (Shopify/commerce controllers, observers, services). Deleted files can't fail the lint. The forward risk: when commerce is reintegrated (planned), these paths would automatically be on the "approved exceptions" list without review — raw `Cache::` calls would bypass the GS-1 lint silently.
    - Fix: Remove the 10 stale exclusions. Confirm `app/Observers/Core/CustomerObserver.php` (the one surviving exception) still legitimately uses raw `Cache::` and add a brief inline justification comment.
    - Models: impl=haiku · review=sonnet

---

### P3 — Tenant Isolation

- [ ] **#P3-09** `StaffNotificationController::store` uses inline validation with no UUID existence check — Lens: `idor`
    - Where: `app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationController.php:25–36`
    - What: The `user_id` rule is `['nullable', 'uuid']` — UUID-format only, no `exists:core.users,id`. A staff operator who mistypes a UUID receives a 201 but the notification exists only as a phantom row. `SendTransactionalNotificationEmailJob` dispatch can fire for a non-existent `user_id`, causing a quiet job failure.
    - Fix: Extract validation to `StoreStaffNotificationRequest`. Add `Rule::exists('core.users', 'id')` to the `user_id` rule. Add a `exists:` rule or `Rule::in([…])` for `category`.
    - Models: impl=haiku · review=sonnet

- [ ] **#P3-10** `PublicSignupAvailabilityController` exposes a second email-enumeration channel via the Supabase orphan-recovery check — Lens: `idor`
    - Where: `app/Http/Controllers/Api/PublicSite/PublicSignupAvailabilityController.php:47–58`
    - What: The route throttle limits volume at the route level. The Supabase admin API call (`$this->supabaseAdmin->findUserByEmail($email)`) on the orphan-check branch has no dedicated sub-limit. An attacker hitting the endpoint at exactly the throttle rate triggers one admin API call per request, probing the Supabase auth database for registered email addresses systematically.
    - Fix: Apply a per-IP sub-rate-limit on the orphan-check branch (e.g. 5 attempts/IP/60 s using a Redis increment). Log orphan-check hits with a hashed email and IP hash so Nightwatch can alert on anomalous volume.
    - Models: impl=sonnet · review=sonnet

---

### P3 — JWT / Auth

- [ ] **#P3-11** Auth-server fallback path returns 401 for network/upstream errors — makes infrastructure outages indistinguishable from bad tokens — Lens: `jwt-mfa`
    - Where: `app/Http/Middleware/Auth/VerifySupabaseJwt.php` (fallback catch block)
    - What: The fallback path (used only when `jwks_fail_closed = false`) catches `\Throwable` from `verifyWithAuthServer()` and returns 401 "Invalid token" whether the failure was a bad token or a Supabase connection timeout. This path is blocked in production by `AppServiceProvider::boot()` guard; the fix is polish for non-production debugging.
    - Fix: In the fallback catch block, inspect the exception type; for `ConnectionException` return 503 with "Auth service unavailable". Add `'kind' => 'auth_server_fallback_failed'` log key.
    - Models: impl=haiku · review=sonnet

---

### P3 — Migrations

- [x] **#P3-12** Skeleton-system cleanup migration has no transaction wrapper — partial failure leaves schema in unrecoverable split state — Lens: `migration`
    - Where: `supabase/migrations/20260527070000_skeleton_system_cleanup.sql`
    - What: Without an explicit `BEGIN;` / `COMMIT;`, each of the ~11 DDL and DML statements is auto-committed independently. If the final `CREATE VIEW site.public_site_payload` fails, the preceding drops (views, table columns, `site.themes`, triggers) are already durably committed — the database is missing the primary public-site view with no clean rollback path. This migration has already run on dev; current practical risk is low but the pattern should be corrected.
    - Fix: Wrap the entire file in `BEGIN;` / `COMMIT;`. None of the statements use `CONCURRENTLY`, so there is no technical barrier.
    - Models: impl=opus · review=opus

- [x] **#P3-13** Redundant `account_type` CHECK constraint added after the baseline already defines an identical one — Lens: `migration`
    - Where: `supabase/migrations/20260526200001_accounttype_check_constraint.sql` · `supabase/migrations/20260526000000_baseline_standalone_user.sql`
    - What: The baseline defines `CONSTRAINT users_account_type_check CHECK (account_type = 'individual')`. Migration `20260526200001` adds a second `CONSTRAINT users_account_type_individual CHECK (account_type = 'individual') NOT VALID`. PostgreSQL accepts both; the incremental files were authored before the baseline was updated and never cleaned up.
    - Fix: Delete `20260526200001_accounttype_check_constraint.sql` and `20260526200002_validate_accounttype_check.sql`. Both are no-ops on any database running the current baseline.
    - Models: impl=haiku · review=sonnet

- [x] **#P3-14** [@10k] No partial index on `confirmation_sent_at IS NULL` for idempotency guard queries in confirmation-send jobs — Lens: `migration`
    - Where: `supabase/migrations/20260530010000_add_visitor_confirmation_sent_at.sql:7–10`
    - What: `SendEnquiryConfirmationJob` and `SendSubscriptionConfirmationJob` query `WHERE confirmation_sent_at IS NULL` to guard against double-sends. Without a partial index, each job tick performs a sequential scan proportional to total row count. At 500k+ rows with a 60-second cadence this becomes a measurable recurring load. Runway item — not a pre-launch blocker.
    - Fix: Add a migration (no `BEGIN/COMMIT`, use `CONCURRENTLY`) with `CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_enquiries_confirmation_unsent ON site.enquiries (created_at) WHERE confirmation_sent_at IS NULL AND deleted_at IS NULL` and the equivalent on `notifications.email_subscriptions`.
    - Models: impl=sonnet · review=opus
    - Resolution (2026-06-03): NOT APPLICABLE — no index added. The finding's premise (a `WHERE confirmation_sent_at IS NULL` sweep) does not exist in the code: both jobs are dispatched once per submission by UUID from the public controllers and load the row by primary key (`Enquiry::find($id)` / `EmailSubscription::find($id)`), checking `confirmation_sent_at !== null` in PHP, not SQL. No sweeper is scheduled (`routes/console.php`) or planned (visitor-confirmation design/plan docs). The prescribed index would never be chosen by a PK lookup — pure write-amplification (the anti-pattern #P3-27 flags). Additionally, `notifications.email_subscriptions` has no `deleted_at` column, so the literal fix could not apply. Decision confirmed by repo owner. Reinstate only if a `WHERE confirmation_sent_at IS NULL` sweeper is ever introduced — add the index in the same change.

- [x] **#P3-15** Two migrations share timestamp `20260530000000`; duplicate grant migration also present — Lens: `migration` · `rls`
    - Where: `supabase/migrations/20260530000000_drop_workplace_hours.sql` · `supabase/migrations/20260530000000_grant_moderation_schema_to_app_backend.sql` (same prefix) · `supabase/migrations/20260530000050_grant_moderation_schema_to_app_backend.sql` (duplicate content)
    - What: Two files share the `20260530000000` version key; Supabase tracks applied migrations by this key, so one may be silently skipped or the CLI may error on `db reset`. The 000050 file is byte-for-byte identical to the 000000 grant file; running both is harmless (DO block is idempotent) but adds noise to history.
    - Fix: Rename `20260530000000_grant_moderation_schema_to_app_backend.sql` to `20260530000010_grant_moderation_schema_to_app_backend.sql`. Delete `20260530000050_grant_moderation_schema_to_app_backend.sql`. If dev DB has already applied the old names, use `supabase migration repair`.
    - Models: impl=haiku · review=sonnet
    - Resolution (2026-06-03): ALREADY RESOLVED before this session — no action needed. Git history (`git log --all -- 'supabase/migrations/*grant_moderation*'`) shows the grant file was only ever committed as `20260530000050_grant_moderation_schema_to_app_backend.sql` (commit `d4055c8a`). The current tree has no `20260530000000_grant_*` file: the sole holder of the `20260530000000` prefix is `20260530000000_drop_workplace_hours.sql`, and only one grant file exists. No timestamp collision, no duplicate. Renaming `000050`→`000010` was deliberately NOT done — it is already applied on dev and renaming would create a needless migration-history mismatch requiring `supabase migration repair` for zero benefit.

---

### P3 — RLS

- [ ] **#P3-16** `moderation` schema tables have no Row-Level Security — defence-in-depth gap — Lens: `rls`
    - Where: `supabase/migrations/20260528000000_create_moderation_schema.sql` — all five CREATE TABLE statements
    - What: The `moderation` schema is correctly excluded from `api.schemas` and `anon`/`authenticated` roles have no USAGE grant. The risk is purely forward-looking: if `moderation` is ever added to the API schemas or USAGE is granted without also enabling RLS, all case data — reporter PII, case types, decision rationale — would be immediately readable by any authenticated user. The `audit` schema has correct DEFAULT PRIVILEGES locked to SELECT/INSERT; moderation does not.
    - Fix: Add `ALTER TABLE moderation.<table> ENABLE ROW LEVEL SECURITY;` for all five tables. Add staff-only SELECT policies and app_backend-only INSERT/UPDATE policies, mirroring `audit.staff_audit_log`.
    - Models: impl=opus · review=opus

---

### P3 — DSAR Polish

- [ ] **#P3-17** At-least-once email send window on process crash between `Mail::send` and `markEmailSent` — Lens: `gdpr-export`
    - Where: `app/Jobs/Gdpr/ExportUserDataJob.php` — `if ($shouldSendEmail)` block
    - What: The existing code explicitly documents this as "at-least-once: a crash between send and stamp causes a retry to re-send — preferable to silent loss for GDPR right-of-access requests." The design is intentional. No correctness change required; adding a call-site note prevents future maintainers from misreading the intent.
    - Fix: Add a short comment at the call site noting the two-email window and the deliberate design intent. Optional hardening: set a Redis key (`gdpr:email-sent:{auditId}`) before `Mail::send()` and check it on retry to suppress the duplicate without a DB round-trip.
    - Models: impl=haiku · review=sonnet

- [ ] **#P3-18** No email delivery or bounce tracking for export notification — Lens: `gdpr-export`
    - Where: `app/Mail/Gdpr/UserDataExportMail.php` · `app/Models/Core/Gdpr/DataExportAudit.php:markEmailSent()`
    - What: `markEmailSent()` records handoff timestamp only. Support staff investigating "I never received my export" tickets can confirm `email_sent_at` is populated but cannot distinguish delivery from bounce. Resend (the provider used by `BaseTransactionalMail`) supports delivery/bounce webhooks.
    - Fix: Add `email_delivery_status TEXT NULL CHECK (email_delivery_status IN ('sent','delivered','bounced','complaint'))` to `audit.data_export_audit`. Default to `'sent'` when `markEmailSent()` is called. Wire a Resend webhook handler to update the column for future plumbing.
    - Models: impl=sonnet · review=sonnet

- [x] **#P3-19** Waitlist entry has no CSV companion in the export zip — Lens: `gdpr-export`
    - Where: `app/Services/User/DataExport/DataExportPayloadBuilder.php:142–147`
    - What: The waitlist section yield sets `'csv_columns' => null`, opting out of CSV output despite `streamWaitlistSignups()` already selecting a well-defined column set. Customers and enquiries both have CSV companions. `DataExportZipWriter::streamRowsArray()` auto-generates CSV when `csv_columns` is non-null — this is a one-line change.
    - Fix: Set `csv_columns` on the waitlist section descriptor to match `streamWaitlistSignups()`: `['id', 'name', 'email', 'phone', 'applicant_type', …, 'created_at', 'updated_at']`.
    - Models: impl=haiku · review=sonnet

- [x] **#P3-20** Design kit preferences not included in DSAR export — Lens: `gdpr-export`
    - Where: `app/Services/User/DataExport/DataExportPayloadBuilder.php` — `site()` method
    - What: `site()` queries `site.sites` and `site.blocks` only. `site.design_kits` (introduced in the skeleton-system cleanup) stores color palettes, typography, spacing, and button styles — user-generated personalisation choices stored specifically about the identified professional. GDPR Art. 15 disclosure obligation applies.
    - Fix: Add `streamDesignKit(string $siteId)` generator fetching the single `design_kits` row by `site_id`. Yield from `stream()` as `'name' => 'site.design_kit'`. Resolve `site_id` from the site already loaded in `stream()`.
    - Models: impl=haiku · review=sonnet

---

### P3 — Policy

- [ ] **#P3-21** `UserSectionBlockController` returns 403 instead of 422 for an unavailable block type — Lens: `policy`
    - Where: `app/Http/Controllers/Api/User/SiteManagement/UserSectionBlockController.php:119` (upsert) · `:302` (remove)
    - What: An invalid `blockType` (not in `config('partna.section_block_types')`) returns 403 "This section is not available for your account type." The Partna doctrine reserves 403 for role/type restrictions. With only one account type, this is an input validation failure (422), not a permission failure. Frontend error handling reading 403 as "permission denied" is misled.
    - Fix: Change both `$this->error(…, 403)` calls to status 422. Optionally move the allowlist check to `UpsertSectionBlockRequest` as a `Rule::in(config('partna.section_block_types'))` validation.
    - Models: impl=haiku · review=sonnet

---

### P3 — Observability

- [ ] **#P3-22** `CacheLockService::recordLockReleaseFailure` inner catch is completely silent when Redis is unreachable — Lens: `observability`
    - Where: `app/Services/Cache/CacheLockService.php:278`
    - What: If the `Redis::incr` counter itself throws (Redis unreachable), the failure is swallowed with no log. The counter exists to expose lock-release failures to ops. When Redis is down (the exact condition that causes lock-release failures to spike) the counter stops working silently.
    - Fix: Change `catch (\Throwable)` to `catch (\Throwable $e)`. Add `Log::warning('cache.lock_release_failure_counter_failed', ['error' => $e->getMessage()])`. Laravel's file/stack log driver is independent of Redis and succeeds even when Redis is down.
    - Models: impl=haiku · review=sonnet

- [ ] **#P3-23** RUM beacon catch block is completely empty — a broken log driver goes undetected — Lens: `observability`
    - Where: `app/Http/Controllers/Api/PublicSite/AnalyticsController.php:241`
    - What: The existing comment ("never bubble logging errors back to the visitor") is correct — the endpoint returns 200 regardless. But an empty `catch` means a persistent log-driver misconfiguration silently kills all RUM data collection with no operator signal.
    - Fix: The exception variable is already captured (`catch (\Throwable $e)`). Add `Log::warning('analytics.rum_logging_failed', ['error' => $e->getMessage()])` inside the catch. The 200 response is preserved.
    - Models: impl=haiku · review=sonnet

- [ ] **#P3-24** `StaffAnalyticsController` inlines three click-analytics query blocks that already exist in `AnalyticsQueryService` — Lens: `observability`
    - Where: `app/Http/Controllers/Api/Staff/StaffSite/StaffAnalyticsController.php:98–153`
    - What: The controller re-implements `clicksAggregate`, `clicksByBucket`, and `topLinks` with raw `DB::table()` calls duplicating `AnalyticsQueryService`. Any evolution to the click-query shape must now be applied in two places. Resolving this also makes #P2-58's logging fix redundant once #P2-57 is applied to the service.
    - Fix: Inject `AnalyticsQueryService` into `StaffAnalyticsController::__construct()`. Replace the three inline try/catch blocks with calls to the service methods. Accept the additional fields from `topLinks()` rather than maintaining a lighter controller variant.
    - Models: impl=sonnet · review=sonnet

---

### P3 — Scheduler

- [x] **#P3-25** Weekly KV backfill uses bare `withoutOverlapping()` — convention inconsistency — Lens: `scheduler`
    - Where: `routes/console.php:148` (`partna:backfill-subdomain-kv`)
    - What: With a 10080-minute weekly cadence the 1440-minute default TTL clears 6 days before the next run — zero practical risk. Purely conventions alignment: every other task uses an explicit TTL; copying this entry as a template for new tasks would silently inherit the bare default.
    - Fix: Replace `->withoutOverlapping()` with `->withoutOverlapping(120)`.
    - Models: impl=haiku · review=sonnet

- [x] **#P3-26** Twelve of thirteen `onFailure` callbacks discard the `\Throwable` instance — Lens: `scheduler`
    - Where: `routes/console.php` — all `onFailure` closures except `partna:prune-notifications`
    - What: Twelve closures use `function (): void` and log only a bare string ("Scheduled task failed: X") with no exception class or message. `partna:prune-notifications` already captures `\Throwable $e` and logs `get_class($e)` and `$e->getMessage()`. Without the exception detail, every overnight failure requires a manual second step to identify the root cause.
    - Fix: Update all twelve signatures to `function (?\Throwable $e = null): void`. Include `'exception' => $e ? get_class($e) : null, 'message' => $e?->getMessage()` in the `Log::error()` context array.
    - Models: impl=haiku · review=sonnet

---

### P3 — Write Amplification

- [ ] **#P3-27** [@10k] `ServiceObserver` re-evaluates section visibility on every service save even when only non-relevant fields changed — Lens: `write-amp`
    - Where: `app/Observers/Core/ServiceObserver.php:reevaluateBooking()`
    - What: `reevaluateBooking` calls `SectionVisibilityService::reevaluateEnabled()` twice (for `'booking'` and `'services'` block types) on every `saved`, `deleted`, and `restored` event. Section toggle can only occur when `is_active` changes. Title, price, description, and duration edits — the most frequent mutations — still incur both DB round-trips to determine that nothing changed.
    - Fix: Gate `reevaluateBooking` in the `saved` path on `$service->wasChanged('is_active')`. Always call it unconditionally on `deleted` and `restored` (those can push active count to zero).
    - Models: impl=haiku · review=sonnet

---

### P3 — Media

- [ ] **#P3-28** Crash window between `storeOriginal` success and DB path update can leave an unreferenced original on R2 — Lens: `media`
    - Where: `app/Services/Media/MediaUploadService.php` (inside `upload()`, between `storeOriginal` and `$media->update(['path' => $originalPath])`)
    - What: `storeOriginal` writes to R2 outside any transaction. A SIGKILL between the R2 write and the DB update leaves `path = ''` in the DB — the file is on R2 but unreachable through any app-level path and undetectable by automated cleanup without a full bucket scan.
    - Fix: Emit a structured log entry after `$media->update(['path' => $originalPath])` with `media_id`, `path`, and `checkpoint: path_written` — gives ops a breadcrumb if a `path = ''` row turns up. Longer-term: write a provisional `path` inside the same transaction as `createMediaRow`.
    - Models: impl=haiku · review=sonnet

---

### P3 — API Resources

- [ ] **#P3-29** Customer list response ships redundant `pagination` key — migration-shim cleanup — Lens: `resources`
    - Where: `app/Http/Controllers/Api/User/Customers/UserCustomerController.php:95–96`
    - What: The controller's own TODO comment identifies this as a one-release migration shim: `$payload['pagination'] = $payload['meta']`. The staff mirror already uses `meta` only. Once frontend confirms it reads `meta`, the alias doubles the pagination payload on every customer list request for no purpose.
    - Fix: Confirm the dashboard reads `meta` exclusively. Remove `$payload['pagination'] = $payload['meta'];` and the surrounding TODO comment.
    - Models: impl=haiku · review=sonnet

---

### P3 — Webhook

- [x] **#P3-30** Two Standard Webhooks implementations diverge in edge-case handling and secret format support — Lens: `webhook`
    - Where: `app/Services/Email/SupabaseEmailHookSignatureVerifier.php:36–56` · `app/Services/Auth/SupabaseAuthHookService.php:24–52`
    - What: Three divergences: (1) email verifier rejects empty `$webhookId`/`$webhookTimestamp`/`$webhookSignatureHeader`; auth hook does not. (2) Email verifier validates timestamp with `ctype_digit()`; auth hook casts to `(int)` and checks `<= 0`. (3) Most critically: email verifier's `decodeSecret()` handles the `v1,whsec_<base64>` format; auth hook uses the config value verbatim. If `SUPABASE_AUTH_HOOK_SECRET` is ever set in the `v1,whsec_<base64>` format, all legitimate deliveries fail signature verification silently.
    - Fix: Extract a `StandardWebhookVerifier` service with unified secret-format handling, empty-header rejection, and timestamp validation. Have both `VerifySupabaseEmailHookSignature` and the future middleware from #P2-06 delegate to it.
    - Models: impl=sonnet · review=sonnet

---

### P3 — KV & Routing

- [ ] **#P3-31** Alias TTL floor grants an extra 60 seconds of KV lifetime to aliases that expire between DB query and job execution — Lens: `subdomain-kv`
    - Where: `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:writeAliasEntries`
    - What: `max(60, (int) now()->diffInSeconds($expires_at, false))` returns 60 when `$expires_at` is in the past (the `false` argument disables Carbon's absolute-value behaviour). A DB alias satisfying `expires_at > now()` at query time can expire while the job is queued. The alias gets a full 60-second KV lifetime beyond its intended expiry. Practically invisible — Cloudflare enforces 60s minimum anyway — but the intent should be explicit.
    - Fix: After computing `$ttl`, add `if ($ttl !== null && $ttl <= 0) { continue; }` to skip KV writes for already-expired aliases.
    - Models: impl=haiku · review=sonnet

---

### P3 — N+1 / Scale

- [ ] **#P3-32** [@10k] Grouped service list fetches all rows with no hard cap — Lens: `n1`
    - Where: `app/Http/Controllers/Api/User/SiteManagement/UserServiceController.php` (grouped path) · `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffServiceManagementController.php` (grouped path)
    - What: When `?grouped=true`, both controllers call `->get()` unconditionally on both the services and categories queries. The grouped payload is assembled entirely in PHP memory. Most professionals have < 30 services; a future bulk-import path could produce thousands. The hot path (`UserCacheService::getDashboardServices`) already bypasses this code.
    - Fix: Add `$servicesQuery->limit(config('partna.limits.services_grouped_max', 500))` before `->get()`. When the cap is reached, include `truncated: true` in the response payload.
    - Models: impl=haiku · review=sonnet

---

## Suggested bundled fix sessions

### Bundle B1: Symfony CVE trifecta (3 CVEs) — Effort: S
- [x] Bundle status checkbox
- Items: CVE-2026-48736 (`symfony/http-foundation`), CVE-2026-46644 (`symfony/polyfill-intl-idn`), CVE-2026-48784 (`symfony/routing`)
- Models: impl=sonnet · review=sonnet
- Rationale: All three resolve with one coordinated `symfony/*` bump to 7.4.13+. Also bump `openspout/openspout` to 5.7.2 (patch, no CVE) in the same PR for housekeeping.
- Suggested approach: `composer require symfony/http-foundation:^7.4.13 symfony/routing:^7.4.13 symfony/polyfill-intl-idn:^1.38.1 openspout/openspout:^5.7.2`. Run `composer audit` to confirm zero advisories. Run `composer test`. Verify no behavioral regressions in routing, mail, and MIME paths.
- Dependencies: None — ship first.

**Session prompts:**

*Implementation:*
> Implement Bundle B1. Run `composer require symfony/http-foundation:^7.4.13 symfony/routing:^7.4.13 symfony/polyfill-intl-idn:^1.38.1 openspout/openspout:^5.7.2`. Run `composer audit` and confirm the three CVEs are resolved. Run `composer test`. Summarise the diff.

*Review:*
> Review Bundle B1.
> 1. Does `composer audit` show zero advisories?
> 2. Did any Symfony version constraint in composer.json tighten unexpectedly?
> 3. Run `composer test`. Report the result.
> Be skeptical — check for version-constraint drift.

---

### Bundle B2: Horizon supervisor + queue lane assignments (5 items — #P1-03, #P1-04, #P2-16, #P2-23, #P2-24) — Effort: S
- [x] Bundle status checkbox
- Items: `#P1-03`, `#P1-04`, `#P2-16`, `#P2-23`, `#P2-24`
- Models: impl=haiku · review=sonnet
- Rationale: All five are pure `config/horizon.php` or job-constructor edits with no logic changes. One focused session touching a single config file and five job constructors eliminates all Horizon lane misconfigurations in one go.
- Suggested approach: (1) Add `supervisor-streaming` to `defaults` and both environment blocks in `config/horizon.php` with `timeout: 120`; add `redis:streaming` to `waits`. (2) Raise `supervisor-notifications` `timeout` in `defaults` from 60 to 130. (3) Split `supervisor-analytics` to a new `supervisor-images` definition; remove `images` from `supervisor-analytics`. (4) Add `$this->onQueue('notifications')` to the three moderation notification job constructors and `DispatchEnquiryNotificationsJob`. Restart Horizon after deploy.
- Dependencies: None.

**Session prompts:**

*Implementation:*
> Implement Bundle B2. Reference `config/horizon.php` and the five job constructor files listed. Apply each fix from the finding's `Fix:` description. Run `composer test`. Confirm Horizon config passes `php artisan horizon:status` in local. Summarise the diff.

*Review:*
> Review Bundle B2.
> 1. Does `supervisor-streaming` appear in `defaults`, `production`, and `development` blocks with `timeout >= 120`?
> 2. Does `supervisor-notifications` `timeout` now exceed `SendStaffBroadcastEmailsJob::$timeout = 120`?
> 3. Is `supervisor-images` present and `images` removed from `supervisor-analytics`?
> 4. Do the four job constructors call `$this->onQueue('notifications')`?
> 5. Run `composer test`. Report.

---

### Bundle B3: Job `failed()` and `report()` hygiene (5 items — #P2-17, #P2-18, #P2-19, #P2-20, #P2-21) — Effort: M
- [x] Bundle status checkbox
- Items: `#P2-17`, `#P2-18`, `#P2-19`, `#P2-20`, `#P2-21`
- Models: impl=sonnet · review=sonnet
- Rationale: Same pattern across 13 job classes: add `failed()` with `report($e)` and audit-trail updates. One session, one pattern, no interaction effects.
- Suggested approach: For each job in the four enforcement and four notification jobs (#P2-17, #P2-18): add `public function failed(Throwable $e): void { report($e); ActionLogEntry::query()->where('id', $this->actionLogId)->update(['status' => 'failed', 'failed_at' => now()]); Log::error(…); }`. For #P2-19 (RecordAnalyticsEventJob, DispatchEnquiryNotificationsJob): add `failed()` with `report($e)` and structured `Log::error`. For #P2-20 and #P2-21: prepend `report($e);` to the existing `failed()` method bodies.
- Dependencies: None.

**Session prompts:**

*Implementation:*
> Implement Bundle B3. For the 8 moderation jobs in #P2-17 and #P2-18: add `failed(Throwable $e): void` that calls `report($e)`, stamps `ActionLogEntry` to `failed`, and logs at error level. For the 2 jobs in #P2-19: add `failed()` with `report($e)` and structured log. For the 4 jobs in #P2-20 and #P2-21: prepend `report($e);` to their existing `failed()` bodies. Run `composer test`. Summarise.

*Review:*
> Review Bundle B3.
> 1. Does every `failed()` call `report($e)` as its first statement?
> 2. Do the 4 enforcement and 4 notification jobs stamp `ActionLogEntry.status = 'failed'` on exhaustion?
> 3. Are structured log keys consistent with the project's `Log::error()` context conventions?
> 4. Run `composer test`. Report.

---

### Bundle B4: GDPR deletion completeness (5 items — #P2-08, #P2-09, #P2-10, #P2-11, #P2-12) — Effort: L
- [x] Bundle status checkbox
- Items: `#P2-08`, `#P2-09`, `#P2-10`, `#P2-11`, `#P2-12`
- Models: impl=sonnet · review=opus
- Rationale: All five are additions to `AccountDeletionService::purge()`. Doing them together ensures a single DB transaction covers all PII erasure steps, the original-email resolution is computed once, and the test suite can validate the full erasure surface in one integration test.
- Suggested approach: In `purge()`, before `forceDelete()`: (1) delete the export ZIPs from R2 (#P2-08). (2) Resolve original email from `audit.user_deletion_audit` (the same pattern as `resolveLookupEmail()`). Use it to (3) delete matching `core.waitlist_signups` row (#P2-09), (4) force-delete `core.feedback` rows (#P2-10), (5) null out PII fields on `moderation.case_signals` where `reporter_user_id = $professional->id` (#P2-11), (6) delete global `notifications.email_subscriptions` by `email_lc` (#P2-12). Wrap all six in a sub-transaction if the outer transaction supports it.
- Dependencies: None; must run after #P0-01 (clean migration state).

**Session prompts:**

*Implementation:*
> Implement Bundle B4. All changes are in `app/Services/User/AccountDeletionService.php::purge()`. Reference each finding's `Fix:` description. Resolve the pre-pseudonymised email once using the `audit.user_deletion_audit` snapshot pattern from `DataExportPayloadBuilder::resolveLookupEmail()`. Add an integration test asserting all six PII surfaces are cleared after purge. Run `composer test`. Summarise the diff.

*Review:*
> Review Bundle B4.
> 1. Are R2 export ZIPs deleted before `forceDelete()` fires?
> 2. Is the original-email snapshot fetched correctly from `audit.user_deletion_audit`?
> 3. Are waitlist, feedback, case signal PII, and global subscription rows all handled?
> 4. Could any step fail silently and leave PII behind?
> 5. Run `composer test`. Report.

---

### Bundle B5: DSAR export section completeness (4 items — #P1-06, #P1-07, #P3-19, #P3-20) — Effort: M
- [x] Bundle status checkbox
- Items: `#P1-06`, `#P1-07`, `#P3-19`, `#P3-20`
- Models: impl=sonnet · review=sonnet
- Rationale: All four are additions to `DataExportPayloadBuilder`: new generator methods + new `yield` entries in `stream()`. One session, one file, no interaction effects.
- Suggested approach: Add `streamFeedback(string $userId)`, `streamContentReports(string $userId, ?string $lookupEmail)`, and `streamDesignKit(string $siteId)` generators following the patterns of adjacent sections. Set `csv_columns` to the correct column array for the waitlist section (#P3-19). Yield all four from `stream()` and `build()`. Ensure no PII fingerprints (`ip_hash`, `reporter_ip_hash`) are included in the new sections.
- Dependencies: None.

**Session prompts:**

*Implementation:*
> Implement Bundle B5. All changes are in `app/Services/User/DataExport/DataExportPayloadBuilder.php`. Add the four missing sections (feedback, content reports, design kit, waitlist CSV). Follow the fix descriptions exactly. Run `composer test`. Summarise.

*Review:*
> Review Bundle B5.
> 1. Does each new section select only user-visible columns (no IP hashes)?
> 2. Does the waitlist section now have a non-null `csv_columns` array?
> 3. Are all four sections yielded from both `stream()` and `build()`?
> 4. Run `composer test`. Report.

---

### Bundle B6: Media pipeline reliability (4 items — #P2-25, #P2-26, #P2-27, #P2-28) — Effort: M
- [x] Bundle status checkbox
- Items: `#P2-25`, `#P2-26`, `#P2-27`, `#P2-28`
- Models: impl=sonnet · review=sonnet
- Rationale: All four are in the media service layer. #P2-25 (file handle leaks) and #P2-28 (silent delete failures) are one-function fixes. #P2-26 and #P2-27 (orphaned re-process files) share the same `old-path-before-upsert` pattern for image and video variants respectively — implementing both together avoids a gap where only one variant type is protected.
- Suggested approach: (1) Wrap the three video `fopen`/`put` call sites in `try/finally` per the `ImageVariantService` pattern. (2) Before each `MediaVariant::updateOrCreate` in `processVariants`, query for the existing row's `path`; if it differs from the new path, delete the old file after the new upload succeeds. (3) In `ImageVariantService::deleteVariants`, add per-file error collection and `Log::error()` following the `VideoVariantService::deleteVariants` pattern.
- Dependencies: None.

**Session prompts:**

*Implementation:*
> Implement Bundle B6. Fix the three video file-handle leak sites in `MediaUploadService` and `VideoVariantService` (#P2-25). Add old-path cleanup logic in `ImageVariantService::processVariants` and `VideoVariantService::processVariants` (#P2-26, #P2-27). Add per-file failure logging to `ImageVariantService::deleteVariants` (#P2-28). Run `composer test`. Summarise.

*Review:*
> Review Bundle B6.
> 1. Do all three video `fopen`/`put` call sites use `try/finally` to close the handle on exception?
> 2. Does the old-path cleanup in `processVariants` happen only after the new file is confirmed stored?
> 3. Does `ImageVariantService::deleteVariants` now log failures instead of silently swallowing them?
> 4. Run `composer test`. Report.

---

### Bundle B7: Write amplification [@10k] (4 items — #P2-48, #P2-49, #P2-50, #P2-51) — Effort: L
- [x] Bundle status checkbox
- Items: `#P2-48`, `#P2-49`, `#P2-50`, `#P2-51`
- Models: impl=sonnet · review=sonnet
- Rationale: The four findings share a single root fix: adding a `bustSite: bool = true` parameter to `UserCacheService::invalidateUser()` and splitting `SiteCacheService::invalidateSite()` into payload and image variants. These changes must be implemented together to avoid a partial state where the flag exists but the image-split does not, or vice versa.
- Suggested approach: (1) Add `bool $bustSite = true` to `UserCacheService::invalidateUser()`; gate the `invalidateSite()` tail call on it. (2) Split `SiteCacheService::invalidateSite()` into `invalidateSitePayload()` and `invalidateSiteImages()`; keep `invalidateSite()` as a wrapper. (3) In `UserObserver::updated`, pass `bustSite: false` when `wasChanged(PUBLIC_PROFILE_USER_FIELDS)` is true. (4) In `ServiceObserver::bust()`, call `invalidateUser($pro, bustSite: false)`. (5) Replace `$pro?->site?->touch()` in `ServiceCategoryObserver::bust()` with direct `invalidateSitePayload()` + `CloudflareCachePurgeJob::dispatch()`. Add an integration test asserting `invalidateSite()` fires exactly once per service save and zero image-view keys are busted on a service title edit.
- Dependencies: None.

**Session prompts:**

*Implementation:*
> Implement Bundle B7. Read the `What:` and `Fix:` blocks for #P2-48 through #P2-51 carefully before starting — the changes are interdependent. Apply all four fixes. Add integration tests per the `Fix:` descriptions. Run `composer test`. Summarise the diff, especially confirming the test that verifies single-fire invalidation.

*Review:*
> Review Bundle B7.
> 1. Does `invalidateUser()` accept `bustSite: bool` and gate the `invalidateSite()` tail call correctly?
> 2. Are `invalidateSitePayload()` and `invalidateSiteImages()` correctly split?
> 3. Does `ServiceObserver::bust()` now call `invalidateUser($pro, bustSite: false)` so the subsequent `touchParentSite()` handles the single bust?
> 4. Does `ServiceCategoryObserver` call `invalidateSitePayload()` directly instead of routing through `touch()`?
> 5. Does the integration test confirm `invalidateSite()` fires exactly once per service save?
> 6. Run `composer test`. Report.

---

### Bundle B8: Resource contract alignment (4 items — #P2-52, #P2-53, #P2-54, #P2-55) — Effort: S
- [x] Bundle status checkbox
- Items: `#P2-52`, `#P2-53`, `#P2-54`, `#P2-55`
- Models: impl=sonnet · review=sonnet
- Rationale: All four are mechanical Resource contract fixes in the staff and moderation response layer. No logic changes. One session, ~10 file edits.
- Suggested approach: (1) Change all five moderation resource base classes from `JsonResource` to `ApiResource` and cast `id` to string. (2) Replace raw Carbon in `UserDocumentController::buildDocumentPayload` and `UserUploadController::buildMediaPayload`. (3) Create `PartnaStaffResource` with `id/name/primary_email` allowlist; use it in `StaffMeController::show()`. Wrap `StaffNotificationController::store()` response in `NotificationListingResource`. (4) Add `use ReturnsPaginatedResponse;` to both feature-flag controllers and swap `Resource::collection()->response()` for `$this->success($this->paginatedResponse(…))`.
- Dependencies: None.

**Session prompts:**

*Implementation:*
> Implement Bundle B8. Apply the four resource contract fixes exactly as described in each finding's `Fix:`. Create `PartnaStaffResource` following the pattern of `UserStaffResource`. Run `composer test`. Summarise.

*Review:*
> Review Bundle B8.
> 1. Do all five moderation resources extend `ApiResource` and cast `id` to `(string)`?
> 2. Are timestamps in the two payload builders now `->toIso8601String()`?
> 3. Does `StaffMeController::show()` use `PartnaStaffResource` with an explicit field allowlist?
> 4. Does `StaffNotificationController::store()` wrap in `NotificationListingResource`?
> 5. Do the two feature-flag controllers emit the project-standard pagination envelope?
> 6. Run `composer test`. Report.

---

### Bundle B9: Scheduler safety (4 items — #P2-46, #P2-47, #P3-25, #P3-26) — Effort: S
- [x] Bundle status checkbox
- Items: `#P2-46`, `#P2-47`, `#P3-25`, `#P3-26`
- Models: impl=haiku · review=sonnet
- Rationale: All four are single-line or two-line changes to `routes/console.php`. One focused session, zero logic changes.
- Suggested approach: (1) Add `->runInBackground()` to the five daily tasks. (2) Replace bare `->withoutOverlapping()` with `->withoutOverlapping(120)` and `->withoutOverlapping(30)` for the two daily tasks. (3) Replace bare `->withoutOverlapping()` with `->withoutOverlapping(120)` for the weekly KV backfill. (4) Update all twelve bare `function (): void` `onFailure` closures to `function (?\Throwable $e = null): void` and include exception detail in the log context.
- Dependencies: None.

**Session prompts:**

*Implementation:*
> Implement Bundle B9. All changes are in `routes/console.php`. Apply all four fix descriptions. Run `composer test`. Summarise the diff.

*Review:*
> Review Bundle B9.
> 1. Do the five daily tasks all have `->runInBackground()`?
> 2. Do all `withoutOverlapping()` calls have an explicit TTL argument (not bare)?
> 3. Do all thirteen `onFailure` closures (except the intentional silent one) capture `\Throwable $e`?
> 4. Run `composer test`. Report.

---

### Bundle B10: CI/deploy hardening (3 items — #P2-31, #P2-32, #P3-08) — Effort: S
- [x] Bundle status checkbox
- Items: `#P2-31`, `#P2-32`, `#P3-08`
- Models: impl=haiku · review=sonnet
- Rationale: All three are changes to `.github/workflows/ci.yml`. One focused session, one file.
- Suggested approach: (1) Add `permissions: { contents: read }` at the `jobs.test` level. (2) Add `development` to the `push: branches:` and `pull_request: branches:` lists. (3) Remove the 10 stale `:!path` exclusions from the GS-1 step; add an inline justification comment for `CustomerObserver.php`.
- Dependencies: None — can merge independently of all other items.

**Session prompts:**

*Implementation:*
> Implement Bundle B10. All changes are in `.github/workflows/ci.yml`. Apply the three fixes. Open a draft PR against `development` to confirm CI triggers correctly on the new branch in `push.branches`. Summarise the diff.

*Review:*
> Review Bundle B10.
> 1. Is `permissions: { contents: read }` present at job level?
> 2. Does `push.branches` now include `development`?
> 3. Does `pull_request.branches` include `development`?
> 4. Are all 10 deleted-file exclusions removed from the GS-1 step?
> 5. Does `CustomerObserver.php` still appear with a brief justification comment?

---

### Bundle B11: Observability — silent catch blocks (5 items — #P2-57, #P2-58, #P2-59, #P3-22, #P3-23) — Effort: S
- [ ] Bundle status checkbox
- Items: `#P2-57`, `#P2-58`, `#P2-59`, `#P3-22`, `#P3-23`
- Models: impl=haiku · review=sonnet
- Rationale: All five are adding one `Log::warning(…)` line inside an existing `catch` block. Same pattern, trivial implementation, high observability return.
- Suggested approach: For each of the five catch sites, capture the exception variable and add a structured `Log::warning` with a distinct `'kind'` key so Nightwatch can aggregate them separately. Ensure `use Illuminate\Support\Facades\Log;` is present in each file.
- Dependencies: None.

**Session prompts:**

*Implementation:*
> Implement Bundle B11. For each of the five findings, add `Log::warning(…)` with the key and context described in the `Fix:` block. Run `composer test`. Summarise.

*Review:*
> Review Bundle B11.
> 1. Does each catch block now capture `$e` and log a distinct structured key?
> 2. Are log keys unique enough to filter individually in Nightwatch?
> 3. Does each fix preserve the original resilience behaviour (returning zero/empty/proceeding)?
> 4. Run `composer test`. Report.

---

### Bundle B12: Webhook hardening (3 items — #P2-06, #P2-07, #P3-30) — Effort: S
- [x] Bundle status checkbox
- Items: `#P2-06`, `#P2-07`, `#P3-30`
- Models: impl=sonnet · review=sonnet
- Rationale: All three are in the `SupabaseAuthHook*` and `SupabaseEmailHook*` service classes. Creating the shared `StandardWebhookVerifier` (#P3-30) provides the infrastructure for the middleware (#P2-06) and logging (#P2-07) to delegate to, making the three fixes mutually reinforcing.
- Suggested approach: (1) Extract `StandardWebhookVerifier` from `SupabaseEmailHookSignatureVerifier`: unified empty-header rejection, `ctype_digit()` timestamp check, and `decodeSecret()` for `v1,whsec_<base64>` format. (2) Create `VerifySupabaseAuthHookSignature` middleware delegating to the shared verifier; add logging for missing-secret (503) and mismatch (401). (3) Register `supabase.auth-hook` alias; attach to the MFA verification route; remove inline `verifySignature()` call from controller.
- Dependencies: None.

**Session prompts:**

*Implementation:*
> Implement Bundle B12. (1) Extract `StandardWebhookVerifier`. (2) Create `VerifySupabaseAuthHookSignature` middleware with proper logging. (3) Wire the middleware alias and attach it to the route. Remove the inline controller verification. Run `composer test`. Summarise.

*Review:*
> Review Bundle B12.
> 1. Does `StandardWebhookVerifier` handle `v1,whsec_<base64>` secret format, empty-header rejection, and `ctype_digit()` timestamp check?
> 2. Is `VerifySupabaseAuthHookSignature` logging on both misconfigured-secret (503) and mismatch (401) paths?
> 3. Is the inline `verifySignature()` call removed from `SupabaseAuthHookController`?
> 4. Does the route carry `supabase.auth-hook` middleware?
> 5. Run `composer test`. Report.

---

### Bundle B13: Race condition fixes (2 items — #P2-36, #P2-37) — Effort: S
- [x] Bundle status checkbox
- Items: `#P2-36`, `#P2-37`
- Models: impl=sonnet · review=sonnet
- Rationale: Both are TOCTOU fixes using `DB::transaction` + `lockForUpdate`. Implementing together ensures consistent use of the savepoint/lock pattern across signup and moderation paths.
- Suggested approach: (1) In `UserBootstrapService::ensureSidestUpdatesSubscription`, replace the SELECT+INSERT with `insertOrIgnore([…])`. (2) In `ModerationCaseService::take()` and `ModerationDecisionService::decide()`, move the status guard inside the `DB::transaction` callback after `lockForUpdate()->findOrFail()`. Run `composer test`.
- Dependencies: #P1-10 (PostgreSQL savepoint pattern) should be fixed before this session to ensure the test suite runs against pgsql.

**Session prompts:**

*Implementation:*
> Implement Bundle B13. Fix the race in `UserBootstrapService::ensureSidestUpdatesSubscription` using `insertOrIgnore`. Fix the race in `ModerationCaseService::take()` and `ModerationDecisionService::decide()` by moving the status guard inside the transaction with `lockForUpdate`. Run `composer test`. Summarise.

*Review:*
> Review Bundle B13.
> 1. Does `ensureSidestUpdatesSubscription` now use `insertOrIgnore` (single atomic instruction)?
> 2. Is the `$case->status` check in `take()` and `decide()` now inside `DB::transaction` after `lockForUpdate()`?
> 3. Would a concurrent request now receive 409 (not 422) for a case already taken?
> 4. Run `composer test`. Report.

---

### Bundle B14: Exception response handling (4 items — #P2-30, #P2-40, #P2-41, #P3-02) — Effort: S
- [x] Bundle status checkbox
- Items: `#P2-30`, `#P2-40`, `#P2-41`, `#P3-02`
- Models: impl=sonnet · review=sonnet
- Rationale: All four are in `bootstrap/app.php` and `app/Providers/AppServiceProvider.php`. One session, two files, closely related concerns (exception rendering, missing headers, debug guard).
- Suggested approach: (1) In `bootstrap/app.php` exception renderer: extend the `else` branch to pass through non-empty 4xx messages; add `Cache-Control: private, no-store` / `Pragma: no-cache` headers before `SecureHeaders::apply()`; restructure the `if (! $request->is('api/*')) { return null}` gate to apply `SecureHeaders` on non-API error responses too. (2) In `AppServiceProvider::boot()`, add the `APP_DEBUG` production guard after the existing six guards.
- Dependencies: None.

**Session prompts:**

*Implementation:*
> Implement Bundle B14. In `bootstrap/app.php`: (1) fix the `else` branch to pass through non-empty 4xx `HttpException` messages; (2) add `$response->headers->set('Cache-Control', 'private, no-store, max-age=0')` and `Pragma: no-cache` before `SecureHeaders::apply()`; (3) restructure the non-API early-return so `SecureHeaders::apply()` is called for the QR-code error path. In `AppServiceProvider::boot()`: add the APP_DEBUG production guard. Run `composer test`. Summarise.

*Review:*
> Review Bundle B14.
> 1. Does a plain `abort(403, 'message')` now return the message in production (not "An error occurred")?
> 2. Do all exception-path responses carry `Cache-Control: private, no-store`?
> 3. Does a QR-code 404 now carry security headers?
> 4. Does `AppServiceProvider::boot()` throw when `APP_DEBUG=true` in production?
> 5. Run `composer test`. Report.

---

### Bundle B15: Migration housekeeping (4 items — #P3-12, #P3-13, #P3-14, #P3-15) — Effort: S
- [x] Bundle status checkbox — done 2026-06-03 (P3-12 wrapped, P3-13 deleted; P3-14 not-applicable, P3-15 already resolved). Reviewed by independent opus session: APPROVE.
- Items: `#P3-12`, `#P3-13`, `#P3-14`, `#P3-15`
- Models: impl=opus · review=opus
- Rationale: All four are Supabase migration file operations. One session with `supabase migration` tooling. No application code changes. Delivers a clean migration history before production promotion.
- Suggested approach: (1) Wrap `20260527070000_skeleton_system_cleanup.sql` in `BEGIN;` / `COMMIT;`. (2) Delete `20260526200001_accounttype_check_constraint.sql` and `20260526200002_validate_accounttype_check.sql`. (3) Add a new migration file for the `confirmation_sent_at` partial indexes using `CREATE INDEX CONCURRENTLY`. (4) Rename `20260530000000_grant_*` to `20260530000010_grant_*`; delete the `20260530000050_grant_*` duplicate; run `supabase migration repair` on dev if the old names are already applied.
- Dependencies: #P0-01 must land first (clean migration state required).

**Session prompts:**

*Implementation:*
> Implement Bundle B15. Apply the four migration housekeeping fixes described in #P3-12 through #P3-15. Run `supabase db reset` (against local/dev) to confirm the full migration sequence applies cleanly. Run `composer test`. Summarise.

*Review:*
> Review Bundle B15.
> 1. Does `supabase db reset` complete without errors?
> 2. Is `20260527070000_skeleton_system_cleanup.sql` wrapped in `BEGIN/COMMIT`?
> 3. Are the two redundant account_type constraint files deleted?
> 4. Are the timestamp-collision files renamed / deduplicated correctly?
> 5. Does the partial-index migration file use `CONCURRENTLY` and no transaction wrapper?

---

### Bundle B16: DSAR export reliability (2 items — #P2-13, #P2-14) — Effort: M
- [x] Bundle status checkbox
- Items: `#P2-13`, `#P2-14`
- Models: impl=sonnet · review=sonnet
- Rationale: Both are in `ExportUserDataJob`. The orphaned-R2-cleanup (#P2-13) and the stale-PROCESSING sweeper (#P2-14) are independent but both address job-lifecycle reliability for the same job class.
- Suggested approach: (1) In `ExportUserDataJob::handle()`, add `$uploaded = false` flag; set it `true` after `$disk->put()`. In the `catch` block, if `$uploaded` call `$disk->delete($remotePath)` before `markFailed()`. (2) Add a scheduled console command `gdpr:sweep-stale-exports` that stamps PROCESSING rows older than 1 hour to `failed`; register it in `routes/console.php` as a daily task with `->runInBackground()` and `->withoutOverlapping(60)`.
- Dependencies: None.

**Session prompts:**

*Implementation:*
> Implement Bundle B16. Fix the orphaned R2 zip cleanup in `ExportUserDataJob::handle()`. Add the `gdpr:sweep-stale-exports` Artisan command and register it in `routes/console.php`. Run `composer test`. Summarise.

*Review:*
> Review Bundle B16.
> 1. Does the catch block delete the R2 file only when the upload already succeeded?
> 2. Does the sweep command target only rows older than 1 hour in PROCESSING state?
> 3. Is the sweep registered with `runInBackground()` and an explicit `withoutOverlapping()` TTL?
> 4. Run `composer test`. Report.

---

### Bundle B17: JWT claim hardening (2 items — #P2-01, #P2-02) — Effort: M
- [x] Bundle status checkbox
- Items: `#P2-01`, `#P2-02`
- Models: impl=sonnet · review=opus
- Rationale: Both are hardening additions to the JWT verification and AAL2 enforcement path. Closely related: #P2-01 tightens the revocation gate; #P2-02 adds per-action freshness checks using already-existing infrastructure.
- Suggested approach: (1) Add `Log::warning('jwt.missing_session_id', …)` when `$sessionId === ''` in both the primary and fallback paths of `VerifySupabaseJwt`. (2) Identify the three to five highest-risk staff controller actions (role changes, account deletion, force-delete) and add `$this->requiresFreshAal2()` checks to the relevant policy methods or inline in controllers, following the existing `MfaController` pattern.
- Dependencies: #P2-03 (AUTH-1 staff policy layer) should be implemented first so freshness checks have policies to attach to.

**Session prompts:**

*Implementation:*
> Implement Bundle B17. (1) Add missing-session-id logging to `VerifySupabaseJwt`. (2) Apply `requiresFreshAal2()` to the three highest-risk staff actions: `forceDestroy`, `bulkUpdateStatus`, and `updateStatus` on `StaffUserController`, following the `MfaController` pattern. Run `composer test`. Summarise.

*Review:*
> Review Bundle B17.
> 1. Does a JWT with no `session_id` now emit a `Log::warning` instead of silently passing?
> 2. Does `requiresFreshAal2()` gate the three staff actions correctly?
> 3. Is the freshness window configurable via `config('partna.mfa.fresh_window_seconds', 300)`?
> 4. Are there any high-risk actions that were missed?
> 5. Run `composer test`. Report.

---

### Bundle B18: Public endpoint hardening (5 items — #P1-11, #P2-33, #P2-34, #P2-42, #P2-43) — Effort: M
- [x] Bundle status checkbox
- Items: `#P1-11`, `#P2-33`, `#P2-34`, `#P2-42`, `#P2-43`
- Models: impl=sonnet · review=sonnet
- Rationale: All five harden public-facing endpoints — two add bot-token middleware, one adds a publication guard, one adds header validation, one adds a per-document rate limiter, and one removes dead CORS patterns. All changes are in `routes/api.php`, two controllers, and `config/cors.php` — one coherent session.
- Suggested approach: (1) Add `bot.token:signup` to availability endpoint and `bot.token:login-identifier` to identifier resolver. (2) In `QrCodeController::svg`, load the site and `abort(404)` if `!$site->is_published`. (3) In `PublicSiteController::showByHeader`, add length and regex validation before the `strtolower(trim())` call. (4) Add a `document-download` rate limiter in `AppServiceProvider` keyed on `ip + document UUID`; apply to the download route. (5) Remove the two Shopify CORS patterns from `config/cors.php`.
- Dependencies: #P1-12 (bot protection mode prod guard) should land first so the new middleware is effective.

**Session prompts:**

*Implementation:*
> Implement Bundle B18. Apply the five public-endpoint hardening fixes exactly as described. Run `composer test`. Verify the Shopify CORS patterns are gone from `config/cors.php`. Summarise the diff.

*Review:*
> Review Bundle B18.
> 1. Do the two POST endpoints carry `bot.token:*` middleware?
> 2. Does `QrCodeController::svg` return 404 for unpublished sites?
> 3. Does `showByHeader` reject values longer than 63 chars or containing non-`[a-z0-9-]` characters with 400?
> 4. Is the `document-download` limiter keyed per `(ip, document_id)`?
> 5. Are both Shopify CORS patterns removed?
> 6. Run `composer test`. Report.

---

### Bundle B19: Config cast and env defaults (2 items — #P1-05, #P2-29) — Effort: S
- [ ] Bundle status checkbox
- Items: `#P1-05`, `#P2-29`
- Models: impl=haiku · review=sonnet
- Rationale: Both are one-line fixes in `config/partna.php`. One session, one file.
- Suggested approach: (1) Add `(bool)` cast to `moderation.enabled`. (2) Replace the hardcoded `dev-api.partna.au` default for `analytics_endpoint` with `rtrim(config('app.url'), '/').'/api/analytics'`. Add `'PARTNA_PUBLIC_ANALYTICS_ENDPOINT'` to `EnvCheckService::RECOMMENDED`. Run `composer test`.
- Dependencies: None.

**Session prompts:**

*Implementation:*
> Implement Bundle B19. In `config/partna.php`, add `(bool)` cast to `moderation.enabled` and fix the `analytics_endpoint` default. Add the env var to `EnvCheckService::RECOMMENDED`. Run `composer test`. Summarise.

*Review:*
> Review Bundle B19.
> 1. Does `config('partna.moderation.enabled')` evaluate to `false` when env var is `'false'`?
> 2. Does the analytics endpoint default resolve to the current `APP_URL` rather than `dev-api.partna.au`?
> 3. Is `PARTNA_PUBLIC_ANALYTICS_ENDPOINT` now in `EnvCheckService::RECOMMENDED`?

---

### Bundle B20: Composer manifest cleanup (2 items — #P3-06, #P3-07) — Effort: S
- [x] Bundle status checkbox
- Items: `#P3-06`, `#P3-07`
- Models: impl=haiku · review=sonnet
- Rationale: Both are one-line changes to `composer.json`. One session, one file, no runtime impact.
- Suggested approach: (1) Remove `--force` from the `post-update-cmd` `vendor:publish` line. (2) Change `"php": "^8.2"` to `"^8.4"`. Run `composer update --lock` (no-op if platform already consistent). Run `composer test`.
- Dependencies: None.

**Session prompts:**

*Implementation:*
> Implement Bundle B20. Remove `--force` from `post-update-cmd` in `composer.json`. Change `"php": "^8.2"` to `"^8.4"`. Run `composer update --lock`. Run `composer test`. Summarise.

*Review:*
> Review Bundle B20.
> 1. Is `--force` removed from `post-update-cmd`?
> 2. Does `composer.json` now declare `"php": "^8.4"`?
> 3. Does `composer validate` pass?

---

### Bundle B21: Cache layer jitter polish (2 items — #P3-03, #P3-04) — Effort: S
- [x] Bundle status checkbox — done 2026-06-03 (P3-03 single-sourced the image-filter allowlists into `SiteMedia::GALLERY_POOLS` / `MEDIA_TYPE_FILTERS` + a lock test; P3-04 already satisfied — MISS_SENTINEL already jittered, no change). Reviewed by independent sonnet session: APPROVE.
- Items: `#P3-03`, `#P3-04`
- Models: impl=haiku · review=sonnet
- Rationale: Both are in the cache layer. #P3-04 is a two-line mechanical change; #P3-03 requires extracting shared constants and adding one test. One focused session.
- Suggested approach: (1) Replace the two bare `now()->addSeconds(…)` calls in the MISS_SENTINEL branch with `self::applyJitter(…)`. (2) Extract pool and media-type allowlists into constants on `SiteMedia`; reference from both `CacheKeyGenerator::siteImagesViewVariants` and `UserUploadController::index`. Add a unit test asserting the Cartesian product matches.
- Dependencies: None.

**Session prompts:**

*Implementation:*
> Implement Bundle B21. Apply both cache layer fixes. Run `composer test`. Summarise.

*Review:*
> Review Bundle B21.
> 1. Does `MISS_SENTINEL` TTL now use `self::applyJitter(…)`?
> 2. Are the pool and media-type allowlists shared constants referenced from both call sites?
> 3. Does the new unit test fail if a constant is added on one side but not the other?

---

### Bundle B22: DSAR email polish (2 items — #P3-17, #P3-18) — Effort: M
- [ ] Bundle status checkbox
- Items: `#P3-17`, `#P3-18`
- Models: impl=sonnet · review=sonnet
- Rationale: Both are about the GDPR export email lifecycle. #P3-17 is a comment addition; #P3-18 requires a schema migration column + optional webhook stub. One coherent session.
- Suggested approach: (1) Add a call-site comment to `ExportUserDataJob` explaining the deliberate at-least-once design. (2) Add `email_delivery_status TEXT NULL CHECK (…IN ('sent','delivered','bounced','complaint'))` to `audit.data_export_audit` via a Supabase migration. Default to `'sent'` inside `markEmailSent()`. Add a column to `DataExportAudit::$fillable`.
- Dependencies: None.

**Session prompts:**

*Implementation:*
> Implement Bundle B22. Add the at-least-once design comment to `ExportUserDataJob`. Write a Supabase migration adding `email_delivery_status` to `audit.data_export_audit`. Update `DataExportAudit::markEmailSent()` to set `email_delivery_status = 'sent'`. Run `composer test`. Summarise.

*Review:*
> Review Bundle B22.
> 1. Is the at-least-once design intent clearly documented in the code?
> 2. Does the migration correctly add the column with the CHECK constraint?
> 3. Does `markEmailSent()` now stamp both `email_sent_at` and `email_delivery_status = 'sent'`?

---

## Standalone — do NOT bundle

The following items touch single-writer KV contracts, schema-wide RLS policies, load-bearing transaction boundaries, or require a human design decision before implementation. Each must be its own PR reviewed with the specified model.

- [x] **#P0-01** — Delete one SQL file. No dependencies on other items. Must land before all migration work.
- [ ] **#P1-01** — StaffUserController theme crash fix. Self-contained, targeted. Land before any staff-dashboard QA session.
- [ ] **#P1-02** — Cloudflare Worker error boundary. Requires Worker deploy; coordinate with edge deploy schedule.
- [ ] **#P1-08** (GDPR-1) — Video R2 orphan strategy. Requires ops design decision on ledger vs sweep approach before implementation.
- [ ] **#P1-09** (JWT-1) — Redis revocation catch. Auth middleware; opus review required. Do not bundle with JWT-2/3.
- [ ] **#P1-10** (PROV-1) — PostgreSQL signup savepoint. Load-bearing transaction boundary; opus review. Add pgsql integration test. Do not bundle.
- [ ] **#P1-12** (RATE-2) — Bot protection mode prod guard. Must land before B18.
- [ ] **#P1-13** (KV-1) — Alias 301 path preservation. Single-writer KV / Worker change. Coordinate with Worker deploy.
- [ ] **#P1-14** (RLS-1) — `design_kits` RLS. Schema-wide RLS migration; opus review. Requires `supabase db push` to both dev and prod.
- [ ] **#P2-03** (AUTH-1) — Staff write policy authorization. New policy class required; load-bearing authz change. Must precede B17.
- [ ] **#P2-04** (RLS-2) — Staff role write scoping on `core.users`. Schema-wide RLS policy migration; opus review.
- [ ] **#P2-05** (RLS-3) — `app_backend` BYPASSRLS reduction. Architectural decision required (which tables, which jobs need cross-tenant access). L effort; discuss before implementing.
- [ ] **#P2-15** (CACHE-1) — `getByAuthId` nullable method fix. Self-contained; touches every auth request path.
- [ ] **#P2-22** (QUEUE-6) — Raw deletion token in job payload. Restructures `AccountDeletionService::request()` + job constructor; load-bearing PII change; opus review.
- [ ] **#P2-35** (IDOR-3) — X-Site-Subdomain trust. Requires human design decision: Worker-strip vs application-layer fallback vs combined. Discuss with Josh before implementing.
- [ ] **#P2-38** (JOB-1) — Deletion mail idempotency. Requires Supabase migration (`deletion_mail_sent_at` column on `core.users`); do not bundle with other migrations.
- [ ] **#P2-39** (MIGR-2) — Index inside BEGIN/COMMIT. Requires creating a sibling migration file; coordinate with migration sequence.
- [ ] **#P2-45** (KV-2) — Professional deletion clears KV entry. Single-writer KV contract; new job or service method required; opus review.
- [ ] **#P2-56** (NPL-1) — Unbounded subscriber CSV export [@10k]. Self-contained controller change but touches export UX; confirm export cap value with Josh before implementing.
- [ ] **#P3-16** (RLS-4) — Moderation schema RLS. Schema-wide RLS policies on five tables; opus review. Low urgency (`moderation` not in `api.schemas`) but delivers defence-in-depth.

---

## Deduplication notes

| Canonical ID | Subsumed IDs | Notes |
|---|---|---|
| **#P1-01** | RES-5 (resources lens), NPL-3 (n1 lens) | All three independently found `StaffUserController::site.theme` crash + dead eager-loads. Fix description in #P1-01 covers all. |
| **#P0-01** | SCHEMA-1 (rls lens) | Both lenses flagged the orphaned `csam_quarantine` index. MIGR lens cited as primary; RLS lens evidence corroborates. |
| **#P3-15** | SCHEMA-2 (rls lens) | Both lenses independently found the `20260530000000` timestamp collision and duplicate grant file. MIGR lens cited as primary. |
| **#P2-57** | OBS-2 partial root cause | #P2-58 (StaffAnalyticsController) shares the same silent-catch pattern. Both kept as separate items because they are in different files. Fixing #P2-57 first + #P3-24 (refactor) renders #P2-58 redundant; note in the B11 session. |
| **#P2-44** | (none, but related) | RATE-5 noisy-neighbour problem is the structural cause that makes RATE-1 (#P1-11) more urgent — fixing the shared limiter first reduces blast radius. |

---

## Coverage report

### Coverage by lens

| Lens file | Findings kept | Findings dropped | Drop reason |
|---|---|---|---|
| `account-caps` | 2 | 0 | — |
| `bootstrap` | 2 | 3 | BOOT-1 (gitignore pattern), MAIL-1 (secure impl), SING-1 (stateless) |
| `cache` | 3 | 2 | CACHE-1 (DeepSeek hallucinated Redis.expire), CACHE-2 (CF cache keys on URL not headers) |
| `cloudflare` | 1 | 1 | CFW-2 (CF cache API URL-keyed, not header-fragmented — DeepSeek misapplied browser W3C spec) |
| `queue-sat` | 3 | 0 | — |
| `ci-deploy` | 5 | 1 | DEPL-4 raw (`composer validate`, confidence 0.6) |
| `config-secret` | 3 | 0 | — |
| `gdpr-export` | 8 | 0 | — |
| `gdpr-deletion` | 6 | 0 | — |
| `idor` | 5 | 2 | IDOR-2/6 (staff routes already gated, no bypass identified) |
| `jwt-mfa` | 4 | 0 | — |
| `media` | 5 | 0 | — |
| `migration` | 6 | 0 | — |
| `rls` | 6 | 0 | — |
| `transactions` | 3 | 0 | — |
| `n1` | 3 | 0 | — |
| `queue-jobs` | 8 | 1 | QUEUE-3 double-dispatch concern (leaf jobs have lockForUpdate idempotency guards) |
| `observability` | 6 | 0 | — |
| `policy` | 2 | 6 | AUTH-2–6 (staff routes fully gated by middleware, no bypass), AUTH-7 (dead code — `LoadCurrentUser` blocks before it fires) |
| `rate-limiting` | 5 | 1 | RATE-2 (unsubscribe RFC 8058 conflict — mailbox providers POST directly, CAPTCHA breaks it) |
| `resources` | 5 | 2 | RES-4 (IndividualProfileController `data` wrapper intentional), RES-7 (frontend verification only) |
| `scheduler` | 4 | 0 | — |
| `security-headers` | 2 | 1 | SEC-3 (HTTPS: Partna is HTTPS-only at infra level — always-drop category) |
| `subdomain-kv` | 3 | 1 | KV-3 staff-bypass hypothesis (UpdateSiteAction is the single handle-change path; StaffUpdateUserRequest doesn't expose `handle`) |
| `webhook` | 3 | 0 | — |
| `write-amp` | 5 | 0 | — |

**Total kept: 106 · Total dropped: ~26**

### Coverage gaps

The following areas were not covered by any of the 26 lens audits and represent known unknowns:

1. **Stripe / payment integration** — Removed in the 2026-05-22 standalone strip; no lens targeted residual payment-related code. If commerce is reintegrated, a dedicated `commerce-payment-safety` lens should be run.
2. **Frontend / Astro Worker (`partna-pages`)** — The Worker route (`cloudflare-worker/src/index.js`) was partially audited but the Astro app itself was not. Cross-stack XSS, content-security-policy enforcement on rendered pages, and Astro route auth are not covered.
3. **Platform scraper controllers** (`InstagramController`, `FreshaController`, `AppleController`, etc.) — The memory file `project_platform_integrations_legal.md` notes confirmed CRITICAL/unshippable findings (SSRF in `InstagramController::mirror()`, Fresha GraphQL impersonation). These require a dedicated `platform-scraper-legal` audit rather than a technical-correctness audit.
4. **Soft-delete retention enforcement** — `PurgeSoftDeleted` was in scope for scheduler safety but the actual query correctness (correct 30-day window, correct table targeting, no FK violation ordering) was not audited by any lens.
5. **Email template XSS** — `resources/views/emails/**` (15 files) were listed in the bootstrap lens scope but the adjudicator dropped all Blade-template findings after verifying Blade auto-escaping. A dedicated `email-xss` lens with manual review of raw-output `{!! !!}` usage would close this gap.
6. **Analytics aggregation correctness** — `AggregateCacheMetricsJob` and `RecordAnalyticsEventJob` were audited for queue safety but not for aggregate calculation correctness, deduplication logic, or time-window boundary conditions.

---

*Generated 2026-05-31 by claude-sonnet-4-5 (v3 consolidator, Partna foundation audit pipeline)*
