# Comet-Backend

Laravel 12 + Supabase + PostgreSQL backend for individual professionals' public site pages.
Full business context: `AI_CONTEXT.md`. API reference: `docs/api.md`.
Cross-repo rules (repo lanes, git workflow, tool routing, STOP gates) live in `~/Developer/CLAUDE.md` — the hub file, written 2026-08 (the old dangling-pointer warning here described a pre-hub state and was resolved 2026-08-19).

## Environments

| Env | Git branch | Backend URL | Supabase project ref |
|-----|------------|-------------|----------------------|
| **Production** | `production` | `https://api.partna.au` | `edplucmvkcnokyygxqsb` |
| **Development** | `development` | `https://dev-api.partna.au` | `glncumufgaqcmqhzwrxm` |

Feature branches off `development`. PR → merge → fast-forward `development` → `production` to deploy prod.
**Routine deploy runbook: `docs/deploy/routine-deploy.md`** (pre-flight, migration ordering, verify, rollback). `production-cutover.md` is the historical one-shot event, not a deploy guide.

**Current reality (2026-07-26, post-cutover):** Each env now stands on its own. **Production** serves `api.partna.au` from the prod Laravel Cloud env backed by prod Supabase (`edplucmvkcnokyygxqsb`); **development** serves `dev-api.partna.au` from dev Supabase (`glncumufgaqcmqhzwrxm`). They deploy independently — pushing `development` no longer updates prod. To deploy prod: `git push origin development:production` — the prod env has `usesPushToDeploy: true`, so **the push IS the deploy** (no promote step, no approval, and **no CI gate** — CI's real gate runs on `development`). Apply migrations against the ref you mean. **Prod schema has DIVERGED from dev** — dev has taken many migrations since the 2026-07-26 baseline that were never applied to prod; prod reconciliation is deferred, separate future work (do not assume parity, verify against the ref). Prod carries no customer data yet (`core.users` = 0). Supabase org is on the **Pro** plan (upgraded 2026-08-14) — daily managed backups exist; the R2 dump remains the belt-and-braces backup. **Two different names, do not conflate them:** `partna-db-backup` (singular) is the GitHub REPO holding the backup workflows; the R2 BUCKET is `partna-db-backups` (plural). `scripts/db/backup-to-r2.sh` reaches it from a laptop — not via `AWS_*`/`R2_*` keys (there are none locally; the scoped ones are GitHub Actions secrets) but via the existing wrangler OAuth session, driven through `npx --yes wrangler@4`, so nothing needs installing.

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

| MCP | Tool prefix | Auto-trigger |
|-----|-------------|-------------|
| **laravel-boost** | `mcp__laravel-boost__` | Routes, artisan, tinker, config, docs, browser-logs. **NOT server logs/errors.** |
| **claude.ai Supabase** | `mcp__claude_ai_Supabase__` | DB query, migration, schema check |

⚠️ **The Supabase MCP is not named `supabase`.** It is the claude.ai-hosted connector — an account-level OAuth integration, not a server spawned from this repo. It therefore does **not** appear in `~/.claude.json` or a `.mcp.json`, and a session that greps the config for `supabase` will conclude it is missing when it is live. `claude mcp list` is the honest check (it covers both transports); a config grep is not. Its tools are deferred — load a schema with `ToolSearch` (`select:mcp__claude_ai_Supabase__execute_sql`) before calling. Pass `project_id` explicitly: dev `glncumufgaqcmqhzwrxm`, prod `edplucmvkcnokyygxqsb` — there is no default, and the two schemas have diverged.

`laravel-boost` is a **stdio** server (`php artisan boost:mcp`, package `laravel/boost` in `composer.json`), registered at **local scope** for this project alongside `github` and `nightwatch`. If its tools vanish, it was dropped from `~/.claude.json`, not uninstalled — re-add with `claude mcp add laravel-boost --scope local -- php artisan boost:mcp` from the repo root. Do **not** run `php artisan boost:install` to fix this: that command also rewrites AI guideline files and can edit this one.

## Laravel logs — Cloud CLI only

Real logs in Laravel Cloud. Local files + boost log tools show **stale test-suite output**. Use:

```bash
cloud env:logs partna development --tail 50              # default
cloud env:logs partna development --minutes 15            # recent window
cloud env:logs partna development --live                  # live tail (bg)
cloud env:logs partna production --tail 50                # prod — confirm first
```

App = `partna`. Environments: `development` (default), `production` (gated).

⚠️ **Never call `cloud env:logs` bare from a loop or in the background.** It has no guaranteed exit path — it can wedge on a dead connection and sleep forever, parentless and socketless, and `--live` backlogs-then-exits rather than streaming. On 2026-08-19 an 11-minute poll loop left **70 orphaned `php` processes alive for 6 hours**, driving load average to 45 on a 10-core machine and pinning `kernel_task` at 67% (macOS thermal throttle). Use the bounded wrapper instead — it kills the wedge and leaves no orphans:

```bash
scripts/env/cloud-logs.sh development --minutes 2 --json      # 60s bound by default
CLOUD_LOGS_TIMEOUT=120 scripts/env/cloud-logs.sh production --tail 50
```

Exit 124 means it timed out and was killed — that is the guard working, not a log failure.

**Every response is capped at 100 lines server-side** — `--tail 2000` or a wide
`--minutes` still returns the LAST 100 lines of the range, which silently hides
everything earlier (this is why the 2026-08-27 pre-account YouTube failures were
initially undiagnosable). For a complete window, use the pagination wrapper,
which slides `--to` backwards page by page (each subprocess call is bounded by
a 120s timeout — no orphan risk):

```bash
scripts/logs/window.py "2026-08-27 03:57:00" "2026-08-27 04:01:00"            # app=partna env=development
scripts/logs/window.py "2026-08-27 03:57:00" "2026-08-27 04:01:00" partna production
```

Emits JSON lines oldest-first; grep the output. It warns on stderr if >100
lines share one second (that excess is unreachable through this API).

**FORBIDDEN:** `mcp__laravel-boost__read-log-entries`, `mcp__laravel-boost__last-error`.

**Debugging — check logs FIRST.** Before code: `cloud env:logs partna development --minutes 10`.

## Architecture Rules

### Database — Supabase Only
- **Never create Laravel migration files.** Composer guard rejects them.
- All schema changes in `supabase/migrations/` as raw SQL. Baseline: `20260726000000_baseline_pilot.sql` (snapshot of the verified dev schema, 2026-07-26). Historical in `supabase/migrations-archive/`.
- Schemas: `public` (Laravel infra), `core` (users, staff, flags, handles, config), `site` (sites, blocks, design_kits, media, customers, enquiries, aliases, section_items), `notifications`, `analytics`, `audit` (append-only — `app_backend` SELECT/INSERT only), `moderation`, `catalog` (platform surface registry — surfaces, brands, detectors, plus the two RUNTIME tables: detector_suspensions, unmatched_domains), `content` (**the curation store** — items, collections, sources, source_items, manual_overrides, item_slugs, storefronts), `ingest` (connector ingest pipeline — runs, sources, streams, record_versions), `routing` (link routing — link_observations, source_intents). No `brand`, `commerce`, `billing`.
- **`site` no longer owns services, menus or shop content** — all of it lives in `content.*`. See "Content pools" below for the drop list and the rules.

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

### Catalog runtime tables — the two the compiler never touches

`catalog.*` is mostly the compiled artefact projected by `catalog:sync` (upsert + tombstone). Two tables are **accumulated at runtime instead**, are not re-derivable from a recompile, and were wired on 2026-08-19 after shipping writerless since 2026-07-27:

- **`catalog.detector_suspensions`** — the staff kill-switch for one detector. Read by `App\Catalog\DetectorSuspensions`, folded onto the `Rulepack` singleton in `AppServiceProvider` via `withSuspensions()`, honoured in `LinkProjector::project()`. Operated with `php artisan catalog:suspend-detector <id> --reason=… [--hours=24] [--release] [--list]` (window capped at 720h; the id is validated against the compiled catalog because a typo would otherwise suspend nothing silently).
- **`catalog.unmatched_domains`** — the triage queue ranking domains the router could not place. Written by `App\Catalog\UnmatchedDomains` from `LinkObserver::record()`. Read with `php artisan catalog:unmatched [--all] [--triage=<key>]`.

Hard rules:
- **The suspension set reaches the projector as DATA, never as a query.** `LinkProjector` is `f(Iri, Rulepack) → Projection` with no I/O — that purity is what makes `routing:reproject` a real diff tool. Resolve suspensions when the singleton is built; never read the table inside `project()`.
- **A suspended detector still sets `anyRuleExists`**, so the no-match reason stays `no-rule-matched`, not `unknown-domain`. Those two strings are what `unmatched_domains.has_detectors` is derived from.
- **Both lookups FAIL OPEN and log.** Production has no `catalog` schema at all, so both throw there on every call; a kill-switch that 500s the paste preview is worse than the detector it disables. While the read is broken, a suspension is not in force — stated, not hidden.
- **`unmatched_domains` stores a registrable key and a MASKED path shape only** — never a raw path, never a query string. It carries no `user_id`, so it has no account-deletion cascade and anything identifying written there outlives its owner. The raw URL belongs in `routing.link_observations`, which is user-scoped and cascade-deleted.
- `catalog:sync` / `catalog:compile` still never touch either table, and `Rulepack::fromCompiledCatalog()` without `withSuspensions()` is the PURE pack that compile/reproject/corpus deliberately use.

### MFA / AAL2

`aal` + `amr` from Supabase JWTs as request attributes (`VerifySupabaseJwt`). Staff: `require.aal2`. User MFA: `$this->requiresFreshAal2()` in policy. Docs: `docs/auth/mfa-foundation.md`, runbook: `docs/auth/mfa-foundation-runbook.md`.

### Handle / subdomain lifecycle

Renames → old subdomain saved to `site.site_subdomain_aliases`, old handle to `core.user_handle_aliases` with `reclaim_until` (+14d) and `expires_at` (+90d, hard-delete by `handles:prune-expired-aliases`). Resolvers use `->active()`. Alias hits → **HTTP 301**. KV alias entries with `expirationTtl`. Config: `config('partna.handle.*')`. Spec: `docs/handle-redirects.md`.

### Pre-account (site-first) signup

`POST /api/public/signup/build` creates provisional `core.users` (`status='unclaimed'`, no auth/email) + unpublished `site.sites` + `core.pre_account_builds`. `GeneratePreAccountSiteJob` populates via IG: `InstagramConnectionSeeder` (scrape→R2→connection); GBP: Places fetch + `IdentitySync` fold via `IntegrationConnectionObserver::saved` (never call `IdentitySync` directly). Claim: `POST /api/claim` (first-come, email-OTP JWT). Staff/ManyChat: `POST /api/staff/builds` (published). `POST /api/bootstrap` → **410 `SIGNUP_MOVED`** for no-`core.users` JWTs.

Hard rules:
- `'unclaimed'` is first-class, and **a pre-account site IS publicly routable pre-claim — by design.** That is the product pitch: a visitor sees their site before claiming it. `IndividualProfileController::show`, `PublicIntegrationController::show` and `SyncSubdomainToKvJob` all serve an unclaimed build **even while `is_published` is false** — 241 of 270 dev sites are in exactly that state. ⚠️ **Since 2026-09-01 the publish flag is NOT ignored — it is scoped by claim state.** Both profile read paths 404 a **claimed** owner's unpublished site (`$site !== null && ! $site->is_published && ! $pro->isUnclaimed()`); the unclaimed carve-out is what keeps the demo fleet lit. This is the complement of "Dark Until Claimed", not a re-add of it. Pinned by `tests/Feature/PublicSite/PublishGateTest.php` with a 5-mutation gate. `SyncSubdomainToKvJob` still ignores `is_published` entirely and routes regardless: **KV is a routing pointer, not a visibility control** — retiring the entry on unpublish would serve `unclaimedHtml()` ("this address is free") for a handle its owner still holds. `PublicSiteResolver::resolvePublishedSite()` gates too, but the profiles route does not use it — it resolves by `handle_lc` only, which is why the gate had to be added to the controller rather than inherited. Capability/notification/deletion gates still fail-closed. **History — do NOT silently re-add:** a "Dark Until Claimed" gate (`ee1c22784`, 2026-08-24) 404'd unvetted self-serve builds on all three paths; it was **reverted 2026-08-25 on owner decision** because it defeats the pre-claim demo, and `isVisibleWhileUnclaimed()` no longer exists. Its premise — audit `#SEC-3`, first-come claiming with nothing tying the claimer to the builder — is therefore **live again, and must be closed at the CLAIM step (ownership proof), not the read step.** Gating log: `tests/Feature/PreAccount/UnclaimedGatingTest.php`; `docs/api.md` §Public visibility vs. `is_published`.
- `config('partna.pre_account.*')` is the single registry. Adding a source = one `SiteSourceGenerator` + config entry + `source_type` CHECK migration.
- `PreAccountBuildService::requestBuild` dedupes **before** pairing map — deliberate (spec §4.1). Do not reorder.
- One LIVE build per source (`pre_account_builds_live_source_unique`, partial on `claimed_at IS NULL`). Failed builds reset, re-run.
- Expiry: `builds:prune-expired` (daily 03:40) hard-deletes with observer teardown. Claim-vs-prune → row locks + `FOR UPDATE SKIP LOCKED`.
- Provisional users have NO email: `User::routeNotificationForMail()` is nullable — new notification paths must tolerate null.
- `pre_account_builds.user_id`/`built_by_staff_id` never fillable — `associate()` only.
- Endpoints: `docs/api.md` §3. Spec: `docs/superpowers/specs/2026-07-18-pre-account-sites-design.md`.
- **ManyChat builds arrive at `POST /api/internal/webhooks/manychat/builds`, NOT `/api/staff/builds`** — the staff group carries `require.aal2` and no robot can satisfy it. A `claim_token` (SHA-256 stored, plaintext returned once) proves invitation in place of `contact_email`, which is what lets a DM'd lead claim with no email in the flow. ⚠️ **A token is minted ONLY for a NEW build, or a retry carrying the same `idempotency_key`** — minting on any other deduped call would let a leaked webhook secret take over any build whose `source_ref` is guessable. The token is **narrow**: it satisfies the invite-gate only and does NOT override `CLAIM_EMAIL_MISMATCH`. Single-use means **used, not opened** — the hash clears on a successful claim only, folded into the `claimed_at` write. ⚠️ `built_via = VIA_STAFF` now also originates from this webhook, so `isOutreach()`'s "only from a staff-authenticated write" premise is dead (it fails safe). Spec: `docs/superpowers/specs/2026-08-25-manychat-claim-link-design.md`.

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
- **Touching `app/Ingest/Projection/ProjectionWriter.php` means running `tests/Postgres/` (`composer test:pg`), not just `tests/Feature/Ingest/`.** That lane's stand-in DDL is hand-written and drifts silently from writer changes — slice 5a turned it red for 7 tests and two reviews missed it on a green SQLite run. It happened again 2026-09-01: `da958493e` added `rs.last_seen_run` to the writer's select, five stand-ins lacked the column, 55 test classes failed, and the lane stayed red for ~15 consecutive runs while `postgres-tests` — a REQUIRED CI check — gave no signal at all because it isn't part of the cheap `composer test` path; PR #316 patched it by hand. Same day, `tests/Feature/Architecture/PostgresLaneReadCoverageTest.php` was added to catch this class of drift statically, and it runs IN the cheap lane (`composer test`): for every table a `tests/Postgres/` file provisions, it asserts every column read by the `App\` code that file drives is declared by that stand-in — the complement of `PostgresLaneDdlDriftTest` (stand-in ⊆ real schema), which says in its own docblock that it cannot see this direction. It found drift PR #316's hand fix missed: `content.media_assets.mirror_eligible` absent from four stand-ins, and `ingest.record_state.current_version_id` still missing from `IngestProjectChunkingTest` even after that PR touched the same file for a different column. Attribution runs through the lane file's own `use App\...;` imports plus `Artisan::call()` resolved to the command class via its `$signature`, and is per-CLASS not per-method — a column read only by an uncalled method of an imported class can still produce a finding (2 such entries live in the guard's own `$knownLimitation`); a lane file reaching app code any other way is invisible to it, as is any table a writer touches that the lane never provisions at all (`42P01`, no static scan catches that), and Eloquent-driven reads demand nothing since they emit no literal column strings. Fix a finding by ADDING the column — `ALTER TABLE … ADD COLUMN IF NOT EXISTS …` so it survives first-creator-wins — never by thinning a table or relaxing an assertion.
- **Recorded fixtures.** Real upstream responses live in `tests/fixtures/recorded/` (loader `Tests\Support\Fixtures\Recorded`, mutator `Recorded::mutate()`); capture with `php artisan fixtures:capture` (`--from=file|url|db|live`; billed sources need `--confirm-spend`), check with `fixtures:verify`. Every file needs a `MANIFEST.json` row (`RecordedFixtureManifestGuardTest`). New scraper/pipeline tests fake upstream from these — never hand-type an Apify/Places payload. Spec: `docs/superpowers/specs/2026-08-18-pipeline-assurance-design.md`.
- After corrections, update memory.

## Individual sitepages

Partna is individual-only. Model: `App\Models\Core\User\User` (DB `core.users`; FK = `user_id`). `account_type`: `'partna'` or `'business'` (migration `20260612120000`). `AccountType` keeps legacy `Individual` for safe casting. Never branch on `account_type` — use `AccountCapabilities`.

All `<handle>.partna.au` → `cloudflare-worker/` → `SUBDOMAIN_KV` via Cache API: `{type:"individual"}` → cache match/miss to Astro app; `{type:"alias"}` → 301. `SyncSubdomainToKvJob` is ONLY KV writer. Cache NOT auto-populated from `Cache-Control` — router MUST call `caches.default.put`.

Sitepages render from `@partnaau/design-system` (monorepo workspace, not published). Design kit vars: `site.design_kits` (column-per-var). Old `settings.design.*` gone, rejected on write.

**Backend rules:** Only read `account_type` inside `AccountCapabilities`. Gate on capabilities. Notification jobs + API endpoints MUST check `AccountCapabilities::for($user)`. `SyncSubdomainToKvJob` is ONLY KV writer.

## Site architecture system (two architectures since 2026-08-24: `staple` + `scroll`)

Partna is an individual-user-only platform. The model is `App\Models\Core\User\User`
(DB table `core.users`; FK columns on other tables use `user_id`).
`account_type` is one of `'partna'` (standard) or `'business'` ("Business Partna"), chosen at signup (migration `20260612120000`). The `AccountType` enum has exactly two cases (`Partna`, `Business`), mirroring the `users_account_type_check` constraint. The two types behave identically EXCEPT where a capability says otherwise; never branch on `account_type` directly outside `AccountCapabilities`.

- `site.sites.architecture_id` TEXT NOT NULL, CHECK `IN ('staple','scroll')` (widened by migration `20260824140000`), default `'scroll'` (migration `20260827120000` moved the default AND every existing row off `'staple'`) — LIVE again, not vestigial: fillable and back on the dashboard wire since 2026-08-24 (`Site::ARCHITECTURE_IDS`, `UpdateSiteRequest` validates `Rule::in`), when the owner shipped `scroll` as the second architecture. It reaches the PUBLIC payload as `architectureId` via `IndividualProfileResource`. (Corrected 2026-08-27, plan 05 pass 9 — the 2026-08-20 retirement-spec framing below this line predates the reversal.)
- `site.themes` → DROPPED. `set_default_theme_for_site()` → DROPPED with CASCADE. `settings.design.*` → STRIPPED.
- `site.design_kits` 1:1 with `site.sites` (PK = site_id, FK CASCADE, all columns NULLABLE). Auto-insert trigger on site create. New var = new NULLABLE column, no DB default — code-side defaults fill nulls.
- `GET /api/public/profiles/{handle}` → `designKit` (partial) + `architectureId` (`staple` or `scroll`). `PATCH /api/professional/site` → writes `design_kits` columns AND `architecture_id` (validated `Rule::in(Site::ARCHITECTURE_IDS)`, persisted — un-ignored 2026-08-24); legacy `skeleton_id` stays IGNORED (accepted, dropped, never persisted; deliberately not `prohibited` so an old client is not 422'd). `settings.design.*` rejected.

**Hard rules:**
- New design kit var = new migration (NULLABLE, no DB default).
- Adding a further architecture is a **platform decision, not a task** (the second one, `scroll`, was exactly that — an owner decision, 2026-08-24; the CHECK is widened and the dashboard field reopened, though scroll lives inline in `[...path].astro` rather than its own directory). `tests/Schema/ArchitectureSystemConstraintsTest` pins the `architecture_id` CHECK, the `design_kits` CASCADE FK, the auto-insert trigger, and that `site.themes` + `set_default_theme_for_site()` stay dropped — but it runs in the **applied-schema lane** (`composer test:schema`, CI `ci.yml`), **NOT** `composer test`. A green `composer test` says nothing about any of them.
- Never reintroduce `site.themes`, `settings.design.*`, or theme-picker machinery. "Theme" ONLY means `theme_mode` (bleach/dust/warm/dusk/midnight). The dropped `site.themes`/`set_default_theme_for_site()` are pinned by the schema test above; the `settings.design.*` write rejection by `UpdateSiteValidationTest` + `StaffUpdateSiteValidationTest` (those two DO run in `composer test`).

## Content pools (`content.*`) — the one curation surface

The Content Pool Convergence programme closed on dev 2026-08-17. **`content.*` is the single store for every curated item**; platforms are sources, not owners. Full record: `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` (checkpoints §12–§31). Per-contract wire manifests: `docs/wire-changes/`. **Dev only — production carries none of it (see below).**

**Ten legacy tables are DROPPED on dev.** Verified by `to_regclass`, 2026-08-17 11:05 UTC:
`site.menu_items` · `site.menu_categories` · `site.menu_item_categories` · `site.menu_item_platforms` · `site.content_selection` (slice 7, spec §27) · `site.shop_brands` · `site.shop_products` (shop re-home, §29) · `site.services` · `site.service_categories` · `site.service_category_assignments` (services cutover, §28).

**Two menu tables SURVIVE by design** and are not part of any teardown: `site.menus` (the bookkeeping row — `scan_items`, `suppressed_items`, `last_successful_fetch_at`) and `site.menu_platform_links`. Live readers in `PoolResolver`, `MenuFetchJob`, `MenuPayloadComposer`, `MenuScanApplier`, `ManualMenuItems`, `PlatformHealthNotifier`. A sweep that expects "all menu tables gone" is reading the drop list wrong.

**The public wire.** `GET /api/public/profiles/{handle}` → pools live at **`data.profile.pools`**, NOT top level. Nine (= PoolRegistry::POOLS, which PoolWire iterates in full): `custom_links`, `events`, `listen`, `media`, `menus`, `reviews`, `services`, `shop`, `watch`. (Was listed as seven here until 2026-08-27 — the line predated reviews/watch joining the wire.) Each carries `items` + `latestItemId`; `menus` adds `collections` + `diningModes`, `shop` adds `collections`.

- **`profile.services` and `profile.pools.services` are different things, deliberately** (owner ruling 2026-08-17). `profile.services` = owner-authored only. `pools.services` = the union of all service-kind items including Fresha-scraped, with `origin` (`auto`|`manual`) per item. Consumers pick one surface or filter by `origin`; the backend will not de-duplicate them.

**Models without tables are DTO carriers — do not "tidy" them away.** `MenuItem`, `MenuCategory`, `MenuItemPlatform`, `Service`, `ServiceCategory` and `ShopBrand` survive their dropped tables because `ManualMenuItems`/`ManualServiceItems` hydrate them unpersisted (`exists = false`) for the dashboard shape. Deleting them breaks the surviving content lane. They MUST stay in `PurgeSoftDeleted::PURGE_EXEMPT` — a table-less model in `PURGE_HANDLED` makes that nightly 03:20 command throw `42P01` (it did; `d8beab929` fixed it, spec §28.3).

**Cache invalidation is a THREE-lane contract** on any owner-initiated pool mutation (owner ruling 2026-08-17, spec §12.6): `BuildState::bump()` + `DB::table('site.sites')->update(['updated_at' => now()])` + conditional `CloudflareCachePurgeJob`. Lane 2 is the one people forget — the public payload cache keys off `site.sites.updated_at`, so skipping it serves stale content for the TTL while the CDN is correctly purged. `App\Site\Documents\SiteCacheLanes::bust()` is the shared seam for all three; `PoolController::poolChanged()`, `PoolItemCreateController::pin()`, `ItemController::destroy()`, `ItemLinkController::upsert()/destroy()` and `ProvisionShopPinsCommand::invalidate()` all route through it, pinned by `tests/Feature/Content/PoolCacheLanesTest.php`, `tests/Feature/Content/ShopPinProvisioningTest.php` and `tests/Feature/Architecture/PoolCacheLaneSeamTest.php`. `ProjectionWriter::bumpSite()` fires lane 1 ONLY, by design — it is a per-item primitive that batch callers invoke once per row, so lanes 2+3 are discharged once at the request boundary instead (see its docblock). `ManualOverrideController::bumpSites`, `ItemMerger::bumpSites` and `SectionItemController::upsert()/destroy()` were the last lane-1-only writers; #PGR-36 (2026-08-18) routed all three through `SiteCacheLanes::bust()`, so every owner-initiated pool mutation now goes through the seam. Keep it that way — a new owner-write path calls `bust()`, never `BuildState::bump()` alone.

**Gotchas that have each cost a session:**
- A **live coverage gate is valid only until the next write.** This programme watched readings go stale mid-verification four times. Timestamp every figure; never gate on totals (net counts can FALL while uncovered rows appear).
- A residual sweep with `grep -rn "table('site\.<t>'" app/` is **blind to Eloquent**. A table is inert only when BOTH the query-builder and Eloquent greps come back empty.
- `partna.*` surface identity lives in `site.platform_connections.surface_key`, **not** `platform`. Filtering on `platform LIKE 'partna.%'` returns 0 for the wrong reason.
- `IntegrationConnection::RETIRED_SURFACES` is a **six-item enumeration**, not `partna.*`. `partna.manual_product` is hidden but deliberately NOT retired.

**Production carries NONE of this.** Prod is missing the `content`, `ingest`, `routing` and `catalog` schemas **outright** — `content.items` does not exist there — and its ledger holds 4 rows (latest `20260803100001`) against dev's 106 (verified 2026-08-17 11:06 UTC). Prod still has all ten dropped tables. This is not "slightly behind": it is a different schema. Scope: `docs/superpowers/plans/2026-08-17-prod-schema-reconciliation.md`.

## Load-testing harness (`scripts/launch-check/k6/`)

DIY k6 harness against dev only (README + plan: `docs/superpowers/plans/2026-07-26-k6-load-testing.md`). ⚠️ **Currently unrunnable:** `seed.sql` still inserts into `site.services`, DROPPED 2026-08-18 by the services cutover, so the seed raises `42P01` before it finishes. Fix that before trusting any run. Its `seed.sql`/`jobs.js` hard-code 3 real invariants that silently broke them once already — touching any of these, re-check the harness:
- ~~Gallery capped at 6/site~~ **RETIRED 2026-09-02.** The `gallery` pool and its `core.enforce_site_gallery_max6` trigger are both dropped (migration `20260902170000`); `seed.sql` now seeds the `content` pool, capped at 20 by `config('partna.image_pools.content.max')`. The 6 it still seeds is a historical constant so past baseline results stay comparable, not a ceiling.
- A media item needs a matching `site.media_variants` (webp) row or its URL resolves empty — guarded by `tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php`'s gallery-engine tests.
- Analytics writes need an `Origin` header matching the site's subdomain (SEC-1) — guarded by `tests/Feature/Security/TenantIsolation/PublicAnalyticsIdorTest.php`. The `Referer` fallback was REMOVED 2026-08-24 (#SEC-3): a non-browser caller sets Referer as freely as any other header, so it authenticated nothing. All eight ingest routes are POST, where a browser always sends `Origin` — the harness already does. Do not reinstate it.

## Do NOT

- Create Laravel migration files (use `supabase/migrations/` raw SQL)
- Modify `.env` directly — reference `.env.example`
- Return raw Eloquent from API endpoints (use Resource classes)
- Over-engineer simple fixes
- Reintroduce `site.themes` or `settings.design.*`
- Reintroduce any of the ten dropped legacy tables, or write a new read path against one — curated content lives in `content.*`
- Reintroduce the pseudo-platform link lane (retired 2026-08-19): no `custom`/`booking`/`reservations`/`online-ordering`/`events-custom` category controllers or `partna.*_link`/`partna.manual_event` surfaces. Every routed link goes `LinkRouter`/`LinkRoutingService` → `SourceReconciler` → its real brand surface; a taken single-slot brand answers 422 `slot_taken` (manual) or a Swap suggestion in the `/routing/suggestions` inbox (auto); a standalone event is an events-POOL item (`ManualEventWriter`), never a connection row
- Delete a table-less DTO model (`MenuItem`, `MenuCategory`, `MenuItemPlatform`, `Service`, `ServiceCategory`, `ShopBrand`) or move one into `PurgeSoftDeleted::PURGE_HANDLED`
- Mutate a pool without all three cache lanes (build state + `site.sites.updated_at` + edge purge)
- Assume dev's schema says anything about production's — prod lacks four whole schemas
- Re-add `analytics.site_metrics_daily`/`_hourly`, `content.source_routes` or `content.item_refs` — dropped 2026-08-19 (`20260819140000`), all four writerless. `content.f_file` looks identical and is NOT dead: it is a live facet in `ProjectionWriter`/`KindRegistry`/`FacetRegistry`, empty only because the `document` kind is poolless
