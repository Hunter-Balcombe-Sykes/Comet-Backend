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
building a site, review the generated site, then manually send a "here's your site, come
claim it" email that drops the prospect into the existing claim flow.

## 2. Scope & constraints

**In scope:** email invites for staff-built pre-account sites, single-entry and CSV
batch, manual send, bounce handling, minimal Spam Act compliance via the existing
subscription system.

**Out of scope (deliberate):**
- **Instagram / Google Business DMs** — the backend structurally cannot send these. IG's
  Messaging API only permits replies inside a 24h window after the user messages first;
  GBP has no outbound business-to-consumer messaging API. Cold DMs remain a ManyChat /
  human task. This is a platform limitation, not a design choice.
- **Claim tokens / pinned invite links** — the claim flow is intentionally "first-come,
  any verified email" against an already-public site (pre-account spec left claim tokens
  out of scope). We reuse it unchanged.
- **Auto-send, scheduling, drip sequences, open/click tracking.**

**Operating mode:** warm, staff-curated contacts (prior touchpoint / consent).

> **Honesty note — "warm" is a process promise, not a code guarantee.** Nothing in this
> design *enforces* that a `contact_email` was obtained with consent; it assumes staff
> only load warm contacts. The compliance surface (§7) — subscription-backed suppression,
> unsubscribe, sender ID, bounce handling — is built so that a later move to colder lists
> is a policy decision, not a rebuild. But if outreach turns cold, revisit consent
> justification under the Spam Act before relying on this design.

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

### 3.2 Suppression reuses `notifications.email_subscriptions` — NO new table

Suppression and unsubscribe are **not** a bespoke table. They reuse the existing
`notifications.email_subscriptions` system (`EmailSubscription` model,
`PublicEmailUnsubscribeController`, `config/subscriptions.php`) under a new list key.

- **New list key `claim_invite`**, registered in `config/subscriptions.php`
  `global_list_keys` (staff/internal, email-keyed, `user_id NULL`) — mirroring how
  `sidest_updates` is declared. It is **not** added to `public_list_keys`.
- The table already provides everything the standalone design would have reinvented:
  email-keyed rows with `user_id` nullable, `status ∈ {subscribed, unsubscribed}`,
  `unsubscribe_token`, consent provenance (`consent_source`, `consent_ip_hash`,
  `consent_user_agent`), the `(list_key, email_lc) WHERE user_id IS NULL` uniqueness index
  (one row per email per list), `List-Unsubscribe` header support, and the weekly
  `notifications:prune-unsubscribed-subscriptions` prune.
- **Suppressed** = an `email_subscriptions` row with `list_key='claim_invite'` and
  `status='unsubscribed'` for that email. This covers both manual unsubscribes and
  bounce-driven suppression (§6.1).

No `core.outreach_suppressions` table is created.

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
are collected and returned (row index + reason) so a bad row never aborts the batch. A row
cap is enforced (implementation default; log any truncation). The endpoint honours the
existing build dedupe / one-live-build-per-source rule; it does not bypass it.

Response: a summary resource — counts of built / skipped / failed, with per-row detail.

## 5. Sending — manual, per build

New `POST /api/staff/builds/{build}/invite`.

**Guards (all must pass, else 4xx with a specific code):**
- build `build_state = ready` and published (unpublished/unbuilt → 409)
- `contact_email` present (else 422)
- `invited_at` IS NULL (else 409 `ALREADY_INVITED`)
- no `email_subscriptions` row for `(list_key='claim_invite', email)` with
  `status='unsubscribed'` (else 409 `SUPPRESSED`)

**On pass:**
1. Ensure a `claim_invite` subscription row exists for the email via `insertOrIgnore`
   (status `subscribed`, `consent_source='staff_invite'`, fresh `unsubscribe_token`).
   `insertOrIgnore` intentionally will **not** revive an existing `unsubscribed` row — but
   that case is already blocked by the guard above.
2. Dispatch `ClaimInviteMail` to `contact_email` via the existing Resend transport (same
   transport as `MagicLinkMail` / `EmailConfirmMail` in `app/Mail/Auth`), carrying the
   row's `unsubscribe_token` in the unsubscribe link and `List-Unsubscribe` headers.
3. Stamp `invited_at` — only after successful queue dispatch, so a transport failure
   leaves the build re-sendable.
4. Write an audit record (§6.2).

Staff "review" needs no new screen — the site is already public at its URL; staff open it
before clicking send.

Authorization via policy (`authorizeForUser`, staff gate), never an inline 403 — consistent
with the staff controllers.

## 6. Deliverability & auditing

### 6.1 Bounce → auto-suppress

Invite emails share the **same Resend account and sending domain** as the auth OTP /
magic-link emails (`SupabaseEmailHookController`, `MagicLinkMail`). Repeated sends to dead
addresses degrade *domain* reputation and can push genuine login OTPs toward spam. So hard
bounces must suppress the address:

- A Resend **bounce webhook** (hard bounce / complaint) marks the matching
  `claim_invite` subscription row `unsubscribed` (`markUnsubscribed()`), which the §5 send
  guard then honours. Reuses the same suppression mechanism as manual unsubscribe.

### 6.2 Audit trail

This is outbound commercial email to real people; a dispute needs a record. Use the
existing append-only `audit` schema (`app_backend` has SELECT/INSERT only):

- On invite **send**: who sent (staff id), target email, build id, timestamp.
- On **unsubscribe** and on **bounce-suppression**: email, list key, reason, timestamp.

This is the evidentiary trail for consent/complaint handling.

## 7. Compliance — minimal but real

Australian Spam Act 2003 requires, on every commercial send (warm included): clear sender
identification and a functional unsubscribe. Both are satisfied by reusing the existing
subscription system:

- **Sender identification** — `ClaimInviteMail` carries Partna's identity + a real
  reply/contact address, matching the existing subscription mailables.
- **Unsubscribe** — the invite includes the standard token unsubscribe link
  (`route('public.unsubscribe', $token)`) and `List-Unsubscribe` /
  `List-Unsubscribe-Post` headers, handled by the existing
  `PublicEmailUnsubscribeController`. No new endpoint.
- **Suppression scope — outreach only, never transactional.** Suppression lives entirely
  in the `claim_invite` list. Claim OTP and the claim-time welcome email do **not** flow
  through `email_subscriptions`, so a `claim_invite` unsubscribe can never block a
  suppressed person's login or a legitimate claim they later initiate themselves.

## 8. The invite email — `ClaimInviteMail`

Contents:
- the site URL (`<handle>.partna.au`) and a **"Claim your site"** CTA → lands on the
  published site, which already carries the email-OTP claim entry point
  (`ClaimController` / `ClaimSiteService`). No new claim machinery.
- sender identification (§7) and the token unsubscribe link + `List-Unsubscribe` headers.
- Reuses the app's existing mail layout/branding used by the auth / subscription mailables.

## 9. What does NOT change

- The existing claim flow (`ClaimController`, `ClaimSiteService`, claim-time welcome
  notification + `sidest_updates` opt-in in `SignupSideEffects`) is untouched.
- Provisional-user email nullability and every null-email dispatcher guard stay as-is.
- `SyncSubdomainToKvJob` remains the only KV writer; this feature adds no KV path.
- No change to the public self-serve signup (`POST /api/public/signup/build`).
- The `email_subscriptions` schema is unchanged — we only add a new `list_key` value in
  config and register the bounce webhook.

## 10. Components summary

| Unit | Purpose | Depends on |
|------|---------|-----------|
| Migration (2 cols on `pre_account_builds`) | Store contact + invited stamp | `pre_account_builds` |
| `config/subscriptions.php` (edit) | Register `claim_invite` global list key | existing config |
| `StaffCreatePreAccountBuildRequest` (edit) | Validate `contact_email` | existing request |
| `POST /api/staff/builds/batch` | CSV loop over `requestBuild` | `PreAccountBuildService` |
| `POST /api/staff/builds/{build}/invite` | Guarded manual send + subscription + audit | build model, `EmailSubscription`, mailable |
| `ClaimInviteMail` | The email | Resend transport, existing mail layout, `unsubscribe_token` |
| Resend bounce webhook handler | Hard bounce → suppress | `EmailSubscription::markUnsubscribed` |
| Policy method | Staff authorization for invite/batch | `BasePolicy` |

Reused as-is (no new code): `PublicEmailUnsubscribeController` / `route('public.unsubscribe')`,
`notifications:prune-unsubscribed-subscriptions`, `List-Unsubscribe` header pattern.

## 11. Testing focus

- Send guards: each rejection path (unpublished, no email, already invited, suppressed)
  returns its specific code.
- `invited_at` stamped only after dispatch; transport failure leaves it re-sendable.
- Suppression persists across a second build for the same email (the `email_subscriptions`
  row, not the build, carries it).
- `insertOrIgnore` does not revive an `unsubscribed` row; the guard blocks the send first.
- Bounce webhook marks the `claim_invite` row unsubscribed and blocks the next send.
- Audit rows written on send, unsubscribe, and bounce-suppression.
- CSV: a bad row is reported, not fatal; dedupe rule still applies; row cap enforced.
- Constraint drift: `contact_email` / subscription writes verified against the raw Postgres
  DDL, not just the SQLite suite (unknown quoted identifiers become string literals in
  SQLite — assert returned data, not just "query ran").
- Suppression scope: a suppressed email can still receive claim OTP / welcome (transactional
  paths bypass `email_subscriptions`).
