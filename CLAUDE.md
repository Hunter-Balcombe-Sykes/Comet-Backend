# Claude Code Instructions

## Project Identity

**Partna** — Laravel 12 + Supabase + PostgreSQL backend for individual professionals' public site pages.
For full business context, domain model, and entity relationships, read `AI_CONTEXT.md`.
For API endpoint reference, read `docs/api.md`.
Cross-project rules (git workflow, cost discipline, pre-agent gate) live in `../CLAUDE.md`.

**Git reminder (shared repo — primary dev is someone else):** Always `git fetch && git pull` + `git log --oneline -10` before any work. Work on a feature branch. Never push without permission.

## Environments

| Env | Git branch | Backend URL | Supabase project ref |
|-----|------------|-------------|----------------------|
| **Production** | `production` | `https://api.partna.au` | `edplucmvkcnokyygxqsb` |
| **Development** | `development` | `https://dev-api.partna.au` | `glncumufgaqcmqhzwrxm` |

Feature branches off `development`. PR → merge into `development` → promote to `production` to deploy prod.

**Push semantics** — when the user says "push to supabase dev/prod", the action is:
1. `supabase link --project-ref <matching-ref>` (interactive; user runs with `!` prefix)
2. `supabase db push --dry-run`
3. `supabase db push`

Dev (`glncumufgaqcmqhzwrxm`) — iterate freely. Prod (`edplucmvkcnokyygxqsb`) — always confirm before step 3 and show dry-run output first. Re-link required when switching projects.

**Fresh prod DB caveat:** the v2 baseline creates `app_backend` as `NOLOGIN` (fail-closed). After pushing migrations to a brand-new Supabase project, run `ALTER ROLE app_backend WITH LOGIN PASSWORD '<from-secret>'` in the SQL editor before the app can connect. Laravel Cloud `DB_USERNAME` must be `app_backend.<project_ref>` (Supavisor tenant prefix), port 5432 (session mode).

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP 8.2, Laravel 12 |
| Database | PostgreSQL (Supabase-hosted), schemas: `public`, `core`, `site`, `notifications`, `analytics`, `audit` |
| Auth | Supabase Auth (JWT) — no backend login; frontend forwards token |
| Cache/Queue | Redis (DB 0 = cache, DB 1 = sessions, DB 2 = queue) |
| Jobs | Laravel Horizon (Redis-backed), separate `redis_video` connection for video processing |
| Frontend | Vite 7, Tailwind CSS 4 (minimal — mostly API backend) |
| Testing | Pest 4 + PHPUnit, Mockery, SQLite in-memory for tests |
| Monitoring | Laravel Nightwatch (exceptions, slow routes/jobs/commands/tasks) |

## MCP servers (this project only — see `../CLAUDE.md` for full catalog)

| MCP | Auto-trigger |
|-----|-------------|
| **laravel-boost** | Routes, artisan, tinker, config, docs, browser-logs. **NOT server logs / errors** — see "Laravel logs" below. |
| **supabase** | Any DB query, migration, schema check |

## Laravel logs — use Cloud CLI, NEVER boost

**The real logs live in Laravel Cloud.** Local `storage/logs/laravel.log` and `laravel-boost`'s log tools (`read-log-entries`, `last-error`) show **stale test-suite output** — they are useless and misleading for any real debugging. Overrides anything the boost-guidelines section below says about logs.

```bash
cloud env:logs partna development --tail 50              # DEFAULT — quick check
cloud env:logs partna development --minutes 15           # recent window
cloud env:logs partna development --live                 # live tail (background it)
cloud env:logs partna development --hours 1 --json       # structured, pipe to jq for filtering
cloud env:logs partna production --tail 50               # prod — confirm with user before pulling
```

CLI path: `/Users/tobiasbalcombeehrlich/.composer/vendor/bin/cloud` (already authenticated). App = `partna`. Environments = `development` (default) and `production` (gated).

**FORBIDDEN tools — never call:**
- `mcp__laravel-boost__read-log-entries`
- `mcp__laravel-boost__last-error`

Boost stays useful for `tinker`, `database-query`, `database-schema`, `list-routes`, `application-info`, `search-docs`, `browser-logs`, `list-artisan-commands`. Just not server logs.

**Debugging discipline — check logs FIRST.** When the user reports something not working, the first action — before reading code — is:

```
cloud env:logs partna development --minutes 10
```

See what the server is actually saying. THEN form a hypothesis. The user reads these logs constantly; reach for them automatically, not when asked.

## Architecture Rules

### Database — Supabase Only
- **Never create Laravel migration files.** A composer guard (`guard:no-laravel-migrations`) will reject them.
- All schema changes go in `supabase/migrations/` as raw SQL files.
- The database uses a single consolidated baseline migration: `supabase/migrations/20260526000000_baseline_standalone_user.sql`. The 147 historical migrations are archived in `supabase/migrations-archive/`.
- PostgreSQL schemas: `public` (Laravel infrastructure), `core` (users, staff, feature flags, handle aliases, platform config), `site` (sites, blocks, services, themes, media, customers, enquiries, subdomain aliases), `notifications`, `analytics`, `audit` (append-only compliance trails — `app_backend` has SELECT/INSERT only). No `brand`, `commerce`, or `billing` schemas.

### Code Organization
```
app/
  Http/Controllers/Api/{User,PublicSite,Staff,Internal}/
  Http/Middleware/{Auth,Context,Logging}/
  Http/Requests/                                      — Form Request validation
  Http/Resources/                                     — API response transformers (always use these)
  Jobs/{Analytics,Cache,Notifications}/
  Models/{Core,Analytics,Views}/                      — organized by DB schema
  Models/BaseModel.php                                — all models extend this (forces pgsql connection)
  Observers/                                          — model lifecycle hooks
  Services/{Analytics,Auth,Cache,Customers,Media,Notifications,User,PublicSite,Site,Streaming}/
routes/
  api.php                                             — bootstrap, health
  api/{user,publicSite,staff}.php                     — domain-specific routes
config/
  partna.php                                           — all Partna feature config & limits
```

### Patterns
- **Business logic in `Services/`**, not controllers. Controllers handle HTTP concerns only. There is no separate `Actions/` namespace — single-shot operations live alongside other services in the relevant domain folder.
- **Resource classes** for all API responses — never return raw Eloquent models.
- **Form Request classes** for input validation.
- **Observer pattern** for model lifecycle side-effects (auto-triggering jobs, cache invalidation).
- **Feature flags** via env vars (e.g., `SIDEST_VIDEO_UPLOADS_ENABLED`). Check `config/partna.php` for all flags.
- **UUID primary keys** on all tables.
- **Soft deletes** with 30-day retention (configurable via `SOFT_DELETE_RETENTION_DAYS`).
- **JSON columns** for flexible settings (site.settings, etc.).
- **Authorization via Policies** — never inline 403 checks in controllers. See below.

### Authorization Pattern

All resource-level authorization goes through Laravel Policies in `app/Policies/`. Every policy extends `BasePolicy`.

**Never do this in a controller:**
```php
abort_unless($user->id === $resource->user_id, 403);
```

**Always do this:**
```php
$this->authorizeForUser($user, 'manage', $resource);
```

**Why `authorizeForUser` not `authorize`:** This app uses Supabase JWT — `Auth::user()` is always null. `authorize()` calls `Gate::forUser(null)`, which silently passes or type-errors depending on the policy. `authorizeForUser($user, ...)` passes the resolved user explicitly.

**Skeleton pattern for pre-create checks** (no DB row yet):
```php
$skeleton = new SiteMedia([
    'user_id' => $user->id,
    'pool' => 'gallery',
]);
$this->authorizeForUser($user, 'create', $skeleton);
```

**Registering a new policy:** Add one line to `AppServiceProvider::boot()`:
```php
Gate::policy(YourModel::class, YourPolicy::class);
```

**CI enforces:** Inline 403 aborts in controllers fail the build.

**Coverage is sweep-tested:** `tests/Feature/Security/PolicyCoverageTest.php` asserts every model under `app/Models/` either has a `Gate::policy()` registration in `AppServiceProvider::boot()` or appears in the `POLICY_EXEMPT` allowlist with a justification. Adding a new tenant-owned model? Register a policy or add an exempt entry — silent omissions fail CI.

**403 vs 404 standard:** Use 404 (not 403) when a resource doesn't exist or doesn't belong to the authenticated user. Use 403 only for role/type restrictions ("brand-only", "staff-only") and policy gate failures. On public (unauthenticated) endpoints, always use 404 for missing/inaccessible resources — returning 403 reveals the resource exists and enables enumeration.

### MFA / AAL2

This codebase reads `aal` and `amr` from Supabase JWTs and exposes them as request attributes (set by `VerifySupabaseJwt`). Staff routes are gated by `require.aal2`. For user-facing routes that should require MFA later, add `$this->requiresFreshAal2()` to the relevant policy method. Reference docs: `docs/auth/mfa-foundation.md`. Operator runbook (rollout, brute-force testing, lockout support): `docs/auth/mfa-foundation-runbook.md`.

### Handle / subdomain lifecycle

Renames write the old subdomain to `site.site_subdomain_aliases` and the old handle to `core.user_handle_aliases` with two timestamps:

- `reclaim_until` (default +14d) — only the original owner can rename back for free.
- `expires_at`    (default +90d) — after this the row is hard-deleted by `handles:prune-expired-aliases` and the handle returns to the pool.

Resolvers (`PublicSiteResolver`, `ResolvesSiteFromRequest`, `SiteCacheService`) filter expired rows with the `->active()` scope. Alias hits return **HTTP 301** to the canonical URL — never serve content under both. Cloudflare KV writes alias entries with `expirationTtl` so the edge auto-evicts in parallel.

Configurable via `config('partna.handle.*')`. Full spec: `docs/handle-redirects.md`.

## Development Commands

```bash
composer dev      # Start server, queue worker, log tail, and Vite (all concurrently)
composer test     # Clear config + run Pest test suite (enforces no Laravel migrations)
php artisan pint  # Fix code style (Laravel Pint)
php artisan tinker # Interactive REPL
```

## Code Conventions

- 4-space indentation, LF line endings (see `.editorconfig`)
- Follow existing naming patterns — check adjacent files before creating new ones
- Tests: `tests/Feature/{domain}/` for integration, `tests/Unit/` for isolated logic
- Write Pest tests for new features and bug fixes

### Commenting

Comment enough that a reader (Tobias, frontend Claude, future-you) can understand a file without tracing every call. **Not extensive — purposeful.**

- **Always comment**: non-obvious WHY (a constraint, an ordering requirement, a workaround), the contract a method enforces, the meaning of "magic" defaults (e.g. `null = all enabled`), and the shape of complex JSON/array structures.
- **Brief docblocks** on public service methods and controller actions: 1-3 lines explaining purpose + return shape. Use `@return array{...}` shape annotations for complex returns.
- **Inline comments** above non-trivial blocks (filtering, validation, cache busting) — one short line saying *why*, not *what*.
- **Avoid**: paragraph-long essays, comments that just restate the next line, decorative banners, TODO graveyards.
- **Test files**: descriptive `it(...)` names are usually enough — only comment when setup is non-obvious.

When in doubt, ask: "if I deleted this comment, would a new dev have to read 3 other files to understand?" If yes, keep it.

## Audits

### Generating new audits — use the pipeline, not a manual session

When asked to audit code (any phrasing: "let's audit X", "audit these controllers", "audit this", "review for issues", "find bugs in X"), use the dual-worker pipeline at `scripts/audit/audit.sh`:

```bash
scripts/audit/audit.sh \
  --lens "<5-15 word audit theme>" \
  --scope <path> \
  [--scope <path>...]
```

This runs DeepSeek V4 Pro for the first-pass scan, then `claude -p --model sonnet` for adjudication, and emits `audit-YYYY-MM-DD-<lens-slug>.md` in the canonical format. Validated 2026-05-04: ~$0.06–0.25 per audit, ~5–7 minutes wall time, ship-quality output.

- DeepSeek key lives in `scripts/audit/.env` (gitignored). Override by exporting `DEEPSEEK_API_KEY`.
- Claude uses the local `claude` CLI's existing OAuth login. No `ANTHROPIC_API_KEY` required.

**Don't manually generate audit findings in a session unless explicitly told to.** The pipeline is faster, cheaper, and produces consistent format for downstream tooling. Use the standalone scripts (`audit-scan.sh`, `audit-adjudicate.sh`) when you want to inspect drafts before adjudicating, or re-adjudicate without re-scanning.

### Audit format (canonical)

The structure is load-bearing: the audit orchestrator (`audit` CLI) parses these files to feed unattended fix sessions. The canonical reference is **`pilot-stage-1.md`** — no separate conventions doc exists. Required structure per finding:

- Top-level `- [ ]` checkbox + `**#ID**` + tier marker (P0/P1/P2/P3) + Effort tag (S/M/L/XL)
- `Where:` / `Affects:` / `What to do:` (bullets) / `Technical:` / `Plain English:` / `Evidence:` (verbatim code)
- Bundles go under `## Suggested Bundled Sessions`
- Items that should not run unattended go under `### Standalone — do NOT bundle`

Companion files (`*-executive-summary.md`, `audit-ledger-*.md`, `*-legal-coding.md`) are not parsed by the orchestrator — only files with the item-list structure are.

## Workflow

### Plan First
- Enter plan mode for any non-trivial task (3+ steps or architectural decisions)
- If something goes sideways, STOP and re-plan immediately
- Check in with the user before starting implementation

### Execute Autonomously
- When given a bug: just fix it. **First** pull Cloud logs (`cloud env:logs partna development --minutes 10`), then trace errors, then resolve. Never skip the log pull — see "Laravel logs" section above.
- **Check Nightwatch** when diagnosing bugs or performance issues — use it to find exceptions, slow routes, slow jobs, and stack traces before diving into code.
- Use subagents to keep the main context window clean — offload research and exploration.
- One task per subagent for focused execution.
- Go fix failing tests without being told how.

### Verify Before Done
- Never mark a task complete without proving it works.
- Run `composer test` to verify changes pass.
- After fixing bugs, check Nightwatch to confirm the issue is resolved and no new issues surfaced.
- Ask yourself: "Would a staff engineer approve this?"

### Learn Continuously
- After any correction from the user: update memory with the pattern.
- Write rules that prevent the same mistake twice.

## Core Principles

- **Simplicity first.** Make every change as simple as possible. Impact minimal code.
- **Find root causes.** No temporary fixes. No bandaids. Senior developer standards.
- **Minimal blast radius.** Only touch what's necessary. No side-effect bugs.
- **Demand elegance (balanced).** For non-trivial changes, pause and ask "is there a more elegant way?" Skip this for simple, obvious fixes.

## Individual sitepages — architectural ground truth

Partna is an individual-user-only platform. The model is `App\Models\Core\User\User`
(DB table `core.users`; FK columns on other tables use `user_id`).
`account_type` is always `'individual'`; the `AccountType` enum has only an `Individual` case.

All `<handle>.partna.au` requests route through one Cloudflare Worker
(`cloudflare-worker/` in this repo) that reads `SUBDOMAIN_KV` and uses
the Cache API:
- `{type:"individual"}` → `caches.default.match`; on miss, Service Binding to
  Astro app on Cloudflare Workers Static Assets; on success, `caches.default.put`
- `{type:"alias"}` → 301 to the canonical subdomain URL

The Worker has ONE writer: `SyncSubdomainToKvJob`. Never write KV elsewhere.

Site pages render from `@partna/themes` (GitHub Packages, per-theme bundles +
shared engines). The package is framework-free and Shopify-free.

Account capabilities (backend: `App\Services\Accounts\AccountCapabilities`)
are the source of truth for what features are available. Every notification
dispatcher, route guard, and API response checks capabilities before acting.

Per-user styling uses the per-Site `settings.design` JSONB.

Worker responses are NOT auto-cached from `Cache-Control` alone. The router
Worker MUST call `caches.default.put(request, response.clone())` to populate
the edge cache. The cache-purge job invalidates by URL.

### Backend-specific rules

- `User.account_type` is always `individual`. Do not write code that branches on other account types.
- Notification jobs and API endpoints MUST check `AccountCapabilities::for($user)`
  before acting.
- `SyncSubdomainToKvJob` is the ONLY writer to `SUBDOMAIN_KV`. All routing
  changes go through it.

## Do NOT

- Create Laravel migration files (use `supabase/migrations/` with raw SQL)
- Modify `.env` directly — reference `.env.example` for available keys
- Return raw Eloquent models from API endpoints (use Resource classes)
- Over-engineer simple fixes — three similar lines > a premature abstraction
- Drown files in comments — see "Commenting" above for the bar

