# Enquiry Inbox — Frontend Handoff

> Backend implementation is complete on `feat/enquiry-inbox`. This document is the API contract for the frontend session.

---

## ⚠️ CORRECTION vs the original spec — route paths

The design spec (and the handoff appendix copied below) refer to dashboard endpoints as `/me/enquiries/*`. **The actual implemented routes are `/api/enquiries/...` — there is NO `/me/` segment.** The existing enquiry routes in this codebase live at `/api/enquiries`, and the new endpoints were added consistently alongside them.

**Authoritative endpoint list (what the backend actually exposes):**

| Method | Path | Purpose |
|--------|------|---------|
| `GET`    | `/api/enquiries`               | List (paginated). `?status=new\|read\|replied\|archived\|spam`. Default excludes `archived`+`spam`. |
| `GET`    | `/api/enquiries/counts`        | `{new, read, replied, archived, spam}` integer counts (flat JSON, no envelope). |
| `GET`    | `/api/enquiries/{id}`          | Detail (enquiry + `customer` + `history`), auto-transitions `new→read`, marks linked notification receipt read. Payload nested under `data`. |
| `PATCH`  | `/api/enquiries/{id}`          | `{read: true|false}` — back-compat read toggle; also sets `status`. |
| `DELETE` | `/api/enquiries/{id}`          | Soft-delete. |
| `POST`   | `/api/enquiries/{id}/read`     | Transition → read (idempotent). |
| `POST`   | `/api/enquiries/{id}/replied`  | Transition → replied (idempotent). |
| `POST`   | `/api/enquiries/{id}/archive`  | Transition → archived (idempotent). |
| `POST`   | `/api/enquiries/{id}/restore`  | Transition → new (idempotent). Does NOT recreate a soft-deleted contact. |
| `POST`   | `/api/enquiries/{id}/spam`     | Transition → spam + lone-Customer cleanup + per-pro HMAC blocklist. |
| `POST`   | `/api/public/enquiry`          | Public submit (unchanged contract; `bot.token:enquiry` middleware already wired). |

Wherever the appendix below says `/me/enquiries/...`, read it as `/api/enquiries/...`.

**Response envelopes (verified against the implementation):**
- `GET /api/enquiries/counts` → flat object: `{"new":3,"read":2,"replied":1,"archived":5,"spam":4}` (no `data` wrapper).
- `GET /api/enquiries` → paginated; items under top-level `data` key.
- `GET /api/enquiries/{id}` → `{"data": { ...EnquiryDetailResource }}` (explicitly nested under `data`).
- Transition `POST`s → `{"enquiry": { ...EnquiryResource }}`.

---

## Frontend handoff (verbatim from spec, paths corrected per the box above)

This appendix is the contract for the frontend session (separate repo `partna-frontend` + `@partna/themes` package). Backend implementation and frontend implementation are decoupled — they only need to agree on this contract.

### Authentication

- **Public endpoints** (`POST /public/enquiry`): no auth header.
- **Dashboard endpoints** (`/api/enquiries/*`): require `Authorization: Bearer <supabase_access_token>` header. The frontend already sets this globally for `/me/*` routes — note these enquiry routes are under `/api/enquiries`, served by the same `user.api` auth middleware group.

### API base URL — config-injected, not hardcoded

The `@partna/themes` package is framework-agnostic and must NOT hardcode an environment-specific URL. The consumer (Astro shell, Hydrogen shell, etc.) injects the base URL as configuration:

```ts
// In theme component:
fetch(`${config.apiBaseUrl}/public/enquiry`, { ... });
```

Environment values:
- Production: `https://api.partna.au`
- Development: `https://dev-api.partna.au`

### Theme contract — contact block renderer (`@partna/themes`)

Block settings shape (what the theme receives in `block.settings`):

```ts
type ContactBlockSettings = {
  headline?: string;          // max 80 chars
  description?: string;       // max 300 chars
  notification_email?: string; // backend-only — DO NOT render
  subject_options?: string[];  // ≤10, max 60 chars each — merged with platform defaults
  notification_channels?: ('in_app' | 'email')[];  // backend-only — DO NOT render
};
```

Form fields to render (all required unless noted):

- `name` — text, 1–120 chars
- `email` — type=email, validated
- `subject` — select, options = platform defaults from `config('partna.contact_subject_defaults')` ∪ `subject_options`
- `message` — textarea, 1–2000 chars
- `website` — hidden honeypot field; MUST be in DOM, MUST be empty when submitted (any value triggers silent abuse-log + fake 200)
- `form_started_at_ms` — hidden, set to `Date.now()` on mount
- `cf-turnstile-response` — Cloudflare Turnstile widget token. The `VerifyBotToken` middleware (alias `bot.token:enquiry`) reads this header/field. Render the widget always when `BOT_PROTECTION_MODE` is `shadow` or `enforce`; backend driver config (`BOT_PROTECTION_DRIVER`) determines which provider validates the token. Mount with `action="enquiry"` (the action tag must match the route's middleware action). Load `https://challenges.cloudflare.com/turnstile/v0/api.js`.

Submit target: `POST {config.apiBaseUrl}/public/enquiry`. JSON body.

**Success response shape:** `{"ok": true}` — no `enquiry_id` (intentionally omitted to avoid PII surface). The honeypot and spam-blocklist paths return the identical shape so client behavior is uniform on success.

Success UX: form replaced with confirmation message. No redirect.

**Error response shapes:**

```json
// 422 from PublicEnquiryRequest validation (standard ApiController::error()):
{"message": "...", "errors": {"name": ["..."], ...}}

// 422 from VerifyBotToken middleware (verified at app/Http/Middleware/VerifyBotToken.php):
{
  "message": "Verification failed.",
  "error": "captcha_missing",          // or other captcha error code
  "captcha": {
    "should_retry": true,
    "should_rerender": true
  }
}

// 429 rate limit:
{"message": "Too many requests, try again in a minute"}

// 503 from VerifyBotToken when fail_open=false and provider is down:
{
  "message": "Verification temporarily unavailable.",
  "error": "captcha_unavailable",
  "captcha": {
    "should_retry": true,
    "should_rerender": false
  }
}

// 5xx generic (Laravel default):
{"message": "Couldn't send right now, try again shortly"}
```

Error UX:
- `422` with `errors.{field}` → field-level validation errors; scroll to first error.
- `422` with `error: "captcha_*"` and `captcha.should_rerender: true` → re-render the Turnstile widget and prompt user to retry.
- `429` → show `message` as a toast.
- `503` with `error: "captcha_unavailable"` and `captcha.should_retry: true` → show "Spam check is temporarily down — try again in a moment" with a retry button.
- `5xx` → generic "Couldn't send right now, try again shortly".

### Dashboard contract — enquiry inbox UI (`partna-frontend`)

**New routes:**
- `/dashboard/enquiries` — list with status tabs (New • Read • Replied • Archived • Spam). Counts from `GET /api/enquiries/counts`. Default tab: All visible statuses (i.e., default list excludes archived and spam).
- `/dashboard/enquiries/{id}` — detail. Three panels: enquiry / contact card / history list (each history item links to its own detail page).

**API endpoints to consume:** see the authoritative table at the top of this doc.

**Tab badges:** the bell-icon top-level badge is `counts.new`. Each tab badge is `counts.{tab_name}` 1:1. After any status transition click, the frontend should **optimistically update** its local copy of `counts` (decrement source, increment destination) AND issue a fresh `GET /api/enquiries/counts` to reconcile. The endpoint has no server-side cache; the GET is sub-millisecond at expected scale.

**Bell-icon inbox:** the existing `/me/notifications` poller automatically includes `type='enquiry.received'` rows. Bell click navigates to `cta_url` (= `/dashboard/enquiries/{id}`). Opening the detail page auto-marks both the enquiry and the notification as read; the frontend should optimistically decrement the bell badge on detail-view navigation.

**Reply UX (v1):** use the server-built `mailto_url` field from the detail response — it is already URL-encoded for special characters. Click handler:
```ts
window.location.href = enquiry.mailto_url;  // e.g., "mailto:foo%40example.com?subject=Re%3A%20..."
```
A separate "Mark as replied" button on the same panel calls `POST /api/enquiries/{id}/replied`. The flow: click email → compose externally → come back → click "Mark as replied".

**Transition responses:** all `POST /api/enquiries/{id}/{action}` endpoints are **idempotent** — they return 200 regardless of prior state, including when called on an enquiry already in that state. The frontend does NOT need to handle 409 / 422 for "invalid" transitions; there are no such errors. The only error responses are 404 (cross-tenant or missing) and 5xx.

**Block authoring UI** (already exists in dashboard, needs one addition): the contact-block settings form needs a new control for `notification_channels` — two checkboxes ("Notify me in the dashboard" pre-checked; "Email me at {notification_email}", disabled if `notification_email` is empty). Validation: at least one must be checked; "email" cannot be checked unless `notification_email` is set.

### Bot-protection config (already wired via bot-protection-foundation)

Per `docs/auth/bot-protection-supabase.md`, the env vars needed in Laravel Cloud dev:

- `BOT_PROTECTION_DRIVER=turnstile`
- `BOT_PROTECTION_MODE=shadow` (initially; flip to `enforce` after 1-week soak)
- `BOT_PROTECTION_FAIL_OPEN=true` (pre-pilot default — keep until ATO incident motivates flipping)
- `TURNSTILE_SECRET=...` (backend)
- `TURNSTILE_SITE_KEY=...` (frontend's site-key env)

Rollout: frontend renders the Turnstile widget with `action="enquiry"`; backend already accepts the token at `POST /public/enquiry` via the existing `bot.token:enquiry` middleware. After the dev soak is clean, flip `BOT_PROTECTION_MODE=enforce` in dev, then push the same env change to prod.
