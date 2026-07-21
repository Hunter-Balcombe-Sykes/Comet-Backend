# Claim-Invite Outreach for Staff-Built Sites — Design

**Date:** 2026-07-21
**Status:** Approved design, pre-implementation
**Depends on:** Pre-account (site-first) signup — `docs/superpowers/specs/2026-07-18-pre-account-sites-design.md`

## 1. Problem

Staff can already build a published site for a prospect from their public presence via
`POST /api/staff/builds` (`StaffPreAccountBuildController::store` →
`PreAccountBuildService::requestBuild`). But nothing delivers that site's URL to the
prospect — the spec deliberately treats invitation as an out-of-band, human/ManyChat
step (pre-account spec §10). There is no field to record who to contact and no path to
email them.

This feature adds a first-party **email** invite: staff record a contact email when
building a site, review the generated site, then send a "here's your site, come claim
it" email that drops the prospect into the existing claim flow.

## 2. Scope & constraints

**In scope:** email invites for staff-built pre-account sites, single-entry and CSV
batch, manual send, minimal Spam Act compliance.

**Out of scope (deliberate):**
- **Instagram / Google Business DMs** — the backend structurally cannot send these. IG's
  Messaging API only permits replies inside a 24h window after the user messages first;
  GBP has no outbound business-to-consumer messaging API. Cold DMs remain a ManyChat /
  human task. This is a platform limitation, not a design choice.
- **Claim tokens / pinned invite links** — the claim flow is intentionally "first-come,
  any verified email" against an already-public site (pre-account spec left claim tokens
  out of scope). We reuse it unchanged.
- **Auto-send, scheduling, drip sequences, open/click tracking.**

**Operating mode:** warm, staff-curated contacts (prior touchpoint / consent). The design
carries a suppression + unsubscribe hook so a later move to colder lists is a policy
change, not a rebuild.

## 3. Data model

Schema changes are raw SQL in `supabase/migrations/` (never Laravel migrations — composer
guard rejects them).

### 3.1 `pre_account_builds` — two new columns

- `contact_email TEXT NULL` — the invite target. **Outreach metadata only**; distinct
  from the provisional user's `primary_email`, which stays NULL until claim and is set
  from whatever email the user verifies via OTP. The two are allowed to differ; none of
  the "unclaimed users are un-emailable" safety rules (pre-account spec §7) are weakened.
- `invited_at TIMESTAMPTZ NULL` — NULL = not yet sent. Set on successful send; doubles as
  the idempotency guard against re-sending.

`contact_email` follows the existing fillability posture: it is a plain staff-supplied
scalar (unlike `user_id` / `built_by_staff_id`, which are never fillable and set via
`associate()`).

### 3.2 `core.outreach_suppressions` — new table

```
email        TEXT PRIMARY KEY
suppressed_at TIMESTAMPTZ NOT NULL DEFAULT now()
```

Email-level (not build-level) on purpose: an unsubscribe must persist across **every**
future build for that person. A boolean on the build would not survive a re-build or
apply to a second site for the same contact.

## 4. Ingestion — one path, two front doors

### 4.1 Single entry (manual)

`contact_email` becomes an optional, validated field (`nullable|email`) on
`StaffCreatePreAccountBuildRequest`. The existing `store` endpoint gains it with no other
change — `PreAccountBuildService::requestBuild` persists it onto the build.

### 4.2 CSV batch

New `POST /api/staff/builds/batch`. Parses rows of:

```
source_type, source_ref, source_name, contact_email, account_type
```

and calls the **same** `PreAccountBuildService::requestBuild` per row in a loop. No
parallel build logic — CSV is strictly a loop over the single-entry path. Per-row failures
are collected and returned (row index + reason) so a bad row never aborts the batch. The
endpoint honours the existing build dedupe / one-live-build-per-source rule; it does not
bypass it.

Response: a summary resource — counts of built / skipped / failed, with per-row detail.

## 5. Sending — manual, per build

New `POST /api/staff/builds/{build}/invite`.

**Guards (all must pass, else 4xx with a specific code):**
- build `build_state = ready` and published (unpublished/unbuilt → 409)
- `contact_email` present (else 422)
- `invited_at` IS NULL (else 409 `ALREADY_INVITED`)
- `contact_email` NOT in `core.outreach_suppressions` (else 409 `SUPPRESSED`)

**On pass:** dispatch `ClaimInviteMail` to `contact_email` via the existing Resend
transport (same transport as `MagicLinkMail` / `EmailConfirmMail` in `app/Mail/Auth`),
then stamp `invited_at`. Stamp only after successful queue dispatch so a transport failure
leaves the build re-sendable.

Staff "review" needs no new screen — the site is already public at its URL; staff open it
before clicking send.

Authorization via policy (`authorizeForUser`, staff gate), never an inline 403 — consistent
with the staff controllers.

## 6. The invite email — `ClaimInviteMail`

Contents:
- the site URL (`<handle>.partna.au`) and a **"Claim your site"** CTA → lands on the
  published site, which already carries the email-OTP claim entry point
  (`ClaimController` / `ClaimSiteService`). No new claim machinery.
- **Sender identification** — Partna identity + a real reply/contact address (Spam Act
  requirement).
- **Unsubscribe link** — signed URL to the public unsubscribe endpoint (§7).

Reuses the app's existing mail layout/branding used by the auth mailables.

## 7. Compliance — minimal but real

Australian Spam Act 2003 requires, on every commercial send (warm included): clear sender
identification and a functional unsubscribe.

- **Unsubscribe endpoint:** public `GET /unsubscribe/{signed}` (Laravel signed URL, no
  auth). Inserts the decoded email into `core.outreach_suppressions` (idempotent upsert),
  returns a plain confirmation page. Signed so the link can't be forged to suppress
  arbitrary addresses.
- **Suppression is enforced at send** (§5 guard), so once someone unsubscribes no future
  invite — for this or any later build — reaches them.

This hook is the single piece of infrastructure that future-proofs the feature for colder
lists without a redesign.

## 8. What does NOT change

- The existing claim flow (`ClaimController`, `ClaimSiteService`, claim-time welcome
  notification + `sidest_updates` opt-in in `SignupSideEffects`) is untouched.
- Provisional-user email nullability and every null-email dispatcher guard stay as-is.
- `SyncSubdomainToKvJob` remains the only KV writer; this feature adds no KV path.
- No change to the public self-serve signup (`POST /api/public/signup/build`).

## 9. Components summary

| Unit | Purpose | Depends on |
|------|---------|-----------|
| Migration (2 cols + 1 table) | Store contact + suppression | `pre_account_builds`, `core` schema |
| `StaffCreatePreAccountBuildRequest` (edit) | Validate `contact_email` | existing request |
| `POST /api/staff/builds/batch` | CSV loop over `requestBuild` | `PreAccountBuildService` |
| `POST /api/staff/builds/{build}/invite` | Guarded manual send | build model, suppression table, mailable |
| `ClaimInviteMail` | The email | Resend transport, existing mail layout |
| `GET /unsubscribe/{signed}` | Opt-out → suppression | signed URLs, suppression table |
| Policy method | Staff authorization for invite/batch | `BasePolicy` |

## 10. Testing focus

- Send guards: each rejection path (unpublished, no email, already invited, suppressed)
  returns its specific code.
- `invited_at` stamped only after dispatch; transport failure leaves it re-sendable.
- Suppression persists across a second build for the same email.
- CSV: a bad row is reported, not fatal; dedupe rule still applies.
- Constraint drift: `contact_email` / suppression writes verified against the raw Postgres
  DDL, not just the SQLite suite (unknown quoted identifiers become string literals in
  SQLite — assert returned data, not just "query ran").
- Unsubscribe signed-URL tamper rejection.
