# Partna external audit checklist — things the lenses can't see

The six source lenses (`checklist.md`) scan static code. These items can't be caught that way. Run them in parallel with the lens-based audits.

---

## Phase X1 — Dependency CVE scanning (P0, weekly)

A vulnerable Stripe SDK / Symfony version / `node_modules` package is a pattern that doesn't exist in your source. Needs a dependency advisor.

- [ ] **Backend PHP dependencies** — Composer audit
  ```bash
  composer audit --no-dev   # production-only first
  composer audit            # then dev too
  ```
  Triage: anything CRITICAL / HIGH → fix this week; MEDIUM → fix this sprint; LOW → tracker.

- [ ] **Cloudflare worker npm dependencies**
  ```bash
  cd cloudflare-worker && npm audit --omit=dev
  cd cloudflare-worker && npm audit
  ```

- [ ] **Add CI gate**
  - GitHub Actions step running `composer audit` + `npm audit` on PR.
  - Or Dependabot / Renovate enabled on the repo.
  - One-line failure threshold: `composer audit --format=json | jq '.advisories | length' -e` → fail if non-zero.

---

## Phase X2 — Supabase RLS policies (P0, one-time + on every migration)

If RLS is enabled on any table, the policy is the security boundary — application code can't compensate for a bad RLS rule.

- [ ] **Inventory RLS state**
  ```bash
  grep -rn "ENABLE ROW LEVEL SECURITY\|FORCE ROW LEVEL SECURITY\|CREATE POLICY\|DROP POLICY" supabase/migrations/
  ```
  Result tells you (a) whether RLS is in use at all, (b) which tables have it, (c) which migrations introduced it.

- [ ] **If RLS is enabled anywhere**: read every `CREATE POLICY` clause and verify:
  - The `USING` clause constrains to the actor's tenant (`brand_professional_id = auth.jwt() ->> 'sub'` or equivalent).
  - There's no policy whose `USING (true)` effectively disables it.
  - `WITH CHECK` is present on INSERT/UPDATE policies (otherwise writes can violate the read constraint).
  - Service-role keys never bypass RLS in app code (they should — that's their purpose — but flag any user-token path that escalates to service-role).

- [ ] **If RLS is NOT enabled**: document the decision. The current model relies on application-layer Policy enforcement; that's defensible, but write it down so future you / a contractor doesn't assume RLS is the backstop.

---

## Phase X3 — Frontend repo audit (P1, once per major change)

The frontend (`github.com/hunterbalcombesykes/partna-frontend`, per memory) has its own attack surface that the backend audits can't see: XSS, CSRF on dashboard cookies, token storage, Hydrogen-side auth handling.

- [ ] **Run the same lens framework against it** if it's a Laravel-equivalent stack — copy `scripts/audit/` over.
- [ ] **Or `/ultrareview`** the frontend branch — multi-agent cloud review covers it.
- [ ] **Or third-party** — a contractor or pentest firm if you want independent eyes before pilot.

Backend-from-frontend trust: confirm the backend treats every frontend-supplied value as untrusted (covered by `security.md` Category 6 on the backend side, but worth verifying both directions).

---

## Phase X4 — Load testing (P1, before pilot)

Static analysis surfaces patterns. Load testing surfaces the patterns that ONLY break under real concurrency.

### Tool: **k6** (Grafana, recommended)

- JavaScript-based scenarios; runs locally for quick passes or on k6 Cloud for sustained load.
- Free local; paid for cloud / >50 VUs sustained.
- Integrates with Grafana / Nightwatch for result visualisation.

Alternatives: **Artillery** (YAML, simpler), **Locust** (Python, branching journeys), **Vegeta** (CLI, brutally simple).

### Scenarios to test (in priority order)

- [ ] **Scenario 1: Shopify webhook burst** — simulate 200 brands × 50 orders/min during a flash sale.
  - Tests: queue depth tolerance, idempotency under re-delivery, vendor budget burn.
  - Targets: `POST /webhooks/shopify/orders/{create,update,paid}` with valid HMAC.
  - Success criteria: p95 webhook-ack < 100ms, zero duplicate orders in DB, Horizon backlog drains within 10min of burst end.

- [ ] **Scenario 2: Dashboard cold cache** — 200 brands authenticating at 9am Monday.
  - Tests: catalog / analytics cache stampede, single-flight lock correctness, JWKS cache.
  - Targets: `GET /api/me`, `GET /api/me/site`, `GET /api/me/analytics/*`.
  - Success criteria: p95 < 500ms, no cache-key Redis lock timeouts, no Postgres connection-pool exhaustion.

- [ ] **Scenario 3: Payout cycle storm** — daily `VoidExpiredPayoutsJob` + `RetryPendingFundsPayoutsJob` + `ReconcileStuckTransferringPayoutsJob` over 10K eligible payouts.
  - Tests: Stripe API rate-limit budget, lockForUpdate contention, retry storms.
  - Targets: dispatch the three jobs concurrently against a seeded payout set.
  - Success criteria: zero Stripe `rate_limit_error`, zero duplicate Transfers, all payouts terminal within 30min.

- [ ] **Scenario 4: Authentication storm** — Supabase JWT validation at 1K req/s sustained for 5min.
  - Tests: JWKS cache, APCu correctness across worker recycles.
  - Targets: any authenticated endpoint with a fresh JWT each request.
  - Success criteria: p95 auth latency < 20ms, zero auth failures, zero JWKS fetches after warm-up.

### When to run

- **Once** before pilot to establish baselines.
- **On every Stripe / commerce / cache change** to catch regressions.
- **Quarterly** to re-baseline.

I can write the k6 scripts when you want — they're ~50 LOC each and reusable. Tell me which scenario to start with.

---

## Phase X5 — Pentest / external review (P1, before pilot)

The audits are run by you (or your AI agents) — they all share assumptions. An independent reviewer challenges those assumptions.

- [ ] **`/ultrareview`** the development branch before merging to production
  - User-triggered multi-agent cloud review.
  - Cheaper than a pentest firm.
  - Catches assumption-aligned gaps your own audits miss.

- [ ] **Third-party pentest** before opening to real users
  - Roughly $5K–$15K AUD for a focused web-app pentest from a reputable firm.
  - Worth it before opening to brands handling real PII + financials.
  - Get the report **before** marketing the pilot, not after.

- [ ] **Bug bounty** (optional, post-pilot)
  - HackerOne / Bugcrowd managed program.
  - Long tail; useful once there's a real attack surface in the wild.

---

## Phase X6 — Infrastructure & operational hardening (P2)

Foundational items that aren't bugs but become bugs the day you wish you'd done them.

- [ ] **Backup / restore drill**
  - Supabase project: confirm point-in-time recovery is enabled (it is on Pro / Team plans).
  - Run a restore-to-staging from a 24h-old snapshot. Verify schema + data integrity.
  - Document the runbook.

- [ ] **Disaster scenario tabletop**
  - "Supabase region is down for 2 hours" — what's the comms / status-page plan?
  - "Stripe Connect onboarding is down" — what's the signup gate?
  - "Cloudflare KV is unreachable" — does the worker fail open or closed?

- [ ] **Monitoring baselines**
  - Per memory `reference_nightwatch_alerts.md`: alerts fire on exceptions + auto-detected slow jobs/routes, NOT on log queries.
  - Confirm Nightwatch thresholds are tuned (default slow-route is often too generous for a financial API).
  - Confirm Horizon alerting is on (long waits, failed-job spike — `b3be0b5` added this).

- [ ] **Secrets rotation**
  - Document rotation procedure for: Stripe webhook secret, Shopify app secret, Cloudflare API token, Supabase service-role key, Hydrogen API key.
  - First rotation should happen pre-pilot to prove the procedure works.

- [ ] **Onboarding runbook freshness**
  - `CLAUDE.md` last-updated date check.
  - `docs/` index reflects current architecture (commerce rebuild deployed 2026-05-06, Stripe payout work 2026-05-09).
  - A new contractor could ship a feature without asking you a foundational question — if not, the runbook is stale.

---

## Phase X7 — Process hygiene (P3, ongoing)

- [ ] CI runs `composer audit` + `npm audit` (covered by X1 but list separately for tracking).
- [ ] CI runs `pest --coverage` and fails on regression of critical-path coverage.
- [ ] PR template prompts: "Did this change touch a webhook? An idempotency key? A FK?" — the patterns the audits catch.
- [ ] Audit re-run cadence: re-run each lens **at minimum** monthly, **immediately** after any major refactor.

---

## Working notes

- **Phase X1 + X2 are quick** (under 30min each) and high-leverage; do them first.
- **Phase X3 + X4 + X5 are pre-pilot blockers** — schedule before opening the doors.
- **Phase X6 + X7 are ongoing** — they become process, not events.
- **The source lens audits (`checklist.md`) and these external audits are complementary** — neither is sufficient alone. The combination is what gives confidence.

A vulnerable dependency, a missing RLS rule, a frontend XSS, a Stripe-rate-limit storm, and a buried assumption are five things no source-scan can find — close each via the phase above.
