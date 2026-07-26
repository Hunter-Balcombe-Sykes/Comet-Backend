# Prompt — write the k6 load-testing implementation plan

Paste the block below into a fresh session when ready to build + run the load tests
(realistically after the audit-fix P0/P1 run). The design is settled; the job is the
step-by-step build plan, not a redesign.

---

We're ready to turn the k6 load-testing design into an implementation plan.

**Source of truth:** `docs/superpowers/specs/2026-07-18-k6-load-testing-design.md`. Read it
fully first — it holds every decision (environment, the two-tier read path, the rate-limiter
constraint, the phased scenarios, guardrails, and the explicit non-goals). Don't re-open
settled decisions; if something in it now looks wrong because the code has moved, flag it
rather than silently changing course.

**Invoke the `writing-plans` skill** and produce the implementation plan from that spec.

**Pre-flight — re-validate the spec against current code before planning (it was written
2026-07-18; things may have drifted):**
1. `GET /api/public/profiles/{handle}` still exists and still uses `throttle:public-profile`.
2. The `public-profile` limiter is still 60/min keyed by `CF-Connecting-IP ?? ip`, tunable via
   `config('partna.public_profile.rate_limit_per_minute')` (defined in `AppServiceProvider`).
3. The Cloudflare Worker still populates `caches.default` (the edge tier the spike test depends on).
4. The `redis_video` queue connection still exists (Phase 3 cross-queue-starvation check).
5. Laravel Cloud reality unchanged — dev still serves both API domains and prod is still paused.
   **If prod has been un-paused, STOP and flag it:** the §2 environment decision changes (dev
   becomes an isolated regression target; a capacity run now wants a prod-sized target).
6. `k6` is installed locally (`k6 version`; else `brew install k6`).

Report any drift before writing the plan.

**Two inputs required from Josh — ask if not already provided:**
- **Target load** — expected launch-day peak (concurrent viewers or req/s) × safety factor.
  Every threshold is judged against this number.
- **Test handle** — the throwaway handle + test user that isolates writes (or confirm you
  should create one).

**Build constraints:**
- Harness lives in `scripts/launch-check/k6/` per the spec's §9 layout; results JSON gitignored.
- No Laravel migrations — any seed/teardown is tinker/SQL scoped to the test `site_id`.
- Phase 0 (representative-data seed) is a prerequisite to any measured run; Phase 3 (job/upload
  saturation) stays deferred until Phases 1–2 pass.
- Guardrails from §6 are non-negotiable: agreed caps, `X-Load-Test: 1` tagging, teardown
  cleanup, live kill-switch.
- Preserve the collaboration model (§8): Claude drives k6 + tails `cloud env:logs partna
  development --live`; Josh watches Horizon + Supabase connections + Nightwatch; checkpoint
  together between every phase.
- Josh commits; work on a branch off `development`.

**Scope guard:** this is the DIY single-origin pass. Distributed / multi-IP spike testing
(k6 Cloud) is explicitly out of scope (spec §7) — don't plan it in.

---
