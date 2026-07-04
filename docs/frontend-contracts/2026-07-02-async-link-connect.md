# Frontend contract change — link-card connect endpoints are now async (202 + poll)

**Landed:** 2026-07-02 (JOB-1 — audit finding SCALE-3 / #JOB-1).
**Pattern:** mirrors the Instagram connect 202 + poll established in 2026-06-09.

---

## Summary

Four connect endpoints that previously blocked on a page-fetch (up to ~10s each) now
return **202 immediately** with a minimal, URL-derived card. A background job
(`EnrichLinkCardJob`, queue `scraping`) fetches the page metadata and upgrades the
stored card. A per-endpoint **status URL** reports progress.

| Endpoint | Before | After |
|---|---|---|
| `POST /api/platforms/custom/links` | `200 {links:[…fully enriched]}` | `202 {status:'pending', link:{…minimal}, statusUrl}` |
| `POST /api/platforms/online-ordering/entries` | `200 {entries:[…fully enriched]}` | `202 {status:'pending', entries:[…minimal], statusUrl}` |
| `POST /api/platforms/booking/detect` (custom branch) | `200 {provider:'custom', selection:{…}}` | `202 {provider:'custom', next:'custom-saved', status:'pending', selection:{…minimal}, statusUrl}` |
| `POST /api/platforms/reservations/detect` (custom branch) | `200 {provider:'custom', selection:{…}}` | `202 {provider:'custom', next:'custom-saved', status:'pending', selection:{…minimal}, statusUrl}` |

**Unchanged branches — still synchronous 200:**
- `POST /booking/detect` → `fresha` or `square` provider branches (no HTTP fetch)
- `POST /reservations/detect` → `opentable`, `fresha`, or `square` provider branches (no HTTP fetch)

Only the `custom` provider branch (user-supplied URL) changed.

---

## Per-endpoint details

### 1. Custom Links — `POST /api/platforms/custom/links`

#### 202 response

```json
{
  "status": "pending",
  "link": {
    "id": "link-<16-char-hash>",
    "url": "https://example.com/menu",
    "name": "example.com",
    "description": null,
    "favicon": "https://www.google.com/s2/favicons?domain=example.com&sz=64",
    "logo": null
  },
  "statusUrl": "https://api.partna.au/api/platforms/custom/links/link-<hash>/status"
}
```

`name` is always the bare domain at 202 time. `description`, `logo` are null until enriched.
`favicon` is always the Google Favicons CDN URL (no HTTP fetch — URL-derived).

#### Status poll — `GET /api/platforms/custom/links/{id}/status`

| Response body | Meaning |
|---|---|
| `{"status":"pending"}` | Job still running — keep polling. |
| `{"status":"ready","links":[…]}` | Done. `links` is the full list for this user in the same shape as `GET /api/platforms/custom/links`. |
| `{"status":"failed"}` | Terminal. Show error + allow retry. |
| `404` | Resource doesn't exist or doesn't belong to the caller. |

---

### 2. Online Ordering — `POST /api/platforms/online-ordering/entries`

#### 202 response

```json
{
  "status": "pending",
  "entries": [
    {
      "id": "oo-<hash>",
      "url": "https://www.ubereats.com/store/x",
      "name": "ubereats.com",
      "description": null,
      "favicon": "https://www.google.com/s2/favicons?domain=ubereats.com&sz=64",
      "logo": null,
      "provider": "ubereats",
      "pickupUrl": null,
      "deliveryUrl": null
    }
  ],
  "statusUrl": "https://api.partna.au/api/platforms/online-ordering/entries/oo-<hash>/status"
}
```

`entries` contains the user's full current list (including the new pending entry).
Existing entries are already enriched; only the new one has null `description`/`logo`.
`statusUrl` references the specific new entry that is being enriched.

#### Status poll — `GET /api/platforms/online-ordering/entries/{id}/status`

| Response body | Meaning |
|---|---|
| `{"status":"pending"}` | Job still running — keep polling. |
| `{"status":"ready","entries":[…]}` | Done. `entries` is the full list, same shape as `GET /api/platforms/online-ordering/entries`. |
| `{"status":"failed"}` | Terminal. Show error + allow retry. |
| `404` | Resource doesn't exist or doesn't belong to the caller. |

---

### 3. Booking — `POST /api/platforms/booking/detect` (custom branch only)

**Trigger:** only when the posted URL is not a recognised Fresha or Square URL. The
`fresha` and `square` branches return synchronous `200` and are unchanged.

#### 202 response

```json
{
  "provider": "custom",
  "next": "custom-saved",
  "status": "pending",
  "selection": {
    "provider": "custom",
    "url": "https://acme.com/book",
    "name": "acme.com",
    "description": null,
    "favicon": "https://www.google.com/s2/favicons?domain=acme.com&sz=64",
    "logo": null
  },
  "statusUrl": "https://api.partna.au/api/platforms/booking/detect/status"
}
```

Note: the booking `statusUrl` has no `{id}` segment — there is only one booking slot per user.

#### Status poll — `GET /api/platforms/booking/detect/status`

| Response body | Meaning |
|---|---|
| `{"status":"pending"}` | Job still running — keep polling. |
| `{"status":"ready","selection":{…}}` | Done. `selection` has the same shape as the 202 `selection` but with enriched `name`/`description`/`logo`. |
| `{"status":"failed"}` | Terminal. Show error + allow retry. |
| `404` | No booking connection for this user. |

---

### 4. Reservations — `POST /api/platforms/reservations/detect` (custom branch only)

**Trigger:** only when the posted URL is not a recognised OpenTable, Fresha, or Square URL.

#### 202 response

```json
{
  "provider": "custom",
  "next": "custom-saved",
  "status": "pending",
  "selection": {
    "provider": "custom",
    "url": "https://acme.com/reserve",
    "name": "acme.com",
    "description": null,
    "favicon": "https://www.google.com/s2/favicons?domain=acme.com&sz=64",
    "logo": null
  },
  "statusUrl": "https://api.partna.au/api/platforms/reservations/detect/status"
}
```

Note: the reservations `statusUrl` has no `{id}` segment — one slot per user.

#### Status poll — `GET /api/platforms/reservations/detect/status`

| Response body | Meaning |
|---|---|
| `{"status":"pending"}` | Job still running — keep polling. |
| `{"status":"ready","selection":{…}}` | Done. Enriched `selection`. |
| `{"status":"failed"}` | Terminal. Show error + allow retry. |
| `404` | No reservations connection for this user. |

---

## Poll contract (all four endpoints)

The three possible `status` values are the complete set — there is no other terminal state:

- **`pending`** — keep polling.
- **`ready`** — stop polling; render enriched data from the response body.
- **`failed`** — stop polling; show an error message and offer retry (respects any rate-limit cooldown).

### Recommended cadence

- First poll: **1.5s** after the 202.
- Subsequent polls: every **1.5s**.
- Hard cap: **~20s** (~13 polls). If no terminal state by then, treat as `failed` and surface a generic error (the background job has a 30s timeout, so a missing terminal state after 20s means the worker is backed up or the job was lost).
- On `ready` or `failed`: stop immediately; do not poll again.

### Minimal card is safe to render immediately

The minimal card in the 202 body is valid and display-safe — it always has a `url` and a
`favicon`, and a `name` derived from the domain. `description` and `logo` will be null until
`status` becomes `ready`. The recommended UX is to render the minimal card straight away with
a loading indicator on the null fields, and update in-place when the poll returns `ready`.

This mirrors how the dashboard handles Instagram: show the card shell immediately, upgrade when
the job completes.

---

## What did NOT change

| Endpoint | Status | Notes |
|---|---|---|
| `POST /booking/detect` → fresha | **unchanged 200** | No HTTP fetch involved. |
| `POST /booking/detect` → square | **unchanged 200** | No HTTP fetch involved. |
| `POST /reservations/detect` → opentable | **unchanged 200** | No HTTP fetch involved. |
| `POST /reservations/detect` → resdiary | **unchanged 200** | No HTTP fetch involved. |
| `POST /reservations/detect` → nowbookit | **unchanged 200** | No HTTP fetch involved. |
| `POST /reservations/detect` → fresha | **unchanged 200** | No HTTP fetch involved. |
| `POST /reservations/detect` → square | **unchanged 200** | No HTTP fetch involved. |
| All `GET` listing endpoints | **unchanged** | `/custom/links`, `/online-ordering/entries`, etc. |
| All `DELETE` endpoints | **unchanged** | Synchronous, no enrichment. |
| Instagram connect | **unchanged** | Already async (2026-06-09); this JOB-1 follows the same pattern. |
