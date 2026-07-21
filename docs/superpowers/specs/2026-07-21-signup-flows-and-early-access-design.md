# Signup Flows & Early Access — Design Spec

- **Date:** 2026-07-21
- **Status:** Approved design (pre-implementation)
- **Author:** Josh + Claude (brainstormed)
- **Scope:** Backend (Partna). Frontend early-access form change is a flagged dependency in a separate repo.

---

## 1. Context & motivation

Partna is site-first: we build a real but **provisional** site for someone *before* they have an account, and they later **claim** it. Three de-facto entry points exist today, but they're inconsistent and one is a dead end:

- **Normal self-serve signup** (`POST /api/public/signup/build` → `POST /api/claim`) — builds an unpublished site, but claiming does **not** publish it (publishing is a separate dashboard action).
- **Staff / ManyChat cold builds** (`POST /api/staff/builds`) — builds and publishes immediately, but there is **no notification layer** (the claim link is expected to travel out-of-band) and no email support.
- **Early access** (`POST /api/public/early-access`) — currently **pure email capture** (email + free-text platform names). It creates no site, no user, and its invite-token loop is dead (`BootstrapController` no longer consumes the token; `EarlyAccessService::markSignedUp()` has no production caller).

**Goal:** three coherent signup flows that share **one build engine and one claim engine**, differing only in a small set of knobs. Early access becomes a real build-and-claim flow with a staff approval gate.

All three ride the existing rails: `PreAccountBuildService::requestBuild()` → `GeneratePreAccountSiteJob` → `ClaimSiteService::claim()`. This is orchestration + a notification port + a few columns — **not** a new subsystem.

**Rejected alternatives:** a separate marketing/campaign subsystem (over-built for pre-beta); folding `early_access_signups` into `pre_account_builds` (throws away the top-of-funnel list that already has retention, GDPR export/purge, and staff segments wired to it).

---

## 2. The unifying model

**Build first → notify → claim → owned.** A provisional `unclaimed` user + a site are created and populated by scraping a public source (Instagram via Apify, Google Business via the official Places API). The person is told their site is ready; they authenticate (Supabase email-OTP) and claim it, which binds their auth + email and flips the account to `active`.

The knobs that vary per flow:

| Knob | Flow 1 Normal | Flow 2 Cold / ManyChat | Flow 3 Early access |
|---|---|---|---|
| Who triggers the build | the person (self-serve) | ManyChat / staff | the person, then staff approves |
| Publish timing | **on claim** | **at build (immediate)** — the hook | **on claim** |
| Notification | none (they're already here) | ManyChat DM + email (if available) | email |
| Claim gating | first-come (built anon, claimed at once) | **email-gated iff an email is on file**, else first-come | **email-gated** (always) |
| Expiry | n/a (claimed immediately) | 30 days from build | none until approved, then 30 days |
| IG refresh treadmill | active (claimed at once) | active (published, bounded 30d) | **off until approved**, then active |

---

## 3. Cross-cutting components

### 3.1 Notification port — `ClaimNotifier`

One concept — "invite this person to claim their site" — that fans out to every channel we have contact info for.

- `ClaimNotifier::notify(PreAccountBuild $build)` dispatches to each available channel driver.
- **Email driver (build now):** a `Mailable` sent with `->to($build->contact_email)` **directly** — *not* `$user->notify()`, because provisional users have no mail route (`User::routeNotificationForMail()` is null by design). Carries the claim link.
- **DM driver (deferred seam):** a `DmChannel` interface + a no-op/log stub. The real implementation (an open-source ManyChat alternative) lands later as **one driver class** with no change to the build/claim core. The build response also surfaces the claim link so an external ManyChat automation can DM it in the interim.
- **Claim link format:** `{frontend_url}/claim/{subdomain}` (exact shape confirmed with frontend during implementation).

`notify()` is a no-op when a build has no contact channels (Flow 1).

### 3.2 Email-gated claim — driven by email presence, not by flow

The rule is **not** per-flow. It is:

> **If a build has a `contact_email`, the claim is gated on it. If it doesn't, the claim is first-come.**

Enforcement is safe even for scraped emails because the gate leans on Supabase OTP: the claimant must **prove they control the inbox they authenticate with**, and `ClaimSiteService` then checks that the verified email equals the build's `contact_email` (case-insensitive). A scraped or staff-supplied email can only *narrow* who may claim — it never weakens security.

- Flow 1: no email collected at build → first-come (they claim seconds later anyway).
- Flow 2: `contact_email` present (ManyChat-supplied, staff-supplied, or harvested from Google Business) → gated; absent → first-come.
- Flow 3: `contact_email` = the early-access signup email → always gated.

**Safety valve:** a wrong scraped email (shared `info@`, agency inbox, stale) would lock the real owner out. Staff can **clear `contact_email` on a build** to drop it back to first-come.

Because gating derives from `contact_email` presence, we likely do **not** need a separate `claim_lock` column. (If a future case wants an email for notify-only *without* gating, revisit; YAGNI for now.)

### 3.3 Auto-publish on claim

`ClaimSiteService::claim()` sets `is_published = true` on successful claim (Flows 1 & 3; a no-op for Flow 2, which is already published). Publishing currently requires a non-empty `display_name`; scraped sites populate it, but add a **fallback to the handle** if the scrape left it empty, so a claim can never fail the publish guard.

### 3.4 Freshness & the refresh treadmill (split by platform)

**Verified current behavior:** after a *successful* build, both Instagram and Google Business pre-account connections end up `is_active = true` (IG's `is_active=false` in `InstagramSourceGenerator` is a transient pre-seed placeholder; `InstagramConnectionSeeder` flips it `true`). The refresh dispatcher (`integrations:refresh` → `IntegrationConnection::scopeDueForRefresh`) filters only on `is_active` + TTL (~12h `refreshEvery()`) + failure count — it has **no publish/claim/status filter**. So today every built pre-account site, dark or not, rides the ~12h refresh treadmill.

**Decision — split by vendor risk profile:**

- **Google Business** refreshes via the **official Places API** (cheap, sanctioned). Early-access GBP sites **stay on the treadmill** while dark. Approval does **not** need to scrape them — content is already ≤12h fresh (an optional cheap on-demand top-up is allowed but not required).
- **Instagram** refreshes via **Apify** (the flagged legal red path). Early-access IG sites must **not** be refreshed while dark and unapproved — we never want to Apify-scrape a stranger we haven't chosen to invite, indefinitely. They are kept **off the treadmill until approved**; approval **activates** the connection and performs the single fresh scrape.

**Implementation note (resolve in the plan):** keeping dark early-access IG off the treadmill must not break the **staff preview** render of that dark site. Two candidate mechanisms — (a) leave the connection `is_active=false` until approval (simple, but verify the preview render path does not require `is_active=true`), or (b) keep `is_active=true` for rendering and add an explicit exclusion to the refresh dispatcher for connections belonging to unapproved early-access builds. Pick whichever keeps `is_active` semantically clean and preserves preview rendering; verify against `PublicSiteResolver` / `public_site_payload` and the staff preview path.

This applies to the **early-access dark pool** specifically. Flow 2 cold IG builds are published (freshness matters) and bounded by a 30-day expiry, so they keep current treadmill behavior.

### 3.5 Early-access build lifecycle & pruning

- **Signup:** build the site immediately (dark), so staff can preview it. `expires_at = NULL` — **no expiry until approved** (a warm lead's pre-built site is never silently pruned before staff review it).
- **Approval:** starts a 30-day claim window (`expires_at = now()+30d`), makes the build healthy (rebuild if the signup-time build failed), refreshes per §3.4, and notifies.
- **Unclaimed after the window:** pruned by `builds:prune-expired`; staff can re-approve to rebuild.
- **`builds:prune-expired` must treat `expires_at IS NULL` as "never expires"** so dark unapproved early-access builds are not deleted.

---

## 4. The three flows (end to end)

### Flow 1 — Normal self-serve
1. Visitor submits their IG/Google source → `POST /api/public/signup/build`.
2. `requestBuild(publish: false)` → provisional `unclaimed` user + **dark** site; scrape populates it.
3. Visitor previews (frontend renders the build payload).
4. Visitor finishes signup → Supabase OTP → `POST /api/claim` → binds auth + email, `status=active`, **auto-publishes**, KV permanent.
5. Claim gating: first-come (no `contact_email` on file).

**Change vs today:** claim auto-publishes (§3.3).

### Flow 2 — Cold / ManyChat marketing
1. ManyChat/staff → `POST /api/staff/builds` with a scraped IG/Google source and an **optional `contact_email`**.
2. `requestBuild(staff, publish: true, contact_email?)` → provisional user + site; scrape; **publishes immediately** with a 30-day KV TTL.
3. `ClaimNotifier::notify()` → email (if `contact_email`) + DM (deferred stub); claim link also in the build response.
4. Claim: `POST /api/claim` → email-gated iff `contact_email` present, else first-come → binds auth + email, `status=active` (already published), KV permanent.
5. Expiry: unclaimed → KV TTL + `builds:prune-expired` at 30 days.

**Change vs today:** optional `contact_email`; `ClaimNotifier` fires; email-gating when an email exists.

### Flow 3 — Early access
1. Visitor submits **email + IG/Google source** → `POST /api/public/early-access`.
2. Create/refresh the `early_access_signups` row **and** `requestBuild(built_via: 'early_access', publish: false, expires_at: NULL, contact_email: email)` → provisional `unclaimed` user + **dark** site; scrape; IG connection kept **off the treadmill** (§3.4). Link the signup row to the build/user.
3. Staff review the pre-built sites on the dashboard and **approve** — individually or in bulk.
4. Approval → `ApproveEarlyAccessBuildJob`: make the build healthy → for IG, activate + fresh-scrape; for GBP, trust the treadmill → set `expires_at = now()+30d` → `early_access_signups.status = 'invited'` → `ClaimNotifier::notify()` (email). Bulk approvals fan these jobs out through the existing per-provider `RateLimiter`.
5. Person authenticates with their **signup email** → `POST /api/claim` → **email-gated** (verified email must equal `contact_email`, else `CLAIM_EMAIL_MISMATCH`) → binds auth + email, `status=active`, **auto-publishes**, KV permanent, `early_access_signups.status = 'signed_up'` (finally re-closing the dead loop).

**Change vs today:** early access builds a real site, captures a resolvable source, gains a staff approval gate + notification, and its status lifecycle is wired end to end.

---

## 5. Data model changes (raw SQL in `supabase/migrations/`)

**`core.pre_account_builds`:**
- `contact_email text NULL` — the notify address + the email-gate value. Set for Flow 3 (signup email) and optionally Flow 2.
- Widen the `built_via` CHECK to add `'early_access'` (currently `'signup' | 'staff'`).
- `expires_at` semantics: `NULL = never expires` (unapproved early-access). Verify the column already allows NULL; update `PruneExpiredPreAccountBuilds` to treat NULL as non-expiring.

**`core.early_access_signups`:**
- Add `source_type text NULL` + `source_ref text NULL` (resolvable source) — required to build. The free-text `platforms` jsonb is superseded (keep the column for now for back-compat / retention, but the redesigned form writes structured source).
- Add a link to the provisional build: `user_id uuid NULL` FK to `core.users` (or `pre_account_build_id`) — decide in the plan; `user_id` is simplest since one provisional user maps 1:1 to one live build.
- Reuse `status`: `'waitlist'` (built, awaiting approval) → `'invited'` (approved + notified) → `'signed_up'` (claimed).
- Keep existing consent/retention/invite columns; the dead invite-token mechanism can remain unused (or be removed in a follow-up — out of scope here).

**Constraints reminder:** SQLite tests don't enforce Postgres CHECK / NOT NULL. Verify the widened `built_via` CHECK and the new columns against the real DDL, per the schema-drift rule.

---

## 6. API changes

- **`POST /api/public/early-access`** — extended: accept `source_type` + `source_ref` (validated against the `config('partna.pre_account.sources')` account_type↔source pairing), keep anti-bot (honeypot + timing) + throttle + thank-you email, and trigger the dark build. **Contract change — frontend early-access form must collect a real IG handle / Google business (separate-repo dependency).**
- **`POST /api/staff/builds`** — extended: optional `contact_email`.
- **`POST /api/staff/early-access/{id}/approve`** (new) and **bulk approve** (new; individual ids or "all") — dispatch `ApproveEarlyAccessBuildJob`. Staff-gated (JWT + AAL2 + policy), consistent with existing staff early-access routes.
- **`POST /api/claim`** — extended: enforce the email-gate (`CLAIM_EMAIL_MISMATCH`, 409) and auto-publish on success.
- Staff dashboard **list/preview** of early-access pre-built sites (reuse existing staff early-access list + surface the linked site for preview).

---

## 7. Services / jobs

- **`ClaimNotifier`** + `EmailClaimDriver` (built) + `DmClaimDriver` (deferred stub) + the claim `Mailable`.
- **`ApproveEarlyAccessBuildJob`** — health check / rebuild-on-failure, platform-conditional refresh (§3.4), set claim window, flip status, notify. Idempotent (re-approve is safe).
- **`ClaimSiteService`** — add email-gate enforcement + auto-publish.
- **`PreAccountBuildService::requestBuild()`** — accept `built_via: 'early_access'`, `contact_email`, and `expires_at: null`; ensure the early-access IG path keeps the connection off the treadmill (§3.4).
- **`EarlyAccessService`** — extend `signupFromMarketing` (or a sibling) to capture the source + kick off the build + link the row.

---

## 8. Security & privacy

- **Email-gate reasoning** (§3.2): gating strictly increases claim security; it leans on Supabase OTP proving inbox control.
- **Apify exposure minimization** (§3.4): dark, unapproved early-access IG sites are never Apify-scraped on a schedule — only when deliberately approved.
- **PII:** `contact_email` is new stored PII on `pre_account_builds` — include it in GDPR export/purge coverage and account-deletion teardown (mirror how `early_access_signups.email` is already handled). Provisional users still have a null mail route; the claim email goes to `contact_email` directly, never via `$user->notify()`.

---

## 9. Testing (SQLite-vs-Postgres aware)

Feature tests, per flow:
- EA signup builds a dark, linked site; the IG connection is off the treadmill; `expires_at` is NULL.
- Staff approval (individual + bulk) makes a failed build healthy, refreshes per platform, opens the 30-day window, sets `status='invited'`, and sends exactly one claim email.
- Email-gated claim **rejects** a mismatched verified email (`CLAIM_EMAIL_MISMATCH`) and **accepts + auto-publishes** a matching one; `status='signed_up'`.
- Flow 2 with `contact_email` notifies and is email-gated; without it, notifies via DM stub and stays first-come.
- Flow 1 claim auto-publishes.
- `builds:prune-expired` spares NULL-`expires_at` early-access builds and prunes expired approved-but-unclaimed ones.
- Verify the widened `built_via` CHECK + new columns against the real Postgres DDL (constraint drift).

---

## 10. Out of scope

- The DM driver implementation and the open-source ManyChat-alternative integration (interface + stub only here).
- Frontend early-access form changes (separate repo — flagged dependency).
- Removing the dead invite-token machinery on `early_access_signups` (leave dormant).

---

## 11. Open implementation details (for the plan to resolve)

1. Treadmill-exclusion mechanism for dark early-access IG vs. preview-render dependency on `is_active` (§3.4).
2. Link column on `early_access_signups` (`user_id` vs `pre_account_build_id`).
3. Exact claim-link URL contract with the frontend.
4. Whether `contact_email` presence alone drives gating (drop `claim_lock`) — confirmed intent yes; verify no path needs notify-without-gating.
5. Bulk-approve request shape (list of ids vs "all matching filter") and its rate-limit fan-out.
