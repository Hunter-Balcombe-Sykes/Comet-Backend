# Claude Code Instructions

## Project Identity

**Partna** — Laravel 12 + Supabase + PostgreSQL backend for individual professionals' public site pages.
For full business context, domain model, and entity relationships, read `AI_CONTEXT.md`.
For API endpoint reference, read `docs/api.md`.
Cross-project rules (git workflow, tool routing, pre-agent gate) live in `../CLAUDE.md`.

**Git reminder (shared repo — primary dev is someone else):** Always `git fetch && git pull` + `git log --oneline -10` before any work. Work on a feature branch. Never push without permission.

## Environments

| Env | Git branch | Backend URL | Supabase project ref |
|-----|------------|-------------|----------------------|
| **Production** | `production` | `https://api.partna.au` | `edplucmvkcnokyygxqsb` |
| **Development** | `development` | `https://dev-api.partna.au` | `glncumufgaqcmqhzwrxm` |

Feature branches off `development`. PR → merge into `development` → promote to `production` to deploy prod.

**Laravel Cloud reality (2026-06-16) — the table is the *intended* split; here's what's actually running.** The **production** Laravel Cloud env is **stopped** and the **prod Supabase (`edplucmvkcnokyygxqsb`) is INACTIVE/paused**. The **development** env serves **both** `dev-api.partna.au` **and** `api.partna.au`, backed by the **dev Supabase (`glncumufgaqcmqhzwrxm`) — the live DB for everything, production sitepages included.** Therefore:
- Pushing `development` (push-to-deploy) updates *both* API domains. There is no separate prod deploy step right now — do NOT "promote to production" (it would ship the ~530-commit divergence + unreviewed migrations to a paused env).
- `migrate --force` is **commented out** in the env's deploy command, so Supabase migrations don't auto-run on deploy. Apply them with `supabase db push` or the Supabase MCP `apply_migration` against the **dev** ref `glncumufgaqcmqhzwrxm`.

**`cloud` CLI beyond logs** (`/Users/tobiasbalcombeehrlich/.composer/vendor/bin/cloud`; arg form is just the env name, e.g. `development`):
- `cloud deployment:list development` — deploy status (`build.running` / `deployment.succeeded` / `build.failed`); builds can transiently time out ("build timed out after 3600s") → re-trigger with `cloud deploy partna development --no-wait`.
- `cloud tinker development --code='…'` — run PHP on the env (e.g. a manual CF cache purge: `app(\App\Services\Cloudflare\CloudflarePurgeService::class)->purgeHandle("<handle>")`).
- `cloud command:run development …` — run an artisan command on the env.

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
- PostgreSQL schemas: `public` (Laravel infrastructure), `core` (users, staff, feature flags, handle aliases, platform config), `site` (sites, blocks, services, design_kits, media, customers, enquiries, subdomain aliases), `notifications`, `analytics`, `audit` (append-only compliance trails — `app_backend` has SELECT/INSERT only). No `brand`, `commerce`, or `billing` schemas. The old `site.themes` table is gone (architecture-system cleanup).

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

### Pre-account (site-first) signup — live 2026-07-19

Signup is site-first. `POST /api/public/signup/build` creates a real but **provisional** `core.users` row (`status='unclaimed'`, `auth_user_id`/`primary_email` both NULL) + an **unpublished** `site.sites` row + a permanent `core.pre_account_builds` audit row (1:1, survives claim). `GeneratePreAccountSiteJob` (scraping lane) populates the site from the source through the EXISTING platform machinery — IG: `InstagramConnectionSeeder` (scrape → R2 mirror → connection payload); GBP: Places fetch + `IdentitySync` fold via `IntegrationConnectionObserver::saved` (never call `IdentitySync` directly). The visitor later claims the site with a Supabase email-OTP JWT (`POST /api/claim`, first-come — `ClaimSiteService` binds auth+email, flips `status='active'`, re-syncs KV to a permanent entry). Staff/ManyChat trigger **published** marketing builds via `POST /api/staff/builds`. `POST /api/bootstrap` no longer creates accounts: **410 `SIGNUP_MOVED`** for a JWT with no `core.users` row; the refresh path for existing users is unchanged; invite-token consumption is retired entirely.

Hard rules:

- `'unclaimed'` is a first-class user status. Public **read** paths deliberately render it when published (`PublicSiteResolver`, `site.public_site_payload` view, `SyncSubdomainToKvJob` — the KV entry carries a TTL aligned to `pre_account_builds.expires_at`, min 60s). Capability/notification/deletion gates stay fail-closed. Before widening or narrowing any status gate, decide which class it's in (decision log: `tests/Feature/PreAccount/UnclaimedGatingTest.php` header).
- `config('partna.pre_account.*')` is the single registry: the account_type→source pairing map and the source_type→generator class map. Adding a source = one `SiteSourceGenerator` implementation + one config entry + widening the `source_type` CHECK in a migration.
- `PreAccountBuildService::requestBuild` checks **dedupe before the pairing map** — deliberate (spec §4.1: a live build re-serves across account types; the reject path creates zero rows). Do not "fix" the order.
- One LIVE build per source (`pre_account_builds_live_source_unique`, partial on `claimed_at IS NULL`); a failed live build resets and re-runs on the next request for the same source.
- Expiry: `builds:prune-expired` (daily 03:40) hard-deletes expired unclaimed builds with observer-driven teardown (connections → media purge → cache/edge bust → `forceDelete`); claim-vs-prune races are settled by row locks + `FOR UPDATE SKIP LOCKED`.
- Provisional users have NO email: `User::routeNotificationForMail()` is nullable — any new notification path must tolerate a null mail route.
- `pre_account_builds.user_id`/`built_by_staff_id` are never fillable — `associate()` only.
- Endpoint reference: `docs/api.md` §3. Spec: `docs/superpowers/specs/2026-07-18-pre-account-sites-design.md`. Plan (per-task commit SHAs): `docs/superpowers/plans/2026-07-18-pre-account-sites.md`.

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

There are exactly **two** audit flows and **two** triggers. Never invent a third, never skip the steps.

### Trigger 1 — "audit X" → GENERATE (run the pipeline, never hand-write findings)

Any phrasing that asks to audit / review / find bugs / check for problems ("audit this", "audit these
controllers", "review X for issues", "find bugs in X") → run the pipeline at `scripts/audit/audit.sh`.
Do NOT read the files and draft findings yourself; the pipeline (DeepSeek scan → `claude -p` Sonnet
adjudication) is cheaper and produces consistent format.

```bash
# targeted — pass --category + --name so the folder is tidy:
scripts/audit/audit.sh --category security --name frontpage \
  --lens "<5-15 word theme>" --scope <path> [--scope <path>...]

# bundle / whole-repo sweep:
scripts/audit/audit.sh --bundle <name> --scope <path>
scripts/audit/audit.sh --codebase --bundle full-sweep
```

**Lens / scope:** when Josh doesn't name a lens, auto-pick the best-fit lens(es) and STATE the choice
before firing — don't ask "what lens?". Bundles: `core` (=`--full`, 8 lenses), `concurrency`,
`pre-merge`, `code-quality`, `pre-pilot` (12), `security` (5), `launch-readiness` (6), `scale-health`
(6), `full-sweep` (all 21). Multi-lens scope → use a bundle, not repeated `--lens`.

**Tiered campaigns — `scripts/audit/campaigns.md`.** For a themed sweep (security,
scale-health, concurrency, data/privacy, code-quality) use the size-bounded tiered plans
there rather than inventing scopes. Each tier is a ready-made prompt; every scope group is
measured under 350KB because **scan recall degrades past ~100K tokens** (measured 2026-07-19:
10/10 planted findings at 2KB vs 8/10 at 669KB). Narrow targeted runs find MORE than a
`--codebase` sweep — sweeps are a coverage instrument, not a discovery one. After a baseline,
audit the delta (`--changed-since <ref>`), not the repo.

**Output is ALWAYS one folder per run, ALWAYS containing `CONSOLIDATED.md`:**
- targeted → `audits/<category>/<date>-<name>/CONSOLIDATED.md`
- bundle / codebase → `audits/sweeps/<date>-<name>/CONSOLIDATED.md`

`CONSOLIDATED.md` always opens with a deterministic header (auto-built by the script, can't drift):
**Scope** (lens + paths) · **Findings at a glance** (P0–P3 counts) · **Execution policy** (which model
plans/implements/reviews). Every finding carries both a **Plain English** summary (2–4 sentences a
learner can follow) and a **Technical** explanation, plus `## Suggested Bundled Sessions` and
`## Standalone — do NOT bundle` sections. After it runs, report tier counts + the folder path; don't
paste the file back.

- DeepSeek scans parallelize (`--scan-jobs`, default 4); adjudications are sequential by design — **never
  run two `audit.sh` at once.** DeepSeek key in `scripts/audit/.env`; Claude uses the local `claude` OAuth.
- Architecture ground truth lives in `scripts/audit/system-prompt.md` + `adjudicate-prompt.md` — update
  those two first on any architectural shift, then grep `scripts/audit/lenses/` for the changed term.

### Trigger 2 — "execute audit <file>" → FIX (plan → implement → independent review)

When Josh says **`execute audit <CONSOLIDATED.md>`** (or "work the audit <file>"), follow
`scripts/audit/fix-flow.md` EXACTLY. The short version (full spec in that file):

- Branch `audit-fix/<slug>-<date>` off `development`. Work units = each **bundle** + each **standalone** item, P0→P3.
- Per unit: **plan (Opus)** → **implement (Sonnet)** → **independent review by a SEPARATE Sonnet instance** → tick the checkbox only after tests pass AND review says PASS → commit.
- **Blocker gate:** P0 / auth / money / DB-or-migration / L-XL / anything under `Standalone` → produce the plan, present it, and **wait for Josh's sign-off** before implementing. Everything else proceeds.
- Models come from the file's **Execution policy** header (Opus plan / Sonnet implement / Sonnet review; combine plan+impl for S/XS; escalate to Opus per-item when warranted).

### Auto-archive — never ask "are the boxes ticked?"

When every checkbox in a run folder's `CONSOLIDATED.md` is `[x]`, the folder moves itself to
`audits/archive/<same path>` (git history preserved). The fix flow runs `scripts/audit/archive-done.sh`
automatically at the end; you can also run `scripts/audit/archive-done.sh` anytime to sweep all finished
audits. Do this automatically — it is never a question to Josh.

### Audit finding format (canonical)

Enforced by `scripts/audit/adjudicate-prompt.md` (the source of truth; `pilot-stage-1.md` is an archived
historical example). Per finding: top-level `- [ ]` checkbox + `**#ID**` + tier (P0/P1/P2/P3) + `Effort`
(S/M/L/XL) + `Where:` / `Affects:` / `What to do:` / `Technical:` / `Plain English:` / `Evidence:`
(verbatim code). Bundles under `## Suggested Bundled Sessions`; standalone items under
`## Standalone — do NOT bundle`.

### Keeping the audit pipeline honest (scope + freshness)

The pipeline can silently audit the wrong thing several ways. All are guarded by
`tests/Feature/Architecture/AuditPipelineIntegrityTest.php` (runs in `composer test` / CI). **A lens
reports nothing for files it never opens, and that reads as "clean"** — every guard below exists
because a silent scope hole is indistinguishable from a passing audit.

- **Scope coverage.** `--codebase` sweeps only scan what `codebase_chunks()` in `scripts/audit/audit.sh`
  lists. The scanner recurses a mapped dir, so a **new file in an existing dir is covered automatically** —
  but a **new top-level namespace is not**. When you add a new dir under `app/Services/`,
  `app/Http/Controllers/Api/`, `app/Jobs/`, or `tests/Feature/`, wire it into the right chunk in
  `codebase_chunks()` **and** the lens's `.md` scope-group. The guard fails CI if you forget (or add a
  justified `$coverageExempt` entry). Also remember the file-type glob in `audit-scan.sh` — a scope path
  whose extension isn't in it (`.php .blade.php .sql .js .ts .yml .yaml .sh`) is read as nothing.
- **Per-lens coverage.** Scanning happens PER LENS, so global coverage is not enough: `app/Models` mapped
  under `schema-rls` does nothing for `code-quality-slop`. Two guards cover this — **breadth lenses**
  (`code-quality-slop`, `semantic-correctness`) must each reach the whole product surface, and **every
  lens must be fed the paths its own `.md` `--scope` lines declare**. If a lens's doc overreaches, narrow
  the doc; don't silently leave the arm short. This is what let the unused waitlist subsystem survive a
  whole-repo dead-code sweep (2026-07-19) — slop's map reached 1 of its 6 files.
- **Bundle reachability.** A lens file listed in no bundle can never run in a sweep. `test-prod-parity`
  sat orphaned this way, so the SQLite-vs-Postgres write drift it hunts (the class behind two Instagram
  incidents) was covered by nothing. The guard fails CI on any lens no bundle references.
- **Applicable-lens coverage (signals).** The guards above ensure *something* reads each file; this one
  ensures the *right* lens does. Applicability can't be judged by a test, but a large slice of it is an
  observable fact rather than an opinion: a file containing `DB::transaction` belongs in
  `transaction-boundaries` whatever anyone thinks. The guard maps mechanical signals → owning lens
  (`DB::transaction`/`lockForUpdate` → transaction-boundaries, `Cache::` → caching-gold-standard,
  `ShouldQueue` → job-queue-correctness, `$fillable`/`$guarded` → security, `env(` →
  configuration-hygiene) and fails when a lens can't reach code carrying its own signal. It caught the
  pre-account `ClaimSiteService` (locked, savepoint-wrapped claim race) sitting outside
  transaction-boundaries. **It is a floor, not a guarantee** — a bug with no grep signature is invisible
  to it, so placing new code across the lenses that matter is still a human call. When adding a signal,
  keep it high-confidence; a noisy guard gets suppressed, and a suppressed guard protects nothing.
- **Lens freshness.** Lenses + `system-prompt.md` + `adjudicate-prompt.md` encode the architecture as-written;
  after a shift they audit code that no longer exists. On any architectural change (renamed/removed class,
  dir, DB concept), refresh `system-prompt.md` + `adjudicate-prompt.md` **first**, then grep `lenses/` for the
  changed term. The guard auto-catches dead **file-path** references in that prose, but it can NOT catch stale
  **concepts** (a renamed DB column, a class whose behaviour changed) — that judgement is still yours.

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
- **Tests run on SQLite, prod is Postgres — and the schemas drift (2026-06-16).** The in-memory test schema (`tests/Pest.php`) does NOT mirror prod constraints: e.g. `site.platform_connections.payload` is `TEXT NULL` in tests but `NOT NULL` in prod, and Postgres CHECK constraints aren't enforced at all. So a write that violates a NOT NULL / CHECK passes CI green and only 500s on real Postgres (this bit the async Instagram connect twice — `payload => null`, then `last_refresh_status => 'pending'` missing from that column's CHECK). For constraint-bound writes, verify against the actual `supabase/migrations/` DDL, not just a passing suite.

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
`account_type` is one of `'partna'` (standard) or `'business'` ("Business Partna"), chosen at signup (migration `20260612120000`). The `AccountType` enum keeps a legacy `Individual` case for safe casting only — it is not user-selectable. The two types behave identically EXCEPT where a capability says otherwise; never branch on `account_type` directly outside `AccountCapabilities`.

All `<handle>.partna.au` requests route through one Cloudflare Worker
(`cloudflare-worker/` in this repo) that reads `SUBDOMAIN_KV` and uses
the Cache API:
- `{type:"individual"}` → `caches.default.match`; on miss, Service Binding to
  the Astro sitepage app (partna-monorepo/apps/pages) on Cloudflare Workers
  Static Assets; on success, `caches.default.put`
- `{type:"alias"}` → 301 to the canonical subdomain URL

The Worker has ONE writer: `SyncSubdomainToKvJob`. Never write KV elsewhere.

Site pages render from `@partnaau/design-system` — a framework-agnostic
workspace package in the monorepo (partna-monorepo/packages/design-system) with
subpath exports `design-kit` / `design-styles` / `engines` / `design-assets`.
The Astro sitepage app (partna-monorepo/apps/pages) consumes it via an npm
workspace symlink, not a published registry artifact. The package is
framework-free and Shopify-free.

Account capabilities (backend: `App\Services\Accounts\AccountCapabilities`)
are the source of truth for what features are available. Every notification
dispatcher, route guard, and API response checks capabilities before acting.

Per-user styling lives in the `site.design_kits` table (one row per site, column-per-var). The old `site.sites.settings.design` JSONB path is gone and `settings.design.*` is rejected on write. See "Site architecture system" below + spec doc at `../docs/superpowers/specs/2026-05-26-skeleton-system-design.md`.

Worker responses are NOT auto-cached from `Cache-Control` alone. The router
Worker MUST call `caches.default.put(request, response.clone())` to populate
the edge cache. The cache-purge job invalidates by URL.

### Backend-specific rules

- `User.account_type` is `'partna'` or `'business'`. Do NOT branch on it directly — read it ONLY inside `AccountCapabilities` to derive a capability, then gate on the capability (e.g. `can_book_storewide` drives Fresha storewide booking for Business accounts).
- Notification jobs and API endpoints MUST check `AccountCapabilities::for($user)`
  before acting.
- `SyncSubdomainToKvJob` is the ONLY writer to `SUBDOMAIN_KV`. All routing
  changes go through it.

## Site architecture system (live — single architecture)

**Live.** Full spec (its filename keeps the old "skeleton" name): `../docs/superpowers/specs/2026-05-26-skeleton-system-design.md`. An **architecture** is how a sitepage is laid out / how its pages connect; design variation lives entirely in `site.design_kits`. The V3+V4 theme model is replaced with an architecture + design-kit system — and the platform is **single-architecture**: `staple` is the only layout (replaced `one` 2026-07-15; the bento/dock/flick/deck/atlas era ended 2026-07-10 and the dashboard picker was removed). The concept was called a "skeleton" until the 2026-07-10 rename.

**Backend changes at cleanup (spec §8):**

- `site.sites.architecture_id` TEXT NOT NULL, CHECK `sites_architecture_id_check` (value must be `'staple'`), default `'staple'` (migration `20260714200000_architecture_one_to_staple.sql`; the earlier collapse-to-`'one'` + skeleton_id rename were 20260710170000 / 20260710230000). The column is effectively vestigial — every write path collapses historical ids (`skeleton-1`…`skeleton-4`, hub/stories/flow, sheet/thread, bento/dock/flick/deck/atlas, `one`) to `'staple'` via `UpdateSiteRequest::LEGACY_ARCHITECTURE_IDS`; `ALLOWED_ARCHITECTURES` is `['staple']`.
- `site.themes` table → DROPPED entirely. The architecture is a code constant (`partna-monorepo/apps/pages/src/architectures/staple/`), not a DB record.
- `set_default_theme_for_site()` Postgres function → DROPPED with CASCADE (kills the trigger too).
- `site.sites.settings.design.*` JSONB path → STRIPPED via `UPDATE site.sites SET settings = settings - 'design'`.
- NEW `site.design_kits` table → 1:1 with `site.sites` (PK = site_id, FK with ON DELETE CASCADE). All columns NULLABLE. Per-user design vars stored column-per-var. Trigger `trg_create_empty_design_kit` auto-inserts an empty row on site creation.
- NEW migration trigger per layer-sweep step 4: every new design kit var introduces a new column on `site.design_kits` (NULLABLE, no DB-level default — code-side defaults in the `@partnaau/design-system/design-kit` package fill nulls at read time).

**API changes:**

- `GET /api/public/profiles/{handle}` payload: `designKit` (partial, only stored non-null values, factor preset layer merged at read) + `architectureId` (always reads as `staple` after normalization). **Transition dual-key:** `IndividualProfileResource` ALSO emits `skeletonId` as an alias of the same value — drop it once the apps/pages deploy reading `architectureId` is confirmed. The Astro sitepage app (partna-monorepo/apps/pages) reads `architectureId ?? skeletonId`, then does the read-time merge with defaults before rendering.
- `site.public_site_payload` DB view still embeds the JSONB **wire key** `skeleton_id` (a base-column rename doesn't touch a JSONB string literal). Renaming that public wire key is a separate, consumer-coordinated change, intentionally out of scope of the column rename — see the note in `20260710230000_rename_skeleton_id_to_architecture_id.sql`.
- `PATCH /api/professional/site` mutation: writes individual `design_kits` columns. The legacy field `skeleton_id` is still accepted (merged into `architecture_id` in `UpdateSiteRequest::prepareForValidation`); every value collapses to `'staple'`. No longer accepts `settings.design.*`.

**Hard rules:**

- Adding a new design kit var = new SQL migration in `supabase/migrations/` adding a NULLABLE column to `site.design_kits`. Never with a DB-level DEFAULT — defaults live in the package.
- `site.sites.architecture_id` is constrained to `'staple'` (CHECK `sites_architecture_id_check`). Adding a second architecture is a **platform decision, not a task**: it needs the CHECK widened via migration, the request-layer collapse in `UpdateSiteRequest` (`LEGACY_ARCHITECTURE_IDS` / `ALLOWED_ARCHITECTURES`) undone for the new id, a new `partna-monorepo/apps/pages/src/architectures/<name>/` + `/a/<name>.astro` page, and a rebuilt dashboard picker. No new DB tables. The constraints are pinned by `tests/Feature/Database/ArchitectureSystemConstraintsTest.php`.
- Don't reintroduce `site.themes`, `settings.design.*`, or theme-picker machinery. The ONLY sanctioned use of the word "theme" is the design kit's **theme mode** (`theme_mode`: bleach/dust/warm/dusk/midnight — palette selection, user-only, never factor-set).

## Do NOT

- Create Laravel migration files (use `supabase/migrations/` with raw SQL)
- Modify `.env` directly — reference `.env.example` for available keys
- Return raw Eloquent models from API endpoints (use Resource classes)
- Over-engineer simple fixes — three similar lines > a premature abstraction
- Drown files in comments — see "Commenting" above for the bar
- Reintroduce `site.themes` table or `settings.design.*` after the architecture-system cleanup (see "Site architecture system" above)

