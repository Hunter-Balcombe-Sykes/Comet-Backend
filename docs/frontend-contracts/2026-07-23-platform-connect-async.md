# Frontend contract change — bespoke platform connects become async (202 + poll)

**Status:** design and scope **signed off 2026-07-23** — **not yet built**. Published early so the
frontend can plan; nothing in this document is live today. Backend lands dark ahead of the frontend
(see Rollout), so there is no deploy to coordinate — only an env var, flipped when you're ready.
**Pattern:** mirrors the Instagram connect 202 + poll (2026-06-09) and the link-card 202 + poll
(2026-07-02). Same three-state poll, same `statusUrl` convention.
**Design:** `docs/superpowers/specs/2026-07-23-platform-connect-async-design.md`.

> **Read the rollout section first.** Unlike the two previous async conversions, this one ships
> **per-platform behind a server-side flag**. Every endpoint below keeps its current synchronous
> behaviour until its slug is activated, and both shapes are live at once during rollout. The
> frontend must handle a mixed state.

---

## Summary

Six connect endpoints that currently block on a vendor fetch (96 s–288 s worst case) will return
**202 immediately** with a URL- or input-derived stub. A background job (`ConnectFetchJob`, queue
`platform_connect`) completes the row, and a per-platform **status URL** reports progress.

| Endpoint | Today | After activation |
|---|---|---|
| `POST /api/platforms/apple/music/connect` | `200 {id, name, thumbnail, …}` | `202 {status:'pending', id, input, statusUrl}` |
| `POST /api/platforms/apple/podcast/connect` | `200 {id, name, thumbnail, …}` | `202 {status:'pending', id, input, statusUrl}` |
| `POST /api/platforms/skool/connect` | `200 {url, name, image, description}` | `202 {status:'pending', url, statusUrl}` |
| `POST /api/platforms/eventbrite/connect` | `200 {id, url, organiser, next, upcoming}` | `202 {status:'pending', id, url, statusUrl}` |
| `POST /api/platforms/humanitix/connect` | `200 {id, url, organiser, next, upcoming}` | `202 {status:'pending', id, url, statusUrl}` |
| `POST /api/platforms/fresha/connect` | `200 {url, mode, …}` | `202 {status:'pending', url, mode, statusUrl}` |
| `POST /api/platforms/events/add` — **organiser branch only** | `200 {selection}` | `202 {status:'pending', selection, statusUrl}` |

Six **new** status endpoints, one per platform. `events/add` adds none of its own — it reuses the
Eventbrite and Humanitix ones. No existing endpoint is removed.

---

## Per-endpoint details

### 1. Apple Music — `POST /api/platforms/apple/music/connect`

Request — **unchanged**: `{ "artist": "Radiohead" }`

#### 202 response

```json
{
  "status": "pending",
  "id": "acct-9f2c1b74e8a03d55",
  "input": "Radiohead",
  "statusUrl": "https://api.partna.au/api/platforms/apple/music/connect/status?account=acct-9f2c1b74e8a03d55"
}
```

`input` is the string the user submitted, echoed back. `name`, `thumbnail`, `releaseDate`, `link`,
`latest` are **absent** until `ready` — there is no artwork or title to show during the pending
window.

#### Status poll — `GET /api/platforms/apple/music/connect/status?account={id}`

| Status | Body | Meaning |
|---|---|---|
| `200` | `{"status":"pending"}` | Job still running — keep polling. |
| `200` | `{"status":"ready","id":"acct-…","connection":{…}}` | Done. `connection` is the same shape the synchronous `200` returned. Stop polling. |
| `200` | `{"status":"failed","error":"Could not find that Apple Music artist or an album."}` | Terminal. Show `error` verbatim; offer retry. |
| `404` | `{"message":"Account not found."}` | No such resource, **or** not the caller's. Never 403 — no existence leak. |

### 2. Apple Podcast — `POST /api/platforms/apple/podcast/connect`

Request — **unchanged**: `{ "show": "The Rest Is History" }`

Identical to Apple Music in every respect except the field name (`show`), the path segment
(`/podcast/`), and the failure string, which is
`"Could not find that Apple Podcast or an episode."`

### 3. Skool — `POST /api/platforms/skool/connect`

Request — **unchanged**: `{ "url": "https://www.skool.com/some-community" }`

#### 202 response

```json
{
  "status": "pending",
  "url": "https://www.skool.com/some-community",
  "statusUrl": "https://api.partna.au/api/platforms/skool/connect/status"
}
```

No `account` segment — Skool is single-selection, one row per user.

#### Status poll — `GET /api/platforms/skool/connect/status`

| Status | Body | Meaning |
|---|---|---|
| `200` | `{"status":"pending"}` | Keep polling. |
| `200` | `{"status":"ready","connection":{"url":"…","name":"…","image":"…","description":"…"}}` | Done. Same shape as the old `200`. |
| `200` | `{"status":"failed","error":"Could not read that Skool community — check the URL."}` | Terminal. |
| `404` | `{"message":"Account not found."}` | No Skool connection for this user. |

> ⚠️ **Status-code change for Skool.** Today a vendor miss returns **HTTP 404** with that message in
> the body. After activation the request returns **202** and the same message arrives as a poll
> `failed`. If any client branches on the 404 status code rather than reading the body, that branch
> stops firing. Eventbrite and Humanitix return 422 for the same condition — the poll shape
> deliberately collapses both to `failed`, so the 404-vs-422 distinction is not preserved.

### 4. Eventbrite — `POST /api/platforms/eventbrite/connect`

Request — **unchanged**: `{ "url": "https://www.eventbrite.com/o/some-organiser-12345" }`

#### 202 response

```json
{
  "status": "pending",
  "id": "acct-4d81f0aa93c26e17",
  "url": "https://www.eventbrite.com/o/some-organiser-12345",
  "statusUrl": "https://api.partna.au/api/platforms/eventbrite/connect/status?account=acct-4d81f0aa93c26e17"
}
```

`organiser`, `next` and `upcoming` are absent until `ready`.

#### Status poll — `GET /api/platforms/eventbrite/connect/status?account={id}`

| Status | Body | Meaning |
|---|---|---|
| `200` | `{"status":"pending"}` | Keep polling. |
| `200` | `{"status":"ready","id":"acct-…","connection":{"url":"…","organiser":{…},"next":{…},"upcoming":[…]}}` | Done. |
| `200` | `{"status":"failed","error":"Could not load that Eventbrite page."}` | Terminal. |
| `404` | `{"message":"Account not found."}` | Not found, or not the caller's. |

**The 5-account cap still returns a synchronous `422`** — `{"message":"You can connect up to 5 accounts."}`.
It is checked before the 202, not after. Same for the 423 lock-contention response.

### 5. Humanitix — `POST /api/platforms/humanitix/connect`

Identical to Eventbrite in shape. Failure string:
`"Could not load that Humanitix page."`

### 6. Tickets & Events smart-detect — `POST /api/platforms/events/add`

Request — **unchanged**: `{ "url": "<any event, organiser or link URL>" }`

**Only the organiser/host branch changes.** This endpoint detects what you pasted and takes one of
three paths; two of them are untouched:

| What you pasted | Behaviour | Changed? |
|---|---|---|
| A single **event** URL | Adds just that event | **No** — still synchronous `200` |
| An **organiser / host** URL | Connects the account | **Yes** — becomes `202` |
| Anything else | Stored as a custom link card | **No** — still synchronous `200` |

This mirrors how `booking/detect` and `reservations/detect` changed in the 2026-07-02 contract: one
branch of one endpoint, with the rest byte-identical.

#### 202 response (organiser branch only)

```json
{
  "status": "pending",
  "selection": {
    "accounts": [ … ],
    "events": [ … ]
  },
  "statusUrl": "https://api.partna.au/api/platforms/eventbrite/connect/status?account=acct-4d81f0aa93c26e17"
}
```

`selection` is the **full unified list**, same shape as `GET /api/platforms/events/selection`, and it
already includes the new pending account so the list never appears to lose an entry mid-connect. The
pending account's `organiser`, `next` and `upcoming` fill in on `ready`.

`statusUrl` points at the **underlying platform's** status endpoint — `eventbrite` or `humanitix`,
whichever was detected. There is no separate `events/*` status endpoint; the row written here is an
ordinary account row and shares the poll described in §4 and §5.

#### Status poll

Use the platform status endpoint given in `statusUrl`. States, bodies and the 404 rule are exactly as
documented in §4 (Eventbrite) / §5 (Humanitix).

On `ready`, refresh the unified list with `GET /api/platforms/events/selection` rather than trying to
splice `connection` into the cached `selection` — the unified list merges accounts, standalone events
and custom cards with its own ordering, and re-reading it is cheaper than reproducing that logic
client-side.

### 7. Fresha — `POST /api/platforms/fresha/connect`

Request — **unchanged**: `{ "url": "https://www.fresha.com/a/some-salon" }`

#### 202 response

```json
{
  "status": "pending",
  "url": "https://www.fresha.com/a/some-salon",
  "mode": "team",
  "statusUrl": "https://api.partna.au/api/platforms/fresha/connect/status"
}
```

`mode` is `"team"` or `"storewide"` and is **known at 202 time** — it comes from the account's
capability, not from the vendor. The frontend can branch on it immediately without waiting for the
poll. `storeName`, `team` and `services` are absent until `ready`.

#### Status poll — `GET /api/platforms/fresha/connect/status`

| Status | Body | Meaning |
|---|---|---|
| `200` | `{"status":"pending"}` | Keep polling. |
| `200` | `{"status":"ready","connection":{"url":"…","mode":"team","storeName":"…","team":[…],"services":[…]}}` | Done. Same shape as the old `200`. |
| `200` | `{"status":"failed","error":"We couldn't read that Fresha page just then — please try again."}` | Terminal. |
| `404` | `{"message":"Account not found."}` | No Fresha connection for this user. |

**All of Fresha's current 4xx responses stay synchronous and unchanged**, because none of them ever
depended on the vendor fetch:

| Status | Body | When |
|---|---|---|
| `403` | `{"message":"Booking is not available for your account."}` | Capability check |
| `409` | `{"message":"Disconnect Square before connecting Fresha — only one booking provider can be active at a time."}` | Square XOR |
| `422` | validation error | URL fails the `fresha.com/…/a/<slug>` regex |
| `423` | `{"message":"Another change is still saving — please retry in a moment."}` | Lock contention |

This is the cleanest of the six: **the only thing that moves behind the poll is what used to be a
generic `502`.** Today those already render as `{"message":"An error occurred"}` in production, so no
message the user currently reads is lost.

### 8. Shop — `POST /api/platforms/shop/brands`

Request — **unchanged**: `{ "url": "https://store.example.com" }` (the optional `discountCode` and the
client-assisted `storeApi` payload are unaffected).

> ⚠️ **Status code is provider-dependent — read this before anything else in this section.** Once
> `shop` is in `PARTNA_CONNECT_DEFERRED`, `POST /brands` returns **either `202` or `200` for the same
> request shape.** No other endpoint in this contract does that. The choice is made by which store
> platform detection found:

| Detected provider | Response | Why |
|---|---|---|
| `shopify`, `woocommerce`, `squarespace` | **202** + poll | the brand profile fetch is deferred to a job |
| `bigcartel`, `generic`, client-assisted (`storeApi`) | **200** + complete brand | the profile is already in memory at response time — there is nothing to defer |

This is deliberate, not an inconsistency: returning `202 {"status":"pending"}` for a brand that is
already complete would be a lie, and the rule already published above already covers it —
**branch on the status code, never on the platform slug, and never assume Shop returns `202`.** A
client that treats every `POST /brands` response the same way will render a stub spinner over a brand
that has, in fact, already finished.

#### 202 response (shopify / woocommerce / squarespace only)

```json
{
  "id": "9f2c1b74e8a03d55",
  "provider": "shopify",
  "url": "https://store.example.com",
  "name": null,
  "currency": "AUD",
  "favicon": null,
  "logo": null,
  "discountCode": "",
  "selectionMode": "manual",
  "linkMode": "product",
  "referralQuery": "",
  "individual": false,
  "products": [],
  "connectStatus": "pending",
  "status": "pending",
  "statusUrl": "https://api.partna.au/api/platforms/shop/brands/9f2c1b74e8a03d55/connect/status"
}
```

This is the **full brand object**, not the trimmed `identify()`-only subset the other six carry in
their 202 body. That is deliberate: the other six strip nulls because an explicit `null` there means
"confirmed absent," and there is nothing useful to render yet. Shop's stub already has a URL, a
provider, and a **working product picker** — `GET /brands/{id}/products` succeeds immediately, see
below — so it is a renderable card, not a placeholder. `name`, `currency`, `favicon` and `logo` are
`null` exactly where the profile hasn't landed. `currency` is the one exception for Shopify: it's
resolved synchronously during detection, so a Shopify stub already carries its real currency.

#### 200 response (bigcartel / generic / client-assisted)

The same brand object, complete, with **no** `status`/`statusUrl` envelope and **no** `connectStatus`
key — byte-identical to what this endpoint returns today, deferred or not.

#### Status poll — `GET /api/platforms/shop/brands/{id}/connect/status`

Legacy alias, same handler: `GET /api/platforms/shopify/brands/{id}/connect/status`.

| Status | Body | Meaning |
|---|---|---|
| `200` | `{"status":"pending"}` | Job still running — keep polling. |
| `200` | `{"status":"ready","id":"<brandId>","brand":{…}}` | Done. `brand` is the same object `addBrand` returns synchronously today. |
| `200` | `{"status":"failed","error":"<sentence>","brand":{…}}` | Terminal. Show `error`; the brand is present and usable — see below. |
| `404` | `{"message":"Brand not found."}` | Not found, or not the caller's. Never `403` — matches every other Shop 404. |

Recommended cadence matches the recommendation above, unchanged: Shop's job carries the identical
timeout/backoff shape as `ConnectFetchJob` (45 s timeout, 3 tries, `[5, 20]` backoff), so the same
180 s cap applies. There is no Shop-specific cadence.

**Two deliberate divergences from the other six, both frontend-visible:**

1. **The ready payload is keyed `brand`, not `connection`.** The other six poll a connection row; Shop
   polls a brand, a sub-resource of the connection. `GET /brands` already returns `{"brands":[…]}` and
   `addBrand` already returns a bare brand object, so `brand` is the shape this contract already
   speaks — inventing `connection` here would be the actual inconsistency.
2. **`failed` still carries `brand`.** For the other six, `failed` means there is nothing to render.
   For Shop the brand is fully usable even in the failed state — `brand_id`, `provider`, `url` and
   `source_url` are all truthful, the product picker works, only the display profile is missing —
   so withholding it would force a needless refetch.

#### During the pending window

- **`GET /brands/{id}/products` works normally.** This is guaranteed by design, not incidental —
  `provider`, `url`, `source_url` (and, for Shopify, `currency`) are all written truthfully at 202
  time, so the picker never 404s or degrades while a brand is pending.
- `connectStatus` and `connectError` appear on the brand object — in `GET /brands`,
  `PATCH /brands/{id}`, and everywhere else a brand is rendered — **only when non-null**. A settled
  brand never carries these keys at all; do not treat their absence as `false`.
- **`productsCuratedAt` (#SEM-1, new)** appears on the brand object — same everywhere-a-brand-is-rendered
  rule, **only when non-null** — an ISO-8601 timestamp of the moment the user last hand-picked this
  brand's products via `PUT /brands/{id}/selection`. Its presence means the scheduled auto-latest sync
  is now **skipping this brand indefinitely**, even while the account's global auto-latest is on: the
  picker should surface something like "auto-updates paused — you picked these on {date}" with a way to
  resume. The only way back to auto-tracking is `PATCH /brands/{id} {"selectionMode":"latest"}`, which
  clears this field and immediately re-syncs to the store's newest products. **There is currently no
  dashboard affordance that sends `selectionMode` at all** — until one exists, a curated brand's product
  data (price/availability/image) will not refresh even though membership won't be clobbered.
- A **pending** brand is omitted from the public sitepage payload — on its own it cannot cause a Shop
  page to appear, and it will not ship as an empty card once another brand's products already make the
  page live. A **failed** brand is **not** omitted: its content is identical to what a failed homepage
  fetch already produces today, so this is pre-existing behaviour, not something new.
- **5-minute stale-pending backstop.** A row stuck `pending` with `updated_at` older than 5 minutes
  polls as `{"status":"failed","error":"We couldn't save your connection just then — please try
  again."}` — the same shared infrastructure sentence used across every platform in this contract.
  This is **synthetic**: it does not write the row, so a merely-slow (not dead) worker can still land
  its real result afterwards, and the next poll reports `ready`.

#### What did NOT change (Shop)

`GET /brands`, `GET /selection`, `PUT /brands/{id}/selection`, `POST /products`, `PATCH /brands/{id}`,
and every `DELETE` path are all unchanged — same status codes, same bodies, same behaviour as today.

---

## How a deferred failure reaches the user

This is the part most likely to be got wrong, so it is stated explicitly.

Today a vendor miss is a synchronous error body — the user sees
*"Could not find that Apple Music artist or an album."* as the response to their POST. After
activation the request has **already returned 202** before the fetch runs, so that string cannot be
delivered as a response body.

The backend stores it instead. Each platform declares its message on the descriptor via
`connectFetchError(...)`; on an expected fetch failure the job writes that exact string to the
connection row, and the status endpoint returns it verbatim as `error`. **Same words, different
transport.**

Consequences for the client:

- `error` is always a complete, user-facing sentence. **Display it verbatim.** It never contains
  scraper internals, stack traces or vendor detail.
- Do not map `error` back onto a status code — the 404-vs-422 distinction that existed synchronously
  is not preserved through the poll (see the Skool note above).
- Two `error` strings are **infrastructure**, not vendor misses, and are shared across all platforms:
  `"We couldn't save your connection just then — please try again."` (the row was stranded — see
  staleness below) and `"We could not load that account. Please try again."` (unhandled job failure).
  Both are safe to display and both warrant an obvious retry affordance.

---

## Poll contract (all six endpoints)

The three `status` values are the complete set. There is no fourth state.

- **`pending`** — keep polling.
- **`ready`** — stop; render `connection`.
- **`failed`** — stop; show `error`; offer retry.

### Recommended cadence

- First poll: **1.5 s** after the 202.
- Subsequent polls: every **2 s**.
- Hard cap: **180 s**, then treat as failed.

Why 180 s rather than the link-card contract's 20 s: `ConnectFetchJob` has a 45 s timeout with
`tries: 3` and `backoff: [5, 20]`, so a worst-case successful completion after two retries is
~160 s. Do **not** copy the 20 s cadence from the 2026-07-02 contract — that job is a ~10 s page
fetch and these are not.

### Server-side staleness backstop

If a worker dies mid-flight, the row would otherwise poll `pending` forever. The status endpoint
independently flips any `pending` row untouched for **more than 5 minutes** to
`{"status":"failed","error":"We couldn't save your connection just then — please try again."}`.
So a terminal state is guaranteed even if the client polls past its own cap.

### The pending card is a stub, not a usable card

Unlike the link-card conversion — where the 202 body carries a display-safe minimal card with a
domain name and favicon — **none of these six produces a useful pending card.** An Apple stub has
only the user's typed string; a Skool or Eventbrite stub has only the URL. Recommended UX is a
spinner or skeleton in the card slot, not an attempt to render the partial payload as a card.

---

## Rollout and kill switch

**Activation is per-platform, server-side, no deploy.** The lever is the env var
`PARTNA_CONNECT_DEFERRED`, a comma-separated list of platform slugs. A platform behaves
**exactly as it does today** until its slug appears in that list. The same lever is the kill switch:
removing a slug restores the synchronous path immediately.

This means **both shapes are live at once during rollout**, and the frontend must handle a mixed
state. The rule is simple and stable:

```
200 → platform is still synchronous. Existing code path, unchanged.
202 → platform is activated. Read statusUrl, poll it.
4xx → inline error. Show the message. Unchanged in both modes.
```

**Branch on the status code, never on the platform slug.** Do not hardcode which platforms are async
— that set changes by env var without a deploy, and per environment. A client that treats `200` and
`202` as the same success case will not crash, but it will close the modal on a stub card that never
fills in; handling `202` explicitly is required for correct behaviour.

**Activation order — decided 2026-07-23:**

| Order | Slugs | Why here |
|---|---|---|
| 1 | `skool` | Smallest blast radius; single selection, one row per user, one endpoint. |
| 2 | `apple-music`, `apple-podcast` | Two slugs, one shape; no chaining between them. |
| 3 | `eventbrite`, `humanitix` | Multi-account, and shares its poll endpoints with `events/add`. |
| 4 | `fresha` | Largest surface, capability-gated, and the only booking-adjacent platform. |

**No slug is activated before this contract ships in the frontend — decided, not merely recommended.**
Because none of the six renders a useful pending card, activating early degrades UX with no
offsetting benefit. (This differs from the eight registry platforms, where Spotify's pending card is
fully functional and *can* be flipped ahead of the frontend.)

The backend work merges **dark** ahead of the frontend: every unit lands with the flag unset, so
`development` can carry the whole change set while every response stays byte-identical. There is no
deploy coordination to manage — only the env var, flipped when the frontend is ready.

---

## What did NOT change

| Endpoint | Status | Notes |
|---|---|---|
| `GET /api/platforms/shop/brands` | **unchanged** | Pure DB read, no vendor fetch. |
| `GET /api/platforms/shop/selection` | **unchanged** | Pure DB read. |
| `POST /api/platforms/shop/brands` | **changed — provider-dependent** | Returns `202` (shopify/woocommerce/squarespace) or `200` (bigcartel/generic/client-assisted) for the same request shape. Full detail in §8, not summarised here. |
| `PUT /api/platforms/shop/brands/{id}/selection` | **unchanged** | Wire contract unchanged — an internal lock restructure only (the vendor fetch moved outside the connection lock); no new status code, no new keys. |
| `POST /api/platforms/shop/products` | **unchanged** | Single fetch. |
| `GET /api/platforms/fresha/selection` | **unchanged** | Pure DB read. |
| `POST /api/platforms/fresha/selection` | **unchanged** | Still synchronous; still re-fetches live. |
| `GET /api/platforms/skool/selection` | **unchanged** | Pure DB read. |
| `POST /api/platforms/eventbrite/events` | **unchanged** | Standalone events stay synchronous — the row's identity is derived from the fetched page, so a pending row cannot be keyed correctly. |
| `POST /api/platforms/humanitix/events` | **unchanged** | Same reason. |
| `POST /api/platforms/events/add` — event branch | **unchanged** | A pasted single-event URL still returns synchronous `200`. |
| `POST /api/platforms/events/add` — custom branch | **unchanged** | A pasted non-event link still returns synchronous `200`. |
| `GET /api/platforms/events/selection` | **unchanged** | Pure DB read. This is what you re-fetch on `ready`. |
| `DELETE /api/platforms/events/custom/{id}` | **unchanged** | |
| `POST /api/platforms/instagram/connect` | **unchanged** | Already async since 2026-06-09. |
| The 8 registry platforms | **unchanged** | `spotify`, `bandcamp`, `twitch`, `pinterest`, `strava`, `vimeo`, `youtube`, `youtube-music` already have `202` + `/connect/status` built behind the same flag — see the 2026-07-20 plan §2e. |
| All `GET` listing endpoints | **unchanged** | |
| All `DELETE` endpoints | **unchanged** | Synchronous, no vendor fetch. |

### Not yet specified

**`GET /api/platforms/fresha/team`** is network-bound (~96 s) and re-scrapes on every call. Making
`connect()` async does **not** fix it. The intended fix serves the stored snapshot immediately at
`200` with the current body shape and refreshes in the background — no `202`, no new keys, just
faster and eventually consistent. That work is scheduled separately and its contract is not final;
this note exists so the endpoint is not assumed unchanged.
