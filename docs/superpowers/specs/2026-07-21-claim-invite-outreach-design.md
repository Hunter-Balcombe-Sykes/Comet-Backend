# Claim-Invite Outreach — Extensions to the Shipped Claim-Notify System

**Date:** 2026-07-21 (rewritten after discovering the core already ships)
**Status:** Approved design, pre-implementation
**Extends:** `docs/superpowers/specs/2026-07-21-signup-flows-and-early-access-design.md`
(the shipped claim-notify / email-gate design)
**Builds on:** Pre-account signup — `docs/superpowers/specs/2026-07-18-pre-account-sites-design.md`

## 0. Why this spec was rewritten

An earlier draft of this document proposed building "staff records a contact email,
site is built, an email invites them to claim it" from scratch, plus a bespoke
suppression table and a first-come claim model. Investigation showed **most of that
already ships** under the 2026-07-21 signup-flows spec. This rewrite discards the
duplicated design and scopes down to the genuine gaps.

### Already shipped — do NOT rebuild
- `contact_email` column on `core.pre_account_builds` (migration `20260721120000`),
  fillable on the model, validated on `StaffCreatePreAccountBuildRequest`, threaded
  through `PreAccountBuildService::requestBuild(... contactEmail ...)`.
- `App\Mail\PreAccount\ClaimInviteMail` — *"Your Partna site is ready — claim it"*.
- `App\Services\PreAccount\ClaimNotifier::notify()` — fans the invite out to email now
  (`ClaimInviteMail` to `contact_email`) and a stubbed DM channel (`ClaimDmChannel`).
- **Auto-send on publish:** `GeneratePreAccountSiteJob:112-119` calls
  `ClaimNotifier::notify()` whenever a build publishes; `ApproveEarlyAccessBuildJob`
  fires it on early-access approval.
- **Email-gated claim** (already stronger than the "first-come plain URL" the earlier
  draft proposed): when `contact_email` is set, `ClaimSiteService.php:69` requires the
  claimer's Supabase-verified email to match it (`CLAIM_EMAIL_MISMATCH`); staff can
  clear `contact_email` to drop back to first-come.
- GDPR export / purge already cover `contact_email`.

### The real gaps this spec covers
1. **Manual review-then-send** — today the invite fires automatically the instant a
   build publishes, so a wrong scraped photo/bio can land in a prospect's inbox
   unreviewed. There is no "publish, eyeball the live site, then send" path.
2. **Send idempotency** — nothing records that an invite was sent, so a job retry or a
   re-publish can re-send. No `invited_at`.
3. **CSV batch ingestion** — the "give us a list" case. Only single-build entry exists.

Compliance hardening (unsubscribe, bounce auto-suppress, audit trail) is **deferred**
to §8 — it matters for cold outreach, not the current warm/low-volume mode, and
`ClaimInviteMail` is currently a transactional send.

## 1. Scope

**In scope:** `invited_at` tracking + notify idempotency; an `auto_invite` toggle so
staff can defer the send; a manual send endpoint; CSV batch build.

**Out of scope (deliberate):**
- Suppression / unsubscribe / bounce handling / audit trail — §8, deferred.
- Claim gating changes — the email-gate already ships and is correct.
- The DM channel — already a bound stub (`ClaimDmChannel`); IG/GBP cannot be sent from
  the backend (platform limitation, unchanged).

## 2. Data model — two columns on `core.pre_account_builds`

Raw SQL in `supabase/migrations/` (never Laravel migrations — composer guard rejects them).

- `invited_at TIMESTAMPTZ NULL` — NULL = invite not yet sent. Stamped by `ClaimNotifier`
  after it queues the mail. Doubles as the idempotency guard and as the "already
  invited" signal for the manual endpoint.
- `auto_invite BOOLEAN NOT NULL DEFAULT true` — `true` preserves today's behaviour
  (invite auto-sends on publish — the ManyChat / automated path is unchanged). `false`
  means: publish the site but **defer** the invite for manual review + a manual send.

Both are plain staff-supplied / lifecycle scalars, consistent with the existing
`contact_email` fillability posture. `invited_at` is written via `forceFill()` (like
`build_state`/`claimed_at`) so a state write is never a silent no-op; `auto_invite` is
fillable at build time.

## 3. Idempotent, defer-aware `ClaimNotifier`

`ClaimNotifier::notify(PreAccountBuild $build)` gains idempotency and stamps the send:

```
if ($build->invited_at !== null) {
    return;                       // already invited — retries/re-publish are no-ops
}
// ... existing email + DM fan-out (unchanged) ...
$build->forceFill(['invited_at' => now()])->save();
```

Stamping happens only after `Mail::queue(...)` succeeds, so a transport failure leaves
the build re-sendable. This fixes gap #2 for the existing auto path too — a
`GeneratePreAccountSiteJob` retry no longer double-sends.

## 4. Defer the auto-send — `GeneratePreAccountSiteJob`

The auto-notify at `GeneratePreAccountSiteJob:112-119` becomes conditional on
`auto_invite`:

```
if ($this->publish) {
    $site->update(['is_published' => true]);
    SyncSubdomainToKvJob::dispatch($user->id);
    if ($build->auto_invite) {
        app(ClaimNotifier::class)->notify($build->fresh());
    }
}
```

A staff review build (`publish=true, auto_invite=false`) goes live so staff can open the
real URL and check it, but no invite is sent until they trigger §5.

`PreAccountBuildService::requestBuild` gains `bool $autoInvite = true`, persisted onto the
build; `StaffCreatePreAccountBuildRequest` gains `'auto_invite' => ['sometimes','boolean']`
and the controller passes it through. Default `true` keeps every existing caller
(including early-access and the automated staff path) behaving exactly as today.

## 5. Manual send endpoint

`POST /api/staff/builds/{build}/invite`.

**Guards (each rejection returns a specific code):**
- build `build_state = ready` and site published (else 409 `BUILD_NOT_READY`)
- `contact_email` present (else 422 `NO_CONTACT_EMAIL`)
- `invited_at` IS NULL (else 409 `ALREADY_INVITED`)

**On pass:** call `app(ClaimNotifier::class)->notify($build)` — which sends and stamps
`invited_at` via §3. Return the `PreAccountBuildStatusResource`.

Authorization via the existing staff policy (`authorizeForUser($staff, 'staffCreate',
PreAccountBuild::class)` or a dedicated `staffInvite` ability), never an inline 403 —
consistent with `StaffPreAccountBuildController`.

This reuses `ClaimNotifier` wholesale; the endpoint is a thin guarded trigger, so the
email/DM fan-out logic lives in exactly one place.

## 6. CSV batch build

`POST /api/staff/builds/batch`. Parses rows of:

```
account_type, source_type, source_ref, source_name, contact_email, auto_invite
```

and calls the **same** `PreAccountBuildService::requestBuild` per row in a loop — no
parallel build logic; CSV is strictly a loop over the single-entry path. Behaviour:

- Per-row failures are caught and collected (row index + `errorCode` + message) so one
  bad row never aborts the batch.
- The existing dedupe / one-live-build-per-source rule is honoured, not bypassed (a
  duplicate row surfaces as a `reused` result, same as the single endpoint).
- A row cap is enforced (implementation default, e.g. 500); any truncation is logged, not
  silent.
- `auto_invite` per row lets a batch be "publish now, send later" for staged review, or
  "publish + invite immediately" for a trusted warm list.

Response: a summary resource — counts of `built` / `reused` / `failed`, with per-row
detail. Authorization identical to the single staff endpoint.

## 7. What does NOT change

- `ClaimInviteMail`, the email-gate in `ClaimSiteService`, the claim flow, and
  `ApproveEarlyAccessBuildJob`'s notify are untouched.
- Default behaviour (`auto_invite=true`) is byte-for-byte the current auto-send-on-publish
  path, plus the new retry-safety from idempotency.
- `SyncSubdomainToKvJob` remains the only KV writer.
- Provisional-user email nullability and null-email dispatcher guards stay as-is.

## 8. Deferred — compliance hardening for cold outreach

Not built now; documented so a later cold-outreach mode is a scoped follow-on, not a
redesign. When outreach moves beyond warm/consented contacts:

- **Unsubscribe + suppression** via the existing `notifications.email_subscriptions`
  system (a new `claim_invite` list key in `config/subscriptions.php` `global_list_keys`,
  reusing `PublicEmailUnsubscribeController` / `route('public.unsubscribe')` and the
  `List-Unsubscribe` header pattern). `ClaimInviteMail` would move from purely
  transactional to carrying an unsubscribe link, and the §5/auto paths would gate on
  suppression.
- **Bounce auto-suppress** — a Resend bounce webhook marking the address suppressed;
  matters because invites share the auth-OTP sending domain, so bad-address volume can
  hurt login deliverability.
- **Audit trail** — send / unsubscribe / bounce rows in the append-only `audit` schema.

Trigger to build §8: the first time staff load a list that is not demonstrably
warm/consented. (Reminder: "warm" is a process promise, not a code guarantee.)

## 9. Components summary

| Unit | Purpose | Type |
|------|---------|------|
| Migration (`invited_at`, `auto_invite` on `pre_account_builds`) | Track sends; allow deferral | new SQL |
| `ClaimNotifier::notify` (edit) | Idempotency + stamp `invited_at` | modify |
| `GeneratePreAccountSiteJob` (edit, ~L112-119) | Gate auto-invite on `auto_invite` | modify |
| `PreAccountBuildService::requestBuild` (edit) | `$autoInvite` param, persist | modify |
| `StaffCreatePreAccountBuildRequest` (edit) | Validate `auto_invite` | modify |
| `PreAccountBuild` model (edit) | `auto_invite` fillable, `invited_at` cast + @property | modify |
| `POST /api/staff/builds/{build}/invite` | Guarded manual send | new endpoint + controller |
| `POST /api/staff/builds/batch` | CSV loop over `requestBuild` | new endpoint + controller |

Reused as-is: `ClaimNotifier`, `ClaimInviteMail`, `ClaimDmChannel`,
`PreAccountBuildStatusResource`, the staff policy.

## 10. Testing focus

- Idempotency: two `notify()` calls / a job retry send **one** email; `invited_at`
  stamped once.
- `auto_invite=false` + `publish=true` → site published, **no** invite; `invited_at` NULL.
- `auto_invite=true` (default) → unchanged auto-send on publish (existing tests stay green).
- Manual endpoint: each guard rejection (`BUILD_NOT_READY`, `NO_CONTACT_EMAIL`,
  `ALREADY_INVITED`) returns its code; success sends once and stamps.
- Transport failure leaves `invited_at` NULL / re-sendable.
- CSV: a bad row is reported not fatal; dedupe surfaces as `reused`; row cap enforced +
  logged; per-row `auto_invite` honoured.
- Constraint drift: `invited_at` / `auto_invite` writes verified against the raw Postgres
  DDL, not just the SQLite suite (unknown quoted identifiers become string literals in
  SQLite — assert returned data, not just "query ran").
- Email-gate regression: a build with `contact_email` still rejects a mismatched claimer
  (existing behaviour must survive these changes).
