# Comet-Backend

Laravel 12 + Supabase + PostgreSQL backend for individual professionals' public site pages.
Full business context: `AI_CONTEXT.md`. API reference: `docs/api.md`.
Cross-project rules (git, tool routing, STOP gates) live in `../CLAUDE.md`.

## Environments

| Env | Git branch | Backend URL | Supabase project ref |
|-----|------------|-------------|----------------------|
| **Production** | `production` | `https://api.partna.au` | `edplucmvkcnokyygxqsb` |
| **Development** | `development` | `https://dev-api.partna.au` | `glncumufgaqcmqhzwrxm` |

Feature branches off `development`. PR → merge → fast-forward `development` → `production` to deploy prod.
**Routine deploy runbook: `docs/deploy/routine-deploy.md`** (pre-flight, migration ordering, verify, rollback). `production-cutover.md` is the historical one-shot event, not a deploy guide.

**Current reality (2026-07-26, post-cutover):** Each env now stands on its own. **Production** serves `api.partna.au` from the prod Laravel Cloud env backed by prod Supabase (`edplucmvkcnokyygxqsb`); **development** serves `dev-api.partna.au` from dev Supabase (`glncumufgaqcmqhzwrxm`). They deploy independently — pushing `development` no longer updates prod. To deploy prod: `git push origin development:production` — the prod env has `usesPushToDeploy: true`, so **the push IS the deploy** (no promote step, no approval, and **no CI gate** — CI's real gate runs on `development`). Apply migrations against the ref you mean; prod schema == dev schema (both on the 2026-07-26 baseline, verified identical 2026-07-26). Prod carries no customer data yet (`core.users` = 0). Supabase org is on the **Free** plan — no PITR, no managed backups, and projects can auto-pause; the `partna-db-backup` R2 dump is the only backup.

**Cloud CLI** (`~/.composer/vendor/bin/cloud`, arg = env name): `cloud deployment:list development` / `tinker` / `command:run`. Read an env's configured vars: `cloud environment:get <env> --json --fields=environmentVariables` (masked; `--show-sensitive` to reveal).

**Env-var diff:** `scripts/env/compare-env.sh` — diffs env-var **keys** (never values) across local `.env` / dev / prod + the prod-cutover checklist; prints a presence matrix + "set nowhere / prod-only / dev-only" gap buckets. Use before cutover instead of hand-comparing.

**Push to Supabase:** `supabase link --project-ref <ref>` → `db push --dry-run` → `db push`. Dev freely; prod confirm first. `DB_USERNAME` = `app_backend.<project_ref>` (Supavisor), port 5432.

**From-zero apply.** Since the 2026-07-26 collapse, `supabase/migrations/` holds a single `CONCURRENTLY`-free baseline, so a from-zero apply is one file and the old pipeline hazard no longer bites. (Historical: the CLI sends each migration file's statements as one libpq **pipeline** whenever the file has >1 statement, and `CREATE/DROP INDEX CONCURRENTLY` can't run in a pipeline — `SQLSTATE 25001`. Eleven pre-collapse files paired a `CONCURRENTLY` statement with something else and so could not be applied from scratch; all are now in `supabase/migrations-archive/`. See `supabase/migrations/CONVENTIONS.md` §1.) Keep the one-`CONCURRENTLY`-per-file rule for NEW migrations.
- **Local fresh DB:** `scripts/db/fresh-reset.sh` (provisions the base, then applies every migration via a `psql` simple-query loop — no pipeline, so `CONCURRENTLY` succeeds).
- **Fresh prod cutover:** apply `supabase/migrations/*.sql` in filename order via `psql -f` (simple protocol; **not** `--single-transaction`) against the prod DB URL, recording each in `supabase_migrations.schema_migrations`; then `ALTER ROLE app_backend WITH LOGIN PASSWORD '<secret>'` (NOLOGIN by default). Verify a from-zero apply with `fresh-reset.sh` locally first.

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP 8.4 (`composer.json` `^8.4`; CI + both Cloud envs on 8.4), Laravel 12 |
| Database | PostgreSQL (Supabase-hosted), schemas: `public`, `core`, `site`, `notifications`, `analytics`, `audit`, `moderation`, `catalog`, `content`, `ingest`, `routing` |
| Auth | Supabase Auth (JWT) — no backend login; frontend forwards token |
| Cache/Queue | Redis (DB 0 = **queue + Horizon + the auth session blocklist/tracking keyspace**, 1 = cache, 2 = sessions, 4 = cache locks; DB 3 is a dormant queue-override slot). Queue and Horizon both resolve the connection named `default` (`config/queue.php` `REDIS_QUEUE_CONNECTION`, `config/horizon.php` `use`) — **not** the connection named `queue`. `TokenRevocationService` writes `auth:revoked-session:`, `auth:user-sessions:`, `auth:session-meta:`, `auth:session-touch:` on the `app` connection (`TokenRevocationService::redis()`, `app/Services/Auth/TokenRevocationService.php:473-476`) — `app` and `default` are two bound views of the same DB 0, so every authenticated request's session checks still land on DB 0 alongside the queue, not on a separate DB. `default` stays at 15s read_timeout (must exceed `block_for`), `app` is 3s for the request path. Cache is kept off DB 0 because `Cache::flush()` issues a raw `FLUSHDB` that would wipe Horizon job state. `config/database.php`'s `redis` block defines six named connections (`default`, `app`, `cache`, `session`, `queue`, `cache_locks`), plus a seventh — `horizon` — that `HorizonServiceProvider` (`vendor/laravel/horizon`) synthesises at runtime via `Horizon::use(config('horizon.use'))`, aliasing `default`'s config. All seven share one Valkey instance, so `maxmemory-policy` is **instance-wide** — target is `volatile-lru`, under which "has a TTL" is what protects queued jobs from eviction. Therefore every cache key MUST carry a TTL: never `Cache::forever()`. Guarded by `tests/Feature/Cache/CacheKeyspaceConstraintsTest.php`. SWR cache rebuilds run *after* the response via `defer()` (`Concerns\DefersRecompute`); the `runningInConsole()` gate in that trait is load-bearing — `defer()` also flushes after queued jobs and artisan commands, so removing it would make `WarmPublicSiteCacheJob` report success before warming anything. DB-index isolation (queue=0, cache=1, sessions=2, locks=4) is single-node-only: Redis Cluster supports DB 0 only, so a future Cluster migration would need separate instances per concern, not separate indices — and `Cache::lock()` cluster-safety is unverified (Laravel 13.5.0 hash-tagged queue/`ConcurrencyLimiter` keys for Cluster but never mentioned `RedisLock`). Not a blocker today (163 keys on dev, Cluster is rejected at this scale) — written down so a future scaling decision starts from this fact instead of rediscovering it. Invalidation is Eloquent-observer-driven (`SiteObserver`, `BlockObserver`, `UserObserver`, `CustomerObserver`, `ServiceObserver`, `ServiceCategoryObserver`) and only fires on writes that go through the model layer — TTL is the backstop for everything else. Any new write path that bypasses Eloquent (`DB::table()->update()`, a manual SQL fix) MUST invalidate the affected cache keys explicitly; it will not be caught by an observer. |
| Jobs | Laravel Horizon (Redis-backed), separate `redis_video` connection for video processing |
| Frontend | Vite 7, Tailwind CSS 4 (minimal — mostly API backend) |
| Testing | Pest 4 + PHPUnit, Mockery, SQLite in-memory for tests |
| Monitoring | Laravel Nightwatch (exceptions, slow routes/jobs/commands/tasks) |

## MCP servers

| MCP | Auto-trigger |
|-----|-------------|
| **laravel-boost** | Routes, artisan, tinker, config, docs, browser-logs. **NOT server logs/errors.** |
| **supabase** | DB query, migration, schema check |

## Laravel logs — Cloud CLI only

Real logs in Laravel Cloud. Local files + boost log tools show **stale test-suite output**. Use:

```bash
cloud env:logs partna development --tail 50              # default
cloud env:logs partna development --minutes 15            # recent window
cloud env:logs partna development --live                  # live tail (bg)
cloud env:logs partna production --tail 50                # prod — confirm first
```

App = `partna`. Environments: `development` (default), `production` (gated).

**FORBIDDEN:** `mcp__laravel-boost__read-log-entries`, `mcp__laravel-boost__last-error`.

**Debugging — check logs FIRST.** Before code: `cloud env:logs partna development --minutes 10`.

## Architecture Rules

### Database — Supabase Only
- **Never create Laravel migration files.** Composer guard rejects them.
- All schema changes in `supabase/migrations/` as raw SQL. Baseline: `20260726000000_baseline_pilot.sql` (snapshot of the verified dev schema, 2026-07-26). Historical in `supabase/migrations-archive/`.
- Schemas: `public` (Laravel infra), `core` (users, staff, flags, handles, config), `site` (sites, blocks, services, design_kits, media, customers, enquiries, aliases), `notifications`, `analytics`, `audit` (append-only — `app_backend` SELECT/INSERT only), `moderation`, `catalog` (platform surface registry — surfaces, brands, detectors), `content` (normalized content items, media, sources), `ingest` (connector ingest pipeline — runs, sources, streams, record_versions), `routing` (link routing — link_observations, source_intents). No `brand`, `commerce`, `billing`.

### Code Organization
```
app/
  Http/Controllers/Api/{User,PublicSite,Staff,Internal}/
  Http/Middleware/{Auth,Context,Logging}/
  Http/Requests/   Http/Resources/   Jobs/{Analytics,Cache,Notifications}/
  Models/{Core,Analytics,Views}/   Models/BaseModel.php (pgsql, base for all)
  Observers/   Services/{Analytics,Auth,Cache,Customers,Media,Notifications,User,PublicSite,Site,Streaming}/
routes/   api.php (bootstrap/health)   api/{user,publicSite,staff}.php (domain-specific)
config/   partna.php (all feature config & limits)
```

### Patterns
- **Business logic in `Services/`**, not controllers. No separate `Actions/` namespace.
- **Resource classes** for all API responses — never raw Eloquent. **Form Request classes** for validation.
- **Observer pattern** for lifecycle side-effects. **Feature flags** via env vars → check `config/partna.php`.
- **UUID primary keys** everywhere. **Soft deletes** 30-day. **JSON columns** for flexible settings.
- **Authorization via Policies** — never inline 403 checks.

### Authorization Pattern

All resource authorization via Laravel Policies in `app/Policies/`. Every policy extends `BasePolicy`.

**Never:** `abort_unless($user->id === $resource->user_id, 403);`
**Always:** `$this->authorizeForUser($user, 'manage', $resource);`

**Why `authorizeForUser`:** Supabase JWT — `Auth::user()` is always null. `authorize()` calls `Gate::forUser(null)` (silently passes/type-errors). `authorizeForUser($user, ...)` passes the resolved user.

**Pre-create checks:** `$this->authorizeForUser($user, 'create', new SiteMedia(['user_id' => $user->id, 'pool' => 'gallery']));`

**New policy:** `Gate::policy(YourModel::class, YourPolicy::class);` in `AppServiceProvider::boot()`.

**CI enforces:** inline 403 aborts fail build. `PolicyCoverageTest` asserts every model has a policy or justified `POLICY_EXEMPT`.

**403 vs 404:** 404 when resource doesn't exist/belong to user. 403 only for role/type restrictions. Public endpoints: always 404 (403 enables enumeration).

### Outbound HTTP (SSRF)

**Every outbound fetch in `app/` must sit in one of four categories**, pinned by `tests/Feature/Architecture/OutboundHttpGuardTest.php` (own CI job — the Feature suite can abort before it runs). The `Http` facade is the ONLY permitted transport: no `curl_*`, no direct Guzzle, no `file_get_contents('http…')`.

- **B — `SafeUrlFetcher`** — the URL came from a user, a scrape, or any third-party payload. **This is the default for new code.** Inject `App\Services\Http\SafeUrlFetcher`, call `fetch()`/`tryFetch()`.
- **A — ConstantEndpoint** — host is a class const or `config()` value.
- **C — HostAllowlist** — untrusted URL checked against an explicit host allowlist AND `->withoutRedirecting()` (see `InstagramConnectionSeeder`).
- **D — FixedHostVariablePath** — host hardcoded, path variable; the variable segment MUST be validated against a strict pattern const.

Adding an allowlist entry is **not** the default fix — if the URL is externally influenced, the answer is B. Laravel's `url` validation rule does NOT prevent SSRF (`http://169.254.169.254/` passes it). Spec: `docs/superpowers/specs/2026-07-30-outbound-http-guard-design.md`.

### MFA / AAL2

`aal` + `amr` from Supabase JWTs as request attributes (`VerifySupabaseJwt`). Staff: `require.aal2`. User MFA: `$this->requiresFreshAal2()` in policy. Docs: `docs/auth/mfa-foundation.md`, runbook: `docs/auth/mfa-foundation-runbook.md`.

### Handle / subdomain lifecycle

Renames → old subdomain saved to `site.site_subdomain_aliases`, old handle to `core.user_handle_aliases` with `reclaim_until` (+14d) and `expires_at` (+90d, hard-delete by `handles:prune-expired-aliases`). Resolvers use `->active()`. Alias hits → **HTTP 301**. KV alias entries with `expirationTtl`. Config: `config('partna.handle.*')`. Spec: `docs/handle-redirects.md`.

### Pre-account (site-first) signup

`POST /api/public/signup/build` creates provisional `core.users` (`status='unclaimed'`, no auth/email) + unpublished `site.sites` + `core.pre_account_builds`. `GeneratePreAccountSiteJob` populates via IG: `InstagramConnectionSeeder` (scrape→R2→connection); GBP: Places fetch + `IdentitySync` fold via `IntegrationConnectionObserver::saved` (never call `IdentitySync` directly). Claim: `POST /api/claim` (first-come, email-OTP JWT). Staff/ManyChat: `POST /api/staff/builds` (published). `POST /api/bootstrap` → **410 `SIGNUP_MOVED`** for no-`core.users` JWTs.

Hard rules:
- `'unclaimed'` is first-class. The profiles route and the KV write render/route it regardless of `is_published` — a pre-account site is public pre-claim by design, not gated on the publish flag (KV TTL from `pre_account_builds.expires_at`). Capability/notification/deletion gates fail-closed. Gating log: `tests/Feature/PreAccount/UnclaimedGatingTest.php`.
- `config('partna.pre_account.*')` is the single registry. Adding a source = one `SiteSourceGenerator` + config entry + `source_type` CHECK migration.
- `PreAccountBuildService::requestBuild` dedupes **before** pairing map — deliberate (spec §4.1). Do not reorder.
- One LIVE build per source (`pre_account_builds_live_source_unique`, partial on `claimed_at IS NULL`). Failed builds reset, re-run.
- Expiry: `builds:prune-expired` (daily 03:40) hard-deletes with observer teardown. Claim-vs-prune → row locks + `FOR UPDATE SKIP LOCKED`.
- Provisional users have NO email: `User::routeNotificationForMail()` is nullable — new notification paths must tolerate null.
- `pre_account_builds.user_id`/`built_by_staff_id` never fillable — `associate()` only.
- Endpoints: `docs/api.md` §3. Spec: `docs/superpowers/specs/2026-07-18-pre-account-sites-design.md`.

## Commands

```bash
composer dev      # server, queue, log tail, Vite
composer test     # clear config + Pest (enforces no Laravel migrations)
php artisan pint
```

## Code Conventions

4-space indent, LF. Follow existing naming. Tests: `tests/Feature/{domain}/`, `tests/Unit/`. Pest for new features. Comment for WHY, not what — brief docblocks on public methods, one line above non-trivial blocks. No paragraphs, restatements, banners.

## Audits

### Trigger 1 — "audit X" → run the pipeline

Any "audit/review/find bugs" → `scripts/audit/audit.sh`. Never hand-write findings.

```bash
scripts/audit/audit.sh --category <cat> --name <name> --lens "<theme>" --scope <path>
scripts/audit/audit.sh --bundle <name> --scope <path>
scripts/audit/audit.sh --codebase --bundle full-sweep
```

Auto-pick lens when not named. Bundles: `core` (8), `concurrency`, `pre-merge`, `code-quality`, `pre-pilot` (12), `security` (5), `launch-readiness` (6), `scale-health` (6), `full-sweep` (21). **Narrow targeted runs find MORE than sweeps** (recall degrades past ~100K tokens). After baseline, audit delta (`--changed-since <ref>`). Tiered campaigns: `scripts/audit/campaigns.md`.

Output: one folder per run, always `CONSOLIDATED.md` (targeted → `audits/<category>/<date>-<name>/`, bundle → `audits/sweeps/<date>-<name>/`). Auto-built header. Every finding: Plain English + Technical.

- **Never run two `audit.sh` at once** (scans parallelize; adjudications sequential).
- Ground truth: `scripts/audit/system-prompt.md` + `adjudicate-prompt.md`. Update first on architectural shifts, then grep `lenses/`.

### Trigger 2 — "execute audit <file>" → fix

Follow `scripts/audit/fix-flow.md`. Branch `audit-fix/<slug>-<date>` off `development`. Per unit: **plan → implement → independent review** → tick. **Blocker gate:** P0 / auth / money / DB / L-XL / Standalone → plan first, wait for sign-off.

### Pipeline integrity

`AuditPipelineIntegrityTest.php` guards scope, per-lens, bundle reachability, applicable-lens (signals→lens), and freshness. New dir under `app/Services/`, `app/Http/Controllers/Api/`, `app/Jobs/`, or `tests/Feature/` → wire into `codebase_chunks()` + lens `.md` scope-group. Add signals high-confidence only; noisy guards get suppressed.

### Auto-archive

All checkboxes `[x]` → auto-moves to `audits/archive/`. Run `scripts/audit/archive-done.sh` anytime. Never ask.

### Opportunistic fixes — absorb the P3 tail, never schedule it

**When you have a file open for real work, fix that file's `SLOP-*` / `CFG-*` / cosmetic P3 findings in the same commit.** That is where the low-tier audit backlog is meant to go — absorbed in passing, not run as a campaign. Mention them in the commit body; they need no separate plan, unit, or review.

Bounds — do NOT absorb opportunistically:
- Anything listed **Standalone — do NOT bundle** in its source audit (locked DB writes, live third-party surfaces). Those get their own branch and review.
- Anything touching auth, money, a migration, or the public wire — the blocker gate still applies.
- Files another session owns (check `git worktree list` + the sibling worktree's `git status` before assuming a file is free).

**Never run a "clear the backlog" campaign.** Recall degrades past ~100K tokens, the P3 tail carries a measured ~40% already-fixed rate, and under `fix-flow.md` the verify→plan→implement→review overhead exceeds a sub-hour fix. Disposition beats execution: see `audits/consolidation/2026-07-30-pilot-launch-reprioritisation/BACKLOG-TRIAGE.md` for the WONTFIX / OPPORTUNISTIC / PROMOTE policy and the reasoning behind it.

**A ticked box means "resolved as an open question", not "the code changed."** Closing a finding WONTFIX with a stated reason is a legitimate outcome — leaving it open forever is not, because it blocks auto-archive and makes the audit system read permanently red.

## Workflow

- Plan mode for non-trivial tasks (3+ steps). Bug → pull Cloud logs FIRST, check Nightwatch.
- Subagents for research. `composer test` before done; Nightwatch after fixes.
- **Tests run SQLite, prod is Postgres** — CHECK/NOT NULL drift. Verify constraint-bound writes against `supabase/migrations/` DDL, not just passing suite.
- After corrections, update memory.

## Individual sitepages

Partna is individual-only. Model: `App\Models\Core\User\User` (DB `core.users`; FK = `user_id`). `account_type`: `'partna'` or `'business'` (migration `20260612120000`). `AccountType` keeps legacy `Individual` for safe casting. Never branch on `account_type` — use `AccountCapabilities`.

All `<handle>.partna.au` → `cloudflare-worker/` → `SUBDOMAIN_KV` via Cache API: `{type:"individual"}` → cache match/miss to Astro app; `{type:"alias"}` → 301. `SyncSubdomainToKvJob` is ONLY KV writer. Cache NOT auto-populated from `Cache-Control` — router MUST call `caches.default.put`.

Sitepages render from `@partnaau/design-system` (monorepo workspace, not published). Design kit vars: `site.design_kits` (column-per-var). Old `settings.design.*` gone, rejected on write.

**Backend rules:** Only read `account_type` inside `AccountCapabilities`. Gate on capabilities. Notification jobs + API endpoints MUST check `AccountCapabilities::for($user)`. `SyncSubdomainToKvJob` is ONLY KV writer.

## Site architecture system (single architecture `staple`)

Partna is an individual-user-only platform. The model is `App\Models\Core\User\User`
(DB table `core.users`; FK columns on other tables use `user_id`).
`account_type` is one of `'partna'` (standard) or `'business'` ("Business Partna"), chosen at signup (migration `20260612120000`). The `AccountType` enum has exactly two cases (`Partna`, `Business`), mirroring the `users_account_type_check` constraint. The two types behave identically EXCEPT where a capability says otherwise; never branch on `account_type` directly outside `AccountCapabilities`.

- `site.sites.architecture_id` TEXT NOT NULL, CHECK (`'staple'`), default `'staple'`. Write paths collapse all historical ids via `LEGACY_ARCHITECTURE_IDS` — effectively vestigial.
- `site.themes` → DROPPED. `set_default_theme_for_site()` → DROPPED with CASCADE. `settings.design.*` → STRIPPED.
- `site.design_kits` 1:1 with `site.sites` (PK = site_id, FK CASCADE, all columns NULLABLE). Auto-insert trigger on site create. New var = new NULLABLE column, no DB default — code-side defaults fill nulls.
- `GET /api/public/profiles/{handle}` → `designKit` (partial) + `architectureId` (always `staple`, `skeletonId` transitional alias). `PATCH /api/professional/site` → writes `design_kits` columns; legacy `skeleton_id` accepted/collapsed. `settings.design.*` rejected.

**Hard rules:**
- New design kit var = new migration (NULLABLE, no DB default).
- Adding a second architecture is a **platform decision, not a task** — needs CHECK widened, collapse undone, new `src/architectures/<name>/`, rebuilt dashboard picker. `tests/Schema/ArchitectureSystemConstraintsTest` pins the `architecture_id` CHECK, the `design_kits` CASCADE FK, the auto-insert trigger, and that `site.themes` + `set_default_theme_for_site()` stay dropped — but it runs in the **applied-schema lane** (`composer test:schema`, CI `ci.yml`), **NOT** `composer test`. A green `composer test` says nothing about any of them.
- Never reintroduce `site.themes`, `settings.design.*`, or theme-picker machinery. "Theme" ONLY means `theme_mode` (bleach/dust/warm/dusk/midnight). The dropped `site.themes`/`set_default_theme_for_site()` are pinned by the schema test above; the `settings.design.*` write rejection by `UpdateSiteValidationTest` + `StaffUpdateSiteValidationTest` (those two DO run in `composer test`).

## Load-testing harness (`scripts/launch-check/k6/`)

DIY k6 harness against dev only (README + plan: `docs/superpowers/plans/2026-07-26-k6-load-testing.md`). Its `seed.sql`/`jobs.js` hard-code 3 real invariants that silently broke them once already — touching any of these, re-check the harness:
- Gallery capped at 6/site (`core.enforce_site_gallery_max6` trigger) — guarded by `tests/Postgres/GalleryMax6TriggerTest.php`.
- A gallery item needs a matching `site.media_variants` (webp) row or its URL resolves empty — guarded by `tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php`'s gallery-engine tests.
- Analytics writes need an `Origin`/`Referer` header matching the site's subdomain (SEC-1) — guarded by `tests/Feature/Security/TenantIsolation/PublicAnalyticsIdorTest.php`.

## Do NOT

- Create Laravel migration files (use `supabase/migrations/` raw SQL)
- Modify `.env` directly — reference `.env.example`
- Return raw Eloquent from API endpoints (use Resource classes)
- Over-engineer simple fixes
- Reintroduce `site.themes` or `settings.design.*`
