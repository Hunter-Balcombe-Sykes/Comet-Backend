# Cross-Stack API-Surface Diff — backend supply vs. frontend demand — 2026-07-03

**Question asked:** which backend API surface does `PartnaAu/partna-frontend` actually consume, so we can find endpoints/features nothing calls.

**Method:** `php artisan route:list` (508 unique `/api/*` paths = *supply*) diffed against every backend call site in `partna-frontend` (extracted via GitHub API, no clone = *demand*). Path params treated as wildcards. Companion to the internal `CONSOLIDATED.md` (which covers dead code *inside* the backend).

> ⚠️ **The single most important caveat is Tier D below.** The public-facing sitepage is rendered by a **separate Astro app** (`partna-monorepo/apps/pages`), which this diff did **not** inspect. Every `/api/public/*` route may be alive there. Do **not** delete anything in that group on the strength of this report.

---

## Tier A — Highest-value, safe-to-remove-now (with 1-line frontend coordination)

### A1. The `/api/integrations/*` ↔ `/api/platforms/*` mirror — 176 duplicate routes

`routes/api/integrations.php:346-347` registers the **entire** integrations route tree twice over the **same controllers**:

```php
$registerIntegrationRoutes('integrations');
$registerIntegrationRoutes('platforms');
```

- Backend supply: **176** `/integrations/*` paths + **176** identical `/platforms/*` paths.
- Frontend demand: uses `/platforms/*` **exclusively** — with exactly **one** straggler call to `/api/integrations/meta` (`lib/queries/fetchers/integrations.ts`). Its mirror `/api/platforms/meta` already exists.

**⚠️ Drift — the in-code comment points the cleanup the WRONG way.** `routes/api/integrations.php:30-34` says:

> "Registered under /integrations (canonical) AND /platforms (a legacy alias … dropped once the frontend flips to /integrations)."

The frontend flipped to **`/platforms`**, the opposite of what the comment predicts. **Acting on that comment (dropping `/platforms`) would break the entire integrations dashboard.** Backend intent and frontend reality disagree on which prefix survives.

**⚠️ Not a one-line change — the backend mints `/integrations/*` URLs itself.** Five controllers build `url('/api/integrations/...')` status URLs and hand them to the frontend to poll (server-supplied, so the frontend calls `/integrations/*` *indirectly* — this is the "unresolved/dynamic" Tier D pattern below):
- `OnlineOrderingController.php:101` — `/api/integrations/online-ordering/entries/{id}/status`
- `ReservationsController.php:74` — `/api/integrations/reservations/detect/status`
- `CustomLinksController.php:71` — `/api/integrations/custom/links/{id}/status`
- `InstagramController.php:88` — `/api/integrations/instagram/connect/status` (the async IG-scrape poll)
- `BookingController.php:75` — `/api/integrations/booking/detect/status`

Plus **7 backend test files / 25 lines** assert against `/api/integrations/*`.

**Chosen resolution (2026-07-04, Josh) — delete `/integrations` outright, no shim.** Pre-beta with no customers, so the one static frontend caller (`/api/integrations/meta`) 404ing until the frontend migrates it is acceptable; not worth a shim.
1. Backend: delete `$registerIntegrationRoutes('integrations');` (line ~346); keep `$registerIntegrationRoutes('platforms');`. Fix the stale comment (lines 30-34).
2. Backend: repoint the 5 controller `url('/api/integrations/...')` → `/api/platforms/...` (**required** — else they mint URLs to deleted routes; grep `api/integrations` in `app/` must come back empty after).
3. Backend: migrate the 7 test files' `/api/integrations/*` → `/api/platforms/*` (**required** — else red suite; same controllers, identical behaviour; point `IntegrationsMetaTest` at `/api/platforms/meta`).
4. Frontend (separate, unhurried): change the lone `/api/integrations/meta` call → `/api/platforms/meta`.

Net effect: 508 → ~333 registered API routes. Brief pre-beta breakage of `/api/integrations/meta` (dashboard "synced Xh ago" badge) until the frontend line ships — accepted. Effort: **S–M**.

### A2. `/api/booking/settings` — 1 dead route

Top-level `PATCH /api/booking/settings`. No frontend caller; only referenced in old planning docs. Distinct from the **live** `/api/platforms/booking/detect` + `/status` (link-type detection, actively used). The dropped-booking product (memory: booking/Fresha/Square dropped 2026-05) left this singular settings endpoint behind.

---

## Tier B — Frontend dead code that corroborates unused backend surface

Found in `partna-frontend`; listed here because each one *confirms* a backend route has no live caller.

- **`lib/queries/sitepage.ts` + `fetchers/sitepage.ts`** — entire query factory orphaned (no `useQuery` callers; real UI uses `lib/sitepage/api.ts`). Implicates as uncalled: `/api/gallery` (bare), `/api/service-categories*` (backend's own `booking/api.ts` comment agrees: "Categories were removed … not called by the dashboard").
- **`lib/queries/public.ts` + `fetchers/public.ts`** — almost entirely orphaned. But its targets are all `/api/public/*` → **see Tier D**, judge via Astro, not here.
- **`customerQueries.enquiries()` / `.emailSubscribers()`** — orphaned → `/api/enquiries` (bare list) and `/api/email-subscribers` uncalled from the dashboard.
- **Dead Next.js proxies calling non-existent backend routes (always error):** `app/api/public/booking/{config,services,availability,checkout}/route.ts` → `/api/public/booking/*-by-slug` (these paths **do not exist** on the backend — leftover scaffolding from the dropped booking product); `fetchPublicJoinByHandle` → `/api/public/join/{handle}` (also non-existent).

---

## Tier C — Unused BUT likely intentional (backend ahead of frontend — do NOT delete)

Pre-beta, backend-first. These have no frontend caller *yet* because the UI isn't built, not because they're dead. Flag for product tracking, not removal.

- **Account lifecycle:** `/api/me/data-export`, `/api/me/deletion/{request,confirm,cancel}`, `/api/me/feedback[/{id}]`, `/api/confirmation-preferences`, `/api/me/site/reclaim-handle`, `/api/site/visibility` (publish currently done via generic `PATCH /api/site`).
- **Soft-delete restore endpoints (no undo UI):** `/api/customers/{id}/restore`, `/api/services/{id}/restore`, `/api/service-categories/{category}/restore`.
- **Enquiry actions (no enquiry-management UI):** `/api/enquiries/counts`, `/api/enquiries/{id}`, `/api/enquiries/{id}/{read,replied,archive,spam,restore}`.
- **`/api/email-subscribers/export`** — dashboard does client-side CSV from already-fetched data.
- **Staff / admin namespace — 57 routes, 2 used.** Only `/api/staff/me` + `/api/staff/professionals` (bare list) are called (`admin/users/page.tsx` is a minimal browse tool). The other ~55 (`cases*`, `feature-flags*`, per-professional detail / bulk-status / analytics / customers / deletion / data-export / enquiries / sites / stats …) are an **unbuilt staff dashboard**, not dead code. Confirm with product before touching.

---

## Tier D — CANNOT be judged from this diff — needs an Astro (`apps/pages`) demand sweep first

All **22** `/api/public/*` routes. The public sitepage renderer is a separate app not inspected here. Absence of a caller in `partna-frontend` proves nothing about these. Includes: `/api/public/profiles/{handle}` (+ `/integrations`, `/menu`, `/platforms`), `/api/public/site-by-slug`, `/api/public/config/{integrations,social-platforms}`, `/api/public/customers`, `/api/public/enquiry`, `/api/public/marketing-preference`, `/api/public/unsubscribe/{token}`, `/api/public/analytics/{ping,rum,section-seen}`, `/api/v1/public/report`.

**Action:** re-run this demand-mapping against `partna-monorepo/apps/pages` before deleting anything in Tier D.

---

## Tier E — Expected non-callers (NOT findings)

Infra/ops probes and externally-initiated endpoints, correctly absent from application JS: `/api/health`, `/api/health/scheduler`, `/api/ready`, `/api/ping`, `/api/internal/{csp-report,email-hooks/supabase,env-check}`, `/api/webhooks/supabase/auth/mfa-verification`.

---

## Bottom line

- **Biggest win:** collapse the 176-route `/integrations` mirror (Tier A1) — one frontend line + one backend line, after fixing a comment that currently documents the *wrong* direction.
- **Backend internals are clean** — the code-quality sweep found only 6 low-severity items (2×P2 copy-paste, 4×P3 comment noise; see `CONSOLIDATED.md`).
- **Don't confuse "no UI yet" with "dead"** (Tier C) and **don't judge public routes without the Astro app** (Tier D).
