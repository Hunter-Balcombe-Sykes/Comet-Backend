# Handoff — bespoke platform connects go async (202 + poll)

**To:** frontend / dashboard
**From:** backend
**Date:** 2026-07-24
**Full contract:** `docs/frontend-contracts/2026-07-23-platform-connect-async.md` — this note is the
cover sheet, that document is normative. Read this first, then that.

---

## TL;DR

Six connect endpoints (plus one branch of a seventh) will start returning **`202` with a stub +
`statusUrl`** instead of `200` with the finished object. You poll the status URL until `ready`.

**You are not blocked and you cannot break anything by not acting.** The backend ships this
disabled. Every endpoint keeps its exact current behaviour until we flip a server-side env var, and
we won't flip it until you tell us you're ready. There is no deploy to coordinate and no deadline
from our side — **but we can't turn it on until you've shipped the handling.**

Nothing is live today. The backend implementation is next up on our side.

---

## Why we're doing this

These six endpoints block on a third-party fetch while the user waits. Worst case is **96–288
seconds** in a single request. That's past any sensible HTTP timeout, and it pins a server worker
for the whole duration. Moving them to accept-then-poll is the same pattern you already implemented
twice: Instagram connect (2026-06-09) and link cards (2026-07-02). Same `statusUrl` convention, same
three poll states.

---

## The one rule that matters

> **Branch on the HTTP status code. Never on the platform slug.**

```
200 → still synchronous. Your existing code path, unchanged.
202 → activated. Read statusUrl, poll it.
4xx → inline error. Show the message. Same in both modes.
```

Do **not** hardcode a list of "async platforms." That set is server config, it changes without a
deploy, it differs per environment, and it can revert. A client that treats `200` and `202` as one
success case won't crash — it'll close the modal on an empty card that silently never fills in.

### Why both shapes exist at once

This is the part worth understanding, because it's different from the two previous conversions.

We activate **one platform group at a time** — `skool`, then the two Apple ones, then
Eventbrite + Humanitix, then Fresha — observing between each step. The lever is a comma-separated
slug list in a server env var, and the same lever is the kill switch: removing a slug restores the
synchronous path instantly, no deploy.

So during rollout, some platforms return `202` while others still return `200`, and an individual
platform can flip back if we roll it back. Any single request is deterministic — it's `200` or `202`
based on server config at that moment — but the *mapping* is not stable, which is exactly why the
status code is the only safe thing to branch on.

---

## Scope

| Endpoint | Today | After activation |
|---|---|---|
| `POST /api/platforms/apple/music/connect` | `200 {id, name, thumbnail, …}` | `202 {status, id, input, statusUrl}` |
| `POST /api/platforms/apple/podcast/connect` | `200 {id, name, thumbnail, …}` | `202 {status, id, input, statusUrl}` |
| `POST /api/platforms/skool/connect` | `200 {url, name, image, description}` | `202 {status, url, statusUrl}` |
| `POST /api/platforms/eventbrite/connect` | `200 {id, url, organiser, next, upcoming}` | `202 {status, id, url, statusUrl}` |
| `POST /api/platforms/humanitix/connect` | `200 {id, url, organiser, next, upcoming}` | `202 {status, id, url, statusUrl}` |
| `POST /api/platforms/fresha/connect` | `200 {url, mode, …}` | `202 {status, url, mode, statusUrl}` |
| `POST /api/platforms/events/add` | `200 {selection}` | `202` — **organiser/host branch only** |

Six new status endpoints, one per platform. `events/add` adds none — it reuses the Eventbrite and
Humanitix ones. **No existing endpoint is removed.** Request bodies are **unchanged** throughout.

For `events/add`, the single-event and plain-link branches stay synchronous `200`. Only the
organiser/host branch changes.

---

## Poll behaviour

Three states, and that's the complete set — there is no fourth.

- **`pending`** — keep polling.
- **`ready`** — stop, render `connection`. It's the same shape the old synchronous `200` returned.
- **`failed`** — stop, show `error` **verbatim**, offer retry.

A `404` from a status endpoint means no such resource *or* not yours. It is never a `403` — that
would leak existence.

### Cadence

- First poll **1.5 s** after the 202, then every **2 s**, hard cap **180 s** → treat as failed.
- ⚠️ **Do not copy the 20 s cap from the 2026-07-02 link-card contract.** That job is a ~10 s page
  fetch. These have a 45 s timeout with 2 retries, so a legitimately successful completion can take
  ~160 s. A 20 s cap would fail perfectly good connects.
- You also get a server-side backstop: any row stuck `pending` for over 5 minutes is independently
  flipped to `failed`, so a terminal state is guaranteed even if you poll past your own cap.

### The pending card is a stub — don't try to render it

Unlike the link-card conversion, where the 202 carried a display-safe minimal card with a domain and
favicon, **none of these six produces a usable card.** An Apple stub has only the string the user
typed; Skool and Eventbrite stubs have only the URL. `name`, `thumbnail`, `organiser`, `upcoming`
etc. are simply **absent** until `ready`.

Recommended UX is a spinner or skeleton in the card slot. Attempting to render the partial payload
as a card will look broken.

---

## Three gotchas worth reading twice

1. **Skool's error status code changes.** Today a vendor miss returns **HTTP 404** with the message
   in the body. After activation the request returns **202** and that same message arrives as a poll
   `failed`. If anything branches on Skool's 404 rather than reading the body, that branch stops
   firing.

2. **The poll collapses 404 and 422.** Eventbrite and Humanitix currently return 422 where Skool
   returns 404 for the equivalent "couldn't read that" condition. Both become `failed` in the poll.
   That distinction is deliberately not preserved — read `error`, don't infer from a code.

3. **Some errors stay synchronous.** The 5-account cap still returns an immediate **422**
   (`"You can connect up to 5 accounts."`), and lock contention still returns **423**. Both are
   checked *before* the 202, so your existing inline-error handling for these must stay.

---

## What we need from you

1. Handle `202` on the seven endpoints above — read `statusUrl`, poll it.
2. Render the three poll states, with `error` shown verbatim and a retry affordance.
3. Spinner/skeleton for the pending window, not a partial card.
4. Keep the `200` path working — both shapes are live simultaneously during rollout.
5. Preserve inline handling for the synchronous 422 / 423 cases.

Then tell us, and we'll start activating one platform group at a time. We'd suggest starting with
`skool` — smallest surface, single selection, one row — so the first observation window is cheap.

## What you need from us

Ask, and we'll get it. Likely useful:

- A dev environment with a specific slug activated so you can build against the real 202 before
  anything is on by default.
- The exact `connection` payload per platform if the `ready` shape isn't obvious from the contract.

## Where things stand

- Contract signed off 2026-07-23; **backend not yet built** — it's next in our queue.
- It merges **dark**: shipped, deployed, flag unset, every response byte-identical to today.
- Activation is per-platform whenever you're ready, and reversible in seconds.

The full normative contract, with every response body and poll table spelled out per platform, is
`docs/frontend-contracts/2026-07-23-platform-connect-async.md`.
