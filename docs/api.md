# API Reference

This document is the single source of truth for backend so the frontend can build:

- Public mini-site (read-only site payload + lead capture + email subscribe + analytics)
- Professional dashboard (profile + site settings + links + sections + services + gallery + customers + analytics + notifications)
- Staff dashboard (staff-only browsing + admin editing tools)
- Backend: Laravel API (this repo)
- Auth: Supabase Auth (JWT access token)
- Media: Laravel Cloud Object Storage (S3-compatible / Cloudflare R2) with server-side WebP processing

## Contents

- Recent Backend Changes (Commit Log Snapshot)
- Environments and Base URLs
- Authentication (Supabase JWT)
- Site-first signup (pre-account builds)
- Roles and permissions
- Data Models
- Conventions (headers, errors, pagination, rate limits)
- Public Mini-Site API
- User Dashboard API
- Staff API
- Media uploads & processing (images + videos, server-side via queue)
- Test users and getting tokens
- Insomnia collection
- Frontend env var checklist
- Backend env var checklist
- Known implementation gotchas

## Companion Docs

- **[docs/social-links.md](./social-links.md)** — full conceptual guide to the social link platform registry (37 entries as of 2026-08-27 — count from config/partna.php `social_platforms`; handle/URL normalization, security model, frontend integration). Read this before working on link blocks, the social picker, or adding a new social platform.
- **[docs/frontend-contracts/](./frontend-contracts/)** — endpoint-level 202+poll async migrations, aimed at the (separate-repo) frontend. Read the relevant one before integrating against an endpoint that changed shape:
  - **[instagram-connect-async.md](./frontend-contracts/instagram-connect-async.md)** — Instagram connect.
  - **[2026-07-02-async-link-connect.md](./frontend-contracts/2026-07-02-async-link-connect.md)** — custom links / online ordering / booking / reservations "custom" branches.
  - **[2026-07-23-refresh-async.md](./frontend-contracts/2026-07-23-refresh-async.md)** — manual per-platform refresh (`POST .../refresh`).

## 0) Recent Backend Changes (Commit Log Snapshot)

> This section is preserved for context but covers the pre-strip history. The backend has been stripped to individual-user-only (brand/affiliate/Shopify/Stripe/commerce removed) as of 2026-05-22.

## 1) Environments and Base URLs

All endpoints below are served under the Laravel API base URL, with the default /api prefix.

### API base URL

- API base URL is your APP_URL (Laravel). Example: https://api.partna.au
- All API routes live under /api. Example: https://api.partna.au/api/me Public mini-site domain rules Public mini-site routes are domain-scoped. They MUST be called on the mini-site host, not the API host.
- Host pattern: https://{subdomain}.{PARTNA_PUBLIC_DOMAIN}
- Public API base URL: https://{subdomain}.{PARTNA_PUBLIC_DOMAIN}/api
- Example: https://joshbarber.localtest.me/api/public/site Local development tip
- Use a wildcard-friendly domain such as localtest.me or lvh.me so subdomains resolve to 127.0.0.1.
- Set PARTNA_PUBLIC_DOMAIN=localtest.me and APP_URL=http://api.localtest.me (or similar).

## 2) Authentication (Supabase JWT)

### What the frontend sends

All authenticated requests MUST include the Supabase access token:

- Header: Authorization: Bearer <SUPABASE_ACCESS_TOKEN>
- Also send: Accept: application/json
- For JSON bodies: Content-Type: application/json Tokens are verified by the supabase.jwt middleware using Supabase JWKS + issuer/audience settings.

### No login endpoint in Partna

- Partna does not manage passwords or sessions.
- Frontend signs in with Supabase Auth.
- Frontend calls Partna API with the returned access_token.

### Signup is site-first — bootstrap only refreshes existing users

**Pre-Account Sites (2026-07-18):** signup no longer creates an account directly. A brand-new user
builds a site first (from an Instagram handle or Google Business listing), then claims it with a
Supabase email-OTP JWT. `POST /api/bootstrap` survives only as the idempotent profile-refresh call
for users who already have a `core.users` row (JWT `sub` already bound via `auth_user_id`) — it no
longer has a create branch. See "Site-first signup (pre-account builds)" below for the new flow.

If a Supabase JWT has no matching `core.users` row, `POST /api/bootstrap` now returns **410** — see below.

### `POST /api/bootstrap`

### Auth: Required (Supabase JWT)

**Purpose:** Refresh the authenticated user's profile + site. No longer creates an account — see above.

**Request body:**

```json
{
"display_name": "Josh Barber",
"primary_email": "josh@example.com",
"phone": "+61400000000",
"first_name": "Josh",
"last_name": "Barber",
"country_code": "AU",
"timezone": "Australia/Sydney",
"handle": "joshbarber"
}
```

**Field notes:**
- `display_name` (required): Public-facing name (e.g., business name)
- `primary_email` (required): Contact email
- `phone` (required): Contact phone
- `first_name` (required): First name
- `last_name` (optional): Last name
- `country_code` (optional): 2-5 letter country code
- `timezone` (optional): IANA timezone
- `handle` (optional): Unique username/slug (if omitted, auto-generated from display_name)
- `invite`: still accepted on the request but no longer consumed — an existing user sending a stale `invite` param now just refreshes normally (200) instead of erroring.

**No row for this JWT sub (410):**
- The create branch is retired. A Supabase JWT whose `sub` has no `core.users` row (`auth_user_id` match) gets:
  - Status: `410`
  - Body: `{ "message": "Signup now starts from your site. Build it first, then claim it.", "code": "SIGNUP_MOVED" }`
- There is no invite-token bypass — invites retired with the create branch. The waitlist gate moved too: it's now a 403 on `POST /api/public/signup/build` (below), not on bootstrap.

**Response (200):**

```json
{
    "professional": {
        "id": "uuid",
        "handle": "josh-barber",
        "display_name": "Josh Barber",
        "primary_email": "josh@example.com",
        "phone": "+61400000000",
        "first_name": "Josh",
        "last_name": "Barber",
        "country_code": "AU",
        "timezone": "Australia/Sydney",
        "status": "active",
        "onboarding_step": 0
    },
    "site": {
        "id": "uuid",
        "user_id": "uuid",
        "subdomain": "josh-barber",
        "is_published": false
    }
}
```

**Common status codes:** 200, 401 (invalid JWT), 403 (disabled account), 410 (no existing row — see above), 422 (validation error, or `EMAIL_VERIFICATION_REQUIRED` when the JWT carries no verified email claim)

## 3) Site-first signup (pre-account builds)

New accounts are built from a public source before any auth exists. The flow: **build → poll → claim**.
All three endpoints are unauthenticated except claim, which requires a Supabase email-OTP JWT (no
`core.users` row needed yet — that's what claim creates).

**Concept:** `POST /api/public/signup/build` creates a *provisional* `core.users` row
(`status='unclaimed'`, `auth_user_id`/`primary_email` both `NULL`) plus an unpublished `site.sites`
row, then dispatches a background job that scrapes the source and populates the site. The frontend
polls `GET .../builds/{build_id}` until `build_state` is `ready`, then the visitor signs in with
Supabase (email OTP) and calls `POST /api/claim` to bind their new auth identity to the site
(first-come — whoever claims first wins).

### `POST /api/public/signup/build`

- Purpose: kick off (or re-serve) a pre-account site build from a typed source
- Auth: None
- Rate limit: `pre-account-build` (3/min + 10/hour per IP, keyed on `CF-Connecting-IP`)
- Middleware: `bot.token:pre-account-build` — currently a no-op (`BOT_PROTECTION_MODE=off` by default); wiring `enforce` later is a config change, not a route change, and needs no frontend work until then.

**Request body:**

```json
{
  "account_type": "partna",
  "source_type": "instagram",
  "source_ref": "@janedoe",
  "source_name": null
}
```

- `account_type` (required): `partna` | `business` — must be a source this platform allows for that type (currently `partna` ⇄ `instagram`, `business` ⇄ `google_business`; see `config('partna.pre_account.sources')`)
- `source_type` (required): `instagram` | `google_business`
- `source_ref` (required, string, max 300): Instagram handle, or Google Places `place_id`
- `source_name` (required when `source_type = google_business`, else ignored, max 120): the Places picker's business name — a `place_id` is opaque, so this seeds the subdomain/handle/display name

**Response — new build (202):** same shape as the poll response below, `build_state: "pending"`.

**Response — re-served existing live build (200):** one LIVE (unclaimed) build per source already
exists for this `source_type`/`source_ref` — the existing build is returned as-is, **including its
original `account_type`** (which may differ from what this request sent; reflect the returned value in
the UI, not the request's). Dedupe is checked before the pairing validation, so re-serving never
re-validates `account_type`/`source_type` pairing.

```json
{
  "build_id": "uuid",
  "build_state": "pending",
  "account_type": "partna"
}
```

`subdomain` is present from `pending` — it's guessable-by-design (spec §"Claim reference"), so there's no reason to withhold the identifier itself, and the frontend needs it pre-ready to call `POST /api/claim` now that claim no longer waits for ready. `site_url` stays ready-gated: that's the "go visit a real site" signal, which should wait for actual content (see poll endpoint). `failure_code` is omitted when null.

**Errors:**
- `422 SOURCE_PAIRING_INVALID` — `source_type` isn't configured for that `account_type` (or `source_type` isn't a known generator)
- `422 SOURCE_REF_INVALID` — the ref failed source-specific normalization (e.g. not a valid Instagram handle shape)
- `429 IP_BUILD_CAP` — this IP already has `partna.pre_account.max_unclaimed_per_ip` (default 3) outstanding unclaimed builds; claim one first
- `403 WAITLIST_ONLY` — new-account creation is currently waitlist-gated (`SIDEST_WAITLIST_ENABLED`/`PARTNA_WAITLIST_ENABLED`)

All four error bodies put the machine-readable code at the **top level**: `{ "message": "...", "code": "SOURCE_PAIRING_INVALID" }` (not nested under `errors`).

**Common status codes:** 202, 200, 403, 422, 429

### `GET /api/public/signup/builds/{build_id}`

- Purpose: poll a build's progress
- Auth: None
- Rate limit: `public-site`
- `build_id` is an opaque UUID (route-model-bound); persist it client-side so polling survives a refresh

**Response (200):**

```json
{
  "build_id": "uuid",
  "build_state": "ready",
  "account_type": "partna",
  "subdomain": "jane-doe",
  "site_url": "https://jane-doe.partna.au"
}
```

- `build_state`: `pending` | `building` | `ready` | `failed`
- `subdomain` appears from `build_state = "pending"` — guessable-by-design, and the frontend needs it pre-ready to call `POST /api/claim`. `site_url` appears **only** when `build_state = "ready"` — that's the "go visit a real site" signal, which waits for actual content
- `failure_code` appears only when `build_state = "failed"` (e.g. `source_not_found`, `scrape_failed`)
- No scraped content leaks through this endpoint — content is only visible via the normal public site payload once the build is ready

**Common status codes:** 200, 404 (unknown `build_id`)

### `POST /api/claim`

- Purpose: bind a Supabase-authenticated visitor to an unclaimed pre-account site (first-come)
- Auth: Required (Supabase JWT) — email read **only** from the verified JWT `email` claim, never the body (same OV-A hardening as bootstrap)
- Rate limit: `claim` (5/min per Supabase `sub`)

**Request body:**

```json
{ "subdomain": "jane-doe" }
```

**Response (200):** identical shape to `POST /api/bootstrap`'s success response — `{ "professional": {...}, "site": {...} }` — so the frontend can land straight in the dashboard. A retry with the same JWT after a successful claim replays the same 200 (idempotent), not a 409.

**Errors** (all put `code` at the top level, same convention as the build endpoint):
- `404 CLAIM_NOT_FOUND` — no site exists for that subdomain. **Also returned, byte-for-byte, when an
  outreach build exists but has no invited address and no valid `claim_token`** (the service still
  throws `CLAIM_NOT_INVITED` internally). Collapsed 2026-08-26 (#SEM-3): a distinct 409 let anyone
  sweep public handles and separate "nothing here" from "a staff-built site awaiting invite", which
  is a target list of the sites worth squatting. Do not reintroduce a distinct code or message here.
- `409 ALREADY_CLAIMED` — this site was claimed by someone else already
- `409 BUILD_FAILED` — no build row exists, or it's in `failed` state (`pending`/`building` claim fine — claim no longer waits for `ready`)
- `409 CLAIM_EMAIL_MISMATCH` — the build has a `contact_email` and the JWT's verified email doesn't match it
- `409 ACCOUNT_EXISTS` — this Supabase user already owns a different account
- `409 EMAIL_ALREADY_REGISTERED` — the verified email is already bound to a different auth user
- `422 EMAIL_VERIFICATION_REQUIRED` — the JWT carries no email claim, or carries one whose `email_verified` is false

**Common status codes:** 200, 401 (missing `supabase_uid`), 404, 409, 422, 429

#### `POST /api/internal/webhooks/manychat/builds`

Machine surface for ManyChat marketing builds. Static shared secret in the
`X-Partna-Webhook-Secret` header (`MANYCHAT_WEBHOOK_SECRET`); 503 when unset,
401 on mismatch. Exists because `POST /api/staff/builds` requires `require.aal2`,
which an automation platform cannot satisfy.

Body: `account_type`, `source_type`, `source_ref`, `idempotency_key` (all
required), optional `source_name` (**required** for `google_business`), optional
`expires_days` (1–90).

`202` on a new build, `200` when deduped. Response is **flat — no `data`
envelope**. `claim_url` is present only when a token was minted: for a new
build, or a retry carrying the same `idempotency_key`. Any other deduped call
returns no `claim_url`.

Send timing is ManyChat's choice: the claim URL is valid immediately (claiming
does not wait for `ready`), but polling `GET /api/public/signup/builds/{id}`
until `site_url` appears avoids DMing someone into a half-built page.

#### `POST /api/staff/builds/{build}/claim-token`

Staff + AAL2. Mints a fresh claim link, invalidating the previous one. For a
lost DM or a suspected leak.

#### Claim tokens

`POST /api/claim` accepts an optional `claim_token` in the **body** (the
frontend reads `?t=` from the claim page URL and forwards it, then strips it
with `history.replaceState`). A valid token satisfies the invite-gate in place
of `contact_email`.

The token is **narrow**: it proves invitation, not identity. A build carrying a
`contact_email` still requires that address — a token does not override
`CLAIM_EMAIL_MISMATCH`.

Single-use means **used, not opened**: the token is consumed only by a
successful claim, so an abandoned sign-in or a failed claim leaves the link
working for the rest of the build's 30-day life.

## 4) Roles and permissions

- Public (anon): no token, can only access public mini-site routes and health routes.
- User: valid Supabase JWT AND a core.users row where auth_user_id matches JWT sub.
- Staff: valid Supabase JWT AND a core.sidest_staff row where auth_user_id matches JWT sub.
- Staff admin: staff plus is_admin = true in core.sidest_staff.

### RLS behavior

Partna reads/writes Postgres through Laravel using the configured database user.

- Database table RLS does not gate Partna API calls if the DB user bypasses RLS (typical for server-side roles).
- Image uploads go through the Partna API (server-side), not through Supabase Storage. Supabase Storage is not used at all — all media is stored on Laravel Cloud Object Storage (Cloudflare R2).

## 5) Data Models

All ids are UUID strings. Timestamps are ISO 8601 strings when returned by the API.

### User (core.users)

> Model class: `App\Models\Core\User\User`. FK columns on other tables are named `user_id`.

| Name                    | Type     | Nullable | Example                                  | Constraints / Notes                                        |
|-------------------------|----------|----------|------------------------------------------|------------------------------------------------------------|
| id                      | uuid     | no       | **4db0c0b4-5e4a-4f8d-8d49-3e5b0b62d9a1** | Primary Key                                               |
| auth_user_id            | uuid     | no       | c1b2... (Supabase user id)               | JWT sub, set at bootstrap                                  |
| handle                  | string   | no       | joshbarber                               | unique (case-sensitive), 3-40 char, must start with letter |
| display_name            | string   | no       | Josh Barber                              | Max 80                                                     |
| bio                     | string   | yes      | Mobile Barber in Darwin                  | Max 1000. Owner-authored About Me. Business accounts also receive workplace.description via the identity mirror |
| account_type            | string   | no       | partna                                   | `partna` (default) or `business`                            |
| primary_email           | email    | no       | josh@example.com                         | Max 255                                                    |
| phone                   | string   | no       | +6140000000                              | Max 40                                                     |
| first_name              | string   | no       | Josh                                     | Max 80                                                     |
| last_name               | string   | yes      | Hunter                                   | Max 80                                                     |
| public_contact_number   | string   | yes      | +6140000000                              | Max 40, public-facing contact                              |
| public_contact_email    | string   | yes      | bookings@example.com                     | Max 255, public-facing contact                             |
| location_street_address | string   | yes      | 1 Smith Street                           | Max 255                                                    |
| location_city           | string   | yes      | Darwin                                   | Max 120                                                    |
| location_state          | string   | yes      | NT                                       | Max 120                                                    |
| location_postcode       | string   | yes      | 1800                                     | Max 20                                                     |
| location_country        | string   | yes      | Australia                                | Max 120                                                    |
| status                  | string   | no       | active                                   | active or suspended (staff-admin can update)               |
| onboarding_step         | integer  | yes      | 1                                        | 0+                                                         |
| created_at              | datetime | yes      | 2026-01-12T05:12:00Z                     |                                                            |
| updated_at              | datetime | yes      | 2026-01-12T05:12:00Z                     |                                                            |


### Site
| Name            | Type     | Nullable | Example                   | Constaints / Notes                                                                                                |
|-----------------|----------|----------|---------------------------|-------------------------------------------------------------------------------------------------------------------|
| id              | uuid     | no       | b8e7...                   | Primary Key                                                                                                       |
| user_id         | uuid     | no       | 4db0...                   | FK → core.users.id                                                                                                |
| subdomain       | string   | no       | joshbarber                | unique (case-sensitive), 3-63,lowercase letters/numbers/hyphen; no leading/trailing hyphen; reserved list blocked |
| is_published    | boolean  | no       | false                     | Dashboard-level flag — NOT a public-visibility control on every read path; see "Public visibility vs. is_published" below |
| settings        | object   | yes      | {...}                     | Freeform JSON object merged on PATCH                                                                              |
| created_at      | datetime | yes      | 2026-01...                |                                                                                                                   |
| updated_at      | datetime | yes      | 2026-01...                |                                                                                                                   |

### Public visibility vs. `is_published`

`is_published` is a dashboard-level flag, not a public-visibility control. Pre-account sites (post-signup,
pre-claim) render publicly at `<handle>.partna.au` and via `GET /api/public/profiles/{handle}` regardless
of `is_published` — deliberate, so a visitor can see their site before claiming it. Some public-facing
paths gate on it and some do not; there is no single rule, so check this table rather than assuming:

| Path | Gates on `is_published`? |
|---|---|
| `GET /api/public/profiles/{handle}` (`IndividualProfileController`) — the live `<handle>.partna.au` sitepage | No |
| `GET /api/public/profiles/{handle}/integrations` and `/platforms` (`PublicIntegrationController`) | No |
| `SyncSubdomainToKvJob` (Cloudflare KV write) — gates on `isActive() \|\| isUnclaimed()` instead | No |
| `PublicSiteResolver` (lead/enquiry/subscribe site lookup) | Yes |
| `PublicDocumentDownloadController` | Yes |
| `AnalyticsController` (pageview/click/dwell tracking) | Yes |
| `QrCodeController` | Yes |
| `GET /api/public/site` (`PublicSiteController`, subdomain-resolved) | Yes — **in the view, not in PHP** |

The last one gates in SQL: `site.public_site_payload`'s `WHERE` clause is
`s.is_published = true AND p.status IN ('active','unclaimed') AND p.deleted_at IS NULL`, so an
unpublished site yields no row and the controller 404s. Grepping `app/` for `is_published` will not
lead you to it — the one PHP hit in that class (`PublicSiteController::showByHeader`'s alias-redirect
canonical lookup) is a different sub-path. Check the view definitions in `supabase/migrations/` before
concluding a read path is ungated.

Note the three ungated profile sub-resources above carry third-party-sourced content — scraped menu
items, and platform connection payloads including Google Business data. They are public pre-claim by
the same design decision; see `SIGNUP-3` and the `LEGAL-2` privacy-policy item in
`docs/checklists/launch-readiness-checklist.md`.

Do not add a gate to the profiles route or the KV write to "fix" this — it is intended. See audit
finding `SIGNUP-3` for the decision record.

### SiteImage (core.site_images)

All images (gallery showcase and content/branding) live in the `site_images` table, organised into **pools**. The frontend assigns purpose by choosing from the variants map (`optimized` or `maximized`) for each image.

| Name       | Type     | Nullable | Example                                        | Constraints / Notes                                              |
|------------|----------|----------|-------------------------------------------------|------------------------------------------------------------------|
| id         | uuid     | no       | `f7a2...`                                       | Primary key                                                      |
| site_id    | uuid     | no       | `b8e7...`                                       | FK → sites.id                                                    |
| pool       | string   | no       | `gallery`                                       | `gallery` or `content`                                           |
| path       | string   | no       | `images/<proId>/<imageId>/original_abc123.jpg`  | Path to original file on the media disk                          |
| alt_text   | string   | yes      | `Fade haircut example`                          | Max 255                                                          |
| sort_order | integer  | no       | `0`                                             | Non-negative; used for gallery ordering                          |
| is_active  | boolean  | no       | `true`                                          | Soft visibility flag                                             |
| created_at | datetime | yes      | `2026-03-02T10:00:00Z`                          |                                                                  |
| updated_at | datetime | yes      | `2026-03-02T10:00:00Z`                          |                                                                  |
| deleted_at | datetime | yes      | `null`                                          | Soft delete                                                      |

**Pool limits** (configurable via env):
- `gallery`: max 5 images (env `PARTNA_GALLERY_IMAGE_MAX`)
- `content`: max 5 images (env `PARTNA_CONTENT_IMAGE_MAX`)

### ImageVariant (core.image_variants)

Each `SiteImage` gets a set of universal WebP variants generated server-side via a queue job. Content-hashed filenames enable aggressive CDN caching (`Cache-Control: public, max-age=31536000, immutable`).

| Name         | Type     | Nullable | Example                                         | Constraints / Notes                               |
|--------------|----------|----------|--------------------------------------------------|----------------------------------------------------|
| id           | uuid     | no       | `c3d4...`                                        | Primary key                                        |
| image_id     | uuid     | no       | `f7a2...`                                        | FK → site_images.id (cascade delete)               |
| variant      | string   | no       | `optimized`                                      | One of: optimized, maximized                        |
| disk         | string   | no       | `media`                                          | Storage disk name                                  |
| path         | string   | no       | `images/<proId>/<imgId>/optimized_abc123def456.webp` | Content-hashed filename                        |
| format       | string   | no       | `webp`                                           | Always WebP                                        |
| width        | integer  | no       | `3024`                                           | Actual output width in pixels                      |
| height       | integer  | no       | `4032`                                           | Actual output height in pixels                     |
| file_size    | integer  | no       | `3200`                                           | Bytes                                              |
| content_hash | string   | no       | `abc123def456ghij`                               | First 16 hex chars of SHA-256                      |
| created_at   | datetime | yes      | `2026-03-02T10:00:05Z`                           |                                                    |
| updated_at   | datetime | yes      | `2026-03-02T10:00:05Z`                           |                                                    |

**Variant profiles:**

| Variant   | Resolution policy   | Quality policy                                  | Typical use                             |
|-----------|---------------------|--------------------------------------------------|-----------------------------------------|
| optimized | Preserve original   | Adaptive quality, targets `PARTNA_IMAGE_TARGET_KB` (default 500KB) | Fast page loads / default display |
| maximized | Preserve original   | Highest quality (`PARTNA_IMAGE_MAXIMIZED_QUALITY`, default 100)    | Zoom/full-detail display          |

### Customer
| Name                      | Type     | Nullable | Example                | Constraints / Notes                                                         |
|---------------------------|----------|----------|------------------------|-----------------------------------------------------------------------------|
| id                        | uuid     | no       | `a3c1...`              | Primary key                                                                 |
| user_id                   | uuid     | yes      | `4db0...`              | Set by server on create                                                     |
| full_name                 | string   | no       | `Sam Smith`            | Max 120                                                                     |
| email                     | email    | yes      | `sam@example.com`      | Max 255                                                                     |
| phone                     | string   | yes      | `+61411111111`         | Max 40                                                                      |
| notes                     | string   | yes      | `Prefers Fridays`      | Max 5000                                                                    |
| source                    | string   | yes      | `manual`               | manual, site, or other; staff can set when creating/updating               |
| external_id               | string   | yes      | `manual:abc123`        | Max 255; external system reference                                         |
| marketing_opt_in_cached   | boolean  | no       | `true`                 | Cache of EmailSubscription status (defaults to true). Source of truth is EmailSubscription. Set to false if customer explicitly opts-out. |
| created_at                | datetime | yes      | `2026-01-12T05:12:00Z` |                                                                             |
| updated_at                | datetime | yes      | `2026-01-12T05:12:00Z` |                                                                             |
| deleted_at                | datetime | yes      | `2026-01-20T05:12:00Z` | Soft delete timestamp                                                       |

### Service
| Name            | Type     | Nullable | Example                | Constraints / Notes     |
|-----------------|----------|----------|------------------------|-------------------------|
| id              | uuid     | no       | `a3c1...`              | Primary key             |
| user_id         | uuid     | no       | `4db0...`              | FK → core.users.id      |
| category_id     | uuid     | yes      | `c5e2...`              | Optional service category |
| title           | string   | no       | `Standard Haircut`     | Max 255                 |
| description     | string   | yes      | `Professional cut`     | Max 2000                |
| price_cents     | integer  | no       | `3500`                 | Must be positive        |
| currency_code   | string   | yes      | `AUD`                  | ISO 4217 code           |
| duration_minutes| integer  | yes      | `30`                   | Must be positive        |
| is_active       | boolean  | no       | `true`                 | If false: hidden from public site |
| sort_order      | integer  | no       | `0`                    | Non-negative            |
| created_at      | datetime | yes      | `2026-01-12T05:12:00Z` |                         |
| updated_at      | datetime | yes      | `2026-01-12T05:12:00Z` |                         |
| deleted_at      | datetime | yes      | `2026-01-20T05:12:00Z` | Soft delete timestamp   |

### ServiceCategory
| Name            | Type     | Nullable | Example                | Constraints / Notes     |
|-----------------|----------|----------|------------------------|-------------------------|
| id              | uuid     | no       | `c5e2...`              | Primary key             |
| user_id         | uuid     | no       | `4db0...`              | FK → core.users.id      |
| title           | string   | no       | `Men's Cuts`           | Max 255                 |
| description     | string   | yes      | `All mens haircuts`    | Max 2000                |
| sort_order      | integer  | no       | `0`                    | Non-negative            |
| created_at      | datetime | yes      | `2026-01-12T05:12:00Z` |                         |
| updated_at      | datetime | yes      | `2026-01-12T05:12:00Z` |                         |
| deleted_at      | datetime | yes      | `2026-01-20T05:12:00Z` | Soft delete timestamp   |

### Link Block (core.blocks where block_group = links)
| Name            | Type    | Nullable | Example                       | Constraints / Notes                                                                       |
|-----------------|---------|----------|-------------------------------|-------------------------------------------------------------------------------------------|
| id              | uuid    | no       | `d5b0...`                     | Primary key                                                                               |
| user_id         | uuid    | no       | `4db0...`                     | FK → core.users.id                                                                        |
| site_id         | uuid    | no       | `b8e7...`                     | Owner site                                                                                |
| block_group     | string  | no       | `links`                       | Always links                                                                              |
| block_type      | string  | no       | `link`                        | Always link                                                                               |
| title           | string  | no       | `Book now`                    | Max 80                                                                                    |
| url             | string  | no       | `https://booking.example.com` | Max 2048; must be valid URL                                                               |
| icon_key        | string  | yes      | `calendar`                    | Must be one of config comet.link_block_icon_keys                                          |
| sort_order      | integer | no       | `0`                           | Non-negative                                                                              |
| is_active       | boolean | no       | `true`                        | If false: hidden from public site and click tracking is forbidden                         |
| settings        | object  | yes      | `{ "open_in_new_tab": true }` | Allowed keys only: open_in_new_tab, rel_nofollow, rel_sponsored, rel_ugc, highlight, note |

### PublicSiteData (view of returned GET /api/public/site)
| Name         | Type    | Nullable | Example                                                   | Constraints / Notes                                       |
|--------------|---------|----------|-----------------------------------------------------------|-----------------------------------------------------------|
| published    | boolean | no       | `true`                                                    | Derived from site is_published                            |
| site         | object  | no       | `{ id, subdomain, settings, gallery, content_images }` | Includes gallery + content image pools with variant URLs  |
| professional | object  | no       | `{ id, handle, display_name, bio, ... }` | Includes public-facing location fields                    |
| blocks       | array   | no       | `[ LinkBlock \| SectionBlock ]`                           | Only active blocks are returned                           |
| gallery      | array   | no       | `[ { id, pool, alt_text, sort_order, variants: {...} } ]` | Only active gallery-pool images; variants are URL maps    |
| services     | array   | no       | `[ { id, title, price_cents, ... } ]`                     | Only active services returned                             |

### Analytics Event Payloads
| Name                  | Type   | Nullable | Example                 | Constraints / Notes                                                                                                     |
|-----------------------|--------|----------|-------------------------|-------------------------------------------------------------------------------------------------------------------------|
| site_id               | uuid   | yes      | `b8e7...`               | Required unless subdomain is resolved from route or `X-Site-Subdomain` header                                          |
| session_id            | uuid   | yes      | `7f1e4d6b-...`          | Optional per-session ID                                                                                                 |
| visitor_id            | uuid   | yes      | `f2a1...`               | Optional stable visitor ID                                                                                              |
| referrer              | string | yes      | `https://instagram.com` | Max 2048; if missing, backend uses request `Referer` header                                                            |
| utm_source            | string | yes      | `instagram`             | Max 255                                                                                                                 |
| utm_medium            | string | yes      | `social`                | Max 255                                                                                                                 |
| utm_campaign          | string | yes      | `jan_promo`             | Max 255                                                                                                                 |
| block_id (click only) | uuid   | no       | `d5b0...`               | Must belong to the site, be active, and be trackable: `links/link` or `sections/{gallery,services,shop,booking,barbershop_info}` |


## 6) Conventions (headers, errors, pagination, rate limits)

### Standard headers

- Accept: application/json
- Content-Type: application/json (for JSON bodies)
- Authorization: Bearer <SUPABASE_ACCESS_TOKEN> (authenticated routes only)

### Idempotency keys (mutating endpoints)

Frontend can opt into safe-retry semantics on mutating endpoints by sending an `Idempotency-Key` header. When present, the backend caches the first response under `{user, route, key}` and replays it on duplicate keys for 24 hours — protecting against browser refresh, mobile double-tap, and network-retry double-submits.

**Header contract:**

- `Idempotency-Key: <UUID v4>` — strict UUID v4 only (rejects v1/v3/v5).
- Generate a **fresh key per logical user action** (typically per button click). Refresh on page navigation. Reusing a key across different actions silently replays the wrong response.
- Required scope: opt-in per request. Endpoints documented as "idempotency-aware" honour the header; others ignore it.

**Endpoints that honour `Idempotency-Key` today:**

- `POST /api/me/deletion/request`
- `POST /api/me/deletion/confirm`
- `POST /api/me/deletion/cancel`

**Server behaviour:**

| Scenario | Server response |
|---|---|
| First request with key K | Runs handler, caches response (24h), returns it |
| Duplicate request with key K | Replays cached response + adds `Idempotency-Replayed: true` header |
| Concurrent request with key K (first still in-flight) | `409 Conflict` + `Retry-After: 1` + `{"code": "idempotency_locked"}` — retry after a brief delay |
| Same key across different routes | Isolated — each route has its own scope |
| Same key across different users | Isolated — each user has their own scope |
| Header missing or empty | Bypassed — handler runs every call (no replay) |
| Header is not UUID v4 | `400 Bad Request` + `{"code": "idempotency_invalid_key"}` |
| Handler returned 5xx | NOT cached — retry can succeed against a recovered backend |
| Handler returned 2xx/3xx/4xx | Cached and replayable for 24h |
| Response body > 256 KB | NOT cached — handler re-runs on retry; no `Idempotency-Replayed` header |
| `StreamedResponse` / `BinaryFileResponse` (downloads, exports) | NOT cached — handler re-runs on retry |
| Method is GET/HEAD/OPTIONS | Bypassed (those are HTTP-idempotent already) |

**Frontend guidance:**

- Generate one UUID v4 per logical user action; hold it for the lifetime of that action only.
- On `409 idempotency_locked`, wait 1s and retry — the in-flight request will have populated the cache by then.
- The `Idempotency-Replayed: true` header lets you confirm a replay occurred (useful for debugging).
- If you fix a validation error and retry, **generate a NEW key** — reusing the key would replay the prior 422.

**Cache layer outage behaviour:** middleware fails open. If Redis is unreachable, the request proceeds without idempotency protection and a warning is logged server-side. The endpoint behaves as if the header was never sent.

**Replay semantics — what gets re-checked, and what doesn't:**

- The replay short-circuits BEFORE downstream middleware runs (rate limit, state checks like `EnforcePendingDeletionReadOnly`, model resolution). This is by design — a replay returns the prior outcome rather than re-executing the request.
- JWT verification ALWAYS runs first; an invalid or revoked token gets a 401 before the cache is consulted.
- App-version-scoped cache: a deploy that changes a response shape bumps the cache namespace so old-shape replays don't outlive the deploy.

### Browser CORS

- Frontend browser origins must be allowed by backend CORS config.
- If requests fail in browser but work in Postman/curl, add your frontend origin to `config/cors.php` allowed origins/patterns.

### Standard error format

**Most Partna errors use:**

```json
{
    "message": "Human readable message",
    "errors": {
        "field": [
            "Reason"
        ]
    }
}
```

}

Some framework-level errors (for example abort(404)) may return only:

```json
{ "message": "Not Found" }
```

### Middleware error envelope

Auth/context middleware (`EnsurePartnaStaff`, `EnsurePartnaAdmin`, `RequireAal2`, `VerifySupabaseJwt`, `RequireEmailVerified`, `VerifyBotToken`, `EnforcePendingDeletionReadOnly`, the Supabase webhook signature gate) respond with `{ "error": "<snake_case_code>", "message": "<human text>" }`. `message` is always present; `error` is a stable machine-readable code — safe to switch on, never changes meaning once shipped. Some responses add extra keys on top (e.g. `code` for legacy compatibility, `captcha` fields, `pending_deletion` + `deletes_at`) — treat those as additive, not a replacement for `error`.

Common status codes
- 200 OK: successful read or update
- 201 Created: successful create
- 204 No Content: no body (not commonly used yet)
- 400 Bad Request: bad payload or could not determine site from URL
- 401 Unauthorized: missing or invalid token
- 403 Forbidden: valid token but forbidden (missing professional profile, staff required, unpublished site, inactive block)
- 404 Not Found: resource not found, or site not found
- 409 Conflict: cannot delete due to constraints, or a conflicting state transition (e.g. build already invited)
- 422 Unprocessable Entity: validation errors or business rule (gallery limit)
- 429 Too Many Requests: rate limited
- 502 Bad Gateway: an upstream call the request depends on failed (e.g. Supabase auth-user deletion on staff force-delete) Example error responses Validation failure (422): { "message": "Validation failed", "errors": { "handle": ["The handle field is required."] } } Unauthorized (401): { "message": "Missing Bearer token" } Forbidden (403): { "message": "Professional profile missing for this user. Complete bootstrap first." } Not found (404): { "message": "Site not found." } Pagination
- Some list endpoints return { dataKey: [...], meta: {...} }.
- Both professional and staff list endpoints return { customers: [...], meta: {...} }.
- The `meta` key contains: current_page, per_page, total, last_page, next_page_url, prev_page_url.
- Query params: page, per_page (limits are enforced server-side).

### Rate limits (per IP)

- public-site: 60 requests per minute
- analytics: 120 requests per minute
- leads: 3 requests per minute per IP, plus 100 requests per minute per subdomain

## 7) Public Mini-Site API

All routes below are unauthenticated.

Frontend can connect in 2 modes:

1. Domain-scoped mini-site host  
`https://{subdomain}.{PARTNA_PUBLIC_DOMAIN}/api/public/...`
2. Header-based API host fallback (no subdomain DNS needed)  
`https://api.{PARTNA_PUBLIC_DOMAIN}/api/public/...` with header `X-Site-Subdomain: {subdomain}`

For analytics endpoints, provide either `site_id` in the JSON body OR `X-Site-Subdomain` header. Ingest additionally requires an `Origin` header whose host is `{subdomain}.{public_domain}` or the site's active custom domain — a request with no such header is rejected with 404 (Referer is no longer accepted, #SEC-3).

Frontend quick-start (header-based API host):

```ts
const API_BASE = "https://api.<PARTNA_PUBLIC_DOMAIN>/api/public";
const subdomain = "fadez";
const visitorId = localStorage.getItem("comet_visitor_id") ?? crypto.randomUUID();
localStorage.setItem("comet_visitor_id", visitorId);

const siteRes = await fetch(`${API_BASE}/site-by-slug`, {
  headers: { "X-Site-Subdomain": subdomain }
});

await fetch(`${API_BASE}/analytics/pageviews`, {
  method: "POST",
  headers: {
    "Content-Type": "application/json",
    "X-Site-Subdomain": subdomain
  },
  body: JSON.stringify({
    session_id: crypto.randomUUID(),
    visitor_id: visitorId
  })
});
```

### `GET /api/public/site`

- Purpose: fetch the published mini-site payload for rendering
- Auth: None
- Rate limit: public-site

**Response (200):**

```json
{
  "published": true,
  "site": { "id": "uuid", "subdomain": "fadez", "settings": {}, "gallery": [], "content_images": [], "gallery_videos": [], "content_videos": [] },
  "professional": { "id": "uuid", "handle": "fadez", "display_name": "Fadez Studio", "bio": null },
  "links": [],
  "sections": [],
  "blocks": [],
  "services": []
}
```

**Common status codes:** 200, 404 (site not found, unpublished, or owner not active/unclaimed), 429

An unpublished site returns **404, not 403** — `site.public_site_payload` simply yields no row
(see "Public visibility vs. `is_published`"), and public endpoints answer 404 rather than 403 so a
caller cannot enumerate which subdomains exist.

**Notes:**
- `blocks` is a combined, sort-ordered array of both `links` and `sections` and includes `block_group` on each item.
- `site.gallery` and `site.content_images` are image-only arrays. `site.gallery_videos` and `site.content_videos` are video-only arrays. Each video item includes `{ id, sort_order, processing_state, duration_ms, poster, variants: { optimized, maximized }, streams: { adaptive, optimized, maximized } }`. Videos with `processing_state != ready` are excluded automatically.

### `POST /api/public/analytics/pageviews`

- Purpose: record a page view
- Auth: None
- Rate limit: analytics

**Request body:**

```json
{
  "site_id": "optional-uuid",
  "session_id": "optional-uuid",
  "visitor_id": "optional-uuid",
  "referrer": "optional string",
  "utm_source": "optional string",
  "utm_medium": "optional string",
  "utm_campaign": "optional string"
}
```

**Response (201):**

```json
{
  "message": "Pageview recorded",
  "visit_id": "uuid"
}
```

**Notes:** `occurred_at` is generated server-side.

**Common status codes:** 201, 403, 404, 422, 429

### `POST /api/public/analytics/clicks`

- Purpose: record a link click or supported section interaction
- Auth: None
- Rate limit: analytics

**Request body:**

```json
{
  "block_id": "required-uuid",
  "site_id": "optional-uuid",
  "session_id": "optional-uuid",
  "visitor_id": "optional-uuid",
  "referrer": "optional string",
  "utm_source": "optional string",
  "utm_medium": "optional string",
  "utm_campaign": "optional string"
}
```

**Response (201):**

```json
{
  "message": "Click recorded",
  "click_id": "uuid"
}
```

`message` is `"Section interaction recorded"` when the clicked block is a supported section block.

**Common status codes:** 201, 403 (unpublished or inactive block), 404 (site or block), 422 (not trackable/validation), 429

### `POST /api/public/customers`

- Purpose: submit a customer lead (name + contact details)
- Auth: None
- Rate limit: leads
- Site resolution order: `X-Site-Subdomain` header -> `subdomain`/`slug` query -> `subdomain`/`slug` body -> host subdomain
- Request body (example): `{ "full_name": "Sam Smith", "email": "sam@example.com", "phone": "+61411111111", "notes": "optional", "marketing_opt_in": true, "form_started_at_ms": 1700000000000 }`
- Response (201): `{ "ok": true, "customer_id": "uuid" }`
- Common status codes: 201, 400 (cannot determine site), 404, 403, 422, 429

### `POST /api/public/subscribe`

- Purpose: subscribe an email address to a marketing list for the professional
- Auth: None
- Rate limit: public-site
- Site resolution order: `X-Site-Subdomain` header -> `subdomain`/`slug` query -> `subdomain`/`slug` body -> host subdomain
- Request body: `{ "email": "sam@example.com", "full_name": "Sam Smith", "list_key": "marketing" }`
- Response (200): `{ "ok": true, "subscribed": true, "list_key": "marketing" }`
- Common status codes: 200, 404, 400 (cannot determine site), 422, 429

### `GET /api/public/marketing-preference`

- Purpose: check current marketing subscription status for an email
- Auth: None
- Rate limit: public-site
- Query params:
  - `email` (required): customer email address
  - `subdomain` (required): mini-site subdomain to identify professional

**Response (200):**

```json
{
    "email": "sam@example.com",
    "opted_in": true,
    "status": "subscribed"
}
```

**Status values:** `subscribed`, `unsubscribed`, `bounced`, `complained`, `unknown`

**Common status codes:** 200, 404 (site not found), 400 (missing params), 429

### `POST /api/public/unsubscribe/{token}`

- Purpose: unsubscribe from marketing emails using token from email link
- Auth: None
- Rate limit: public-site
- Path params: `token` (required): unsubscribe token from email

**Response (200):**

```json
{
    "message": "Successfully unsubscribed from marketing emails",
    "email": "sam@example.com"
}
```

**Common status codes:** 200, 404 (token not found), 400 (invalid token), 429

### `POST /api/public/resubscribe/{token}`

- Purpose: resubscribe to marketing emails using the same token
- Auth: None
- Rate limit: public-site
- Path params: `token` (required): unsubscribe token from email

**Response (200):**

```json
{
    "message": "Successfully resubscribed to marketing emails",
    "email": "sam@example.com"
}
```

**Common status codes:** 200, 404 (token not found), 400 (invalid token), 429

#### Header-Based Slug Routing

For frontends that cannot use subdomain DNS routing, the following endpoints accept the subdomain via the `X-Site-Subdomain` header and are accessed on the API host:
`https://api.{PARTNA_PUBLIC_DOMAIN}/api/public/...`

#### `GET /api/public/site-by-slug`

- Purpose: fetch the published mini-site payload using header-based subdomain resolution
- Auth: None
- Rate limit: public-site
- Headers: `X-Site-Subdomain` (required): the site subdomain slug

**Response (200):** Same as `GET /api/public/site`

**Common status codes:** 200, 400 (missing header), 404 (site not found), 403 (site not published)

#### `POST /api/public/analytics/pageviews`
#### `POST /api/public/analytics/clicks`

- Purpose: header-based variants of the analytics endpoints (record page views and link clicks)
- Auth: None
- Rate limit: analytics
- Headers: `X-Site-Subdomain` (required if `site_id` is not in the request body): the site subdomain slug

**Behavior:** Identical to the domain-scoped analytics endpoints above. You must provide either `site_id` in the body OR the `X-Site-Subdomain` header — otherwise validation returns 422.

**Request body:** Same as domain-scoped versions above.

**Response:** Same as corresponding domain-scoped endpoints.

**Common status codes:** 201, 404, 403, 422, 429

### Content pools on `GET /api/public/profiles/{handle}`

`profile.pools` carries one entry per content pool (`watch`, `listen`, `media`, `events`,
`services`, `shop`, `reviews`), each `{items, latestItemId}`. A pool with an empty
selection is **absent**, not empty. Per-slice detail lives in `docs/wire-changes/`; only
the cross-cutting item keys are listed here.

**Every pool item carries every key regardless of kind** — the wire shape does not change
with kind, so a facet that does not apply is `null` (or `[]`), never missing. The exact
key set is `PoolResolver::ITEM_KEYS`, pinned by `tests/Feature/Content/PoolWireShapeTest.php`.

**Item order follows `settings.pool_order[pool]` (2026-08-23).** `newest` (the default)
orders the WHOLE selection — pins and auto together — by `publishedAt` desc with dated
items before undated (a link-pool item counts its `firstSeenAt` as its date: it was
hand-added); `smart` orders by `popularityRank` asc (ranked before unranked, then the
newest order); `manual` keeps pins by `sort_key` then the pool's rule order. `events` is
always soonest-first and `reviews` never ranks. `popularityRank` is emitted for EVERY
item — its kind's family in `analytics.content_popularity_scores`, **every family keyed
by the item id** (smart ordering v2, 2026-08-23; the handle / url keys are gone).

**Category pools (`menus`, `services`) display grouped by category (D4, 2026-08-23).**
Each `collections[<id>]` entry carries `popularityRank` — the category's own rank in the
`menu_category` / `service_category` family, whose score is the **SUM** of its served
members' item scores (breadth beats one hit); `null` on a storefront and when unranked.
In `smart` the categories order by that rank (unranked after, by `position`); in `newest`
through their newest member; `manual` keeps the Categories sheet's drag. `position` is
rewritten to the resolved order. The flat `items` list is emitted in category order
(home = the item's first provider-null collection; uncategorised items last), and a
`settings.pool_locks` position on these two pools is the index **within** the item's
category. On every other pool `collections[*].popularityRank` is `null`.

**Media never produces an action (D1, 2026-08-23):** `actions.entries` contains no
`item:` from the media pool and no gallery category; the gallery keeps its own pool
smart order (item views + a share of the gallery page's dwell + freshness).

- **`review`** (2026-08-13) — `{rating, text, authorName, authorPhotoUrl, authorUri,
  reviewedAt}` on items of kind `review`, `null` on every other kind. `rating` is always
  present on a review; the three author fields are `null` for an unclaimed (pre-claim)
  site, because reviewer identity is stripped when the record is stored. Read from
  `content.f_review`, which is the only copy of reviewer identity the redaction scope,
  `content:prune-orphaned-review-pii` and the DSAR omission all govern.
- **`headline` is `null` on review items** by contract, and so is `description`. Render the
  card from the `review` block; there is deliberately no title to fall back to.

**`pools.reviews.stats`** — `{ratingAvg, ratingCount, summaryText}`, the connected Google
place's own aggregates, read from `content.source_stats`. Absent when null (the same
contract `pools.shop.collections` keeps), and present on `reviews` only.

**Retired from `GET /api/public/profiles/{handle}/integrations` (2026-08-13):** the
`google-business` platform payload no longer publishes `reviews`, `reviewSummary`,
`rating` or `reviewCount`. They are served by `pools.reviews` and `pools.reviews.stats`
above. Every other `google-business` key on that lane is unchanged, and the authenticated
dashboard resource still reads all four.

**Curation is exclusion-only on `reviews`.** Pinning, reordering and hand-authoring a
`review` item are refused with **422** on every write path
(`POST /api/content/pools/reviews/selection/{item}`,
`PUT /api/content/pools/reviews/order`, `PUT /api/site/sections/{id}/items/{item}` with
`state: pinned`, `POST /api/content/pools/reviews/items`). Excluding and deselecting
return 200. Every other pool is unaffected.

---

## 8) User Dashboard API

All routes below require: Authorization header AND a user profile (current.user middleware).

### `GET /api/me`

- Purpose: bootstrap dashboard UI with current professional, site, blocks, services, and customer count
- Auth: Required
- Response (200): `{ "uid": "supabase-user-uuid", "professional": { ... }, "site": { ... }, "blocks": [], "services": [], "customers_count": 0 }`
- Common status codes: 200, 401, 403

### Account Deletion

Self-service lifecycle: email-confirmed grace period → 30-day read-only window → hard delete.

#### `POST /api/me/deletion/request`

Initiates deletion. Sends confirmation email (expires 24h). Rate-limited 3/hour.

- `200` — confirmation email sent
- `409` — already in grace period (body: `deletes_at`)
- `403` — account is suspended/disabled
- `422` — unsettled obligations (body: `reasons: [...]`)
- `429` — rate limited
- `503` — mail send failed (safe to retry)

#### `POST /api/me/deletion/confirm`

Body: `{ "token": "<from email>" }`. Status → `pending_deletion`, integration credentials deleted.

- `200` — body: `deletes_at` ISO timestamp
- `410` — token expired (>24h since request)
- `404` — token invalid or no deletion request

#### `POST /api/me/deletion/cancel`

Restores previous status. Exempt from read-only middleware.

- `200` — account reactivated
- `409` — no pending deletion

#### Read-only enforcement

During grace period, all non-GET/HEAD/OPTIONS requests return:

```json
HTTP 423 Locked
{
  "message": "Account is pending deletion.",
  "pending_deletion": true,
  "deletes_at": "2026-05-19T03:20:00Z"
}
```

### `PATCH /api/me`

- Purpose: update professional profile fields
- Request body (all fields optional; if provided they are validated): `{ "display_name": "Josh Barber", "bio": "Mobile barber", "public_contact_email": "bookings@example.com" }`
- Response (200): `{ "professional": { ... } }`
- Common status codes: 200, 401, 403, 422
- Images are managed via `POST /api/uploads` (pool=gallery or pool=content). No image fields are accepted on this endpoint.

### `GET /api/site`

- Purpose: fetch site record for the logged-in professional
- Response (200): `{ "site": { ... } }`

### `PATCH /api/site`

- Purpose: update site settings, subdomain, and design kit
- Request body: `{ "subdomain": "joshbarber", "design_kit": { "theme_mode": "dust", "color_accent": "#000000" }, "settings": { "primary_color": "#000000" } }`
- Response (200): `{ "site": { ... } }`
- Common status codes: 200, 401, 403, 422
- Banners are managed via `POST /api/uploads` (pool=content) and the frontend picks from `optimized` / `maximized`. No banner fields are accepted on this endpoint.
- Ordering settings (unified actions, 2026-08-23): `settings.smart_page_order` (bool, default true), `settings.manual_page_order` (list of taxonomy page-ids, distinct, ≤16), `settings.actions` = `{ "mode": "newest|smart|manual", "slots": [ { "position": 0..9, "id": "<kind>:<ref>" } ] }` (≤10 slots, positions and ids distinct; in `smart`/`newest` the slots are LOCKS and may be sparse, in `manual` they ARE the list and must be contiguous from 0; id grammar `^(page|platform|item|category):[A-Za-z0-9_.:/-]{1,160}$`, existence not checked at write time), `settings.pool_order` = `{ "<pool>": "newest|smart|manual" }` over `watch, listen, media, services, shop, custom_links, menus` (`events`/`reviews` 422; absent = newest), `settings.pool_locks` = `{ "<pool>": [ { "position": int, "id": "<item uuid>" } ] }` (same pool keys, ≤50 per pool, ids distinct, positions distinct except on `menus`/`services` where a position is the index within the item's category; applied in `newest`/`smart` only — a locked item holds its position while the mode fills the rest, unknown ids skipped). `actions`, `pool_order` and `pool_locks` REPLACE atomically on write. The retired keys `smart_actions` / `manual_actions` / `manual_order_pools` are stripped silently. The public payload's `pageOrder` / `actions` / pool item order apply these server-side.

### `GET /api/site/actions`

- Purpose: the dashboard `/actions` page — the owner's mode, stored slots, the LIVE resolution, and every candidate for the swap picker
- Auth: Required
- Response (200): `{ "mode": "newest|smart|manual", "slots": [ { "position": int, "id": string, "unavailable": bool } ], "entries": [ActionEntry], "candidates": [ActionCandidate] }` (no data envelope)
- `ActionEntry` = `{ "position": int, "id": "<kind>:<ref>", "kind": "page|platform|item|category", "label": string, "url": string, "thumb": string|null, "locked": bool, "ref": { "pool": string, "itemId": string } | null }` — `entries` is EXACTLY what the public payload resolves for the same state (same candidates, same scores, same resolver), so preview and lander cannot drift.
- `ActionCandidate` = `ActionEntry` fields minus position/locked, plus `connectedAt` (ISO|null), `score` (stored smart score|null), `scoreShare` (score ÷ site max, 0..1|null), `meta` (`pageId` | `platformKey` | `pool` (+ `collectionId`, `itemIds` for a category) | `fallback` | `undated`). In smart order.
- `slots[].unavailable` = the stored id is no longer a candidate (item removed, platform disconnected, page lost presence) — skipped at resolution, kept in settings so it re-applies if the candidate returns.
- Candidates (spec §2): `page:<id>` for the six destination-of-intent pages (`services, reservations, menu, shop, events, contact`) when present; `platform:<key>` for every connection whose platform is a public destination (socials, music/video/podcast profiles, online ordering) — a SOURCE platform (booking, store, ticketing) folds into its page and only appears as `platform:<key>` while that page is absent; `item:<uuid>` for every item currently served on the sitepage; `category:<collectionId>` for a menu/services category block. `reviews` never ranks.
- Writes go through `PATCH /api/site` `settings.actions`. This read does a full pool hydration per call — owner-only, uncached.
- The public profile payload (`GET /api/public/profiles/{handle}`) carries top-level `actions` = `{ "mode", "entries": [ActionEntry] }` (≤10, always present, `entries: []` when nothing resolves); every `ref` resolves against the served `pools`. `pageOrder` reflects `manual_page_order` when `smart_page_order` is false, else the `page:*` action scores. The legacy `rankedActions` + `ordering` keys ride beside `actions` for one deploy and are then removed.
- Scoring: `analytics:compute-popularity` (15-min) writes `content_type='action'` rows keyed by action id — `0.45·demandRate + 0.30·reach + 0.25·freshness + prior` (see `App\Services\Analytics\ActionScorer`; a category's reach is the SUM of its members' item scores). Beacons `POST /api/public/analytics/action-seen|action-tap` take the `<kind>:<ref>` id; a tap on an `item:<id>` also counts as one click in that item's family (D7).
- Item families (pool smart order, 2026-08-23): one formula with per-family weights from `config/partna.php` `pools.smart` — `Σ_days (w_click·clicks + w_view·views + w_dwell·dwell_s)·2^(−age/90) + w_fresh·2^(−ageSince(publishedAt ?? firstSeenAt)/half_life)`; every family keyed by `content.items.id`; events never score; `item_type` beacons accept `service_category` alongside `menu_category`. The sweep scopes in sites with traffic OR changed content in the last hour (D6).

### `PATCH /api/site/visibility`

- Purpose: publish or unpublish the mini-site
- Request body: `{ "published": true }`
- Response (200): `{ "published": true }`

### Services

- GET /api/services
- POST /api/services
- GET /api/services/{service}
- PATCH /api/services/{service}
- DELETE /api/services/{service}
- POST /api/services/reorder
- POST /api/services/{service}/restore (requires trashed binding)

**Store/Update body:**

```json
{
"title": "Standard cut",
"category": "Mens",
"description": "Optional",
"price_cents": 3500,
"currency_code": "AUD",
"duration_minutes": 30,
"is_active": true
}
```

**Reorder body:**

```json
{ "ids": ["uuid1","uuid2"] }
```

### Service Categories

- GET /api/service-categories
- POST /api/service-categories
- GET /api/service-categories/{category}
- PATCH /api/service-categories/{category}
- DELETE /api/service-categories/{category}
- POST /api/service-categories/reorder
- POST /api/service-categories/{category}/restore (requires trashed binding)

**Store/Update body:**

```json
{
"title": "Men's Cuts",
"description": "Optional",
"sort_order": 0
}
```

**Reorder body:**

```json
{ "ids": ["uuid1","uuid2"] }
```

### Service Layout Reorder

- POST /api/services/reorder-layout

**Body:**

```json
{
  "layout": [
    {
      "type": "category",
      "id": "category-uuid",
      "services": ["service-uuid1", "service-uuid2"]
    },
    {
      "type": "category",
      "id": "category-uuid-2",
      "services": ["service-uuid3"]
    }
  ]
}
```

### `GET /api/analytics`

- Purpose: analytics summary for the logged-in professional
- Query: days=30 or from=YYYY-MM-DD&to=YYYY-MM-DD Response (200): { "range": { "from": "2026-01-01", "to": "2026-01-30" }, "totals": { "visits": 0, "unique_visitors": 0, "clicks": 0, "unique_clickers": 0, "ctr_percent": 0 }, "charts": { "visits_by_day": [], "clicks_by_day": [] }, "top_links": [] }

#### Links (Link blocks)

> **Full conceptual guide:** [docs/social-links.md](./social-links.md)
>
> Covers the platform registry, normalization rules, frontend integration expectations, and security considerations.

- `GET /api/links`
- `POST /api/links`
- `PATCH /api/links/{block}`
- `DELETE /api/links/{block}`
- `POST /api/links/reorder`
- `GET /api/public/config/social-platforms` (public, no auth — returns the list of supported social platforms with display name, icon key, and placeholder)

**Two write modes** on POST/PATCH (the presence of `platform` is the discriminator):

**Social mode** — accepts either a handle or a URL; backend normalizes either to a canonical https URL and tags `settings.platform`/`settings.handle`:
```json
{ "platform": "instagram", "handle": "joshhunter" }
```
or
```json
{ "platform": "instagram", "url": "https://instagram.com/joshhunter" }
```

**Custom mode** — legacy contract, requires `title` and `url`:
```json
{ "title": "Book now", "url": "https://booking.example.com", "icon_key": "calendar", "is_active": true, "settings": { "open_in_new_tab": true } }
```

Custom-mode URLs are restricted to `http`/`https` schemes only — `javascript:`, `data:`, `file:`, `ftp:` are rejected with 422.

Supported social platform keys: `instagram`, `facebook`, `linkedin`, `youtube`, `tiktok`, `x`, `spotify`, `soundcloud`. See [docs/social-links.md](./social-links.md) for handle formats, host allowlists, and the full conceptual model.

Common status codes: 200, 201, 401, 403, 404, 422

#### Sections (Section blocks)

Allowed section block types are defined in config: `gallery`, `services`, `shop`, `booking`, `barbershop_info`

- GET /api/sections
- PUT /api/sections/{blockType}
- POST /api/sections/reorder
- DELETE /api/sections/{blockType} Upsert body: { "is_active": true, "settings": { "text": "About me" } } Note: settings are merged (PATCH-style) when provided. Bio section text also updates professional.bio when sent.

### Customers

- GET /api/customers?search=...&marketing_opt_in=true/false&page=1&per_page=25 (filters by marketing opt-in status using cache)
- GET /api/customers/{customer}
- POST /api/customers
- PATCH /api/customers/{customer}
- DELETE /api/customers/{customer}
- POST /api/customers/{customer}/restore

**Store/Update body:**

```json
{
    "full_name": "Sam Smith",
    "email": "sam@example.com",
    "phone": "+61411111111",
    "notes": "Optional",
    "source": "manual",
    "external_id": "square:cus_123",
    "marketing_opt_in_cached": true
}
```

**Query params:**
- `search`: search in full_name, email, phone
- `marketing_opt_in`: filter by `true`, `false`, or omit (applies to marketing_opt_in_cached field)
- `page`: pagination (default 1)
- `per_page`: items per page (default 25, max 100)

**Note:** `marketing_opt_in_cached` is a UX cache of the source-of-truth `EmailSubscription.status`. Defaults to `true` for new customers. When professionals update this field:
- Setting to `true` enables marketing emails
- Setting to `false` disables marketing emails
- Cache auto-syncs when EmailSubscription status changes

### Notifications

- GET /api/me/notifications
- POST /api/me/notifications/{notification}/read
- POST /api/me/notifications/{notification}/dismiss Email subscribers (marketing list)
- GET /api/email-subscribers?list_key=marketing&status=subscribed&search=...
- GET /api/email-subscribers/export?list_key=marketing&status=subscribed

## 9) Staff API

Staff routes are for internal staff tooling. They require a staff JWT (user must exist in core.sidest_staff).

### Staff (non-admin) routes

- GET /api/staff/me
- POST /api/staff/builds — marketing-pipeline build trigger; see "Pre-account builds (marketing pipeline)" below
- POST /api/staff/builds/batch — CSV batch build upload; see "Pre-account builds (marketing pipeline)" below
- GET /api/staff/sites/{subdomain}
- GET /api/staff/professionals?q=...&status=...&per_page=...&page=... — `status=unclaimed` filters to provisional (never-claimed) pre-account users
- GET /api/staff/professionals/{professional} — for an unclaimed user, the response includes a `pre_account_build` block (absent entirely for normal users) — see below
- DELETE /api/staff/professionals/{professional} (soft delete)
- POST /api/staff/professionals/{professional}/restore
- GET /api/staff/professionals/{professional}/customers
- GET /api/staff/professionals/{professional}/customers/{customer}
- POST /api/staff/professionals/{professional}/customers/{customer}/restore
- GET /api/staff/professionals/{professional}/services
- GET /api/staff/professionals/{professional}/services/{service}
- POST /api/staff/professionals/{professional}/services/{service}/restore
- GET /api/staff/professionals/{professional}/service-categories
- GET /api/staff/professionals/{professional}/service-categories/{category}
- POST /api/staff/professionals/{professional}/service-categories/{category}/restore
- GET /api/staff/professionals/{professional}/site
- GET /api/staff/professionals/{professional}/analytics
- GET /api/staff/professionals/{professional}/links
- GET /api/staff/professionals/{professional}/sections
- GET /api/staff/feedback?type=error|good|bad_ui|idea&area=<string>&from=YYYY-MM-DD&to=YYYY-MM-DD&per_page=&page= (OV-D — triage list across ALL users, not scoped to one professional; unrecognised `type` values are ignored rather than erroring; invalid `from`/`to` → 422; response envelope: `{ "feedback": [StaffFeedbackRow], "meta": {...} }`, `StaffFeedbackRow` adds `user: {id,handle,display_name,email}|null`, `reply_email`, `request_id`, `tags`, `internal_notes`, `ip_hash`, `updated_at` on top of the owner-facing shape) Staff-admin routes (requires core.sidest_staff.is_admin = true)
- PATCH /api/staff/professionals/{professional}/status
- PATCH /api/staff/professionals/{professional}
- DELETE /api/staff/professionals/{professional}/force (full immediate purge — see below)
- POST /api/staff/professionals/{professional}/release-claim (non-destructive claim release — see below)
- PATCH /api/staff/professionals/{professional}/customers/{customer}
- DELETE /api/staff/professionals/{professional}/customers/{customer} (soft delete)
- DELETE /api/staff/professionals/{professional}/customers/{customer}/hard (hard delete)
- POST /api/staff/professionals/{professional}/services (create)
- PATCH /api/staff/professionals/{professional}/services/{service}
- DELETE /api/staff/professionals/{professional}/services/{service} (soft delete)
- DELETE /api/staff/professionals/{professional}/services/{service}/hard (hard delete)
- POST /api/staff/professionals/{professional}/services/reorder
- POST /api/staff/professionals/{professional}/service-categories (create)
- PATCH /api/staff/professionals/{professional}/service-categories/{category}
- DELETE /api/staff/professionals/{professional}/service-categories/{category} (soft delete)
- DELETE /api/staff/professionals/{professional}/service-categories/{category}/hard (hard delete)
- POST /api/staff/professionals/{professional}/service-categories/reorder
- POST /api/staff/professionals/{professional}/services/reorder-layout
- PATCH /api/staff/professionals/{professional}/site
- POST /api/staff/professionals/{professional}/links
- PATCH /api/staff/professionals/{professional}/links/{block}
- DELETE /api/staff/professionals/{professional}/links/{block}
- POST /api/staff/professionals/{professional}/links/reorder
- PUT /api/staff/professionals/{professional}/sections/{blockType}
- POST /api/staff/professionals/{professional}/sections/reorder
- DELETE /api/staff/professionals/{professional}/sections/{blockType}
- POST /api/staff/notifications

#### `DELETE /api/staff/professionals/{professional}/force`

Admin-only. Runs the **full immediate purge** (`AccountDeletionService::adminPurgeNow`) — pseudonymises PII,
**permanently deletes the professional's Supabase auth user (frees the email for reuse)**, hard-deletes the
row, and retires the KV routing entry. Skips the 30-day self-service grace period entirely; unlike
`POST /api/me/deletion/request`, no scheduled-deletion email is ever queued. Also requires a fresh AAL2
verification (same `partna.mfa.fresh_window_seconds` window as `updateStatus`/`bulk-status`).

**Request body:** `{ "reason": "10–500 chars", "override_obligations"?: bool }`. `reason` is required — record
the support ticket reference and justification; it lands in `audit.user_deletion_audit`. The frontend
force-delete button must collect a reason before calling this endpoint.

- `200` — body: `{ "message": "...", "permanently_deleted": true, "email_freed": true }`
- `422` — validation (missing/short/long `reason`), or unsettled obligations (body: `reasons: [...]`,
  retry with `override_obligations: true`)
- `401` — `mfa_fresh_required` (stale or missing AAL2 verification)
- `403` — non-admin staff (`staffForceDelete` policy is admin-only even within the staff-admin route group)
- `502` — the Supabase auth-user deletion failed; the account is left in `pending_deletion` and is
  retried automatically by the daily purge command (safe to re-run `/force` too)

#### `POST /api/staff/professionals/{professional}/release-claim`

Admin-only. The **non-destructive** counterpart to `/force`: unbinds the claimer and returns the row to
`status='unclaimed'` so the rightful owner can claim it through `POST /api/claim`. **The built site
survives** — no rebuild, no fresh scrape. Use this when the wrong person claimed a pre-account site; use
`/force` when they also used the account. Requires a fresh AAL2 verification, same window as `/force`.

What it undoes (the exact inverse of `ClaimSiteService::claim()`): nulls `auth_user_id` and
`primary_email`, sets `status='unclaimed'`, nulls `pre_account_builds.claimed_at` (returning the build to
`scopeLive()`), deletes the welcome notification, and — for **self-serve** builds only — unpublishes the
site. Outreach builds stay published because that is their provisioned state. Post-commit it re-syncs KV
(the permanent routing entry reverts to the unclaimed expiry-TTL pointer) and purges the edge.

⚠️ **The site returns to OPEN first-come** (owner ruling 2026-08-25) — there is deliberately no email lock,
so nothing stops the same person re-claiming it. Release while you are in contact with the rightful owner,
not as a fire-and-forget action. See `#SEC-3` in `audits/sweeps/2026-08-24-claim-gate-security/`.

⚠️ **A release always proceeds**, even when the previous claimer added content. `warnings` reports what the
incoming owner would inherit; a **non-empty `warnings` means `/force` was the right tool**, because
releasing hands a stranger's uploads — and any customer/enquiry records — to a different person.

**Request body:** none.

- `200` — body: `{ "released": true, "warnings": { "customers"?: int, "enquiries"?: int,
  "integration_connections"?: int, "media"?: int }, "message": "..." }`. `warnings` is `[]` when clean —
  only non-zero categories appear.
- `409` — `NOT_CLAIMED` (the row is not currently claimed) or `NOT_PRE_ACCOUNT` (the user has no
  `pre_account_builds` row, so there is no claim to release)
- `401` — `mfa_fresh_required`
- `403` — non-admin staff (`staffReleaseClaim` is admin-only even within the staff-admin route group)

### Pre-account builds (marketing pipeline)

`POST /api/staff/builds` — the ManyChat/marketing surface for staff-triggered pre-account builds. Same
staff stack as every other staff route (`supabase.jwt`, `require.email_verified`, `staff`,
`require.aal2`, `throttle:staff`, `staff.audit`). Any staff role may call it
(`PreAccountBuildPolicy::staffCreate`) — it isn't admin-gated.

**Request body:** same as `POST /api/public/signup/build` (§3) plus two staff-only knobs:

```json
{
  "account_type": "business",
  "source_type": "google_business",
  "source_ref": "<place_id>",
  "source_name": "Jane's Nails",
  "publish": true,
  "expires_days": 30
}
```

- `publish` (optional boolean, default `true`) — staff builds go **live immediately** once the scrape
  succeeds (the site IS the pitch); the public signup build endpoint never publishes pre-claim.
- `expires_days` (optional integer, 1-365) — overrides `partna.pre_account.expiry_days` (default 30) for this build.

**Response:** identical shape to the public build endpoint — 202 (new) / 200 (re-served existing live
build). Unlike the public endpoint, there's no waitlist/IP-cap path: `requestBuild()` skips the
per-IP cap entirely when called with a staff actor, so every thrown error here is a bad
source/pairing and the controller always returns a flat `422` (not the public endpoint's
429-for-cap/422-for-pairing split).

**Common status codes:** 202, 200, 401, 403 (not staff / AAL2 required), 422

`POST /api/staff/builds/batch` — CSV batch upload for the marketing pipeline, one `requestBuild()` per
row. Same staff stack and policy (`PreAccountBuildPolicy::staffCreate`) as the single-row endpoint above.

**Request:** `multipart/form-data`, field `file` (max 2048 KB). CSV header row, then one data row per
build:

```
account_type,source_type,source_ref,source_name,contact_email,auto_invite
partna,instagram,alice_ig,,alice@example.com,true
```

- `account_type`, `source_type`, `source_ref` — required per row, same vocabulary as the single-build
  endpoint.
- `source_name`, `contact_email` — optional; blank is `null`.
- `auto_invite` — optional, parsed with `FILTER_VALIDATE_BOOLEAN`; defaults to `true` when absent.
- Every row publishes immediately (`publish: true`, not configurable per-row).
- Rows beyond the **first 500** in the file are silently dropped and reported via `truncated`.

**Response** (no envelope — keys sit at the JSON root):

| Key | Type | Meaning |
|---|---|---|
| `built` | int | New builds created |
| `reused` | int | Rows that deduped onto an existing live build |
| `failed` | array | `{row, code, message}` per row that didn't build — `code` is a `PreAccountBuildException` code, `INVALID_EMAIL`, or `ROW_FAILED` (an unexpected error, reported to Nightwatch, generic message) |
| `truncated` | bool | `true` if the file had more than 500 data rows |
| `total` | int | Rows in the attempt set, after the 500-row cap |
| `processed` | int | Rows actually attempted (successes + every `failed` entry) |
| `remaining` | int | `total - processed`; `0` means the whole file was processed |
| `time_budget_exceeded` | bool | `true` if the loop stopped early on `partna.pre_account.batch_time_budget_seconds` (default 20s) rather than running out of rows |

The loop is wall-clock budgeted (`CACHE-2`/`SCALE-7`) so a large file can't outrun the request timeout and
return nothing. When `remaining > 0`, re-upload the same file (or just the un-processed tail) — `requestBuild()`
dedupes on the source ref, so already-built rows re-serve as `reused` rather than duplicating. See
`docs/wire-changes/2026-08-26-staff-batch-build-time-budget.md`.

**Common status codes:** 200 (the batch endpoint itself never fails per-row — errors land in `failed`), 401,
403 (not staff / AAL2 required), 422 (missing/oversized file)

### Segments (OV-A)

Staff-defined user segments — a dynamic filter definition plus an optional manual member list. Consumed by the feature kill-switch, staff notifications, and any staff tooling that resolves a user set.

Read side (staff):
- GET /api/staff/segments
- GET /api/staff/segments/{segment}
- GET /api/staff/segments/{segment}/users

Write side (staff-admin):
- POST /api/staff/segments
- PATCH /api/staff/segments/{segment}
- DELETE /api/staff/segments/{segment}
- POST /api/staff/segments/{segment}/members
- DELETE /api/staff/segments/{segment}/members

#### `filters` definition

All keys are optional and AND-combine. A missing or null key is unconstrained. **A definition with zero active criteria resolves to an EMPTY dynamic set** (manual members only) — `{}` never means "all users". Soft-deleted users are always excluded from dynamic results.

| Key | Type | Meaning |
|---|---|---|
| `account_type` | `"partna" \| "business"` | Exact account type match |
| `sector` | `string[]` | Any of the given sector slugs |
| `created_from` / `created_to` | `YYYY-MM-DD` | Absolute signup-date window |
| `has_integration` | `true \| "<platform>"` | Any active connection, or one platform |
| `early_access` | `boolean` | In (or, when `false`, not in) the early-access programme |
| `country_code` | `string[]` | ISO alpha-2, uppercase — e.g. `["AU","NZ"]` |
| `location_state` | `string[]` | Free-text state, case-insensitive exact match |
| `location_city` | `string[]` | Free-text city, case-insensitive exact match |
| `tenure_days_min` | `int` 0–3650 | On Partna at least N days |
| `tenure_days_max` | `int` 0–3650 | On Partna at most N days |
| `ig_followers` | object | Instagram follower band — see below |
| `analytics` | object | Visit/click volume — see below |
| `include_manual_members` | `boolean` (default `true`) | Structural, not a filter |

```jsonc
"ig_followers": {
  "min": 1000,               // optional; at least one of min/max required
  "max": 50000,              // optional
  "synced_within_days": 30   // optional freshness window on the connection
}
```

Reads `followersCount` from the synced Instagram connection payload, matching **active** `instagram` connections only. A missing or non-numeric follower count excludes the user (it never errors). `synced_within_days` measures from `last_refreshed_at`, falling back to `created_at` when the connection has never been refreshed.

```jsonc
"analytics": {
  "metric": "visits",        // visits | unique_visitors | clicks | unique_clickers
  "window_days": 30,         // 1-90 (raw analytics events are purged at 90 days)
  "min": 100,                // optional
  "max": null                // optional; at least one of min/max required
}
```

Thresholds on the total over the lookback window. **Zero-activity users are excluded when a positive `min` is set, and included otherwise** — a user with no events has 0, which is at or below any max. That is what makes `max` a usable "low-traffic users" filter.

`min: 0` means *no lower bound*, not "at least zero" — a count is never negative, so the zero constrains nothing and the filter behaves exactly as if `min` were omitted. Consequently:

| shape | matches |
|---|---|
| `{"min": 5}` | ≥ 5 events; zero-activity users excluded |
| `{"max": 5}` | ≤ 5 events; zero-activity users **included** |
| `{"min": 0, "max": 5}` | identical to `{"max": 5}` |
| `{"min": 0, "max": 0}` | exactly the users with **no activity** in the window |
| `{"min": 0}` alone | **422** — see below |

`min: 0` on its own is rejected with `422` rather than accepted as a no-op. It asks for no bounds at all, and an inert criterion is dropped from the query — so a segment filtered only on `{"min": 0}` would resolve to *nobody* rather than everybody. The error is the same "requires at least one of min or max" as an empty object. `max: 0` is unaffected: zero is a meaningful **upper** bound.

The same `min: 0` rule applies to `ig_followers`, where it is a no-op in every shape that also sets `max`. To target "has an Instagram connection" with no follower threshold, use `has_integration: "instagram"`.

Free-text `location_state` / `location_city` matching is best-effort: users who left the field blank never match.

## 10) Media uploads & processing (images + videos)

Images and videos are uploaded through the Partna API and processed entirely server-side. No direct-to-storage uploads from the frontend.

### Architecture

1. Frontend sends `POST /api/uploads` with `pool` and either `image` or `video` as `multipart/form-data`.
2. The server validates the file, stores the original on the **media disk** (Laravel Cloud Object Storage / Cloudflare R2), creates a `site_images` row with `processing_state = pending`.
3. Processing runs on the appropriate worker queue (images → `images` queue, videos → `videos` queue on dedicated `redis_video` connection).
4. Frontend polls `GET /api/images?media_type=video&ids[]=<id>` until `processing_state` is `ready` or `failed`.

### Queue modes

| Media | Queue | Worker command |
|-------|-------|---------------|
| Images | `images` | `php artisan queue:work --queue=images` |
| Videos | `videos` (`redis_video` connection) | `php artisan queue:work redis_video --queue=videos --timeout=3600` |

Both queues fall back to sync inline processing in `local` and `testing` environments (no worker needed for dev).

### Media pools

Each professional has two pools:

- **gallery** — portfolio / showcase media (max configurable, default 5 items total)
- **content** — general-purpose branding media (max configurable, default 5 items total)

Images and videos share the same per-pool cap.

### Image processing

- Two WebP variants per upload: `optimized` (adaptive quality ~500 KB) and `maximized` (100% quality)
- GD-based encoding; content-hashed filenames for immutable CDN caching
- Inline in dev/sync mode; async via `ProcessImageVariantsJob` in production

### Video processing

- Requires the `video_uploads` feature flag (FeatureFlagService — DB registry, falling back to `partna.features.video_uploads`; there is no env var for this) and `ffmpeg`/`ffprobe` on the worker's `$PATH`
- Outputs per video:
  - **MP4:** `variants.optimized` (720p / 2 Mbps), `variants.maximized` (1080p / 5 Mbps)
  - **HLS:** `streams.optimized` (720p playlist), `streams.maximized` (1080p playlist), `streams.adaptive` (master playlist for ABR)
  - **Poster:** `poster` — JPEG frame extracted at ~1s
- HLS segments are stream-copied from the MP4s (no extra re-encode)
- `processing_state` lifecycle: `pending → processing → ready | failed`

### Frontend upload flow (image)

1. `POST /api/uploads` with `pool=gallery`, `image=<file>`, optional `alt_text`.
2. If `processing_state = pending/processing` → poll `GET /api/images?pool=gallery` until `ready`. If already `ready` → variants in upload response.
3. Use `variants.optimized` for normal display, `variants.maximized` for high-detail/zoom.
4. Delete: `DELETE /api/images/{image}`.
5. Reorder: `POST /api/images/reorder` with `{ "pool": "gallery", "media_type": "image", "ids": [...] }`.

### Frontend upload flow (video)

1. `POST /api/uploads` with `pool=gallery`, `video=<file>`, optional `alt_text`.
2. Response always has `processing_state = pending` (video is always async).
3. Poll `GET /api/images?media_type=video&ids[]=<id>` until `processing_state = ready` or `failed`.
4. On `ready`: render using `streams.adaptive` (best for ABR), fall back to `variants.optimized`. Use `poster` for preview/placeholder.
5. Delete: `DELETE /api/images/{image}` (storage cleanup is async for video).
6. Reorder: `POST /api/images/reorder` with `{ "pool": "gallery", "media_type": "video", "ids": [...] }`.

### Supported file types

**Images:** JPEG, PNG, WebP — max `PARTNA_IMAGE_MAX_UPLOAD_KB` (default 10 MB)

**Videos:** MP4, MOV, WebM, AVI — max `PARTNA_VIDEO_MAX_UPLOAD_KB` (default 200 MB), max duration `PARTNA_VIDEO_MAX_DURATION_SECONDS` (default 30s)

### Design-layer singleton images (brand logos + integration covers)

Separate from the pool-based gallery/content uploads above: one row per `(site, purpose)` for the two brand logos, the brand placeholder image, and one cover image per cover-capable platform. Cover slots are registry-derived, not gated on whether the site has actually connected that platform. There is no ordering and no pool cap — uploading into an occupied `purpose` replaces it.

- GET /api/design-media
- POST /api/design-media
- DELETE /api/design-media/{purpose}

**Valid `purpose` values:** `logo_full`, `logo_square`, `placeholder`, plus `cover_<platform>` for every cover-capable platform in the social/integration registry (currently `cover_youtube`, `cover_apple_music`, `cover_apple_podcast`, `cover_eventbrite`) — this list is registry-derived, not fixed, so a new coverable platform adds a new valid `cover_*` value without a doc/schema change.

**`GET /api/design-media`** — every design purpose, keyed by purpose; `null` where a slot is empty.

Response (200):

```json
{
  "images": {
    "logo_full": { "id": "uuid", "purpose": "logo_full", "processing_state": "ready", "processing": false, "url": "https://.../optimized.webp", "svg_url": null, "variants": { "optimized": "...", "maximized": "..." } },
    "logo_square": null,
    "placeholder": null,
    "cover_youtube": null
  }
}
```

**`POST /api/design-media`** — upload or replace one purpose's image. `multipart/form-data`: `purpose` (required, one of the values above), `image` (required file, JPEG/PNG/WebP, max `PARTNA_IMAGE_MAX_UPLOAD_KB` — same limits as the pool uploads above).

- `201` — the uploaded image object, same shape as one entry of `GET`'s `images` map above
- `422` — `purpose` missing/not in the allowlist, or `image` missing/wrong type/too large
- `409` — lost a race against a concurrent upload/replace of the *same* `purpose` (two distinct races underneath — an insert-time unique-violation and a mid-upload conditional claim — both surface identically). Body carries `code: "SINGLETON_UPLOAD_CONFLICT"`; nothing from the losing request is left in storage, so it's safe to just resubmit.

**`DELETE /api/design-media/{purpose}`** — clear a slot without replacing it (until this endpoint existed, the only way to empty a slot was to upload something else into it).

Response (200): `{ "purpose": "logo_full", "removed": true }`

- `404` — `purpose` is not one of the valid values above (`message: "Unknown design image slot."`), **or** it is valid but the slot is already empty (`message: "Nothing uploaded for this slot."`) — same status both times, distinguishable only by `message` text, no machine-readable `code` like the 409 above carries

## 11) Test users and getting tokens

Tokens come from Supabase Auth. Partna does not issue tokens.

### Create test users

- Professional user: create in Supabase Auth, then call POST /api/bootstrap once.
- Staff user: create in Supabase Auth, then insert a row into core.sidest_staff with auth_user_id = the Supabase user id.
- Staff admin: same as staff user, but set is_admin = true.

### Get an access token via Supabase REST

### Request: POST/auth/v1/token?grant_type=password

### Headers: apikey: SUPABASE_ANON_KEY, Content-Type: application/json

### Body:

Response includes access_token. Use that token as the Authorization Bearer token when calling Partna.
This flow is included in the Insomnia collection as Login requests.

## 12) Insomnia collection

Import the provided Insomnia export JSON.
It contains requests for all Stage 1-2 endpoints plus Supabase login requests.

-
-
- Set workspace environment variables first (api_base_url, public_api_base_url, supabase_url, supabase_anon_key, access_token, subdomain, ids).

## 13) Frontend env var checklist

- SUPABASE_URL
- SUPABASE_ANON_KEY
- API_BASE_URL (example: https://api.partna.au/api)
- PUBLIC_DOMAIN (example: sidest.co or localtest.me)
- Optionally: STAFF_DASHBOARD_ENABLED flag if you ship staff tooling in the same frontend

Note: The frontend does not need any storage credentials — all image URLs come from the API `variants` map.

## 14) Backend env var checklist

### Core Laravel

- APP_NAME, APP_ENV, APP_KEY, APP_DEBUG, APP_URL
- LOG_LEVEL Database
- DB_CONNECTION=pgsql
- DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
- DB_SEARCH_PATH (recommended: public,core,site,notifications,analytics)
- DB_STATEMENT_TIMEOUT (ms), DB_LOCK_TIMEOUT (ms)

### Supabase JWT verification

- SUPABASE_URL
- SUPABASE_ANON_KEY
- SUPABASE_JWT_ISSUER
- SUPABASE_JWT_AUD (default: authenticated)
- SUPABASE_JWKS_URL
- SUPABASE_JWKS_CACHE_SECONDS (default: 600)

### Partna app settings

- PARTNA_PUBLIC_DOMAIN (used for domain-scoped public routes)
- PARTNA_MEDIA_DISK (default: media — the Laravel filesystem disk name)
- PARTNA_GALLERY_IMAGE_MAX (default: 6)
- PARTNA_CONTENT_IMAGE_MAX (default: 6)
- PARTNA_IMAGE_MAX_UPLOAD_KB (default: 10240 = 10 MB)
- PARTNA_VIDEO_MAX_UPLOAD_KB (default: 204800 = 200 MB)
- PARTNA_VIDEO_MAX_DURATION_SECONDS (default: 30)
- PARTNA_WAITLIST_ENABLED (default: `false`; when true, blocks bootstrap for new users)
- SOFT_DELETE_RETENTION_DAYS (default: 30)

### Pre-launch account gating

- Set `PARTNA_WAITLIST_ENABLED=true` to block new account creation at `POST /api/bootstrap`.
- Existing professionals are unaffected by this gate.
- Also disable public signups in Supabase Auth (Dashboard -> Authentication -> Providers -> Email -> Disable Signups) to prevent new auth accounts during waitlist-only mode.

### Media disk (Laravel Cloud Object Storage / Cloudflare R2)

**On Laravel Cloud:** No manual env vars needed. Create a bucket in the Cloud dashboard, and set:
- `PARTNA_MEDIA_DISK` = the disk name from `LARAVEL_CLOUD_DISK_CONFIG` (e.g., `public_dev`)

Laravel Cloud auto-injects credentials via `LARAVEL_CLOUD_DISK_CONFIG`. The image system reads `PARTNA_MEDIA_DISK` to find the right disk.

**Self-managed (standalone R2 / AWS S3):** Configure the `media` disk manually:
- MEDIA_DISK_KEY (S3 access key)
- MEDIA_DISK_SECRET (S3 secret key)
- MEDIA_DISK_REGION (default: auto)
- MEDIA_DISK_BUCKET (default: comet-media)
- MEDIA_DISK_URL (public CDN base URL)
- MEDIA_DISK_ENDPOINT (R2/custom S3 endpoint URL)

### Optional: cache, queues, mail

- CACHE_STORE, REDIS_URL or REDIS_HOST/REDIS_PASSWORD
- QUEUE_CONNECTION: `sync` (no worker needed — processes inline) | `database` | `redis` (worker required; recommended for scale)
- MAIL_MAILER, MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS

## 15) Known implementation gotchas

### Domain-scoped public routes

- If you call /api/public/site on the API host instead of {subdomain}.{PARTNA_PUBLIC_DOMAIN}, the route may not match or may return 404.
- Always use public_api_base_url = https://{subdomain}.{PARTNA_PUBLIC_DOMAIN}/api for public routes.

### Analytics timestamps

- Public analytics endpoints set `occurred_at` server-side (`now()`).
- Frontend does not need to send `occurred_at`.

### Gallery limits and ordering

- Gallery pool: max 5 active images (configurable via `PARTNA_GALLERY_IMAGE_MAX`). Content pool: max 5 (via `PARTNA_CONTENT_IMAGE_MAX`).
- Pool limits are enforced server-side with PostgreSQL advisory locks for race safety.
- `POST /api/uploads` validates the pool limit before creating a new image.
- Reorder endpoint (`POST /api/gallery/reorder`) accepts an `ids` array; any omitted ids will be appended in existing order.
- Variants are generated inline (sync mode) or asynchronously (queue mode). If async, poll `GET /api/images` until `processing: false`.
- Content-hashed variant URLs are immutable for CDN caching; re-processing generates new URLs automatically.
