# Foundation Audit v3 — Prompt & Lens Table

**Date:** 2026-05-31 · **Branch:** `development` · **Output dir:** `audits/foundation-audit-v3/`

This is the driver document for the third whole-backend audit. It defines the goal,
scope, the 26-lens table (lens prompt text + scope paths), how to run the pipeline,
and the consolidated output format. It follows the same machinery as the two prior
runs:

- **foundation-audit-v2** (2026-05-25, 25 lenses) — broad correctness/security/infra. Driver: `scripts/audit/run-foundation-v2.sh`.
- **standalone-pages** (2026-05-19, 12 lenses) — the scaling-heavy set (write-amplification, db/queue scaling, lifecycle-at-scale). Driver: `audits/standalone-pages/AUDIT-SCOPE.md`.

v3 = the v2 superset, re-weighted with the standalone-pages scaling lenses, and framed
explicitly around **two questions at once**: *prepilot defects* and *behaviour at scale
(10,000 site pages, tens of thousands of concurrent public visitors)*.

---

## Goal

Find problems on two axes simultaneously, for every lens:

1. **Prepilot correctness & security** — defects that would bite the first real users:
   auth/tenant-isolation holes, data-integrity gaps, broken lifecycle flows, PII/secret
   leakage, GDPR completeness, raw-model leakage, idempotency bugs.
2. **Scale to 10k site pages** — code that is *correct at 1 user* but *degrades, saturates,
   or melts at 10k sites / high concurrent public traffic*: N+1 on hot read paths,
   unbounded result sets, cache stampede, write amplification, KV/cache rebuild storms,
   Supavisor connection-pool exhaustion, queue-lane saturation, synchronous work in the
   request path.

Every lens prompt below carries an explicit **"@10k"** instruction so the scanner reasons
about both axes, not just point correctness.

> **Audit ≠ completeness.** An audit finds defects in code that *exists*; it will not
> surface a *missing* endpoint or *missing* index. Pair this with a journey walk-through
> (signup → dashboard editor → public `<handle>.partna.au` page) to confirm required
> endpoints and hot-path indexes are actually built. Note gaps in the consolidation's
> "Coverage gaps" section.

---

## Scope — the whole backend

Since the standalone strip (2026-05-22) the backend serves exactly one product surface:
individual professionals' public site pages. There is no brand/affiliate/Shopify/Stripe/
commerce code left. In scope:

- `app/` — controllers, services, jobs, models, observers, policies, resources, middleware,
  requests, enums, mail, listeners, providers, rules, contracts, DTOs, support.
- `routes/` — `api.php`, `api/{user,publicSite,staff}.php`, `console.php`, `web.php`.
- `config/` — all config, especially `config/partna.php` (flags/limits) and `config/database.php` (Supavisor).
- `supabase/migrations/` — the consolidated baseline + incremental migrations.
- `cloudflare-worker/src/index.js` — edge router / cache / KV reader.
- `.github/workflows/ci.yml`, `composer.json`, `composer.lock`, `.env.example` — deploy/CI/deps.

**New domains since v2** (must be covered — they were not in the v2 table): `app/Services/Moderation`,
`app/Services/BotProtection`, `app/Services/Audit`, `app/Services/Diagnostics`, `app/Services/Feedback`,
`app/Services/Email`, `app/Jobs/Account`, `app/Jobs/Moderation`, `app/Models/Moderation`,
`app/Notifications/`, `app/Rules/`, `app/Support/`. These are folded into the relevant lenses below.

**Hardening that must not regress** (flag any finding that weakens these): SWR/single-flight
cache core (`CacheLockService`, `SiteCacheService`); MFA/AAL2 (`VerifySupabaseJwt`, `RequireAal2`,
`require.aal2`); notification idempotency; GDPR deletion/export path; Policy-based authz
(`BasePolicy`, the inline-403 CI guard, `PolicyCoverageTest`); SUBDOMAIN_KV single-writer invariant
(`SyncSubdomainToKvJob`).

---

## The 26 lenses

~24 thematic lenses, with the three historically-oversized themes **pre-split** so no lens
returns truncated (the v2 run lost "Lens 16" to scope overflow and had to re-fire it as 5
narrower lenses — v3 avoids that by splitting up front):

- **Authorization** → 2 lenses (policy/route coverage · IDOR/tenant-isolation/validation)
- **GDPR** → 2 lenses (deletion cascade · DSAR export)
- **Scale @10k** → 4 lenses (N+1/unbounded reads · cache stampede · write-amplification · DB/queue saturation)

Each row: the **lens prompt** is the verbatim text fed to the scanner; **scope** is the
space-separated `--scope` path list. Lenses marked **[@10k]** must weight the scale axis
heavily; all lenses still report point-correctness defects too.

### Group A — Authorization & authentication

| # | Lens prompt | Scope |
|---|-------------|-------|
| 1 | Policy coverage gaps, 403 vs 404 leakage, route middleware gaps, missing `Gate::policy` registrations, inline-403 aborts bypassing BasePolicy, public-endpoint enumeration via 403, staff vs user route guard drift | `app/Policies app/Http/Controllers app/Http/Middleware routes` |
| 2 | IDOR and tenant isolation — cross-user data access, missing user_id scoping on queries, mass-assignment, unvalidated request params, Form Request coverage gaps, handle/subdomain resolution authz @10k (resolver must not leak another user's site) | `app/Http/Controllers app/Http/Requests app/Rules app/Services/PublicSite app/Services/Site` |
| 3 | JWT verification gaps, AAL2 bypass, MFA enforcement holes, claim trust, token replay, `aal`/`amr` attribute handling, fresh-AAL2 policy gaps | `app/Services/Auth app/Http/Middleware/Auth app/Exceptions/Auth` |
| 4 | AccountCapabilities bypass — missing capability checks before notification/API/job actions; AccountType enum integrity (only `Individual` case — stale rows throw ValueError); moderation/feedback actions gated by capabilities | `app/Services/Accounts app/Services/Moderation app/Services/Feedback app/Jobs/Notifications app/Jobs/Moderation app/Http/Controllers app/Enums app/Models/Core` |

### Group B — Data & schema correctness

| # | Lens prompt | Scope |
|---|-------------|-------|
| 5 | Missing RLS policies, role grants too permissive, `app_backend` privileges (audit schema must be SELECT/INSERT only), schema-level authz gaps, unsafe seed data, search_path correctness, Supabase project config | `supabase/migrations supabase/seed.sql supabase/config.toml config/database.php` |
| 6 | Missing transactions, race conditions, soft-delete consistency, FK/unique constraint gaps, N+1 writes, observer side-effects outside transactions, double-dispatch on retried writes | `app/Services app/Models app/Observers app/Jobs supabase/migrations` |
| 7 | Migration safety — lock-on-deploy risk (non-CONCURRENTLY index builds, ALTER on large tables), backfill ordering, baseline/incremental drift, CHECK constraints that reject valid app inputs, missing hot-path indexes for 10k-row tables `[@10k]` | `supabase/migrations supabase/config.toml` |

### Group C — Privacy & GDPR

| # | Lens prompt | Scope |
|---|-------------|-------|
| 8 | GDPR deletion completeness — cascade gaps, models missing from the deletion flow, soft-delete vs hard-delete correctness, retention enforcement, orphaned rows/media after account deletion | `app/Jobs/Gdpr app/Jobs/Account app/Exceptions/Gdpr app/Services/User app/Services/Accounts app/Models supabase/migrations` |
| 9 | GDPR data export (DSAR) integrity & completeness — every PII-bearing table represented, export audit trail, email-sent tracking, no missing relations, export job idempotency | `app/Jobs/Gdpr app/Mail/Gdpr app/Services/User app/Models app/Http/Controllers` |

### Group D — API contract

| # | Lens prompt | Scope |
|---|-------------|-------|
| 10 | Raw Eloquent model leakage (controllers returning models without a Resource), inconsistent Resource shapes, JSONB column leakage (`settings`, design kit), missing pagination/filtering contracts, breaking-change risk, envelope-key drift (`meta` vs `pagination`) | `app/Http/Resources app/Http/Controllers routes` |

### Group E — Scale to 10k site pages `[@10k]`

| # | Lens prompt | Scope |
|---|-------------|-------|
| 11 | N+1 queries, unbounded result sets, missing eager-loading, missing pagination on hot read paths (public profile resolve, dashboard lists, analytics reads); queries whose row count grows with sites/customers/enquiries/visits at 10k scale `[@10k]` | `app/Http/Controllers app/Services/PublicSite app/Services/Site app/Services/Analytics app/Http/Resources app/Models` |
| 12 | Cache invalidation gaps, stampede risk, SWR/single-flight correctness (`CacheLockService`, `SiteCacheService`), stale reads, KV/Redis/HTTP cache layering, thundering-herd on cold profile reads when 10k pages expire together `[@10k]` | `app/Services/Cache app/Jobs/Cache app/Observers app/Services/PublicSite app/Services/Site` |
| 13 | Write amplification & write fan-out — per-write cache busting, per-write KV sync, observer cascades that multiply work, rebuild storms when one change invalidates many entries; cost of a single dashboard edit at 10k sites `[@10k]` | `app/Observers app/Jobs/Cache app/Jobs/Cloudflare app/Services/Cache app/Services/Cloudflare` |
| 14 | Database & queue saturation — Supavisor connection-pool exhaustion (session vs transaction mode), queue-lane saturation, Horizon throughput, synchronous work in the request path that belongs in a job, analytics-ingest backpressure at high visit volume `[@10k]` | `config/database.php config/queue.php config/horizon.php app/Jobs app/Services/Analytics app/Http/Controllers` |

### Group F — Jobs & scheduling

| # | Lens prompt | Scope |
|---|-------------|-------|
| 15 | Non-idempotent jobs, unsafe retries, missing `failed()` handlers, wrong queue lane, unbounded backoff, missing `report()` in failure paths, at-least-once delivery assumptions (duplicate emails/notifications), serialized PII in job payloads | `app/Jobs app/Jobs/Concerns` |
| 16 | Scheduler safety — missing `withoutOverlapping` locks, missing `onOneServer`, silent scheduled-task failures, missing critical schedules (alias prune, retention), frequency-vs-runtime mismatch (lock timeout = cadence creates a race) | `routes/console.php app/Console/Commands app/Jobs` |

### Group G — Edge routing & media

| # | Lens prompt | Scope |
|---|-------------|-------|
| 17 | SUBDOMAIN_KV single-writer invariant violations (only `SyncSubdomainToKvJob` may write KV), KV/DB drift, sync job idempotency, alias `expirationTtl` correctness, 301 alias-vs-canonical correctness at scale `[@10k]` | `app/Services/Cloudflare app/Jobs/Cloudflare app/Observers cloudflare-worker/src/index.js routes` |
| 18 | Cloudflare worker signature verification, R2 presigned URL leakage, public bucket scope, edge cache `caches.default.put` correctness, service-binding fallback safety | `app/Services/Cloudflare app/Services/Media cloudflare-worker/src/index.js` |
| 19 | Media/video pipeline — presigned URL leakage, storage authz, orphaned media after delete failures, MIME validation before public-bucket write (spoofed-extension XSS/phishing), variant-generation idempotency | `app/Services/Media app/Services/Streaming app/Jobs/Streaming` |

### Group H — Web hardening

| # | Lens prompt | Scope |
|---|-------------|-------|
| 20 | Rate-limiting coverage on public + auth routes, throttle bypass, CORS misconfig, bot-protection coverage on enquiry/feedback/signup endpoints, abuse surface at 10k-visitor traffic `[@10k]` | `routes app/Http/Middleware app/Services/BotProtection app/Providers config/cors.php bootstrap` |
| 21 | Security header gaps, HTTPS enforcement, frame/CSP/HSTS posture, header coverage on public site responses | `app/Http/Middleware bootstrap/app.php app/Providers` |
| 22 | Webhook signature verification, Supabase email-hook auth, third-party webhook replay risk, internal-API auth weakness, moderation/CSAM webhook auth (if present) | `app/Http/Controllers/Api/Internal app/Http/Middleware/Auth app/Services/Auth routes` |

### Group I — Config, bootstrap & deploy

| # | Lens prompt | Scope |
|---|-------------|-------|
| 23 | Env reads outside the config layer, hardcoded secrets, dangerous config defaults, feature-flag determinism, diagnostic-info leakage, plaintext credentials/PII in logs and exception messages | `config app/Services/FeatureFlags app/Services/Diagnostics app/Services/Auth app/Services/Media app/Services/Streaming app/Console/Commands` |
| 24 | Bootstrap & providers — global middleware order bugs, exception render leakage, route-model-binding misuse, Laravel 12 bootstrap config drift, dangerous singletons, service-provider boot bugs, mail-send layer correctness (mail XSS, unsigned mail links, PII in emails) | `bootstrap app/Exceptions app/Http/Middleware app/Providers app/Mail resources/views/emails app/Services/Email` |
| 25 | Deploy script safety, CI workflow secrets handling, action permission scope, dangerous post-deploy hooks, `.env.example` drift vs config, composer-script footguns; plus `composer audit` (CVEs) and `composer outdated --direct` (drift) | `.github/workflows/ci.yml composer.json composer.lock .env.example scripts` |

### Group J — Observability, architecture & tests

| # | Lens prompt | Scope |
|---|-------------|-------|
| 26 | Observability & architecture hygiene & test coverage — missing structured log context, PII in logs, silent catch blocks, exception/slow-job coverage gaps, audit-log integrity; service-boundary correctness, fat controllers with business logic, dead code post-strip; test coverage of critical paths (idempotency, race safety, auth gates, deletion cascade, cache correctness) | `app/Services/Audit app/Services app/Http/Controllers app/Http/Concerns app/Http/Middleware/Logging tests` |

> Lens 26 is intentionally broad; if the scan truncates, re-fire it as three narrower lenses
> (observability / architecture-hygiene / test-coverage) — the same reactive split the v2 run
> used for its overflowed lens.

---

## How to run

The pipeline is unchanged from v2 (`scripts/audit/run-foundation-v2.sh` is the reference
implementation). Two options:

### Option 1 — clone the v2 driver (fastest)

Copy `scripts/audit/run-foundation-v2.sh` → `run-foundation-v3.sh`, set
`PHASE="foundation-audit-v3"`, and replace the `LENSES` heredoc with the block below
(each line is `lens text|||space-separated scope paths`, exactly the format the v2 driver
parses). The driver already does: P=4 parallel, resumable (skips lenses whose output exists),
Phase 2 `composer audit`/`outdated`, Phase 3 consolidation.

```
Policy coverage gaps, 403 vs 404 leakage, route middleware gaps, missing Gate::policy registrations, inline-403 aborts bypassing BasePolicy, public-endpoint enumeration via 403, staff vs user route guard drift|||app/Policies app/Http/Controllers app/Http/Middleware routes
IDOR and tenant isolation, cross-user data access, missing user_id scoping, mass-assignment, unvalidated request params, Form Request coverage gaps, handle/subdomain resolution authz at 10k scale|||app/Http/Controllers app/Http/Requests app/Rules app/Services/PublicSite app/Services/Site
JWT verification gaps, AAL2 bypass, MFA enforcement holes, claim trust, token replay, aal/amr attribute handling, fresh-AAL2 policy gaps|||app/Services/Auth app/Http/Middleware/Auth app/Exceptions/Auth
AccountCapabilities bypass, missing capability checks before notification/API/job actions, AccountType enum integrity, moderation/feedback actions gated by capabilities|||app/Services/Accounts app/Services/Moderation app/Services/Feedback app/Jobs/Notifications app/Jobs/Moderation app/Http/Controllers app/Enums app/Models/Core
Missing RLS policies, role grants too permissive, app_backend privileges, audit schema SELECT/INSERT only, schema-level authz gaps, unsafe seed data, search_path correctness, Supabase project config|||supabase/migrations supabase/seed.sql supabase/config.toml config/database.php
Missing transactions, race conditions, soft-delete consistency, FK/unique constraint gaps, N+1 writes, observer side-effects outside transactions, double-dispatch on retried writes|||app/Services app/Models app/Observers app/Jobs supabase/migrations
Migration safety, lock-on-deploy risk, non-CONCURRENTLY index builds, backfill ordering, baseline/incremental drift, CHECK constraints rejecting valid inputs, missing hot-path indexes for 10k-row tables|||supabase/migrations supabase/config.toml
GDPR deletion completeness, cascade gaps, models missing from deletion flow, soft vs hard delete correctness, retention enforcement, orphaned rows/media after account deletion|||app/Jobs/Gdpr app/Jobs/Account app/Exceptions/Gdpr app/Services/User app/Services/Accounts app/Models supabase/migrations
GDPR data export DSAR integrity and completeness, every PII-bearing table represented, export audit trail, email-sent tracking, missing relations, export job idempotency|||app/Jobs/Gdpr app/Mail/Gdpr app/Services/User app/Models app/Http/Controllers
Raw Eloquent model leakage, inconsistent Resource shapes, JSONB column leakage, missing pagination/filtering contracts, breaking-change risk, envelope-key drift|||app/Http/Resources app/Http/Controllers routes
N+1 queries, unbounded result sets, missing eager-loading, missing pagination on hot read paths, queries whose row count grows with sites/customers/enquiries/visits at 10k scale|||app/Http/Controllers app/Services/PublicSite app/Services/Site app/Services/Analytics app/Http/Resources app/Models
Cache invalidation gaps, stampede risk, SWR/single-flight correctness, stale reads, KV/Redis/HTTP cache layering, thundering-herd on cold profile reads when 10k pages expire together|||app/Services/Cache app/Jobs/Cache app/Observers app/Services/PublicSite app/Services/Site
Write amplification and write fan-out, per-write cache busting, per-write KV sync, observer cascades multiplying work, rebuild storms, cost of a single dashboard edit at 10k sites|||app/Observers app/Jobs/Cache app/Jobs/Cloudflare app/Services/Cache app/Services/Cloudflare
Database and queue saturation, Supavisor connection-pool exhaustion, queue-lane saturation, Horizon throughput, synchronous work in request path, analytics-ingest backpressure at high visit volume|||config/database.php config/queue.php config/horizon.php app/Jobs app/Services/Analytics app/Http/Controllers
Non-idempotent jobs, unsafe retries, missing failed() handlers, wrong queue lane, unbounded backoff, missing report() in failure paths, at-least-once duplicate delivery, serialized PII in job payloads|||app/Jobs app/Jobs/Concerns
Scheduler safety, missing withoutOverlapping locks, missing onOneServer, silent scheduled-task failures, missing critical schedules, frequency vs runtime mismatch|||routes/console.php app/Console/Commands app/Jobs
SUBDOMAIN_KV single-writer invariant violations, KV/DB drift, sync job idempotency, alias expirationTtl correctness, 301 alias-vs-canonical correctness at scale|||app/Services/Cloudflare app/Jobs/Cloudflare app/Observers cloudflare-worker/src/index.js routes
Cloudflare worker signature verification, R2 presigned URL leakage, public bucket scope, edge cache caches.default.put correctness, service-binding fallback safety|||app/Services/Cloudflare app/Services/Media cloudflare-worker/src/index.js
Media/video pipeline, presigned URL leakage, storage authz, orphaned media after delete failures, MIME validation before public-bucket write, variant-generation idempotency|||app/Services/Media app/Services/Streaming app/Jobs/Streaming
Rate-limiting coverage on public and auth routes, throttle bypass, CORS misconfig, bot-protection coverage on enquiry/feedback/signup endpoints, abuse surface at 10k-visitor traffic|||routes app/Http/Middleware app/Services/BotProtection app/Providers config/cors.php bootstrap
Security header gaps, HTTPS enforcement, frame/CSP/HSTS posture, header coverage on public site responses|||app/Http/Middleware bootstrap/app.php app/Providers
Webhook signature verification, Supabase email-hook auth, third-party webhook replay risk, internal-API auth weakness, moderation webhook auth|||app/Http/Controllers/Api/Internal app/Http/Middleware/Auth app/Services/Auth routes
Env reads outside the config layer, hardcoded secrets, dangerous config defaults, feature-flag determinism, diagnostic-info leakage, plaintext credentials/PII in logs and exception messages|||config app/Services/FeatureFlags app/Services/Diagnostics app/Services/Auth app/Services/Media app/Services/Streaming app/Console/Commands
Bootstrap and providers, global middleware order bugs, exception render leakage, route-model-binding misuse, Laravel 12 bootstrap drift, dangerous singletons, service-provider boot bugs, mail-send layer correctness, mail XSS, unsigned mail links, PII in emails|||bootstrap app/Exceptions app/Http/Middleware app/Providers app/Mail resources/views/emails app/Services/Email
Deploy script safety, CI workflow secrets handling, action permission scope, dangerous post-deploy hooks, env.example drift vs config, composer-script footguns|||.github/workflows/ci.yml composer.json composer.lock .env.example scripts
Observability and architecture hygiene and test coverage, missing structured log context, PII in logs, silent catch blocks, exception/slow-job coverage gaps, audit-log integrity, service-boundary correctness, fat controllers, dead code post-strip, test coverage of critical paths|||app/Services/Audit app/Services app/Http/Controllers app/Http/Concerns app/Http/Middleware/Logging tests
```

### Option 2 — per-lens, ad hoc

Run any single lens directly (useful for re-firing one that truncated):

```bash
scripts/audit/audit.sh \
  --phase foundation-audit-v3 \
  --lens "<lens text from the table>" \
  --scope <path> [--scope <path> ...]
# → audits/foundation-audit-v3/audit-2026-05-31-<slug>.md
```

After all 26 land, run the dependency advisories and the consolidation pass (below).

---

## Consolidation pass → the single `CONSOLIDATED.md`

After Phase 1 (26 per-lens audits) and Phase 2 (`composer audit` + `composer outdated --direct`),
run one Sonnet consolidation pass that reads all per-lens files + the composer outputs, verifies
each finding against the live code (Read/Grep), and emits **one** document:

`audits/foundation-audit-v3/audit-2026-05-31-CONSOLIDATED.md`

> **Template:** use **`audits/foundation-audit-v1/audit-2026-05-24-CONSOLIDATED.md`** as the
> structural template. **Do NOT** use the v2 CONSOLIDATED — that run died mid-generation and
> its file is a 1-line session-limit stub.

### Consolidator system prompt (carry verbatim)

```
You are the v3 consolidator for the Partna foundation audit. Your job:

1. Read the 26 lens audits + composer audit + composer outdated outputs.
2. Cross-reference every finding against the current codebase via Read/Grep/Glob BEFORE
   including it. If you cannot verify a finding names a real file/symbol/line, DROP it
   (precision > recall).
3. Produce ONE consolidated markdown document matching the v1 CONSOLIDATED template's
   structure section-by-section.

Dual-axis tagging: this audit targets BOTH prepilot correctness AND scale to 10k site
pages. Tag every scale-driven finding with `[@10k]` in its title so the reader can triage
"breaks now" vs "breaks at scale". Severity reflects pilot impact: a 10k-only degradation
is usually P2/P3 unless it also corrupts data or breaks correctness today.

Format invariants (the audit orchestrator parses these files — match v1 exactly):

- `# Consolidated Foundation Audit v3 — 2026-05-31`
- One-liner: Branch / source count / raw count / final count / bundle count.
- "Read before fixing" pointer paragraph (the #P0-/#P1- + lens-backref scheme).
- `## Model selection — read once` — copy v1's text verbatim (standing convention).
- `## Dependency advisories` with `### Security CVEs` and `### Direct dependency drift`.
- `## Cross-lens high-confidence findings` — themes that surface under 2+ lenses.
- `## P0`, `## P1`, `## P2`, `## P3` — P2/P3 sub-grouped by theme.
- `## Suggested bundled fix sessions` — `### Bundle BN: <title> (N items) — Effort: S/M/L/XL`
  blocks, each with rationale, approach, dependencies, AND the two Session prompts
  (Implementation + Review).
- `## Standalone — do NOT bundle`.
- `## Deduplication notes`.
- `## Coverage report` with `### Coverage by lens` table and `### Coverage gaps`.
- Final line: `*Generated 2026-05-31 by ...*`.

Per-finding format (every P0/P1/P2/P3 entry):
- [ ] **#PN-NN** <one-line title, with [@10k] if scale-driven> — Lens: `<lens-shortname>`
    - Where: <file>:<line> (· optional more files)
    - What: <2–4 sentence context>
    - Fix: <actionable description>
    - Models: impl=<x> · review=<y>

Bundle entries:
### Bundle BN: <title> (N items — #P*-XX..) — Effort: S/M/L/XL
- [ ] bundle status checkbox
- Items: `#P*-XX`, ...
- Models: impl=<x> · review=<y>
- Rationale / Suggested approach / Dependencies
**Session prompts** (Implementation + Review blockquotes).

Model selection rules (fill the Models: lines):
- haiku — trivial mechanical changes (delete a line, add a default, single-line report($e)).
- sonnet — default for most implementation (refactors, Resources, observers, queue swaps, migrations, new services).
- opus — load-bearing invariants (auth gates, RLS policies, transaction boundaries, single-writer KV contract, GDPR/PII flows, schema migrations).
- Review with opus on auth/RLS/KV/transactions/GDPR/PII/schema/mail; sonnet elsewhere. Never review with haiku.

Bundling rules:
- 2–6 findings per bundle, grouped by root cause + atomic-PR size.
- Items touching single-writer KV, schema-wide RLS, or needing human design decisions go in `## Standalone — do NOT bundle`.

Diff-vs-prior awareness:
- Many v1/v2 findings are already fixed — check the recent commit log. If a lens doesn't
  surface an old item, that's expected; don't re-add it from memory. Mark genuinely new
  findings as new in a Run-notes paragraph.

Output rules:
- No preamble. Start at the first `#`. No closing chatter. No code-fence wrapping the whole output. Raw markdown.
```

The v2 driver invokes this as:

```bash
claude -p --model sonnet \
  --system-prompt "$(<consolidator-system-prompt.md)" \
  --disallowed-tools "Bash Edit Write NotebookEdit WebFetch WebSearch Skill Agent ..." \
  --max-budget-usd 6.00 --output-format text --no-session-persistence \
  < consolidation-input.md > audits/foundation-audit-v3/audit-2026-05-31-CONSOLIDATED.md
```

where `consolidation-input.md` inlines: today's date + branch, `git log --oneline -30`
(to validate fixes since v1/v2), the **v1 CONSOLIDATED** as the structural template, all 26
per-lens audit files, and the two composer outputs. (Phase 3 of `run-foundation-v2.sh` builds
this input file automatically — reuse it.)

---

## Output format reference (what a finding looks like)

From the v1 CONSOLIDATED — the exact shape every P0–P3 checkbox must take:

```
- [ ] **#P1-03** R2 public bucket accepts arbitrary MIME via spoofed extensions — Lens: `MEDIA-1`
    - Where: `app/Services/Media/ImageVariantService.php:219–227`
    - What: storeOriginal() runs synchronously before the async MIME-validating job. A user
      uploads phishing.html renamed .jpg; it lands on the public R2 bucket at a predictable URL.
    - Fix: add finfo(FILEINFO_MIME_TYPE) sniff at the top of storeOriginal() against
      ALLOWED_IMAGE_MIMES; throw UnprocessableImageException on mismatch, before disk()->put().
    - Models: impl=sonnet · review=opus
```

Tier meaning: **P0** = must fix before adding new features / blocks pilot · **P1** = fix soon ·
**P2** = should fix · **P3** = nice-to-have / cleanup. Checkboxes (`- [ ]`) let the audit
orchestrator (`audit` CLI) drive unattended fix sessions over this file.

---

## Cost & timing (from prior runs)

26 lenses × (DeepSeek scan + Sonnet adjudicate) at P=4, plus one Sonnet consolidation. Prior
per-lens audits ran ~$0.06–0.25 / ~5–7 min each; the full v2 run (25 lenses + consolidation)
was the bulk of an afternoon's compute. Budget the consolidation at `--max-budget-usd 6.00`.
