# Audit campaigns — tiered, size-bounded audit plans

Copy-pasteable prompts for running a themed audit across the backend in tiers.
Each tier is a single prompt you can hand to Claude; each run inside it is also
listed as a raw command if you'd rather drive it yourself.

## Why these scopes look the way they do

**Narrow scope finds more than wide scope.** Measured 2026-07-19 with 10
deliberately planted findings, same lens, same files:

| Payload | Planted items found |
|---|---|
| 2 KB | **10 / 10** |
| 669 KB | **8 / 10** |

`audit-scan.sh` warns once a payload passes ~100K tokens (~400 KB); that warning
is real. **Every scope group below is measured and sits under 350 KB.** Coverage
and detection pull in opposite directions — adding files to a run takes attention
away from the files already in it.

Consequence: **a `--codebase` sweep is a coverage instrument, not a discovery
one.** Use it to answer "is anything obviously wrong anywhere". Use the campaigns
below to actually hunt.

## Rules

- **One `audit.sh` at a time.** They share the local `claude` CLI budget.
- **Tiers are stop-anywhere.** Tier 1 is the highest risk-per-token; stopping
  after it is a legitimate choice, not a half-finished job.
- Each run writes `audits/<category>/<date>-<name>/CONSOLIDATED.md`.
- Fix them with `execute audit <path>` (runbook: `fix-flow.md`).
- A failed run now writes **no** audit and exits non-zero (as of 2026-07-19) —
  if a `CONSOLIDATED.md` exists, adjudication genuinely succeeded.

---

# Campaign 1 — Security

Bundle: `--bundle security` (security · schema-rls · configuration-hygiene ·
edge-worker · privacy-compliance), 5 lenses per run.

## Tier 1 — user-reachable attack surface (8 runs)

> **Prompt:**
> Run Tier 1 of the security campaign in `scripts/audit/campaigns.md`. Execute the
> 8 runs **sequentially** — never two `audit.sh` at once — waiting for each to
> finish before starting the next. After each run, report the tier counts and the
> folder path only; do not paste the file contents. If a run fails, stop and tell
> me rather than continuing. When all 8 are done, give me a consolidated summary
> of P0/P1 findings across every run, deduplicated, ranked by severity.

```bash
A=scripts/audit/audit.sh
$A --category security --name preaccount-claim --bundle security \
  --scope app/Services/PreAccount --scope app/Models/Core/User                      # 42 KB
$A --category security --name public-surface --bundle security \
  --scope app/Http/Controllers/Api/PublicSite --scope app/Http/Requests/Api/PublicSite  # 138 KB
$A --category security --name authz-core --bundle security \
  --scope app/Http/Middleware --scope app/Policies --scope app/Services/Auth \
  --scope app/Providers --scope app/Rules --scope app/Exceptions \
  --scope app/Http/Controllers/Concerns --scope app/Http/Controllers/Controller.php \
  --scope app/Http/Controllers/Api/ApiController.php \
  --scope app/Http/Controllers/Api/HealthController.php                             # 274 KB
$A --category security --name user-api --bundle security \
  --scope app/Http/Controllers/Api/User                                             # 195 KB
$A --category security --name requests-resources --bundle security \
  --scope app/Http/Requests --scope app/Http/Resources                              # 171 KB
$A --category security --name staff-api --bundle security \
  --scope app/Http/Controllers/Api/Staff                                            # 161 KB
$A --category security --name webhooks-internal --bundle security \
  --scope app/Http/Controllers/Api/Webhooks --scope app/Http/Controllers/Api/Internal \
  --scope app/Services/Webhooks                                                     # 23 KB
$A --category security --name models-data --bundle security \
  --scope app/Models --scope app/Enums --scope app/DTOs                             # 141 KB
```

**Why these first:** everything an unauthenticated or authenticated user can
reach directly. `preaccount-claim` leads because it is new, unaudited, and the
only place two strangers race for the same resource. `authz-core` matters
disproportionately here — under Supabase JWT `Auth::user()` is always null, so a
stray `authorize()` instead of `authorizeForUser()` **silently passes**.

## Tier 2 — outbound, integrations, wiring (6 runs)

> **Prompt:**
> Run Tier 2 of the security campaign in `scripts/audit/campaigns.md` — the 6
> outbound/integration/wiring runs. Same rules: sequential, report counts + paths
> only, stop on failure. Summarise P0/P1 at the end. Pay particular attention to
> SSRF regressions: `InstagramController::mirror()` was a CRITICAL SSRF fixed on
> 2026-06-03 with `SafeUrlFetcher` + host allowlist + image-only content-type —
> flag anything that bypasses that path.

```bash
A=scripts/audit/audit.sh
$A --category security --name platforms-api --bundle security \
  --scope app/Http/Controllers/Api/Platforms                                        # 232 KB
$A --category security --name platforms-svc-a --bundle security \
  $(printf -- '--scope %s ' app/Services/Platforms/[A-M]*.php)                      # 334 KB
$A --category security --name platforms-svc-b --bundle security \
  $(printf -- '--scope %s ' app/Services/Platforms/[N-Z]*.php) \
  --scope app/Services/Platforms/Strategies --scope app/Services/Platforms/Registry \
  --scope app/Services/Platforms/Payloads --scope app/Services/Platforms/Normalizers \
  --scope app/Services/Platforms/Concerns                                           # 261 KB
$A --category security --name outbound-ssrf --bundle security \
  --scope app/Services/Http --scope app/Services/Media \
  --scope app/Services/Streaming --scope app/Services/Cloudflare                    # 152 KB
$A --category security --name wiring --bundle security \
  --scope routes --scope config --scope bootstrap/app.php \
  --scope bootstrap/providers.php                                                   # 262 KB
$A --category security --name edge-worker --bundle security \
  --scope cloudflare-worker/src                                                     # 23 KB
```

## Tier 3 — completeness (7 runs)

> **Prompt:**
> Run Tier 3 of the security campaign in `scripts/audit/campaigns.md` — the 7
> completeness runs covering internal services, jobs, console, design and
> migrations. Sequential, counts + paths only, stop on failure, summarise P0/P1
> at the end. These are defence-in-depth on code not externally reachable, so
> down-rank anything whose only exploit path requires already-compromised access.

```bash
A=scripts/audit/audit.sh
$A --category security --name domain-services --bundle security \
  --scope app/Services/Site --scope app/Services/PublicSite \
  --scope app/Services/User --scope app/Services/Accounts                           # 319 KB
$A --category security --name domain-services-b --bundle security \
  --scope app/Services/Segments --scope app/Services/Moderation \
  --scope app/Services/Analytics --scope app/Services/Cache --scope app/Services/Audit  # 264 KB
$A --category security --name jobs --bundle security --scope app/Jobs              # 249 KB
$A --category security --name console --bundle security --scope app/Console        # 140 KB
$A --category security --name design --bundle security --scope app/Services/Design # 240 KB
$A --category security --name notif-mail-rest --bundle security \
  --scope app/Observers --scope app/Notifications --scope app/Mail \
  --scope app/Services/Notifications --scope app/Services/Profile \
  --scope app/Services/FeatureFlags --scope app/Services/FeatureAvailability \
  --scope app/Services/EarlyAccess --scope app/Services/Feedback \
  --scope app/Services/Diagnostics --scope app/Services/BotProtection \
  --scope app/Listeners --scope app/Support --scope app/Contracts \
  --scope app/helpers.php                                                           # 218 KB
$A --category security --name migrations-a --bundle security \
  $(printf -- '--scope %s ' supabase/migrations/20260[1-6]*.sql)                    # 332 KB
$A --category security --name migrations-b --bundle security \
  $(printf -- '--scope %s ' supabase/migrations/20260[7-9]*.sql)                    # 212 KB
```

Tiers 1–3 together cover **1095 of 1096** backend files. Tier 1 alone covers the
externally reachable surface.

---

# Campaign 2 — Scale health

Bundle: `--bundle scale-health` (caching trio · database-and-queue-scaling ·
job-queue-correctness · observability). Graded against a 10k-user target.

## Tier 1 — the paths that melt first (3 runs)

> **Prompt:**
> Run Tier 1 of the scale-health campaign in `scripts/audit/campaigns.md`.
> Sequential, counts + paths only, stop on failure. Grade findings against a
> 10,000-user target, not today's load — we are pre-beta, so "fine now" is not a
> pass. Summarise P0/P1 at the end, and call out separately anything that would
> require a schema change to fix, since those need lead time.

```bash
A=scripts/audit/audit.sh
$A --category scale --name hot-read-paths --bundle scale-health \
  --scope app/Services/Cache --scope app/Services/Site --scope app/Services/PublicSite \
  --scope app/Services/Accounts --scope app/Http/Middleware                         # 348 KB
$A --category scale --name public-read-controllers --bundle scale-health \
  --scope app/Http/Controllers/Api/PublicSite --scope app/Http/Resources            # 217 KB
$A --category scale --name analytics-ingest --bundle scale-health \
  --scope app/Services/Analytics --scope app/Jobs/Analytics --scope app/Models/Analytics  # 140 KB
```

**Why:** public sitepage reads and analytics ingest are the only two paths that
scale with *traffic* rather than with *user count*. Note `site_metrics_daily` /
`_hourly` are defined but never populated — all analytics reads compute from raw,
which is the thing to scrutinise here.

## Tier 2 — user-facing load (3 runs)

> **Prompt:**
> Run Tier 2 of the scale-health campaign in `scripts/audit/campaigns.md` — the 3
> user-facing load runs. Sequential, counts + paths only, stop on failure. Focus
> on N+1 queries, unbounded result sets, and missing pagination. Note the deployed
> dev env currently runs `QUEUE_CONNECTION=sync` with 0 Horizon masters, so every
> job runs inline on the request — weight job-latency findings accordingly.

```bash
A=scripts/audit/audit.sh
$A --category scale --name queries-models --bundle scale-health \
  --scope app/Models --scope app/Http/Controllers/Api/User                          # 325 KB
$A --category scale --name jobs-queue --bundle scale-health \
  --scope app/Jobs --scope config/horizon.php --scope config/queue.php              # 270 KB
$A --category scale --name staff-dashboards --bundle scale-health \
  --scope app/Http/Controllers/Api/Staff --scope app/Services/Segments              # 157 KB
```

## Tier 3 — background fan-out (2 runs)

> **Prompt:**
> Run Tier 3 of the scale-health campaign in `scripts/audit/campaigns.md` — the 2
> background fan-out runs. Sequential, counts + paths only. Focus on per-provider
> rate limiting, retry storms, and scheduled-command overlap.

```bash
A=scripts/audit/audit.sh
$A --category scale --name platforms-fanout --bundle scale-health \
  --scope app/Services/Platforms/Strategies --scope app/Jobs/Platforms \
  --scope app/Services/Platforms/Registry                                           # 164 KB
$A --category scale --name console-cron --bundle scale-health \
  --scope app/Console --scope routes/console.php                                    # 155 KB
```

---

# Campaign 3 — Correctness under concurrency

Bundle: `--bundle concurrency` (caching-gold-standard · webhook-idempotency ·
transaction-boundaries). Hunts silent state drift — the bugs that never throw.

## Tier 1 — races that corrupt state (3 runs)

> **Prompt:**
> Run Tier 1 of the concurrency campaign in `scripts/audit/campaigns.md`.
> Sequential, counts + paths only, stop on failure. For every finding, state the
> concrete interleaving that produces the bad outcome — two requests, what order,
> what lands wrong. Reject anything that cannot name one.

```bash
A=scripts/audit/audit.sh
$A --category concurrency --name claim-and-provision --bundle concurrency \
  --scope app/Services/PreAccount --scope app/Services/User --scope app/Services/Site  # 238 KB
$A --category concurrency --name cache-invalidation --bundle concurrency \
  --scope app/Services/Cache --scope app/Observers \
  --scope app/Jobs/Cache --scope app/Jobs/Cloudflare                                # 139 KB
$A --category concurrency --name webhooks-idempotency --bundle concurrency \
  --scope app/Http/Controllers/Api/Webhooks --scope app/Http/Controllers/Api/Internal \
  --scope app/Services/Webhooks --scope app/Http/Middleware/Auth                    # 58 KB
```

**Why `claim-and-provision` leads:** `ClaimSiteService` runs a pinned transaction
with `lockForUpdate` on the user row and a savepoint-wrapped save to survive a
23505. First-come claim on an unclaimed site is the only genuine multi-actor race
in the product.

## Tier 2 — write paths (2 runs)

> **Prompt:**
> Run Tier 2 of the concurrency campaign in `scripts/audit/campaigns.md` — the 2
> write-path runs. Sequential, counts + paths only. Focus on transactions that
> wrap external calls or job dispatches, and on advisory-lock ordering.

```bash
A=scripts/audit/audit.sh
$A --category concurrency --name uploads-media --bundle concurrency \
  --scope app/Services/Media --scope app/Http/Controllers/Api/User/Uploads \
  --scope app/Jobs/Design                                                           # 120 KB
$A --category concurrency --name txn-controllers --bundle concurrency \
  --scope app/Http/Controllers/Api/Staff \
  --scope app/Http/Controllers/Api/User/SiteManagement                              # 208 KB
```

---

# Campaign 4 — Data integrity & privacy

Lenses: `data-integrity`, `privacy-compliance`, `schema-rls`. No single bundle
covers exactly this, so pass lens files directly.

## Tier 1 — GDPR and PII exposure (2 runs)

> **Prompt:**
> Run Tier 1 of the data & privacy campaign in `scripts/audit/campaigns.md`.
> Sequential, counts + paths only, stop on failure. This is legal-risk territory:
> for deletion findings, trace the full cascade and name any table or external
> store (Cloudflare KV, R2, Supabase auth) left holding data after the flow
> completes. Self-service deletion never soft-deletes — it goes
> active → pending_deletion → forceDelete.

```bash
A=scripts/audit/audit.sh
L=scripts/audit/lenses
$A --category privacy --name gdpr-deletion-export \
  --lens-file $L/privacy-compliance.md \
  --scope app/Services/User --scope app/Jobs/Gdpr --scope app/Jobs/Account \
  --scope app/Policies                                                              # 189 KB
$A --category privacy --name pii-schema \
  --lens-file $L/schema-rls.md \
  --scope app/Models \
  $(printf -- '--scope %s ' supabase/migrations/20260[7-9]*.sql)                    # 241 KB
```

## Tier 2 — retention and customer data (2 runs)

> **Prompt:**
> Run Tier 2 of the data & privacy campaign in `scripts/audit/campaigns.md` — the
> 2 retention/customer-data runs. Sequential, counts + paths only. Focus on
> retention windows that never actually prune, PII in logs, and over-exposure in
> API resources. The `audit` schema is append-only (SELECT/INSERT only for
> `app_backend`) — flag anything assuming it can update or delete there.

```bash
A=scripts/audit/audit.sh
L=scripts/audit/lenses
$A --category privacy --name retention-logging \
  --lens-file $L/data-integrity.md \
  --scope app/Console --scope app/Http/Middleware/Logging \
  --scope app/Services/Audit --scope app/Services/Moderation                        # 188 KB
$A --category privacy --name customer-data \
  --lens-file $L/privacy-compliance.md \
  --scope app/Http/Controllers/Api/User/Customers --scope app/Http/Resources \
  --scope app/Models/Core                                                           # 235 KB
```

---

# Campaign 5 — Code quality

Bundle: `--bundle code-quality` (code-quality-slop · semantic-correctness).
Lowest priority — run it on new code, not the whole repo.

> **Prompt:**
> Run the code-quality campaign in `scripts/audit/campaigns.md`. Sequential,
> counts + paths only. These lenses are taste and plausible-but-wrong-logic
> passes, so hold a high bar: drop anything that is merely stylistic, and drop any
> "dead code" claim not backed by a repo-wide grep. Dormant-by-design code (the
> post-strip CSAM vocab, empty capability maps) is not a finding.

```bash
A=scripts/audit/audit.sh
$A --category quality --name new-code --bundle code-quality \
  --scope app/Services/PreAccount --scope app/Services/Segments \
  --scope app/Services/FeatureAvailability                                          # 56 KB
$A --category quality --name services-core --bundle code-quality \
  --scope app/Services/Site --scope app/Services/PublicSite \
  --scope app/Services/User --scope app/Services/Accounts                           # 319 KB
$A --category quality --name controllers --bundle code-quality \
  --scope app/Http/Controllers/Api/User --scope app/Http/Controllers/Api/PublicSite # 308 KB
```

---

# After the baseline — audit the delta, not the repo

Once a campaign has run, **do not repeat it**. Diff-scoped sweeps sit far below
the recall cliff, so they detect *better* than a whole-repo sweep as well as
costing less:

```bash
scripts/audit/audit.sh --codebase --bundle security \
  --changed-since <last-audited-ref> --name security-delta
```

It narrows every lens's scope map to files changed since `<ref>`, drops chunks
with no changed file, and re-packs oversized ones. This is the right default for
PR-time and week-to-week auditing; the campaigns above are for establishing a
baseline or gating a launch.

Record the ref you audited to so the next delta starts from the right place.
