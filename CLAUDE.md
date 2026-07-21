# Comet-Backend

Laravel 12 + Supabase + PostgreSQL backend for individual professionals' public site pages.
Full business context: `AI_CONTEXT.md`. API reference: `docs/api.md`.
Cross-project rules (git, tool routing, STOP gates) live in `../CLAUDE.md`.

## Environments

| Env | Git branch | Backend URL | Supabase project ref |
|-----|------------|-------------|----------------------|
| **Production** | `production` | `https://api.partna.au` | `edplucmvkcnokyygxqsb` |
| **Development** | `development` | `https://dev-api.partna.au` | `glncumufgaqcmqhzwrxm` |

Feature branches off `development`. PR → merge → promote to `production` to deploy prod.

**Current reality (2026-06-16):** Production env stopped, prod Supabase inactive. **Development** env serves BOTH domains, backed by dev Supabase (`glncumufgaqcmqhzwrxm`) — the live DB. Push `development` updates both APIs (do NOT promote). Apply migrations via `supabase db push` or Supabase MCP against dev ref.

**Cloud CLI** (`~/.composer/vendor/bin/cloud`, arg = env name): `cloud deployment:list development` / `tinker` / `command:run`.

**Push to Supabase:** `supabase link --project-ref <ref>` → `db push --dry-run` → `db push`. Dev freely; prod confirm first. `DB_USERNAME` = `app_backend.<project_ref>` (Supavisor), port 5432.

**⚠ From-zero apply — `db reset`/`db push` cannot provision an empty DB here.** The CLI sends each migration file's statements to Postgres as one libpq **pipeline** whenever the file has >1 statement of any kind, and `CREATE/DROP INDEX CONCURRENTLY` can't run in a pipeline (`SQLSTATE 25001`), so any file that pairs a `CONCURRENTLY` statement with anything else aborts a from-scratch apply (9 grandfathered files do; see `supabase/migrations/CONVENTIONS.md` §1). Ordinary **incremental** `db push` of compliant (each `CONCURRENTLY` alone in its file) migrations is unaffected.
- **Local fresh DB:** `scripts/db/fresh-reset.sh` (provisions the base, then applies every migration via a `psql` simple-query loop — no pipeline, so `CONCURRENTLY` succeeds).
- **Fresh prod cutover:** apply `supabase/migrations/*.sql` in filename order via `psql -f` (simple protocol; **not** `--single-transaction`) against the prod DB URL, recording each in `supabase_migrations.schema_migrations`; then `ALTER ROLE app_backend WITH LOGIN PASSWORD '<secret>'` (NOLOGIN by default). Verify a from-zero apply with `fresh-reset.sh` locally first.

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP 8.2, Laravel 12 |
| Database | PostgreSQL (Supabase-hosted), schemas: `public`, `core`, `site`, `notifications`, `analytics`, `audit` |
| Auth | Supabase Auth (JWT) — no backend login; frontend forwards token |
| Cache/Queue | Redis (DB 0 = **queue + Horizon**, 1 = cache, 2 = sessions, 4 = cache locks; DB 3 is a dormant queue-override slot). Queue and Horizon both resolve the connection named `default` (`config/queue.php` `REDIS_QUEUE_CONNECTION`, `config/horizon.php` `use`) — **not** the connection named `queue`. Cache is kept off DB 0 because `Cache::flush()` issues a raw `FLUSHDB` that would wipe Horizon job state. |
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
- All schema changes in `supabase/migrations/` as raw SQL. Baseline: `20260526000000_baseline_standalone_user.sql`. Historical in `supabase/migrations-archive/`.
- Schemas: `public` (Laravel infra), `core` (users, staff, flags, handles, config), `site` (sites, blocks, services, design_kits, media, customers, enquiries, aliases), `notifications`, `analytics`, `audit` (append-only — `app_backend` SELECT/INSERT only). No `brand`, `commerce`, `billing`.

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

### MFA / AAL2

`aal` + `amr` from Supabase JWTs as request attributes (`VerifySupabaseJwt`). Staff: `require.aal2`. User MFA: `$this->requiresFreshAal2()` in policy. Docs: `docs/auth/mfa-foundation.md`, runbook: `docs/auth/mfa-foundation-runbook.md`.

### Handle / subdomain lifecycle

Renames → old subdomain saved to `site.site_subdomain_aliases`, old handle to `core.user_handle_aliases` with `reclaim_until` (+14d) and `expires_at` (+90d, hard-delete by `handles:prune-expired-aliases`). Resolvers use `->active()`. Alias hits → **HTTP 301**. KV alias entries with `expirationTtl`. Config: `config('partna.handle.*')`. Spec: `docs/handle-redirects.md`.

### Pre-account (site-first) signup

`POST /api/public/signup/build` creates provisional `core.users` (`status='unclaimed'`, no auth/email) + unpublished `site.sites` + `core.pre_account_builds`. `GeneratePreAccountSiteJob` populates via IG: `InstagramConnectionSeeder` (scrape→R2→connection); GBP: Places fetch + `IdentitySync` fold via `IntegrationConnectionObserver::saved` (never call `IdentitySync` directly). Claim: `POST /api/claim` (first-come, email-OTP JWT). Staff/ManyChat: `POST /api/staff/builds` (published). `POST /api/bootstrap` → **410 `SIGNUP_MOVED`** for no-`core.users` JWTs.

Hard rules:
- `'unclaimed'` is first-class. Public read paths render it when published (KV TTL from `pre_account_builds.expires_at`). Capability/notification/deletion gates fail-closed. Gating log: `tests/Feature/PreAccount/UnclaimedGatingTest.php`.
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
- Adding a second architecture is a **platform decision, not a task** — needs CHECK widened, collapse undone, new `src/architectures/<name>/`, rebuilt dashboard picker. Pinned by `ArchitectureSystemConstraintsTest`.
- Never reintroduce `site.themes`, `settings.design.*`, or theme-picker machinery. "Theme" ONLY means `theme_mode` (bleach/dust/warm/dusk/midnight).

## Do NOT

- Create Laravel migration files (use `supabase/migrations/` raw SQL)
- Modify `.env` directly — reference `.env.example`
- Return raw Eloquent from API endpoints (use Resource classes)
- Over-engineer simple fixes
- Reintroduce `site.themes` or `settings.design.*`
