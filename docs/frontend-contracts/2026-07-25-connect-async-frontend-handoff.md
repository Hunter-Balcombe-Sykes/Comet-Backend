# Frontend handoff — implement the bespoke-connect 202→poll (unblocks Connect Phase 4)

**Date:** 2026-07-25 · **From:** backend · **For:** frontend
**Wire contract (byte-level source of truth):** `docs/frontend-contracts/2026-07-23-platform-connect-async.md`
**This doc:** the implementation guide — what to build, where, and how to test it.

---

## TL;DR

The backend for the async connect rollout is **merged and deployed dark** on `development`. Turning it
on (Phase 4) is blocked on **one frontend gap**: the generic connect modal treats a `202` as instant
success and never polls, so a flipped platform would show "Connected" over an empty stub and would never
surface an expected `failed`.

**Your job:** give the bespoke connect modal the same `202 → poll → ready/failed` behaviour Instagram
already has. Ship to `main`, deploy. Then Josh flips platforms on, one group at a time, server-side, with
no per-flip frontend deploy.

**You can build and test this today** — see "Test against a live 202 now" below.

---

## The gap (what's wrong today)

- `app/(app)/account/(dashboard)/integrations/connect-modals.tsx:294-315` — `SingleInputConnectModal.submit()`
  awaits the POST and unconditionally `setSucceeded(true)` → "Connected — it's now on your site" → redirect.
  It never inspects the response body.
- `lib/backend-account.ts:149,164` — `authedJsonRequest` throws **only** on non-2xx and otherwise returns
  `result.payload` (the parsed body). A `202` is 2xx, so it resolves *silently* with `{status:'pending', …}`
  and the modal declares success.

Consequence once a slug is flipped: the user sees "Connected" immediately; the card is a stub until some
later refetch; and an **expected** `failed` (e.g. a mistyped Apple artist — designed to resolve `failed`)
is never shown. That is why no bespoke slug can be flipped yet.

> **Key idiom:** `authedJsonRequest` does **not** expose the HTTP status on success. So the contract's
> "branch on the status code" means, in this codebase, **branch on `body.status === 'pending'`** (and/or the
> presence of `statusUrl`). This is exactly how Instagram does it.

---

## The template you already have — copy it

`lib/hooks/use-instagram-connect.ts` is a complete, clean 202-pending poll machine. Reuse its shape:

- `isPendingConnectBody(body)` (`:38`) — `body.status === 'pending'`. **This is your 202 detector.**
- `connect()` (`:150`) — POST, then `if (isPendingConnectBody(body)) { setStep('polling'); pollUntilReady() }`
  else treat the body as a synchronous success.
- `pollUntilReady()` (`:116`) — loop: GET the status endpoint; `ready` → cache the result + finish;
  `failed` → show error + back to input; loop exhausted → timeout error. Transient network errors keep polling.
- Background-resume probe (`:186`) — on mount, while idle + unconnected, probe the status endpoint once and
  resume polling if it's still `pending` (covers a reload mid-pending). Nice-to-have for v1, not required.

---

## What to build — the six bespoke platforms

Generalise that machine for **skool · apple-music · apple-podcast · eventbrite · humanitix · fresha**
(all flow through `SingleInputConnectModal` today). Concretely:

1. After the connect POST, if `body.status === 'pending'`, enter a polling/spinner state **instead of** success.
2. Poll the status endpoint. **Use `body.statusUrl` from the 202** rather than hardcoding paths — it already
   encodes the `?account=…` query that eventbrite/humanitix need.
   - ⚠️ **Gotcha:** `statusUrl` is an **absolute** URL (`https://api.partna.au/…`), but `authedJsonRequest`
     takes a path relative to the API base. Feed it `new URL(statusUrl).pathname + new URL(statusUrl).search`,
     or add an absolute-URL variant. (Instagram sidesteps this by hardcoding its one path; you have per-account
     URLs, so prefer `statusUrl`.)
3. Three terminal states (contract §"Poll contract"): `pending` → keep polling; `ready` → write the
   `connection` into the section cache + show success; `failed` → show `error` **verbatim** + offer retry.
4. **Cadence (contract §"Recommended cadence") — do NOT copy Instagram's 60 s cap.** First poll **1.5 s**,
   then every **2 s**, hard cap **180 s** then treat as failed. (`ConnectFetchJob` = 45 s timeout × 3 tries.)
5. `404` during poll = terminal "not found / not yours"; transient network errors keep polling.
6. Leave every 4xx synchronous: `422` (5-account cap), `409` (Square XOR), `423` (lock), `403` (capability),
   validation errors. They throw **before** the 202, and your existing `catch` already renders them.

Per-platform notes:

| Platform | Note |
|---|---|
| apple-music / apple-podcast | Free-text input → a `failed` poll is **expected** (mistyped name). Show the error + retry; not a bug. |
| eventbrite / humanitix | Multi-account; 202 carries `id`. Also wire `POST /platforms/events/add` **organiser branch** — it returns a 202 whose `statusUrl` points at the eventbrite/humanitix status endpoint. On `ready`, re-fetch `GET /platforms/events/selection` (don't splice). |
| fresha | 202 body carries `mode` (`team`\|`storewide`), known immediately — branch on it without waiting for the poll. |
| skool | Single-selection, status URL has no account param. Note the status-code change: a miss was `404`, now it's `202` then a `failed` poll. |

---

## Shop is separate — do it last

Different components (`store-connect-wizard.tsx`, `shop-brand-modals.tsx`, `products-section.tsx`) and
different rules (contract **§8**):

- **Provider-dependent:** shopify / woocommerce / squarespace → `202` + poll; bigcartel / generic /
  client-assisted → `200` complete. Detect via `body.status === 'pending'` (a 200 brand has no `status`
  key) — **never assume Shop returns 202.**
- `ready` payload is keyed **`brand`** (not `connection`); a `failed` payload **still carries a usable `brand`**.
- `GET /brands/{id}/products` works during the pending window — **don't block the product picker.**
- A 5-min stale backstop synthetically fails a stuck row; a pending brand is omitted from the public page,
  a failed one is not. Full detail in §8 — read it before touching the Shop wizard.

---

## Do NOT touch the registry platforms

`spotify · bandcamp · twitch · pinterest · strava · vimeo · youtube · youtube-music` already work when
flipped, through this same modal, because a registry pending card is functional and the index's
`refetchOnMount: 'always'` (`lib/queries/integrations.ts:27`) surfaces it. If you generalise the poll in the
shared modal it will also poll these — harmless, even an improvement — but they are **not** the blocker. The
six bespoke platforms + Shop are.

---

## Test against a live 202 now — no waiting

**Spotify is already flipped on `development`** (`PARTNA_CONNECT_DEFERRED=spotify`). So on `dev-api.partna.au`
**today**, `POST /platforms/spotify/connect` returns a real `202` with a `statusUrl`, and
`GET /platforms/spotify/connect/status` is live. Build and exercise the whole poll state machine against that
before Josh flips any bespoke slug — zero backend coordination needed.

---

## Definition of done

- Connecting a bespoke platform: POST → spinner/pending → poll → renders the real card on `ready`; shows the
  backend `error` **verbatim** + a retry affordance on `failed`; gives up at 180 s.
- Both eventbrite/humanitix entry points (own `connect` **and** `events/add` organiser) poll correctly.
- Shop's `202` path polls **and** the product picker works during pending; Shop's `200` path is unchanged.
- Registry platforms (incl. Spotify) still connect fine.
- No change to any 4xx behaviour.

## After you ship

Ship to `main` + deploy. Tell Josh. He re-verifies the frontend, then runs Phase 4 —
`skool → apple-music/apple-podcast → eventbrite/humanitix → fresha → shop` — each an env-var append + redeploy,
no frontend deploy per flip. Runbook: `docs/superpowers/plans/2026-07-24-connect-phase4-activation-PROMPT.md`.
