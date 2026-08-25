# ManyChat claim links — design

**Date:** 2026-08-25
**Status:** Approved in principle (owner, 2026-08-25); spec pending review
**Scope:** The ManyChat/outreach build lane only. Self-serve (`built_via='signup'`) is explicitly OUT of scope — see §9.
**Relates to:** audit `#SEC-3` (`audits/sweeps/2026-08-24-claim-gate-security`). ⚠️ This does **NOT**
close it. `#SEC-3` is about the SELF-SERVE first-come arm, which is untouched here (§9); the
outreach arm was already closed by `45a87669a`. What this work does is *re-open* outreach
claimability under a token, deliberately. **Do not tick `#SEC-3` on the strength of this.**

---

## 1. Why

Two problems, in opposite directions, both in the outreach lane.

**ManyChat cannot create a build.** `POST /api/staff/builds` sits behind
`supabase.jwt` + `staff` + **`require.aal2`** (`routes/api/staff.php:51`). That is a
human-with-MFA surface. An automation platform cannot satisfy it, so the lane has
never been callable by a robot — and the data agrees: **zero `built_via='staff'`
builds exist on dev, and `invited_at` is null on all 17 builds.** No invite has
ever been sent.

**A DM'd person cannot claim.** An outreach build with no `contact_email` throws
`CLAIM_NOT_INVITED` (`ClaimSiteService`, the invite-gate added in `45a87669a`). On
Instagram you have a handle, not an email. So the person who receives the DM hits a
wall unless a staff member manually PATCHes an address onto the build first.

And the link itself proves nothing. `ClaimNotifier` builds:

```php
$claimUrl = rtrim((string) config('app.frontend_url'), '/').'/claim/'.$fresh->user->site->subdomain;
```

That is the public handle. Anyone can construct it.

**Why not just hide unclaimed sites:** tried on 2026-08-24 (`ee1c22784`, "Dark Until
Claimed"), reverted on 2026-08-25 — it defeats the pre-claim demo, which is the
product pitch. The fix belongs at the claim step. See `CLAUDE.md` §Pre-account.

## 2. What already exists

Do not rebuild these:

- **`ClaimDmChannel`** (`app/Services/PreAccount/Notifications/ClaimDmChannel.php`) —
  interface `send(PreAccountBuild $build, string $claimUrl): void`, bound to
  `NullClaimDmChannel` (logs `claim.dm.stub`). Reserved for exactly this.
- **`ClaimNotifier`** — already fans out to email AND the DM channel, and the DM
  fan-out fires **independent of `contact_email` presence**.
- **Signed internal webhooks** — the established pattern is a dedicated middleware
  alias per caller (`supabase.email-hook`, `resend.webhook`, `supabase.auth-hook` in
  `bootstrap/app.php:181-182`), fail-closed 503 when the secret is unset.
- **Claim works before the build finishes** — `ClaimSiteService` claims `pending` and
  `building` fine; only a missing or `failed` build blocks. So a link can be DM'd the
  instant the build is requested.

## 3. Decision: Pull, not Push

**ManyChat calls us and sends the DM itself.** We do not call ManyChat.

Rejected alternative (Push): a real `ClaimDmChannel` driver posting to ManyChat's API.

Rationale:

- **Instagram only permits DMing someone who messaged you first.** The person is
  already mid-conversation in ManyChat before a build is triggered. ManyChat holds
  the subscriber context; Push would mean storing IG subscriber IDs solely to hand
  ManyChat back what it already has.
- **That storage is not free.** `pre_account_builds` has an account-deletion cascade
  obligation. Third-party identifiers there would need to join it, and getting that
  wrong means subscriber IDs outliving their account.
- **Push couples our queue to ManyChat's uptime** — outbound HTTP, an API key, an
  SSRF category review, retries, and a send-once guard we would have to write.
- **The async build is not an argument for Push**, because claiming does not wait for
  `ready` (§2). There is no dead-link window.

`ClaimDmChannel` stays bound to the null driver. It is not wasted — it remains the
right seam for genuinely backend-initiated messages (build failed, window expiring, a
day-7 nudge), which are our events on our clock. The initial invite is not.

## 4. The claim token

New column on `core.pre_account_builds`:

| Column | Type | Notes |
|---|---|---|
| `claim_token_hash` | `text NULL` | SHA-256 of the token. **The plaintext is never stored.** |
| `claim_token_issued_at` | `timestamptz NULL` | For observability and rotation. |

**Store a hash, not the token.** A DB read (backup, log, support query, the `audit`
schema) must not yield a working takeover capability for every live build. The
plaintext is returned exactly once, in the create response, and never again.

**Generation:** 32 bytes from `random_bytes()`, base64url — **not** a UUIDv7. The
build's own id is UUIDv7 and leaks creation time in its prefix; a capability should
not.

**Lifetime:** rides the build's existing `expires_at` (30 days default,
`PARTNA_PRE_ACCOUNT_EXPIRY_DAYS`, overridable per build via `expires_days`). No
separate clock. `builds:prune-expired` already hard-deletes the row, taking the token
with it.

**Single-use means USED, not OPENED.** The hash is cleared
(`claim_token_hash = NULL`) inside the claim transaction, **on success only** — the
same transaction that already holds `lockForUpdate()` and transfers ownership. Every
outcome short of a completed claim leaves the token intact:

| What happens | Token after |
|---|---|
| Taps the link, closes the tab | Still valid |
| Starts the OTP sign-in, abandons it | Still valid |
| Signs in but never submits the claim | Still valid |
| Claim throws (`ACCOUNT_EXISTS`, `BUILD_FAILED`, …) | Still valid — the throw rolls back |
| Claim succeeds | **Burned** |

This is the intended behaviour, not an accident of the implementation: a cold DM
recipient will open the link, get distracted, and come back. Burning on open would
lose the lead. A forwarded link is spent only once someone actually takes ownership.

Any future retry/expiry logic must preserve this property — it is the difference
between a 30-day window and a one-tap window.

**Not fillable.** Follows the existing `user_id` / `built_by_staff_id` precedent —
set via `forceFill` on a trusted path only.

## 5. The ManyChat endpoint

```
POST /api/internal/webhooks/manychat/builds
```

Placed under `Api\Internal\`, alongside `SupabaseEmailHookController` and the Resend
webhook. Middleware: a new `manychat.webhook` alias + `throttle` bucket.

### 5.1 Authentication — and its honest limit

**ManyChat's "External Request" action cannot compute an HMAC over the request body.**
It can send static or field-substituted headers. So the Standard Webhooks HMAC used by
`VerifySupabaseHookSignature` is **not available** here.

Auth is therefore a **static bearer secret** in a header, compared with
`hash_equals()`, read from `config('services.manychat.webhook_secret')`, **fail-closed
503 when unset** (matching `VerifySupabaseHookSignature`'s contract).

This is weaker than HMAC. Stated, not hidden. Mitigations:

- ≥32 bytes of entropy, rotatable without a deploy (env var).
- Dedicated throttle bucket — build requests are Apify-billed.
- Theft of OTHER people's builds is bounded by §5.4, the load-bearing control.

**What §5.4 does NOT bound, stated plainly:** a secret holder can create a published,
publicly-routable site for **any** business they can name, and receive a token letting them
claim it themselves. Before this change that lane required staff + AAL2 *and* a
staff-attached email, and an outreach build with no `contact_email` was unclaimable by
anyone. So this materially lowers the cost of identity squatting (Threat 2, §9) even though
it does not introduce it. The residual is not "can create spam builds" — it is "can mint
themselves a claimable site for a business they do not own". Rotate the secret on any
suspicion, and keep the throttle tight.

### 5.2 Request / response

Request:

```json
{
  "account_type": "partna",
  "source_type": "instagram",
  "source_ref": "amiconirestaurant",
  "source_name": "Amiconi",
  "expires_days": 30,
  "idempotency_key": "manychat-sub-4471-amiconirestaurant"
}
```

Response `202` (new build) — **flat, no `data` envelope.** `ApiController::success()` is
called with `(new Resource(...))->resolve()`, and `resolve()` returns the unwrapped array;
`$wrap = 'data'` only applies when a Resource is returned directly. This matches the sibling
endpoints (`PreAccountBuildController`) and the assertions in `PublicBuildEndpointsTest`:

```json
{
  "build_id": "01a03729-…",
  "subdomain": "amiconi-restaurant",
  "build_state": "pending",
  "reused": false,
  "claim_url": "https://partna.au/claim/amiconi-restaurant?t=<token>"
}
```

ManyChat inserts `claim_url` into the DM. Nothing else is needed. **Configure the ManyChat
flow against the flat path** (`claim_url`, not `data.claim_url`).

**`idempotency_key` (required).** ManyChat must send a stable key per lead-conversation.
See §5.4 — without it a lost response strands the build.

### 5.3 Send timing — ManyChat's choice, not ours

The build is async (~90s in practice: Amiconi ran 04:24:13 → 04:25:37 on 2026-08-25).
The claim URL is valid immediately, because claiming does not wait for `ready` (§2).
So there is no dead-link window and **no backend work is required to delay the send**.

When the DM goes out is a ManyChat flow setting:

- **Delay:** a wait step of ~2 minutes, then send. Simplest.
- **Poll (recommended):** poll `GET /api/public/signup/builds/{build_id}` until the
  build is ready, then send.

For polling, use the signal that already exists rather than inventing one:
`PreAccountBuildStatusResource` **withholds `site_url` until `build_state === 'ready'`**,
and its comment states the intent —

> `site_url` stays ready-gated: that's the "go visit a real site" signal, which should
> wait for actual content.

So `site_url` present ⇒ safe to send. `build_state` is also returned if a flow prefers
to match on it directly. The endpoint is public (opaque UUID, `throttle:public-site`),
so ManyChat needs no credentials to poll it.

**Recommended:** poll, then send. A cold DM recipient tapping through to a half-scraped
page is a worse first impression than a two-minute delay — unlike a self-serve user,
who just built the thing and expects it to be loading.

This is a second-order argument for Pull (§3): send timing stays a setting someone can
change in the ManyChat UI, instead of backend code and a deploy per experiment.

### 5.4 The dedupe hazard — load-bearing

`PreAccountBuildService::requestBuild` **dedupes and can return an existing build**
(`$result['reused']`). Naively returning a token on that path would mean: anyone
holding the webhook secret submits the `source_ref` of a build **someone else**
created and receives a working claim token for it. That converts a leaked secret from
"can create spam builds" into "can take over any build whose source I can guess".

**Rule: a claim token is minted and returned ONLY when a new build is created.**

On reuse, return `200` with the build's status and **no `claim_url`**, plus
`"reused": true`. Re-issuing a token for an existing build is a deliberate,
separate, staff-authenticated action (§8), never a side effect of a webhook call.

This is the control that bounds §5.1's weaker auth, and it must be pinned by a test.

**The cost of that rule: a lost response strands the build.** Webhook callers retry. If
ManyChat's first call succeeds server-side but the response never arrives, the retry dedupes
to `reused: true` and returns no token — leaving a build that is `built_via = staff`, has no
`contact_email` and no token, i.e. `CLAIM_NOT_INVITED` forever until a human runs §8.

**Therefore `idempotency_key` is REQUIRED on the request.** Store it on the build; when a
request arrives whose key matches the build it deduped to, **re-mint and return a fresh
claim_url**. A retry is then safe, and the §5.4 rule is unweakened: the key proves the caller
is the same one that created this build, which is exactly what the dedupe path could not
otherwise establish.

⚠️ **Do NOT implement the tempting shortcut** "re-mint on reuse when `claim_token_hash IS NULL`."
Self-serve builds also have a null token, so that rule would hand a leaked webhook secret a
working capability for any `signup` build — reintroducing the takeover this section exists to
prevent.

## 6. Changes to the claim path

### 6.1 `ClaimNotifier` — NOT changed

Under Pull, the claim URL that matters is built in the webhook response (§5.2), at
the one moment the plaintext token exists. `ClaimNotifier` is not on that path and is
left alone.

Its emailed link keeps carrying the bare `/claim/{subdomain}`, which is correct: the
email path's proof is OTP-verified control of `contact_email`, enforced by the
existing `CLAIM_EMAIL_MISMATCH` gate. A token there would be redundant, and threading
the plaintext into `ClaimNotifier` would mean either passing it through every caller
or reading it back from the model — and it is not stored in plaintext (§4).

This is deliberate: the two lanes prove invitation by different means, and neither
needs the other's mechanism.

### 6.2 `ClaimSiteService`

The invite-gate becomes: a valid token satisfies it.

```php
$tokenOk = $this->tokens->matches($build, $claimToken);

$contactEmail = trim((string) $build->contact_email);
if ($build->isOutreach() && $contactEmail === '' && ! $tokenOk) {
    throw new RuntimeException('CLAIM_NOT_INVITED');
}

// NOTE the absence of $tokenOk here — deliberate (owner, 2026-08-25).
if ($contactEmail !== ''
    && strtolower(trim($verifiedEmail)) !== strtolower($contactEmail)) {
    throw new RuntimeException('CLAIM_EMAIL_MISMATCH');
}
```

**The token is NARROW: it proves INVITATION, not identity.** It satisfies the invite-gate
only. If a build carries a `contact_email`, that address must still match — a token holder
cannot claim an email-gated build with some other address.

This is a decision, not an oversight. The wider reading (token overrides everything) is
reachable in practice: `reconcileContactEmail()` can attach an address to an existing live
build, and §8 will mint a token for a build that already has one. Under the wide reading a
token would then silently override a staff-attached invitation. Both behaviours must be
pinned by tests so neither can drift.

Both existing gates keep their current behaviour when no token is presented. On
success, inside the same transaction that already holds `lockForUpdate()`, clear
`claim_token_hash`.

**A token does not bypass:** `CLAIM_EMAIL_MISMATCH` (see above), `ALREADY_CLAIMED`,
`BUILD_FAILED`, `ACCOUNT_EXISTS`, `EMAIL_ALREADY_REGISTERED`, or expiry. It satisfies
*invitation*, nothing else.

**Expired build:** the token is refused. `builds:prune-expired` deletes the row
anyway; the check is explicit so a not-yet-pruned expired build cannot be claimed.

### 6.3 `ClaimSiteRequest`

Add `'claim_token' => ['nullable', 'string', 'max:128']`. The frontend reads `?t=` and
forwards it in the **POST body**, so the token never reaches OUR access logs or `Referer`.

⚠️ **It is still in the frontend's URL.** The DM'd link is `…/claim/{sub}?t=<token>`, which
lands in the pages app's access logs, the visitor's browser history, and the `Referer` of
every asset the claim page loads. The frontend contract therefore MUST strip it on load:

```js
history.replaceState(null, '', location.pathname);
```

Frontend work is out of scope here (§9), but this is part of the contract handed over, not
an optional nicety.

### 6.4 Send-once guard for the DM

`ClaimNotifier` documents the gap outright:

> only the EMAIL send is idempotency-gated (via `invited_at`) — a no-email build is
> never stamped, so a repeat `notify()` re-fires this channel.

Under Pull the DM is ManyChat's to send, so *delivery* dedupe is theirs and the
documented gap is not on our critical path. Ours is the stronger property, from §5.4:
a repeat webhook call for the same source dedupes to `reused: true` and mints nothing,
so **no second token is ever live for one build**.

The stub's re-fire gap therefore stays as-is and stays documented. It becomes real work
only if a Push driver ever lands (§3), and this spec does not land one.

### 6.5 `isOutreach()`'s docblock becomes false — update it

`PreAccountBuild::isOutreach()` currently documents: *"Both come from the same
`$staff ? VIA_STAFF : VIA_SIGNUP` expression at creation time, so `built_via` is exactly as
trustworthy"*, and `#SEM-2`'s note says `built_via === 'staff'` *"can only originate from an
actual staff-authenticated write."*

After this change `VIA_STAFF` also originates from a static-secret webhook. The
classification still fails **safe** (more outreach, never less), so there is no security
regression — but leaving the comment planted is a false premise for the next reviewer.
Update the docblock and add a line to the `#SEM-2` entry.

## 7. Migration

`supabase/migrations/20260825120000_pre_account_builds_claim_token.sql`

Two nullable columns, no default, no backfill. Existing builds have no token and
continue to work through the email path exactly as today.

Checked: no filename-prefix collision (`ls | cut -d_ -f1 | uniq -d` is empty). Avoid
`20260824120000` and `20260824122012` — burned ledger stamps with no file, per
`audits/2026-08-24-P1-SWEEP-REPORT.md`.

Dev first, then prod, per `reference_dev_migration_before_merge`.

## 8. Re-issuing a token

Staff-only, AAL2, on the existing staff surface — `POST /api/staff/builds/{build}/claim-token`.
Mints a new token, invalidating the old one, and returns the plaintext once. For "the
lead lost the DM" and for rotation after a suspected leak. Deliberately NOT on the
webhook (§5.4).

## 9. Explicitly out of scope

- **Self-serve (`built_via='signup'`).** Still first-come; `#SEC-3` stays open for
  that lane. Closing it needs either an email field on the signup form or the token
  held in the browser session — an unresolved product decision (owner, 2026-08-25).
- **Threat 2 — identity squatting** (the builder was never the business). No token
  scheme addresses it; only proving control of the source does (GBP OAuth, or OTP to
  the listing's published contact). Note the harvest data is sparse — of 8 unclaimed
  builds, 4 have a phone and **1 has an email** — so the cheap version does not cover
  the corpus. Separate spec.
- **Push / a real `ClaimDmChannel` driver.** §3.
- **Frontend work.** `partna-monorepo` must read `?t=` on the claim page and forward
  it in the POST body. Contract is §5.2 + §6.3; ownership to be confirmed.

## 10. Testing

| Assertion | Why |
|---|---|
| New build via webhook returns a `claim_url` containing a token | Happy path |
| **Reused build returns NO `claim_url`** | §5.4 — the control bounding a leaked secret |
| Opening the link without completing a claim leaves the token valid | §4 — used, not opened |
| A failed claim leaves the token valid | §4. NOTE: the burn is folded into the final `claimed_at` write, so **every** throw is structurally before it. This test guards against someone moving the burn earlier — it is a regression guard, not a proof of rollback. |
| Valid token claims an outreach build with no `contact_email` | The wall is gone |
| Token is cleared after a successful claim; replay fails | Single-use |
| A token does NOT satisfy an email-gated build with a mismatched address | §6.2 — the token is NARROW |
| A token DOES claim an email-gated build when the address matches | §6.2 — no regression on the email lane |
| A retry carrying the same `idempotency_key` re-mints and returns a claim_url | §5.4 — a lost response must not strand the build |
| A reuse WITHOUT a matching idempotency_key still returns no claim_url | §5.4 — the rule is unweakened |
| A bad source/account pairing returns 422 with a `code`, not a 500 | The error contract — an unresolvable exception import would silently 500 |
| Wrong / absent token on a no-email outreach build still throws `CLAIM_NOT_INVITED` | No regression |
| Token on an expired build is refused | Expiry is not bypassable |
| Token does not bypass `ALREADY_CLAIMED` / `ACCOUNT_EXISTS` | Scope of the capability |
| Missing webhook secret → 503, not 200 | Fail-closed, matches the Supabase hook |
| Only the hash is persisted; plaintext appears in no column | §4 |
| Existing email-only claim path unchanged | The two existing gates still work |

Note the SQLite/Postgres divergence rule in `CLAUDE.md`: the new columns are nullable
`text`/`timestamptz` with no CHECK, so the SQLite lane is representative here. No
`tests/Postgres/` addition required.

⚠️ **The suite does not run migrations.** `RefreshDatabase` is disabled (`tests/Pest.php:49`);
each suite hand-builds its SQLite tables via `setupPreAccountBuildsTable()`, which carries its
own defensive `ALTER TABLE ... ADD COLUMN` list mirroring each migration. **Every schema change
here is a TWO-file change** — `supabase/migrations/` *and* `tests/Pest.php`.

This is not bookkeeping: a missing column reads back as `null`, so `ClaimTokenIssuer::matches()`
would return `false` for every input and the test *"a wrong token still throws
CLAIM_NOT_INVITED"* would **pass while the gate was simply broken**. Same vacuous-pass shape as
a rotted DAST fixture.

## 11. Open questions

1. **Frontend ownership** — is `partna-monorepo` yours to change, or does it need
   coordinating with the co-developer who authored `ee1c22784`?
2. **Does ManyChat send `source_type: instagram` only**, or also `google_business`?
   Affects nothing structurally; the pairing map already validates it.
3. **Secret rotation cadence** — env var change + redeploy. Worth a runbook line if
   ManyChat flows are edited by non-engineers.
