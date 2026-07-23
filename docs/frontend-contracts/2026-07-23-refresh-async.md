# Frontend contract change — manual platform refresh is now async (202 + poll)

**Landed:** 2026-07-23 (audit finding RV-8).
**Pattern:** mirrors the link-card connect 202 + poll (`2026-07-02-async-link-connect.md`) and the Instagram connect 202 + poll (`instagram-connect-async.md`).

**Why:** `POST /api/platforms/{platform}/refresh` used to re-scrape every connected row for that platform inline, on the request thread. `SafeUrlFetcher`'s timeouts are per-hop (up to ~96s per outbound `fetch()` call, worst case), and several platform strategies (`shop`, `eventbrite`, others) chain multiple sequential fetches per row — so a single request could hold a PHP-FPM worker for minutes, multiplied by however many rows the user had connected. The re-scrape now runs on the `platform_refresh` queue via the same `RefreshConnectionJob` the hourly cron already uses; the endpoint returns immediately.

This only touches the **manual refresh** endpoints. It does not touch connect, selection, or any other platform route.

---

## Before (synchronous — REMOVED)

`POST /api/platforms/{platform}/refresh` blocked until every connected row for that platform had been re-scraped, then returned:

```json
{ "refreshed": 2, "ok": 2 }
```

`refreshed` = rows attempted (previously **all** rows, active or not). `ok` = rows whose `last_refresh_status` read `'ok'` afterward.

## After (async)

### 1. Kick off — `POST /api/platforms/{platform}/refresh`

No request body.

| Status | Body | Meaning |
|--------|------|---------|
| `202` | `{ "status": "pending", "refreshed": 2, "statusUrl": "https://api.partna.au/api/platforms/{platform}/refresh/status" }` | Accepted. One re-scrape job queued per **active** connected row. `refreshed` is the row count — kept in the same key name as the old `200` body so a client that only checks `response.ok` and ignores the body keeps working. |
| `422` | `{ "message": "This connection refreshes on its own — there's nothing to pull manually." }` | Platform isn't in the refreshable set. **Unchanged.** |
| `429` | `{ "message": "Just refreshed — give it a few seconds before trying again." }` | Per-user+platform 12s cooldown, still enforced **before** anything is queued. **Unchanged.** |
| `404` | `{ "message": "Nothing connected to refresh." }` | No active row for that platform. **Unchanged message; the row set behind it is now filtered to active rows — see "What changed but isn't new API surface" below.** |

**`ok` is not returned in the `202` body, ever — not even as `0`.** Its value is unknowable until the queued jobs finish; sending `0` would read as "everything already failed," which is worse than omitting the key. Read `ok` only from the poll endpoint's `ready`/`failed` responses.

### 2. Poll — `GET /api/platforms/{platform}/refresh/status` (authenticated, no body)

| Status | Body | Meaning |
|--------|------|---------|
| `200` | `{ "status": "pending" }` | At least one row is still being refreshed — keep polling. |
| `200` | `{ "status": "ready", "refreshed": 2, "ok": 2 }` | All rows reached a terminal state and at least one is `ok`. Stop polling. `refreshed`/`ok` are computed fresh at poll time, over the caller's currently-active rows for that platform. |
| `200` | `{ "status": "failed", "refreshed": 2, "ok": 0 }` | All rows reached a terminal state and none is `ok`. Stop polling; show an error + allow retry (subject to the 12s cooldown). Per-row failure detail (`last_refresh_error`) is deliberately **not** exposed here — it can carry internal scraper detail, same reasoning as `GET /platforms/meta`. |
| `404` | `{ "message": "Nothing connected to refresh." }` | No active row for that platform belonging to the caller. |

There is no other terminal state — `pending` → (`ready` or `failed`) is the complete set.

### Recommended polling

- First poll: **1.5s** after the `202`.
- Subsequent polls: every **1.5s**.
- Hard cap: **~150s** (not the ~20s used for link-card enrichment — a refresh job may sit behind the per-provider `platform-refresh` rate limiter, which the lighter enrichment jobs don't). If no terminal state by then, fall back to `GET /platforms/meta` rather than showing a hard error.
- On `ready` or `failed`: stop immediately; do not poll again.

### Stale-pending safety net

If a worker dies mid-job, or the refresh silently loses a write-lock race, a row can be left reading `'pending'` forever with nothing to flip it. The poll endpoint treats any row still `'pending'` after **5 minutes** as resolved (folded into the `ready`/`failed` computation) rather than blocking the client indefinitely. This mirrors the existing `connect/status` endpoints' stale-pending handling — no new column, no reaper cron.

---

## What changed but isn't new API surface

**Inactive connections are no longer refreshed by this endpoint.** The row query that both `POST .../refresh` and `GET .../refresh/status` use now filters to `is_active = true`, matching the Instagram refresh branch and the hourly cron's due-ness scope. Previously the endpoint refreshed every row for the platform regardless of active state. An inactive row isn't rendered on the public sitepage, so refreshing it bought nothing — but if a dashboard client was relying on the old "refreshed" count including inactive rows, that count will now be lower (or the endpoint may 404 where it previously returned `200`, if the user's only row for that platform is inactive).

---

## What did NOT change

| Endpoint / branch | Status | Notes |
|---|---|---|
| `POST /platforms/instagram/refresh` | **unchanged** | Already its own async 202 + poll (`instagram/connect/status`), landed 2026-06-09. Not touched by this change. |
| `422` — non-refreshable platform | **unchanged** | Same message, same trigger. |
| `429` — 12s cooldown | **unchanged** | Same message, same trigger, same timing (still gates before any queueing). |
| `404` — nothing connected | **unchanged message** | Trigger now reads active rows only — see above. |
| `GET /platforms/meta` | **unchanged** | Still the cross-platform "Synced 2h ago" / error-badge source; not the completion signal for a manual refresh (a partial completion across several rows can't be distinguished from a full one there). Continue reading it for the dashboard index; use the new poll endpoint only after a manual refresh `202`. |

---

## Companion docs

- [`2026-07-02-async-link-connect.md`](./2026-07-02-async-link-connect.md) — the same 202+poll pattern for the four link-enrichment endpoints.
- [`instagram-connect-async.md`](./instagram-connect-async.md) — the same pattern for Instagram connect (not refresh).
