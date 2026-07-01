# PRIV-1 — Instagram Apify Scrape-and-Rehost: Consent & Auto-Sync Gating

**Date:** 2026-07-02
**Finding:** PRIV-1 (P1) — Connecting Instagram sends a username + scraped profile
media to Apify (US processor) and permanently re-hosts that media into Partna R2,
with no consent step; the same scrape also fires automatically as a side-effect of
a Google Business connect. Neither path shows a consent screen or records a
disclosure/consent event.
**Status:** ⚠️ Decision-support. This is an engineering + decision plan, not legal
advice, and NOT an approval to ship a consent screen. Read §1 first — the gating
question is whether code is even the right next step.

---

## 0. Confirmed code paths (the blast radius)

| Path | File:line | What it does |
|---|---|---|
| Scrape call | `app/Services/Platforms/InstagramScraper.php:29-42` | `fetchProfile()` POSTs `{usernames:[$username], resultsLimit:24}` to Apify actor `apify~instagram-profile-scraper` (`run-sync-get-dataset-items`). No consent check. |
| Job fetch | `app/Jobs/Platforms/InstagramConnectJob.php:96` | `handle()` calls `$scraper->fetchProfile()`. |
| Rehost target | `InstagramConnectJob.php:110` | Folder `platforms/instagram/{connection->created_at->timestamp}`. |
| Rehost writes | `InstagramConnectJob.php:218` (`mirrorOne` → `Storage::disk('media')->put`), `:271` (`mirrorVideo`) | Downloads IG CDN media → **permanent** R2 objects. |
| Manual entrypoint | `app/Http/Controllers/Api/Platforms/InstagramController.php:35-90` (dispatch at `:84`) | `connect()` writes a `pending` placeholder and dispatches `InstagramConnectJob`. No consent gate; only a global Apify budget guard (`:151`). |
| Auto-sync entrypoint | `app/Services/Platforms/GoogleBusinessAutoSync.php` — `seed()` `:69` → `seedSocials()` `:511` → `seedInstagram()` `:523-544` → `dispatchInstagram()` `:550-573` (dispatch at `:570`) | A Google Business connect (via `app/Jobs/Platforms/GoogleBusinessEnrichJob.php:85` `$autoSync->seed(...)`) fires the IG scrape with **no fresh user action** and no consent gate. |
| Second auto entrypoint | `GoogleBusinessAutoSync.php:98` (`applyFinding()` → `dispatchInstagram()`), reached from `app/Http/Controllers/Api/Platforms/GoogleBusinessController.php:180-201` (`applySync`) | The "Change to" flow re-dispatches the same scrape. |

Both dispatch sites share one chokepoint (`InstagramConnectJob`) but two authorisation
contexts (an explicit `connect` request vs. a background seed). Any gate must cover both.

---

## 1. Legal / product blockers — RESOLVE FIRST (the gating decision)

**Bottom line: a consent screen in front of the Apify scrape does not fix PRIV-1,
and shipping one as "the fix" may make Partna's position worse.** The prior legal
reviews already deem this path unshippable. Code is very likely *not* the right next
step; the right next step is a product/legal decision to kill the scrape-and-rehost
path. Do not merge §2/§3 engineering until the questions below are answered.

### 1.1 The prior legal review already ruled on this
- `docs/legal/2026-06-01-scraping-legal-review.md` (which **supersedes**
  `2026-05-31-platform-integrations-legal-review.md` — the "2026-05-31 review" the
  finding cites) rates **Instagram — Apify + re-host photos to R2 as 🔴 CRITICAL**
  (§1 verdict table; §3.1). Its §8 remediation order, item 2: *"Stop Instagram Apify
  scraping + photo re-hosting; delete re-hosted third-party photos."* Item 6: *"never
  re-host copyrighted media without a licence … Australian counsel sign-off before
  launch."*
- `docs/legal/2026-06-13-integrations-legal-review.md` §4 ("The consent question —
  what a 'do you agree' layer can and cannot do", lines 112-132) is directly on point
  and dispositive for this finding:
  - *"consent is a necessary ingredient of the green path and worthless against the
    red path. It is not a wrapper that legalises scraping."*
  - The four hard limits: a user **cannot waive Meta's ToS** (the Apify collection
    breaches Meta §3.2 "regardless of whether … logged in", whoever owns the data);
    **cannot consent for third parties** in the photos (*Clearview* [2021] AICmr 54:
    "publicly available" ≠ free to collect); cannot license content they don't own;
    and **consent doesn't cure the access method**.
  - Explicit warning (X4, line 127 / 276): a clickwrap that makes users **warrant
    rights they don't have** is *"worse than nothing"* — it evidences that Partna knew
    rights were required, binds no platform, and is itself misleading conduct (ACL s18)
    that *"will not survive acquisition diligence."*
  - Findings X4/X5/X6 (lines 205-207): no consent/rights-warranty exists anywhere in
    the integration flow; no ownership verification (arbitrary username accepted); and
    third-party PII is stored — a user can't consent for the people in the photos.

**Interpretation for PRIV-1:** the finding names the missing consent step as the
defect, but the reviews are explicit that a consent step **belongs in front of the
green path (official Instagram Graph API + Instagram Login OAuth → embed, not re-host,
+ a display-licence/rights-warranty), not in front of the Apify scraper.** Bolting a
consent modal onto `InstagramController::connect()` while it still Apify-scrapes and
re-hosts to R2 addresses the *disclosure* gap but leaves the CRITICAL copyright/ToS/
third-party-privacy exposure fully intact — and the false rights-warranty risk makes
it net-negative.

### 1.2 Questions that must be answered before any code lands
1. **Is Apify a documented sub-processor with a signed DPA + SCCs for the US
   transfer?** (Grep-confirmed: nothing in `docs/` documents Apify as a processor.)
   If not, the transfer at `InstagramScraper.php:37-42` has no lawful basis regardless
   of any in-app consent. Blocker.
2. **Do the ToS / Privacy Policy disclose the scrape-and-rehost transfer** (that a US
   processor receives the username and that IG media is copied into Partna storage)?
   APP 5 disclosure + APP 8 cross-border obligations. If not disclosed, in-app consent
   text cannot retroactively cure it.
3. **Does the 2026-05-31 → 2026-06-01 review's CRITICAL/unshippable rating still
   stand, and if so, is code the right next step at all?** Per §8 item 2 and the
   2026-06-13 §4 conclusion, the sanctioned remediation is *stop the scrape + re-host
   and move to OAuth + embed*, not *add a consent screen to the scraper*. **Recommended
   answer: the correct engineering ticket is the OAuth-Graph-API/embed migration
   (kill the Apify path), and PRIV-1's "no consent step" is a symptom of shipping the
   red path at all.** §2/§3 below should be read as *"how to gate the scrape safely
   IF, and only until, it is killed"* plus *"where the real consent/licence layer
   goes once the path is OAuth+embed."*
4. **Retention rationale for indefinitely-rehosted R2 media.** Today the rehost lives
   under `platforms/instagram/{timestamp}/` (`InstagramConnectJob.php:110`) with **no
   TTL** and is only reclaimed on disconnect/overwrite via
   `app/Observers/Core/IntegrationConnectionObserver.php:55` +
   `app/Jobs/Platforms/DeleteMirroredMediaJob.php`. There is no documented retention
   period, no minimisation, and third-party PII (people in the photos) is persisted
   (finding X6). What is the lawful retention basis and period? (See §4.)
5. **Australian tech-counsel sign-off** on any path that continues to fetch via Apify,
   per 2026-06-01 §8 item 6.

### 1.3 Decision gate (what §2–§4 are conditional on)
- **Decision A (recommended):** Kill the Apify scrape + R2 re-host; migrate to
  Instagram Graph API w/ Instagram Login (OAuth) → **embed, don't re-host** → capture
  a display-licence + rights-warranty. Then §2 becomes the consent/licence layer in
  front of that OAuth path, and §3's auto-sync gate becomes "auto-sync only ever
  deep-links / never scrapes."
- **Decision B (interim, only with counsel sign-off + DPA + disclosure in place):**
  Keep the scrape temporarily behind an explicit, itemised, per-connect consent gate
  **and** an append-only consent/disclosure record, and hard-gate the auto-sync path.
  This does **not** cure copyright/third-party exposure — it only closes the
  disclosure/consent-record gap and stops silent auto-scraping. Time-boxed to the
  OAuth migration.

Everything below is written for Decision B (the interim gate) because that is the
narrow slice PRIV-1 asks for; each piece is reusable for Decision A's licence layer.

---

## 2. Consent-step engineering plan (conditional on §1)

### 2.1 Where the gate goes
- **Manual path:** a new guard at the top of
  `InstagramController::connect()` (`app/Http/Controllers/Api/Platforms/InstagramController.php:35`),
  **before** the placeholder write and the `InstagramConnectJob::dispatch(...)` at
  `:84`. It runs alongside the existing `guardApifyBudget()` pattern (`:151`): a
  private `guardConsent()` that returns a `JsonResponse` (409/422) when no valid,
  current consent record exists for this user.
- The consent itself is captured as a **separate, explicit user action** (its own
  endpoint), not inferred from the connect call — the finding's defect is precisely
  that the scrape fires with no distinct consent event. A single endpoint that both
  records consent *and* connects would re-hide the consent inside the connect.

### 2.2 How/where to persist the consent event — reuse, don't invent
The codebase already has an append-only audit pattern in the `audit` schema. **Reuse
it; do not invent a new persistence mechanism.**

- **Append-only audit trail (name it):** `audit.moderation_events` — model
  `app/Models/Moderation/AuditEvent.php`, single write path
  `app/Services/Moderation/ModerationAuditService.php`
  (`recordSystemAction()` / `recordStaffAction()`), DDL at
  `supabase/migrations/20260528000000_create_moderation_schema.sql:159-176`
  (UUID id, `actor_kind`, `action`, `target_type`, `target_id`, JSONB `payload`,
  `created_at`; append-only — `app_backend` has SELECT/INSERT only, enforced by
  `supabase/migrations/20260607000000_restrict_app_backend_append_only_grants.sql`).
- **Sibling append-only log:** `core.staff_audit_log` — model
  `app/Models/Core/Staff/StaffAuditEntry.php` (`const UPDATED_AT = null`), service
  `app/Services/Audit/StaffAuditService.php`. This is the shape to copy for a
  *user*-actor consent log.
- **Consent-column precedent already in the schema:** `waitlist_signups` and
  `email_subscriptions` carry `consent_source`, `consent_ip_hash`, `consent_user_agent`
  (`supabase/migrations/20260526000000_baseline_standalone_user.sql:442-444` and
  `:1094-1096`). Mirror these exact column names for consistency.

**Recommended persistence:** a new append-only table
`audit.platform_consent_events` (migration in `supabase/migrations/` **only** — per
`database/migrations/README.md`, Laravel `database/migrations/` is Supabase-only), plus
a thin `PlatformConsentService::record(...)` modelled on `StaffAuditService::record()`.
Columns (mirroring existing conventions):

| Column | Notes |
|---|---|
| `id uuid PK default gen_random_uuid()` | as `audit.moderation_events` |
| `user_id uuid` FK `core.users(id)` | the consenting professional |
| `platform text` | `'instagram'` (extensible) |
| `action text` | e.g. `'scrape_rehost_consent_granted'` / `'_revoked'` |
| `subject_ref text NULL` | the IG username consented for (ownership scope; supports X5) |
| `disclosure_version text` | the exact ToS/PP/consent-copy version shown (so a later copy change invalidates stale consent) |
| `consent_source text` | e.g. `'ig_connect_modal'` |
| `consent_ip_hash text NULL`, `consent_user_agent text NULL` | mirror `waitlist_signups` |
| `payload jsonb default '{}'` | itemised acknowledgements (see 2.3); **no raw PII** — reuse `ModerationAuditService::scrubPii()`'s denylist approach |
| `created_at timestamptz default now()` | append-only; `UPDATED_AT = null` |

Grants: `GRANT SELECT, INSERT` only to `app_backend` (copy the pattern in
`20260607000000_restrict_app_backend_append_only_grants.sql`). Revocation is a **new
row**, never an UPDATE/DELETE — same discipline as `moderation.decisions`.

`guardConsent()` validates: a `granted` row exists for `(user_id, platform,
subject_ref=username)` with `disclosure_version` == the current version and no later
`revoked` row. Ownership scope (`subject_ref` == the username being connected)
directly addresses X5 ("no ownership verification").

### 2.3 API shape
- `POST /api/platforms/instagram/consent` (add to
  `routes/api/integrations.php` inside the existing `{$base}/instagram` group,
  `:89-96`, same `$middleware`).
  - **Request body (what the frontend must send):**
    ```json
    {
      "username": "the.handle",
      "disclosureVersion": "2026-07-02",
      "acknowledgements": {
        "ownContent": true,          // "This is my account / I own this content"
        "displayLicence": true,      // "I grant Partna a licence to display it"
        "processorTransfer": true,   // "media + username sent to Apify (US) and stored by Partna"
        "noThirdParties": true       // "I won't surface others' content" (see limits below)
      }
    }
    ```
    All four must be explicitly `true` (checkboxes, not pre-ticked — APP-grade
    affirmative consent). The server records one `audit.platform_consent_events`
    row with `action='scrape_rehost_consent_granted'`, `subject_ref=username`,
    `disclosure_version`, `consent_ip_hash` (hash of `request()->ip()`),
    `consent_user_agent`, and the `acknowledgements` map in `payload`.
  - **Response:** `201` `{ "consent": "granted", "expiresWith": "disclosureVersion" }`.
- `DELETE /api/platforms/instagram/consent` → append a `_revoked` row (does not delete
  history).
- `POST /api/platforms/instagram/connect` (`InstagramController::connect`) now returns
  `409 { "error": "consent_required", "consentUrl": "…/instagram/consent",
  "disclosureVersion": "2026-07-02" }` when `guardConsent()` finds no current grant;
  the frontend shows the itemised modal, POSTs consent, then retries connect.

> ⚠️ **Legal caveat that must be honoured in the copy (from 2026-06-13 §4 / X4):** do
> **not** ask the user to *warrant they hold rights to a brand's / third parties'
> content*. The `noThirdParties` acknowledgement and the ownership scope exist to
> narrow, not launder, the claim. If the account can't clear the four limits, the
> honest outcome is that the scrape path is not available — which is exactly why
> Decision A (OAuth + embed) is the real fix.

### 2.4 Frontend contract
- Reuse the existing async-connect contract doc
  `docs/frontend-contracts/instagram-connect-async.md`: add the `409 consent_required`
  precondition and the consent POST as step 0 of the connect flow. The itemised
  disclosure copy (processor = Apify US, storage = Partna R2, retention period from §4)
  is rendered from `disclosureVersion` so copy changes bump the version and re-prompt.

---

## 3. Auto-sync gating plan

**Requirement:** a Google Business connect must **never** trigger an IG scrape without
an explicit *prior* consent record. Today `seedInstagram()` →
`dispatchInstagram()` fires unconditionally (subject only to token + Apify budget at
`GoogleBusinessAutoSync.php:552`).

### 3.1 The exact check and where it goes
- Add the consent check inside **`GoogleBusinessAutoSync::dispatchInstagram()`
  (`app/Services/Platforms/GoogleBusinessAutoSync.php:550-573`)** — the single
  chokepoint reached by *both* auto-sync callers (`seedInstagram()` at `:539` and
  `applyFinding()` at `:98`). Put it at the very top, before the token/budget check:

  ```php
  // No fresh user action here — an auto-sync IG scrape requires a PRIOR,
  // explicit consent record for THIS user+username (PRIV-1). Absent it, we
  // do not scrape; the finding is downgraded to a link/deep-link (below).
  if (! app(PlatformConsentService::class)->hasCurrentConsent($userId, 'instagram', $username)) {
      return false;   // same contract as "no token / budget spent": nothing seeded
  }
  ```

  Returning `false` reuses the existing "nothing seeded" contract — `seedInstagram()`
  already treats `false` as "no card" (`:539-540`), so the Google-Business connect
  simply doesn't produce an IG scrape. No exception, no torn row.

- **Do not** move the gate up into `seedSocials()`/`seed()` — keeping it in
  `dispatchInstagram()` guarantees both the initial seed and the "Change to"
  (`applyFinding`) re-dispatch are covered by one check, and keeps the account-type
  read where it belongs.

### 3.2 Product decision for the no-consent auto-sync case
Because the whole point of auto-sync is "no fresh user action," a prior consent record
will usually **not** exist at Google-Business-connect time. Two acceptable behaviours
(pick in §1):
- **(a) Link-only seed (recommended, matches the other socials):** instead of
  scraping, write a link-only Instagram row from the discovered URL — exactly how
  `seedSocials()` already handles facebook/tiktok/x/linkedin (`GoogleBusinessAutoSync.php:483-503`)
  — and surface a "Connect Instagram to show your latest post" CTA that routes through
  the §2 consent gate. No scrape, no rehost, no consent needed for a plain link.
- **(b) Deferred scrape:** seed a `pending`/`needs_consent` card that does nothing
  until the user completes the §2 consent flow, which then dispatches the job.

Either way, `dispatchInstagram()` never scrapes without a prior consent row.

### 3.3 Keep AccountCapabilities as the sanctioned account-type gate
The social seeds are already gated on `google_business_full_sync`
(`GoogleBusinessAutoSync.php:62`; capability defined in
`app/Services/Accounts/AccountCapabilities.php:51`, derived from `isBusiness()`). That
gate stays and is **orthogonal** to consent: capability answers "is this account type
allowed the feature," consent answers "did this specific user agree to this specific
scrape." Do **not** overload a capability flag to carry per-user, per-username consent
— consent is a per-connect *event* (the `audit.platform_consent_events` row), not a
capability. Defence-in-depth: capability gate (account type) **and** consent gate
(per-user event) both apply.

---

## 4. Data minimisation — retention / re-sync policy for rehosted R2 media

Current state: `InstagramConnectJob.php:110` writes media to
`platforms/instagram/{connection.created_at.timestamp}/` with **no TTL**; cleanup only
happens on disconnect/overwrite via `IntegrationConnectionObserver.php:55` +
`DeleteMirroredMediaJob`. This is the "permanently re-hosted" defect and stores
third-party PII (X6).

Minimisation plan (all conditional on §1; strongest form is "don't re-host at all"):
1. **Preferred (Decision A): don't copy — embed / hot-link.** The green-path rule from
   both reviews ("hot-link, don't copy") removes the copyright-reproduction act
   entirely. No R2 object, no retention question. This is the real fix.
2. **If re-hosting persists (Decision B, interim):**
   - **Bounded retention TTL.** Treat the rehost as a short-lived cache, not
     permanent storage — e.g. media expires N days after `last_refreshed_at` and is
     re-derived on next refresh. Implement via an R2 lifecycle rule on the
     `platforms/instagram/` prefix and/or a scheduled reaper that reuses
     `DeleteMirroredMediaJob` for connections whose `last_refreshed_at`
     (`platform_connections`, `supabase/migrations/20260602150238_create_platform_connections.sql:23`)
     is older than the TTL. Retention period is a §1 legal input, not an engineering
     default.
   - **Re-sync policy: overwrite-in-place, never accumulate.** The folder is already
     keyed on `created_at.timestamp`, so a re-scrape reuses the same prefix (good), but
     confirm the observer reclaims the *old* `_folder` on username change
     (`IntegrationConnectionObserver.php:55` notes a changed `_folder` orphans the old
     one — verify the reaper covers orphans).
   - **Minimise what's stored.** Store only the single latest cover the product needs
     (already the case — one photo + one reel), never comments, captions of others, or
     other users' media (X6). Consider dropping the profile-pic rehost if it's not
     load-bearing.
   - **Deletion on revoke.** A §2 consent revoke (`DELETE …/consent`) must enqueue
     `DeleteMirroredMediaJob` for that connection's `_folder` and soft-delete/clear the
     connection — consent withdrawal ⇒ data erasure, not just "stop future scrapes."
   - **Cascade on account deletion / erasure** — verify the existing deletion lifecycle
     already reclaims `platforms/instagram/` (cross-check against the account-deletion
     service).

---

## 5. Test plan (Pest)

Model on the two existing suites: `tests/Feature/Platforms/InstagramAsyncConnectTest.php`
and `tests/Feature/Platforms/GoogleBusinessApifyTest.php` (which already uses
`Bus::fake([InstagramConnectJob::class])` + `Bus::assertNotDispatched(...)`, e.g.
`:321`, `:588`). New/changed tests:

**Consent-required on the manual path** (extend `InstagramAsyncConnectTest.php`):
1. `connect() 409s and dispatches NOTHING when no consent record exists`
   — `Queue::fake(); config apify token`; POST connect; assert `409`
   `error=consent_required`; `Queue::assertNothingPushed()` (or
   `assertNotPushed(InstagramConnectJob::class)`).
2. `POST /instagram/consent records exactly one append-only consent event`
   — assert one `audit.platform_consent_events` row with the four acknowledgements,
   `subject_ref=username`, `disclosure_version`, `consent_ip_hash` set, no raw PII in
   `payload`.
3. `connect() 202s + dispatches the job only after a current consent record exists`
   — grant consent, then connect → `202`, `Queue::assertPushed(InstagramConnectJob…)`.
4. `stale disclosure version re-prompts` — grant at `v1`, bump current version to
   `v2`, connect → `409 consent_required` (no dispatch).
5. `consent scoped to username` — grant for `@a`, connect `@b` → `409` (no dispatch).
6. `revoke appends a row and enqueues DeleteMirroredMediaJob` — grant, connect, revoke
   → assert `_revoked` row + `DeleteMirroredMediaJob` pushed for the `_folder`.

**Auto-sync gated** (extend `GoogleBusinessApifyTest.php`):
7. `Google Business enrichment does NOT dispatch InstagramConnectJob without a prior
   consent record` — business account, enrichment carries
   `socials.instagram=https://instagram.com/fadelab`; run
   `GoogleBusinessAutoSync::seed(...)`; `Bus::assertNotDispatched(InstagramConnectJob::class)`.
8. `…seeds a link-only Instagram card instead` (if Decision 3.2a) — assert a link-only
   IG `IntegrationConnection` row exists, `source=google-business`, no scrape.
9. `…dispatches the scrape when a prior consent record DOES exist` — insert a current
   consent row first, then `seed(...)` → `Bus::assertDispatched(InstagramConnectJob…,
   username==='fadelab')`.
10. `applyFinding("Change to") also refuses to scrape without consent` — mirror the
    existing conflict/apply test (`GoogleBusinessApifyTest.php:576-591`) but with no
    consent row → `Bus::assertNotDispatched(...)`.
11. `capability gate still independently applies` — a non-business account still gets
    no IG social seed regardless of consent (guards against consent short-circuiting
    the `google_business_full_sync` gate at `GoogleBusinessAutoSync.php:62`).

**Persistence discipline:** a unit test asserting `audit.platform_consent_events` is
append-only from the app's perspective (no model `update()`/`delete()` path; revoke =
insert), mirroring the `StaffAuditEntry` / `moderation.decisions` convention.

---

## 6. Open questions for Josh

1. **Decision A vs. B (the actual call):** Given 2026-06-01 §8 item 2 and 2026-06-13
   §4 both say the fix is *stop scraping + re-host, go OAuth + embed*, do we ship the
   interim consent gate (§2/§3) at all, or route PRIV-1 straight to the Graph-API/OAuth
   migration and treat "no consent step" as one symptom of shipping the red path? My
   recommendation: A; use B only as a time-boxed stop-gap **with counsel sign-off**.
2. **Apify DPA / sub-processor status:** is there a signed DPA + documented US-transfer
   basis for Apify? If not, no in-app consent is sufficient and the scrape must pause
   now. (Nothing found in `docs/`.)
3. **ToS / Privacy Policy:** do they currently disclose the Apify transfer and the R2
   re-host? If not, who owns the copy update, and what is the `disclosureVersion` we
   anchor consent to?
4. **Retention period (§4):** what TTL/legal basis for rehosted media if it isn't
   killed outright? Default to "cache, expire on refresh + delete on revoke"?
5. **Third-party content (X6):** are we comfortable that the single-latest-post rehost
   can contain non-consenting third parties, which no user consent can cover? This is
   the part a consent screen cannot fix.
6. **Auto-sync no-consent behaviour (§3.2):** link-only seed (recommended, matches the
   other socials) vs. a deferred "needs consent" card?
7. **Backfill:** what do we do with IG connections + R2 media already scraped under the
   no-consent flow — purge, or grandfather behind a one-time consent prompt? (A purge
   aligns with 2026-06-01 §8 item 2's "delete re-hosted third-party photos.")

### Critical files for implementation
- app/Http/Controllers/Api/Platforms/InstagramController.php
- app/Services/Platforms/GoogleBusinessAutoSync.php
- app/Jobs/Platforms/InstagramConnectJob.php
- app/Services/Moderation/ModerationAuditService.php  (reuse pattern for the consent-event service)
- supabase/migrations/  (new append-only `audit.platform_consent_events` migration — Supabase-only)
