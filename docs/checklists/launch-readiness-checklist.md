# Partna launch-readiness checklist — staged by go-live tier

**Date:** 2026-05-11
**Companion to:** `audit-checklist.md` (technical security/correctness audits) and `audit-checklist-external.md` (external validation items)

This is the **business + operational** counterpart to the audit checklists. It covers everything between "code passes audits" and "first paying customer is using the product safely" — including legal, compliance, insurance, infrastructure, monitoring, and funding strategy.

## How to read this doc

- **Stages** match `audit-checklist.md` so the technical and business plans align: Stage 1 (closed pilot), Stage 2 (open beta), Stage 3 (public launch), Stage 4 (scale).
- **Priority tiers**: P0 = blocks launch at this stage; P1 = should do at this stage; P2 = strongly recommended but deferrable to next stage; P3 = nice-to-have.
- **Cost** is in AUD unless otherwise noted. "DIY" = your time only, no out-of-pocket.
- **When** = the latest moment to have it done; earlier is fine.
- Costs are realistic 2026 estimates for a bootstrapped AU pre-pilot SaaS targeting SMB customers.

## Total cost summary

| Stage | One-time | Annual recurring | Cumulative monthly burn |
|-------|----------|------------------|------------------------|
| **Stage 1 (closed pilot)** | $0–600 | $1,500–3,000 | ~$30–80 |
| **Stage 2 (open beta)** | +$3,000–6,000 | +$1,500–2,500 | ~$80–200 |
| **Stage 3 (public launch)** | +$5,000–10,000 | +$3,000–6,000 | ~$200–500 |
| **Stage 4 (scale)** | +$10,000–30,000 (SOC2 if needed) | +$10,000–20,000 | ~$500–2,000 |

**Bottom line:** the actual pre-pilot compulsory cost is **~$1,500–3,000 AUD/year** (almost entirely cyber insurance). Everything else is DIY or deferable until revenue arrives.

---

# STAGE 1 — Closed pilot launch (≤ 2 brands, ≤ 16 affiliates)

**Goal:** safely onboard the first 2 brands without launch-blocking risk. Most items here are DIY or one-time small spend. Compulsory out-of-pocket = ~$1,500–3,000 AUD/year (cyber insurance + optional legal templates).

**Suggested timeline:** 4–6 weeks from today.

## Phase 1 — Technical / operational must-do

- [ ] **TECH-1 · P0** — Close all 6 phases of source-scan audits → see `audit-checklist.md`
    - **When:** Before first pilot customer
    - **Cost:** ~$5–30 in DeepSeek spend + your time for fixes (~1.5–2 weeks)
    - **Notes:** Phase 1 in progress. Phases 2–6 still to run (~6h wall time, ~$5)

- [ ] **TECH-2 · P0** — Implement consolidated remediation plan from Phase 1 audits → see `audits/phase-1-security/remediation-plan.md`
    - **When:** Before first pilot customer
    - **Cost:** DIY (~1.5–2 weeks)
    - **Notes:** 4 architectural patterns + 13 standalone fixes; closes 26 unique findings

- [ ] **TECH-3 · P0** — Backup / restore drill (External X6 from `audit-checklist.md`)
    - **When:** Before first pilot customer
    - **Cost:** DIY (~half day — restore a Supabase snapshot to a fresh project, verify integrity)
    - **Notes:** A backup you've never restored isn't a backup. Document the restore steps as a runbook.

- [ ] **TECH-4 · P0** — Incident response runbook
    - **When:** Before first pilot customer
    - **Cost:** DIY (~1 day to write)
    - **What it covers:** Who decides this is an incident, severity tiers, communication template, postmortem template, customer notification thresholds, breach notification triggers (AU OAIC requires within 30 days for eligible data breaches)

- [ ] **TECH-5 · P0** — Secret rotation runbook
    - **When:** Before first pilot customer
    - **Cost:** DIY (~half day to write)
    - **What it covers:** For each credential class (Stripe keys, Supabase service role, Cloudflare API token, Shopify app secret, Hydrogen API key, Embedded API key), the steps to rotate without downtime + which env files / Laravel Cloud env vars to update
    - **Google Maps key note:** the public `GOOGLE_MAPS_API_KEY` (served CDN-cached at `/api/public/config/integrations`) is safe only while its GCP **HTTP-referrer restriction** (`*.partna.au/*`) holds. Re-verify that restriction in the Google Cloud Console as part of any rotation **and on every fresh-environment deploy** — dropping it on a new project is the highest-risk moment.

- [ ] **TECH-6 · P0** — DIY AI-driven pentest pass
    - **When:** Before first pilot customer
    - **Cost:** $5–50 (token spend) + DIY
    - **Approach:** Use Claude with computer-use against staging with a credentialed test brand; ask the agent to attempt privilege escalation, IDOR, and tenant boundary violations. Plus run OWASP ZAP (free) for automated baseline scan.
    - **Notes:** Not a substitute for a human pentest, but catches common-class bugs cheaply

- [ ] **TECH-7 · P0** — DIY load test (k6 or Locust)
    - **When:** Before first pilot customer
    - **Cost:** DIY (~half day) — k6 is free, scripts ~50 lines
    - **What to test:** Webhook burst (100 Shopify orders/min for 10 min), dashboard concurrent load (50 simultaneous brand sessions), affiliate signup burst
    - **Notes:** Not the full X4 scenario suite, but proves the queue + DB connections survive realistic ingest

- [ ] **TECH-8 · P0** — Status page (uptime communication)
    - **When:** At launch
    - **Cost:** Free (Atlassian Statuspage free tier, limited) or $29/mo (Better Uptime, Statuspage paid)
    - **Notes:** Even a manually-updated free page is better than nothing. Customers expect a URL like `status.partna.au`.

- [ ] **TECH-9 · P1** — External uptime monitoring
    - **When:** At launch
    - **Cost:** Free (UptimeRobot free tier — 50 monitors, 5min interval)
    - **Notes:** Independent of your own infra, so you find out about full outages

- [ ] **TECH-10 · P1** — Customer support channel
    - **When:** At launch
    - **Cost:** Free (just email — `support@partna.au`) or $20–50/mo (Help Scout free tier, Crisp)
    - **Notes:** With 2 brands, plain email is fine. Move to ticketing tool when volume warrants.

## Phase 2 — Legal / compliance must-do

- [ ] **LEGAL-1 · P0** — Terms of Service
    - **When:** Before first pilot customer signs
    - **Cost:** $0 (templates — Iubenda free tier, Termly) or **$500–1,000** (Sprintlaw AU "Essential Pack", LegalVision)
    - **Notes:** For B2B SaaS in AU, the Sprintlaw essential pack is the practical sweet spot. DIY templates are legally usable but harder to defend.

- [ ] **LEGAL-2 · P0** — Privacy Policy (AU Privacy Act 1988 + GDPR)
    - **When:** Before first pilot customer signs
    - **Cost:** $0 (templates) or **$500** (often bundled with ToS package)
    - **Notes:** Iubenda has a free generator. AU Privacy Act applies to any business with >$3M turnover OR any business handling health/credit info. GDPR applies if you have EU users. Both apply for safety; Australian regulatory bar is rising.

- [ ] **LEGAL-3 · P0** — Acceptable Use Policy
    - **When:** Before first pilot customer signs
    - **Cost:** Bundled with ToS
    - **Notes:** Required to terminate abusive accounts later

- [ ] **LEGAL-4 · P1** — Data Processing Agreement (DPA) template
    - **When:** When first B2B customer asks (often during contract negotiation)
    - **Cost:** $0 (Iubenda DPA template, GDPR.eu) or $500 if customised
    - **Notes:** B2B customers handling PII will ask. Have one ready rather than scrambling.

- [ ] **LEGAL-5 · P0** — ABN registration + business structure
    - **When:** Before any revenue
    - **Cost:** **Free** (`business.gov.au`)
    - **Notes:** If not already a Pty Ltd, consider registering one (~$500 via lawyer or DIY $497 via ASIC). Liability protection.

- [ ] **LEGAL-6 · P2** — GST registration
    - **When:** When forecasted annual turnover hits $75K AUD
    - **Cost:** Free
    - **Notes:** Once registered, must charge 10% GST on AU customer invoices and lodge BAS quarterly. Most AU SaaS founders register early to look professional.

- [ ] **LEGAL-7 · P3** — Trademark "Partna"
    - **When:** Optional, but cheap insurance
    - **Cost:** **$330 AUD per class** (IP Australia)
    - **Notes:** Software is class 9 + class 42. ~$660 total to cover both. Free trademark search at `search.ipaustralia.gov.au` first.

## Phase 3 — Insurance (the actually-required compulsory spend)

- [ ] **INS-1 · P0** — Cyber insurance quote
    - **When:** This week (free quotes)
    - **Cost:** Free to quote
    - **Quote sources:** BizCover (instant online quote), Insurance House, Honan, Coverforce. Get 3+ quotes.

- [ ] **INS-2 · P0** — Bind cyber insurance
    - **When:** Before first paying customer onboarded
    - **Cost:** **$1,500–3,000 AUD/year** (pre-revenue startup, limited PII)
    - **Notes:** This is the single biggest compulsory pre-launch cost. Many B2B customers will eventually ask "do you carry cyber insurance?" — have a yes ready. Coverage typically includes: breach response costs, customer notification, regulatory fines, business interruption, social engineering loss.

- [ ] **INS-3 · P1** — Professional indemnity insurance quote
    - **When:** Before scaling beyond pilot
    - **Cost:** Free to quote
    - **Notes:** Often bundled cheaply with cyber insurance. Covers errors/omissions in service delivery.

- [ ] **INS-4 · P2** — Bind professional indemnity insurance
    - **When:** Stage 2 (open beta) when customer count + contract size grows
    - **Cost:** **$1,000–3,000 AUD/year**

## Phase 4 — Customer-facing readiness

- [ ] **CX-1 · P0** — Onboarding flow end-to-end test with a real Shopify store
    - **When:** Before first pilot customer
    - **Cost:** DIY
    - **Notes:** Use a free Shopify development store. Click through every step from app install → setup wizard → first deploy. Document any friction.

- [ ] **CX-2 · P0** — GDPR data export endpoint
    - **When:** Before first pilot customer
    - **Cost:** DIY
    - **Notes:** Required by GDPR right-to-portability. Check Phase 1 audit findings for current state — Shopify GDPR webhooks are in place per memory `project_shopify_gdpr_webhooks_todo.md`.

- [ ] **CX-3 · P0** — GDPR data deletion endpoint (end-to-end)
    - **When:** Before first pilot customer
    - **Cost:** DIY
    - **Notes:** Right-to-erasure. Verify the full path works: Shopify webhook receives → job dispatched → all PII redacted including soft-deleted rows. Test with a real account.

- [ ] **CX-4 · P0** — Transactional email templates polished
    - **When:** Before first pilot customer
    - **Cost:** DIY
    - **Notes:** Welcome, password reset, invitation, payout notification, etc. Use real branding, not "Hello {{name}}".

- [ ] **CX-5 · P1** — Help center / docs
    - **When:** Stage 1 launch
    - **Cost:** Free (Notion public pages, GitHub Pages) or $39–99/mo (Intercom Help Center, HelpScout Docs)
    - **Notes:** Even 10 well-written articles covering top onboarding questions reduces support volume hugely.

- [ ] **CX-6 · P1** — Marketing site polish + clear pricing page
    - **When:** Before opening beyond direct outreach
    - **Cost:** DIY or designer
    - **Notes:** "Talk to sales" pricing is fine for pilot; switch to public pricing at Stage 2.

## Phase 5 — Financial / operational setup

- [ ] **FIN-1 · P0** — Stripe live mode + verification
    - **When:** Before accepting any real payment
    - **Cost:** Free (Stripe takes their %)
    - **Notes:** Live mode requires identity verification (driver's licence, ABN). Allow ~1–3 business days for Stripe to approve.

- [ ] **FIN-2 · P0** — Business bank account (separate from personal)
    - **When:** Before any revenue
    - **Cost:** Free (most AU business banks have $0 monthly fee for first year)
    - **Notes:** Required by Stripe for payouts. Common choices: CommBank Stream Business, NAB Business Everyday, Up Business.

- [ ] **FIN-3 · P0** — Pricing model finalized
    - **When:** Before first pilot customer
    - **Cost:** DIY (research + decision)
    - **Notes:** Even pilot customers should have a documented price even if discounted. "Free pilot" customers are often less committed than $200/mo customers.

- [ ] **FIN-4 · P1** — Accounting setup
    - **When:** Before first revenue
    - **Cost:** **$30/month** (Xero starter) or free (manual spreadsheet for the first few months)
    - **Notes:** Xero integrates with Stripe directly. Saves weeks at tax time.

- [ ] **FIN-5 · P1** — Tax handling (GST collection if registered)
    - **When:** Configure when you GST-register
    - **Cost:** Built into Stripe Tax ($0 base + 0.5% per transaction) or DIY in invoice generation
    - **Notes:** Stripe Tax handles AU GST automatically — worth the 0.5% to avoid manual quarterly BAS pain.

## Phase 6 — Funding setup (do this week, costs nothing, returns thousands)

- [ ] **FUND-1 · P0** — R&DTI registration assessment
    - **When:** This week
    - **Cost:** **Free** (initial consult with R&DTI specialist)
    - **Specialist sources:** Bulletpoint, Swanson Reed, Grant Thornton, Brentnalls
    - **What it returns:** Up to **43.5% refundable tax offset** on eligible R&D spend if turnover <$20M. For software development, most technical work qualifies. If you spent $50K of your time + cash building Partna in FY2026 → ~$21K back at end of financial year. Effectively funds your entire Stage 1 + 2 compliance/security spend with money to spare.
    - **Catch:** Must register the activity *before* claiming. Annual claim. Specialists take a 10–20% success fee but the math is overwhelmingly positive.

- [ ] **FUND-2 · P1** — State / federal grant scan
    - **When:** Within 4 weeks
    - **Cost:** Free to apply
    - **Sources:** `business.gov.au/grants-and-programs`, NSW Going Global Export Program, VIC LaunchVic, Cyber Security Skills Partnership Innovation Fund, Export Market Development Grant (EMDG)
    - **Notes:** Most are competitive but worth the application time

- [ ] **FUND-3 · P2** — Friends & family round consideration
    - **When:** If runway < 6 months
    - **Cost:** Equity dilution (typically 5–15% for $25–50K)
    - **Notes:** Standard pre-seed amount for AU SaaS. SAFE notes via Sprintlaw template ~$500.

---

# STAGE 2 — Open beta launch (5+ brands, ~50 affiliates)

**Goal:** open self-serve signup or expand pilot beyond direct outreach. Volume starts mattering — concurrency, support load, and brand expectations all rise. Compulsory out-of-pocket = ~$3,000–6,000 one-time + ~$1,500–2,500/year on top of Stage 1.

**Trigger:** confidence in the pilot + product-market fit signals + ~$2K+ MRR.

## Phase 1 — Technical / operational additions

- [ ] **TECH-S2-1 · P0** — Run remaining audit phases per `audit-checklist.md` Stage 2 section
    - LIFE-C, LIFE-E, LIFE-F (lifecycle correctness)
    - CACHE-A through D (Phase 3 scaling)
    - SCALE-A, SCALE-C (database/queue, partial)
    - TEST-C (policy + auth coverage)
    - DATA-C (GDPR / retention)
    - **Cost:** ~$5 in DeepSeek spend + your fix time

- [ ] **TECH-S2-2 · P0** — Tier-2 AI-augmented pentest
    - **When:** Before opening signup
    - **Cost:** **$3,000–5,000 AUD one-time** (Astra Pentest, XBOW, Intruder.io)
    - **Notes:** AI-driven, real findings, but no signed report suitable for SOC2/insurance. Great for pre-public-launch confidence.

- [ ] **TECH-S2-3 · P1** — HackerOne private bounty program
    - **When:** Stage 2 launch
    - **Cost:** **$1,000–2,000 AUD reward pool** (pay only on validated findings)
    - **Notes:** Continuous human pentesting at fraction of fixed-fee cost. Real attackers will find what AI misses.

- [ ] **TECH-S2-4 · P1** — Frontend audit via `/ultrareview`
    - **When:** Before opening signup
    - **Cost:** **$50–200** (per `/ultrareview` run cost) or DIY
    - **Notes:** Frontend repo per memory `reference_frontend_repo.md`. XSS or token mishandling there undermines backend hardening.

- [ ] **TECH-S2-5 · P1** — Partial X4 load test (Scenario 1: Shopify webhook burst)
    - **When:** Before opening signup
    - **Cost:** DIY ($0) or **$1,000–3,000** for managed load test service
    - **Notes:** 200 brands × concurrent orders. Tells you the queue + idempotency story.

- [ ] **TECH-S2-6 · P2** — Better monitoring / alerting tools
    - **When:** When pilot pages you at 2am for the first time
    - **Cost:** **$50–100/month** (PagerDuty for on-call, Grafana Cloud for dashboards beyond Nightwatch)
    - **Notes:** Nightwatch covers exceptions + slow paths; Grafana adds query-able operational metrics.

- [ ] **TECH-S2-7 · P2** — On-call rotation if more than just you
    - **When:** When you hire engineer #2
    - **Cost:** PagerDuty starter ~$20/user/month
    - **Notes:** Skip if still solo founder.

## Phase 2 — Legal / compliance additions

- [ ] **LEGAL-S2-1 · P0** — Tighten ToS and Privacy Policy with lawyer review
    - **When:** Before opening signup
    - **Cost:** **$500–1,500** (LegalVision, Sprintlaw, or fixed-fee solo lawyer)
    - **Notes:** Templates are fine for pilot; once you're signing 10+ brands, a real review pays for itself in dispute prevention.

- [ ] **LEGAL-S2-2 · P1** — Vendor contracts review
    - **When:** Stage 2
    - **Cost:** DIY (~half day)
    - **What to review:** Cloudflare, Supabase, Stripe, Shopify partner agreement, Laravel Cloud — read the SLAs, data processing terms, sub-processor lists. Document them in a "sub-processor list" page on your site (some customers ask).

- [ ] **LEGAL-S2-3 · P2** — Sub-processor list page on website
    - **When:** Before first enterprise prospect asks
    - **Cost:** Free (just a page listing vendors)
    - **Notes:** B2B customers expect this. Page lists every third party that processes their data + what data + jurisdiction.

## Phase 3 — Insurance additions

- [ ] **INS-S2-1 · P1** — Increase cyber insurance limit
    - **When:** When customer count or PII volume materially grows
    - **Cost:** Step-up from $1.5–3K to **$3–6K AUD/year** as coverage limit increases
    - **Notes:** Pre-pilot policy might be $500K limit; Stage 2 typically $1–2M limit. Re-shop annually.

- [ ] **INS-S2-2 · P2** — Bind professional indemnity insurance
    - **When:** When contract value exceeds insurance gap
    - **Cost:** **$1,000–3,000 AUD/year**

## Phase 4 — Customer-facing additions

- [ ] **CX-S2-1 · P1** — Help center → real product
    - **When:** Stage 2 launch
    - **Cost:** **$39–99/month** (HelpScout Docs, Intercom)
    - **Notes:** Notion pages start to look amateurish at 5+ brands.

- [ ] **CX-S2-2 · P1** — Public pricing page
    - **When:** Stage 2 launch
    - **Cost:** DIY
    - **Notes:** "Contact sales" pricing kills self-serve signup. Even an opinionated 3-tier table beats nothing.

- [ ] **CX-S2-3 · P2** — Onboarding email sequence (drip)
    - **When:** Stage 2 launch
    - **Cost:** Built into transactional email tool (~$0–50/mo extra)
    - **Notes:** Day 0 welcome, day 3 setup nudge, day 7 first-deploy congrats, day 14 feature discovery.

---

# STAGE 3 — Public launch (20+ brands, ~500 affiliates)

**Goal:** open the gates publicly. Marketing, directory listings, real PII volume, real money flow at meaningful scale. This is where the compliance machinery activates. Compulsory out-of-pocket = ~$5,000–10,000 one-time + ~$3,000–6,000/year on top of Stages 1+2.

**Trigger:** ~$10K+ MRR + product-market fit confirmed + interest from larger customers.

## Phase 1 — Technical / operational additions

- [ ] **TECH-S3-1 · P0** — Run Stage 3 audit phases per `audit-checklist.md`
    - SCALE-B, SCALE-D, SCALE-E (jobs/queue, controllers, migrations under load)
    - TEST-D, TEST-E (resource/form-request, migration invariants)
    - DATA-D (enum drift)
    - Re-baseline Phase 1 Security
    - **Cost:** ~$5 in DeepSeek spend + your fix time

- [ ] **TECH-S3-2 · P0** — Tier-3 traditional pentest with signed report
    - **When:** Before public launch OR before first enterprise customer signs (whichever first)
    - **Cost:** **$5,000–8,000 AUD** (Cobalt PTaaS, modern boutique firm). $10K+ for tier-1 firms (NCC Group, Bishop Fox, Trail of Bits) — overkill until enterprise sales.
    - **Notes:** This is the gate that produces the *signed report* that insurance, enterprise procurement, and SOC2 auditors require. AI tools can't replace this artifact.

- [ ] **TECH-S3-3 · P0** — Full X4 load test suite
    - **When:** Before public launch
    - **Cost:** DIY ($0) or **$3,000–5,000** for managed
    - **Notes:** All 4 scenarios from `audit-checklist-external.md`. Confirms throughput at scale target.

- [ ] **TECH-S3-4 · P0** — CI hardening (X7)
    - **When:** Before public launch
    - **Cost:** DIY (~2–3 days)
    - **What:** `composer audit` + `npm audit` blocking on critical CVEs in CI. Critical-path coverage gates (e.g., webhook handlers must have idempotency tests). Codeowners file. Branch protection.

- [ ] **TECH-S3-5 · P1** — Operational runbooks tightened
    - **When:** Before public launch
    - **Cost:** DIY (~1 week to write all)
    - **Coverage:** Per credential class (rotation), per vendor outage (Shopify down, Stripe down, Supabase down, Cloudflare down), per incident severity (data breach, payment failure, mass account lockout)

- [ ] **TECH-S3-6 · P1** — Status page upgrade (auto-publishing from monitoring)
    - **When:** Public launch
    - **Cost:** **$29–99/month** (Statuspage paid, Better Uptime)
    - **Notes:** Manual updates don't scale to 100+ brands. Wire to Nightwatch + UptimeRobot for auto-updates.

- [ ] **TECH-S3-7 · P2** — Backup drill cadence (quarterly)
    - **When:** Public launch onward
    - **Cost:** DIY (~half day per drill)
    - **Notes:** Document each drill — date, RTO/RPO measured, any issues found.

## Phase 2 — Legal / compliance additions

- [ ] **LEGAL-S3-1 · P1** — DPA finalized and signable
    - **When:** Before first enterprise prospect signs
    - **Cost:** **$500–2,000** for lawyer review of your template
    - **Notes:** Enterprise procurement always asks. Have a clean PDF ready.

- [ ] **LEGAL-S3-2 · P2** — SOC2 readiness assessment
    - **When:** Only when a customer specifically demands SOC2
    - **Cost:** **$5,000–15,000** for readiness gap analysis (Vanta, Drata, Tugboat Logic)
    - **Notes:** SOC2 itself is $20–50K + 6–12 months. Don't start until a real customer is conditional on it.

- [ ] **LEGAL-S3-3 · P2** — Vendor security questionnaire response template
    - **When:** Before first enterprise prospect
    - **Cost:** DIY (~1 day)
    - **Notes:** Pre-fill answers to standard questionnaires (SIG Lite, CAIQ). Saves days per enterprise deal.

## Phase 3 — Insurance additions

- [ ] **INS-S3-1 · P1** — Cyber insurance step-up (higher limits, broader coverage)
    - **When:** At public launch
    - **Cost:** **$5,000–10,000 AUD/year** for $5M+ limit
    - **Notes:** Carrier may require pentest report (TECH-S3-2) as a condition.

- [ ] **INS-S3-2 · P2** — Directors & Officers (D&O) insurance
    - **When:** When you raise institutional capital
    - **Cost:** **$2,000–5,000 AUD/year**
    - **Notes:** Required by most VCs. Skip if bootstrapping.

## Phase 4 — Customer-facing / GTM additions

- [ ] **CX-S3-1 · P1** — Onboarding self-serve fully polished
    - **When:** Public launch
    - **Cost:** DIY
    - **Notes:** No human touch required for SMB segment.

- [ ] **CX-S3-2 · P1** — Public security page (`/security`)
    - **When:** Public launch
    - **Cost:** DIY (~half day)
    - **Notes:** Lists: encryption in transit + at rest, sub-processor list, last pentest date (after S3-2 lands), vulnerability disclosure email, cyber insurance carrier.

- [ ] **CX-S3-3 · P2** — Vulnerability disclosure / responsible disclosure policy
    - **When:** Public launch
    - **Cost:** DIY (~1 hour to write, free template at `disclose.io`)
    - **Notes:** Goes on `/security` page. Tells researchers how to report bugs without legal risk. Reduces risk of unfriendly disclosure.

## Phase 5 — Financial additions

- [ ] **FIN-S3-1 · P1** — Bookkeeper or accountant relationship
    - **When:** When financial complexity grows beyond DIY
    - **Cost:** **$200–500/month** (bookkeeper) or **$3,000–8,000/year** (accountant for tax + advice)
    - **Notes:** R&DTI claims (FUND-1) often require accountant assistance.

- [ ] **FIN-S3-2 · P2** — Pricing model evolution (per-seat, usage-based, tiered)
    - **When:** When pricing friction is hurting deals
    - **Cost:** DIY analysis
    - **Notes:** Pilot pricing is rarely the right Stage-3 pricing. Most SaaS evolves pricing 2–3 times in first 24 months.

---

# STAGE 4 — Scale (~200 brands × ~50 affiliates)

**Goal:** the design target. Audits and security become continuous practices, not events. This stage is about cadence and operational maturity.

**Trigger:** ~$50K+ MRR + structured GTM motion + likely team of 5+.

## Continuous cadence (replaces one-off Stage 1–3 items)

- [ ] **OPS-S4-1 · P0 (continuous)** — Monthly source-scan audit re-run
    - **Cost:** ~$5/month in DeepSeek spend + ~half day per phase
    - **Cadence:** One phase per week, all 6 phases per month

- [ ] **OPS-S4-2 · P0 (continuous)** — Monthly `composer audit` + `npm audit`
    - **Cost:** Free (CI-integrated)

- [ ] **OPS-S4-3 · P1 (continuous)** — Quarterly load test re-baseline
    - **Cost:** DIY ($0) or $3K/quarter managed
    - **Notes:** Confirms scaling assumptions still hold

- [ ] **OPS-S4-4 · P1 (continuous)** — Quarterly backup / restore drill
    - **Cost:** DIY (~half day each)

- [ ] **OPS-S4-5 · P0 (annual)** — Annual third-party pentest
    - **Cost:** **$5,000–10,000 AUD** (Cobalt PTaaS or boutique)
    - **Notes:** Required by most cyber insurance policies + enterprise procurement.

- [ ] **OPS-S4-6 · P2 (annual)** — Annual ToS / Privacy Policy review
    - **Cost:** **$500–2,000 AUD**
    - **Notes:** Regulatory landscape changes; AI/privacy laws especially.

- [ ] **OPS-S4-7 · P1** — SOC2 attestation if recurring enterprise sales
    - **Cost:** **$20,000–50,000 AUD initial + $15K/year**
    - **Notes:** Only worth it if customers are actually demanding it. Vanta/Drata streamline the process.

## New concerns at this scale

- [ ] **CAP-S4-1 · P1** — Capacity planning (Redis memory, Postgres connections, queue worker counts)
- [ ] **CAP-S4-2 · P2** — Multi-region / failover (currently single region per memory `reference_deployment.md`)
- [ ] **CAP-S4-3 · P2** — Cost monitoring (vendor API spend, observability spend, AI spend)
- [ ] **CAP-S4-4 · P1** — SLO definition + alerting tuned to scale
- [ ] **HR-S4-1 · P1** — On-call rotation across team
- [ ] **HR-S4-2 · P2** — Security training for non-engineering staff (phishing-resistant culture)

---

# Funding strategy

The actual cost picture for a bootstrapped AU pre-pilot SaaS founder targeting SMB customers:

## Year 1 (pre-pilot → end of pilot)

| Source | Approximate annual contribution | Notes |
|--------|-------------------------------|-------|
| Founder cash | $5,000–10,000 | Stage 1 + 2 compulsory items |
| Pilot revenue (2–5 brands × $200/mo) | $5,000–12,000 | Funds Stage 2 spend if pricing is non-zero |
| **R&DTI tax offset** | **$15,000–25,000** (refunded at FY end) | If $40–60K of dev spend is eligible R&D |
| State/federal grants | $0–10,000 | Highly variable, competitive |
| F&F angel round | $0 or $25–50K | Optional; covers Stage 2 with margin |

**Total Year 1 funded budget: $25,000–80,000** (depends on R&DTI + grant outcomes + F&F)

## Year 2 (pilot → public launch)

| Source | Approximate annual contribution |
|--------|-------------------------------|
| Year-1 R&DTI cash refund | $15,000–25,000 (received Q1–Q2 of Year 2) |
| Pilot/beta revenue (10–30 brands × $200–500/mo) | $30,000–150,000 |
| Year-2 R&DTI accrual | $20,000–40,000 (claimed end of Year 2) |
| Optional seed round | $0 or $250K–1M |

**Total Year 2 funded budget: $65,000–215,000+**

## What this means in practice

- **Stage 1 is cheap enough to bootstrap entirely** ($1.5–3K/year compulsory + your time). Most of the rest of Stage 1 is DIY.
- **R&DTI is the single biggest unlock.** Register this week. The first claim funds your entire Stage 2 spend.
- **Stripe revenue covers Stage 2 spend** even at modest pilot volumes ($1–3K MRR funds the $3–6K Stage 2 one-time spend over 1–6 months).
- **Defer the human pentest until Stage 3.** Use the AI tools + bug bounty in Stages 1–2.
- **Defer SOC2 indefinitely** until a customer specifically requires it. Then use that contract revenue to pay for it.

## Quick actions to take this week (zero / low cost, high return)

- [ ] **FUND-1**: Book a free R&DTI consultation with a specialist (Bulletpoint, Swanson Reed, Grant Thornton)
- [ ] **INS-1**: Get 3 cyber insurance quotes via BizCover (instant), Insurance House, Honan
- [ ] **TECH-1**: Run Phases 2–6 audits in parallel (~6h wall time, ~$5 cost) — see `audit-checklist.md`
- [ ] **LEGAL-5**: Confirm ABN + business structure is correct; consider Pty Ltd if not already
- [ ] **TECH-3**: Schedule a backup drill — restore the latest Supabase snapshot to a dev project, verify data integrity

These five take ~2 hours of your time combined and unlock significant downstream value (R&DTI alone can return $20K+).

---

# Cross-references

- **Source-scan audit plan**: `audit-checklist.md`
- **External validation plan**: `audit-checklist-external.md`
- **Phase 1 Security findings**: `audits/phase-1-security/` directory
- **Phase 1 remediation plan**: `audits/phase-1-security/remediation-plan.md`
- **Frontend repo (separate)**: per memory `reference_frontend_repo.md`
- **Deployment context**: per memory `reference_deployment.md`
