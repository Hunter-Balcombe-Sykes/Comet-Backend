# Frontend Handoff — Signup Flows & Early Access

**Date:** 2026-07-21
**Backend branch:** `feature/signup-flows-early-access` (Partna backend)
**Audience:** Frontend developer (partna-frontend)
**Status:** Backend complete + tested; needs frontend work in 3 areas before the backend deploys.

---

## TL;DR — what changed and what you need to do

The backend now supports **three signup flows** that all share one "build a site → claim it" engine. Two things are new that need frontend work, and one is a **breaking change to the existing early-access form**:

1. 🔴 **BREAKING — Early-access form:** it must now send a real **`source_type` + `source_ref`** (an Instagram handle or a Google Business place). Without them the endpoint returns **422**. **The current live form will stop working the moment the backend deploys** — ship the form change together with (or before) the backend deploy.
2. 🟡 **New — Claim page** at `/claim/{subdomain}`: where a person takes ownership of a site we pre-built for them.
3. 🟡 **New — Staff dashboard:** "Allow claiming" (approve) buttons for early-access leads, + a preview of the pre-built site. **Stop using the old `/staff/early-access/invite` endpoint for early-access rows** (see §5).

---

## The three flows (mental model)

We build a real but **provisional** site for someone *before* they have an account, then they **claim** it (prove who they are → the account becomes theirs → the site goes live).

| Flow | Who starts it | Site goes live… | Notified via | Who can claim |
|------|---------------|-----------------|--------------|---------------|
| **1. Normal signup** | the visitor | on claim | (self-serve) | first-come (they claim in-session) |
| **2. Cold / ManyChat** | staff/marketing | immediately (the hook) | ManyChat DM + email (if we have one) | first-come, OR email-locked if we have their email |
| **3. Early access** | the visitor, then staff approves | on claim | email | **email-locked** to their signup email |

The **claim page is the same** for all flows — it's keyed on the site's subdomain.

---

## 1. Early-access form — `POST /api/public/early-access`

**This is the breaking change.** The form now captures a resolvable source and we build a (dark, unpublished) site from it immediately.

**Request body:**
```json
{
  "email": "person@example.com",
  "type": "partna",                 // "partna" | "business"
  "platforms": ["instagram", "tiktok"],  // 2–3 strings, still captured (analytics)
  "source_type": "instagram",       // REQUIRED — "instagram" | "google_business"
  "source_ref": "theirhandle",      // REQUIRED — the IG handle (no @) OR a Google place_id
  "website": "",                    // honeypot — leave empty/hidden (bot trap)
  "form_started_at_ms": 1721540000000  // optional timing anti-bot signal
}
```

**Source pairing (must match `type`):**
- `type: "partna"` → `source_type: "instagram"`, `source_ref` = the Instagram handle.
- `type: "business"` → `source_type: "google_business"`, `source_ref` = the Google **place_id**.

**Response:** always `200 { "ok": true }` — deliberately uniform whether the email is new or already on the list (anti-enumeration). Don't infer state from the response. Throttled (`throttle:early-access`).

**Notes:**
- A malformed handle still returns 200 (the lead is captured); staff see the row and can correct it. So basic client-side validation of the handle format is a nice-to-have, not a gate.
- The person is **not** emailed yet at signup — they're emailed only when **staff approve them** (§4).

---

## 2. Claim page — `POST /api/claim`

The person receives a link to **`{frontend}/claim/{subdomain}`** (backend builds this URL; make sure this route exists). Your claim page:

1. Reads `{subdomain}` from the URL.
2. Has the user authenticate via **Supabase email-OTP** (this is how they prove they control their email).
3. Calls `POST /api/claim` with the Supabase JWT in the auth header.

**Request body:**
```json
{ "subdomain": "theirhandle", "marketing_opt_in": false }
```
- **Email is read from the verified JWT only** — never send it in the body (it's ignored).
- `marketing_opt_in` defaults to `false` (fail-closed) if omitted.

**Success `200`** — bootstrap-shaped payload; land them straight in the dashboard:
```json
{ "professional": { "id": "...", "display_name": "...", "status": "active", ... },
  "site": { "id": "...", "subdomain": "...", ... } }
```
On success the **site auto-publishes** — no separate "publish" step needed for a claimed site.

**Error codes** (all put `code` at the top level of the body):
| HTTP | `code` | Meaning / what to show |
|------|--------|------------------------|
| 422 | `EMAIL_VERIFICATION_REQUIRED` | JWT had no verified email — re-run OTP. |
| 409 | `CLAIM_EMAIL_MISMATCH` | 🔑 **Early-access sites are email-locked.** They signed in with a different email than they signed up with. Show: "Please sign in with the email you used to sign up." |
| 404 | `CLAIM_NOT_FOUND` | No site for that address. |
| 409 | `ALREADY_CLAIMED` | Someone already claimed it. |
| 409 | `BUILD_FAILED` | The site couldn't be built — retry / contact support. |
| 409 | `ACCOUNT_EXISTS` | This login already owns a site. |
| 409 | `EMAIL_ALREADY_REGISTERED` | This email is already on another account. |

`CLAIM_EMAIL_MISMATCH` is the one truly new code — it only fires for email-locked builds (early access, and cold builds where we had a verified contact email). First-come builds never hit it.

---

## 3. Staff builds (Flow 2) — `POST /api/staff/builds` *(mostly unchanged)*

Cold/ManyChat builds. One new optional field: **`contact_email`** — if provided, we email the person a claim link (in addition to the DM) and the claim becomes email-locked to that address.

```json
{ "account_type": "partna", "source_type": "instagram", "source_ref": "handle",
  "source_name": null,          // REQUIRED when source_type = "google_business"
  "publish": true,              // staff default; the site goes live immediately
  "expires_days": 30,
  "contact_email": "person@example.com"  // NEW, optional
}
```
Returns `202` (or `200` if an existing live build for that handle was re-served).

---

## 4. Staff dashboard — approve early-access leads (NEW)

Early-access leads sit as **pre-built, unpublished** sites waiting for staff to "allow claiming." Two new endpoints (both **admin-only**, staff JWT + AAL2):

**Single:** `POST /api/staff/early-access/{signup}/approve`
→ `202 { "ok": true }`. Dispatches a job that re-scrapes (Instagram) for freshness, opens the 30-day claim window, and **emails the person their claim link**.

**Bulk:** `POST /api/staff/early-access/approve-bulk`
```json
{ "ids": ["uuid1", "uuid2"] }        // explicit list (max 500)
// — OR —
{ "all_waitlisted": true }            // approve every waitlisted lead
```
→ `202 { "dispatched": <count> }`.

**Listing / preview:** `GET /api/staff/early-access` lists the leads (existing endpoint). The pre-built sites are **unpublished**, so to preview one before approving you'll render from the linked site's build data (coordinate with backend on the exact preview payload if the current staff list doesn't expose enough).

**Lead lifecycle status** (on each early-access row): `waitlist` (built, awaiting approval) → `invited` (approved + emailed) → `signed_up` (claimed).

---

## 5. ⚠️ Do NOT use the old invite endpoint for early-access

`POST /api/staff/early-access/invite` is the **old, retired** invite path (it sent a now-dead `/signup?invite=...` link). It does **not** build, re-scrape, open a claim window, or send a working claim link. For early-access leads, always use the **approve** endpoints in §4. (The backend now defensively skips the old invite for build-linked rows, but the dashboard should simply not call it for early access.)

---

## 6. Deploy sequencing (important)

- The early-access form change (§1) is a **breaking contract change**. If the backend deploys first, every submission from the current form **422s** and top-of-funnel capture goes dark until the form ships. **Ship the form update with, or ahead of, the backend deploy.**
- The claim page (§2) and staff approve UI (§4) are **additive** — they can ship anytime after the backend deploy without breaking anything.

---

## 7. What you do NOT need to worry about

- **ManyChat DM sending** — that's a deferred backend integration seam. For now the claim link reaches people via email (early access / cold builds with a contact email). No frontend work.
- **Publishing a claimed site** — claiming auto-publishes; there's no separate publish call for the claim flow.
- **The refresh/freshness of scraped content** — handled backend-side (early-access sites are re-scraped at staff approval; the rest ride the normal refresh cycle).

---

## Questions

Backend contact: Josh. The full backend spec + implementation plan live in the backend repo under `docs/superpowers/specs/2026-07-21-signup-flows-and-early-access-design.md` and `docs/superpowers/plans/2026-07-21-signup-flows-and-early-access.md`.
