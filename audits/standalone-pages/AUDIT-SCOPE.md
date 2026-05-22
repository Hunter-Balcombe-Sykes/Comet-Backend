# Audit Scope — Standalone-User Backend

**Goal:** audit the **whole backend**. Since the standalone strip (Tasks 2–8,
2026-05-22), the backend serves exactly one product surface — individual
professionals' public site pages (signup → dashboard sitepage editor → public
`<handle>.partna.au` page). There is no brand, affiliate, partner, Shopify,
Square, Fresha, Stripe, commerce, orders, or payouts code left. The audit scope
is therefore simply: everything under `app/`, `routes/`, `config/`, and
`supabase/migrations/`.

Date: 2026-05-22 · rewritten post-strip (supersedes the pre-strip carved scope).

---

## Scope

The entire backend is in scope. There is no longer any feature surface to
carve out — the strip removed every non-standalone domain. Audit:

- `app/` — controllers, services, jobs, models, observers, policies, resources,
  middleware, requests, enums, mail.
- `routes/` — `api.php` and the `api/{user,publicSite,staff}.php` files.
- `config/` — all config, especially `config/sidest.php` feature flags/limits.
- `supabase/migrations/` — the single consolidated baseline
  `20260526000000_baseline_standalone_user.sql`.

## Surviving domains

User accounts; sites/sitepages; blocks/sections/links; services +
service_categories (display-only catalog); site media + image variants;
customers (CRM contacts); waitlist; enquiries/leads; notifications + email
subscriptions; site analytics (visits/clicks/section views); GDPR data export
+ account deletion; feature flags; MFA/AAL2 staff auth; Cloudflare KV
subdomain routing + edge cache.

## Hardening that must not regress

SWR/single-flight cache core (`CacheLockService`, `SiteCacheService`);
MFA/AAL2 (`VerifySupabaseJwt`, `RequireAal2`, `require.aal2`); notification
idempotency (`SendEnquiryNotificationJob`); GDPR deletion/export path;
Policy-based authorization (`BasePolicy`, the inline-403 CI guard,
`PolicyCoverageTest`).

---

## NOTE — audit ≠ completeness

An audit finds defects in code that **exists**. It will not surface a
**missing** backend endpoint. Alongside the audit, run a completeness check:
walk the standalone-page user journeys end to end and confirm every required
endpoint is built.
