# Frontend contract change — Instagram connect is now async (202 + poll)

**Status:** Backend landed 2026-06-09 (audit finding CONS-6). **Frontend must update to match.**
**Why:** the synchronous `connect()` blocked a PHP-FPM worker for up to ~150s (110s Apify scrape + serial image mirroring). The scrape + mirror now run in a background job (`InstagramConnectJob`, queue `scraping`); the endpoint returns immediately.

Both `/api/integrations/instagram/...` and `/api/platforms/instagram/...` resolve to the same controller (legacy alias) — use whichever the frontend already uses.

---

## Before (synchronous — REMOVED)

`POST /api/instagram/connect` `{ "username": "someuser" }` blocked until done, then returned `200` with the full selection payload (`username`, `fullName`, `profilePicUrl`, `images[]`, `imagesDropped`, …).

## After (async)

### 1. Kick off — `POST .../instagram/connect`
Request: `{ "username": "someuser" }`

| Status | Body | Meaning |
|--------|------|---------|
| `202`  | `{ "status": "pending", "statusUrl": "https://api.partna.au/api/integrations/instagram/connect/status" }` | Accepted; job queued. Begin polling `statusUrl`. |
| `422`  | validation error | Bad/missing username. |
| `429`  | rate-limit error | Per-user cooldown or daily Apify cap hit. Do **not** poll; surface a "try again later" message. |

The cooldown is still enforced **before** the job is queued, so a rapid second `connect()` returns `429` without starting new work.

### 2. Poll — `GET .../instagram/connect/status` (authenticated, no body)

| Status | Body | Meaning |
|--------|------|---------|
| `200`  | `{ "status": "pending" }` | Job still running — keep polling. |
| `200`  | `{ "status": "ready", "connection": { …full selection payload… } }` | Done. `connection` has the same shape the old synchronous call returned (`username`, `fullName`, `profilePicUrl`, `businessCategory`, `followersCount`, `postsCount`, `mode: "automatic"`, `images[]`, `imagesDropped`). Stop polling. |
| `200`  | `{ "status": "failed", "error": "apify_fetch_failed" \| "job_failed" }` | Terminal failure (scrape/network/job error). Stop polling; show an error + offer retry (subject to cooldown). |
| `404`  | `{ "message": "No Instagram connection found." }` | No connect attempt for this user (or not owned by caller). |

### Recommended polling
- First poll ~2s after the `202`, then every ~3s.
- Terminal states: `ready` and `failed` — stop on either.
- Safety timeout ~180s; if no terminal state by then, show a generic error (the job has a 150s timeout, so this means the worker is backed up).

---

## Notes
- The **manual** picker flow (`GET .../instagram/posts`, `POST .../instagram/selection`) is unchanged and still synchronous — only the automatic `connect()` changed.
- Image hosts are restricted to the Instagram/Facebook CDN and fetched with redirects disabled (SSRF guard); occasionally an image may be dropped — `imagesDropped` reflects this, as before.
