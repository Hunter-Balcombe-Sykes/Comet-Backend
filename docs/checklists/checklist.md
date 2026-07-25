# Partna audit checklist — staged by scale

Six lenses, ordered by priority. Tick each scope group as it completes and the findings are triaged. Every `audit.sh` command writes a dated output file under `audits/<phase>/audit-YYYY-MM-DD-<lens>.md` — each phase gets its own folder via the `--phase` flag baked into every command below. Add `--keep-drafts` to retain the DeepSeek scan output (drafts land in `audits/<phase>/.drafts/`).

**Pick your stage below — don't run everything at once.** Most scaling-focused audits only matter once you're past a certain volume. Running them too early surfaces noise; running them too late means you find scale bugs in production.

---

# STAGE 1 — Before pilot launch (≤ 2 brands, ≤ 16 affiliates)

**You are here.** Goal: ship to a tiny pilot with **no correctness, security, or money-handling bugs**. Scale concerns are deferred — at 16 affiliates, N+1 queries and cache stampede don't manifest.

**14 source-scan runs + 3 external — roughly 2h wall-clock.**

### Source-scan must-do
- [x] **All of Phase 1 Security** (7 runs: SEC-A through G) — a tenant boundary failure is just as catastrophic at 2 brands as at 200.
- [ ] **LIFE-A** — Shopify webhook lifecycle (webhook duplicates happen at any scale)
- [ ] **LIFE-B** — Notifications fan-out + dedup (a bad dedup still double-emails 16 affiliates)
- [ ] **LIFE-D** — Auth / policy gating on financial endpoints (money flows from day 1)
- [ ] **TEST-A** — Financial flow tests (you can't roll back a paid affiliate)
- [ ] **TEST-B** — Webhook idempotency tests
- [ ] **DATA-A** — Migrations (pilot data becomes prod data; FK / orphan bugs compound)
- [ ] **DATA-B** — Models + factories

### External must-do
- [ ] **X1** — `composer audit` + `cd cloudflare-worker && npm audit` (~5 min)
- [ ] **X2** — Supabase RLS review (~30 min — even if just to document "RLS off, application layer is the boundary")
- [ ] **X6** — Backup / restore drill (one restore-to-staging from a snapshot)

### Suggested order (1 week)
| Day | Work | Time |
|---|---|---|
| Day 1 | External X1 + X2 + X6 | 1h |
| Day 2 | Phase 1 Security (SEC-A through G) + triage | 90min + fixes |
| Day 3 | LIFE-A, B, D + triage | 45min + fixes |
| Day 4 | TEST-A, B + write missing tests | 30min + fixes |
| Day 5 | DATA-A, B + triage | 30min + fixes |
| Day 6–7 | Fix anything P0/P1 surfaced above | varies |

### Deferred at this stage (don't run yet)
Phase 3 (Scaling), Phase 4 (Database/queue), LIFE-C/E/F, TEST-C/D/E, DATA-C/D, External X3/X4/X5.

---

# STAGE 2 — Before opening to 5+ brands or ~50 affiliates

**Trigger:** you're moving from a closed pilot to a small open beta. Volume starts mattering — multiple brands writing concurrently, more webhooks, more cache pressure.

**Add on top of Stage 1: ~10 more source-scan runs + 2 external.**

### Source-scan additions
- [ ] **LIFE-C** — Cache invalidation + write-path discipline (multiple writers stress this)
- [ ] **LIFE-E** — Edge vendors (Media / Streaming / Hydrogen / Cloudflare)
- [ ] **LIFE-F** — Schema correctness
- [ ] **CACHE-A, B, C, D** — Full Phase 3 Scaling antipatterns (4 runs)
- [ ] **SCALE-A** — Models + Resources (N+1 starts to bite around brand #3)
- [ ] **SCALE-C** — Services with vendor I/O (Shopify GraphQL points budget matters now)
- [ ] **TEST-C** — Policy + auth coverage
- [ ] **DATA-C** — GDPR / retention paths

### External additions
- [ ] **X3** — Frontend `/ultrareview` (cheap; catches dashboard XSS / token issues before brands are watching)
- [ ] **X4 Scenario 1** — Shopify webhook burst load test (200 brands worth of orders to a dev env — tells you the queue + idempotency story)

### Re-run from Stage 1
- [ ] **Phase 1 Security** — re-run after any auth-touching change since pilot
- [ ] **External X1** — CVE audit (monthly cadence starts here)

### Deferred at this stage
SCALE-B/D/E, TEST-D/E, DATA-D, External X5 (pentest can wait until 10+ brands).

---

# STAGE 3 — Before opening to 20+ brands or ~500 affiliates

**Trigger:** you're going public-ish — directory listings, marketing, real PII volume. Queue throughput, scheduler stampede, vendor budgets all become real.

**Add on top of Stage 2: ~7 more source-scan runs + full external.**

### Source-scan additions
- [ ] **SCALE-B** — Jobs + queue shape (Horizon supervisor sizing matters now)
- [ ] **SCALE-D** — Controllers + edge worker (backpressure on real ingress)
- [ ] **SCALE-E** — Migrations under load (no more 30-second blocking migrations)
- [ ] **TEST-D** — Resource / Form Request structure (PII shape regressions = real users now)
- [ ] **TEST-E** — Migration invariants
- [ ] **DATA-D** — Enum DB ↔ app drift

### External additions
- [ ] **X4** — Full load test suite (all 4 scenarios from `checklist-external.md`)
- [ ] **X5** — Third-party pentest before opening to real public users (~$5–15K AUD, ~2 weeks lead time)
- [ ] **X7** — CI hardening: `composer audit` + `npm audit` + critical-path coverage gates in CI

### Re-run from Stage 1 + 2
- [ ] **Full Phase 1 Security** — re-baseline
- [ ] **Phase 2 Lifecycle** — re-run after any vendor-integration change
- [ ] **External X6** — backup drill (quarterly)

---

# STAGE 4 — Scale target (~200 brands × ~50 affiliates × ~100 orders/year)

**Trigger:** you're approaching the design target. The audits transition from event-driven to cadence-driven.

### Ongoing cadence
- [ ] **Monthly:** rerun all 31 source-scan audits (one phase per week)
- [ ] **Monthly:** `composer audit` + `npm audit`
- [ ] **Quarterly:** full load test suite (X4) — re-baseline
- [ ] **Quarterly:** backup / restore drill (X6)
- [ ] **Annually:** third-party pentest (X5)
- [ ] **On every major change:** re-run the relevant phase before merging to production

### New concerns at this scale
- **Capacity planning** — Redis memory, Postgres connection limits, queue worker counts; needs measured numbers, not pattern matches
- **Multi-region / failover** — Supabase + Laravel Cloud single-region today; flag if this changes
- **Cost monitoring** — vendor API spend, DeepSeek/Sonnet audit spend, observability spend
- **SLO definition + alerting** — Nightwatch already in place; SLO thresholds need tuning to scale

---

# Decision table — "do I run this audit yet?"

| Audit | Stage 1 (pilot) | Stage 2 (5 brands) | Stage 3 (20 brands) | Stage 4 (scale) |
|---|---|---|---|---|
| Phase 1 Security (SEC-A through G) | ✅ all | 🔁 re-run | 🔁 re-run | 🔁 monthly |
| LIFE-A (Shopify webhook) | ✅ | 🔁 | 🔁 | 🔁 monthly |
| LIFE-B (Notifications) | ✅ | 🔁 | 🔁 | 🔁 monthly |
| LIFE-C (Cache) | ⏭️ | ✅ | 🔁 | 🔁 monthly |
| LIFE-D (Financial auth) | ✅ | 🔁 | 🔁 | 🔁 monthly |
| LIFE-E (Edge vendors) | ⏭️ | ✅ | 🔁 | 🔁 monthly |
| LIFE-F (Schema) | ⏭️ | ✅ | 🔁 | 🔁 monthly |
| Phase 3 Scaling (CACHE-A through D) | ⏭️ | ✅ all | 🔁 | 🔁 monthly |
| SCALE-A (Models + Resources) | ⏭️ | ✅ | 🔁 | 🔁 monthly |
| SCALE-B (Jobs / queue) | ⏭️ | ⏭️ | ✅ | 🔁 monthly |
| SCALE-C (Vendor I/O) | ⏭️ | ✅ | 🔁 | 🔁 monthly |
| SCALE-D (Controllers + worker) | ⏭️ | ⏭️ | ✅ | 🔁 monthly |
| SCALE-E (Migrations under load) | ⏭️ | ⏭️ | ✅ | 🔁 monthly |
| TEST-A (Financial tests) | ✅ | 🔁 | 🔁 | 🔁 monthly |
| TEST-B (Webhook idempotency tests) | ✅ | 🔁 | 🔁 | 🔁 monthly |
| TEST-C (Policy / auth tests) | ⏭️ | ✅ | 🔁 | 🔁 monthly |
| TEST-D (Resource / Form Request) | ⏭️ | ⏭️ | ✅ | 🔁 monthly |
| TEST-E (Migration invariants) | ⏭️ | ⏭️ | ✅ | 🔁 monthly |
| DATA-A (Migrations) | ✅ | 🔁 | 🔁 | 🔁 monthly |
| DATA-B (Models + factories) | ✅ | 🔁 | 🔁 | 🔁 monthly |
| DATA-C (GDPR / retention) | ⏭️ | ✅ | 🔁 | 🔁 monthly |
| DATA-D (Enum drift) | ⏭️ | ⏭️ | ✅ | 🔁 monthly |
| External X1 (CVEs) | ✅ | 🔁 monthly | 🔁 monthly | 🔁 monthly |
| External X2 (RLS) | ✅ | 🔁 on migration | 🔁 on migration | 🔁 on migration |
| External X3 (Frontend) | ⏭️ | ✅ | 🔁 | 🔁 on change |
| External X4 (Load test) | ⏭️ | ✅ partial (Sc 1) | ✅ full | 🔁 quarterly |
| External X5 (Pentest) | ⏭️ | ⏭️ | ✅ | 🔁 annually |
| External X6 (Backup / infra) | ✅ | 🔁 | 🔁 quarterly | 🔁 quarterly |
| External X7 (CI gates) | ⏭️ | ⏭️ | ✅ | 🔁 ongoing |

Legend: ✅ = run for the first time at this stage · 🔁 = re-run · ⏭️ = defer

---

## Phase 1 — Security (P0, pre-pilot must-pass)

A tenant-boundary failure is the worst-case outcome. Run this lens first.

**Lens:** `scripts/audit/lenses/security.md` (prefix `SEC-N`)

- [x] **SEC-A — Auth middleware + policies (highest priority within phase)** → `audits/phase-1-security/audit-2026-05-11--security-auth-middleware-and-policies.md` (1 P0, 1 P1, 2 P2)
  ```bash
  scripts/audit/audit.sh \
    --phase phase-1-security \
    --lens-file scripts/audit/lenses/security.md \
    --scope app/Http/Middleware/Auth \
    --scope app/Policies \
    --scope app/Providers/AppServiceProvider.php \
    --scope app/Http/Controllers/Concerns
  ```

- [x] **SEC-B — Webhook controllers** → `audits/phase-1-security/audit-2026-05-11--security-webhooks-and-shopify-oauth.md` (3 P2)
  ```bash
  scripts/audit/audit.sh \
    --phase phase-1-security \
    --lens-file scripts/audit/lenses/security.md \
    --scope app/Http/Controllers/Api/Webhooks \
    --scope app/Http/Controllers/Api/Shopify
  ```

- [x] **SEC-C — Embedded Shopify-admin surface (session-token + install)** → `audits/phase-1-security/audit-2026-05-11--security-internal-and-pro-shopify-integration.md` (1 P0, 3 P1, 1 P2)
  ```bash
  scripts/audit/audit.sh \
    --phase phase-1-security \
    --lens-file scripts/audit/lenses/security.md \
    --scope app/Http/Controllers/Api/Internal \
    --scope app/Http/Controllers/Api/Professional/ShopifyIntegration
  ```

- [x] **SEC-D — Financial endpoints + Form Requests** → `audits/phase-1-security/audit-2026-05-11--security-pro-stripe-brand-affiliate-and-requests.md` (3 P1)
  ```bash
  scripts/audit/audit.sh \
    --phase phase-1-security \
    --lens-file scripts/audit/lenses/security.md \
    --scope app/Http/Controllers/Api/Professional/Stripe \
    --scope app/Http/Controllers/Api/Professional/Brand \
    --scope app/Http/Controllers/Api/Professional/Affiliate \
    --scope app/Http/Requests
  ```

- [x] **SEC-E — Public surface (enumeration + PII risk)** → `audits/phase-1-security/audit-2026-05-11--security-public-site-and-resources.md` (2 P2, 2 P3)
  ```bash
  scripts/audit/audit.sh \
    --phase phase-1-security \
    --lens-file scripts/audit/lenses/security.md \
    --scope app/Http/Controllers/Api/PublicSite \
    --scope app/Http/Resources
  ```

- [x] **SEC-F — Vendor I/O services + edge worker (SSRF + secret handling)** → `audits/phase-1-security/audit-2026-05-11--security-services-and-cloudflare-worker.md` (1 P0, 2 P1, 4 P2, 1 P3)
  ```bash
  scripts/audit/audit.sh \
    --phase phase-1-security \
    --lens-file scripts/audit/lenses/security.md \
    --scope app/Services/Shopify \
    --scope app/Services/Stripe \
    --scope app/Services/Cloudflare \
    --scope app/Services/Hydrogen \
    --scope app/Services/Auth \
    --scope cloudflare-worker/src
  ```

- [x] **SEC-G — Configuration + worker config (secret leak + CORS)** → `audits/phase-1-security/audit-2026-05-11--security-config-and-wrangler.md` (1 P2, 1 P3)
  ```bash
  scripts/audit/audit.sh \
    --phase phase-1-security \
    --lens-file scripts/audit/lenses/security.md \
    --scope config \
    --scope cloudflare-worker/wrangler.toml
  ```

---

## Phase 2 — Lifecycle correctness (P0/P1, pre-pilot must-pass)

Race conditions, idempotency gaps, anchor decoupling, reconcile loops, vendor hygiene — modelled on the Stripe payout work shipped 2026-05-06 → 2026-05-09.

**Lens:** `scripts/audit/lenses/lifecycle-correctness.md` (prefix `LIFE-N`)

- [x] **LIFE-A — Shopify webhook + integration lifecycle (highest within phase)** → `audits/phase-2-lifecycle/audit-2026-05-11--lifecycle-shopify-webhook-and-integration.md` (1 P1, 4 P2)
  ```bash
  scripts/audit/audit.sh \
    --phase phase-2-lifecycle \
    --lens-file scripts/audit/lenses/lifecycle-correctness.md \
    --scope app/Services/Shopify \
    --scope app/Jobs/Shopify \
    --scope app/Http/Controllers/Api/Webhooks \
    --scope app/Http/Controllers/Api/Shopify \
    --scope app/Http/Controllers/Api/Professional/ShopifyIntegration
  ```

- [x] **LIFE-B — Notifications fan-out + dedup** → `audits/phase-2-lifecycle/audit-2026-05-11--lifecycle-notifications-fanout-and-dedup.md` (6 P2, 2 P3)
  ```bash
  scripts/audit/audit.sh \
    --phase phase-2-lifecycle \
    --lens-file scripts/audit/lenses/lifecycle-correctness.md \
    --scope app/Services/Notifications \
    --scope app/Jobs/Notifications \
    --scope app/Notifications \
    --scope app/Http/Controllers/Api/Professional/Notifications \
    --scope app/Models/Core/Notifications
  ```

- [x] **LIFE-C — Cache invalidation + write-path discipline** → `audits/phase-2-lifecycle/audit-2026-05-11--lifecycle-cache-invalidation-and-write-path.md` (1 P1, 2 P2, 1 P3)
  ```bash
  scripts/audit/audit.sh \
    --phase phase-2-lifecycle \
    --lens-file scripts/audit/lenses/lifecycle-correctness.md \
    --scope app/Services/Cache \
    --scope app/Services/Analytics \
    --scope app/Jobs/Cache \
    --scope app/Observers
  ```

- [x] **LIFE-D — Auth / policy gating on financial endpoints** → `audits/phase-2-lifecycle/audit-2026-05-11--lifecycle-financial-auth-gating.md` (1 P1, 2 P2, 1 P3)
  ```bash
  scripts/audit/audit.sh \
    --phase phase-2-lifecycle \
    --lens-file scripts/audit/lenses/lifecycle-correctness.md \
    --scope app/Policies \
    --scope app/Http/Controllers/Api/Professional/Stripe \
    --scope app/Http/Controllers/Api/Professional/Brand \
    --scope app/Http/Controllers/Api/Professional/Affiliate \
    --scope app/Http/Middleware/Auth \
    --scope app/Http/Requests
  ```

- [x] **LIFE-E — Edge vendors + worker (Media / Streaming / Hydrogen / Cloudflare)** → `audits/phase-2-lifecycle/audit-2026-05-11--lifecycle-edge-vendors-and-worker.md` (1 P1, 11 P2, 2 P3)
  ```bash
  scripts/audit/audit.sh \
    --phase phase-2-lifecycle \
    --lens-file scripts/audit/lenses/lifecycle-correctness.md \
    --scope app/Services/Media \
    --scope app/Services/Streaming \
    --scope app/Services/Hydrogen \
    --scope app/Services/Cloudflare \
    --scope app/Jobs/Cloudflare \
    --scope app/Jobs/Streaming \
    --scope cloudflare-worker/src
  ```

- [x] **LIFE-F — Schema correctness (run last in this phase)** → `audits/phase-2-lifecycle/audit-2026-05-11--lifecycle-schema-correctness.md` (2 P2)
  ```bash
  scripts/audit/audit.sh \
    --phase phase-2-lifecycle \
    --lens-file scripts/audit/lenses/lifecycle-correctness.md \
    --scope supabase/migrations
  ```

---

## Phase 3 — Scaling antipatterns (P1, pre-scale must-pass)

Rebuild-on-write, write amplification, weak read-side caching, aggregate-tables-that-should-be-live-queries — the patterns from the commerce-analytics rebuild.

**Lens:** `scripts/audit/lenses/scaling-antipatterns.md` (prefix `CACHE-N`)

- [ ] **CACHE-A — Booking-adjacent services (high-cardinality non-commerce)**
  > NOTE: booking itself is dropped (memory `project_booking_dropped.md`). This group is the high-volume non-commerce ingest paths instead.
  ```bash
  scripts/audit/audit.sh \
    --phase phase-3-scaling \
    --lens-file scripts/audit/lenses/scaling-antipatterns.md \
    --scope app/Services/Analytics \
    --scope app/Services/Site \
    --scope app/Jobs/Analytics
  ```

- [ ] **CACHE-B — Notifications (rebuild-on-write + fan-out)**
  ```bash
  scripts/audit/audit.sh \
    --phase phase-3-scaling \
    --lens-file scripts/audit/lenses/scaling-antipatterns.md \
    --scope app/Services/Notifications \
    --scope app/Jobs/Notifications \
    --scope app/Notifications
  ```

- [ ] **CACHE-C — Cache layer (read-side weakness)**
  ```bash
  scripts/audit/audit.sh \
    --phase phase-3-scaling \
    --lens-file scripts/audit/lenses/scaling-antipatterns.md \
    --scope app/Services/Cache \
    --scope app/Jobs/Cache \
    --scope app/Observers
  ```

- [ ] **CACHE-D — Hot-read controllers (dashboard + analytics endpoints)**
  ```bash
  scripts/audit/audit.sh \
    --phase phase-3-scaling \
    --lens-file scripts/audit/lenses/scaling-antipatterns.md \
    --scope app/Http/Controllers/Api/Professional/Analytics \
    --scope app/Http/Controllers/Api/Internal \
    --scope app/Http/Controllers/Api/Staff
  ```

---

## Phase 4 — Database & queue scaling (P1, pre-scale must-pass)

N+1 hunting, unbounded result sets, connection scoping, queue shape, vendor rate-limit budgets, scheduler stampede, migration safety, backpressure, memory pressure.

**Lens:** `scripts/audit/lenses/database-and-queue-scaling.md` (prefix `SCALE-N`)

- [ ] **SCALE-A — Models + resources (N+1 + unbounded reads)**
  ```bash
  scripts/audit/audit.sh \
    --phase phase-4-database \
    --lens-file scripts/audit/lenses/database-and-queue-scaling.md \
    --scope app/Models \
    --scope app/Http/Resources
  ```

- [ ] **SCALE-B — Jobs + queue shape**
  ```bash
  scripts/audit/audit.sh \
    --phase phase-4-database \
    --lens-file scripts/audit/lenses/database-and-queue-scaling.md \
    --scope app/Jobs \
    --scope app/Console \
    --scope config/horizon.php \
    --scope config/queue.php \
    --scope routes/console.php
  ```

- [ ] **SCALE-C — Services with vendor I/O + transaction scoping**
  ```bash
  scripts/audit/audit.sh \
    --phase phase-4-database \
    --lens-file scripts/audit/lenses/database-and-queue-scaling.md \
    --scope app/Services/Shopify \
    --scope app/Services/Stripe \
    --scope app/Services/Cloudflare \
    --scope app/Services/Hydrogen \
    --scope app/Services/Media \
    --scope app/Services/Streaming
  ```

- [ ] **SCALE-D — Controllers + edge worker (backpressure + list endpoints)**
  ```bash
  scripts/audit/audit.sh \
    --phase phase-4-database \
    --lens-file scripts/audit/lenses/database-and-queue-scaling.md \
    --scope app/Http/Controllers/Api/Webhooks \
    --scope app/Http/Controllers/Api/Professional \
    --scope app/Http/Controllers/Api/Staff \
    --scope app/Http/Controllers/Api/Internal \
    --scope cloudflare-worker/src
  ```

- [ ] **SCALE-E — Migrations under load**
  ```bash
  scripts/audit/audit.sh \
    --phase phase-4-database \
    --lens-file scripts/audit/lenses/database-and-queue-scaling.md \
    --scope supabase/migrations
  ```

---

## Phase 5 — Test coverage (P2, safety-net verification)

Find the production paths that the other five lenses flag as risky but the test suite doesn't exercise. A static-analysis audit finds *what could break*; this finds *whether you'll catch it when it does*.

**Lens:** `scripts/audit/lenses/test-coverage.md` (prefix `TEST-N`)

- [ ] **TEST-A — Financial flow tests (highest priority within phase)**
  ```bash
  scripts/audit/audit.sh \
    --phase phase-5-tests \
    --lens-file scripts/audit/lenses/test-coverage.md \
    --scope tests/Feature/Stripe \
    --scope tests/Feature/Commerce \
    --scope tests/Feature/Commission \
    --scope app/Services/Stripe \
    --scope app/Jobs/Stripe \
    --scope app/Policies/CommissionPolicy.php \
    --scope app/Policies/WalletMovementPolicy.php
  ```

- [ ] **TEST-B — Webhook idempotency tests**
  ```bash
  scripts/audit/audit.sh \
    --phase phase-5-tests \
    --lens-file scripts/audit/lenses/test-coverage.md \
    --scope tests/Feature/Webhooks \
    --scope tests/Feature/Shopify \
    --scope app/Http/Controllers/Api/Webhooks \
    --scope app/Jobs/Shopify \
    --scope app/Jobs/Gdpr
  ```

- [ ] **TEST-C — Policy + auth coverage**
  ```bash
  scripts/audit/audit.sh \
    --phase phase-5-tests \
    --lens-file scripts/audit/lenses/test-coverage.md \
    --scope tests/Feature/Policies \
    --scope tests/Feature/Auth \
    --scope app/Policies \
    --scope app/Http/Middleware/Auth
  ```

- [ ] **TEST-D — Resource / Form Request structure**
  ```bash
  scripts/audit/audit.sh \
    --phase phase-5-tests \
    --lens-file scripts/audit/lenses/test-coverage.md \
    --scope tests/Feature \
    --scope app/Http/Resources \
    --scope app/Http/Requests
  ```

- [ ] **TEST-E — Migration invariants**
  ```bash
  scripts/audit/audit.sh \
    --phase phase-5-tests \
    --lens-file scripts/audit/lenses/test-coverage.md \
    --scope tests/Feature/Audit \
    --scope tests/Feature/Migrations \
    --scope supabase/migrations \
    --scope database/factories
  ```

---

## Phase 6 — Data integrity & privacy (P2, foundational hardening)

FK hygiene, soft-delete coherence, orphan-row risk, PII inventory + retention, GDPR redact path completeness, enum / CHECK / UNIQUE coverage.

**Lens:** `scripts/audit/lenses/data-integrity-and-privacy.md` (prefix `DATA-N`)

- [ ] **DATA-A — Migrations (source of truth)**
  ```bash
  scripts/audit/audit.sh \
    --phase phase-6-data \
    --lens-file scripts/audit/lenses/data-integrity-and-privacy.md \
    --scope supabase/migrations
  ```

- [ ] **DATA-B — Models + factories**
  ```bash
  scripts/audit/audit.sh \
    --phase phase-6-data \
    --lens-file scripts/audit/lenses/data-integrity-and-privacy.md \
    --scope app/Models \
    --scope database/factories
  ```

- [ ] **DATA-C — GDPR / retention paths**
  ```bash
  scripts/audit/audit.sh \
    --phase phase-6-data \
    --lens-file scripts/audit/lenses/data-integrity-and-privacy.md \
    --scope app/Http/Controllers/Api/Webhooks \
    --scope app/Jobs/Gdpr \
    --scope app/Services
  ```

- [ ] **DATA-D — Enums (DB ↔ app drift)**
  ```bash
  scripts/audit/audit.sh \
    --phase phase-6-data \
    --lens-file scripts/audit/lenses/data-integrity-and-privacy.md \
    --scope app/Enums \
    --scope supabase/migrations
  ```

---

## Working notes

- **Output files** land at `audits/<phase>/audit-YYYY-MM-DD-<lens-slug>.md` via the `--phase` flag baked into every command. After triage, mark closed audits with a `-closed` suffix or move to `audits/<phase>/closed/`. Drafts (when `--keep-drafts` is set) land in `audits/<phase>/.drafts/`. To park existing root-level audits, run `mkdir -p audits/phase-0-pre-pipeline && mv audit-*.md audits/phase-0-pre-pipeline/` (only after confirming the orchestrator's glob handles subfolders).
- **Findings are draft until adjudicated.** The DeepSeek scan emits `[DRAFT, confidence: 0.X]` markers; the Sonnet adjudicator re-tiers and dedupes before writing the final file.
- **Per-phase order matters.** Phase 1+2 catch failures that ship bad behaviour to real users. Phase 3+4 catch failures that break under load. Phase 5 is durability hardening — important but not pilot-blocking.
- **Run lenses in parallel within a phase** if you want to compress wall-clock time; each invocation is independent.
- **Triage workflow.** Each finding has a checkbox in the output file. Tick as fixes ship, just like `audit-2026-05-09-stripe-payout-lifecycle.md`.
- **Out-of-scope flags.** Each lens carries its own "do NOT re-flag" section — booking / Fresha / Square / already-closed audits stay out by design.
- **Cost.** DeepSeek scan is the dominant cost; adjudication runs through the local `claude` CLI's OAuth, so no extra billing beyond your usage. Roughly proportional to scope file count × file size.

---

## Files in this audit set

| Lens file | Prefix | Phase |
|---|---|---|
| `scripts/audit/lenses/security.md` | `SEC` | 1 (P0) |
| `scripts/audit/lenses/lifecycle-correctness.md` | `LIFE` | 2 (P0/P1) |
| `scripts/audit/lenses/scaling-antipatterns.md` | `CACHE` | 3 (P1) |
| `scripts/audit/lenses/database-and-queue-scaling.md` | `SCALE` | 4 (P1) |
| `scripts/audit/lenses/test-coverage.md` | `TEST` | 5 (P2) |
| `scripts/audit/lenses/data-integrity-and-privacy.md` | `DATA` | 6 (P2) |
| `scripts/audit/lenses/brand-status-recent-changes.md` | `BSE` | Reference only — already shipped |

Runner: `scripts/audit/audit.sh` (DeepSeek scan → Sonnet adjudicate → dated output file).

**Companion checklist:** `checklist-external.md` covers the items that can't be source-scanned — Composer / npm CVE audits, Supabase RLS review, frontend repo audit, load testing, pentest, infrastructure hardening. Run them in parallel with this checklist.
