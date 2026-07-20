# Audit campaigns — tiered, size-bounded audit plans

Copy-pasteable prompts for running a themed audit across the backend in tiers.
Each tier is a single prompt you can hand to Claude; each run inside it is also
listed as a raw command if you'd rather drive it yourself.

---

# ⭐ Priorities — read this first

Two gates, in order. **Pre-pilot** = cutting over to a real prod Supabase and
putting a controlled cohort of real users with real data on it. **Pre-launch** =
opening to public, uncontrolled traffic. Everything else is ongoing hygiene.

## Where the line actually falls

**The pilot bounds who signs up, not who can reach the product.** Partna's
product *is* public sitepages: 20 pilot users means 20 publicly-reachable pages
at `<handle>.partna.au`, served by the Worker, indexable, hit by anyone who finds
them. So "a controlled cohort bounds the blast radius" is true for *volume* and
false for *reachability*. The test that decides the gate:

- **On the public path at all** → Gate A, regardless of volume.
- **Only hurts at volume** → Gate B.

Within a gate, ranked by risk-per-token rather than severity: an irreversible
one-shot operation (the migration cutover) outranks a worse-but-fixable defect,
because you only get one attempt at it.

## Gate A — pre-pilot (23 runs)

Nothing here is optional. This is the set that gates the cutover.

| Rank | Run | Runs | Why it gates the pilot |
|---|---|---|---|
| 1 | **Security T1** | 8 | Everything externally reachable. Nothing else matters if this is wrong. |
| 2 | **Concurrency T1** | 3 | The claim race is new, unaudited, and corrupts data silently rather than erroring. |
| 3 | **Security T2a** — `edge-worker`, `wiring`, `outbound-ssrf` | 3 | The public path, not the integration path. The Worker is the front door to every sitepage; `wiring` covers CORS and secret leakage at the moment real secrets first enter a real prod env; `InstagramController::mirror()` was already a CRITICAL SSRF once and any pilot user can trigger it on day one. |
| 4 | **Lifecycle T1** | 2 | Deletion/claim state machines + KV reconcile. Real user data means a stuck or half-applied transition is a support incident, not a test artifact. |
| 5 | **Cutover T1** | 3 | 185 unapplied migrations replayed onto a v2 schema is the single riskiest planned operation, and it is one-shot. |
| 6 | **Cutover T2** | 2 | The SQLite/Postgres constraint asymmetry that has already caused two production incidents. |
| 7 | **Data & privacy T1** | 2 | Pilot means real PII. GDPR is legal risk, not technical debt. |

Executable end-to-end prompt: [Running Gate A](#running-gate-a-end-to-end-the-pre-pilot-set).

**If 23 runs is too much in one go:** ranks 1–2 (11 runs) cover the externally
reachable attack surface and the data-corruption race — the two that cannot wait
under any circumstance.

## Gate B — pre-launch (15 runs)

Deferrable through the pilot because these genuinely are volume-bound or
change-bound. Not deferrable past it.

| Rank | Run | Runs | Why it gates launch, not the pilot |
|---|---|---|---|
| 8 | **Scale T1** | 3 | Public sitepage reads and analytics ingest scale with *traffic*, not user count. 20 users generate 20 users' traffic. The tail risk — one pilot page going viral — is a risk you accept, not a gate you pay for up front. |
| 9 | **Security T2b** — `platforms-api`, `platforms-svc-a`, `platforms-svc-b` | 3 | The scraper surface, 827 KB combined and the most expensive runs in the whole plan. A scraper defect hurts the one user who connected that integration; it is not on the public read path. |
| 10 | **Contract** | 3 | The frontend and the Astro sitepage app both consume these payloads. A shape break is cheap to fix pre-launch and expensive after. |
| 11 | **Lifecycle T2** | 4 | Vendor resilience, retry storms, notification dedup — failure modes that need fan-out to appear at all. |
| 12 | **Data & privacy T2** | 2 | Retention windows that never actually prune. Harmless at pilot volume; a compliance finding at launch volume. |

## Ongoing — opportunistic, or when planning a change in that area

| Run | When |
|---|---|
| **Security T3** (7) | Defence-in-depth on code that isn't externally reachable. |
| **Concurrency T2** (2) | Before touching upload or staff write paths. |
| **Scale T2–T3** (5) | Before a load event, or when queue depth becomes visible. |
| **Foundation** (2) | When planning a subsystem change, never on a schedule. |
| **Code quality** (3) | On new code only, never the whole repo. |

**After either gate passes, do not re-run it.** Audit the delta instead —
see [audit the delta](#after-the-baseline--audit-the-delta-not-the-repo).

---

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

## Tier 2a — the public path (3 runs) · **Gate A, pre-pilot**

These three are split out of the old Tier 2 because they sit on the path every
public sitepage visitor traverses, so a pilot cohort does not bound their
exposure. See [Where the line actually falls](#where-the-line-actually-falls).

> **Prompt:**
> Run Tier 2a of the security campaign in `scripts/audit/campaigns.md` — the 3
> public-path runs. Sequential, counts + paths only, stop on failure. Pay
> particular attention to SSRF regressions: `InstagramController::mirror()` was a
> CRITICAL SSRF fixed on 2026-06-03 with `SafeUrlFetcher` + host allowlist +
> image-only content-type — flag anything that bypasses that path. For the edge
> worker, remember `SyncSubdomainToKvJob` is the ONLY sanctioned writer to
> `SUBDOMAIN_KV`; treat any other write path, or any origin-trust assumption in
> the Worker, as a routing-integrity finding.

```bash
A=scripts/audit/audit.sh
$A --category security --name edge-worker --bundle security \
  --scope cloudflare-worker/src                                                     # 23 KB
$A --category security --name wiring --bundle security \
  --scope routes --scope config --scope bootstrap/app.php \
  --scope bootstrap/providers.php                                                   # 262 KB
$A --category security --name outbound-ssrf --bundle security \
  --scope app/Services/Http --scope app/Services/Media \
  --scope app/Services/Streaming --scope app/Services/Cloudflare                    # 152 KB
```

## Tier 2b — the integration surface (3 runs) · **Gate B, pre-launch**

827 KB combined — the most expensive runs in the whole plan. A defect here hurts
the single user who connected that integration, not the public read path, which
is why it can wait out the pilot.

> **Prompt:**
> Run Tier 2b of the security campaign in `scripts/audit/campaigns.md` — the 3
> platform-integration runs. Sequential, counts + paths only, stop on failure.
> Summarise P0/P1 at the end. These are scrapers and vendor clients: weight
> credential handling, per-connection tenant scoping, and anything that fetches a
> URL derived from vendor-controlled data.

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

Tiers 1, 2a, 2b and 3 together cover **1095 of 1096** backend files. Tier 1 alone covers the
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

# Campaign 6 — Prod-cutover readiness  ⚠ time-sensitive

Lenses: `migration-safety`, `test-prod-parity`, `schema-rls`.

**Why this is urgent and not optional.** Prod is still on the old v2 schema; all
185 repo migrations are unapplied, so the cutover is a gated re-baseline rather
than a normal deploy. And tests run on SQLite while prod is Postgres — the test
schema does NOT mirror prod constraints (NOT NULL, CHECK, FK), so a write that
violates them passes CI green and only 500s on real Postgres. That exact class has
already bitten twice on the Instagram connect (`payload => null`, then a
`last_refresh_status` value missing from that column's CHECK).

## Tier 1 — will the cutover survive contact (3 runs)

> **Prompt:**
> Run Tier 1 of the prod-cutover campaign in `scripts/audit/campaigns.md`.
> Sequential, counts + paths only, stop on failure. Context: prod Supabase is on
> the pre-standalone v2 schema with all 185 repo migrations unapplied, and the
> pilot will cut over to it. Grade every finding by "would this break or corrupt
> data during a full replay of these migrations onto an old schema". For
> test-prod-parity findings, verify the constraint against the actual DDL in
> `supabase/migrations/`, NOT against the SQLite test schema in `tests/Pest.php` —
> the two have drifted and the SQLite one is not authoritative.

```bash
A=scripts/audit/audit.sh
L=scripts/audit/lenses
$A --category cutover --name migrations-early --lens-file $L/migration-safety.md \
  $(printf -- '--scope %s ' supabase/migrations/20260[1-6]*.sql)                    # 332 KB
$A --category cutover --name migrations-recent --lens-file $L/migration-safety.md \
  $(printf -- '--scope %s ' supabase/migrations/20260[7-9]*.sql)                    # 212 KB
$A --category cutover --name parity-models --lens-file $L/test-prod-parity.md \
  --scope app/Models --scope app/Observers                                          # 174 KB
```

## Tier 2 — write paths that only fail on real Postgres (2 runs)

> **Prompt:**
> Run Tier 2 of the prod-cutover campaign in `scripts/audit/campaigns.md` — the 2
> write-path parity runs. Sequential, counts + paths only. For each finding, name
> the exact column and constraint in `supabase/migrations/` that the write would
> violate, and confirm the SQLite test schema does not enforce it (that asymmetry
> is the bug). Reject findings that cannot cite the DDL.

```bash
A=scripts/audit/audit.sh
L=scripts/audit/lenses
$A --category cutover --name parity-services --lens-file $L/test-prod-parity.md \
  --scope app/Services/User --scope app/Services/Site --scope app/Services/PreAccount \
  --scope app/Services/Platforms/Registry                                           # 258 KB
$A --category cutover --name parity-jobs --lens-file $L/test-prod-parity.md \
  --scope app/Jobs --scope database/factories                                       # 260 KB
```

---

# Campaign 7 — API contract & test coverage

Lenses: `api-contract`, `test-coverage`. Run before a release that touches public
API shape — the frontend and the Astro sitepage app both consume these payloads.

> **Prompt:**
> Run the API-contract & test-coverage campaign in `scripts/audit/campaigns.md`.
> Sequential, counts + paths only. For contract findings, treat any change to a
> field name, nullability or shape in a Resource as breaking unless a dual-key
> transition is in place (see `IndividualProfileResource`, which deliberately
> emits both `architectureId` and the legacy `skeletonId`). For coverage findings,
> only report gaps on code that can actually break a user flow — do not ask for
> tests on trivial getters.

```bash
A=scripts/audit/audit.sh
L=scripts/audit/lenses
$A --category contract --name public-api-shape --lens-file $L/api-contract.md \
  --scope app/Http/Resources --scope app/Http/Controllers/Api/PublicSite            # 217 KB
$A --category contract --name user-api-shape --lens-file $L/api-contract.md \
  --scope app/Http/Controllers/Api/User --scope app/Http/Requests/Api/User          # 261 KB
$A --category contract --name coverage-security --lens-file $L/test-coverage.md \
  --scope tests/Feature/Security --scope app/Policies --scope app/Http/Middleware/Auth  # 259 KB
```

---

# Campaign 8 — Foundational durability

Bundle: `--bundle foundational`. Architecture debt — shotgun surgery, JSON that
should be columns, leaky boundaries, breaking-migration risk. Run when planning
a subsystem change, not on a schedule.

> **Prompt:**
> Run the foundational-durability campaign in `scripts/audit/campaigns.md`.
> Sequential, counts + paths only. This lens grades extensibility, so anchor every
> finding to a concrete near-term change that would be painful — "adding platform
> #37", "adding a second architecture", "adding a design-kit var". Reject abstract
> "this could be cleaner" findings with no such change behind them.

```bash
A=scripts/audit/audit.sh
$A --category foundation --name platform-registry --bundle foundational \
  --scope app/Services/Platforms/Registry --scope app/Http/Controllers/Api/Platforms \
  --scope app/Services/Platforms/Strategies                                         # 303 KB
$A --category foundation --name json-denormalisation --bundle foundational \
  --scope app/Models --scope app/Services/Segments --scope config/partna.php        # 249 KB
```

---

# Campaign 9 — Lifecycle correctness

Lens: `lifecycle-correctness` (LIFE-*). Race-safety, idempotency, anchor
decoupling, reconcile loops, vendor resilience, log discrimination.

**Why this campaign exists.** This lens ships in the `core`, `pre-pilot` and
`full-sweep` bundles, but no campaign above invokes any of those — so before this
was added it ran in **zero** campaigns while passing the CI bundle-reachability
guard. A lens that never opens a file reports nothing, which is indistinguishable
from a clean result.

It is a deliberate **sibling** to `scaling-antipatterns` (which owns
rebuild-on-write and weak caching) and overlaps `transaction-boundaries` /
`caching-gold-standard` from Campaign 3. The non-overlapping doctrine is the point:
anchor decoupling (`*_started_at` vs a retry-reset deadline), daily reconcile jobs
for at-least-once-or-zero external events, JSONB notification dedup, `:stale` twin
busting, jittered 1→N invalidation, and vendor API version pinning. None of those
are hunted anywhere else.

## Tier 1 — state machines and reconcile (2 runs)

> **Prompt:**
> Run Tier 1 of the lifecycle campaign in `scripts/audit/campaigns.md`.
> Sequential, counts + paths only, stop on failure. Every finding must cite one of
> the named house-doctrine patterns in the lens header — reject anything that
> cannot. Two specifics to weight heavily: (1) self-service deletion never
> soft-deletes, it goes active → pending_deletion → forceDelete, so a transition
> stuck mid-way strands data; (2) `SyncSubdomainToKvJob` is the ONLY writer to
> `SUBDOMAIN_KV` and KV writes are eventually-consistent, so flag any state that
> depends on a KV write landing without a reconcile path.

```bash
A=scripts/audit/audit.sh
L=scripts/audit/lenses
$A --category lifecycle --name state-machines --lens-file $L/lifecycle-correctness.md \
  --scope app/Services/User --scope app/Services/PreAccount --scope app/Jobs/Account \
  --scope app/Jobs/Gdpr --scope app/Services/Segments \
  --scope app/Http/Middleware/Context                                               # 216 KB
$A --category lifecycle --name cache-edge-reconcile --lens-file $L/lifecycle-correctness.md \
  --scope app/Services/Site --scope app/Services/Cache --scope app/Observers \
  --scope app/Jobs/Cloudflare --scope app/Services/Cloudflare \
  --scope routes/console.php                                                        # 245 KB
```

## Tier 2 — fan-out and vendor resilience (4 runs)

> **Prompt:**
> Run Tier 2 of the lifecycle campaign in `scripts/audit/campaigns.md` — the 4
> fan-out/vendor runs. Sequential, counts + paths only. Focus on retry storms,
> missing reconcile jobs for external state, unpinned vendor API versions, and
> paraphrased-instead-of-verbatim vendor errors. Note provisional (unclaimed)
> users have NO email — `routeNotificationForMail()` is nullable — so flag any
> notification path that assumes a mail route exists.

```bash
A=scripts/audit/audit.sh
L=scripts/audit/lenses
$A --category lifecycle --name notif-moderation --lens-file $L/lifecycle-correctness.md \
  --scope app/Services/Notifications --scope app/Jobs/Notifications \
  --scope app/Http/Controllers/Api/User/Notifications --scope app/Services/Moderation \
  --scope app/Jobs/Moderation --scope app/Policies \
  --scope app/Services/Streaming --scope app/Jobs/Streaming                         # 222 KB
$A --category lifecycle --name jobs-media --lens-file $L/lifecycle-correctness.md \
  --scope app/Jobs --scope app/Services/Media                                       # 332 KB
$A --category lifecycle --name vendor-platforms-a --lens-file $L/lifecycle-correctness.md \
  $(printf -- '--scope %s ' app/Services/Platforms/[A-M]*.php)                      # 334 KB
$A --category lifecycle --name vendor-platforms-b --lens-file $L/lifecycle-correctness.md \
  $(printf -- '--scope %s ' app/Services/Platforms/[N-Z]*.php) \
  --scope app/Services/Platforms/Strategies --scope app/Services/Platforms/Registry \
  --scope app/Services/Platforms/Payloads --scope app/Services/Platforms/Normalizers \
  --scope app/Services/Platforms/Concerns --scope app/Jobs/Platforms                # 335 KB
```

---

# Running Gate A end to end (the pre-pilot set)

**Ranks 1–7 from [Priorities](#-priorities--read-this-first) — that table is the
single source of truth for ordering; this section is only the executable form.**

23 runs. Paste the prompt below. It is resumable — re-paste it after an
interruption and it skips whatever already completed.

> ⚠ **The one hard rule: `audit.sh` is never run concurrently.** Every run
> shares the local `claude` CLI's plan/rate budget, so two at once corrupts both.
> The prompt below spawns one agent at a time and waits. Do not "parallelise" it.

> **Prompt:**
>
> Work through Gate A of `scripts/audit/campaigns.md` — the 23-run pre-pilot
> audit set (ranks 1–7). Read that file first for the exact commands.
>
> **The run list, in this exact order:**
>
> | # | category | name | campaign |
> |---|---|---|---|
> | 1 | security | preaccount-claim | Security T1 |
> | 2 | security | public-surface | Security T1 |
> | 3 | security | authz-core | Security T1 |
> | 4 | security | user-api | Security T1 |
> | 5 | security | requests-resources | Security T1 |
> | 6 | security | staff-api | Security T1 |
> | 7 | security | webhooks-internal | Security T1 |
> | 8 | security | models-data | Security T1 |
> | 9 | concurrency | claim-and-provision | Concurrency T1 |
> | 10 | concurrency | cache-invalidation | Concurrency T1 |
> | 11 | concurrency | webhooks-idempotency | Concurrency T1 |
> | 12 | security | edge-worker | Security T2a |
> | 13 | security | wiring | Security T2a |
> | 14 | security | outbound-ssrf | Security T2a |
> | 15 | lifecycle | state-machines | Lifecycle T1 |
> | 16 | lifecycle | cache-edge-reconcile | Lifecycle T1 |
> | 17 | cutover | migrations-early | Cutover T1 |
> | 18 | cutover | migrations-recent | Cutover T1 |
> | 19 | cutover | parity-models | Cutover T1 |
> | 20 | cutover | parity-services | Cutover T2 |
> | 21 | cutover | parity-jobs | Cutover T2 |
> | 22 | privacy | gdpr-deletion-export | Data T1 |
> | 23 | privacy | pii-schema | Data T1 |
>
> **Rules — these are not negotiable:**
>
> 1. **STRICTLY SEQUENTIAL.** Exactly one `audit.sh` process at any moment. Spawn
>    the agent for run N+1 only after run N's agent has returned. Never use
>    parallel tool calls for these. This is a correctness constraint, not a
>    preference — concurrent runs share the `claude` CLI budget and corrupt each
>    other.
> 2. **Resume first.** Before starting, check for each run whether
>    `audits/<category>/*-<name>/CONSOLIDATED.md` already exists. Skip those and
>    tell me which you skipped. A folder with no `CONSOLIDATED.md` means a failed
>    run — delete the folder and redo it.
> 3. **One subagent per run**, `model: sonnet`, `run_in_background: false`. Give it
>    the exact command from `campaigns.md` plus the tier's guidance prompt. Tell it
>    to return ONLY: the tier counts (P0/P1/P2/P3/total), the folder path, and any
>    P0/P1 finding titles. It must not paste the audit file back.
> 4. **Stop on failure.** If a run exits non-zero or writes no `CONSOLIDATED.md`,
>    stop the whole sequence and tell me which run failed and why. Do not continue
>    past a failure. Common causes are the `claude` CLI session limit and budget
>    exhaustion — both now fail loudly and write no audit, so a missing
>    `CONSOLIDATED.md` is a genuine failure, not a clean result.
> 5. **Budget awareness.** 23 runs is roughly 115 scans plus 23 adjudications and
>    will likely hit a session limit partway. That is expected — stop, tell me
>    where you got to, and I will re-paste this prompt to resume.
> 6. **Progress only, no dumps.** After each run, one line: `[n/23] <name> —
>    P0:x P1:x P2:x P3:x → <path>`.
>
> **When all 23 are done (or you stop early), give me:**
>
> - A single table of every P0 and P1 across all runs: ID, tier, run it came from,
>   one-line summary.
> - **Deduplicated** — the same defect will surface in several runs (e.g. a
>   mass-assignment issue appearing in both `models-data` and `parity-models`).
>   Merge those, noting which runs found it; agreement across runs is a confidence
>   signal worth recording.
> - Anything needing a schema change called out separately — those need lead time
>   before the cutover.
> - Your own read on which 3 findings to fix first, and why.
>
> Do not fix anything. This pass is discovery only; fixes go through
> `execute audit <path>` per `fix-flow.md`.

## Expected cost

Roughly 115 DeepSeek scans and 23 Claude adjudications. Adjudication budget scales
with scope size (`$2 + $2/100KB`, capped $18), so the larger runs sit near the top
of that range. Several hours wall-clock, sequential by design.

If that is too much in one go, ranks 1–2 (11 runs) cover the externally reachable
attack surface and the data-corruption race — the two things that cannot wait.

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
