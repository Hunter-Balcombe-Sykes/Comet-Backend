# Setup-settled email timing — design

**Date:** 2026-09-03
**Status:** Design approved (owner, 2026-09-03); spec pending review
**Scope:** Both pre-account lanes — the self-serve welcome email and the outreach claim invite. The
manual staff invite endpoint is deliberately **excluded** (§7).
**Relates to:** `reference_build_ready_is_not_cascade_finished` (the memory that names this bug class),
`BuildProgressReader` (2026-09-02, the setup progress ledger).

---

## 1. Why

Two lifecycle emails fire before the thing they announce is true.

**The welcome email fires at claim.** `ClaimSiteService::claim()` queues `WelcomeMail` — subject
*"Welcome to Partna — your site is live"* — the moment the account binds. But claiming no longer waits
on the build reaching `ready`: `pending` and `building` both claim fine (deliberate, so the dashboard
shows the progress rather than the gate). So the email can arrive while the site has nothing on it.

**The outreach invite fires at `ready`.** `GeneratePreAccountSiteJob:253` calls
`ClaimNotifier::notify()` immediately after stamping `build_state = ready`. `ready` means the SITE was
built, not that the cascade finished — the Fresha auto-connect, media mirroring, menu fetch and
workplace chain all run after it, and take tens of seconds to minutes more. A cold lead's first
impression of Partna is therefore a half-populated page.

These are the same defect twice. Both are fixed by hanging the email on a *finished* signal instead of
an *exists* signal.

## 2. What "finished" already means

`BuildProgressReader::isDone()` (2026-09-02) already encodes it, and this design does not redefine it:

- the build is `ready` **and** `content_filled_at` is stamped, and
- every stage that said it STARTED has been answered (landed, skipped or failed), and
- the workplace question is answered (or was never asked), and
- enough media has settled (`min(total, 12)` mirrored-or-failed), and
- nothing is `stillConnecting()` — no storefront mid-connect, no ingest source mid-first-pull,

— **or** the build passed the 10-minute ceiling, **or** it failed.

Those last two arms are why `isDone()` alone is not the email trigger. It answers "should a loader stop
spinning", which is not the same question as "did this work".

## 3. The new signal: `outcome()`

`BuildProgressReader` gains one method:

```php
outcome(PreAccountBuild $build, array $events, array $media): string
// 'pending' | 'settled' | 'ceiling' | 'failed'
```

`isDone()` is re-expressed as `outcome() !== 'pending'` — the identical boolean, so the two existing
readers (the signup poll's `forPoll()`, the sitepage overlay's `forSite()`) are semantically untouched
and need no test changes.

**Only `settled` sends email.** `ceiling` and `failed` are terminal-but-silent (§6).

**Why widen rather than add a parallel predicate:** two independent definitions of "done" would drift,
which is the exact bug class this work exists to close. One function, one source of truth, a finer
return type.

## 4. Stamps

One migration on `core.pre_account_builds`, three nullable timestamptz columns:

| Column | Meaning |
|--------|---------|
| `settled_at` | The cascade genuinely finished. Set once, by the sweep. |
| `setup_stalled_at` | Terminal without settling (ceiling or failed). The staff record. |
| `welcomed_at` | The welcome email went out. The signup lane's idempotency guard. |

`invited_at` already exists and remains the outreach lane's guard, unchanged.

**No backfill (owner, 2026-09-03).** Every column is NULL on existing rows and stays that way. The
sweep's time window (§5) is what keeps historical rows out, not a migration.

## 5. The sweep

`php artisan builds:settle-sweep`, scheduled every minute.

**Candidate set:** builds where `created_at > now() - 30 minutes` AND `settled_at IS NULL` AND
`setup_stalled_at IS NULL`.

**Per candidate**, compute `outcome()`:

| Outcome | Action |
|---------|--------|
| `pending` | Nothing. Re-evaluated next tick. |
| `settled` | Stamp `settled_at`, then fan out (§6). |
| `ceiling` / `failed` | Stamp `setup_stalled_at`, log structured, **send nothing**. |

**Why 30 minutes:** it is 3× the 10-minute ceiling, so no build can pass through unobserved between
ticks. It is also what implements "only new ones" — every pre-existing build is days old, so the window
structurally cannot see one. No backfill migration is needed to make the cutover safe.

**Why a timer and not an event hook.** Three of `isDone()`'s terms have no event to hook: the media
counts are read from media rows rather than the progress ledger; `stillConnecting()` queries
`content.storefronts` and `ingest.sources` directly (its own docblock calls these "two queues the stage
ledger could not see"); and `OWED_MINUTES` means a started-but-unanswered stage *stops blocking after
four minutes*. That last one is decisive — the state transitions because time passed and nothing
happened, and there is no event for the absence of an event. Something has to look.

**Cost.** Per tick: one indexed range query for the window, plus ~4 queries per build actually in
flight. Cost scales with in-flight builds, not table size, so it does not degrade as the table grows.
At 1000 signups/day (~21 builds in a 30-minute window) that is ~85 queries/minute. For comparison, the
existing browser poll runs this same computation every few seconds per open tab — the sweep is
strictly less work than the polling it complements.

**Deferred, not rejected:** an observer on `PreAccountBuildEvent::created` would fire most settles
within milliseconds instead of within a minute. It does not remove the need for the timer (the
clock-driven arms remain), so it is a latency optimisation to add later if a minute ever matters, not
part of this work.

## 6. Fan-out on `settled`

Two gates on one event. A given build matches at most one.

**Welcome (self-serve).** If `claimed_at IS NOT NULL` and `welcomed_at IS NULL` → queue `WelcomeMail`,
stamp `welcomed_at`.

The claim gate is not a policy choice — it is forced. The self-serve lane passes no `contactEmail`
(`PreAccountBuildController:40-47` calls `requestBuild` with account type, source type, source ref,
source name and IP hash only), so `contact_email` is NULL on every signup build and we have no address
until claim binds one.

**Outreach invite.** If `claimed_at IS NULL` and the build is published and `auto_invite` →
`ClaimNotifier::notify()`. Its existing advisory-lock + `invited_at` guard is unchanged and remains the
idempotency mechanism for that lane.

⚠️ The `claimed_at IS NULL` term is new and load-bearing. `ClaimNotifier` guards on `invited_at`, **not**
on claim state, because until now it was only ever called from a pre-claim moment. Calling it from the
sweep breaks that assumption: an outreach build that was claimed before it settled would otherwise be
sent "come claim your site" by someone who already owns it. The gate belongs here rather than inside
`ClaimNotifier` so the manual staff endpoint (§7) keeps its override.

The two gates are mutually exclusive by construction — welcome requires `claimed_at IS NOT NULL`,
outreach requires `claimed_at IS NULL` — so a build never matches both.

## 7. Trigger points that stay, move, or go

**Stays — the claim-side send.** `ClaimSiteService::claim()` keeps a send, but re-gated:

- **was:** send whenever `is_new_claim` (i.e. the welcome notification row inserted)
- **now:** send when `settled_at IS NOT NULL` and `welcomed_at IS NULL`

This is load-bearing, not belt-and-braces. The sweep is window-bounded, so a claim weeks after settle
would never be observed by it. Whichever of {claim, settle} lands second performs the send; `welcomed_at`
makes it exactly one across both orderings.

**Stays — the in-app welcome notification.** `SignupSideEffects::createWelcomeNotification()` continues
to run at claim. It is a dashboard message and the person is on the dashboard. It stops being the
email's idempotency key; `welcomed_at` is.

⚠️ This decouples two things currently coupled. `ClaimSiteService::releaseClaim()` deletes the welcome
notification row specifically to re-arm the next claim's email. With `welcomed_at` as the key, release
must clear **`welcomed_at` too**, or a released-and-reclaimed site silently never welcomes its rightful
owner. Same obligation, new column.

**Goes — the invite at `ready`.** The `ClaimNotifier::notify()` call at `GeneratePreAccountSiteJob:253`
is removed; the sweep owns it. `ApproveEarlyAccessBuildJob` gets the same treatment.

**Untouched — the manual staff invite.** `POST /api/staff/builds/{build}/invite` keeps working
regardless of settle state (owner, 2026-09-03). That endpoint exists for builds staff wanted to eyeball
first (`auto_invite = false`); a human who has looked at the page and judged it good enough may send.
Gating it would defeat the lane.

## 8. The unhappy path

**Give up at the ceiling, surface to staff (owner, 2026-09-03).** A build that reaches ten minutes
without settling, or that failed, is stamped `setup_stalled_at` and gets no email — ever. There is no
retry past the ceiling and no "still working on it" email.

This is a deliberate acceptance: a self-serve signup whose build stalls hears nothing from us. It is
also already partly true — a failed build throws `BUILD_FAILED` at claim, so those people cannot claim
at all today.

**Both surfaces (owner, 2026-09-03):**

- `setup_stalled_at` on the row, exposed via `StaffPreAccountBuildResource` and the staff user index's
  `pre_account_build` block. Durable and queryable; renders whenever the frontend picks it up.
- `php artisan builds:stalled` — a triage listing in the shape of the existing `catalog:unmatched`
  command. Self-contained, so the surface works the day it merges with no frontend dependency.

## 9. Email copy

`WelcomeMail` gains a `connectedPlatforms` parameter: **platform names only, no counts** (owner,
2026-09-03). The template lists them above the existing three to-dos.

An empty array must render today's copy verbatim. A thin scrape that connected nothing is a real and
not-rare case (`thin_scrape_at` exists precisely for it), and it must degrade to the current email
rather than to an empty list or a dangling sentence. This is a test case, not a hope.

`ClaimInviteMail` copy is unchanged — only its timing moves.

## 10. Testing

**Unit — `outcome()`:** all four states, with `settled` vs `ceiling` the discriminating pair (a build
that is done only because of the ceiling must return `ceiling`, never `settled`).

**Feature — the sweep:**
- settled + claimed → one `WelcomeMail`, `settled_at` and `welcomed_at` stamped
- settled + unclaimed + published + `auto_invite` → one `ClaimInviteMail`
- ceiling → `setup_stalled_at` stamped, **zero mail**
- failed → `setup_stalled_at` stamped, **zero mail**
- a build outside the 30-minute window is never a candidate
- settled + **already claimed** outreach build → **zero** `ClaimInviteMail` (guards §6)

**Feature — ordering:** claim-then-settle and settle-then-claim each send exactly one welcome; running
both the sweep and a claim over the same build sends exactly one.

**Feature — release:** release-then-reclaim sends a second welcome (guards the §7 `welcomed_at` clearing
obligation).

**Feature — staff override:** the manual invite endpoint still sends on an unsettled build.

**Existing tests that must change:** `ClaimSiteServiceTest`'s welcome-mail assertions currently expect a
send at claim on an unsettled build. They become "no mail at claim when unsettled" plus a settled-build
case. `GeneratePreAccountSiteJobTest:139` and `ApproveEarlyAccessBuildJobTest:82,116` assert
`ClaimInviteMail` queued from the job; those sends move to the sweep.

## 11. Risks

**PG lane drift.** Three new columns on `core.pre_account_builds`. Any `tests/Postgres/` stand-in that
provisions that table must declare them, or the lane goes red on a green SQLite run — this repo's
most-repeated failure mode. `PostgresLaneReadCoverageTest` runs in the cheap `composer test` lane and
should catch it; fix a finding by ADDING the column, never by thinning a stand-in.

**Deploy-window double-send.** A build claimed in the ~30 minutes before deploy already received the
old claim-time welcome, and still falls inside the sweep window — so it could receive a second. At
most a handful of rows. Eliminable with a config cutover timestamp bounding the sweep's lower edge;
not included by default, and cheap to add if the owner wants zero.

**`welcomed_at` and release.** Called out in §7 because forgetting it produces a silent failure — the
rightful owner of a released site never gets welcomed, and nothing errors.

## 12. Owner decisions on the record (2026-09-03)

| Decision | Ruling |
|----------|--------|
| Scope | **Both lanes** — welcome email and outreach invite share one settle event |
| Ceiling case | **No email.** Ceiling-done is not settled |
| Unhappy path | **Give up at the ceiling**, surface to staff; no retry, no fallback email |
| Cutover | **No backfill.** New builds only, via the sweep's time window |
| Staff surface | **Both** — a column on the row and an artisan command |
| Manual staff invite | **Staff can override** the settle gate |
| Email copy | **Platform names only**, no counts |
| Mechanism | **Sweep**, with the ledger observer deferred as a later latency optimisation |
