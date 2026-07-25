# AI_CONTEXT.md — Partna Platform (Standalone Individual)

> **Source of truth for AI tools working on this codebase.**
> Read this before making changes. Update after meaningful progress.

---

## Project Overview

**Partna** is a single-purpose backend for individual professionals' public site pages: signup → dashboard sitepage editor → public `<handle>.partna.au` page.

This is a stripped, individual-user-only backend. All brand/affiliate/partner/Shopify/Square/Fresha/Stripe/commerce/orders/payouts/billing code and database tables have been removed.

**What problem it solves:**
Individual professionals (barbers, hairdressers, stylists, etc.) need a polished public presence page on a custom subdomain, with a service catalog, gallery, analytics, and lead capture — without any commerce or affiliate complexity.

**Main goals:**
1. Give each individual a published site on a `<handle>.partna.au` subdomain
2. Let them manage their site content (blocks, links, sections, gallery, services) via a dashboard
3. Collect leads and analytics (page views, link clicks)
4. Provide GDPR data export and account deletion

**Current status:** Pilot-stage standalone backend. Shopify/Stripe/commerce stripped out; reintegration planned post-pilot.

---

## Core Idea

### Plain-English Explanation

- Signup is **site-first** (Pre-Account Sites, 2026-07-18): a visitor gives a source (an Instagram
  handle or a Google Business listing) and Partna builds a real, previewable site for them — an
  auth-less **provisional** `core.users` row (`status='unclaimed'`, `auth_user_id`/`primary_email`
  both `NULL`) plus an unpublished `site.sites` row — before any account exists.
- A background job scrapes the source and populates the site; the frontend polls until it's ready.
- The visitor then signs in with Supabase (email OTP) and **claims** the finished site — first-come —
  which binds their `auth_user_id`/`primary_email` onto the provisional row and flips `status` to `active`.
- `POST /api/bootstrap` no longer creates accounts — it only refreshes an existing user's profile
  (410s a JWT with no matching row, pointing at the build/claim flow).
- The user configures their site in the dashboard (bio, gallery, links, services, design tokens).
- On publish, `SyncSubdomainToKvJob` writes `{type:"individual"}` to `SUBDOMAIN_KV`.
- `<handle>.partna.au` hits the Cloudflare Worker → KV lookup → Astro app renders the page.
- Visitors trigger analytics events (pageviews, clicks). Leads submit via the public API.
- An unclaimed build that's never claimed expires (30 days by default) and is hard-deleted by the
  `builds:prune-expired` command.
- Staff (and ManyChat automation) can trigger the same builds via `POST /api/staff/builds` — these
  publish immediately (the live site IS the marketing pitch) with a configurable expiry; the
  Cloudflare KV routing entry for any unclaimed site carries a TTL aligned to the build's
  `expires_at`, so an expired build stops routing at the edge even if pruning lags.

### How the System Works

```
Visitor picks a source (Instagram handle / Google Business listing)
    ↓
POST /api/public/signup/build → provisional core.users (status='unclaimed') + unpublished site.sites
    + core.pre_account_builds row (build_state='pending')
    ↓
GeneratePreAccountSiteJob scrapes the source, populates the site → build_state='ready' (or 'failed')
    ↓
Frontend polls GET /api/public/signup/builds/{id} until ready
    ↓
Visitor signs in with Supabase (email OTP) → POST /api/claim (first-come)
    → auth_user_id/primary_email bound, status='active', pre_account_builds.claimed_at set
    ↓
User configures site (gallery, blocks, services, design, publish)
    ↓
SyncSubdomainToKvJob writes {type:"individual"} to SUBDOMAIN_KV
    ↓
<handle>.partna.au → Cloudflare Worker → KV lookup → Astro site render
    ↓
Visitor analytics + lead capture → analytics.* tables

(unclaimed, never built on: builds:prune-expired hard-deletes the user/site/build after 30 days)
```

---

## Codebase Summary

**Stack:** Laravel 12 · PHP 8.2+ · PostgreSQL (Supabase) · Redis · Cloudflare R2 · Supabase Auth (JWT)

### Directory Map

```
/
├── app/
│   ├── Models/
│   │   ├── Core/
│   │   │   ├── User/          — User (class App\Models\Core\User\User, table core.users), PreAccountBuild, Customer, Service, ServiceCategory, UserConfirmationPreference, UserDeletionAuditEntry
│   │   │   ├── Site/          — Site, Block, SiteMedia, SiteSubdomainAlias, Theme
│   │   │   ├── Notifications/ — Notification, NotificationReceipt, EmailSubscription, NotificationEmailPolicy, NotificationEmailPreference
│   │   │   ├── Staff/         — PartnaStaff
│   │   │   ├── Waitlist/      — WaitlistSignup
│   │   │   └── MediaVariant
│   │   ├── Analytics/         — SiteVisit, LinkClick, LeadSubmission
│   │   └── Views/             — PublicSitePayload, AllSiteData (read-only views)
│   ├── Http/
│   │   ├── Controllers/Api/
│   │   │   ├── User/          — Authenticated user dashboard endpoints
│   │   │   ├── PublicSite/    — Unauthenticated mini-site endpoints
│   │   │   ├── Staff/         — Internal staff/admin endpoints
│   │   │   └── Internal/      — Server-to-server endpoints (Cloudflare Worker)
│   │   ├── Middleware/        — JWT auth, role guards, cache headers
│   │   ├── Requests/          — Form request validation classes
│   │   └── Resources/         — API response transformers
│   ├── Services/
│   │   ├── Analytics/         — Site analytics aggregation
│   │   ├── Auth/              — SupabaseAdminService
│   │   ├── Cache/             — Site, user, analytics caching + CacheLockService (SWR)
│   │   ├── Customers/         — CRM contacts
│   │   ├── Media/             — Image + video variant processing
│   │   ├── Notifications/     — Notification publishing, email dispatch
│   │   ├── User/              — User onboarding, site provisioning, defaults
│   │   ├── PublicSite/        — Public site resolution
│   │   ├── Site/              — Site content management
│   │   └── PreAccount/        — Site-first signup: PreAccountBuildService, ClaimSiteService, source generators (Instagram, Google Business)
│   ├── Jobs/                  — Analytics, Cache, Notifications, PreAccount (GeneratePreAccountSiteJob) + root-level media jobs
│   ├── Observers/             — Cache invalidation, notification triggers
│   └── Console/Commands/      — Analytics backfill/compact/purge, notification pruning, soft-delete purge, builds:prune-expired (pre-account builds)
├── routes/
│   ├── api.php                — bootstrap, claim, signup/build + poll, health
│   ├── api/user.php           — User dashboard routes
│   ├── api/publicSite.php     — Public mini-site routes (subdomain-scoped)
│   └── api/staff.php          — Staff/admin routes
├── supabase/migrations/       — Single baseline SQL migration (20260726000000_baseline_pilot.sql)
├── supabase/migrations-archive/ — 379 historical migrations (reference only; already applied)
├── config/sidest.php           — Feature flags and limits
├── tests/                     — Pest framework tests
└── docs/api.md                — API reference
```

### Core Models

| Model | Class | Table | Role |
|-------|-------|-------|------|
| `User` | `App\Models\Core\User\User` | `core.users` | Central identity for individual users |
| `Site` | `App\Models\Core\Site\Site` | `site.sites` | Mini-site config (subdomain, theme, settings, publish state) |
| `Service` | `App\Models\Core\Site\Service` | `site.services` | Display-only service catalog |
| `ServiceCategory` | `App\Models\Core\Site\ServiceCategory` | `site.service_categories` | Groups services for display |
| `Customer` | `App\Models\Core\User\Customer` | `site.customers` | CRM contact records per user |
| `Block` | `App\Models\Core\Site\Block` | `site.blocks` | Site content blocks (links, sections) |
| `SiteMedia` | `App\Models\Core\Site\SiteMedia` | `site.site_media` | Images/videos with processing states |
| `MediaVariant` | `App\Models\Core\MediaVariant` | `site.media_variants` | Processed variants (WebP, MP4, HLS, poster) |
| `PartnaStaff` | `App\Models\Core\Staff\PartnaStaff` | `core.sidest_staff` | Staff/admin accounts |
| `PreAccountBuild` | `App\Models\Core\User\PreAccountBuild` | `core.pre_account_builds` | Permanent origin record for a site-first signup build (source, build state, claimed_at); 1:1 with a provisional `User`, survives claim |
| `Notification` | — | `notifications.notifications` | In-app notification records |
| `EmailSubscription` | — | `notifications.email_subscriptions` | Marketing email opt-in per user |
| `SiteSubdomainAlias` | — | `site.site_subdomain_aliases` | Subdomain alias management |
| `Theme` | — | `site.themes` | Site theme definitions (platform-wide catalog, lives in site for render-data co-location) |
| `SiteVisit` | — | `analytics.site_visits` | Page view events |
| `LinkClick` | — | `analytics.link_clicks` | Link/section click events |
| `LeadSubmission` | — | `analytics.lead_submissions` | Lead form submissions |

### Database Schemas

| Schema | Contents |
|--------|----------|
| `public` | Laravel infrastructure (cache, jobs, failed_jobs) |
| `core` | Users, customers, themes, staff |
| `site` | Sites, blocks, media, services, service_categories, subdomain_aliases |
| `notifications` | Notifications, receipts, email subscriptions, policies, preferences |
| `analytics` | Raw event tables: `site_visits`, `link_clicks`, `lead_submissions` |

`DB_SEARCH_PATH=public,core,site,notifications,analytics`

---

## How It Works

### Authentication & Middleware Stack

```
Request → VerifySupabaseJwt (validate JWT via JWKS, extract supabase_uid)
        → LoadCurrentUser (load User from cache)
        → [EnsurePartnaStaff] (require staff role)
        → [EnsurePartnaAdmin] (require admin role)
        → Controller
```

### Cloudflare KV / Worker Routing

All `<handle>.partna.au` requests go through the Cloudflare Worker:
- `{type:"individual"}` → `caches.default.match`; on miss, Service Binding to Astro app; on hit, serve from Cache API
- `{type:"alias"}` → 301 to the canonical subdomain URL

`SyncSubdomainToKvJob` is the ONLY writer to `SUBDOMAIN_KV`. Handle/subdomain renames write alias rows to `site.site_subdomain_aliases`.

### Cache Pattern

Public site payload: Redis via `CacheLockService::rememberLocked` (60s TTL + jitter + SWR, single-flight). Push-invalidated on every site write. Cache-purge job invalidates the Cloudflare Cache API entry by URL.

### Queue Architecture

| Queue | Connection | Purpose |
|-------|-----------|---------|
| `default` | redis | Notifications, cache warm, KV sync |
| `analytics` | redis | Analytics processing |
| `images` | redis | Image variant processing |
| `videos` | redis_video | Video transcoding (dedicated connection) |
| `mail` | redis | Email delivery |

---

## Surviving Domains

- Site-first signup + claim flow (provisional/unclaimed users, `core.pre_account_builds`, staff marketing builds)
- User accounts (profile, handle, bio, about, location)
- Sites / sitepages (subdomain, publish state, design tokens)
- Blocks + sections + links (site builder)
- Services + service_categories (display-only catalog)
- Site media + image/video variants
- Customers (CRM contacts, marketing opt-in)
- Waitlist
- Enquiries / leads
- Notifications + email subscriptions
- Analytics (site visits, link clicks, section views)
- GDPR data export + account deletion
- Feature flags
- MFA / AAL2 staff auth
- Cloudflare KV subdomain routing + cache
- Handle/subdomain aliases and redirect lifecycle

---

## Rules and Constraints

### Critical Constraints

- **`account_type` is always `individual`.** There are no brand/partner types in this codebase.
- **Never use Laravel migrations.** All schema changes use `supabase/migrations/` (plain SQL). There is a composer guard enforcing this.
- **Supabase JWT only for auth.** Never add password-based auth or Laravel Sanctum.
- **Multi-schema PostgreSQL.** Schemas: `public`, `core`, `site`, `notifications`, `analytics`. No `brand`, `commerce`, or `billing` schemas.
- **R2 for all media.** Never store media in local filesystem or Supabase Storage.
- **No commerce, Shopify, Stripe, Square, or Fresha code.** These integrations have been stripped and will be reintroduced post-pilot.

### Coding Conventions

- Follow **Laravel conventions** — Eloquent relationships, Form Requests for validation, Service classes for business logic.
- Use **Pest** for all tests. No PHPUnit-style test classes.
- Format with **Laravel Pint** (`./vendor/bin/pint`) before committing.
- Controllers should be thin — delegate to Services.
- All API responses use **Resource classes** — never return raw Eloquent models.
- Soft deletes on any user-generated content model.
- Cache invalidation must happen in Observers or after write operations.

---

## AI Working Instructions

When another AI reads this file, it should:
- Read this document before making changes.
- Check `// V2:` or inline comments on classes for per-file context.
- Never recreate brand/affiliate/commerce/Shopify/Stripe/Square/Fresha concepts — these are deliberately removed.
- Explain proposed changes before large refactors.
- Update this file after meaningful implementation.
- Never run `php artisan migrate` — use `supabase/migrations/` for schema changes.
- Check `docs/api.md` for the authoritative API reference before adding or modifying endpoints.

---

## Decisions Log

| Date | Decision | Reason |
|------|----------|--------|
| Pre-2026 | Use Supabase for auth (JWT) | Supabase handles cross-platform auth; avoids managing passwords |
| Pre-2026 | Use Supabase PostgreSQL with multiple schemas | Clean domain separation |
| Pre-2026 | Disable Laravel migrations; use supabase/migrations only | Supabase manages DB schema; avoids conflicts with RLS and extensions |
| Pre-2026 | Use Cloudflare R2 for all media storage | Cost-effective, S3-compatible, CDN-native |
| Pre-2026 | Feature-flag video uploads | FFmpeg workers must be provisioned separately |
| 2026-03-19 | Created AI_CONTEXT.md as shared AI source of truth | Multiple AI tools working on codebase need a shared orientation document |
| 2026-04-03 | Renamed Comet → Partna | Full codebase rename across config, routes, middleware, models |
| 2026-05-22 | Stripped to individual-standalone-site essentials | Removing brand/affiliate/Shopify/Stripe/commerce for pilot; reintegrate post-pilot |
| 2026-05-22 | Renamed Professional model to User | Cleaner terminology for individual-only platform |
| 2026-05-22 | Consolidated 147 migrations to single baseline | Clean slate for the standalone pilot DB schema |
| 2026-07-18 | Site-first signup (Pre-Account Sites): build a real site before any account exists, claim it after | Marketing conversion — a working preview site converts better than a signup form; `POST /api/bootstrap`'s create branch retired (410 for new JWTs) |
