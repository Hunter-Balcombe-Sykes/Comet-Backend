# Triaged execute-audit — P2 — reconciled 2026-07-11+17 full-work sweep

> **▶ To run this file:** `execute audit audits/sweeps/2026-07-11-full-work-sweep/TRIAGE-2-P2.md`
> Fires the fix-flow: branch off `development`, then for each work unit (every **bundle** + every **standalone**, in tier order) **plan (Opus) → implement (Sonnet) → independent Sonnet review → commit** — ticking the box only after tests pass AND review says PASS. **Blocker gate:** P0 · auth · money · DB/migration · L/XL → present the plan and WAIT for Josh's sign-off before implementing. Auto-archives when every box is `[x]`. Full runbook: `scripts/audit/fix-flow.md`.

## This file
- **Tier(s):** P2  ·  **Findings:** 101  (56 carried from 07-11 · 45 new from 07-17)
- **IDs ≥ 100 = 2026-07-17** changed-file findings; **IDs < 100 = carried 2026-07-11**. Same file, same lens sections as the master; sliced to this tier.
- Full context + reconciliation ledger: `CONSOLIDATED.md` (same folder).

## Execution policy
- **Plan:** Opus 4.8 · **Implement:** Sonnet 4.6 · **Review:** separate Sonnet 4.6 (never the implementer).
- **Combine plan+impl:** YES for S/XS · NO for P0/P1 or L/XL. Per-item escalate to Opus for gnarly logic/blast radius.

## Execution grouping — P2 (triage 2026-07-17)

**Merge as one fix (duplicates / same root cause):**
- `SCHEMA-102` ← `SCHEMA-5` — both are `site.sites.shop_link_mode` missing a DB `CHECK` constraint (same column, table, fix). One migration closes both.

**Work units, in recommended execution order:**
1. **Auth/Policy-gate hardening** — `SEC-103`, `SEC-104`, `SEC-105`, `SEC-106`, `SEC-107`, `SEC-108` · missing `authorizeForUser`/Policy gate on mutating controller paths · effort ~M · 🔒 blocker: auth boundary
2. **Auth-middleware hardening** — `SEC-2`, `WHK-3` · JWT revocation-gate + bot-token circuit-breaker fail-open (`Http/Middleware/Auth*`) · effort ~S · 🔒 blocker: auth
3. **Secret-rotation runbook** — `CFG-1` · Supabase/Cloudflare secret rotation (may need `VerifySupabaseHookSignature` change) · effort ~M · 🔒 blocker: auth-adjacent secrets
4. **PII log-hygiene sweep** — `SEC-5`, `SEC-102` · hash-before-log, both extend `PiiLogHygieneSweepTest` · effort ~S · autonomous
5. **Account-deletion lifecycle cluster** — `WHK-2`, `WHK-102`, `TXN-102`, `LIFE-102`, `LIFE-103`, `PRIV-102` · all in/around `AccountDeletionService.php` + `/me/deletion/*` · effort ~M · 🔒 blocker: account-deletion/GDPR lifecycle
6. **MFA auth-hook idempotency anchor** — `WHK-101` · new `webhook_id` column + unique index on `audit.auth_factor_events` · effort ~M · 🔒 blocker: auth + DB migration
7. **Schema CHECK/FK constraint batch** — `SCHEMA-1`, `SCHEMA-2`, `SCHEMA-3`, `SCHEMA-4`, `SCHEMA-5`, `SCHEMA-102`, `DINT-101` · same `NOT VALID`+`VALIDATE` pattern · effort ~M (each ships as its own migration+sign-off) · 🔒 blocker: DB migration
8. **Migration safety & lock-timeout hygiene** — `MIG-3`, `MIG-4`, `SCHEMA-101`, `MIG-101`, `MIG-102`, `MIG-103` · lock/statement timeouts, backfill idempotency, txn-boundary splits (SCHEMA-101 & MIG-101 are the same file) · effort ~M · 🔒 blocker: DB migration
9. **GoogleBusinessEnrichJob hardening** — `LIFE-10`, `JOB-2`, `OBS-6` · same file/method (payload race, retry reruns paid scrape, silent soft-failure) · effort ~M · autonomous
10. **Platform auto-sync (GBP/IG) race + dedup** — `LIFE-105`, `LIFE-106`, `LIFE-107`, `LIFE-108`, `SLOP-101` · `GoogleBusinessAutoSync`/`InstagramAutoSync`/`IdentitySync` — booking-XOR/sector/workplace races + duplicated finding-builder · effort ~M · autonomous
11. **Platform connect/highlights async-fetch pattern** — `LIFE-13`, `LIFE-14`, `LIFE-15`, `LIFE-16`, `LIFE-17`, `LIFE-18`, `LIFE-19`, `LIFE-20`, `LIFE-21`, `LIFE-22`, `LIFE-23`, `LIFE-24` (12 IDs) · identical sync-vendor-fetch-in-request-cycle across 8 Connect + 4 Highlights strategies · effort ~L · autonomous (design shared async pattern once, then fan out)
    - **⏸ DEFERRED IN FULL — Josh's decision, 2026-07-20.** All 12 boxes stay open; needs its own session. Premise verification is DONE, so do not redo it — findings below are confirmed VALID against current code, and all 12 strategies are live (registered in `PlatformRegistryServiceProvider`, routed through `GenericPlatformController`). None is dead code.
        - **No shared seam.** `ConnectStrategy` and `HighlightsStrategy` are BARE interfaces implemented directly by all 12; there is no base class. `ConnectResult` is `final readonly` with no pending variant. "Lock one async contract then fan out" has no free seam — it means touching all 12 files plus the result type. This is why it is L, not M.
        - **Connect half (`LIFE-13`..`LIFE-20`) is a BREAKING API change.** `GenericPlatformController.php:91,97` echoes `$result->selection` (name/thumbnail/latest item) in the same HTTP response, built from the in-memory result and not re-read from the DB. Deferring the fetch changes the `POST .../connect` response shape for 8 platforms. There IS a proven in-house pattern to copy — Instagram/GBP write `last_refresh_status='pending'`, `::dispatch()`, return **202**, and the frontend polls the already-generic `GET /platforms/meta` — but it requires coordinated work in `partna-frontend` (read-only from a backend session).
        - **Highlights half (`LIFE-21`..`LIFE-24`) is architecturally different.** `GET /{platform}/recent` feeds the picker modal and is expected to feel instant; there is NO pending/poll precedent for it anywhere. The better direction is the audit's own alternative: these platforms already have a working 12h `refreshEvery()` pipeline (`RefreshConnectionJob`/`PlatformRefresher`) that could populate a cached recent-items snapshot instead of live-fetching on every picker open. Non-breaking, no frontend dependency.
        - **Bonus defect NOT in the audit, found during verification:** `BandcampHighlights::apply()` (`app/Services/Platforms/Strategies/Highlights/BandcampHighlights.php:55`) calls `$this->scraper->enrichPrices(...)` synchronously INSIDE `withConnectionLock`'s locked read-mutate-write (`GenericPlatformController.php:134-157`) — a sync vendor fetch while HOLDING a lock, strictly worse than what `LIFE-21` describes (which only covers the GET `/recent` picker read). Whoever works this unit should cover `apply()`, not just `recentItems()`.
12. **SyncSubdomainToKvJob hardening** — `LIFE-109`, `LIFE-110` · same file — TTL floor + `ShouldBeUnique` drop window · effort ~M · autonomous
13. **Menu pipeline data-integrity hardening** — `TXN-101`, `DINT-102` · `MenuFetchJob`/`MenuItem`/`MenuCategory` — status-before-content txn, soft-delete orphan guard · effort ~S · autonomous
14. **ContentSelectionService transaction-boundary gap** — `LIFE-6` · flag commits before dependent rebuild txn · effort ~S · autonomous
15. **EarlyAccessService lifecycle hardening** — `LIFE-4`, `LIFE-8` · same file, same three methods · effort ~S · autonomous
16. **YoutubeScraper silent-failure logging** — `LIFE-25`, `LIFE-26` · same file, add discriminating `Log::warning` · effort ~S · autonomous
17. **Console-command/job instrumentation hygiene** — `OBS-3`, `OBS-4`, `OBS-5` · add `report()`/`$timeout`, no behavior change · effort ~S · autonomous
18. **Notification-publisher hygiene** — `LIFE-9`, `CACHE-2` · dedup-key scoping + unreachable fan-out loop (`Services/Notifications`) · effort ~M · autonomous
19. **FeatureAvailability caching hardening** — `CCH-2`, `CCH-3`, `CCH-5` · same file, one `rememberLocked` fix closes jitter/SWR/fail-open gaps · effort ~S · autonomous
20. **Cache-layer exception-swallowing hardening** — `CCH-4`, `CCH-101`, `LIFE-7` · swallow-and-cache/log-only in Analytics/UserObserver/LoadCurrentUser · effort ~S · autonomous
21. **Analytics/cache scaling & coverage hygiene** — `CACHE-1`, `SCALE-1`, `SCALE-2`, `SCALE-3`, `CCG-102` · unbounded/unbatched scheduled reads-writes + missing popularity-rank cache · effort ~M · autonomous
22. **Cloudflare purge/rate-limit hygiene** — `SCALE-101`, `OBS-101`, `EDGE-101` · all in `Services/Cloudflare/`, `purgeHandle`/`delete()` failure handling · effort ~S · autonomous
23. **Edge-Worker/KV routing hygiene** — `EDGE-2`, `EDGE-3`, `EDGE-4`, `EDGE-102` · `cloudflare-worker/` sync-comment/config/KV-outage-UX/staging-namespace · effort ~S · autonomous
24. **Moderation takedown ↔ KV/cache-retirement guard** — `EDGE-103` · architecture test ensuring `suspend_site` always pairs with cache/KV retirement · effort ~S · autonomous
25. **Sitepage presence-probe logging correlation** — `LIFE-104` · `SitepageDataResolverService::safeQuery` per-probe discriminator + context · effort ~S · autonomous
26. **InstagramConnectJob memory-streaming fix** — `SCALE-102` · mirror sibling `mirrorVideo`'s streaming pattern · effort ~S · autonomous
27. **Privacy/GDPR export & retention completeness** — `PRIV-5`, `PRIV-6`, `PRIV-8`, `PRIV-101` · export field-completeness, feedback retention, signup consent gate (needs frontend checkbox) · effort ~M · autonomous
28. **PII minimisation (audit log + analytics geo)** — `PRIV-7`, `PRIV-9` · drop PII duplication into append-only audit trail; truncate lat/lon · effort ~M · autonomous (handle `PRIV-7` carefully — append-only compliance trail)
29. **Policy test coverage (staff-tool policies)** — `TEST-101`, `TEST-102` · new test files, mirror existing exemplars · effort ~M · autonomous
30. **Test coverage: observer/concurrency/guard regressions** — `TEST-2`, `TEST-103`, `TEST-107` · real factories over mocks + missing negative/concurrency tests · effort ~M · autonomous
31. **Staff-search test hardening** — `TEST-104`, `TEST-105` · same file, real HTTP + Postgres-only `q` coverage · effort ~M · autonomous
32. **Misc resource/integration test coverage** — `TEST-3`, `TEST-106` · resource key-set snapshot + Cloudflare-failure test · effort ~M · autonomous

**Ordering / dependencies:**
- Run units 1–8 (all 🔒 blockers) first and get sign-off — highest severity within P2.
- Unit 1 (`SEC-103`) and unit 10 both touch `GoogleBusinessAutoSync`/`InstagramAutoSync` — same session to avoid re-reading.
- Units 6, 7, 8 are all DB-migration batches — plan/review together in one migration-hygiene / `supabase db push` window.
- Unit 9 before/alongside unit 10 — the races in 10 are triggered by the same job's payload writes.
- Unit 11 is the largest (12 files, one pattern) — Opus locks the shared async contract, then Sonnet fans out per file.
- Units 29–32 (test-only, zero prod-code change) have no dependencies — good parallel/filler work anytime.

**Possibly already addressed (verify at fix time):**
- `LIFE-7` (this file, unit 20 — `LoadCurrentUser.php` cache-invalidation catch) is NOT the shipped commit `8c47c5e5` ("atomic dedup for the signup welcome notification — LIFE-7"); that belongs to a separate archived audit's numbering. This LIFE-7 is still open.
- Unit 5 (account-deletion cluster) — cross-check against the archived "user-deletion-lifecycle" run (commit `82bc8dd8`) which touched the same `AccountDeletionService.php`; confirm none of the six overlap with shipped work before planning.

---

<!-- ═══════════ audit-2026-07-11-security.md ═══════════ -->

# Security Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Security: auth boundaries, tenant isolation, mass assignment, inbound callbacks, secrets, injection, SSRF, upload safety, PII exposure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Http/Middleware/Auth/VerifySupabaseJwt.php
- app/Http/Middleware/AddPublicCacheHeaders.php
- app/Http/Middleware/VerifyBotToken.php
- app/Http/Middleware/Auth/VerifySupabaseHookSignature.php
- app/Http/Middleware/Auth/RequireAal2.php
- app/Http/Middleware/IdempotencyKey.php
- app/Http/Middleware/Context/EnforcePendingDeletionReadOnly.php
- app/Http/Controllers/Concerns/DetectsClientInfo.php
- app/Http/Controllers/Api/User/SiteManagement/UserSectionBlockController.php
- app/Http/Controllers/Api/User/SiteManagement/UserWorkplaceController.php
- app/Http/Controllers/Api/User/Profile/SectorController.php
- app/Http/Controllers/Api/User/Analytics/UserAnalyticsController.php
- app/Http/Controllers/Api/User/Analytics/DevInsightsController.php
- app/Http/Controllers/Api/User/Account/UserSelfController.php
- app/Http/Requests/Api/User/Content/UploadContentImageRequest.php
- app/Http/Requests/Concerns/SniffsFileMimeType.php
- app/Http/Requests/Api/PublicSite/Analytics/ItemSeenRequest.php
- app/Http/Requests/Api/PublicSite/Analytics/SectionDwellRequest.php
- app/Http/Requests/Platforms/UpdateShopBrandRequest.php
- app/Http/Controllers/Api/PublicSite/AnalyticsController.php
- app/Http/Controllers/Api/PublicSite/BootstrapController.php
- app/Http/Controllers/Api/PublicSite/PublicEarlyAccessController.php
- app/Http/Controllers/Api/Platforms/BookingController.php
- app/Http/Controllers/Api/Platforms/ReservationsController.php
- app/Http/Controllers/Api/Platforms/OnlineOrderingController.php
- app/Http/Controllers/Api/Platforms/DisplaySettingsController.php
- app/Http/Controllers/Api/Platforms/ShopController.php
- app/Jobs/Platforms/EnrichLinkCardJob.php
- app/Services/Platforms/LinkCardScraper.php
- app/Services/Platforms/GenericShopScraper.php
- app/Services/Platforms/ShopProviderDetector.php
- app/Services/Webhooks/StandardWebhookVerifier.php
- app/Providers/AppServiceProvider.php
- config/cors.php
- routes/api/user.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 2 of 2 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

## P2 — Should fix

- [x] **#SEC-2** · P2 — JWT revocation gate skipped when a token carries no `session_id` claim
    - **Where:** app/Http/Middleware/Auth/VerifySupabaseJwt.php:86-96 (and the mirrored fallback path at :180-189)
    - **Affects:** "Sign out everywhere" / admin-forced-logout correctness — a cryptographically valid but un-revocable token, if one were ever issued without `session_id`, would keep working until natural expiry.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Confirm with the Supabase project config that every standard user access token carries `session_id` (the code comment already flags this as a "before hardening to a 401" TODO).
        - Once confirmed, replace the `Log::warning` branch with an early 401 rejection so a session-id-less token can never silently bypass the revocation check.
    - **Technical:** Both the JWKS-verified path and the Auth-Server-fallback path skip `TokenRevocationService::isRevoked()` entirely when `session_id` is absent from claims, logging a warning instead of rejecting. This is real but narrow: it requires Supabase itself to omit the claim on a standard token, which the surrounding code comments indicate has not been observed — this is a pre-emptive hardening gap, not an active exploit path (no attacker-controlled input forces this condition today). Fits the "hardening / defense-in-depth" P2 anchor rather than a live P0/P1 bypass.
    - **Plain English:** Every login session gets a serial number so we can cancel it individually if you sign out everywhere or an admin force-logs you out. If a token somehow arrived without that serial number, we currently just write a note in the log instead of refusing it — meaning that one token couldn't be cancelled early. This hasn't been seen happening, but the code should refuse rather than merely note it, pre-launch.
    - **Evidence:**
        ```php
        $sessionId = isset($claims['session_id']) ? (string) $claims['session_id'] : '';
        if ($sessionId === '') {
            // Revocation gate is skipped for tokens that carry no session_id.
            // Log so we can confirm this case never legitimately fires before
            // hardening to a 401 rejection.
            Log::warning('jwt.missing_session_id', [
                'request_id' => $requestId
                'operation' => __METHOD__
                'uid' => $uid
            ]);
        }
        ```

- [x] **#SEC-5** · P2 — Applicant email logged unhashed on the bootstrap account-conflict path
    - **Where:** app/Http/Controllers/Api/PublicSite/BootstrapController.php:160-164
    - **Affects:** Users whose email is already registered — their raw email address persists in Nightwatch / the log aggregator beyond the request lifecycle.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `'email' => $email` with `'email_hash' => hash('sha256', mb_strtolower((string) $email))`, matching the existing pattern at `PublicEarlyAccessController::store()` (honeypot-hit logging).
        - Extend `PiiLogHygieneSweepTest` to cover the `EMAIL_ALREADY_REGISTERED` log path.
    - **Technical:** `translateBootstrapException()` writes the raw `$email` string into a `Log::info` call reachable on every duplicate-signup attempt. The codebase already has the canonical mitigation elsewhere (`PublicEarlyAccessController` hashes the email before logging on its honeypot path) — this is the one bootstrap-path log call that didn't get the same treatment. Internal-log-only exposure (Nightwatch, not a public response), so tiered as a hygiene gap rather than a live data breach.
    - **Plain English:** When someone tries to sign up with an email that's already taken, we write a log note that includes their full email address — that note then sits in our internal monitoring tool indefinitely. Elsewhere in the app we've already learned to fingerprint the email instead of storing it plainly for this exact kind of log; this one spot didn't get the same fix.
    - **Evidence:**
        ```php
        if ($e->getMessage() === 'EMAIL_ALREADY_REGISTERED') {
            Log::info('Bootstrap rejected: email already registered to another auth user', [
                'uid' => $uid
                'email' => $email
            ]);
        ```

## P3 — Nice to have

## Suggested Bundled Sessions

- **Bundle 1 — Auth-doctrine consistency (site-management controllers):** #SEC-2
    - **Why grouped:** Both are `VerifySupabaseJwt` / `authorizeForUser` doctrine-hardening items with no live exploit path today; can land as one review pass over the auth-adjacent controller layer.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** Identical mechanical fix (inline `$request->validate()` → dedicated `FormRequest` class) across five endpoints in the Platforms controller family.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#SEC-5 — Bootstrap email log hygiene** · standalone: small, isolated, single-file PII-hygiene fix — no reason to couple it to the doctrine-consistency or Form-Request bundles above.


<!-- ═══════════ audit-2026-07-11-lifecycle-correctness.md ═══════════ -->

# Lifecycle Correctness Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Lifecycle correctness: race-safety, idempotency, anchor decoupling, reconcile loops, vendor resilience, observability discipline
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Services/EarlyAccess/EarlyAccessService.php
- app/Services/User/UserBootstrapService.php
- app/Services/Site/UpdateSiteAction.php
- app/Services/User/AccountDeletionService.php
- app/Services/Site/ContentSelectionService.php
- app/Http/Middleware/Context/LoadCurrentUser.php
- app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php
- app/Services/Notifications/Dispatchers/PlatformHealthNotifier.php
- app/Services/Notifications/NotificationPublisher.php
- app/Jobs/Platforms/GoogleBusinessEnrichJob.php
- app/Jobs/Platforms/InstagramConnectJob.php
- app/Services/Platforms/GoogleBusinessAutoSync.php
- app/Services/Platforms/ShopCatalog.php
- app/Services/Platforms/Strategies/Fetch/GoogleBusinessFetch.php
- app/Services/Platforms/Strategies/Refresh/ScheduledRefresh.php
- app/Services/Platforms/PlatformRefresher.php
- app/Http/Controllers/Api/Platforms/GenericPlatformController.php
- app/Services/Platforms/Strategies/Connect/{Spotify,Bandcamp,Pinterest,Strava,Twitch,Vimeo,Youtube,YoutubeMusic}Connect.php
- app/Services/Platforms/Strategies/Highlights/{Bandcamp,Vimeo,Youtube,YoutubeMusic}Highlights.php
- app/Services/Platforms/WooCommerceScraper.php
- app/Services/Platforms/YoutubeScraper.php
- supabase/migrations/20260711000300_early_access_signups.sql

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 7 of 20 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

## P2 — Should fix

- [x] **LIFE-4** · P2 — Early-access invite mint/save races without a row lock — a fast re-invite can dead-link the first email
    - **Where:** app/Services/EarlyAccess/EarlyAccessService.php:69-91
    - **Affects:** Staff-initiated invite flow — two staff members (or one staff member double-clicking) inviting the same waitlist row concurrently mint two tokens; only the last one persists.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Re-query the row with `lockForUpdate()` inside a transaction, re-check `status !== STATUS_SIGNED_UP` under that lock, then mint/save.
    - **Technical:** Category 2 — race-safe read-modify-write. `invite()` reads `$signup->status` off a pre-loaded instance, then unconditionally mints `Str::random(48)` and saves — no lock, no re-check against the DB's current row. Two concurrent invokes both pass the `signed_up` guard and both write; the second `invite_token_hash` wins, silently invalidating the first email's link before it's even opened. Staff-only, low-concurrency operation, so the practical blast radius is small, but the fix is a single `lockForUpdate()`.
    - **Plain English:** Two support staff both click "invite" on the same waitlist entry within a second of each other. Both generate a fresh sign-up link and save it — but only one link actually works, because the second save overwrites the first. Whoever's email arrives first in the recipient's inbox will click a dead link. Locking the row while reading-and-writing means only one of the two invites can proceed at a time.
    - **Evidence:**
        ```php
        public function invite(EarlyAccessSignup $signup, ?string $invitedBy = null): ?string
        {
            if ($signup->status === EarlyAccessSignup::STATUS_SIGNED_UP) {
                return null;
            }
            $token = Str::random(48);
            $signup->fill([
                'status' => EarlyAccessSignup::STATUS_INVITED
                'invited_at' => now()
                'invite_token_hash' => hash('sha256', $token)
            ]);
            $signup->invited_by = $invitedBy;
            $signup->save();
        ```

- [ ] **LIFE-6** · P2 — Instagram-auto flag commits outside the content-selection transaction — a mid-operation DB failure leaves the flag on with no reserved slots
    - **Where:** app/Services/Site/ContentSelectionService.php:222-284
    - **Affects:** Dashboard "auto-fill from Instagram" toggle — if `persist()` throws after `$site->save()` already committed, the site advertises auto-fill as enabled with no ig-reel/ig-post rows behind it.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap `$site->save()` and the `persist()` call in one `DB::connection('pgsql')->transaction()` so the flag and the content-selection rows commit or roll back together.
    - **Technical:** This doesn't map cleanly onto a single named canonical pattern but is the same "two co-dependent writes must be one atomic unit" discipline `AccountDeletionService::executeConfirmation()` and `restoreSiteAndStatus()` apply correctly elsewhere in this codebase. `setInstagramAuto()` calls `$site->save()` first (commits immediately), then computes and calls `persist()`, which wraps its own delete+insert in a separate transaction. If `persist()` throws (DB blip, connection drop), the flag is durably `true` with zero ig-* rows behind it — a self-inconsistent state that only self-heals on a subsequent manual toggle. Low-frequency trigger (a DB failure mid-request), so P2 not P1.
    - **Plain English:** When a professional turns on "auto-fill with my latest Instagram post," the system flips a switch and then tries to reserve two content slots for that Instagram content. If reserving the slots fails partway through (a brief database hiccup), the switch is already stuck in the "on" position even though no Instagram content was actually reserved — a confusing half-done state that won't fix itself until the user manually toggles it off and back on.
    - **Evidence:**
        ```php
        public function setInstagramAuto(Site $site, bool $enabled): void
        {
            $site->content_instagram_auto_enabled = $enabled;
            $site->save();

            $existing = ContentSelection::query()
                ->where('site_id', $site->id)
                ->orderBy('position')
                ->get();
        ```

- [x] **LIFE-7** · P2 — Cache invalidation sits inside a catch clause scoped to a different exception type — a Redis hiccup 500s an otherwise-successful email sync
    - **Where:** app/Http/Middleware/Context/LoadCurrentUser.php:93-137
    - **Affects:** Every authenticated request where the JWT's verified email differs from the stored `primary_email` (rare per-user, but hits every affected request during any Redis blip on that path).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `$this->userCache->invalidateUser($professional)` to its own `try { ... } catch (\Throwable $e) { report($e); }` block after the `save()`/`catch (UniqueConstraintViolationException)` block, so a cache failure never propagates past a successful DB write.
    - **Technical:** Category 8 — cache operations must degrade gracefully, never become a single point of failure. `syncEmailFromClaims()` wraps `$professional->save()` AND `$this->userCache->invalidateUser($professional)` in one `try` block that only `catch`es `UniqueConstraintViolationException`. If `invalidateUser()` throws anything else (Redis connection refused/timeout), it propagates uncaught out of middleware `handle()`, turning an already-successful DB write into a 500 for the whole request. The code's own comment argues the DB race here is "not worth" a conditional UPDATE — but the cache-invalidation exception-safety gap is a separate, unrelated issue the comment doesn't address.
    - **Plain English:** When someone logs in with a newly-verified email, the system updates their stored email and then clears the old cached copy so it doesn't linger. The "clear the cache" step lives inside a safety net that's only built to catch one specific kind of error (a duplicate-email conflict). If the cache server itself is briefly down, that different kind of failure isn't caught — it crashes the whole request, even though the important part (saving the new email) already succeeded.
    - **Evidence:**
        ```php
        try {
            $professional->primary_email = $claimedEmail;
            $professional->save();
            $this->userCache->invalidateUser($professional);
        } catch (UniqueConstraintViolationException $e) {
            Log::warning('LoadCurrentUser email sync collision', [
        ```

- [x] **LIFE-8** · P2 — `markSignedUp` swallows every failure as a bare `Log::warning` — a persistent write failure is invisible to Nightwatch
    - **Where:** app/Services/EarlyAccess/EarlyAccessService.php:97-114
    - **Affects:** Early-access bookkeeping — if the UPDATE fails on every invocation (bad migration state, permission error), no waitlist row ever flips to `signed_up`, and nothing alerts anyone.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e)` alongside the existing `Log::warning`, so a systemic failure surfaces to Nightwatch while the signup flow itself still proceeds unaffected (the catch-and-continue behavior is correct and should stay).
    - **Technical:** Category 10 — `Log-with-context`. The comment correctly identifies this as "bookkeeping only" that must never fail the signup — that part of the design is right. But `catch (\Throwable $e) { Log::warning(...); }` with no `report($e)` means Nightwatch (which alerts on exceptions, not log queries) never sees a systemic failure of this update. A discriminating log key (`early_access.mark_signed_up_failed`) already exists; it just needs to also reach the alerting path.
    - **Plain English:** After someone finishes signing up, the system quietly tries to mark their waitlist entry as "done." If that step keeps failing — say, because of a database misconfiguration — nobody finds out, because the failure is only written to a log file nobody watches. Sending it to the monitoring system too means the team gets paged instead of discovering the problem weeks later by accident.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            // Bookkeeping only — a failed status flip must never fail the signup
            // itself. (Also covers SQLite test mirrors without the table.)
            Log::warning('early_access.mark_signed_up_failed', ['error' => $e->getMessage()]);
        }
        ```

- [x] **LIFE-9** · P2 — Platform-health critical-notification dedup key is permanent — a user who reconnects and later fails again is never warned twice
    - **Where:** app/Services/Notifications/Dispatchers/PlatformHealthNotifier.php:26-49
    - **Affects:** Users whose platform connection trips the failure breaker, reconnects it, and later has it fail again — the second failure episode produces no notification at all.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Scope the dedupe key to a failure episode, e.g. `"platform_connection_failed:{$connection->id}:{$connection->updated_at->timestamp}"` captured at the moment the breaker trips, or explicitly delete the prior critical notification row when `consecutive_failures` resets to 0 on a successful refresh.
    - **Technical:** Category 3/9 — periodic-notification dedup shape. `NotificationPublisher::publish()` correctly dedupes via `insertOrIgnore` on `(user_id, dedupe_key)` (atomic, race-free — no finding there). The gap is upstream: `connectionRefreshFailing()` uses a dedupe key scoped to the connection's entire lifetime (`"platform_connection_failed:{$connection->id}"`), and the notification is `critical: true` → `ends_at = null` (no auto-expiry, no prune — confirmed in `NotificationPublisher::publish()`'s `ends_at` branch). Once that row exists, `insertOrIgnore` permanently blocks any future notification for that connection, even after the user reconnects and `PlatformRefresher::recordNotModified()`/a successful refresh resets `consecutive_failures` to 0. Contrast `menuScrapeFailed()`, which sets `retentionConfigKey: 'content_scrape'` so the row auto-expires and the dedupe naturally clears — that's the correct reference pattern already in the same file. Confirmed against the recently-shipped notification infra (`48d5f9fb feat(notifications): automatic dispatchers + critical email path + expiry prune (OV-H)`).
    - **Plain English:** Think of a smoke detector that can only ever go off once. A connection to a platform breaks, the user gets warned, they fix it — but if it breaks again six months later, the detector stays silent because it already "used up" its one alert for that connection. The permanent alert flag needs to reset whenever the problem is actually fixed, not stay tripped forever.
    - **Evidence:**
        ```php
        $this->safePublish(
            userId: (string) $connection->user_id
            frontendType: 'Warning'
            category: 'platform_connection'
            title: "Reconnect your {$label}"
            body: "We couldn't refresh your {$label} connection after several attempts..."
            dedupeKey: "platform_connection_failed:{$connection->id}"
            ctaUrl: '/account/integrations'
            critical: true
            retentionConfigKey: null
        );
        ```

- [x] **LIFE-10** · P2 — GoogleBusinessEnrichJob writes `payload` without a lock; a same-window scheduled refresh can lose the enrichment
    - **Where:** app/Jobs/Platforms/GoogleBusinessEnrichJob.php:90-167 (vs. app/Services/Platforms/Strategies/Refresh/ScheduledRefresh.php:22-40)
    - **Affects:** Google Business connections — a connect-time Apify enrichment racing a due scheduled refresh for the same connection can have either write clobber the other's `payload`.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap the final `forceFill(['payload' => ...])->saveQuietly()` in a `lockForUpdate()`-guarded re-read-and-merge, or compare-and-swap on `apifyFetchedAt`/`updated_at` so a stale writer's update is rejected rather than silently applied.
    - **Technical:** Category 2 — race-safe read-modify-write. `GoogleBusinessEnrichJob::handle()` reads the connection's `payload` early, does multi-second external work (website harvest, optional Apify run), then writes back `forceFill(['payload' => [...], 'apify_status' => 'ok'])->saveQuietly()` with no lock. `PlatformRefresher::refresh()` → `ScheduledRefresh::run()` → `GoogleBusinessFetch::fetch()` is a completely separate write path to the same `payload` column, also with no lock, triggered by the daily cron's `dueForRefresh` scope. `GoogleBusinessEnrichJob`'s `uniqueFor=900` only dedupes against *itself* (same job class, same `userId:placeId`), not against `PlatformRefresher`. The window is narrow — `GoogleBusinessFetch` has its own 40-hour freshness short-circuit and the cron cadence is daily — so a fresh connect colliding with a due scheduled refresh for the same connection is uncommon, not a routine occurrence; downgraded from the draft's P1 accordingly.
    - **Plain English:** Two different background processes can both update a Google Business connection's saved details at almost the same time — one right after the user connects it, one from the routine daily refresh. Neither checks whether the other is mid-update, so whichever finishes last wins, and the other's work quietly vanishes. It's an unlikely timing coincidence today, but worth closing before it becomes a real support ticket.
    - **Evidence:**
        ```php
        $connection->forceFill([
            'payload' => [
                ...$businessInfo
                'apifyFetchedAt' => now()->toIso8601String()
                'syncFindings' => $findings
            ]
            'apify_status' => 'ok'
        ])->saveQuietly();
        ```

- [ ] **LIFE-13** · P2 — SpotifyConnect fetches the vendor oEmbed endpoint synchronously in the request cycle
    - **Where:** app/Services/Platforms/Strategies/Connect/SpotifyConnect.php:17-40 (invoked from app/Http/Controllers/Api/Platforms/GenericPlatformController.php:63)
    - **Affects:** Users connecting a Spotify link — Spotify oEmbed latency/downtime directly extends or fails the `POST /api/platforms/{platform}/connect` response.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Mirror the pattern already established for Google Business / Instagram (`GoogleBusinessEnrichJob`, `InstagramConnectJob`): persist a minimal pending row synchronously, dispatch a queued job for the oEmbed fetch, and have the dashboard poll for completion.
    - **Technical:** Category 6 — vendor-integration hygiene: synchronous vendor calls in the request cycle. `GenericPlatformController::connect()` calls `$strategy->resolve(...)` directly and synchronously (confirmed — no queue dispatch anywhere in that method); `SpotifyConnect::resolve()` performs a live `Http` call to `open.spotify.com/oembed` inline. Any latency or outage on Spotify's side is felt directly by the user as request latency/failure. This is a one-time, user-initiated action (not on the public sitepage hot path), so the blast radius is limited to the connecting user's own request — hardening, not a today-hurts-a-real-user issue, hence P2 rather than P1.
    - **Plain English:** When someone pastes a Spotify link into their dashboard, the site calls out to Spotify's servers live and makes the person wait for the reply before showing anything. If Spotify is slow or down, the user just sees a spinner or an error, even though nothing is actually wrong on our end.
    - **Evidence:**
        ```php
        $resolved = $this->oembed->resolve('https://open.spotify.com/oembed?url='.rawurlencode($link));
        if ($resolved === null) {
            return ConnectResult::fail('Could not load that Spotify link.');
        }
        ```

- [ ] **LIFE-14** · P2 — BandcampConnect fetches the artist page + price enrichment synchronously in the request cycle
    - **Where:** app/Services/Platforms/Strategies/Connect/BandcampConnect.php:23-48
    - **Affects:** Users connecting a Bandcamp page — same request-latency exposure as LIFE-13.
    - **Effort:** M (~2–4h)
    - **What to do:** Same remediation direction as LIFE-13 — async job + poll.
    - **Technical:** Category 6, same shape as LIFE-13. `resolve()` calls `$this->scraper->fetchProfile($origin)` (full page scrape) then `enrichPrices(...)` (a second fetch), both synchronously inside `GenericPlatformController::connect()`.
    - **Plain English:** Same issue as the Spotify one, for Bandcamp: connecting a page makes the user wait on a live fetch of the artist's whole page plus a price lookup.
    - **Evidence:**
        ```php
        $profile = $this->scraper->fetchProfile($origin);
        if ($profile === null || $profile['items'] === []) {
            return ConnectResult::fail('Could not find releases on that Bandcamp page.', 404);
        }
        $latest = $this->scraper->enrichPrices([$profile['items'][0]])[0];
        ```

- [ ] **LIFE-15** · P2 — PinterestConnect makes two sequential synchronous vendor fetches in the request cycle
    - **Where:** app/Services/Platforms/Strategies/Connect/PinterestConnect.php:16-38
    - **Affects:** Users connecting a Pinterest profile — two sequential external fetches (state JSON + RSS) both block the response.
    - **Effort:** M (~2–4h)
    - **What to do:** Same remediation direction as LIFE-13.
    - **Technical:** Category 6, same shape as LIFE-13. `resolve()` calls `fetchProfile()` then `fetchPins()`, sequentially, synchronously.
    - **Plain English:** Connecting a Pinterest profile makes the user wait through two separate live lookups to Pinterest, one after the other, before anything shows up.
    - **Evidence:**
        ```php
        $profile = $this->scraper->fetchProfile($username);
        if ($profile === null) {
            return ConnectResult::fail('Could not find that Pinterest profile.', 404);
        }
        $pins = $this->scraper->fetchPins($username);
        ```

- [ ] **LIFE-16** · P2 — StravaConnect fetches the club page synchronously in the request cycle
    - **Where:** app/Services/Platforms/Strategies/Connect/StravaConnect.php:16-30
    - **Affects:** Users connecting a Strava club — same exposure as LIFE-13.
    - **Effort:** M (~2–4h)
    - **What to do:** Same remediation direction as LIFE-13.
    - **Technical:** Category 6, same shape.
    - **Plain English:** Same pattern as the others: pasting a Strava club link makes the user wait on a live page fetch.
    - **Evidence:**
        ```php
        $club = $this->scraper->fetchClub($url);
        if ($club === null) {
            return ConnectResult::fail('Could not read that Strava club page.', 404);
        }
        ```

- [ ] **LIFE-17** · P2 — TwitchConnect fetches the channel page synchronously in the request cycle
    - **Where:** app/Services/Platforms/Strategies/Connect/TwitchConnect.php:15-35
    - **Affects:** Users connecting a Twitch channel — same exposure as LIFE-13.
    - **Effort:** M (~2–4h)
    - **What to do:** Same remediation direction as LIFE-13.
    - **Technical:** Category 6, same shape.
    - **Plain English:** Same pattern: connecting a Twitch channel blocks on a live page fetch.
    - **Evidence:**
        ```php
        $channel = $this->scraper->fetchChannel($login);
        if ($channel === null) {
            return ConnectResult::fail('Could not find that Twitch channel.', 404);
        }
        ```

- [ ] **LIFE-18** · P2 — VimeoConnect makes two sequential synchronous vendor API calls in the request cycle
    - **Where:** app/Services/Platforms/Strategies/Connect/VimeoConnect.php:18-40
    - **Affects:** Users connecting a Vimeo profile — two sequential keyless-API calls block the response.
    - **Effort:** M (~2–4h)
    - **What to do:** Same remediation direction as LIFE-13.
    - **Technical:** Category 6, same shape. `resolve()` calls `fetchProfile()` then `fetchVideos()` sequentially.
    - **Plain English:** Connecting Vimeo makes the user wait through two separate live API calls before the profile shows up.
    - **Evidence:**
        ```php
        $profile = $this->vimeo->fetchProfile($source['apiPath']);
        $videos = $this->vimeo->fetchVideos($source['apiPath']);
        if ($profile === null && $videos === []) {
            return ConnectResult::fail('Could not find that Vimeo profile.', 404);
        }
        ```

- [ ] **LIFE-19** · P2 — YoutubeConnect fetches the channel's recent videos synchronously in the request cycle
    - **Where:** app/Services/Platforms/Strategies/Connect/YoutubeConnect.php:21-42
    - **Affects:** Users connecting a YouTube channel — same exposure as LIFE-13.
    - **Effort:** M (~2–4h)
    - **What to do:** Same remediation direction as LIFE-13.
    - **Technical:** Category 6, same shape. `fetchRecentVideos()` itself does two synchronous fetches internally (channel-id resolution + RSS feed — see `YoutubeScraper`).
    - **Plain English:** Same pattern: connecting a YouTube channel blocks on live fetches to YouTube.
    - **Evidence:**
        ```php
        $videos = $this->scraper->fetchRecentVideos($handle);
        if (empty($videos)) {
            return ConnectResult::fail('Could not find that YouTube channel or its latest video.', 404);
        }
        ```

- [ ] **LIFE-20** · P2 — YoutubeMusicConnect fetches channel-id resolution + uploads feed synchronously in the request cycle
    - **Where:** app/Services/Platforms/Strategies/Connect/YoutubeMusicConnect.php:20-31
    - **Affects:** Users connecting a YouTube Music artist — two sequential external fetches block the response.
    - **Effort:** M (~2–4h)
    - **What to do:** Same remediation direction as LIFE-13.
    - **Technical:** Category 6, same shape.
    - **Plain English:** Same pattern for YouTube Music: two live round-trips before the connect request completes.
    - **Evidence:**
        ```php
        $channelId = $this->scraper->channelIdFrom($input);
        if (! $channelId) {
            return ConnectResult::fail();
        }
        $feed = $this->scraper->fetchUploadsFeed($channelId, self::MAX_ITEMS);
        ```

- [ ] **LIFE-21** · P2 — BandcampHighlights fetches the artist page synchronously from the picker endpoint
    - **Where:** app/Services/Platforms/Strategies/Highlights/BandcampHighlights.php:28-36 (invoked from `GenericPlatformController::recent()`)
    - **Affects:** Users opening the "choose highlights" picker for an already-connected Bandcamp page — the modal blocks on a live fetch every time it opens.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Serve from the periodically-refreshed cached catalog (mirroring `ShopCatalog`'s cached-catalog fallback pattern) rather than a live fetch on every picker open, or queue+poll as in LIFE-13.
    - **Technical:** Category 6, same shape as LIFE-13–20 but on the `GET /api/platforms/{platform}/recent` picker endpoint rather than `connect`. `recentItems()` calls `$this->scraper->fetchProfile($identity)` synchronously; `GenericPlatformController::recent()` calls the highlights strategy directly with no queue involved.
    - **Plain English:** When a user opens the popup to pick which Bandcamp releases to feature, the site fetches the artist's entire page live, every single time the popup opens — if Bandcamp is slow, the popup just hangs.
    - **Evidence:**
        ```php
        public function recentItems(string $identity): ?array
        {
            $profile = $this->scraper->fetchProfile($identity);
            if ($profile === null) {
                return null;
            }
            return array_slice($profile['items'], 0, 15);
        }
        ```

- [ ] **LIFE-22** · P2 — VimeoHighlights fetches recent videos synchronously from the picker endpoint
    - **Where:** app/Services/Platforms/Strategies/Highlights/VimeoHighlights.php:27-33
    - **Affects:** Users opening the Vimeo highlights picker — same exposure as LIFE-21.
    - **Effort:** M (~2–4h)
    - **What to do:** Same remediation direction as LIFE-21.
    - **Technical:** Category 6, same shape.
    - **Plain English:** Same as the Bandcamp picker issue, for Vimeo.
    - **Evidence:**
        ```php
        public function recentItems(string $identity): ?array
        {
            $videos = $this->vimeo->fetchVideos($identity);
            return $videos === [] ? null : $videos;
        }
        ```

- [ ] **LIFE-23** · P2 — YoutubeHighlights fetches recent videos synchronously from the picker endpoint
    - **Where:** app/Services/Platforms/Strategies/Highlights/YoutubeHighlights.php:28-31
    - **Affects:** Users opening the YouTube highlights picker — same exposure as LIFE-21.
    - **Effort:** M (~2–4h)
    - **What to do:** Same remediation direction as LIFE-21.
    - **Technical:** Category 6, same shape.
    - **Plain English:** Same picker-latency issue, for YouTube.
    - **Evidence:**
        ```php
        public function recentItems(string $identity): ?array
        {
            return $this->scraper->fetchRecentVideos($identity);
        }
        ```

- [ ] **LIFE-24** · P2 — YoutubeMusicHighlights fetches the uploads feed synchronously from the picker endpoint
    - **Where:** app/Services/Platforms/Strategies/Highlights/YoutubeMusicHighlights.php:28-36
    - **Affects:** Users opening the YouTube Music highlights picker — same exposure as LIFE-21.
    - **Effort:** M (~2–4h)
    - **What to do:** Same remediation direction as LIFE-21.
    - **Technical:** Category 6, same shape.
    - **Plain English:** Same picker-latency issue, for YouTube Music.
    - **Evidence:**
        ```php
        public function recentItems(string $identity): ?array
        {
            $feed = $this->scraper->fetchUploadsFeed($identity);
            if ($feed === null || $feed['videos'] === []) {
                return null;
            }
            return YoutubeMusicItems::map($feed['videos']);
        }
        ```

- [x] **LIFE-25** · P2 — `YoutubeScraper::resolveChannelId()` returns null on every failure path with zero logging
    - **Where:** app/Services/Platforms/YoutubeScraper.php:158-172
    - **Affects:** Every YouTube connect/refresh that depends on handle-to-channel-id resolution — a sustained YouTube-side block or layout change silently degrades every affected user's YouTube integration with no operational signal.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `Log::warning('youtube.channel_resolve_failed', ['handle' => $handle, 'reason' => ...])` on both null-return paths (fetch failure vs. pattern-match failure), with distinct `reason` values so Nightwatch search can tell them apart.
    - **Technical:** Category 10 — distinct logs for distinct failure modes. Both `$page === null || $page['status'] !== 200` and the triple `preg_match` miss return `null` silently. A sustained YouTube block on the platform's egress IP would degrade every user's YouTube connect/refresh with zero breadcrumb in logs — the only symptom is "my YouTube stopped updating" support tickets with no way to correlate them to a systemic cause.
    - **Plain English:** When the system tries to look up a YouTube channel's ID and can't — because YouTube is blocking us or changed their page layout — it just gives up quietly. No record is kept of the failure, so if this starts happening to everyone at once, the team has no way to notice except waiting for user complaints to pile up.
    - **Evidence:**
        ```php
        private function resolveChannelId(string $handle, array $headers): ?string
        {
            $page = $this->fetcher->tryFetch('https://www.youtube.com/@'.rawurlencode($handle), $headers);
            if ($page === null || $page['status'] !== 200) {
                return null;
            }
            if (! preg_match('/"externalId":"(UC[A-Za-z0-9_-]{22})"/', $page['body'], $m)
                && ! preg_match('~/channel/(UC[A-Za-z0-9_-]{22})~', $page['body'], $m)
                && ! preg_match('/"channelId":"(UC[A-Za-z0-9_-]{22})"/', $page['body'], $m)) {
                return null;
            }
        ```

- [x] **LIFE-26** · P2 — `YoutubeScraper::fetchUploadsFeed()` returns null on three distinct failure paths with zero logging
    - **Where:** app/Services/Platforms/YoutubeScraper.php:76-97
    - **Affects:** Periodic refresh keeping a user's YouTube/YouTube Music highlights current — a silent feed failure leaves the sitepage showing stale videos indefinitely with no operational signal.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Log a warning with `channelId` and a discriminating reason (`fetch_null`, `non_200:{status}`) for the RSS-fetch-failed and non-200 paths (the 304-via-`$cond` path is a legitimate success case and should stay quiet).
    - **Technical:** Category 10, same shape as LIFE-25. This is the primary data source feeding both YouTube and YouTube Music highlights; a systemic RSS-fetch failure would silently stall every affected user's video feed with nothing in the logs to distinguish "feed down" from "channel not found."
    - **Plain English:** This is the step that actually fetches a channel's latest videos. If that fetch fails, the code just quietly hands back nothing — no note is made of why. If YouTube starts blocking these requests broadly, dozens of users' pages would show stale video lists and nobody would know until someone investigated by hand.
    - **Evidence:**
        ```php
        $rss = $this->fetcher->tryFetch('https://www.youtube.com/feeds/videos.xml?playlist_id='.$uploadsPlaylistId, $headers);
        if ($rss === null) {
            return null;
        }
        if ($cond !== null && $cond->handle($rss)) {
            return null;
        }
        if ($rss['status'] !== 200) {
            return null;
        }
        ```

## P3 — Nice to have

## Suggested Bundled Sessions

    - **Why grouped:** Same file (`EarlyAccessService.php`), same three lifecycle methods (`signupFromMarketing`, `invite`, `markSignedUp`) — one focused session touching one file.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** Both are idempotency/dedup gaps in the notification-dispatch path (job-level cache guard vs. dispatcher-level dedupe key), reviewable together against `NotificationPublisher`'s existing correct dedup pattern.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** All three are narrow-window race/degradation fixes on account-adjacent write paths (deletion confirm, content-selection toggle, auth-middleware cache invalidation); similar size and risk profile, reviewable as one session.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** All three are lock-safety gaps in the same connect/enrich/auto-sync subsystem (`GoogleBusinessEnrichJob`, `InstagramConnectJob`, `GoogleBusinessAutoSync`) writing to the same `IntegrationConnection` model family.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** Identical root cause (synchronous vendor fetch inside `GenericPlatformController::connect()`) and identical fix shape across 8 `ConnectStrategy` implementations — best executed as one mechanical pass establishing the async pattern once, then applied per-file.
    - **Model:** Plan: Opus (design the one shared async pattern) · Implement: Sonnet (apply per-file) · Review: Sonnet.

    - **Why grouped:** Same root cause and fix shape as Bundle 5, but on the `recent()` picker endpoint's `HighlightsStrategy` implementations — natural follow-on once Bundle 5's pattern exists.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** Same fix shape (add discriminating `Log::warning` on existing silent-null paths) across `YoutubeScraper` and `WooCommerceScraper` — cheap, mechanical, low-risk session.
    - **Model:** Plan+Implement combined (Sonnet, XS-per-item) · Review: Sonnet.

## Standalone — do NOT bundle



<!-- ═══════════ audit-2026-07-11-scaling-antipatterns.md ═══════════ -->

# Scaling Antipatterns Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Scaling antipatterns: write amplification, rebuild-on-write, weak caching — per-event fan-out that scales with data cardinality instead of request rate, aggregate rebuilds on single writes, and caches lacking single-flight/jitter/push-invalidation.
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Jobs/Analytics/RecordAnalyticsEventJob.php
- app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php
- app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php
- app/Observers/Core/IntegrationConnectionObserver.php
- app/Observers/User/UserObserver.php
- app/Services/Analytics/AnalyticsCacheService.php
- app/Services/Analytics/AnalyticsDedupGuard.php
- app/Services/Analytics/ContentFreshness.php
- app/Services/Analytics/ContentPopularityReader.php
- app/Services/Analytics/Ingestors/QueuedIngestor.php
- app/Services/Analytics/RankedActionsComputer.php
- app/Services/Analytics/Writers/PostgresEventWriter.php
- app/Services/Cache/CacheKeyGenerator.php
- app/Services/Cache/UserCacheService.php
- app/Services/Notifications/Dispatchers/AchievementNotifier.php
- app/Services/Notifications/Dispatchers/PlatformHealthNotifier.php
- app/Services/Notifications/NotificationPublisher.php
- app/Services/Segments/SegmentResolver.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 1 of 2 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [ ] **CACHE-1** · P2 — `PostgresEventWriter::writeMany()` loops session-ping upserts one row at a time — latent because the only caller dispatches one event per job
    - **Where:** app/Services/Analytics/Writers/PostgresEventWriter.php:75-77
    - **Affects:** Analytics ingest throughput under burst ingest. Not active today: `QueuedIngestor::ingest()` (app/Services/Analytics/Ingestors/QueuedIngestor.php) dispatches exactly one `RecordAnalyticsEventJob` per HTTP ping, and `RecordAnalyticsEventJob::handle()` calls `$writer->write($event)`, which is `writeMany([$event])` — so `$sessionEvents` here never holds more than one item under the current architecture.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Collect all `TYPE_SESSION_PING` events in a batch and issue a single multi-row `INSERT ... ON CONFLICT (id, site_id) DO UPDATE ... GREATEST(...)` statement, mirroring the driver-portability handling already in `upsertSession()`.
        - Preserve the existing first-write-wins origin-field semantics and the SQLite-vs-Postgres `MAX`/`GREATEST` branch.
        - No urgency ahead of a batching ingestor landing — track alongside that work (`docs/superpowers/specs/2026-05-30-async-analytics-ingest-design.md` documents a future `BufferedIngestor`) rather than shipping standalone.
    - **Technical:** Category (2) write amplification. The `visitRows`/`clickRows`/`sectionRows`/`itemRows` arrays in the same method already use bulk `insertOrIgnore()`; only the session-ping path stayed a per-event loop because a single-row `ON CONFLICT ... WHERE`-guarded upsert with `GREATEST()` doesn't trivially generalize to a multi-row `VALUES` statement. Per the P1/P2 calibration anchor, a pattern whose bad behavior "only manifests under a scenario that isn't documented or expected" (a batching ingestor that doesn't exist yet) re-tiers to P2 hardening rather than P1. Canonical replacement: collapse the loop into one multi-row upsert so a future high-cardinality `writeMany()` call doesn't silently degrade back to N round-trips.
    - **Plain English:** Right now, every visitor's "still on the page" heartbeat is saved one at a time — but that's harmless today because the system only ever hands this code one heartbeat per database trip anyway. There's a loop that looks ready to handle many heartbeats at once but doesn't actually get used that way yet. If a future change starts grouping many heartbeats together before saving (to go faster under traffic spikes), this loop would quietly cancel that grouping out. Worth fixing so it's ready when that day comes — nothing is at risk right now.
    - **Evidence:**
        ```php
        foreach ($sessionEvents as $event) {
            $this->upsertSession($event);
        }
        ```

- [x] **CACHE-2** · P2 — `NotificationPublisher::publishMany()` fans out one email job per recipient with no batching — currently unreachable (zero callers)
    - **Where:** app/Services/Notifications/NotificationPublisher.php:273-281
    - **Affects:** Any future caller of `publishMany()` for bulk in-app + email delivery (staff broadcasts, segment-targeted announcements). As of this audit `publishMany()` has zero callers anywhere in `app/` or `tests/` (verified by repo-wide grep) — the fan-out risk described here does not manifest in production today.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Before wiring any caller to `publishMany()`, replace the per-ID `SendTransactionalNotificationEmailJob::dispatch()` loop with a `Bus::batch()` + `array_chunk()` (chunk size from config) pattern, so bulk notification delivery shares one Redis-pipelining convention with the rest of the fan-out surfaces in this codebase rather than reintroducing a naive per-item dispatch loop.
        - Alternatively, if the intended caller only ever targets a small bounded audience (e.g. a staff per-professional batch under ~50), document that bound explicitly in the docblock so a future large-audience caller doesn't inherit the eager per-ID dispatch by copy-paste.
    - **Technical:** Category (2) write amplification. Structurally identical root cause to the pre-fix pattern other broadcast fan-out paths in this codebase have already moved away from (one queue job dispatched per recipient at fan-out time instead of chunked). Because there is currently no call site — the code was added as part of the OV-H critical-email-path work (commit `48d5f9fb`) but nothing invokes `publishMany` yet — this is pure latent risk rather than an active P1 per the calibration anchor ("requires a code path that doesn't currently exist" re-tiers P1→P2). Flagging it now, before a caller lands, is cheaper than fixing it after a broadcast feature ships on top of the naive loop.
    - **Plain English:** There's a "send this notification to a big list of people" function in the code that isn't wired up to anything yet — but if someone connects it later (e.g. for a staff broadcast to thousands of users), it would hand out one email task per person, one at a time, instead of bundling them. That's like hiring a separate courier for every single letter instead of loading one courier's van with a full batch. Nothing is broken today because nothing calls this function, but it's a trap waiting for whoever wires it up next — cheap to fix now, before that happens.
    - **Evidence:**
        ```php
        foreach ($insertedIds as $id) {
            [$category, $userId, $critical] = $idToCategoryAndPro[$id];
            // Only critical notifications escalate to email (OV-H) — matches publish().
            if (! $critical) {
                continue;
            }
            SendTransactionalNotificationEmailJob::dispatch($id, $category, $userId)
                ->onQueue('mail');
        }
        ```

## Suggested Bundled Sessions

    - **Why grouped:** Same root-cause pattern (per-event/per-recipient DB or queue round-trip in a `foreach`, currently latent/unreachable in production) across the two write-heavy surfaces named in the lens (analytics ingest, notification fan-out). Neither is urgent; both are mechanical batching fixes that converge on the same chunking idiom.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet — no escalation needed, both are mechanical batching changes with existing in-repo reference implementations (bulk `insertOrIgnore()` for CACHE-1's sibling arrays; chunked dispatch patterns elsewhere in the notification fan-out surface for CACHE-2).

## Standalone — do NOT bundle

None — neither finding touches auth/authorization, money, or a DB migration/schema change, and both are M-effort.


<!-- ═══════════ audit-2026-07-11-database-and-queue-scaling.md ═══════════ -->

# Database & Queue Scaling Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Database & queue scaling: N+1, unbounded reads, connection scoping, queue shape, vendor budgets, migration safety, backpressure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Console/Commands/BackfillWebsiteAnalysesCommand.php
- app/Console/Commands/ComputeContentPopularityScores.php
- app/Console/Commands/PruneNotifications.php
- app/Console/Commands/PurgeRawAnalyticsEvents.php
- app/Console/Commands/ResolveAllDesignPresetsCommand.php
- app/Console/Commands/BackfillMediaPaletteCommand.php
- app/Http/Resources/SiteResource.php
- app/Http/Resources/Staff/StaffUserListResource.php
- app/Models/Core/Site/Site.php
- app/Models/Core/Site/ShopBrand.php
- app/Models/Core/Site/IntegrationConnection.php
- app/Jobs/Design/AnalyzeConnectionWebsitesJob.php
- app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Jobs/Analytics/RecordAnalyticsEventJob.php
- app/Services/Notifications/NotificationPublisher.php
- app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php
- app/Http/Controllers/Api/Platforms/ShopController.php
- app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php
- routes/console.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [ ] **SCALE-1** · P2 — `PruneNotifications` issues one unbounded `DELETE` per run instead of batching like its sibling purge command
    - **Where:** app/Console/Commands/PruneNotifications.php:36
    - **Affects:** The `notifications.notifications` table (and cascaded `notification_receipts` rows) on every professional. Runs daily at 03:25 automatically — not an opt-in operator action.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Batch the delete the same way `PurgeRawAnalyticsEvents::purgeBatched()` already does: loop `->limit($batchSize)->delete()` until a partial batch is returned.
        - Pull the batch size from `config('partna.analytics.purge_batch_size', 10_000)` or a dedicated notifications-purge config key so both purge commands share one convention.
    - **Technical:** `routes/console.php`'s schedule comment for this command claims the job is "bounded by retention-window batch size," but `PruneNotifications::handle()` has no batching at all — it runs a single `$q->delete()` over every non-critical notification whose `ends_at` is more than 30 days old. `PurgeRawAnalyticsEvents` (same file family, same cadence style) deliberately batches with a `do…while` loop specifically to avoid long-running transactions on hot tables. As notification volume grows with the user base, this single statement's row count grows unbounded, and the table's `ON DELETE CASCADE` to `notification_receipts` amplifies the same statement's lock/I/O footprint. A slow run risks lengthening the daily maintenance window and holding table locks longer than intended.
    - **Plain English:** Every night the system cleans out old notifications people have already seen. Right now it does this cleanup in one giant sweep instead of small batches — like emptying an entire recycling bin into the truck in one heave instead of a few manageable bags. As the number of professionals grows, that one heave gets heavier and could jam things up. The system already knows how to do this safely in small batches for a similar cleanup job elsewhere — this one should copy that pattern.
    - **Evidence:**
        ```php
        Schedule::command('partna:prune-notifications', ['--days' => 30])
            ->dailyAt('03:25')
            ->onOneServer()
            ->withoutOverlapping(120) // 2h lock — bounded by retention-window batch size.
        ```
        ```php
        $deleted = $q->delete(); // relies ON DELETE CASCADE to remove receipts
        ```

- [ ] **SCALE-2** · P2 — Unbounded `->get()` in `BackfillWebsiteAnalysesCommand` when `--retry-failures` is used
    - **Where:** app/Console/Commands/BackfillWebsiteAnalysesCommand.php:78
    - **Affects:** Operators running `design:backfill-website-analyses --retry-failures`. At scale (thousands of active shop/custom connections), this loads every matching row into memory at once before looping.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `foreach ($outsideConnections()->get() as $connection)` with `$outsideConnections()->chunk(200, function ($chunk) { ... })` or `->cursor()`.
    - **Technical:** `$outsideConnections()->get()` materialises the full matching `IntegrationConnection` result set into memory before the mutate-and-update loop runs. This is operator-invoked (not scheduled), so today's blast radius is low, but as the connection table grows into the tens of thousands the command risks memory pressure and a long-running, deploy-blocking foreground process. The fix is a one-line swap to `chunk()`/`cursor()` — no behavior change, since each row is already updated independently.
    - **Plain English:** When an engineer runs the repair tool with the "retry failures" switch, it grabs every single matching record into memory at once — like emptying an entire filing cabinet onto a desk before reading each paper. As the user base grows, that desk gets too crowded. The fix is to process records in small stacks instead of all at once.
    - **Evidence:**
        ```php
        if ($this->option('retry-failures')) {
            foreach ($outsideConnections()->get() as $connection) {
                $payload = is_array($connection->payload) ? $connection->payload : [];
                $changed = false;
                if ($connection->platform === Platform::Custom->value) {
                    if ($this->stripFailedAnalysis($payload)) {
                        $changed = true;
                    }
                } else {
                    foreach ($payload as $key => $brand) {
                        if (is_array($brand) && $this->stripFailedAnalysis($payload[$key])) {
                            $changed = true;
                        }
                    }
                }
                if ($changed) {
                    IntegrationConnection::withoutEvents(fn () => $connection->update(['payload' => $payload]));
                }
            }
        }
        ```

- [ ] **SCALE-3** · P2 — `analytics:compute-popularity` full-sweeps every published site every 15 minutes
    - **Where:** routes/console.php:73-88, app/Console/Commands/ComputeContentPopularityScores.php:148-157
    - **Affects:** Scheduler runtime and Postgres load, automatically every 15 minutes. The command re-computes popularity for EVERY published site each tick regardless of whether it received any new analytics events.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Scope the sweep to sites with events since the last run (e.g. a `last_popularity_computed_at` timestamp on `site.sites`, or a `MAX(occurred_at)`-since-cutoff filter joined against the raw event tables before `chunkById`).
        - Alternatively, move back toward a coarser cadence for the full sweep and dispatch a lighter per-site queue job for sites that just received a live event, so the 15-minute tick isn't paying for idle sites.
    - **Technical:** The scheduler entry's own comment (added 2026-07-09, still present and unresolved) flags this directly: the cadence was dropped from daily to every-15-minutes for ONE-theme validation, but the command still iterates every `is_published = true` site via `chunkById(200, ...)` on every tick, and the 0.7/0.3 blend + 90-day half-life were tuned assuming a daily run, not 96 runs/day. `withoutOverlapping(14)` prevents concurrent runs, but as the published-site count grows, a run that creeps past 14 minutes causes the scheduler to skip ticks, and the blend's math degrades further at higher cadence (per the comment: "at 15-min the blend barely smooths"). This is a known, self-documented gap, not a hypothetical.
    - **Plain English:** Every 15 minutes the system recalculates a "how popular is this page" score for every single professional's page — even pages nobody has visited in weeks. Imagine repainting every piece of gym equipment every 15 minutes whether it's been used or not; that's a lot of wasted effort. The fix is to only redo the score for equipment (pages) that actually got used since the last check.
    - **Evidence:**
        ```php
        // CADENCE (2026-07-09): every 15 min while validating the ONE theme, so page +
        // item scores reflect real browsing without a manual trigger. ⚠️ REVISIT before
        // real prod scale — this full-sweeps EVERY published site each run (wasteful at
        // scale; should scope to sites with recent events), and the 0.7/0.3 hysteresis
        // blend + 90-day half-life were tuned for a DAILY cadence (at 15-min the blend
        // barely smooths). Was: ->dailyAt('02:40'). The daily 03:00 purge still bounds
        // the retained window this reads.
        Schedule::command('analytics:compute-popularity')
            ->everyFifteenMinutes()
            ->onOneServer()
            ->withoutOverlapping(14) // 14min lock (< 15min cadence): releases immediately on a normal run; a stuck run's lock clears before the next tick.
        ```
        ```php
        $query = Site::query()->where('is_published', true)->with('user');
        ```

## P3 — Nice to have

## Suggested Bundled Sessions

    - **Why grouped:** Same root pattern (unbounded or unscoped work in `app/Console/Commands/`, scheduled or operator-invoked) with the same class of fix (batch/chunk/scope).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** Both are latent/low-impact N+1 traps around the design-kit and shop-brand read paths, same fix shape (eager-load or guard test), no urgency.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

None.


<!-- ═══════════ audit-2026-07-11-schema-rls.md ═══════════ -->

# Schema / RLS / search_path Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Schema / RLS / search_path: database-side correctness, constraint coverage, migration safety
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `supabase/migrations/20260701150000_create_workplaces.sql`
- `supabase/migrations/20260704160000_shop_brands_products.sql`
- `supabase/migrations/20260705150000_workplaces_identity_columns.sql`
- `supabase/migrations/20260705150200_create_content_selection.sql`
- `supabase/migrations/20260707030000_shop_brand_modes.sql`
- `supabase/migrations/20260708120000_sites_shop_global_settings.sql`
- `supabase/migrations/20260709042716_create_content_popularity_scores.sql`
- `supabase/migrations/20260709042911_create_item_views.sql`
- `supabase/migrations/20260710140000_rls_policies_new_tables.sql`
- `supabase/migrations/20260711000000_staff_account_type.sql`
- `supabase/migrations/20260711000100_user_segments.sql`
- `supabase/migrations/20260711000200_feature_availability.sql`
- `supabase/migrations/20260711000300_early_access_signups.sql`
- `supabase/migrations/20260711153000_feedback_type_area_target.sql`
- `supabase/migrations/20260711160000_analytics_force_rls_parity.sql`
- `app/Models/Analytics/ItemView.php`
- `app/Models/Core/Segments/UserSegmentMember.php`
- `app/Models/Core/Site/ShopBrand.php`
- `app/Models/Core/Site/Site.php`
- `app/Console/Commands/PurgeRawAnalyticsEvents.php`
- `scripts/guard-no-unsafe-migrations.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 5 of 5 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [x] **#SCHEMA-1** · P2 — `analytics.item_views.item_type` has a documented 7-value taxonomy but no `CHECK` constraint
    - **Where:** supabase/migrations/20260709042911_create_item_views.sql:13
    - **Affects:** Data integrity for popularity scoring — a mistyped `item_type` from the item-seen telemetry endpoint silently pollutes `analytics:compute-popularity` input.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ALTER TABLE analytics.item_views ADD CONSTRAINT item_views_item_type_check CHECK (item_type IN ('shop_product','menu_item','menu_category','service','block','gallery_item','engine_item')) NOT VALID;` then `VALIDATE CONSTRAINT` in a follow-up migration (`scripts/guard-no-unsafe-migrations.php` Check 3 requires the split).
    - **Technical:** The column comment documents the exact taxonomy inline but nothing enforces it at the DB layer. The canonical pattern for exactly this shape is `site.site_media.pool CHECK (pool IN (...))`, added `NOT VALID` then validated. `core.users.account_type` (`20260711000000_staff_account_type.sql`) follows the identical two-step dance for the same reason. The guard script (`scripts/guard-no-unsafe-migrations.php`, Check 3) requires `NOT VALID` + a separate `VALIDATE CONSTRAINT` migration for any new `CHECK` — that's a real but small cost, not a reason to skip the constraint entirely.
    - **Plain English:** The analytics pipeline records "what kind of thing was viewed" — a product, a menu item, a gallery image, etc. There are exactly seven valid kinds, documented right in the code, but the database doesn't enforce that list. A typo from the frontend (like `'shopProduct'` instead of `'shop_product'`) would write bad data forever into the popularity scores. A one-line rule closes that gap.
    - **Evidence:**
        ```sql
        item_type    text NOT NULL,    -- shop_product|menu_item|menu_category|service|block|gallery_item|engine_item
        ```

- [x] **#SCHEMA-2** · P2 — `analytics.content_popularity_scores.content_type` has a documented 8-value taxonomy but no `CHECK` constraint
    - **Where:** supabase/migrations/20260709042716_create_content_popularity_scores.sql:10
    - **Affects:** The read-side popularity table the public payload builder (`IndividualProfilePayloadBuilder`) and `RankedActionsComputer` read directly — a bad `content_type` from a buggy upsert in `analytics:compute-popularity` surfaces wrong ranks in the sitepage payload.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ALTER TABLE analytics.content_popularity_scores ADD CONSTRAINT content_popularity_scores_content_type_check CHECK (content_type IN ('page','shop_product','menu_item','menu_category','service','block','gallery_item','engine_item')) NOT VALID;` then `VALIDATE CONSTRAINT`.
    - **Technical:** Same taxonomy as `item_views.item_type` plus `'page'`. Unlike `item_views`, this table has no purge job coverage (see `#SCHEMA-3` below) so a bad row here is effectively permanent, making the constraint slightly higher-value than its `item_views` sibling. Same canonical `NOT VALID` + `VALIDATE` pattern applies.
    - **Plain English:** The popularity leaderboard table — "which products/menu items/images are most popular" — has a column for "what kind of content this score is for." There are eight valid kinds, but the database doesn't enforce that list. One bug in the nightly scoring job and the leaderboard could return nonsense that's read directly by the live sitepage.
    - **Evidence:**
        ```sql
        content_type text NOT NULL,   -- page|shop_product|menu_item|menu_category|service|block|gallery_item|engine_item
        ```

- [x] **#SCHEMA-3** · P2 — `analytics.item_views` and `analytics.content_popularity_scores` have no foreign key on `site_id` (or `user_id`), unlike their sibling `analytics.section_views` — orphan rows never clean up after site deletion
    - **Where:** supabase/migrations/20260709042911_create_item_views.sql:10-12; supabase/migrations/20260709042716_create_content_popularity_scores.sql:9
    - **Affects:** `AccountDeletionService::forceDelete()` relies on `ON DELETE CASCADE` FK chains to clean up user/site-linked rows (per its own comments: "user_id-linked rows are cascade-deleted by forceDelete via FK ON DELETE CASCADE"). Neither table has that FK, so a force-deleted site/user leaves permanently dangling `site_id`/`user_id` values. `analytics.item_views` self-heals via the 90-day `partna:analytics:purge-raw-events` retention purge; `analytics.content_popularity_scores` is **not** in that command's table list at all, so its orphan rows persist forever.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `site_id uuid REFERENCES site.sites(id) ON DELETE CASCADE` (and `user_id uuid REFERENCES core.users(id) ON DELETE CASCADE` on `item_views`) via `ADD CONSTRAINT ... FOREIGN KEY ... NOT VALID` + `VALIDATE CONSTRAINT` (guard script Check 2).
        - Either add `analytics.content_popularity_scores` to `PurgeRawAnalyticsEvents::TABLES` keyed on `computed_at`, or rely purely on the new `ON DELETE CASCADE` for cleanup (cascade is sufficient once the FK exists).
    - **Technical:** The direct sibling table `analytics.section_views` (same author, same file family, explicitly named as the pattern `item_views` "mirrors") declares `CONSTRAINT section_views_site_fk FOREIGN KEY (site_id) REFERENCES site.sites(id) ON DELETE CASCADE` and an equivalent `professional_id` FK in the baseline (`20260526000000_baseline_standalone_user.sql:1235`). `item_views` and `content_popularity_scores` — both created 2026-07-09, well after the FK-safety convention (`GRANDFATHERED_CUTOFF = 20260514100000`) — dropped that FK entirely rather than just its `NOT VALID` qualifier. This is a real regression from the established sibling pattern, not a deliberate simplification (nothing in either migration's header explains the omission).
    - **Plain English:** When someone deletes their account, most of their data is cleaned up automatically because the database is told "when the parent row goes, delete the children too." These two newer analytics tables never got that instruction, so a deleted user's item-view and popularity-score rows just sit there forever, unlinked to anyone. It's not a live data leak (site IDs aren't reused), but it's clutter the database was supposed to clean up and never will.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS analytics.item_views (
            id           uuid PRIMARY KEY DEFAULT gen_random_uuid()
            user_id      uuid,             -- site owner (denormalized; populated by controller, nullable = fail-open)
            site_id      uuid NOT NULL
        ```
        ```sql
        CREATE TABLE IF NOT EXISTS analytics.content_popularity_scores (
            id           uuid PRIMARY KEY DEFAULT gen_random_uuid()
            site_id      uuid NOT NULL
        ```

- [x] **#SCHEMA-4** · P2 — `site.shop_brands.selection_mode` and `.link_mode` are two-value enum columns without a `CHECK` constraint
    - **Where:** supabase/migrations/20260707030000_shop_brand_modes.sql:19-22
    - **Affects:** Data integrity for per-brand store rendering — a raw INSERT or direct DB fix could write an invalid `selection_mode`/`link_mode`; `UpdateShopBrandRequest` is the only guard today.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ADD CONSTRAINT shop_brands_selection_mode_check CHECK (selection_mode IN ('manual','latest')) NOT VALID` + `VALIDATE`.
        - `ADD CONSTRAINT shop_brands_link_mode_check CHECK (link_mode IN ('product','checkout')) NOT VALID` + `VALIDATE`.
    - **Technical:** The migration's own comment says "no CHECK constraints, matching the SQLite-test-mirror convention" — but this doesn't hold up: `site.sites.booking_mode` (same schema, same table family) *does* carry a DB `CHECK` (`sites_booking_mode_check`, referenced directly in `App\Models\Core\Site\Site::BOOKING_MODES`'s docblock) despite the identical SQLite-test-mirror concern, and the app-side `Site::SHOP_LINK_MODES`/`ShopBrand`'s two modes are exactly the shape the canonical `site.site_media.pool` `CHECK` pattern targets. The guard script's `NOT VALID`+`VALIDATE` requirement is the documented safe path, not a reason to omit the constraint.
    - **Plain English:** Two dropdown columns — each with only two valid choices — are stored as free text with no database-level "only these values" rule, even though a sibling column on the same table (`booking_mode`) already has exactly this kind of rule. A typo written directly to the database would be accepted silently. The fix is a one-line guardrail.
    - **Evidence:**
        ```sql
        ALTER TABLE site.shop_brands
            ADD COLUMN IF NOT EXISTS selection_mode text NOT NULL DEFAULT 'manual'
            ADD COLUMN IF NOT EXISTS link_mode text NOT NULL DEFAULT 'product'
            ADD COLUMN IF NOT EXISTS referral_query text NOT NULL DEFAULT '';
        ```

- [x] **#SCHEMA-5** · P2 — `site.sites.shop_link_mode` is a two-value GLOBAL enum column without a `CHECK` constraint, unlike the sibling `booking_mode` column on the same table
    - **Where:** supabase/migrations/20260708120000_sites_shop_global_settings.sql:28
    - **Affects:** Every connected store on every site — `shop_link_mode` is the single global switch the public payload builder (`PublicIntegrationConnectionResource`) stamps onto every brand's `linkMode` at read time. An invalid value here has the largest blast radius of any finding in this audit (site-wide, not per-brand).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ADD CONSTRAINT sites_shop_link_mode_check CHECK (shop_link_mode IN ('checkout','product')) NOT VALID` + `VALIDATE CONSTRAINT` in a follow-up migration.
    - **Technical:** `Site::SHOP_LINK_MODES = ['checkout', 'product']` is the app-side source of truth (`app/Models/Core/Site/Site.php:41`), and the migration comment explicitly cites the same "SQLite-test-mirror convention" rationale as `#SCHEMA-4` to skip it. But `Site::BOOKING_MODES` — a structurally identical two-value enum on the exact same table — *is* backed by a real DB `CHECK` (`sites_booking_mode_check`, per the model's own docblock: "mirrors the sites_booking_mode_check DB CHECK constraint"). There is no principled reason `booking_mode` gets a DB-level guarantee and `shop_link_mode` doesn't; both are small, pre-beta, low-row-count tables where the `NOT VALID`+`VALIDATE` split costs nothing.
    - **Plain English:** The master switch that controls whether every store link on every site goes straight to checkout or to the product page is stored as free text with no database guardrail — even though a nearly identical switch on the very same table (booking mode) already has one. Same fix, applied consistently.
    - **Evidence:**
        ```sql
        ALTER TABLE site.sites
            ADD COLUMN IF NOT EXISTS shop_link_mode  text    NOT NULL DEFAULT 'checkout'
        ```

## P3 — Nice to have

## Suggested Bundled Sessions

None — every finding in this audit is a `supabase/migrations/` schema change, and per the fix-flow doctrine every DB migration/schema change runs standalone with its own plan + sign-off, never bundled.

## Standalone — do NOT bundle

- **#SCHEMA-1 — item_views.item_type CHECK constraint** · DB migration/schema change.
- **#SCHEMA-2 — content_popularity_scores.content_type CHECK constraint** · DB migration/schema change.
- **#SCHEMA-3 — missing FK on item_views/content_popularity_scores** · DB migration/schema change (FK + backfill).
- **#SCHEMA-4 — shop_brands selection_mode/link_mode CHECK constraints** · DB migration/schema change.
- **#SCHEMA-5 — sites.shop_link_mode CHECK constraint** · DB migration/schema change (site-wide blast radius).


<!-- ═══════════ audit-2026-07-11-caching-gold-standard.md ═══════════ -->

# Caching Gold-Standard Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Caching: gold-standard adherence — deviations from `CacheLockService::rememberLocked` / `SiteCacheService::getPublicSitePayload` gold standard (single-flight locks, TTL jitter, stale-while-revalidate, push-invalidation, version tokens, lock hygiene, bounded TTLs, centralised key generation)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Http/Middleware/AddPublicCacheHeaders.php
- app/Http/Middleware/Auth/EnsurePartnaAdmin.php
- app/Http/Middleware/Auth/EnsurePartnaStaff.php
- app/Http/Middleware/Auth/RequireAal2.php
- app/Http/Middleware/Auth/VerifySupabaseJwt.php
- app/Http/Middleware/Context/EnforcePendingDeletionReadOnly.php
- app/Http/Middleware/Context/LoadCurrentUser.php
- app/Http/Middleware/IdempotencyKey.php
- app/Http/Middleware/Logging/LogLeadRateLimits.php
- app/Http/Middleware/Logging/RecordStaffAuditEntry.php
- app/Http/Middleware/Moderation/PerTargetReportThrottle.php
- app/Http/Middleware/VerifyBotToken.php
- app/Services/Accounts/AccountCapabilities.php
- app/Services/Accounts/AccountCapabilitySet.php
- app/Services/Cache/CacheKeyGenerator.php
- app/Services/Cache/SiteCacheService.php
- app/Services/Cache/UserCacheService.php
- app/Services/FeatureAvailability/FeatureAvailability.php
- app/Services/FeatureAvailability/UserFeatureAvailability.php
- app/Services/PublicSite/IndividualProfilePayloadBuilder.php
- app/Services/PublicSite/SiteActionsService.php
- app/Services/PublicSite/SitepageDataResolverService.php
- app/Services/Site/ContentSelectionService.php
- app/Services/Site/UpdateSiteAction.php
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Observers/Core/IntegrationConnectionObserver.php
- app/Observers/User/UserObserver.php
- app/Services/Analytics/AnalyticsCacheService.php
- app/Services/Analytics/AnalyticsDedupGuard.php
- app/Services/Analytics/AnalyticsEvent.php
- app/Services/Analytics/AnalyticsQueryService.php
- app/Services/Analytics/Concerns/EscalatesRepeatedFaults.php
- app/Services/Analytics/ContentFreshness.php
- app/Services/Analytics/ContentPopularityReader.php
- app/Services/Analytics/Ingestors/QueuedIngestor.php
- app/Services/Analytics/InsightEngine.php
- app/Services/Analytics/RankedActionsComputer.php
- app/Services/Analytics/Writers/PostgresEventWriter.php
- app/Services/Notifications/Dispatchers/AchievementNotifier.php
- app/Services/Notifications/Dispatchers/PlatformHealthNotifier.php
- app/Services/Notifications/NotificationPublisher.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 4 of 4 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

## P2 — Should fix

- [x] **#CCH-2** · P2 — `FeatureAvailability::for()` writes with a hardcoded, unjittered 60s TTL
    - **✅ ALREADY FIXED (verified 2026-07-20).** Commit `d211fb34` routed `FeatureAvailability::for()` through `CacheLockService::rememberLocked`, and `writeWithJitter()` applies ±20% jitter to every int TTL write (`CacheLockService.php:186`). No code change needed.
    - **Where:** app/Services/FeatureAvailability/FeatureAvailability.php:33, 43
    - **Affects:** Every user whose feature-availability entry was written in the same second (post-flush stampede, deploy restart) — all expire on the same tick.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Route the write through `CacheLockService::rememberLocked` (jitters automatically) — same fix as CCH-1.
    - **Technical:** `CACHE_TTL_SECONDS` is a literal `60` written straight into `Cache::remember`. Every entry written within the same second shares the same expiry second, synchronising a re-fetch stampede across users. `JitteredTtl::applyJitter(60)` (applied automatically by `rememberLocked`) spreads expiry across ~48–72s.
    - **Plain English:** All the cached feature-flag answers are set to expire at exactly the same second, like every parking meter in a city running out at 3:00:00 PM sharp — everyone refills at once. A small random offset spreads the expirations out so the rush never forms.
    - **Evidence:**
        ```php
        private const CACHE_TTL_SECONDS = 60;
        // ...
        $overrides = Cache::remember(
            "feature-availability:user:{$user->id}:v{$version}"
            self::CACHE_TTL_SECONDS
            fn () => self::resolveOverrides($user)
        );
        ```

- [x] **#CCH-3** · P2 — `FeatureAvailability::for()` has no stale-while-revalidate companion
    - **✅ ALREADY FIXED (verified 2026-07-20).** Same commit `d211fb34`: `rememberLocked` unconditionally writes a `:stale` companion key at 10× TTL (`CacheLockService.php:190`), giving `for()` SWR semantics. No code change needed.
    - **Where:** app/Services/FeatureAvailability/FeatureAvailability.php:41-45
    - **Affects:** Any caller whose per-user entry expired — blocks on the DB query + segment resolution instead of getting last-good immediately.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Same fix as CCH-1/CCH-2 — `rememberLocked` pairs every write with a `:stale` copy at 10× TTL automatically.
    - **Technical:** `Cache::remember` writes only the primary key; on expiry every concurrent caller blocks on `resolveOverrides()`. Impact is bounded (60s TTL, per-user key) but is still a deviation from the gold standard `rememberLocked` provides for free.
    - **Plain English:** When a user's cached feature-flag answer expires, the next request has to wait for the full lookup to finish. With a stale-while-revalidate pattern they'd get the previous answer instantly while a worker quietly refreshes it in the background — like a shop that keeps selling from the shelf while a stocker restocks out back.
    - **Evidence:**
        ```php
        $overrides = Cache::remember(
            "feature-availability:user:{$user->id}:v{$version}"
            self::CACHE_TTL_SECONDS
            fn () => self::resolveOverrides($user)
        );
        // No :stale companion written anywhere.
        ```

- [x] **#CCH-4** · P2 — `AnalyticsCacheService::computeInsights` swallows exceptions and caches an empty result for the full 1h TTL
    - **Where:** app/Services/Analytics/AnalyticsCacheService.php:131, 142-209 (`insights()` / `computeInsights()`)
    - **Affects:** Every professional viewing the analytics dashboard "Insights" card — a transient DB blip produces an empty insights panel that `rememberLocked` then caches fleet-wide for up to an hour, self-healing only on TTL expiry, with no Nightwatch signal.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the `try/catch` inside `computeInsights` and let exceptions bubble out of the `rememberLocked` closure so Nightwatch surfaces the fault and `rememberLocked` never persists it.
        - If a fail-open empty panel is genuinely wanted, cache it via a short, dedicated negative-cache path (e.g. `rememberLockedNullable` with a short null-TTL) rather than the same 3600s key `summary()`'s siblings use.
    - **Technical:** `insights()` calls `$this->cacheLock->rememberLocked($cacheKey, 3600, fn (): array => $this->computeInsights($professional))`. Inside `computeInsights`, `catch (Throwable $e)` returns `[]`, which `rememberLocked` then writes to the primary AND `:stale` Redis keys for a full hour — a textbook category-10 violation (read-through hides errors). Notably, `summary()` in the same class does NOT wrap its closure in try/catch — exceptions there bubble and Nightwatch fires — so this is an inconsistency within one service, not a deliberate house style.
    - **Plain English:** Imagine a specials board that gets locked behind glass for an hour once written. If the chef has a bad morning and can't cook, the board gets locked with a blank sheet for the full hour — every customer sees "no specials" even after the kitchen recovers ten minutes later. The fix is to not lock up a blank board — let the manager (monitoring) know something went wrong instead of hiding it behind a plausible-looking empty result.
    - **Evidence:**
        ```php
        return $this->cacheLock->rememberLocked($cacheKey, 3600, fn (): array => $this->computeInsights($professional));
        ```
        ```php
        private function computeInsights(User $professional): array
        {
            try {
                $proId = $professional->id;
                // ... computes insights from queries ...
                return $insights;
            } catch (Throwable $e) {
                Log::warning('analytics.insights_failed', ['user_id' => $professional->id, 'error' => $e->getMessage()]);

                return [];
            }
        }
        ```

- [x] **#CCH-5** · P2 — `FeatureAvailability::resolveOverrides()` swallows DB exceptions and caches the empty ("all features available") sentinel for the full TTL
    - **Where:** app/Services/FeatureAvailability/FeatureAvailability.php:62-70
    - **Affects:** All users for up to 60 seconds after any transient DB error — the fail-open empty map ("all features available," including gated integrations) gets cached fleet-wide via the enclosing `Cache::remember`, even after the DB recovers seconds later.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Let the `\Throwable` bubble out of the closure so it is never cached; Nightwatch will surface the fault and the next request retries fresh.
        - If fail-open is non-negotiable for the SQLite-test-mirror case the comment describes, guard that specific case explicitly (e.g. a config flag) rather than swallowing all `\Throwable`s, and if a sentinel is still wanted, cache it with a short, dedicated TTL via `rememberLockedNullable` rather than the shared 60s key.
    - **Technical:** The closure passed to `Cache::remember` in `for()` calls `resolveOverrides()`, which catches `\Throwable` and returns `[]` on any DB failure — this empty map is then written into Redis for 60s by the enclosing `Cache::remember`/`rememberLocked` call. A 2-second DB blip becomes a 60-second fleet-wide fail-open window where every staff-managed restriction (e.g. `integration.<platform>` availability) reads as "available" regardless of intent, even after the DB is healthy again.
    - **Plain English:** If the database hiccups for two seconds, the system stores "no restrictions apply" for a full minute — even after the database recovers 58 seconds early. It's like a restaurant that runs out of a dish for two minutes and then leaves a "temporarily unavailable" sign up for an hour by mistake.
    - **Evidence:**
        ```php
        try {
            $rules = FeatureAvailabilityRule::query()->get(['feature_key', 'mode', 'segment_id']);
        } catch (\Throwable) {
            // Fail-open to "everything available" — matches the absence-=-enabled
            // contract. Also covers SQLite test mirrors without the table.
            return [];
        }
        ```

## P3 — Nice to have

## Suggested Bundled Sessions

- **Bundle 1 — FeatureAvailability caching hardening:** , #CCH-2, #CCH-3, #CCH-5
    - **Why grouped:** Same file (`app/Services/FeatureAvailability/FeatureAvailability.php`), same root fix — routing `for()` through `CacheLockService::rememberLocked` closes the single-flight, jitter, and SWR gaps in one edit; the exception-swallowing (#CCH-5) and key-centralisation () cleanups are trivial adjacent changes to the same ~10 lines.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet (all S-effort; no escalation needed).

- **Bundle 2 — Analytics insights fail-open caching:** #CCH-4
    - **Why grouped:** Single finding, isolated to `AnalyticsCacheService::computeInsights` — distinct file/service from Bundle 1, no shared fix.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

None.


<!-- ═══════════ audit-2026-07-11-webhook-idempotency.md ═══════════ -->

# Inbound Callbacks & Idempotency Semantics Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Inbound callbacks & idempotency semantics — Supabase auth/email hooks, `bot.token`-gated internal endpoints, and the client-supplied `IdempotencyKey` middleware, measured against the Standard Webhooks gold standard (HMAC-before-parse, atomic idempotency anchors, no silent-200-on-failure, out-of-order tolerance).
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php
- app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php
- app/Http/Controllers/Api/Internal/EnvCheckController.php
- app/Http/Middleware/Auth/VerifySupabaseHookSignature.php
- app/Services/Webhooks/StandardWebhookVerifier.php
- app/Services/Auth/AuthFactorEventRepository.php
- app/Services/Notifications/SupabaseEmailEventService.php
- app/Http/Middleware/IdempotencyKey.php
- app/Http/Middleware/VerifyBotToken.php
- app/Http/Middleware/Logging/LogLeadRateLimits.php
- app/Http/Controllers/Api/User/Account/UserAccountDeletionController.php
- app/Services/User/AccountDeletionService.php
- routes/api.php
- routes/api/user.php
- app/Providers/AppServiceProvider.php (rate limiters)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 2 of 2 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

## P2 — Should fix

- [x] **#WHK-2** · P2 — `idempotent` middleware runs after `throttle:authenticated` on the acco
      **REJECTED — NOT IMPLEMENTED (2026-07-20).** The premise is wrong. It reasons from route-group
      merge order, but `bootstrap/app.php:76-88` already pins the order explicitly with
      `prependToPriorityList(ThrottleRequests::class, IdempotencyKey::class)`, and Laravel's
      `SortedMiddleware` re-sorts the merged stack by that list regardless of textual group nesting.
      The documented contract ("a successful replay must not consume rate-limit budget") is already
      enforced. Worse, the suggested one-line group reorder would risk moving `idempotent` OUTSIDE the
      group that sets `supabase_uid`, where the middleware self-disables (`IdempotencyKey.php:56-58`) —
      silently turning idempotency off entirely. Only a clarifying comment was added to
      `routes/api/user.php` so the next reader does not re-file this. The real double-submit gap on this
      route is `WHK-102`, which IS fixed (domain-layer locked guard in `AccountDeletionService::request()`).unt-deletion route group, so lock-contended 409s consume rate-limit budget
    - **Where:** routes/api/user.php:41-65, app/Http/Middleware/IdempotencyKey.php:95-102
    - **Affects:** Authenticated users hitting `/me/deletion/*` with concurrent identical `Idempotency-Key` requests — the losing request gets a 409 but has already been counted against the per-user `authenticated` rate limit.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Reorder the group so `idempotent` sits ahead of `throttle:authenticated` for this route group (either apply it on the outer group with a no-op guard for GET routes, or split the inner group's middleware stack).
    - **Technical:** Laravel merges nested route-group middleware outer-first, so the effective stack for `/me/deletion/*` is `user.api` → `EnforcePendingDeletionReadOnly` → `throttle:authenticated` → `idempotent` → (route-specific `throttle:3,60` on `/request`, which therefore runs *after* `idempotent` and is unaffected). The lens's documented contract places `idempotent` before `ThrottleRequests` precisely so a lock-contended 409 costs zero rate-limit budget; here it costs one hit against the outer `authenticated` limiter (`RateLimiter::for('authenticated', ...)` in `AppServiceProvider.php`, 300 req/min per user). At that budget a client would need to sustain roughly 300 concurrent identical retries in one minute before self-locking, so this is hardening rather than a live-today failure mode — but it's a real, fixable ordering violation of the documented contract, and the fix is a one-line group reorder.
    - **Plain English:** Before checking whether a request is a duplicate, the server first counts it against how many requests that user is allowed to make per minute. So a burst of identical retries (say, from a flaky connection) uses up part of the user's request allowance even though the server ultimately rejects them as duplicates. The per-minute allowance here is generous enough that this wouldn't realistically lock someone out today, but the order is backwards from how it's documented to work, and it's cheap to fix.
    - **Evidence:**
        ```php
        Route::middleware(['user.api', EnforcePendingDeletionReadOnly::class, 'throttle:authenticated'])
            ->group(function () {
                ...
                Route::prefix('me/deletion')->middleware('idempotent')->group(function () {
                    Route::post('/request', [UserAccountDeletionController::class, 'request'])
                        ->middleware('throttle:3,60');
                    Route::post('/confirm', [UserAccountDeletionController::class, 'confirm']);
                    Route::post('/cancel', [UserAccountDeletionController::class, 'cancel'])
                        ->withoutMiddleware([EnforcePendingDeletionReadOnly::class]);
                });
        ```
        ```php
        if (! $acquired) {
            // Another request with the same key is mid-flight. Tell the client
            // to retry shortly — they should hit the cache fast-path next time.
            return response()->json([
                'message' => 'Request with the same Idempotency-Key is already in progress.'
                'code' => 'idempotency_locked'
            ], 409, ['Retry-After' => '1']);
        }
        ```

- [x] **#WHK-3** · P2 — `VerifyBotToken`'s circuit-breaker fail-open alerting self-masks during the exact Redis outage it's meant to escalate
    - **Where:** app/Http/Middleware/VerifyBotToken.php:223-265 (`firstHitInWindow`, `throttledFailReport`)
    - **Affects:** Operators monitoring bot-protection health during a Redis outage; all `bot.token`-gated public write endpoints (subscribe, signup, lead, enquiry, waitlist, login-identifier) silently lose CAPTCHA enforcement with zero alert.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `throttledFailReport`, add a fallback path when `firstHitInWindow` fails due to a Redis exception (not just "already logged this window") so `report()` still fires — mirror the pattern already used in `LogLeadRateLimits::terminate()` and `IdempotencyKey::logFailOpen()`, both of which fall through to an unconditional `report($e)` when the throttling lock itself is unreachable.
        - Keep `Log::warning` unconditional on this path (as the `provider_error` branch already does) so the breadcrumb survives even when the Nightwatch page is throttled.
    - **Technical:** `throttledFailReport` gates *both* `Log::warning` and `report()` behind `firstHitInWindow()`, which wraps its Redis `INCR`/`EXPIRE` call in a try/catch that returns `false` on any `Throwable` (line 235-237). When Redis is down, `firstHitInWindow` returns `false`, `throttledFailReport` returns immediately, and neither the log nor the Nightwatch report fires — for both the `circuit_open` path and the `breaker_unavailable` path (the latter is reached specifically because a Redis-backed `CircuitBreaker::isOpen()` call just threw, meaning the *same* Redis outage will almost certainly make the subsequent `firstHitInWindow` call fail too). This is the opposite of the established codebase pattern in `LogLeadRateLimits::terminate()` and `IdempotencyKey::logFailOpen()`, both of which catch a lock-acquisition failure and fall through to an unconditional `report($e)` specifically so a sustained outage can't suppress its own alert. A circuit-open state is a security-relevant posture change (CAPTCHA enforcement is bypassed for every gated public endpoint); losing observability of it during the outage that likely caused the breaker to trip is a self-masking failure.
    - **Plain English:** When the bot-protection system trips its circuit breaker and starts letting requests through without a CAPTCHA check, it's supposed to send an alert so the team knows. But the alert mechanism itself relies on the same Redis server that's probably the reason the breaker tripped in the first place. If Redis goes down, the breaker opens, captcha checks get skipped for everyone, and nobody gets paged — because the paging system also needs Redis and quietly gives up. It's a fire alarm wired to the same circuit as the fire.
    - **Evidence:**
        ```php
        private function throttledFailReport(string $dedupKey, string $logEvent, array $context, ?string $reportMessage = null): void
        {
            if (! $this->firstHitInWindow($dedupKey)) {
                return;
            }

            try {
                Log::warning($logEvent, $context);
                if ($reportMessage !== null) {
                    report(new \RuntimeException($reportMessage));
                }
            } catch (Throwable $e) {
                // Observability must never break a request — a fail-open decision is already made.
            }
        }
        ```
        ```php
        private function firstHitInWindow(string $dedupKey): bool
        {
            try {
                ...
                return (int) $count === 1;
            } catch (Throwable $e) {
                return false;
            }
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Account-deletion idempotency hardening:** #WHK-2
    - **Why grouped:** same route group (`/me/deletion/*`), same middleware file, same underlying subsystem — fixing enforcement and reordering can land in one pass over `routes/api/user.php` + `IdempotencyKey.php` + `AccountDeletionService.php`.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#WHK-3 — Bot-protection fail-open observability self-masks:** touches a distinct file (`VerifyBotToken.php`) with no shared subsystem with Bundle 1; small, self-contained fix — runs alone so its Nightwatch-visibility behavior can be verified in isolation (simulate a Redis outage, confirm `report()` fires).


<!-- ═══════════ audit-2026-07-11-transaction-boundaries.md ═══════════ -->

# Transaction Boundary Correctness Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Transaction boundary correctness — DB::transaction / DB::beginTransaction sites measured against the gold-standard discipline (no external I/O, no queue dispatch, no cache writes, no side-effecting observers inside the atomic unit; bounded scope; safe retries; intentional nesting; consistent lock ordering)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `app/Observers/Core/IntegrationConnectionObserver.php`
- `app/Observers/User/UserObserver.php`
- `app/Observers/Core/SiteObserver.php` (adjacent — referenced by TXN-1 claim)
- `app/Services/Accounts/AccountCapabilities.php`
- `app/Services/Accounts/AccountCapabilitySet.php`
- `app/Services/Feedback/FeedbackService.php`
- `app/Services/Moderation/EvidenceSnapshotService.php`
- `app/Services/Site/ContentSelectionService.php`
- `app/Services/Site/UpdateSiteAction.php`
- `app/Services/Site/RenameSubdomainAction.php` (adjacent — invoked inside `UpdateSiteAction`'s transaction)
- `app/Services/User/AccountDeletionService.php`
- `app/Jobs/Account/SendAccountDeletionRequestMailJob.php` (adjacent — verifies afterCommit claim)
- `app/Services/User/DataExport/DataExportPayloadBuilder.php`
- `app/Services/User/SectionVisibilityService.php`
- `app/Services/User/SiteProvisioningService.php`
- `app/Services/User/UserBootstrapService.php`
- `app/Jobs/Analytics/RecordAnalyticsEventJob.php`
- `app/Jobs/Cloudflare/CloudflareCachePurgeJob.php`
- `app/Jobs/Design/AnalyzeConnectionWebsitesJob.php`
- `app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php`
- `app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php`
- `app/Jobs/Platforms/GoogleBusinessEnrichJob.php`
- `app/Jobs/Platforms/InstagramConnectJob.php`
- `app/Jobs/Platforms/MenuFetchJob.php`
- `app/Jobs/ProcessLogoVariantsJob.php`
- `app/Services/Platforms/ShopCatalog.php`
- `app/Providers/EventServiceProvider.php` (adjacent — confirms observer registration)
- `vendor/laravel/framework/src/Illuminate/Queue/Queue.php` (adjacent — confirms job-level `afterCommit` semantics)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete

---

## P1 — Fix before pilot launch

## P2 — Should fix

## Suggested Bundled Sessions

    - **Why grouped:** Same root-cause pattern in two different subsystems — a flag/status column commits in its own transaction ahead of a dependent child-row rebuild that runs in a second, separate transaction, so a failure in the second leaves the flag/status pointing at content that doesn't exist. Same fix shape (widen the transaction to cover both writes) applies to both.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

## Standalone — do NOT bundle

None.


<!-- ═══════════ audit-2026-07-11-data-integrity.md ═══════════ -->

# Data Integrity & Privacy Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Data integrity & privacy: FK hygiene, soft-delete coherence, orphan rows, JSONB drift, PII inventory, retention
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- supabase/migrations/20260704160000_shop_brands_products.sql
- supabase/migrations/20260705150200_create_content_selection.sql
- supabase/migrations/20260707030000_shop_brand_modes.sql
- supabase/migrations/20260705150000_workplaces_identity_columns.sql
- supabase/migrations/20260708124853_staff_audit_log_ip_hash_and_get_reads.sql
- supabase/migrations/20260701150000_create_workplaces.sql
- supabase/migrations/20260711000100_user_segments.sql
- supabase/migrations/20260526000000_baseline_standalone_user.sql
- app/Models/Core/EarlyAccess/EarlyAccessSignup.php
- app/Services/User/DataExport/DataExportPayloadBuilder.php
- app/Services/User/AccountDeletionService.php
- app/Models/Core/Site/SiteMedia.php
- app/Observers/Core/SiteMediaObserver.php
- app/Http/Controllers/Api/User/Uploads/UserUploadController.php
- app/Models/Core/Site/IntegrationConnection.php
- app/Http/Controllers/Api/Platforms/ShopController.php
- app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php
- app/Services/User/UserBootstrapService.php
- app/Console/Commands/PurgeSoftDeleted.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

## P2 — Should fix

## P3 — Nice to have

## Suggested Bundled Sessions

    - **Why grouped:** single-file pair (export builder + deletion service), same root cause (new table shipped same-day without GDPR wiring).
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).
    - **Why grouped:** single migration + single guard method; standalone anyway per the DB-migration rule below, listed here only for theming reference.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

## Standalone — do NOT bundle



<!-- ═══════════ audit-2026-07-11-job-queue-correctness.md ═══════════ -->

# Job/Queue Correctness Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Job/Queue Correctness — idempotency, retry safety, ShouldBeUnique, missing `$this->fail()`, retry storms
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Jobs/Analytics/RecordAnalyticsEventJob.php
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Jobs/Design/AnalyzeConnectionWebsitesJob.php
- app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php
- app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php
- app/Jobs/Platforms/GoogleBusinessEnrichJob.php
- app/Jobs/Platforms/InstagramConnectJob.php
- app/Jobs/Platforms/MenuFetchJob.php
- app/Jobs/ProcessLogoVariantsJob.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 1 of 1 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

## P2 — Should fix

- [x] **#JOB-2** · P2 — GoogleBusinessEnrichJob can re-run the paid Apify scrape on a retry after a partial success
    - **Where:** app/Jobs/Platforms/GoogleBusinessEnrichJob.php:106-166
    - **Affects:** Google Business enrichment flow; duplicate Apify actor billing and duplicate scrape traffic against the same place on a DB-write failure
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Before invoking `$scraper->fetch()`, write an interim `apify_status = 'processing'` (or equivalent) marker to the connection so a retried attempt can detect that the paid step already ran.
        - At the top of `handle()`, short-circuit (treat as already complete / re-fetch nothing) when `apify_status` is already `'ok'` for this run's inputs.
        - This mirrors the pending → processing → ready|failed state machine already used elsewhere in this file (`apify_status: pending/ok/unavailable`) and in `ProcessLogoVariantsJob` (`PROCESSING_STATE_PROCESSING`).
    - **Technical:** `ShouldBeUnique`/`uniqueId()` on this job (scoped to `userId:placeId`, `uniqueFor = 900`) only coalesces *concurrent* duplicate dispatches — Laravel releases a `ShouldBeUnique` lock once a job attempt finishes processing (success or failure), so it does not protect a job's own retry from re-entering. With `$tries = 0` (unlimited, bounded by `retryUntil()`/`$maxExceptions = 2`), an exception thrown between the `$scraper->fetch($this->placeId, $this->userId)` call and the final `saveQuietly()` (e.g. the `forceFill(...)->saveQuietly()` failing) causes a retry that re-evaluates `needsApify()` against the same harvest/category and re-invokes the paid Apify actor, since no status marker distinguishes "scrape already ran" from "not yet attempted."
    - **Plain English:** This job pays a third party to fetch extra business details, then saves the result. If the save step glitches after the paid fetch already succeeded, the job tries the whole thing again from scratch — paying for the same fetch a second time. Leaving a "still working on it" note before the paid step would let a retry skip straight past the expensive part.
    - **Evidence:**
        ```php
        $enrichment = null;
        if ($this->needsApify($harvest, $gbp->category())) {
            $enrichment = $scraper->fetch($this->placeId, $this->userId);
        }
        ```
        ```php
        $connection->forceFill([
            'payload' => [
                ...$businessInfo
                'apifyFetchedAt' => now()->toIso8601String()
                'syncFindings' => $findings
            ]
            'apify_status' => 'ok'
        ])->saveQuietly();
        ```

## P3 — Nice to have

## Suggested Bundled Sessions

- **Bundle 1 — Platforms paid-scrape retry idempotency:** #JOB-2
    - **Why grouped:** Identical root cause (paid vendor scrape re-run on a job's own retry because no pre-call "processing" marker exists) across three sibling files in `app/Jobs/Platforms/`; the fix pattern is the same state-machine addition in each.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet (per file's Execution policy).

    - **Why grouped:** Single small hygiene fix, no shared file/pattern with other findings.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet (combine plan+implement — S effort).

## Standalone — do NOT bundle



<!-- ═══════════ audit-2026-07-11-observability.md ═══════════ -->

# Observability Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Observability: logging gaps, silent failures, missing Nightwatch instrumentation — jobs that swallow exceptions silently, inbound callbacks that 200-but-don't-process, missing Nightwatch coverage, log calls that obscure rather than illuminate
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Console/Commands/ComputeContentPopularityScores.php
- app/Console/Commands/BackfillMediaPaletteCommand.php
- app/Console/Commands/BackfillWebsiteAnalysesCommand.php
- app/Console/Commands/ResolveAllDesignPresetsCommand.php
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Jobs/Analytics/RecordAnalyticsEventJob.php
- app/Jobs/Platforms/GoogleBusinessEnrichJob.php
- app/Jobs/Platforms/MenuFetchJob.php
- app/Jobs/Platforms/InstagramConnectJob.php
- app/Jobs/Moderation/Concerns/HasActionLogLifecycle.php
- app/Services/Audit/StaffAuditService.php
- app/Services/Cloudflare/CloudflarePurgeService.php
- app/Services/Media/ImageVariantService.php
- app/Services/Moderation/EvidenceSnapshotService.php
- app/Services/Platforms/FreshaScraper.php
- app/Services/Platforms/ShopCatalog.php
- app/Services/Platforms/AppleSearch.php
- app/Services/Platforms/YoutubeScraper.php
- app/Services/Platforms/WooCommerceScraper.php
- app/Services/Platforms/PlatformRefresher.php
- app/Services/Platforms/Strategies/Fetch/ShopFetch.php
- app/Services/Platforms/Strategies/Fetch/FreshaFetch.php
- app/Services/Platforms/Strategies/Fetch/AppleMusicFetch.php
- app/Services/Platforms/Strategies/Fetch/YoutubeFetch.php
- app/Http/Controllers/Api/Platforms/ShopController.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 4 of 4 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

## P2 — Should fix

- [x] **OBS-3** · P2 — Ranked-actions computation failure in the popularity-score command is caught and only logged, never reported to Nightwatch
    - **Where:** app/Console/Commands/ComputeContentPopularityScores.php:275-286
    - **Affects:** The derived "ranked actions" ordering layer used by the dashboard — a broken computation goes stale with no alert, though core page/item scores are unaffected (the fail-open is intentional and correct there).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e);` alongside the existing `Log::warning` call so a sustained failure of the ranked-actions layer reaches Nightwatch.
        - Keep the fail-open behavior (never let this exception break page/item score writes) — only the alerting is missing.
    - **Technical:** `computeForSite()` deliberately wraps `computeActions()` in a `try/catch (\Throwable $e)` so an action-layer fault degrades to "no rankedActions refresh" rather than corrupting the (unrelated) page/item score writes — this fail-open design is correct and should stay. The gap is purely instrumentation: the catch block calls only `Log::warning`, which is a breadcrumb (per the canonical Nightwatch alert model, only exceptions/`report()` trigger paging), so a sustained bug in `RankedActionsComputer` across every site's nightly run produces zero operator-facing signal.
    - **Plain English:** This command recomputes which actions ("Book now," "Shop," etc.) should be highlighted on a professional's page, ranked by popularity. If that specific calculation breaks, the rest of the analytics job keeps working fine, but the ranking freezes and nobody on the team is told — it just quietly stops updating.
    - **Evidence:**
        ```php
        try {
            $actionResult = $this->computeActions($site, $rows);
            $rows = array_merge($rows, $actionResult['rows']);
            if ($actionResult['deletes'] !== []) {
                $deletes[RankedActionsComputer::CONTENT_TYPE] = $actionResult['deletes'];
            }
        } catch (\Throwable $e) {
            Log::warning('analytics.ranked_actions_failed', [
                'site_id' => $site->id
                'error' => $e->getMessage()
            ]);
        }
        ```
    - `[Adjudicated: jobs-hooks DeepSeek draft OBS-1 (P1, confidence 0.9); re-tiered P2 — fail-open is intentional/documented and scoped to a secondary derived layer, not routing/auth/irreversible-work]`

- [x] **OBS-4** · P2 — ImageVariantService::deleteVariants logs storage-delete failures with good structured context but never escalates to Nightwatch
    - **Where:** app/Services/Media/ImageVariantService.php:345-386
    - **Affects:** Media cleanup on delete/reprocess — a sustained R2/S3 delete failure accumulates orphaned storage objects indefinitely with no operator alert (DB rows are correctly cleared regardless).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - When `$failures !== []`, in addition to the existing `Log::error`, call `report(new \RuntimeException("Image variant storage delete failed for {$imageId}: ".count($failures).' file(s)'));` so a systemic storage outage reaches Nightwatch rather than only Cloud logs.
    - **Technical:** The method already does the right thing for correctness — DB rows are cleared even when storage deletes fail, and failures are logged with good structured context (`image_id`, `failure_count`, `failures`). The only gap is that `Log::error` alone doesn't page anyone; per the canonical alert model, Nightwatch reacts to exceptions/`report()`, not log severity. A sustained storage-provider outage (all deletes failing) would silently leak orphaned objects with zero operator visibility until someone notices the storage bill.
    - **Plain English:** When old profile images are deleted, the system tries to remove the actual files from storage too. If that file-removal keeps failing (say, the storage provider is having issues), the system correctly doesn't block the user — but it also never tells anyone the trash isn't actually being taken out, so orphaned files quietly pile up.
    - **Evidence:**
        ```php
        if ($failures !== []) {
            Log::error('ImageVariantService::deleteVariants: storage delete failures; DB rows cleared, orphans may remain.', [
                'image_id' => $imageId
                'failure_count' => count($failures)
                'failures' => array_slice($failures, 0, 20)
            ]);
        }
        ```
    - `[Adjudicated: vendor-services-1 DeepSeek draft OBS-4 (P2, confidence 0.7); confirmed verbatim, tier retained]`

- [x] **OBS-5** · P2 — Multiple long-running artisan commands lack a `$timeout` property, so a hung run is invisible to Nightwatch's slow-command detection
    - **Where:** app/Console/Commands/ComputeContentPopularityScores.php, app/Console/Commands/BackfillMediaPaletteCommand.php, app/Console/Commands/ResolveAllDesignPresetsCommand.php
    - **Affects:** Nightwatch operators — none of these commands declare a `$timeout`, so Nightwatch's auto-slow-detection has no baseline to compare against; a hung DB query or stuck GD palette extraction blocks a scheduler slot silently.
    - **Effort:** S (~1h)
    - **What to do:**
        - Add a `protected $timeout` (or the equivalent Nightwatch-recognized property) to each of the three commands reflecting realistic worst-case runtime (e.g. a per-site/per-row budget × expected row count).
        - Confirmed excluded from this finding: `BackfillWebsiteAnalysesCommand` — its `handle()` only chunks a query and dispatches `AnalyzePreviousWebsiteJob`/analysis jobs, doing no heavy synchronous work itself, so it isn't a slow-command risk in the same way.
    - **Technical:** `ComputeContentPopularityScores` does chunked-but-synchronous per-site aggregation across `analytics.section_views`/`link_clicks`/`item_views` for every published site; `BackfillMediaPaletteCommand` synchronously runs GD palette extraction per image; `ResolveAllDesignPresetsCommand` synchronously re-resolves `DesignPresetResolver` per site with an active connection. None declare `$timeout`, so Nightwatch's auto-detected "slow command" alerting (which compares actual runtime against a declared baseline) has nothing to compare against for these three.
    - **Plain English:** These are background maintenance scripts that loop over potentially thousands of rows one at a time. If one gets stuck — a slow database query, a corrupted image file — nobody is told, because the monitoring system doesn't know how long the script *should* take and so can't flag "this is taking way too long."
    - **Evidence:**
        ```php
        // ComputeContentPopularityScores — no $timeout declared anywhere in the class
        class ComputeContentPopularityScores extends Command
        {
            protected $signature = 'analytics:compute-popularity
                                    {--dry-run : Report computed scores without writing}
                                    {--site= : Restrict to a single site id (uuid)}';
            protected $description = 'Recompute content_popularity_scores (pages + scored items) from raw analytics events.';
        ```
    - `[Adjudicated: jobs-hooks DeepSeek draft OBS-4 (P2, confidence 0.8); scope narrowed — BackfillWebsiteAnalysesCommand dropped after confirming it only dispatches jobs and does no heavy synchronous work]`

- [x] **OBS-6** · P2 — GoogleBusinessEnrichJob's soft-failure branch marks the connection 'unavailable' with zero logging
    - **Where:** app/Jobs/Platforms/GoogleBusinessEnrichJob.php:111-118
    - **Affects:** Users whose Google Business enrichment fails without an exception (Apify returned nothing AND the website harvest found nothing) — the core Place Details card still renders, but repeated soft failures are invisible to operators.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `Log::warning('google_business.enrich.soft_unavailable', ['user_id' => $this->userId, 'place_id' => $this->placeId]);` before `$this->mark($connection, 'unavailable')` on this branch.
        - Consider incrementing a lightweight failure counter (mirroring `consecutive_failures` on `IntegrationConnection`, already used by the platform's refresh circuit breaker) so a sustained Apify outage is distinguishable from an isolated place with genuinely no enrichable data.
    - **Technical:** This is a normal (non-exception) return path, so it never reaches the job's `failed(Throwable $e)` method — which correctly calls `report($e)` and `Log::error` for genuine exceptions. The soft path — "Apify and the in-house harvest both came back empty" — sets `apify_status = 'unavailable'` via `mark()` with no log call at all. A systemic Apify actor outage would silently mark every new Google Business connection 'unavailable' with Horizon reporting all jobs as successfully completed.
    - **Plain English:** When connecting a Google Business listing, this job tries to fill in extra details (menu, ordering links, etc.) from two sources. If both sources come up empty, the system just marks that part "unavailable" and moves on — which is fine for one bad listing, but if the underlying data source is broken for everyone, nobody on the team is told; it just looks like a string of unlucky listings.
    - **Evidence:**
        ```php
        if ($enrichment === null && $harvest === []) {
            // Soft failure: keep the Place Details payload, just mark the Apify
            // layer 'unavailable' so the dashboard stops polling. No hard fail —
            // the core card is unaffected and a re-connect can retry.
            $this->mark($connection, 'unavailable');

            return;
        }
        ```
    - `[Adjudicated: jobs-hooks DeepSeek draft OBS-2 (P2, confidence 0.85); confirmed verbatim, tier retained]`

## Suggested Bundled Sessions

- **Bundle 1 — Platform-scraper silent-degradation fixes:** , #OBS-6
    - **Why grouped:** Same root-cause pattern (a fetch/scrape failure returns null/empty and is recorded as a quiet non-alerting status) across the Platforms scraper/job layer;  and  additionally require tracing into `PlatformRefresher`/`ShopFetch`/`FreshaFetch`, so reviewing them together avoids re-deriving that context twice.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).  touches the shared `PlatformRefresher` failure-classification path used by every platform — escalate implement → Opus for that item specifically, or split it into its own standalone session (see below) if the plan reveals broader blast radius.

- **Bundle 2 — Instrumentation/logging hygiene:** #OBS-3, #OBS-4, #OBS-5
    - **Why grouped:** All three are "add `report()`/`$timeout`, keep existing behavior" changes with no logic changes to the surrounding fail-open design — low-risk, mechanical, and independent of each other.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet); combine plan+impl given the small size of each change.

## Standalone — do NOT bundle



<!-- ═══════════ audit-2026-07-11-caching-coverage-gaps.md ═══════════ -->

# Caching Coverage Gaps Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Caching coverage gaps — hot, expensive reads with no cache at all (public sitepage resolution, handle/profile resolution, account-capability lookups, dashboard controllers, synchronous vendor reads)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `app/Http/Controllers/Api/PublicSite/AnalyticsController.php`
- `app/Http/Controllers/Api/PublicSite/BootstrapController.php`
- `app/Http/Controllers/Api/PublicSite/PublicEarlyAccessController.php`
- `app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php`
- `app/Http/Controllers/Api/PublicSite/PublicMenuController.php`
- `app/Http/Middleware/AddPublicCacheHeaders.php`
- `app/Http/Middleware/Auth/{EnsurePartnaAdmin,EnsurePartnaStaff,RequireAal2,VerifySupabaseJwt}.php`
- `app/Http/Middleware/Context/{EnforcePendingDeletionReadOnly,LoadCurrentUser}.php`
- `app/Http/Middleware/{IdempotencyKey,VerifyBotToken}.php`
- `app/Http/Middleware/Logging/{LogLeadRateLimits,RecordStaffAuditEntry}.php`
- `app/Http/Middleware/Moderation/PerTargetReportThrottle.php`
- `app/Services/Accounts/{AccountCapabilities,AccountCapabilitySet}.php`
- `app/Services/Cache/{CacheKeyGenerator,SiteCacheService,UserCacheService}.php`
- `app/Services/FeatureAvailability/{FeatureAvailability,UserFeatureAvailability}.php`
- `app/Services/PublicSite/{IndividualProfilePayloadBuilder,SiteActionsService,SitepageDataResolverService}.php`
- `app/Services/Site/{ContentSelectionService,UpdateSiteAction}.php`
- `app/Services/Platforms/**/*.php` (all connect/fetch/highlights strategies, scrapers, registry, payloads)
- `app/Services/Analytics/ContentPopularityReader.php` (adjudicator addition)
- `app/Http/Controllers/Api/PublicSite/IndividualProfileController.php` (adjudicator addition)
- `routes/api.php`, `routes/api/publicSite.php`, `bootstrap/app.php` (adjudicator addition — route/middleware verification)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 0 complete

---

No findings survived adjudication. Summary of verification performed beyond DeepSeek's three chunks:

- **Public profile payload (`IndividualProfileController::show`)** — confirmed the canonical reference implementation: `handle.resolve` cache (30s, `rememberLocked`) → `public.profile:{handle}:{updated_at_ts}` cache (60s, `rememberLocked`, SWR via `SiteCacheService`/`CacheLockService`) wraps the *entire* `IndividualProfilePayloadBuilder::build()` call, which is where the expensive fan-out (`SitepageDataResolverService::presentPageIds`, `getGallery`, `getLinks`, `ContentSelectionService::resolve`, `ContentPopularityReader::forSite`, design-kit read, etc.) actually lives. None of that fan-out is a coverage gap — it's already inside the cache boundary.
- **Auth path (`LoadCurrentUser` → `UserCacheService::getByAuthId`)** — confirmed two-level cache (30min immutable id-map + 60s SWR hydrated-model cache via `CacheLockService::rememberLockedNullable`), matching the doctrine's canonical reference implementation.
- **`PublicMenuController` / `PublicIntegrationController`** — confirmed both routes (`/public/profiles/{handle}/menu`, `/integrations`, `/platforms`) fall under `AddPublicCacheHeaders::CACHEABLE_PATH_PREFIXES` (`api/public/profiles`), which is appended to the global `api` middleware group in `bootstrap/app.php`. Every response gets `Cache-Control: public, max-age=900, s-maxage=900` — the CDN is genuinely the cache layer here, matching the explicit design comment in `PublicIntegrationController`. `ContentPopularityReader::forSite()` (called directly by both controllers, outside any backend cache) is a single indexed `WHERE site_id = ?` returning a small per-site row set — not an aggregate/join/JSONB-scan, so it doesn't clear the "expensive" bar even setting the CDN aside.
- **`AccountCapabilities::for()`** — per-request `WeakMap` memo; the underlying computation reads already-hydrated `User` attributes with no DB query except `staffRole()` for staff accounts (rare, single indexed lookup) — not a coverage gap under the lens (memoization scope matches the value's actual invalidation lifetime; a Redis-level cache would add invalidation surface for a value cheaper than the lookup that would invalidate it).
- **`FeatureAvailability::for()`** — already implements the category-5 canonical fix pattern exactly (`Cache::remember` behind a version token bumped on write).
- **`MenuSource`, `AppleSearch`** — confirmed per-instance memoization / `Cache::get`+`Cache::put` wrapper respectively; both are single indexed reads or already cached vendor calls.

No read in scope is simultaneously hot, expensive, and repeated with zero cache of any kind. This is a clean result, not an unscanned one.

## Suggested Bundled Sessions

None.

## Standalone — do NOT bundle

None.


<!-- ═══════════ audit-2026-07-11-privacy-compliance.md ═══════════ -->

# Privacy & Data-Rights Compliance Audit — 2026-07-12

**Branch:** HEAD
**Lens:** Privacy & data-rights compliance: PII inventory, export/delete completeness, retention enforcement, processor flows (bundle: rights-machinery + collection-retention-1/2 + schema-pii chunks)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- `app/Services/User/AccountDeletionService.php`
- `app/Services/User/DataExport/DataExportPayloadBuilder.php`
- `app/Models/Core/EarlyAccess/EarlyAccessSignup.php`
- `app/Services/Audit/StaffAuditService.php`
- `app/Services/Moderation/EvidenceSnapshotService.php`
- `app/Services/Analytics/Writers/PostgresEventWriter.php`
- `app/Services/Analytics/AnalyticsEventSanitizer.php`
- `app/Http/Controllers/Concerns/DetectsClientInfo.php`
- `app/Http/Resources/WorkplaceResource.php`
- `app/Models/Core/User/User.php`
- `app/Console/Commands/PruneNotifications.php`
- `app/Console/Commands/PurgeRawAnalyticsEvents.php`
- `config/partna.php`
- `routes/console.php`
- `routes/api/staff.php`
- `supabase/migrations/20260711000300_early_access_signups.sql`
- `supabase/migrations/20260705150000_workplaces_identity_columns.sql`
- `supabase/migrations/20260705150100_users_sector_columns.sql`
- `supabase/migrations/20260704160000_shop_brands_products.sql`
- `supabase/migrations/20260711153000_feedback_type_area_target.sql`
- `supabase/migrations/20260706000000_add_city_to_site_visits.sql`
- `supabase/migrations/20260707020000_site_visits_lat_lon.sql`
- `supabase/migrations/20260526000000_baseline_standalone_user.sql`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 5 of 5 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

## P2 — Should fix

- [x] **PRIV-5** · P2 — Internal brand-analysis data excluded from the dashboard but exported wholesale in the GDPR export
    - **Where:** `app/Services/User/DataExport/DataExportPayloadBuilder.php:222-233` (`site()`) and `app/Http/Resources/WorkplaceResource.php:11-13`
    - **Affects:** Professionals requesting a DSAR — they receive machine-generated `previous_website_analysis` data the platform deliberately never shows them on the dashboard.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `DataExportPayloadBuilder::site()`, `unset()` the `previous_website_analysis` key from `$workplaceRow` before including it, matching `WorkplaceResource`'s exclusion policy — or explicitly document why the export includes it and the dashboard doesn't.
    - **Technical:** `WorkplaceResource` deliberately excludes `previous_website_analysis` ("internal brand-signal detail, not part of the public workplace-card contract" — the column exists per `supabase/migrations/20260701220001_workplace_previous_website_analysis.sql` and is written by `AnalyzePreviousWebsiteJob`/`WebsiteStyleAnalyzer`). `DataExportPayloadBuilder::site()` instead returns `(array) $workplaceRow` — a wholesale cast of every column with no redaction, so the export includes exactly the field the dashboard resource was written to hide. Under Article 15 this data likely *should* be disclosed (derived/profiling data about the subject), but the inconsistency between the two paths is the finding — it should be a deliberate decision either way, not an accident of which code path happens to touch the row.
    - **Plain English:** The platform runs an automated analysis of a professional's previous website to inform their design defaults. We deliberately hide that internal analysis from their dashboard. But if they request a full data export, it slips through anyway, because the export code grabs the whole database row instead of the same curated list the dashboard uses. Either show it in both places with an explanation, or hide it in both.
    - **Evidence:**
        ```php
        // WorkplaceResource — deliberately excludes it (docblock)
        // `previous_website_analysis` ... is deliberately excluded: it is internal
        // brand-signal detail, not part of the public workplace-card contract.
        ```
        ```php
        // DataExportPayloadBuilder::site() — wholesale (array) cast includes it
        $workplaceRow = DB::connection('pgsql')->table('site.workplaces')->where('site_id', $site->id)->first();
        return ['site' => (array) $site, 'blocks' => $blocks, 'workplace' => $workplaceRow ? (array) $workplaceRow : null];
        ```

- [x] **PRIV-6** · P2 — New `core.feedback` columns (`type`, `area`, `target`) excluded from the GDPR export's explicit column allow-list
    - **Where:** `app/Services/User/DataExport/DataExportPayloadBuilder.php:298-306` (`streamFeedback`) and `supabase/migrations/20260711153000_feedback_type_area_target.sql`
    - **Affects:** Professionals who submit feedback through the OV-D feedback tool — their reaction category, feature-area context, and structured target metadata are silently omitted from their export.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `type`, `area`, and `target` to the explicit `select([...])` allow-list in `streamFeedback()`.
    - **Technical:** The OV-D feedback commit (`4cf90d17 feat(feedback): type/area/target picker + staff triage list`) added three NULLABLE columns to `core.feedback`. `streamFeedback()` uses an explicit column allow-list (not `select('*')`), so new columns are invisible to the export by default unless someone remembers to add them — which didn't happen here. The base table and its deletion path are already correctly in scope (row-level `user_id` delete in `purgeFeedbackRows`); this is a narrow field-completeness gap, not a missing-store gap.
    - **Plain English:** The feedback form just gained three new fields — what kind of feedback it is, which page it's about, and some structured context. The export tool already includes feedback submissions generally, but because it lists column names explicitly rather than grabbing everything, these three new fields don't make it into a download yet.
    - **Evidence:**
        ```php
        private function streamFeedback(string $userId): Generator
        {
            return $this->lazyRows(
                DB::connection('pgsql')->table('core.feedback')
                    ->select(['id', 'user_id', 'reply_email', 'kind', 'severity', 'message', 'page_url', 'viewport', 'app_version', 'request_id', 'status', 'source', 'tags', 'internal_notes', 'created_at', 'updated_at'])
                    ->where('user_id', $userId)
            );
        }
        ```
        ```sql
        ALTER TABLE core.feedback
            ADD COLUMN type text NULL
            ADD COLUMN area text NULL
            ADD COLUMN target jsonb NULL;
        ```

- [x] **PRIV-7** · P2 — Staff audit log duplicates staff/user email and handle into the append-only audit schema
    - **⛔ REJECTED 2026-07-20 — the prescribed fix is WRONG and would destroy the audit trail. Closed as "no code change, comment added".**
        - The premise (snapshots are written unconditionally into an append-only table) is TRUE. The remedy ("drop the snapshots, resolve email/handle from the FK at read time") is not.
        - All three FKs — `staff_id`, `impersonator_staff_id`, `user_id` — are `ON DELETE SET NULL` (baseline DDL lines 604-625), and `core.users` rows really are hard `forceDelete()`d ~30 days after a deletion request via `AccountDeletionService::purge()` on a daily schedule. Read-time FK resolution therefore loses *who was affected* the moment an account completes its ordinary lifecycle — that is the routine end state of every deleted account, not an edge case.
        - Nothing could repair it afterwards: `app_backend` holds SELECT/INSERT only on `audit.staff_audit_log` (UPDATE/DELETE revoked at baseline and again in `20260527010000_reorganize_schemas.sql`), and a DB trigger `core.reject_staff_audit_log_mutation()` independently rejects mutation. There is no backfill path.
        - The snapshot-beside-a-SET-NULL-FK shape is used **deliberately** by both sibling tables, `audit.user_deletion_audit` and `audit.data_export_audit`, with the rationale already written down in `DataExportPayloadBuilder.php` (~line 692). Treating this one as a bug while its two siblings are correct is internally inconsistent.
        - This is the mirror image of the PRIV-102 rejection in unit 5: that one wrongly assumed new PII could be safely written *into* append-only audit; this one wrongly assumes the referent will still exist to read *back*. Both fail because these rows outlive their referents by design.
        - Action taken: a short comment in `StaffAuditService::record()` recording why the snapshots are intentional, so this is not "fixed" again. Any genuine minimisation (e.g. dropping only `impersonator_email_snapshot`) must first prove `core.partna_staff` rows are never hard-deleted, and needs the same append-only-schema sign-off gate PRIV-102 went through.
    - **Where:** `app/Services/Audit/StaffAuditService.php:33-38`
    - **Affects:** Every staff member whose action is logged, every impersonation event, and every professional whose data staff access — their emails/handle become permanently undeletable once written into `audit.staff_audit_log`.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Stop writing `staff_email_snapshot` / `impersonator_email_snapshot` / `professional_handle_snapshot`; rely on the `staff_id` / `impersonator_staff_id` / `user_id` FKs already stored on the same row.
        - Add a lookup on the audit-reader side (staff UI / export) that resolves email/handle from the FK at read time, gated by the reader's own access controls.
    - **Technical:** `StaffAuditService::record()` writes `staff_email_snapshot`, `impersonator_email_snapshot`, and `professional_handle_snapshot` directly into every row of the append-only `audit.staff_audit_log` table, duplicating PII that's already reachable via the `staff_id`/`impersonator_staff_id`/`user_id` FK columns on the same row. Because the audit schema is append-only by design, this PII can never be corrected or redacted without dropping the whole table — unlike `ip_hash`, which correctly uses one-way HMAC-SHA256 (`hashIp()`), the email/handle columns get no such protection.
    - **Plain English:** Every time a staff member looks at or edits a user's account, we permanently log it — that's good practice. But the log entry also copies in the staff member's email address, any impersonator's email, and the user's handle, baked forever into a record we can never edit or delete. It's like a security camera that also stamps everyone's name and email onto the footage — the footage alone already proves who was there; the extra personal detail just creates a second permanent copy of PII with no way to ever remove it.
    - **Evidence:**
        ```php
        return StaffAuditEntry::query()->create([
            'staff_id' => $staff?->id
            'staff_email_snapshot' => $staff?->primary_email
            'impersonator_staff_id' => $impersonator?->id
            'impersonator_email_snapshot' => $impersonator?->primary_email
            'user_id' => $professional?->id
            'professional_handle_snapshot' => $professional?->handle
            ...
            'ip_hash' => $this->hashIp($ip)
        ]);
        ```

- [x] **PRIV-8** · P2 — `core.feedback` has no declared retention rule and no scheduled purge
    - **Where:** `config/partna.php:1552-1577` (`feedback` section — no `retention_days` key) and `routes/console.php` (no feedback prune command)
    - **Affects:** Every feedback submission ever filed — free-text messages routinely embed the submitter's name/email/context and accumulate with no expiry, unlike the structurally similar `moderation.case_signals` (which has `signal_pii_retention_days` + a weekly prune job).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `retention_days` to `config('partna.feedback')`.
        - Add a scheduled command (`feedback:prune-old-submissions`) registered in `routes/console.php`, logging purge counts (not contents).
    - **Technical:** The `feedback` config section covers rate limits, IP-hash pepper, duplicate detection, and message-length caps, but has no `retention_days` — and no command in `routes/console.php` targets `core.feedback` for age-based deletion (only `AccountDeletionService::purgeFeedbackRows()` removes it, and only on account deletion). Compare `moderation.signal_pii_retention_days` (90d + `moderation:prune-resolved-signal-pii` weekly), which is the correct pattern for exactly this kind of user-generated free-text PII store.
    - **Plain English:** When someone submits feedback — including their name and email in the message — that submission sits in the database forever unless they later delete their whole account. There's no automatic cleanup schedule the way there is for other similar records. Under Australian privacy law, personal information should only be kept as long as there's a real reason to keep it, and "we never built a cleanup job" isn't one.
    - **Evidence:**
        ```php
        'feedback' => [
            'notify_emails' => ...
            'rate_limit_per_hour' => (int) env('FEEDBACK_RATE_LIMIT_HOUR', 10)
            'rate_limit_per_day' => (int) env('FEEDBACK_RATE_LIMIT_DAY', 30)
            'duplicate_window_seconds' => (int) env('FEEDBACK_DUPLICATE_WINDOW', 60)
            'ip_hash_pepper' => env('FEEDBACK_IP_HASH_PEPPER')
            'max_message_length' => 5000
        ]
        // no retention_days key; routes/console.php has no feedback-related Schedule::command entry
        ```

- [x] **PRIV-9** · P2 — Analytics visitor coordinates stored as raw, untruncated `double precision` with no minimisation
    - **Where:** `supabase/migrations/20260707020000_site_visits_lat_lon.sql:12-14`, `app/Services/Analytics/Writers/PostgresEventWriter.php:126-127`, `app/Http/Controllers/Concerns/DetectsClientInfo.php:165-187`
    - **Affects:** Every visitor to any Partna sitepage — edge-resolved lat/lon is persisted at full floating-point precision alongside the already-sufficient `city`/`region_code`/`country_code`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Truncate latitude/longitude to ~4 decimal places (≈11m precision — plenty for a metro-level demographics map pin) in `DetectsClientInfo::parseCoordinate()`, at the point of ingest.
        - Document the chosen precision and rationale in the detector's docblock.
    - **Technical:** `PostgresEventWriter::visitRow()` writes `$e->latitude`/`$e->longitude` straight through with no rounding; `DetectsClientInfo::parseCoordinate()` only range-validates (±90/±180), it never truncates. The same row already carries `city`, `region_code`, and `country_code` (added specifically for "demographics map" per the `city` column's own migration comment: "Best-effort demographics only"), so the raw coordinates add identifiability precision the stated use case doesn't need. This mirrors the referrer/UA sanitisation already applied elsewhere in the same writer (`AnalyticsEventSanitizer` — see dropped-finding note below), which shows the minimisation pattern is already established in this codebase; lat/lon is the one field that pattern didn't reach.
    - **Plain English:** Every visit to a Partna page records the visitor's city and country — reasonable for an analytics dashboard. It also records their exact latitude/longitude to many decimal places, when the only feature that uses it is a "which metro area are visitors from" map pin. That's like a delivery company keeping your precise GPS coordinates on file when all they needed was your suburb. Rounding the numbers down when they're collected keeps the map feature working while dropping the unnecessary precision.
    - **Evidence:**
        ```sql
        -- 20260707020000_site_visits_lat_lon.sql
        ALTER TABLE analytics.site_visits
            ADD COLUMN IF NOT EXISTS latitude double precision
            ADD COLUMN IF NOT EXISTS longitude double precision;
        ```
        ```php
        // PostgresEventWriter::visitRow() — no truncation applied
        'latitude' => $e->latitude
        'longitude' => $e->longitude
        ```
        ```php
        // DetectsClientInfo — only bounds-checks, never rounds
        protected function detectLatitude(Request $request): ?float
        {
            return $this->parseCoordinate($request->header('X-Visitor-Lat'), 90.0);
        }
        ```

## P3 — Nice to have

## Suggested Bundled Sessions

    - **Why grouped:** All are additive entries/fields to the same file (`DataExportPayloadBuilder::sectionDescriptors()` / `site()` / `streamFeedback()`) — one coherent pass over the export manifest.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** Identical root-cause pattern (a declared `config/partna.php` retention value with no matching `routes/console.php` command) — same fix shape, same files.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** All are small, low-risk config/ingest-layer cleanups (precision truncation, stale docs, placeholder values, retention-window tuning) with no cross-file coordination needed.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **PRIV-7 — Staff audit log snapshot removal** · writes into the append-only `audit.staff_audit_log` compliance trail; isolate from other bundles so a regression here doesn't silently corrupt the audit record staff/legal rely on.


<!-- ═══════════ audit-2026-07-11-edge-worker.md ═══════════ -->

# Edge Worker Audit — 2026-07-12

**Branch:** HEAD
**Lens:** Cloudflare Worker routing, KV contract, edge-cache correctness, takedown latency, poisoning
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `cloudflare-worker/src/index.js`
- `cloudflare-worker/wrangler.toml`
- `app/Jobs/Cloudflare/CloudflareCachePurgeJob.php`
- `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php`
- `app/Services/Cloudflare/CloudflarePurgeService.php`
- `app/Services/Moderation/ModerationActionDispatcher.php`
- `app/Jobs/Moderation/PurgeModerationCacheJob.php`
- `config/partna.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

## P2 — Should fix

- [ ] **EDGE-2** · P2 — No automated check keeps the Worker's `RESERVED` set in sync with `reserved_subdomains` (Category 1)
    - **Where:** `cloudflare-worker/src/index.js:44-110` (`RESERVED`) vs `config/partna.php:71-143` (`reserved_subdomains`)
    - **Affects:** Public routing — a future edit that adds/removes an entry on only one side goes undetected until a real handle collision or 404 surfaces in production.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a CI check (Pest test or standalone script) that pulls `config('partna.reserved_subdomains')` and asserts it's a subset/superset match against a copy of the Worker's `RESERVED` set (or generate the Worker constant from the PHP config at build time).
        - Add the reciprocal `@sync` comment in `config/partna.php` naming `cloudflare-worker/src/index.js` explicitly (the Worker side already names the config file).
    - **Technical:** Diffed token-by-token, the two lists are currently identical (verified: all ~230 entries across every category match in both files). The gap is process, not a live bug: nothing fails a build if the two drift going forward, and the existing `SubdomainAvailabilityTest.php` only checks the PHP-side list in isolation. This matches the P2 hardening anchor — the failure mode requires a future edit that isn't policed today, not a value collision that exists now.
    - **Plain English:** Two files list the same "these website addresses are off-limits" words — one used by the traffic router, one by the backend that lets people claim an address. Right now they match, but nothing double-checks that they keep matching after future edits. It's like two people keeping the same blocklist in two separate notebooks with no one comparing them — eventually they'll drift, and either a real page will stop working or someone will be able to grab an address that should've been blocked.
    - **Evidence:**
        ```javascript
        // cloudflare-worker/src/index.js
        // Mirrors `reserved_subdomains` in config/partna.php (EDGE-6/EDGE-11). KEEP IN
        // SYNC: a subdomain missing here is sent to KV and 404s instead of passing
        // through to the apex origin.
        const RESERVED = new Set([
          "www", "api", "admin", /* ... */
        ]);
        ```
        ```php
        // config/partna.php
        'reserved_subdomains' => [
            'www', 'api', 'admin', /* ... */
        ]
        ```

- [ ] **EDGE-3** · P2 — Hardcoded `PARTNA_DOMAIN` / cache TTLs carry no `@sync` comment pointing at the backend config that assumes them (Category 7)
    - **Where:** `cloudflare-worker/src/index.js:42,112-118` vs `config/partna.php` (`public_domain`, `cache.purge_followup_seconds`)
    - **Affects:** Deploy correctness — a backend-side change to `PARTNA_PUBLIC_DOMAIN` or to the purge follow-up delay silently stops matching the Worker's hardcoded assumptions.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a comment on `PARTNA_DOMAIN` naming `config('partna.public_domain')` as its mirror; add the reciprocal comment at the `public_domain` config key naming the Worker constant.
        - Add a comment on `PRIMARY_CACHE_TTL_S`/`STALE_SHADOW_TTL_S` noting that `CloudflareCachePurgeJob`'s `purge_followup_seconds` delay (120s default) is deliberately shorter than these TTLs and must stay that way.
    - **Technical:** The Worker hardcodes `"partna.au"` while the backend derives `public_domain` from `env('PARTNA_PUBLIC_DOMAIN', env('SIDEST_PUBLIC_DOMAIN', parse_url(env('APP_URL', ...))))` — a three-level fallback chain that could resolve differently per environment. `CloudflarePurgeService::purgeHandle()` already reads `config('partna.public_domain')` to build purge target URLs (line 103), so if that value ever diverges from the Worker's literal `"partna.au"`, purges would target the wrong host while the Worker still routes and caches under the old one. Same risk for the two TTL constants against `purge_followup_seconds`. The `RESERVED` set already carries a sync comment (EDGE-2); these constants don't.
    - **Plain English:** The Worker has the website's domain name and two cache-lifespan numbers baked directly into its code. The backend server also has opinions about these same values, driven by settings that can change per environment. If someone changes the backend setting without remembering the Worker has its own hardcoded copy, the two systems quietly start disagreeing — purges could target the wrong address, or a cleanup pass could fire before the cache it's meant to sweep is even due for its own refresh.
    - **Evidence:**
        ```javascript
        // cloudflare-worker/src/index.js
        const PARTNA_DOMAIN = "partna.au";
        const PRIMARY_CACHE_TTL_S = 86_400;
        const STALE_SHADOW_TTL_S = 7 * 86_400;
        ```
        ```php
        // config/partna.php
        'public_domain' => env(
            'PARTNA_PUBLIC_DOMAIN'
            env('SIDEST_PUBLIC_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost')
        )
        ```

- [ ] **EDGE-4** · P2 — KV lookup failure for a `<handle>.partna.au` host falls through to `passThrough(request)`, an unconfirmed origin destination (Category 1/6)
    - **Where:** `cloudflare-worker/src/index.js` — `catch (err)` block in the subdomain KV lookup, inside the default `fetch` handler
    - **Affects:** Every visitor to any `<handle>.partna.au` page during a transient KV outage.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - On KV failure for a subdomain (not the custom-domain branch, which already fails open reasonably since it has no dedicated 404), serve the branded `unclaimedHtml(subdomain)` with a distinct `X-Partna-Cache: kv-error` tag (still `noStore: true`) instead of `passThrough(request)`.
        - This keeps the UX consistent with the "unclaimed" 404 case and avoids sending traffic to a hostname the zone's actual origin DNS was never built to serve (this Worker is the only thing that knows how to route `<handle>.partna.au` — there is no confirmed non-Worker origin for it).
    - **Technical:** `wrangler.toml`'s `[[routes]] pattern = "*/*"` on `zone_name = "partna.au"` means this Worker is the sole handler for every request under the zone (including the wildcard subdomain). `passThrough()`'s `fetch(request)` on a KV error for `<handle>.partna.au` re-resolves against whatever DNS record backs the wildcard — almost certainly not a real application server, since individual-handle routing is Worker+KV+Service-Binding only. The actual destination can't be confirmed from the repo (Cloudflare zone/DNS settings are out of this lens's scope), which is itself the finding per the audit brief: any pass-through whose destination can't be confirmed should be flagged. Best case it's a generic error page; worst case a slow/hanging connection attempt against a placeholder IP.
    - **Plain English:** If the Worker's routing lookup service has a brief outage, instead of showing visitors the same clean "not found" page it normally shows, it tries to forward them to a backend address that most likely doesn't know how to answer for personal handles. That's confusing and inconsistent — visitors would get a different, probably uglier error depending on whether the outage hit the lookup service or something else entirely.
    - **Evidence:**
        ```javascript
        let entry = null;
        try {
          entry = await env.SUBDOMAIN_KV.get(subdomain, {type: "json"});
        } catch (err) {
          // KV transient failure — fail open to avoid blocking user traffic.
          console.error("KV lookup failed", {subdomain, err: String(err)});
          return passThrough(request);
        }
        ```

## P3 — Nice to have

## Suggested Bundled Sessions

- **Bundle 1 — Worker/config sync hygiene:** #EDGE-2, #EDGE-3
    - **Why grouped:** All three are documentation/enforcement gaps between the Worker and its backend/config mirrors (RESERVED list, domain/TTL constants, staging namespace) — same files (`index.js` + `wrangler.toml` + `config/partna.php`), no behavioral risk, low effort each.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — KV-outage UX polish:** #EDGE-4
    - **Why grouped:** Both are minor Worker response-shape tweaks (fail-open branded 404 + CTA link) with no cross-cutting risk; both touch only `index.js`.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle



<!-- ═══════════ audit-2026-07-11-configuration-hygiene.md ═══════════ -->

# Configuration Hygiene Audit — 2026-07-12

**Branch:** HEAD
**Lens:** Configuration Hygiene — env() outside config, missing .env.example keys, feature flags without defaults
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- config/partna.php
- config/services.php
- config/supabase.php
- config/cache.php
- .env.example
- bootstrap/app.php
- routes/api.php, routes/api/{platforms,staff,user}.php, routes/console.php
- app/Console/Commands/*.php
- app/Http/Middleware/**/*.php
- app/Jobs/Analytics/RecordAnalyticsEventJob.php
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Jobs/Design/AnalyzeConnectionWebsitesJob.php
- app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php
- app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php
- app/Jobs/Platforms/{GoogleBusinessEnrichJob,InstagramConnectJob,MenuFetchJob}.php
- app/Jobs/ProcessLogoVariantsJob.php
- app/Services/Cloudflare/CloudflarePurgeService.php
- app/Services/Diagnostics/EnvCheckService.php
- app/Services/Media/{ImagePaletteExtractor,ImageVariantService}.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 1 of 1 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [x] **#CFG-1** · P2 — Auth-adjacent secrets have no rotation runbook or dual-secret window
    - **Where:** config/services.php:44,48,74,79 (`supabase.email_hook_secret`, `supabase.auth_hook_secret`, `cloudflare.api_token`, `cloudflare.cache_purge_token`); config/partna.php:1102 (`logo_removal.token`)
    - **Affects:** Ops — rotating `SUPABASE_AUTH_HOOK_SECRET`, `SUPABASE_EMAIL_HOOK_SECRET`, `CLOUDFLARE_API_TOKEN`, or `CLOUDFLARE_CACHE_PURGE_TOKEN` requires an atomic env+dashboard swap with no grace window; a deploy race between the two 503s every hook delivery until they line up.
    - **Effort:** M (~2–4h) — docs + optional dual-secret support in `VerifySupabaseHookSignature`
    - **What to do:**
        - Add a rotation runbook (comment block in `config/services.php` or a `docs/` note): generate new secret → update the Supabase/Cloudflare dashboard → update env var → deploy → verify → deactivate old secret.
        - For the two Supabase hook secrets specifically, `VerifySupabaseHookSignature` verifies Standard-Webhooks-format signatures — the standard natively supports **space-separated multi-secret verification** during rotation (already referenced in the `.env.example` comment for `SUPABASE_EMAIL_HOOK_SECRET`: "Standard Webhooks supports space-separated signatures for zero-downtime rotation"). Confirm the middleware actually splits and tries each secret, or wire that support in if it doesn't yet.
        - For `CLOUDFLARE_API_TOKEN` / `CLOUDFLARE_CACHE_PURGE_TOKEN`, document that these are single-token (no rotation overlap) and that a rotation requires a brief deploy-synchronized swap; this is lower urgency since Cloudflare token failures degrade (cache purge/DNS ops) rather than block user-facing auth.
    - **Technical:** `config/services.php`'s `supabase.auth_hook_secret` / `supabase.email_hook_secret` are each a single env var consumed by `VerifySupabaseHookSignature`, which gates `POST /internal/*-hooks/supabase`. Rotating either requires the backend and Supabase's dashboard to agree on the secret at the exact same instant — any window where they disagree is a 503 on every real hook delivery (auth emails / auth-hook enforcement stop firing). The `.env.example` comment for the email hook already flags that Standard Webhooks supports space-separated multi-secret verification for exactly this reason; verify `VerifySupabaseHookSignature` implements that (or add it) rather than inventing a new `_VERSION` key scheme, since the wire format already has a native answer. The Cloudflare tokens carry the same single-secret rotation gap but a lower blast radius (KV write / cache purge degrade, not user-facing auth failure).
    - **Plain English:** Imagine your front door's smart lock and your phone's unlock app both need to agree on today's passcode. If you update the passcode on the lock but haven't finished updating the phone app, you're locked out until both catch up. Right now several of Partna's server-to-server "passcodes" (used to prove that a Supabase or Cloudflare message is genuinely from them) work exactly that way — there's no way to have the old and new passcode both valid for a few minutes while you switch over. Adding that overlap window means rotating these secrets stops requiring a nerve-wracking flip-the-switch-and-hope moment.
    - **Evidence:**
        ```php
        'supabase' => [
            'email_hook_secret' => env('SUPABASE_EMAIL_HOOK_SECRET')
            'auth_hook_secret' => env('SUPABASE_AUTH_HOOK_SECRET')
        ```
        ```php
        'cloudflare' => [
            'api_token' => env('CLOUDFLARE_API_TOKEN')
            'cache_purge_token' => env('CLOUDFLARE_CACHE_PURGE_TOKEN')
        ```

## P3 — Nice to have

## Suggested Bundled Sessions

    - **Why grouped:** Same root cause — a `*_ENABLED` flag in `config/partna.php` deliberately defaulting `true` with a documented, verified safety net elsewhere. Same file, same decision to make (fix vs. leave documented-as-is).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet (trivial change if approved).

    - **Why grouped:** Same pattern (a hardcoded value that should route through `config()`), different files, no cross-dependency between them — safe to implement and review together.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#CFG-1 — Auth-adjacent secret rotation runbook** · touches Supabase Auth Hook / Cloudflare API-token infrastructure (auth-adjacent); needs its own plan + sign-off before implementation, especially if `VerifySupabaseHookSignature` needs a code change to support multi-secret verification during rotation.


<!-- ═══════════ audit-2026-07-11-migration-safety.md ═══════════ -->

# Migration Safety Audit — 2026-07-12

**Branch:** HEAD
**Lens:** Migration safety: lock-on-deploy risk, backfill ordering, online DDL hygiene, reversibility
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `supabase/migrations/20260704160000_shop_brands_products.sql`
- `supabase/migrations/20260704170000_drop_menu_platform_checks.sql`
- `supabase/migrations/20260704180000_drop_users_about.sql`
- `supabase/migrations/20260704150000_prepilot_p0_schema_expand.sql`
- `supabase/migrations/20260705000000_migrate_retired_font_slugs.sql`
- `supabase/migrations/20260705120000_drop_dead_profile_features.sql`
- `supabase/migrations/20260706000000_add_city_to_site_visits.sql`
- `supabase/migrations/20260707020000_site_visits_lat_lon.sql`
- `supabase/migrations/20260707030000_rename_skeleton_ids.sql`
- `supabase/migrations/20260707120000_rename_skeleton_ids_bento_class.sql`
- `supabase/migrations/20260708000000_add_site_media_palette.sql`
- `supabase/migrations/20260708120000_sites_shop_global_settings.sql`
- `supabase/migrations/20260708124853_staff_audit_log_ip_hash_and_get_reads.sql`
- `supabase/migrations/20260709064322_migrate_retired_font_slugs_one.sql`
- `supabase/migrations/20260710120000_add_section_views_duration_ms.sql`
- `supabase/migrations/20260710160000_design_kit_theme_surface_rework.sql`
- `supabase/migrations/20260710170000_skeleton_id_one_only.sql`
- `supabase/migrations/20260710190000_semantic_text_scale_and_vocab_remap.sql`
- `supabase/migrations/20260710210000_surfaces_backend.sql`
- `supabase/migrations/20260710230000_rename_skeleton_id_to_architecture_id.sql`
- `supabase/migrations/20260711000000_staff_account_type.sql`
- `supabase/migrations/20260711000400_notifications_critical_flag.sql`
- `supabase/migrations/20260711153000_feedback_type_area_target.sql`
- `supabase/migrations/20260711160100_add_analytics_purge_indexes.sql`
- `supabase/migrations/20260711160200_site_sessions_add_composite_unique.sql`
- `supabase/migrations/20260711160300_site_sessions_promote_composite_pk.sql`
- `docs/migration-guidelines.md`
- `scripts/guard-no-unsafe-migrations.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 2 of 2 complete
- P3 Low: 0 of 0 complete

---

## P0 — Must fix before any real user touches the system

## P1 — Fix before pilot launch

## P2 — Should fix

- [x] **#MIG-3** · P2 — Table creation, JSONB CTE backfill, and a live-table `UPDATE` coalesced into one transaction with no lock/statement timeout
    - **Where:** `supabase/migrations/20260704160000_shop_brands_products.sql:7-105`
    - **Affects:** `site.platform_connections` writes (shop connect/disconnect) during this migration's apply window.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `SET LOCAL lock_timeout = '2s'; SET LOCAL statement_timeout = '10s';` at the top of the transaction.
        - No structural split is required — the closing `UPDATE` already carries a correct idempotency guard (`(payload->>'storage') IS DISTINCT FROM 'relational'`), matching the canonical backfill pattern.
    - **Technical:** Category 6 + 9. The transaction creates `site.shop_brands`/`site.shop_products`, backfills them via a `jsonb_each`/`jsonb_array_elements` CTE read against `site.platform_connections` filtered to `platform = 'shop'`, then closes with `UPDATE site.platform_connections SET payload = '{"storage":"relational"}'::jsonb WHERE platform = 'shop' AND ...`. The `UPDATE` takes `ROW EXCLUSIVE` on `site.platform_connections`, held until COMMIT — lengthened by the preceding CTE work and index builds in the same transaction. This is bounded (only `platform = 'shop'` rows, a narrow subset) and already idempotent, so it's hardening rather than a demonstrated hot-table lockup — `site.platform_connections` isn't on the platform's explicit hot-table list, and shop connect/disconnect is infrequent relative to sitepage reads.
    - **Plain English:** This migration opens a new filing cabinet, moves specific papers into it, and puts a sticky note on the old folder saying "moved" — all in one locked session. It only affects shop-connected profiles, and it's already careful not to redo work if run twice, but there's no timer on the lock, so if something else is mid-write to that table when this runs, it could wait indefinitely instead of failing fast.
    - **Evidence:**
        ```sql
        UPDATE site.platform_connections
        SET payload = '{"storage":"relational"}'::jsonb
        WHERE platform = 'shop'
          AND deleted_at IS NULL
          AND (payload->>'storage') IS DISTINCT FROM 'relational';
        ```

- [x] **#MIG-4** · P2 — No CI check enforces `SET LOCAL lock_timeout`/`statement_timeout` on DDL against live-traffic tables
    - **Where:** `scripts/guard-no-unsafe-migrations.php` (existing guard, no timeout check); representative gap example at `supabase/migrations/20260711000000_staff_account_type.sql:13-18`
    - **Affects:** Any future migration touching `site.design_kits`, `site.sites`, `site.blocks`, or `core.users` — a stuck lock-wait on deploy queues instead of failing fast with a clear error.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a 5th check to `scripts/guard-no-unsafe-migrations.php` (alongside the existing 4 lock-pattern checks) that requires `SET LOCAL lock_timeout` when a migration's `ALTER TABLE`/`UPDATE` targets `site.design_kits`, `site.sites`, or `site.blocks` — the three tables `docs/migration-guidelines.md` §Lock and statement timeouts already names. Extending it to `core.users`, `analytics.*`, and `notifications.*` is a reasonable follow-on but is a doc-scope decision, not a bug fix.
        - Backfill `SET LOCAL lock_timeout = '2s'; SET LOCAL statement_timeout = '10s';` into the migrations the new check would flag.
    - **Technical:** Category 9. `docs/migration-guidelines.md` documents the `SET LOCAL lock_timeout`/`statement_timeout` pattern, but `scripts/guard-no-unsafe-migrations.php` ("Master Pattern 20") only enforces `CREATE INDEX ... CONCURRENTLY`, FK/CHECK `NOT VALID`, and the four-step `SET NOT NULL` pattern — it has no check for the timeout directive, so the convention is unenforced and inconsistently applied (only 3 files in the whole `supabase/migrations/` tree use it: `20260703000000_add_platform_connection_conditional_validators.sql`, `20260701220000_promote_gb_apify_status_placeid.sql`, `20260624010000_schema_hardening_constraints.sql`). Note the original scan's file list overstated this: several of the files it cited are provably low-risk on inspection — `20260705000000_migrate_retired_font_slugs.sql` and `20260709064322_migrate_retired_font_slugs_one.sql` explicitly reason "both tables are tiny, no lock-contention concern"; `20260708120000_sites_shop_global_settings.sql` and `20260711000400_notifications_critical_flag.sql` use constant (immutable) `DEFAULT` values, which Postgres 11+ applies metadata-only with no table rewrite; `20260707030000_rename_skeleton_ids.sql`, `20260707120000_rename_skeleton_ids_bento_class.sql`, and `20260711000000_staff_account_type.sql` already correctly split `VALIDATE CONSTRAINT` out under the lighter lock. `20260711153000_feedback_type_area_target.sql` was dropped from this finding entirely — `core.feedback` isn't a hot table, and the file's own header explains it's a "low-traffic internal tool" exempted by the guard's own same-file-column exemption. The real, actionable gap is the missing CI enforcement, not that every listed file is independently dangerous.
    - **Plain English:** A stuck lock-wait during deploy is like a worker standing at a locked filing cabinet forever instead of giving up after a couple of seconds. Most of these migrations are small, careful jobs that are unlikely to hit this problem — but there's currently no automatic check making sure every future migration sets that "give up and retry" timer on the tables people are actively using. Adding one automatic check now means nobody has to remember it by hand later.
    - **Evidence:**
        ```sql
        ALTER TABLE core.users DROP CONSTRAINT IF EXISTS users_account_type_check;

        ALTER TABLE core.users
            ADD CONSTRAINT users_account_type_check CHECK (account_type IN ('partna', 'business', 'staff')) NOT VALID;

        ALTER TABLE core.users VALIDATE CONSTRAINT users_account_type_check;
        ```

## P3 — Nice to have

## Suggested Bundled Sessions

None.

## Standalone — do NOT bundle

- **#MIG-3 — CTE backfill + UPDATE coalesced in one transaction:** edits a `supabase/migrations/` DDL/DML file.
- **#MIG-4 — Missing CI enforcement for lock/statement timeouts:** touches both a CI guard script (`scripts/guard-no-unsafe-migrations.php`) and multiple `supabase/migrations/` files.


<!-- ═══════════ audit-2026-07-11-api-contract.md ═══════════ -->

# API Contract & Resource Leakage Audit — 2026-07-12

**Branch:** HEAD
**Lens:** API Contract & Resource Leakage: raw model fields bleeding through, over-fetching, inconsistent pagination
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `app/Http/Controllers/Api/User/Content/ContentController.php`
- `app/Http/Controllers/Api/User/Uploads/UserDesignMediaController.php`
- `app/Http/Controllers/Api/User/Account/UserSelfController.php`
- `app/Http/Controllers/Api/User/Analytics/DevInsightsController.php`
- `app/Http/Controllers/Api/User/Analytics/UserAnalyticsController.php`
- `app/Http/Controllers/Api/PublicSite/PublicMenuController.php`
- `app/Http/Controllers/Api/PublicSite/SiteVisibilityController.php`
- `app/Http/Controllers/Api/Staff/Segments/StaffSegmentController.php`
- `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php`
- `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffIntegrationManagementController.php`
- `app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationController.php`
- `app/Http/Controllers/Api/Platforms/ShopController.php`
- `app/Http/Resources/SiteResource.php`
- `app/Http/Resources/UserDashboardResource.php`
- `app/Http/Resources/UserPublicResource.php`
- `app/Http/Resources/UserStaffResource.php`
- `app/Http/Resources/Staff/StaffUserListResource.php`
- `app/Http/Resources/Content/ContentLibraryUploadResource.php`
- `app/Http/Resources/DesignMediaResource.php`
- `app/Http/Resources/NotificationListingResource.php`
- `app/Http/Resources/PublicSite/IndividualProfileResource.php`
- `app/Http/Controllers/Concerns/ReturnsPaginatedResponse.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 0 complete

---

## P3 — Nice to have

## Suggested Bundled Sessions

    - **Why grouped:** identical root cause (an internal identity/FK field exposed unconditionally on a self-scoped Resource with no consumer use case) — one small PR touching two Resource files.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

    - **Why grouped:** single-file docblock addition, no code-behavior change.
    - **Model:** Plan+Implement combinable (S effort) · Review: Sonnet.

    - **Why grouped:** same root-cause pattern (controller hand-builds response arrays instead of routing through a Resource class) across Staff and User surfaces; none touch auth/money/schema, all mechanical extractions with existing sibling Resources to model from.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet). is the largest (M effort, two extractions) — implement it last in the bundle so the reviewer can check it in isolation.

    - **Why grouped:** both touch `ContentLibraryUploadResource`'s contract area (one is a field addition, the other is a related consumption-pattern fix on the sibling public menu endpoint) — bundled for reviewer context locality, not a shared root cause with Bundle 3.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

## Standalone — do NOT bundle



<!-- ═══════════ audit-2026-07-11-test-coverage.md ═══════════ -->

# Test Coverage Audit — 2026-07-13

**Branch:** HEAD
**Lens:** Test coverage: critical paths, idempotency, race-safety, policy abilities, mock-vs-integration discipline
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `tests/Pest.php`, `tests/Feature/**`, `tests/Unit/**`, `tests/Integration/**`, `tests/Helpers/**`
- `app/Policies/**`
- Cross-referenced production files: `app/Http/Controllers/Api/PublicSite/IndividualProfileController.php`, `app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php`, `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php`, `app/Jobs/Analytics/RecordAnalyticsEventJob.php`, `app/Jobs/ProcessImageVariantsJob.php`, `app/Jobs/ProcessVideoVariantsJob.php`, `app/Jobs/DeleteMediaArtifactsJob.php`, `app/Jobs/Platforms/GoogleBusinessEnrichJob.php`, `app/Services/Cache/CacheLockService.php`

## Note on this adjudication

The DeepSeek draft (8 chunks, ~80 raw findings) systematically **hallucinated an untested codebase** — nearly every "zero coverage" claim (public sitepage resolution, handle-alias 301s, `SyncSubdomainToKvJob`, `RecordAnalyticsEventJob` dedup, moderation state transitions, media processing jobs, `CacheLockService` concurrency, webhook signature/re-delivery, `AccountCapabilities` gating, staff authorization, migration CHECK-constraint invariants, factory determinism) was directly contradicted by extensive existing test files found via `Read`/`Grep`/`Glob`. Those findings are dropped as hallucinated per the adjudication mandate. Only 7 findings survived verification against actual repo state.

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 2 of 2 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [x] **TEST-2** · P2 — Two observer tests mock the Eloquent `Site` model instead of using a real factory row
    - **Where:** `tests/Feature/User/UserObserverHandleChangeTest.php:121,199`, `tests/Feature/Core/ServiceObserverTouchSiteTest.php:26,63`
    - **Affects:** Confidence that `UserObserver`/`ServiceObserver` actually bump `site.sites.updated_at` — a real cache-busting dependency for `SiteCacheService`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Mockery::mock(Site::class)` with a real `Site::factory()->create()` (or `setRelation('site', $realSite)`) seeded via `setupSitesTable()`.
        - Assert `$site->fresh()->updated_at` actually changed, instead of asserting a `shouldReceive('touch')->once()` call.
    - **Technical:** Confirmed via direct `Read` (not the earlier failed regex grep, which false-negatived on the unescaped parens) — both files construct `Mockery::mock(Site::class)` and assert `shouldReceive('touch')`. Per Partna conventions the DB layer must be real, factory-seeded SQLite — mocking an Eloquent model means the test would still pass even if `touch()` were removed from the observer's calling code (Larastan won't catch a removed *call*, only a removed *symbol*).
    - **Plain English:** Instead of actually updating a real database row and checking the timestamp moved, these two tests use a stand-in object that just nods along when asked "did you get touched?" If the real update logic breaks, the stand-in still says yes.
    - **Evidence:**
        ```php
        // tests/Feature/User/UserObserverHandleChangeTest.php:121-122
        $site = Mockery::mock(Site::class);
        $site->shouldReceive('touch')->once();

        // tests/Feature/Core/ServiceObserverTouchSiteTest.php:26-27
        $site = Mockery::mock(Site::class);
        $site->shouldReceive('touch')->once();
        ```

- [x] **TEST-3** · P2 — No full key-set snapshot test for `IndividualProfileResource` or `UserStaffResource`
    - **Where:** `app/Http/Resources/IndividualProfileResource.php`, `app/Http/Resources/UserStaffResource.php` — only `tests/Feature/Resources/UserPublicResourceTest.php` exists; `tests/Feature/Staff/StaffAdminNotesTest.php` spot-checks a single field (`admin_notes`), not the full shape
    - **Affects:** PII exposure on the public sitepage / audience confusion between the public and staff API surfaces — the highest-risk resource split in the platform.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Resources/IndividualProfileResourceTest.php` snapshotting the exact key set for a standard professional (mirroring `UserPublicResourceTest.php`'s pattern: assert present keys and explicitly assert absence of `primary_email`, `admin_notes`, internal ids).
        - Add `tests/Feature/Resources/UserStaffResourceTest.php` snapshotting the full staff-only key set.
    - **Technical:** Grepped the full `tests/` tree for all three resource class names — only `UserPublicResourceTest.php` does a genuine key-set assertion (`toHaveKey`/`not->toHaveKey` on 6 fields). `StaffAdminNotesTest.php` checks one field crosses the staff/self split correctly but doesn't freeze the resource's full shape, so a new field added to either resource without a `hidden`/whitelist update would ship undetected.
    - **Plain English:** The public page a visitor sees and the internal view staff use are supposed to show different information — staff can see private notes, visitors can't. We only have a full contract test for one of the three "who sees what" templates. A future change could add a new private field to the public template and nothing would catch it.
    - **Evidence:**
        ```php
        // tests/Feature/Resources/UserPublicResourceTest.php:7-33 — the ONLY full snapshot-style test
        it('returns display_name and partna_url, no PII', function () {
            $array = (new UserPublicResource($pro))->toArray(Request::create('/'));
            expect($array)
                ->toHaveKey('display_name', 'Evo')
                ->not->toHaveKey('primary_email');
        });
        // No equivalent file for IndividualProfileResource or UserStaffResource.
        ```

## P3 — Nice to have

## Suggested Bundled Sessions

    - **Why grouped:** Standalone new-test-file work covering the four untested policy classes; no production code changes, low risk.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** Same root-cause pattern — tests bypassing the standard factory-seeded-SQLite fixture convention — across different files.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** All independent, small (S/M effort), test-only additions with no cross-file dependencies; efficient to knock out in one session.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

None — no P0, auth/money/migration-touching, or L/XL-effort findings survived adjudication.


<!-- ═══════════ audit-2026-07-11-code-quality-slop.md ═══════════ -->

# AI Slop & Low-Value Code Audit — 2026-07-12

**Branch:** HEAD
**Lens:** AI Slop & Low-Value Code — comment noise, premature abstraction, dead code, defensive cruft, copy-paste drift
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Services/User, app/Services/Media, app/Services/Platforms, app/Services/Feedback, app/Services/Diagnostics
- app/Mail, app/Http/Controllers/Api/User, app/Http/Resources, app/Jobs, app/Console, app/Notifications, app/Observers

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

## P3 — Nice to have

## Suggested Bundled Sessions

    - **Why grouped:** Same root cause (decorative ASCII-art section dividers violating CLAUDE.md's "avoid decorative banners" rule) across `app/Services/Platforms` and `app/Http/Controllers/Api/User` — purely mechanical deletes, no logic touched.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet (combine plan+impl given S effort).

    - **Why grouped:** Same "cleanup pass" theme — a dead-code removal, a dead-variable removal, and a copy-paste-drift consolidation, each isolated to one file with no cross-file coupling.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet. Escalate implement → Opus for only if the reviewer wants extra scrutiny that no other caller relies on `hasStoreKey`/`count` (grep already confirms zero call sites, so this is likely unnecessary).

## Standalone — do NOT bundle

None.


<!-- ═══════════ audit-2026-07-11-semantic-correctness.md ═══════════ -->

# Semantic Correctness Audit — 2026-07-12

**Branch:** HEAD
**Lens:** Semantic Correctness — code that compiles and type-checks but does the wrong thing (plausible-but-wrong API/config/logic usage)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Services/User
- app/Services/Site
- app/Services/PublicSite
- app/Services/Cache
- app/Services/Accounts
- app/Services/Auth
- app/Services/FeatureFlags
- app/Services/FeatureAvailability
- app/Services/Segments
- app/Services/EarlyAccess
- app/Support
- app/Contracts
- app/helpers.php
- app/Jobs
- app/Http/Controllers/Api/User
- app/Policies
- app/DTOs

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

## P3 — Nice to have

## Suggested Bundled Sessions

None — the two surviving findings touch unrelated subsystems (content-selection transactions vs. a dev-only analytics const) and neither shares a file, subsystem, or root cause with the other.

## Standalone — do NOT bundle



<!-- ═══════════ audit-2026-07-17-security.md ═══════════ -->

# Security Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Security: auth boundaries, tenant isolation, mass assignment, inbound callbacks, secrets, injection, SSRF, upload safety, PII exposure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- `app/Policies/EarlyAccessSignupPolicy.php`, `FeatureAvailabilityPolicy.php`, `FeedbackPolicy.php`, `UserSegmentPolicy.php`, `UserSelfPolicy.php`, `IntegrationConnectionPolicy.php`, `SitePolicy.php`
- `app/Providers/AppServiceProvider.php`, `app/Providers/PlatformRegistryServiceProvider.php`
- `app/Http/Controllers/Api/User/Account/UserAccountDeletionController.php`, `UserSelfController.php`
- `app/Http/Controllers/Api/User/Profile/SectorController.php`
- `app/Http/Controllers/Api/User/SiteManagement/CustomDomainController.php`
- `app/Http/Requests/Api/Staff/Segments/StoreSegmentRequest.php`, `Api/Staff/UserSite/StaffUpdateSiteRequest.php`, `Api/User/Site/UpdateSiteRequest.php`, `Api/User/Site/UpsertWorkplaceRequest.php`, `Api/User/UpdateUserRequest.php`, `Concerns/DesignKitValidationRules.php`, `Concerns/SiteOrderingValidationRules.php`, `Platforms/ApplyMenuScanRequest.php`
- `app/Http/Controllers/Api/Platforms/{Booking,DisplaySettings,Fresha,GoogleBusiness,Instagram,Menu,OnlineOrdering,Reservations,Square}Controller.php`
- `app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php`, `PublicMenuController.php`
- `app/Http/Controllers/Api/Staff/Analytics/StaffAggregateAnalyticsController.php`, `Api/Staff/Feedback/StaffFeedbackController.php`, `Api/Staff/UserSiteManagement/StaffUserController.php`
- `app/Http/Resources/PublicSite/IndividualProfileResource.php`, `UserDashboardResource.php`, `Staff/StaffUserListResource.php`, `UserStaffResource.php`
- `app/Services/Design/**` (DesignRationaleService, Presets/*, Scan/EvidenceConclusions, ThemeModePalettes)
- `app/Services/Profile/SectorTaxonomy.php`
- `app/Services/Platforms/{BigCartelScraper,DoorDashMenuDriver,GenericShopScraper,GoogleBusinessAutoSync,IdentitySync,InstagramAutoSync,InstagramScraper,MenuMerger,MenuScanApplier,ShopifyScraper,UberEatsMenuDriver,WebsiteLinkHarvester,WooCommerceScraper}.php`, `Normalizers/FacebookNormalizer.php`, `Payloads/InstagramPayload.php`, `PlatformScraper.php`, `Registry/PlatformDescriptor.php`
- `app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php` (read for cross-file verification)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 7 of 7 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

## P2 — Should fix

- [x] **#SEC-102** · P2 — `InstagramScraper` logs Instagram usernames alongside internal `user_id` in warning/info breadcrumbs
    - **Where:** app/Services/Platforms/InstagramScraper.php:45, 57-61, 68-73, 210-216
    - **Affects:** Nightwatch/log-aggregator storage builds a persistent, plaintext join between a public Instagram handle and an internal Partna user UUID.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `'username' => $username` with `'username_hash' => hash('sha256', $username)` in all four `Log::warning`/`Log::info` calls in this file.
        - Keep `user_id` as-is (internal UUID, not third-party PII).
        - Consider extending `PiiLogHygieneSweepTest` to assert scraper log payloads never carry a raw third-party handle.
    - **Technical:** `fetchProfile()` and `latestMedia()` emit `Log::warning`/`Log::info` with both the raw Instagram `username` and internal `user_id` in the same payload. Instagram usernames are public on Instagram, but pairing one with a Partna account UUID inside long-retained log storage creates a durable, joinable identity record that doesn't exist anywhere else in the product. `PiiLogHygieneSweepTest` is the house pattern for this category and currently has no assertion covering this file (`grep` for `username`/`instagram` in that test returns no matches) — this is a genuine, uncovered gap, not a duplicate of an existing check.
    - **Plain English:** When the Instagram-scraping code hits an error, it writes both the person's Instagram handle and their internal Partna account ID into the server logs together. Instagram handles are public, so this isn't leaking a secret — but it quietly builds a permanent record tying "this Instagram account" to "this Partna account" inside a system built for debugging, not for storing identity links. Hashing the handle before logging keeps the logs just as useful for spotting patterns without keeping the plaintext link around.
    - **Evidence:**
        ```php
        Log::warning('instagram.apify.threw', ['username' => $username, 'user_id' => $userId, 'error' => $e->getMessage()]);
        ```
        ```php
        Log::warning('instagram.apify.not_ok', [
            'username' => $username
            'user_id' => $userId
            'status' => $response->status()
        ]);
        ```
        ```php
        Log::info('instagram.latest_media', [
            'user_id' => $userId
            'posts' => count($posts)
        ```

- [x] **#SEC-103** · P2 — `GoogleBusinessAutoSync::seed()` / `InstagramAutoSync::seed()` accept a bare `$userId` string with no internal tenant guard
    - **Where:** app/Services/Platforms/GoogleBusinessAutoSync.php:57 (`seed()`), app/Services/Platforms/InstagramAutoSync.php:63 (`seed()`)
    - **Affects:** Currently safe — both call sites (`GoogleBusinessEnrichJob.php:137`, `InstagramConnectJob.php:250`) derive `$userId` from the queued job's own stored state, never from request input. No confirmed exploit path exists today.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - No signature change needed (a `User`-typed parameter would ripple through ~15 test call sites for no live benefit). Instead, add a one-line docblock/assert on `seed()` stating it must only be called from trusted server-derived `$userId` values (job payloads), never from request input, so a future controller wiring doesn't silently inherit the gap.
        - If a controller ever needs to call `seed()` directly, require it to resolve `$userId` via `$this->currentUser($request)->id` and add an explicit `authorizeForUser` check at that call site — not inside the service.
    - **Technical:** `applyFinding()` on both classes (the only other public entry point that mutates `IntegrationConnection` rows by `$userId`) is exclusively reached from `InstagramController::applySync()` and `GoogleBusinessController::applySync()`, both of which pass `(string) $this->currentUser($request)->id` — JWT-derived, not client-supplied — and look up the finding to apply from server-stored `syncFindings` on the connection's own payload (the client only supplies a `platform` string, validated against `PlatformInRegistry`, never the finding object itself). `seed()` is dispatched only from `GoogleBusinessEnrichJob`/`InstagramConnectJob`, whose `$userId` comes from the job's own constructor arguments set at dispatch time from a trusted context. There is no reachable path today where an attacker controls `$userId` into either service. This is a defense-in-depth note, not a live vulnerability — the same category as a cache key that would only collide on an input nothing currently produces.
    - **Plain English:** These two "seed the connection" functions trust whatever user ID they're handed, without double-checking it themselves — they rely entirely on their callers being trustworthy. Today, both callers ARE trustworthy (background jobs reading from their own database records, never from a web request). This is a "wear a seatbelt even though you haven't crashed yet" fix: cheap insurance against a future change accidentally wiring one of these functions up to a request instead of a job.
    - **Evidence:**
        ```php
        public function seed(string $userId, array $enrichment, ?string $businessName, ?array $gbPayload = null): array
        {
            $findings = [];
            $user = User::find($userId);
        ```
        ```php
        public function seed(string $userId, array $bioLinks): array
        {
            if ($bioLinks === []) {
                return ['findings' => [], 'unmatched' => []];
            }
            $user = User::find($userId);
        ```

- [x] **#SEC-104** · P2 — `StaffUserController::index()` queries and returns every professional with no `authorizeForUser` call
      **REJECTED — NOT IMPLEMENTED (2026-07-20).** This finding prescribes exactly the change a prior
      signed-off finding deliberately rejected. Commit `6311a1a1` ("SEC-101 — gate staff-detail PII to
      admin tier") added the `staffView` seam to `UserSelfPolicy` (line 116) and intentionally did NOT
      wire it into `index()`. The rationale is still in the docblock at `StaffUserController.php:34-38`:
      it authorizes against a single User target while this is a paginated collection, so per-row
      authorize calls would be N gate evaluations for a currently-unconditional ability; the PII gate
      (`$showPii`) is the enforcement point for this endpoint, same as `show()`. Implementing SEC-104
      would silently undo a deliberate decision. If the `staffView` ability should become conditional,
      that is a separate design conversation, not a P2 gate insertion. `StaffUserController.php` left
      untouched.
    - **Where:** app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php:35-89
    - **Affects:** Structural doctrine gap only — the list endpoint already gates raw PII behind `isAdmin()` inline (see ); this finding is the missing formal Policy seam underneath that inline check.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add the new `staffView` ability proposed in  to `UserSelfPolicy`, and call `$this->authorizeForUser($staff, 'staffView', User::class);` at the top of `index()`.
        - Fix alongside  in the same change — they share the new Policy method.
    - **Technical:** Every mutating method on this controller (`updateStatus`, `update`, `destroy`, `restore`, `forceDestroy`, `bulkUpdateStatus`) calls `authorizeForUser($staff, ...)` against `UserSelfPolicy`; `index()` is the one read path with zero Policy involvement — it relies solely on the inline `$staff->isAdmin()` check for PII redaction, with no structural seam for `PolicyCoverageTest`-style sweeps to catch a future role model change (e.g., a "read-only" staff tier that shouldn't list professionals at all). Lower severity than  because the actual sensitive fields are already redacted for non-admins today.
    - **Plain English:** The list of all professionals doesn't run through the same formal security checkpoint the edit/delete actions do — it only has one hand-written "is this an admin?" check baked into the code for hiding emails and phone numbers. That inline check happens to work today, but it's not connected to the platform's standard permission system, so it's easy to accidentally break in a future change without anyone noticing.
    - **Evidence:**
        ```php
        public function index(Request $request): JsonResponse
        {
            $status = $request->query('status'); // optional: active|suspended
            $perPage = $this->normalizePerPage(/*...*/);
            $searchLike = $this->prepareSearchLike($request, 'q');

            $query = User::query()
                ->with(['site'])
                ->orderByDesc('created_at');
        ```

- [x] **#SEC-105** · P2 — `SectorController::update()` mutates the User model without an `authorizeForUser` gate
    - **Where:** app/Http/Controllers/Api/User/Profile/SectorController.php:22-38
    - **Affects:** Authenticated user updating their own sector — tenant-safe today (actor resolved via `currentUser`), but bypasses `UserSelfPolicy`'s pending-deletion block and (if `partna.mfa.require_fresh_aal2_for_profile_update` is ever enabled) its fresh-AAL2 requirement.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `$this->authorizeForUser($user, 'update', $user);` immediately after resolving `$user`, matching `UserSelfController::update()`'s established pattern for the same ability on the same model.
    - **Technical:** `UserSelfPolicy::update()` (registered for `User::class` in `AppServiceProvider::boot()`) blocks pending-deletion actors and conditionally requires fresh AAL2. `SectorController::update()` resolves the actor via `ResolveCurrentUser` correctly but assigns `sector`/`sector_source` and calls `$user->save()` with no Policy check at all — the one deviation from the pattern `UserSelfController::update()` establishes for the identical `'update'` ability on the identical model.
    - **Plain English:** Every other place a user edits their own profile checks "is this account allowed to make changes right now?" before saving — this one skips that check. It doesn't let anyone touch someone else's account, but if the account is mid-deletion or (in the future) needs a fresh login-verification step before sensitive changes, this endpoint wouldn't enforce either rule.
    - **Evidence:**
        ```php
        public function update(UpdateSectorRequest $request): JsonResponse
        {
            $user = $this->currentUser($request);
            $sector = $request->validated()['sector']; // null or a valid slug

            // sector_source is not fillable (service-written) — assign directly.
            $changed = $user->sector !== $sector;
            $user->sector = $sector;
            $user->sector_source = $sector === null ? null : 'manual';
            $user->save();
        ```

- [x] **#SEC-106** · P2 — `MenuController::refresh()` and `applyScan()` mutate the Menu model without an `authorizeForUser` gate
    - **Where:** app/Http/Controllers/Api/Platforms/MenuController.php:93-115 (`refresh()`), :121-133 (`applyScan()`); write path app/Services/Platforms/MenuScanApplier.php:166-182 (`resolveMenu()`)
    - **Affects:** Authenticated user re-triggering a menu scrape or applying an AI-scanned menu — tenant-safe today via inline `where('user_id', $user->id)`, but bypasses `SitePolicy`'s pending-deletion block on the `Menu` model.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `AppServiceProvider::boot()` already registers `Gate::policy(Menu::class, SitePolicy::class);` — load the user's `Menu` (or a skeleton when absent) in both `refresh()` and `applyScan()` and call `$this->authorizeForUser($user, 'update', $menu)` before the mutation, mirroring `ManagesIntegrationConnection::writeConnection()`'s create-vs-update resolution pattern used elsewhere in this same directory.
    - **Technical:** `refresh()` runs `Menu::query()->where('user_id', $user->id)->update(['fetch_status' => 'pending'])` and `applyScan()` delegates to `MenuScanApplier::apply()`, whose `resolveMenu()` does `Menu::query()->where('user_id', $user->id)->first()` (or creates one) — neither path ever resolves through `SitePolicy`, even though `Menu::class` is a registered, policed model. The inline `user_id` scope is currently the only protection; it's correct today but not the doctrine-mandated Policy gate, and it silently skips the pending-deletion block `SitePolicy::update()` would otherwise enforce.
    - **Plain English:** Clicking "refresh menu" or applying a scanned menu photo updates the database directly, filtered only by "does this row belong to me" written inline in the query — not through the platform's standard permission check. It only affects your own menu today because of that inline filter, but the formal security check that would also stop someone mid-account-deletion from triggering this is being skipped.
    - **Evidence:**
        ```php
        // MenuController::refresh()
        // Flip to pending immediately for instant UI feedback; the job also sets it.
        Menu::query()->where('user_id', $user->id)->update(['fetch_status' => 'pending']);

        MenuFetchJob::dispatch((string) $user->id, true);
        ```
        ```php
        // MenuScanApplier::resolveMenu()
        private function resolveMenu(User $user): Menu
        {
            $menu = Menu::query()->where('user_id', $user->id)->first();
            if ($menu !== null) {
                $menu->forceFill(['last_fetched_at' => now()])->save();

                return $menu;
            }
        ```

- [x] **#SEC-107** · P2 — `DisplaySettingsController::update()` mutates `IntegrationConnection` and `Site` rows without an `authorizeForUser` gate
    - **Where:** app/Http/Controllers/Api/Platforms/DisplaySettingsController.php:64-143
    - **Affects:** Authenticated user toggling public-display switches for their own platform connections — tenant-safe today via inline `where('user_id', $user->id)`, but bypasses both `IntegrationConnectionPolicy` and `SitePolicy`'s pending-deletion blocks.
    - **Evidence:**
        ```php
        $connections = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('platform', $platform)
            ->where('is_active', true)
            ->get();
        // ...
        $connection->display_settings = $current === [] ? null : $current;
        $connection->save(); // observer → cache purge + payload rebuild
        ```
        ```php
        if ($site !== null && $site->isDirty()) {
            $site->save();
        }
        ```
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Both `IntegrationConnection::class` and `Site::class` are registered (`IntegrationConnectionPolicy`, `SitePolicy`) in `AppServiceProvider::boot()`. Before the `foreach` that saves each connection, call `$this->authorizeForUser($user, 'update', $connection)` per row; before `$site->save()`, call `$this->authorizeForUser($user, 'update', $site)`.
        - This controller doesn't use the `ManagesIntegrationConnection` trait (unlike every other platform controller) because it operates on multiple connections/a raw query rather than one resource per `platform()` — that's why it's the one controller in this family that fell outside the trait's built-in authorization (confirmed by reading `ManagesIntegrationConnection`: `writeConnection`/`forgetConnection`/`connectionFor` all call `authorizeForUser` internally, which is why the sibling `Booking`/`Fresha`/`Square`/`Reservations`/`GoogleBusiness`/`OnlineOrdering` controllers do NOT need a separate finding here).
    - **Technical:** `update()` fetches `IntegrationConnection` rows and (conditionally) a `Site` row scoped by `where('user_id', $user->id)`, then saves both directly — neither path routes through the registered Policy for either model. This is the one platform controller that bypasses `ManagesIntegrationConnection`'s built-in authorization (verified by reading the trait: every `writeConnection`/`writePendingLinkCard`/`forgetConnection`/`forgetAllConnections` call already resolves create-vs-update and calls `authorizeForUser` before touching the row), because it manages toggle state across multiple connections at once rather than one row via the trait's helpers.
    - **Plain English:** Flipping a "show this on my public page" switch updates the database directly, protected only by a filter baked into the query ("only rows that belong to me") rather than the platform's standard permission check. Every sibling integration controller in this same folder DOES go through that standard check via a shared helper — this is the one exception, likely missed because it works with several connections at once instead of the usual "one connection" pattern the helper was built for.

- [x] **#SEC-108** · P2 — `CustomDomainController` mutates the `Site` model on all four write paths without an `authorizeForUser` gate
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/CustomDomainController.php:38-104 (`store()`), :106-144 (`verify()`), :150-163 (`setPrimary()`), :165-190 (`destroy()`); shared resolver :192-198 (`siteOrFail()`)
    - **Affects:** Authenticated user configuring their own custom domain (Cloudflare for SaaS) — tenant-safe today (site resolved via the `$user->site` Eloquent relationship), but bypasses `SitePolicy`'s pending-deletion block on every domain-configuration write, including CNAME/hostname creation and destruction.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Mirror the exact precedent already established by `ManagesIntegrationConnection::connectionFor()`: add `$this->authorizeForUser($this->currentUser($request), 'view', $site)` inside `siteOrFail()` (covers `show()` too), then add `$this->authorizeForUser($this->currentUser($request), 'update', $site)` immediately before `$site->save()` in each of `store()`, `verify()`, `setPrimary()`, and `destroy()`.
        - Keep 'view' and 'update' as separate calls (don't fold 'update' into `siteOrFail()`) so `show()` stays a pure read and isn't incorrectly blocked by the pending-deletion guard on `update`.
    - **Technical:** `siteOrFail()` resolves the site via `$this->currentUser($request)->site` and 404s when absent — correct anti-enumeration behavior with no IDOR risk, since the relationship itself is tenant-scoped. But none of the four mutating methods call `authorizeForUser` before `$site->save()`, so `SitePolicy::update()`'s `denyIfPendingDeletion()` guard never runs — a professional in a pending-deletion grace period could still create/verify/promote/tear down a custom Cloudflare hostname through this controller today, which is inconsistent with every other site-mutation surface in the codebase (`UserSelfController::update()`, the platform connection controllers via `ManagesIntegrationConnection`) all gating on the same ability.
    - **Plain English:** Connecting or removing a custom domain updates the site record directly, relying only on "this profile's site is the one I loaded" rather than the platform's standard permission check. Because domain changes also create and delete real Cloudflare configuration, the missing check matters slightly more here: an account mid-deletion could still spin up or tear down live DNS/certificate configuration through this path, something the standard check would otherwise block.
    - **Evidence:**
        ```php
        private function siteOrFail(Request $request): Site
        {
            $site = $this->currentUser($request)->site;
            abort_unless($site !== null, 404, 'No site to configure.');

            return $site;
        }
        ```
        ```php
        // destroy() — representative of all four mutation methods:
        $site->custom_domain = null;
        $site->custom_domain_cf_id = null;
        $site->custom_domain_status = null;
        $site->custom_domain_verified_at = null;
        $site->custom_domain_primary = false;
        $site->save();
        ```

## Suggested Bundled Sessions

None.

## Standalone — do NOT bundle

Every finding in this audit is an authorization-boundary or PII-exposure fix — per policy, all run standalone with their own plan + sign-off, never bundled.

- **#SEC-102 — InstagramScraper log hygiene** · touches log payloads correlating user identity; run alone to keep the diff auditable against `PiiLogHygieneSweepTest`.
- **#SEC-103 — GoogleBusinessAutoSync/InstagramAutoSync seed() tenant-guard note** · auth/authorization (tenant-boundary defense-in-depth).
- **#SEC-104 — StaffUserController::index() missing Policy gate** · auth/authorization; shares the new `staffView` Policy method with  but is its own sign-off.
- **#SEC-105 — SectorController missing Policy gate** · auth/authorization.
- **#SEC-106 — MenuController missing Policy gate** · auth/authorization.
- **#SEC-107 — DisplaySettingsController missing Policy gate** · auth/authorization.
- **#SEC-108 — CustomDomainController missing Policy gate** · auth/authorization; also touches live Cloudflare DNS/certificate state.


<!-- ═══════════ audit-2026-07-17-lifecycle-correctness.md ═══════════ -->

# Lifecycle Correctness Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Lifecycle correctness — race-safety, idempotency, anchor decoupling, reconcile loops, vendor resilience, observability discipline (account/site lifecycle, Cloudflare KV subdomain sync, and platform-connector auto-sync scope groups)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Services/PublicSite/SitepageDataResolverService.php
- app/Services/User/AccountDeletionService.php
- app/Services/User/SiteProvisioningService.php
- app/Services/User/UserBootstrapService.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Services/Cloudflare/CloudflareKvService.php
- routes/console.php
- app/Console/Commands/BackfillSubdomainKvCommand.php
- app/Services/Platforms/IdentitySync.php
- app/Services/Platforms/GoogleBusinessAutoSync.php
- app/Services/Platforms/InstagramAutoSync.php
- app/Observers/Core/IntegrationConnectionObserver.php
- app/Jobs/Platforms/InstagramConnectJob.php
- app/Services/Platforms/InstagramScraper.php
- supabase/migrations/20260526000000_baseline_standalone_user.sql
- supabase/migrations/20260602150238_create_platform_connections.sql

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 9 of 9 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

## P2 — Should fix

- [x] **#LIFE-102** · P2 — `purgeWaitlistSignup` error log carries no user identifier
    - **Where:** app/Services/User/AccountDeletionService.php:717-733
    - **Affects:** GDPR-erasure audit trail during the daily `purge()` sweep — support can't correlate a failed waitlist-row erasure back to the account that triggered it.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Thread `$professional` (already in scope at the `purge()` call site) into `purgeWaitlistSignup` and add `'user_id' => $professional->id` to the `Log::error` context.
    - **Technical:** The `Log-with-context` canonical pattern requires `user_id` on every lifecycle log so Nightwatch correlation works. `purgeWaitlistSignup` only receives `?string $lookupEmail` — the `$professional` reference available at the call site in `purge()` is discarded before the method boundary, so the catch block has no way to identify which account's erasure step failed.
    - **Plain English:** This step deletes a leftover signup record during account deletion. If that delete fails, the error log right now just says "something broke" with no name attached — like a shredding service reporting a jam with no ticket number. Adding the user ID lets support find and manually clean up the right record.
    - **Evidence:**
        ```php
        private function purgeWaitlistSignup(?string $lookupEmail): void
        {
            if ($lookupEmail === null || trim($lookupEmail) === '') {
                return;
            }

            try {
                DB::connection('pgsql')
                    ->table('core.waitlist_signups')
                    ->where('email_lc', mb_strtolower(trim($lookupEmail)))
                    ->delete();
            } catch (\Throwable $e) {
                Log::error('Waitlist signup erasure failed during account purge', [
                    'error' => $e->getMessage()
                ]);
            }
        }
        ```

- [x] **#LIFE-103** · P2 — `purgeGlobalEmailSubscriptions` error log carries no user identifier
    - **Where:** app/Services/User/AccountDeletionService.php:846-863
    - **Affects:** Same GDPR-erasure audit trail as #LIFE-102 — global (platform-marketing) subscription rows keyed only by email have no user_id trail on failure.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Same fix as #LIFE-102: thread `$professional` into the method and add `'user_id' => $professional->id` to the log context.
    - **Technical:** Identical root cause to #LIFE-102 — same file, same `Log-with-context` gap, same missing parameter. Tiered identically per the "same root cause, same tier" rule.
    - **Plain English:** Same shredder-jam problem as #LIFE-102, but for the platform's global marketing mailing list. When this cleanup step fails, the report is anonymous.
    - **Evidence:**
        ```php
        private function purgeGlobalEmailSubscriptions(?string $lookupEmail): void
        {
            if ($lookupEmail === null || trim($lookupEmail) === '') {
                return;
            }

            try {
                DB::connection('pgsql')
                    ->table('notifications.email_subscriptions')
                    ->whereNull('user_id')
                    ->where('email_lc', mb_strtolower(trim($lookupEmail)))
                    ->delete();
            } catch (\Throwable $e) {
                Log::error('Global email subscription erasure failed during account purge', [
                    'error' => $e->getMessage()
                ]);
            }
        }
        ```

- [x] **#LIFE-104** · P2 — `safeQuery` presence-probe failures log with no correlation context or per-probe discriminator
    - **Where:** app/Services/PublicSite/SitepageDataResolverService.php:355-364
    - **Affects:** Public sitepage resolution — `presentPageIds()` calls `safeQuery` 8 times per resolve; every failure (transient DB blip, partial-env missing table) logs the identical string with no `user_id`/`site_id` and no indication which of the 8 probes failed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add an optional `string $probe` label parameter to `safeQuery` and pass a discriminator at each of the 8 call sites (`'integration_platforms'`, `'shop_product'`, `'gb_display_settings'`, `'menu'`, `'services'`, `'links'`, `'gallery'`, `'curated_gallery'`).
        - Thread `$site?->user_id` / `$site?->id` into the log context — every call site already has `$site` or `$userId` in scope.
    - **Technical:** The `Log-with-context` canonical pattern requires enough context for Nightwatch to group and attribute failures. All 8 call sites in `presentPageIds()` (plus `buildCuratedGalleryData`) funnel through this one private method and emit the identical `'sitepage.presence_probe_failed'` string with only `$e->getMessage()` — which varies per exception instance and defeats grouping entirely. A support report of "my Shop page disappeared" currently yields zero forensic signal distinguishing that from a gallery or menu probe failure.
    - **Plain English:** Eight different background checks feed into building a user's public page. Right now, if any one of them has a hiccup, the error log just says "a check failed" — no user, no site, no indication which of the eight. A user reporting a broken page gives support nothing to trace. Tagging each check with a name and the affected user turns a blur into a precise report.
    - **Evidence:**
        ```php
        private function safeQuery(\Closure $query, mixed $default): mixed
        {
            try {
                return $query();
            } catch (QueryException $e) {
                Log::warning('sitepage.presence_probe_failed', ['error' => $e->getMessage()]);

                return $default;
            }
        }
        ```

- [x] **#LIFE-105** · P2 — `GoogleBusinessAutoSync::seedBooking` XOR invariant (one active booking provider) is check-then-write, not atomic
    - **Where:** app/Services/Platforms/GoogleBusinessAutoSync.php:250-257
    - **Affects:** Business Partna accounts with Google Business connected — two near-simultaneous auto-sync sources (e.g. connecting Google Business and Instagram back-to-back during onboarding, or a scheduled Google Business refresh landing mid-connect) can both observe "no booking connection yet" and each write a different provider (Fresha vs Square vs custom Booking), leaving two live booking cards.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the "any booking platform exists" check + `$this->write(...)` in a short-lived `Cache::lock("booking-seed:{$userId}", 10)->block(5)` (or a Postgres advisory lock keyed on `$userId`) so the check-then-write is serialized per user. A row-level `lockForUpdate` alone doesn't close this gap — the invariant spans three *different* `platform` values (Fresha/Square/Booking), so there's no single row to lock against a concurrent INSERT.
        - Longer-term (separate migration, not bundled here): a Postgres partial unique index — `UNIQUE (user_id) WHERE platform IN ('fresha','square','booking') AND deleted_at IS NULL` on `site.platform_connections` — would enforce the XOR invariant at the DB layer regardless of application-level locking gaps.
    - **Technical:** `site.platform_connections` has `idx_platform_connections_unique_active` — a `UNIQUE (user_id, platform, resource_id) WHERE deleted_at IS NULL` index (confirmed in `supabase/migrations/20260602150238_create_platform_connections.sql:32-34`) — but that index can't prevent two *different* platform values from both landing for the same user, which is exactly the failure mode here: `collect(self::BOOKING_PLATFORMS)->contains(fn ($p) => $this->has($userId, $p))` and the subsequent `write()` are two separate round-trips with no lock between them.
    - **Plain English:** Only one "Book now" button should be live at a time (Fresha, Square, or a custom link — never two). Right now the code checks "is a booking button already set?" and, if not, sets one — but two different automated processes (say, connecting Google Business and Instagram moments apart) can both check at the same instant, both see "not set," and both set a different one. The result: the dashboard shows two competing booking connections instead of one clean choice.
    - **Evidence:**
        ```php
        if (collect(self::BOOKING_PLATFORMS)->contains(fn ($p) => $this->has($userId, $p))) {
            return [$this->conflictFinding($write['platform'], $write['resourceId'], 'booking', is_string($label) ? $label : 'Booking', $this->urlOf($write), [
                'remove' => self::BOOKING_PLATFORMS
                'write' => $write
            ])];
        }

        $this->write($userId, $write['platform'], $write['resourceId'], $write['payload']);
        ```

- [x] **#LIFE-106** · P2 — `InstagramAutoSync` booking XOR check has the same non-atomic race as `GoogleBusinessAutoSync::seedBooking`
    - **Where:** app/Services/Platforms/InstagramAutoSync.php:137-151
    - **Affects:** Same invariant as #LIFE-105 — a Google Business auto-sync racing an Instagram bio-link auto-sync (both can run around the same "connect a platform" moment) can each install a different booking provider.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Apply the identical fix as #LIFE-105 — same lock key (`"booking-seed:{$userId}"`) so a concurrent Google Business seed and Instagram seed serialize against each other, not just against themselves.
    - **Technical:** Same root cause and pattern as #LIFE-105 — `IntegrationConnection::query()->whereIn('platform', self::BOOKING_PLATFORMS)->where('platform', '!=', $platform)->first()` is a plain SELECT with no lock, followed by a separate `write()` call. Tiered identically to #LIFE-105 per "same root cause, same tier" — fixing both together with one shared lock key closes the gap for both sync sources at once.
    - **Plain English:** Same problem as #LIFE-105, but the two competing processes here are the Instagram bio-link scan and the Google Business sync — either can win the race and clobber the other's booking choice.
    - **Evidence:**
        ```php
        $conflictingBooking = IntegrationConnection::query()
            ->where('user_id', $userId)
            ->whereIn('platform', self::BOOKING_PLATFORMS)
            ->where('platform', '!=', $platform)
            ->first();

        if ($conflictingBooking !== null) {
        ```

- [x] **#LIFE-107** · P2 — `IdentitySync::applySector` reads-then-writes the user's sector with no row lock
    - **Where:** app/Services/Platforms/IdentitySync.php:140-148
    - **Affects:** Business Partna users with Google Business connected — a scheduled Google Business refresh (dispatched hourly by `integrations:refresh`, confirmed in `routes/console.php:93-98`) landing in the same instant as a user manually picking their sector via `SectorController` can silently revert the manual pick.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Re-fetch the user under `User::query()->where('id', $user->id)->lockForUpdate()->first()` inside a transaction immediately before the manual/`sector_source` guard check, so a concurrent manual pick that commits between this read and this write is detected rather than silently overwritten.
    - **Technical:** `IntegrationConnectionObserver::syncIdentityFromGoogle` fires `applyFromGooglePayload` (which calls `applySector`) on **every** Google Business connection save where the payload changed — not just the initial connect — including the recurring `integrations:refresh` → `RefreshConnectionJob` cycle. That makes "a scheduled Google resync lands at the same moment the user is editing their sector" a real, recurring scenario rather than a one-off connect-time race, even though the manual-pick guard (`sector_source === 'manual'`) is intended to make manual picks permanent.
    - **Plain English:** A user's chosen business category is supposed to stick once they pick it themselves — Google's automated sync should never overwrite a manual choice. But because the sync and the save aren't coordinated, if a user saves their pick at the exact moment an hourly Google refresh is also running, whichever one finishes writing last wins — occasionally reverting the user's own choice without any error or warning.
    - **Evidence:**
        ```php
        if ($overwrite) {
            if ($user->sector !== $mapped) {
                $user->sector = $mapped;
                $user->sector_source = self::GOOGLE_SOURCE;
                $user->save();
            }

            return;
        }
        ```

- [x] **#LIFE-108** · P2 — `IdentitySync::applyFromGooglePayload` reads-then-writes the workplace row with no row lock
    - **Where:** app/Services/Platforms/IdentitySync.php:71-95
    - **Affects:** Same recurring-refresh scenario as #LIFE-107, but for the workplace card fields (name, address, phone, website, category, hours) — a concurrent user edit to the same field a Google refresh is also touching can be silently clobbered.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Load the workplace row under `lockForUpdate()` inside a transaction before computing `$candidates`/`$sources`, so the precedence check (`! $overwrite && ! $this->isBlank($workplace->{$field})`) reads a consistent snapshot relative to the write.
    - **Technical:** Same root cause and trigger path as #LIFE-107 (the observer's `wasChanged('payload')` gate fires on every refresh, not just connect) — `Workplace::firstOrNew()` is a plain SELECT with no lock, and the eventual `$workplace->save()` only writes the fields this pass touched, so the risk is specifically a same-field collision: a user editing e.g. `phone` in the dashboard at the same instant a scheduled sync also resolves a new `phone` value from Google.
    - **Plain English:** The business-info card on a user's page (address, phone, hours) can be edited by the user or auto-filled from Google. If a user edits a field at the same moment an automatic Google refresh is also writing that same field, one of the two silently disappears — with no error shown to either side.
    - **Evidence:**
        ```php
        $workplace = Workplace::firstOrNew(['site_id' => (string) $site->id]);
        $sources = is_array($workplace->field_sources) ? $workplace->field_sources : [];
        $stamp = now()->toIso8601String();
        $changed = false;
        ```

- [x] **#LIFE-109** · P2 — Alias KV entries with 1–59s of remaining TTL are written without Cloudflare's 60s minimum enforced
    - **Where:** app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:211-251
    - **Affects:** Users with a handle alias expiring in under a minute at the moment any KV resync fires — the whole `bulkPut` batch for that user's aliases can be rejected by Cloudflare, temporarily dropping alias-redirect entries for handles that still have plenty of TTL left, not just the near-expiry one.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - After computing `$ttl`, add a floor: `if ($ttl !== null && $ttl > 0 && $ttl < 60) { $ttl = 60; }` (or skip the entry entirely — it will fall out of the alias query on the very next sync anyway since it's about to expire).
    - **Technical:** `CloudflareKvService::put`/`bulkPut` already document the constraint (`"Cloudflare KV enforces a minimum of 60s; callers should pre-clamp."`, `app/Services/Cloudflare/CloudflareKvService.php:36`), but `writeAliasEntries` only guards `$ttl <= 0` — the `1 ≤ ttl ≤ 59` window passes straight into the batch `bulkPut` call. Cloudflare's bulk KV write validates the whole batch before writing, so one invalid TTL entry can fail the entire request for that user's aliases (retried up to 3 times per `HasCloudflareRetryPolicy`, then permanently until the next trigger). Impact is self-limiting — the offending alias will fall out of the `->active()` window within a minute regardless — but a batch failure temporarily blocks *all* of that user's other, still-valid alias redirects too.
    - **Plain English:** Cloudflare requires "at least 60 seconds of life" on any entry it stores. When a user's old web address is down to its last few seconds before it stops working entirely, this code still tries to hand Cloudflare that almost-expired entry — which can make Cloudflare reject the *whole batch* of that user's old addresses, not just the expiring one, until the next automatic retry.
    - **Evidence:**
        ```php
        $ttl = $alias->expires_at
            ? (int) now()->diffInSeconds(Carbon::parse($alias->expires_at), false)
            : null;

        // P3-31: skip already-expired aliases — Cloudflare KV enforces a 60s
        // minimum TTL, so passing a ≤0 TTL would resurrect an expired alias at
        // the edge for up to 60s past its DB expiry. The DB query above already
        // excludes expires_at < now(), but race conditions between query time and
        // this point mean we must guard here too. Aligns with the resolver
        // ->active() scope which also filters expires_at > now().
        if ($ttl !== null && $ttl <= 0) {
            continue;
        }
        ```

- [x] **#LIFE-110** · P2 — `SyncSubdomainToKvJob`'s `ShouldBeUnique` window can drop a rapid second handle-routing sync while the first is still in flight
    - **Where:** app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:38-71
    - **Affects:** Any user whose routing state changes twice (e.g. two rapid handle corrections, or a handle change immediately followed by a custom-domain change) while the prior sync job for that same `uniqueId()` is still queued or actively processing (including through its up-to-3 Cloudflare-API retries).
    - **Effort:** M (~2–4h, needs care around Horizon lock semantics + a concurrency test)
    - **What to do:**
        - Note before changing anything: Laravel's `ShouldBeUnique` lock is released as soon as the job **finishes processing** (success or failure), not held for the full 45s regardless — `$uniqueFor` is a safety ceiling for a crashed/lost job, not the normal-case drop window. The drop only happens if a second dispatch lands *while the first job is still actively queued or running* (including its Cloudflare-retry backoff, up to ~100s under `HasCloudflareRetryPolicy`'s `[10, 30, 60]`).
        - Consider switching to `ShouldBeUniqueUntilProcessing` instead of `ShouldBeUnique`, which releases the lock the instant a worker picks the job up rather than after it finishes — this shrinks the drop window to just queue-wait time. This is safe here specifically because `handle()` always re-reads current user/site/alias state fresh at execution time rather than trusting dispatch-time data, so two overlapping executions converge to the same correct result rather than tearing state.
        - Preserve the existing weekly backstop (`partna:backfill-subdomain-kv --all --queue`, scheduled Sunday 04:00 UTC in `routes/console.php:187-192`) regardless of which fix is chosen — it already re-syncs every professional's KV state unconditionally and is the safety net that keeps this at P2 rather than P1.
    - **Technical:** The class doc comment (`"ShouldBeUnique with a 45s window collapses observer storms to a single KV write per 45s"`) documents the coalescing as deliberate, and for the common case (fast queue, job completes in well under a second) the actual drop window is negligible — the lock releases on completion, not after a flat 45s. The real exposure is narrower than DeepSeek's original framing but still real: if the first job is genuinely still processing (backed up queue, or retrying against a slow/erroring Cloudflare API) when a second routing-relevant change commits, that second dispatch is silently skipped and the job that eventually runs may have already read stale state before the change. Given the existing weekly `backfill-subdomain-kv` reconcile sweep, any such drift self-heals within 7 days rather than persisting indefinitely.
    - **Plain English:** When a user's routing info changes (say, they fix a handle typo right after setting it), the system queues an update to Cloudflare's routing table. If an update for that same user is already in progress — usually a fraction of a second, but potentially longer if Cloudflare itself is being slow — the second update can get silently skipped. There's already a weekly safety sweep that re-checks every user's routing and fixes any drift, so this isn't an "invisible forever" problem, but tightening the window means fewer users ever see a stale routing entry in the meantime.
    - **Evidence:**
        ```php
        // `ShouldBeUnique` with a 45s window collapses observer storms to a single KV write per 45s.
        class SyncSubdomainToKvJob implements ShouldBeUnique, ShouldQueue
        {
            use Dispatchable, HasCloudflareRetryPolicy, InteractsWithQueue, Queueable, SerializesModels;

            public int $timeout = 30;

            public int $uniqueFor = 45;
        ```

## P3 — Nice to have

## Suggested Bundled Sessions

- **Bundle 1 — Log-with-context sweep (account/site lifecycle):** #LIFE-102, #LIFE-103, #LIFE-104
    - **Why grouped:** Same root-cause pattern (missing `user_id`/discriminator in a lifecycle log's catch block) across two files; all S-effort, no auth/money/schema involvement.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Platform auto-sync race hardening:** #LIFE-105, #LIFE-106, #LIFE-107, #LIFE-108
    - **Why grouped:** All live in `app/Services/Platforms/` + `app/Jobs/Platforms/`, all stem from unlocked read-modify-write races in the Google Business / Instagram auto-sync pipeline, and #LIFE-105/#LIFE-106 share one fix (same lock key) by design.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Cloudflare KV sync job hardening:** #LIFE-109, #LIFE-110
    - **Why grouped:** Same file (`SyncSubdomainToKvJob.php`), same subsystem (subdomain routing sync), both touch the alias/TTL and uniqueness logic that a single reviewer should reason about together.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle



<!-- ═══════════ audit-2026-07-17-scaling-antipatterns.md ═══════════ -->

# Scaling Antipatterns Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Scaling antipatterns: write amplification, rebuild-on-write, weak caching (chunk: write-paths)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `app/Http/Resources/PublicSite/IndividualProfileResource.php`
- `app/Http/Resources/UserDashboardResource.php`
- `app/Observers/User/UserObserver.php`
- `app/Services/Analytics/ContentFreshness.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 0 complete

---

DeepSeek's null result was independently re-verified against each of the four scoped files:

- **`UserObserver.php`** dispatches a bounded, fixed set of side effects per single model save (`invalidateUser`, a conditional `touchParentSiteIfPublicFieldChanged`, a conditional `SyncSubdomainToKvJob::dispatch`, a conditional `reevaluatePublicContactSection`, a conditional `cleanupLifestyleConnectionsIfBusiness`). None of these scale with data cardinality or event payload size — they are O(1) per user update, not a per-row loop, not a rebuild-on-write, and not a fan-out job that multiplies per recipient. This is normal observer side-effect wiring, not the write-amplification shape the lens targets (N rows per event where N is unbounded). User-profile edits are also not a hot/write-heavy path per the platform's own scale context (that's public sitepage resolution and analytics ingest) — even if this work were synchronous request-thread cost, it wouldn't clear the bar for a scaling finding here.
- **`ContentFreshness::boostsForSite`** issues two bounded, well-scoped read queries (`IntegrationConnection::query()->where('user_id', ...)->active()->get(...)` and `Service::query()->where(...)->max('created_at')`), both scoped to one site's own data (bounded by a single user's connections/services — small cardinality, not a list/analytics sweep). It performs no writes, no DELETE+INSERT rebuild, and no cache access at all — it's a pure compute service consumed by other layers (a console command and two services outside this chunk's scope), so there's nothing here for categories (1)–(6) to attach to.
- **`IndividualProfileResource.php`** and **`UserDashboardResource.php`** are pure array-shaping Resource classes — no DB queries, no cache calls, no loops proportional to unbounded input. `UserDashboardResource` touches at most one lazy relation (`$this->site`) on a single-record "own profile" response, which is explicitly out of scope per the N+1 threshold (single row, not a list/sweep).

No rebuild-on-write, write-amplification, weak-caching, live-query, hot-path fan-out, or append-only/mutable-confusion pattern is present in verbatim code across these four files. This is a legitimate clean result for this chunk, not an under-scan — the higher-risk surfaces named in the lens background (analytics ingestors/writers, notification fan-out jobs, other observers) are covered by separate chunks in this sweep.

## Suggested Bundled Sessions

None.

## Standalone — do NOT bundle

None.


<!-- ═══════════ audit-2026-07-17-database-and-queue-scaling.md ═══════════ -->

# Database & Queue Scaling Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Database & queue scaling — N+1, unbounded reads, connection scoping, queue shape, vendor budgets, migration safety, backpressure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `app/Models/Core/Site/Site.php`
- `app/Http/Resources/UserDashboardResource.php`
- `app/Http/Resources/PublicSite/IndividualProfileResource.php`
- `app/Services/Cloudflare/CloudflarePurgeService.php`
- `app/Services/Cloudflare/CloudflareCustomHostnameService.php`
- `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php`
- `app/Jobs/Platforms/InstagramConnectJob.php`
- `app/Jobs/Platforms/MenuFetchJob.php`
- `app/Models/Core/Site/Menu.php`, `MenuItem.php`
- `app/Services/Analytics/ContentFreshness.php`
- `app/Console/Commands/CleanupOrphanedLifestyleConnections.php`
- `config/horizon.php`
- `app/Services/Http/SafeUrlFetcher.php` (cross-check only)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 1 of 2 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [ ] **#SCALE-101** · P2 — `CloudflarePurgeService::purgeUrls` fires unbounded sequential Cloudflare API calls with no inter-chunk delay
    - **Where:** app/Services/Cloudflare/CloudflarePurgeService.php:66-73
    - **Affects:** Every profile-edit / image-upload / design-kit-change cache purge (`CloudflareCachePurgeJob` → `purgeHandle`). The method's own docblock documents that a full sitepage purge (root + 15 deep-link sub-pages + their SWR shadows + API subrequest, per host, plus up to 100 shop product handles) routinely exceeds the 30-URL-per-request limit, so most real purges already fire multiple sequential POSTs — this isn't a rare bulk-admin edge case, it's the common path.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a small delay (e.g. 100–200ms) between chunk POSTs in `purgeUrls`, or switch to `Http::pool` with a bounded concurrency so chunks aren't fired back-to-back with zero pacing.
        - No change needed to worker concurrency — `config/horizon.php`'s `supervisor-cloudflare` already caps this queue at `maxProcesses: 2`, which bounds cross-job concurrency; this fix only addresses intra-job chunk pacing.
    - **Technical:** Cloudflare's `purge_cache` endpoint accepts at most 30 URLs per request on non-Enterprise plans. `purgeUrls` correctly chunks into batches of 30 but the `foreach` fires each chunk's HTTP POST immediately with no pacing. Since a single `purgeHandle()` call for one profile can already produce 10+ chunks (per the method's own docblock), and up to 2 Horizon workers can run this concurrently (`supervisor-cloudflare` `maxProcesses: 2`), a handle with many shop products can produce a burst of near-simultaneous requests against the same zone. This is defense-in-depth today (pre-beta traffic is low) but will become a real 429/retry-amplification risk as product-catalog size and edit frequency grow.
    - **Plain English:** Every time someone edits their page, we tell Cloudflare "forget these cached copies" — but if there are a lot of cached copies (which is now the normal case, not rare), we fire off all those "forget" requests back-to-back with zero pause between them. It's like a courier making 15 trips to the same loading dock without ever slowing down — eventually the dock says "too many, come back later" and the requests start failing and retrying, which makes the pile-up worse.
    - **Evidence:**
        ```php
        // Cloudflare's purge_cache `files` accepts at most 30 URLs per request on
        // non-Enterprise plans. A full sitepage purge (root + 15 deep-link
        // sub-paths + each one's SWR shadow + the API subrequest) exceeds that, so
        // chunk into <=30-URL batches — one POST each.
        foreach (array_chunk(array_values($urls), 30) as $chunk) {
            Http::withToken($this->apiToken)
                ->asJson()
                ->acceptJson()
                ->post($this->url(), ['files' => $chunk])
                ->throw();
        }
        ```

- [x] **#SCALE-102** · P2 — `InstagramConnectJob::mirrorOne` buffers the full image body in memory instead of streaming, unlike the sibling `mirrorVideo` path in the same file
    - **Where:** app/Jobs/Platforms/InstagramConnectJob.php:330-338
    - **Affects:** The `scraping` Horizon queue worker (`supervisor-scraping`, `memory: 256`, `maxProcesses: 2`) during Instagram auto-connect. Each connect can mirror up to three images (photo, reel poster, profile pic) at up to 15MB each.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Mirror `mirrorVideo`'s pattern: fetch with `Http::sink($tmp)` to a temp file, size-check via `filesize()`, then stream the temp file to R2 via `fopen()`, instead of reading `$response->body()` into a PHP string.
        - Keep the existing `Content-Length` fast-rejection and the post-fetch hard cap — only the transport mechanism changes.
    - **Technical:** `mirrorOne` fetches with `$response->body()`, buffering the entire response into a PHP string before the size check and before the `Storage::put()` call — up to `MAX_IMAGE_BYTES` (15MB) held in memory per call, and `mirrorOne` can be invoked up to 3 times per job. The sibling `mirrorVideo` in the same file already solved this correctly (`Http::sink($tmp)` → stream to R2), so this is an inconsistency within one file rather than a systemic gap. Given `supervisor-scraping` caps at `maxProcesses: 2` with a 256MB Horizon memory limit per worker, actual OOM risk today is bounded, not imminent — but it's a real asymmetry against an established in-file pattern and worth fixing opportunistically rather than as urgent hardening.
    - **Plain English:** When we mirror someone's Instagram photo, we read the entire picture into the computer's short-term memory before saving it — even though we already know how to stream video straight to storage without doing that (we do it correctly two functions below in the same file). It's an inconsistency: one path is careful, the other isn't. Fixing the photo path to match the video path closes a real (if currently modest) risk of the server running low on memory during a busy signup period.
    - **Evidence:**
        ```php
            $body = $response->body();

            // Hard cap enforced after buffering — covers absent or inaccurate
            // Content-Length headers. Nothing over the limit reaches R2.
            if (strlen($body) > self::MAX_IMAGE_BYTES) {
                return null;
            }

            Storage::disk('media')->put($path, $body);
        ```

## P3 — Nice to have

## Suggested Bundled Sessions

- **Bundle 1 — Cloudflare API rate-limit hardening:** #SCALE-101
    - **Why grouped:** Same vendor (Cloudflare), same root theme (outbound write-rate defense-in-depth), touch adjacent files in `app/Services/Cloudflare` and `app/Jobs/Cloudflare`.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

## Standalone — do NOT bundle

- **#SCALE-102 — InstagramConnectJob::mirrorOne memory streaming** · different subsystem (media pipeline, not Cloudflare) and file (`app/Jobs/Platforms/`) from the bundled pair; no shared dependency worth coordinating.


<!-- ═══════════ audit-2026-07-17-schema-rls.md ═══════════ -->

# Schema / RLS / search_path Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Schema / RLS / search_path: database-side correctness, constraint coverage, migration safety
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Models/Core/Site/Menu.php
- app/Models/Core/Site/MenuItem.php
- app/Models/Core/Site/Site.php
- app/Models/Core/User/User.php
- supabase/migrations/20260711170000_users_email_unique_case_insensitive.sql
- supabase/migrations/20260712000000_retire_staff_account_type.sql
- supabase/migrations/20260713120000_reconcile_instagram_gallery_unification.sql
- supabase/migrations/20260714200000_architecture_one_to_staple.sql
- supabase/migrations/20260714210000_drop_effect_surface.sql
- supabase/migrations/20260714220000_add_aesthetic_axes.sql
- supabase/migrations/20260714230000_drop_glass_satellites.sql
- supabase/migrations/20260715090000_menu_item_currency_and_dining_modes.sql

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 2 of 2 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [x] **SCHEMA-101** · P2 — Inline data backfill (`UPDATE` over matching rows) inside `20260713120000_reconcile_instagram_gallery_unification.sql`
    - **Where:** supabase/migrations/20260713120000_reconcile_instagram_gallery_unification.sql:16-41
    - **Affects:** `site.sites` and `site.platform_connections` — both statements require a full sequential scan to evaluate their `WHERE`/join predicates (no index backs `platform_connections.platform`+`display_settings ? 'gallery'`, nor the `site.sites` join key for this purpose), and both are data mutations executed inside a schema-migration transaction rather than a post-deploy path.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract the two `UPDATE` statements into a post-deploy artisan command (or a job dispatched after the migration transaction commits), leaving only schema-shape changes (if any) inside the migration itself — this is the exact pattern `docs/migration-guidelines.md` §"Full-table-scan data scrubs (#SCHEMA-102)" already prescribes, using nearly this same `site.sites`/`settings` shape as its own canonical "avoid" example.
        - If the team judges current row counts too small to matter (as was explicitly done for `20260714200000_architecture_one_to_staple.sql`, which carries a documented `guard:no-unsafe-migrations:disable-file` exemption for a 10-row `site.sites` table), add the same kind of explicit row-count justification comment rather than leaving the risk undocumented.
    - **Technical:** `scripts/guard-no-unsafe-migrations.php` only lints 4 patterns (unindexed `CREATE INDEX`, `ADD CONSTRAINT FK`/`CHECK` without `NOT VALID`, and `SET NOT NULL`) — inline data backfills are not covered by that CI guard, so this pattern only gets caught by manual/audit review. This migration is structurally the same anti-pattern `docs/migration-guidelines.md` already documents under the `#SCHEMA-102` heading (its own "avoid" example is `UPDATE site.sites SET settings = settings - 'design' WHERE settings ? 'design';`), so the fix here should follow that doc's own "prefer" pattern: a post-deploy command dispatched after the migration transaction commits.
    - **Plain English:** Think of the database as a shared filing system. This migration reaches in and rewrites data on two tables (which store, and Instagram connections) while everyone else is trying to read and write at the same time. The house style guide for this exact situation already exists and says "don't do data cleanup inside a schema change — do it as a separate step after the schema change ships," but this migration does it inline anyway.
    - **Evidence:**
        ```sql
        WITH ig AS (
            SELECT pc.user_id
                   bool_or((pc.display_settings ->> 'gallery') IS DISTINCT FROM 'false') AS any_on
            FROM site.platform_connections pc
            WHERE pc.platform = 'instagram' AND pc.is_active = true
            GROUP BY pc.user_id
        )
        UPDATE site.sites s
        SET content_instagram_auto_enabled = CASE
                WHEN s.content_instagram_auto_enabled IS NULL THEN ig.any_on
                WHEN s.content_instagram_auto_enabled = true AND ig.any_on = false THEN false
                ELSE s.content_instagram_auto_enabled
            END
        FROM ig
        WHERE s.user_id = ig.user_id;

        UPDATE site.platform_connections
        SET display_settings = NULLIF(display_settings - 'gallery', '{}'::jsonb)
        WHERE platform = 'instagram'
          AND display_settings ? 'gallery';
        ```

- [x] **SCHEMA-102** · P2 — `site.sites.shop_link_mode` is an enum-like `text` column with no `CHECK` constraint (recurring, previously flagged)
    - **Where:** app/Models/Core/Site/Site.php:34-42
    - **Affects:** Any write path setting `shop_link_mode` — the column accepts any string, not just `'checkout'`/`'product'`; a bad value would silently corrupt the public shop-link behavior for every connected store on that site.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ALTER TABLE site.sites ADD CONSTRAINT sites_shop_link_mode_check CHECK (shop_link_mode IN ('checkout', 'product')) NOT VALID;` followed by `VALIDATE CONSTRAINT` in a separate statement — required to pass `scripts/guard-no-unsafe-migrations.php` (Check 3 fails any `ADD CONSTRAINT ... CHECK` without `NOT VALID`).
        - Keep the app-side validation as the first line of defense; the DB constraint is the backstop.
    - **Technical:** The `Site` model's own comment concedes "no DB CHECK, matching the SQLite-test-mirror convention" — but that rationale is applied inconsistently: `BOOKING_MODES` on the same model *does* have a backing DB `CHECK` (`sites_booking_mode_check`) despite the identical SQLite-test-mirror limitation, so `shop_link_mode` is the outlier, not the norm. This exact gap was already raised as `` in the 2026-07-10 audit (`audits/sweeps/2026-07-10-new-work-sweep/CONSOLIDATED.md`) and remains unfixed. The canonical pattern to mirror is `site.sites.architecture_id`'s `sites_architecture_id_check` CHECK.
    - **Plain English:** The column that controls how every connected store's "buy" link behaves only has two valid settings, but the database itself doesn't enforce that — only the app code does. If a bug or a bad data import ever writes something other than those two values, the database will happily store it, and the public store link could quietly break. This was already flagged as a gap a week ago and hasn't been fixed yet.
    - **Evidence:**
        ```php
        /**
         * Allowed GLOBAL shop link modes — mirrors the value the shop-settings
         * request validates (no DB CHECK, matching the SQLite-test-mirror
         * convention). 'checkout' deep-links product cards straight to the store
         * cart/checkout; 'product' links to the product page. Applied to EVERY
         * connected store — the public payload stamps each brand's linkMode from
         * site.sites.shop_link_mode. Default 'checkout' (direct-to-checkout ON).
         */
        public const SHOP_LINK_MODES = ['checkout', 'product'];
        ```

## P3 — Nice to have

## Suggested Bundled Sessions

None — every finding in this audit is a direct DB migration/schema change, which the fix-flow policy always routes to standalone execution (see below), matching how the prior schema-rls audit (`audits/sweeps/2026-07-10-new-work-sweep/CONSOLIDATED.md`) handled the same category of findings.

## Standalone — do NOT bundle

- **SCHEMA-101 — reconcile-instagram-gallery migration backfill** · DB migration/schema change (data backfill inside a migration transaction).
- **SCHEMA-102 — sites.shop_link_mode CHECK** · DB migration/schema change.


<!-- ═══════════ audit-2026-07-17-caching-gold-standard.md ═══════════ -->

# Caching Gold-Standard Adherence Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Caching gold-standard adherence — single-flight locks, TTL jitter, stale-while-revalidate, push-invalidation, version tokens, lock/connection hygiene, bounded TTLs, and key-generation drift (categories 1–10), measured against the `CacheLockService` / `SiteCacheService` / `UserCacheService` reference pattern documented in `docs/caching-gold-standard.md`.
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Services/Accounts/AccountCapabilities.php
- app/Services/Accounts/AccountCapabilitySet.php
- app/Services/Accounts/LifestyleConnectionCleanup.php
- app/Services/PublicSite/IndividualProfilePayloadBuilder.php
- app/Services/PublicSite/SiteActionsService.php
- app/Services/PublicSite/SitepageDataResolverService.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Observers/User/UserObserver.php
- app/Services/Analytics/ContentFreshness.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 1 of 1 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [x] **#CCH-101** · P2 — Cache-invalidation failures on all UserObserver lifecycle hooks are swallowed into an invisible `Log::warning`
    - **Where:** app/Observers/User/UserObserver.php:59-66 (`updated`), 160-167 (`deleted`), 190-197 (`restored`)
    - **Affects:** Every profile edit, soft-delete, and restore. If `UserCacheService::invalidateUser()` throws (e.g. a transient Redis blip), the DB mutation still commits but the ~10-key push-invalidation fan-out (primary + `:stale` SWR companions for the professional payload, services, dashboard services, customer count, plus the id/handle/auth-id lookup keys) silently doesn't happen — no alert fires, no compensating action runs, and the stale set survives until natural TTL/SWR expiry.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Log::warning(...)` with `report($e)` in all three catch blocks so Nightwatch surfaces the failure as an exception (per the architecture doctrine: "a failure that needs attention must throw or `$this->fail($e)`; bare `Log::warning` is invisible").
        - Add a small compensating job (`app/Jobs/Cache/InvalidateUserCacheJob.php`, modeled on the existing `ShouldBeUnique`/`ShouldQueue`/`$backoff` pattern in `WarmPublicSiteCacheJob`) dispatched from the catch block so a transient Redis outage is retried instead of silently accepted as final.
    - **Technical:** `UserCacheService::invalidateUser()` (app/Services/Cache/UserCacheService.php:273-303+) is the correct push-invalidation call and correctly forgets both primary keys and their `:stale` SWR companions — the write-side implementation itself meets the gold standard. The problem is purely in the observer's error posture: all three call sites (`updated`, `deleted`, `restored`) wrap the invalidation in `try { ... } catch (\Throwable $e) { Log::warning(...); }` with no `report()` call and no compensating action anywhere in the file (confirmed via grep — zero `report()` calls in `UserObserver.php`). Per `reference_nightwatch_alerts.md`, Nightwatch alerts trigger on exceptions and auto-detected slow jobs/routes, never on log queries, so this failure mode is invisible to on-call until a user reports stale data. The observer's `public bool $afterCommit = true` class property is correct (Laravel's documented Observer-level after-commit deferral, no interface needed) — invalidation timing relative to the DB transaction is right; only the failure-handling posture is wrong. This is a genuine, if narrow, deviation from category 4 (push-invalidation on every write path) because the invalidation is present in code but not *guaranteed* to execute — a de facto TTL-only window gated by an unlikely-but-real transient dependency failure, not a deliberate design choice.
    - **Plain English:** Picture a coat-check attendant who updates your ticket whenever you swap coats — but if the ticket printer jams, they just shrug and say "it'll sort itself out eventually." The next person who looks up your coat gets outdated information, and nobody finds out the printer jammed unless someone happens to read the logbook by hand — the automatic fire alarm (our monitoring system) only listens for actual fires, not logbook entries. The fix makes a jam trigger the fire alarm, and adds a backup runner who quietly retries the ticket update a little later.
    - **Evidence:**
        ```php
        // updated() — lines 59-66
        try {
            $this->userCache->invalidateUser($professional, bustSite: ! $publicFieldChanged);
        } catch (\Throwable $e) {
            Log::warning('Professional cache invalidation failed on update', $this->logContext(__METHOD__, [
                'user_id' => $professional->id
                'message' => $e->getMessage()
            ]));
        }

        // deleted() — lines 160-167
        try {
            $this->userCache->invalidateUser($professional);
        } catch (\Throwable $e) {
            Log::warning('Professional cache invalidation failed on delete', $this->logContext(__METHOD__, [
                'user_id' => $professional->id
                'message' => $e->getMessage()
            ]));
        }

        // restored() — lines 190-197
        try {
            $this->userCache->invalidateUser($professional);
        } catch (\Throwable $e) {
            Log::warning('Professional cache invalidation failed on restore', $this->logContext(__METHOD__, [
                'user_id' => $professional->id
                'message' => $e->getMessage()
            ]));
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — UserObserver cache-invalidation observability:** #CCH-101
    - **Why grouped:** Single file, single root cause (three identical silent-catch occurrences of the same pattern) — one fix session touches `updated()`, `deleted()`, and `restored()` together.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet (S-effort, no escalation needed).

## Standalone — do NOT bundle

None.


<!-- ═══════════ audit-2026-07-17-webhook-idempotency.md ═══════════ -->

# Inbound Callbacks & Idempotency Semantics Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Inbound callbacks & idempotency semantics — Supabase auth/email hooks, `bot.token`-gated internal endpoints, and the client-supplied `IdempotencyKey` middleware, measured against the Partna gold-standard callback pattern (HMAC-before-parse, persisted idempotency anchors, 200-only-on-success, no domain mutations outside a job).
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php`
- `app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php`
- `app/Http/Controllers/Api/Internal/EnvCheckController.php`
- `app/Http/Controllers/Api/Internal/CspReportController.php`
- `app/Http/Middleware/Auth/VerifySupabaseHookSignature.php`
- `app/Services/Webhooks/StandardWebhookVerifier.php`
- `app/Http/Middleware/IdempotencyKey.php`
- `app/Http/Middleware/VerifyBotToken.php`
- `app/Services/Auth/AuthFactorEventRepository.php`
- `app/Services/Notifications/SupabaseEmailEventService.php`
- `app/Services/User/AccountDeletionService.php`
- `app/Mail/BaseTransactionalMail.php`, `app/Mail/Auth/EmailConfirmMail.php`
- `routes/api.php`, `routes/api/user.php`, `bootstrap/app.php`
- `routes/api/platforms.php` (original scan scope — no callback surface present)

**Note on scope:** the DeepSeek scan for this chunk was configured with `--scope routes/api/platforms.php`, which contains none of this lens's target surface (no hook controllers, no `IdempotencyKey`/`VerifyBotToken`/`StandardWebhookVerifier`). That "no findings" draft is accurate for the scope it was given, but the scope itself was a pipeline misconfiguration — `routes/api/platforms.php` holds only authenticated dashboard integration routes. Per the adjudicator's mandate to read source against the lens and add missed findings, this audit instead directly reads the Group A–E files the lens actually targets (`SupabaseAuthHookController`, `SupabaseEmailHookController`, `VerifySupabaseHookSignature`, `StandardWebhookVerifier`, `IdempotencyKey`, `VerifyBotToken`, `routes/api.php`).

**Overall finding:** this surface is unusually well-hardened already — in-code annotations (`WHK-101`…`WHK-5`, `OBS-101`, `OBS-4`, `PRIV-101`, `LIFE-102`, `CCH-101`, `SCALE-101/2`) and matching Pest coverage (`SupabaseAuthHookBruteForceTest`, `SupabaseAuthHookSignatureTest`, `SupabaseEmailHookTest`, `IdempotencyKeyMiddlewareTest`, `VerifyBotTokenTest`) show this exact lens has already been through at least one hardening pass. HMAC-before-parse, `hash_equals`, timestamp tolerance, atomic `Cache::add` anchors, anchor-reversal-on-failure, 500-on-dispatch-failure, and stable Message-ID mail dedup are all correctly implemented and verified against source. Two narrower gaps survived review.

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 2 of 2 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [x] **#WHK-101** · P2 — MFA auth-hook idempotency anchor is Redis-only; no DB-level uniqueness backstop on the audit trail
    - **Where:** `app/Services/Auth/AuthFactorEventRepository.php:30-56`, `app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php:53-59`
    - **Affects:** `audit.auth_factor_events` rows and the MFA brute-force counter (`countRecentFailures`) for any user going through TOTP/phone/webauthn verification during a Redis blip.
    - **Effort:** M (~2-4h) — new nullable `webhook_id` column + unique index migration, thread the id through `record()`, switch the insert to `INSERT … ON CONFLICT (webhook_id) DO NOTHING`.
    - **What to do:**
        - Add a `webhook_id TEXT` column (nullable, unique partial index `WHERE webhook_id IS NOT NULL`) to `audit.auth_factor_events` in a new `supabase/migrations/` file.
        - Pass `$id` from the controller into `AuthFactorEventRepository::record()` and switch the insert to `DB::connection('pgsql')->table(...)->insertOrIgnore([...])` (or raw `INSERT … ON CONFLICT (webhook_id) DO NOTHING`) keyed on `webhook_id`.
        - Leave the existing `Cache::add` anchor as the fast-path gate (it's correct and atomic) — the DB constraint is a second line of defense for when Redis loses the key.
    - **Technical:** the only thing preventing a redelivered `webhook-id` from double-recording a factor event is `Cache::add("supabase:auth-hook:{$id}", ...)` with a 24h TTL (`config('partna.cache.ttls.webhook_idempotency')`, default 86400s). That anchor lives purely in Redis. If Redis restarts, fails over, or evicts the key under `maxmemory` pressure between the original delivery and a Supabase retry, `Cache::add` succeeds again on the retry and `AuthFactorEventRepository::record()` — which has no `webhook_id` column and no uniqueness constraint at all — inserts a second row for the same real-world event. For a `verify_failed`/`verify_rejected_by_hook` pair this inflates `countRecentFailures()` by one, which can flip a legitimate 4th failure into a false 5th and trigger a premature MFA lockout. The email hook (`SupabaseEmailEvent::updateOrCreate(['webhook_id' => $webhookId], ...)`) already has a DB-persisted anchor for its forensic trail; the auth hook's audit table has no equivalent column, so it can't backstop a lost cache key the way the email hook's trail table structurally could.
    - **Plain English:** every MFA verification attempt gets a receipt stamped with a delivery ID so we never count the same attempt twice. That receipt currently lives only in a fast, temporary scratchpad (Redis) — if that scratchpad gets wiped during routine server maintenance right as Supabase resends a message, we lose track and might count one real failed login attempt as two. In rare cases that could lock a legitimate user out of their account a login attempt early. The fix is to also write the delivery ID into the permanent record, so even if the scratchpad is wiped, we can still tell "already saw this one" from the permanent copy.
    - **Evidence:**
        ```php
        public function record(
            string $userId
            string $eventType
            ?string $factorId = null
            ?string $factorType = null
            ?string $sessionId = null
            ?string $ip = null
            ?string $userAgent = null
            array $metadata = []
        ): string {
            $id = (string) Str::uuid();

            DB::connection('pgsql')->table(self::TABLE)->insert([
                'id' => $id
                'user_id' => $userId
                'session_id' => $sessionId
                'event_type' => $eventType
                'factor_id' => $factorId
                'factor_type' => $factorType
                'ip' => $ip
                'user_agent' => $userAgent
                'metadata' => json_encode($metadata)
                'created_at' => now()->toIso8601String()
            ]);

            return $id;
        }
        ```
        ```php
        if (! Cache::add(
            "supabase:auth-hook:{$id}"
            true
            (int) config('partna.cache.ttls.webhook_idempotency')
        )) {
            return response()->json(['decision' => 'continue']);
        }
        ```

- [x] **#WHK-102** · P2 — `IdempotencyKey` is opt-in on `/me/deletion/*`; the double-submit race it's meant to close has no server-side enforcement and no test for the header-omitted path
    - **Where:** `app/Http/Middleware/IdempotencyKey.php:44-47`, `routes/api/user.php:54-59`, `app/Services/User/AccountDeletionService.php:48-113` (`request()`)
    - **Affects:** `POST /api/me/deletion/request` — a browser double-tap, refresh, or client retry without the `Idempotency-Key` header sends two deletion-confirmation emails and writes two `UserDeletionAuditEntry` rows for one user action.
    - **Effort:** M (~2-4h) — add a `lockForUpdate`-style guard to `AccountDeletionService::request()` matching the pattern already used in `confirm()`, plus a test for the missing-header path.
    - **What to do:**
        - Give `AccountDeletionService::request()` the same re-read-under-`lockForUpdate` guard `confirm()` already uses (lines 211-215) so a concurrent double-submit is closed at the domain layer regardless of whether the client sent an idempotency key.
        - Add a Pest test exercising `POST /me/deletion/request` twice concurrently (or back-to-back with the header omitted) asserting only one `SendAccountDeletionRequestMailJob` dispatch and one audit row — mirroring the existing `#P2-43` coverage that only tests the key-present path.
    - **Technical:** the `idempotent` middleware is intentionally opt-in system-wide — `if (! is_string($key) || $key === '') { return $next($request); }` — which is correct for the general contract (client sends the header, server replays on retry). But the route comment on `me/deletion` frames the middleware as *the* fix for a specific double-submit race (`#P2-43`), and unlike `confirm()` (which independently re-reads the row under `lockForUpdate` and no-ops the losing concurrent caller — see the comment at `AccountDeletionService.php:211-214`), `request()` has no equivalent domain-layer guard: it unconditionally does `$professional->update([...])` + `SendAccountDeletionRequestMailJob::dispatch(...)` + `logAuditEvent(...)` inside a transaction, gated only by the controller's `status === 'pending_deletion'` check — which `request()` itself never flips (only `confirm()` does), so two concurrent `request()` calls both pass that check and both fire. If the `Idempotency-Key` header is ever omitted (frontend bug, non-browser client, a retry path that doesn't reuse the header), the race this middleware exists to close reopens silently with zero enforcement and zero test coverage of that scenario.
    - **Plain English:** clicking "delete my account" twice quickly (a slow network, a nervous double-click) is supposed to be safe because of a receipt-based dedup system — but that system only works if the app remembers to attach the receipt number to the request. Right now nothing on the server double-checks that the receipt was actually attached, and the underlying "delete" action itself has no independent safety check to fall back on. If the receipt ever goes missing for any reason, the user could get two deletion-confirmation emails and the compliance log gets a duplicate entry — confusing, not catastrophic, but avoidable with a proper backstop.
    - **Evidence:**
        ```php
                // Account Deletion — self-service lifecycle.
                // `idempotent` middleware closes the concurrent-double-submit race that
                // would otherwise let a browser refresh or mobile double-tap persist
                // duplicate audit rows and queue duplicate confirmation mails (#P2-43).
                // Frontend must send a per-action `Idempotency-Key: <uuid-v4>` header.
                Route::prefix('me/deletion')->middleware('idempotent')->group(function () {
        ```
        ```php
                $key = $request->header('Idempotency-Key');
                if (! is_string($key) || $key === '') {
                    return $next($request);
                }
        ```
        ```php
            DB::connection('pgsql')->transaction(function () use ($professional, $tokenHash, $confirmationUrl, $request) {
                $professional->update([
                    'deletion_token_hash' => $tokenHash
                    'deletion_requested_at' => now()
                    'deletion_mail_sent_at' => null
                ]);

                SendAccountDeletionRequestMailJob::dispatch(
                    $professional->id
                    $confirmationUrl
                    $tokenHash
                );

                $this->logAuditEvent($professional, UserDeletionAuditEntry::EVENT_REQUESTED, $request);
            });
        ```

## Suggested Bundled Sessions

None — the two surviving findings touch unrelated files and subsystems (auth-hook audit trail vs. account-deletion lifecycle) with no shared root cause.

## Standalone — do NOT bundle

- **#WHK-101 — MFA auth-hook idempotency anchor is Redis-only** · requires a Supabase migration (new column + unique index on `audit.auth_factor_events`).
- **#WHK-102 — `IdempotencyKey` opt-in on `/me/deletion/*`** · touches the account-deletion authorization/lifecycle flow (policy-gated, GDPR-adjacent audit trail) and warrants isolated plan + sign-off.


<!-- ═══════════ audit-2026-07-17-transaction-boundaries.md ═══════════ -->

# Transaction Boundary Correctness Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Transaction boundary correctness — every `DB::transaction`/`DB::beginTransaction` site measured against the gold-standard discipline (no external I/O, no queue dispatch, no cache writes, no side-effecting event/observer hooks inside a transaction; bounded scope; safe retry semantics; intentional nesting; consistent lock ordering).
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- app/Observers/User/UserObserver.php
- app/Services/Accounts/AccountCapabilities.php
- app/Services/Accounts/AccountCapabilitySet.php
- app/Services/Accounts/LifestyleConnectionCleanup.php
- app/Services/User/AccountDeletionService.php
- app/Services/User/SiteProvisioningService.php
- app/Services/User/UserBootstrapService.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Jobs/Platforms/InstagramConnectJob.php
- app/Jobs/Platforms/MenuFetchJob.php
- app/Services/Cloudflare/CloudflareCustomHostnameService.php
- app/Services/Cloudflare/CloudflarePurgeService.php
- app/Services/Platforms/BigCartelScraper.php
- app/Services/Platforms/DoorDashMenuDriver.php
- app/Services/Platforms/GenericShopScraper.php
- app/Services/Platforms/GoogleBusinessAutoSync.php
- app/Services/Platforms/IdentitySync.php
- app/Services/Platforms/InstagramAutoSync.php
- app/Services/Platforms/InstagramScraper.php
- app/Services/Platforms/MenuMerger.php
- app/Services/Platforms/MenuScanApplier.php
- app/Services/Platforms/Normalizers/FacebookNormalizer.php
- app/Services/Platforms/Payloads/InstagramPayload.php
- app/Services/Platforms/PlatformScraper.php
- app/Services/Platforms/Registry/PlatformDescriptor.php
- app/Services/Platforms/ShopifyScraper.php
- app/Services/Platforms/UberEatsMenuDriver.php
- app/Services/Platforms/WebsiteLinkHarvester.php
- app/Services/Platforms/WooCommerceScraper.php
- app/Jobs/Account/SendAccountDeletionRequestMailJob.php (verification read)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 2 of 2 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [x] **#TXN-101** · P2 — `MenuFetchJob` writes platform sync-status metadata ("ok"/"unavailable" + `synced_at`) before the content-rebuild transaction, so a rolled-back rebuild leaves a false "synced" marker
    - **Where:** app/Jobs/Platforms/MenuFetchJob.php:163-173 (store-URL upsert), 188-193 (sync-status upsert), 216 (call into `persist()`), 265-364 (`persist()`'s `DB::transaction`)
    - **Affects:** The per-platform `MenuPlatformLink` rows a user's dashboard reads to show "synced" status. A `persist()` failure (constraint violation, deadlock) between two menu-content rebuilds leaves `status='ok'`/`'unavailable'` and `synced_at` pointing at content that was never actually written, until the next scheduled/forced re-fetch corrects it.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the two `MenuPlatformLink::updateOrCreate(...)` status/URL writes (lines 163-173, 188-193) inside `persist()`'s transaction so they commit or roll back atomically with the category/item rebuild.
        - Alternatively, write the sync-status update only *after* `persist()` returns successfully, keyed off the same `$now` timestamp already threaded through.
    - **Technical:** `persist()` (line 265) wraps only the `MenuCategory`/`MenuItem`/`MenuItemPlatform` rebuild in `DB::connection('pgsql')->transaction(...)`. The `MenuPlatformLink` store-URL write (163-173) and the sync-status write (188-193) both execute as autocommitted statements *before* that transaction opens. If `persist()` then throws and rolls back, the link rows already show the scrape as settled (`status='ok'`, fresh `synced_at`) while the actual menu content is unchanged — a window where the dashboard's "last synced" indicator lies about the state of the data. This is not corruption (the next fetch cycle re-derives and overwrites it), but it's a genuine transaction-boundary gap: the metadata and the content it describes are not one atomic unit.
    - **Plain English:** Think of a delivery tracking sticker that gets stamped "delivered" the moment the truck leaves the warehouse, before anyone confirms the package actually arrived. If the truck breaks down (the database write fails), the sticker still says "delivered" until the next shipment corrects the record. Nobody loses data permanently, but for a while the status shown to the user doesn't match reality.
    - **Evidence:**
        ```php
        foreach ($plan['storeUrls'] as $platform => $url) {
            if ($url === null) {
                $menu->platformLinks()->where('platform', $platform)->delete();

                continue;
            }
            MenuPlatformLink::updateOrCreate(
                ['menu_id' => $menu->id, 'platform' => $platform]
                ['store_url' => $url]
            );
        }
        ```
        ```php
        foreach ($storeLinks as $platform => $link) {
            MenuPlatformLink::updateOrCreate(
                ['menu_id' => $menu->id, 'platform' => $platform]
                ['synced_at' => $now, 'status' => ($menus[$platform] ?? null) !== null ? 'ok' : 'unavailable']
            );
        }
        ```
        ```php
        $this->persist($menu, $contentSource, $merged, $now);
        ```

- [x] **#TXN-102** · P2 — `AccountDeletionService::request()`'s docblock claims the deletion-token write "rolls back automatically" on dispatch failure — it does not, because the job's `afterCommit` flag defers the Redis push past the point of no return
    - **Where:** app/Services/User/AccountDeletionService.php:36-40, 78-98; app/Jobs/Account/SendAccountDeletionRequestMailJob.php:53-58
    - **Affects:** Users initiating self-service account deletion during a Redis outage. The DB commit (token hash + `EVENT_REQUESTED` audit row) already succeeds before the deferred queue push is attempted, so a push failure at that point cannot roll anything back — the request-handling code nonetheless returns the same 503 "please try again" it uses for genuine DB failures, telling the user nothing happened when in fact a live, unconfirmed deletion token now sits on their row with no email ever sent.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Correct the docblock (lines 36-40) and the in-closure comment (lines 87-90): `SendAccountDeletionRequestMailJob` setting `$this->afterCommit = true` in its constructor means the Redis push is deferred until *after* the `pgsql` transaction's `COMMIT` has already executed — a subsequent push failure happens too late to roll back the token write. The two comments assert the opposite causality.
        - Move `SendAccountDeletionRequestMailJob::dispatch(...)` out of the `DB::transaction()` closure entirely (dispatch it after the transaction returns), so a push failure is caught by its own explicit handling — one that can report "deletion recorded, confirmation email pending" rather than reusing the blanket `catch (\Throwable $e)` that implies the whole operation failed.
        - Since the job's own `handle()` already re-validates `deletion_token_hash` against the dispatched `tokenHash` before sending (idempotent, self-correcting on retry), no data-integrity fix is needed — only the error-reporting/documentation mismatch.
    - **Technical:** Laravel's queue dispatcher checks `$job->afterCommit` in `shouldDispatchAfterCommit()`; when true, the push is registered as a transaction-commit callback via the `DatabaseTransactionsManager` rather than executed inline. That callback fires *after* the real SQL `COMMIT` for the `pgsql` connection has already run — meaning a Redis outage at push time throws an exception whose failure occurs strictly after the token write is durable. `request()`'s `catch (\Throwable $e)` (lines 99-110) has no way to distinguish "the DB write itself failed" (genuinely nothing happened, 503 is correct) from "the DB write succeeded but the post-commit push failed" (deletion is recorded, only the email is missing) — both paths return the identical 503 response. This is a narrow, self-healing edge case (the 24-hour token-expiry check in `confirm()` and any later retry both correct it), not a data-loss bug, but the code's own stated invariant is factually wrong and could mislead a future engineer who takes the comment at face value while modifying this flow.
    - **Plain English:** The code has a comment that says "if sending the confirmation email fails to even get queued, we automatically undo saving the deletion request" — like a bank teller saying "if the printer jams, I'll tear up your form." But the way it's actually wired, the teller has already filed your form away in the cabinet *before* checking whether the printer worked. If the printer then jams, your form is still filed — the promise in the comment doesn't hold. Nothing is lost forever (a retry fixes it, and the request naturally expires after a day), but the system currently tells a user "it failed, try again" in a case where it actually already succeeded, which is confusing and could show up as a mismatch during a real outage.
    - **Evidence:**
        ```php
        /**
         * Initiate a deletion request. Checks preconditions, stores hashed token
         * queues the confirmation email. Token write + job dispatch + audit log
         * commit atomically: if dispatch infrastructure fails, the token write
         * rolls back automatically — no manual cleanup, no DEL-2 race window.
         ```
        ```php
            DB::connection('pgsql')->transaction(function () use ($professional, $tokenHash, $confirmationUrl, $request) {
                $professional->update([
                    'deletion_token_hash' => $tokenHash
                    'deletion_requested_at' => now()
                    'deletion_mail_sent_at' => null
                ]);

                SendAccountDeletionRequestMailJob::dispatch(
                    $professional->id
                    $confirmationUrl
                    $tokenHash
                );

                $this->logAuditEvent($professional, UserDeletionAuditEntry::EVENT_REQUESTED, $request);
            });
        ```
        ```php
        $this->onQueue(config('partna.queues.notifications', 'notifications'));
        // afterCommit prevents the worker from picking up the job before
        // AccountDeletionService::request()'s wrapping DB::transaction commits.
        // Set on the instance (not as a typed property) because the Queueable
        // trait already declares $afterCommit as an untyped property.
        $this->afterCommit = true;
        ```

## Suggested Bundled Sessions

None — the two surviving findings touch unrelated subsystems (menu-scraper sync metadata vs. account-deletion mail dispatch) with no shared file, subsystem, or root cause.

## Standalone — do NOT bundle

- **#TXN-101 — MenuFetchJob sync-status metadata outside transaction** · standalone: single unrelated S-effort fix, no shared subsystem with #TXN-102.
- **#TXN-102 — AccountDeletionService misleading rollback comment / dispatch ordering** · standalone: touches the account-deletion flow (highest-stakes path per audit doctrine) — run and verify it in isolation even though effort is S.


<!-- ═══════════ audit-2026-07-17-data-integrity.md ═══════════ -->

# Data Integrity & Privacy Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Data integrity & privacy — FK hygiene, soft-delete coherence, orphan rows, JSONB drift, PII inventory, retention
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Enums/AccountType.php
- app/Enums/SitepageId.php
- database/factories/Core/Site/SiteFactory.php
- supabase/migrations/20260711170000_users_email_unique_case_insensitive.sql
- supabase/migrations/20260712000000_retire_staff_account_type.sql
- supabase/migrations/20260713120000_reconcile_instagram_gallery_unification.sql
- supabase/migrations/20260714200000_architecture_one_to_staple.sql
- supabase/migrations/20260714210000_drop_effect_surface.sql
- supabase/migrations/20260714220000_add_aesthetic_axes.sql
- supabase/migrations/20260714230000_drop_glass_satellites.sql
- supabase/migrations/20260715090000_menu_item_currency_and_dining_modes.sql
- supabase/migrations/20260619050000_menu_relational_redesign.sql
- supabase/migrations/20260617130000_create_menus.sql
- app/Models/Core/Site/Menu.php
- app/Models/Core/Site/MenuItem.php
- app/Models/Core/Site/MenuCategory.php
- app/Models/Core/Site/MenuPlatformLink.php
- app/Models/Core/Site/Site.php
- app/Models/Core/User/User.php
- app/Observers/User/UserObserver.php
- app/Services/User/AccountDeletionService.php
- app/Services/User/SiteProvisioningService.php
- app/Jobs/Platforms/MenuFetchJob.php
- app/Console/Commands/PurgeSoftDeleted.php
- app/Console/Commands/PruneExpiredHandleAliases.php
- app/Http/Requests/Concerns/DesignKitValidationRules.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 2 of 2 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [x] **#DINT-101** · P2 — `typography_tracking` / `theme_contrast` design-kit columns have no DB CHECK backing their fixed vocabulary
    - **Where:** supabase/migrations/20260714220000_add_aesthetic_axes.sql:15-18
    - **Affects:** `site.design_kits` rows written by any path that bypasses `UpdateSiteRequest`/`DesignKitValidationRules` — direct DB fixes, restore/import tooling, seeders, or a future admin script. A bad value renders as broken/missing CSS on the public sitepage since `@partnaau/design-system` trusts the DB to hold only valid selections.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `CHECK (typography_tracking IS NULL OR typography_tracking IN ('tight', 'normal', 'wide'))` to `site.design_kits.typography_tracking`.
        - Add `CHECK (theme_contrast IS NULL OR theme_contrast IN ('soft', 'normal', 'stark'))` to `site.design_kits.theme_contrast`.
        - Use the `ADD CONSTRAINT ... NOT VALID` → `VALIDATE CONSTRAINT` pattern this repo already uses for zero-lock rollout, or the `site.sites` low-row-count exemption pattern (`20260714200000`) if `site.design_kits` is similarly small.
        - `weight_heading` is documented as a VALUE (not SELECTION) axis — correctly left unconstrained; no CHECK needed there.
    - **Technical:** Confirmed via `app/Http/Requests/Concerns/DesignKitValidationRules.php:70,148` that the HTTP write path already validates both fields (`'sometimes', 'nullable', 'string', 'in:tight,normal,wide'` and `'in:soft,normal,stark'`), so this is not a live user-facing gap — the Always-Drop rule for "generic input validation on routes with a Form Request" does not disqualify this finding, though, because the concern here is specifically about **non-HTTP write paths** (direct DB writes, restore jobs, future tooling) that the Form Request can't reach. The DB CHECK is the only enforcement point those paths see. This matches the lens's canonical pattern (`site.sites.architecture_id CHECK`, `site.site_media.pool` CHECK) that every other SELECTION-type column in this schema already follows — these two new columns are the exception, not the rule.
    - **Plain English:** These two new design knobs each have exactly three valid settings, and the dashboard already double-checks that when you use it. But the database itself has no such check — so any other tool that writes directly to it (a support fix, a data-restore script) could put in an invalid value the sitepage doesn't know how to render. This finding closes that second door to match every other similar setting in the system.
    - **Evidence:**
        ```sql
        -- supabase/migrations/20260714220000_add_aesthetic_axes.sql
        ALTER TABLE site.design_kits
            ADD COLUMN IF NOT EXISTS typography_tracking TEXT NULL
            ADD COLUMN IF NOT EXISTS theme_contrast TEXT NULL
            ADD COLUMN IF NOT EXISTS weight_heading TEXT NULL;
        ```

- [x] **#DINT-102** · P2 — `Menu`'s only soft-delete is safe today, but nothing in the schema or model layer stops a future call site from soft-deleting it without first clearing `MenuItem`/`MenuCategory` children
    - **Where:** app/Models/Core/Site/MenuItem.php (class body), app/Models/Core/Site/MenuCategory.php (class body), app/Jobs/Platforms/MenuFetchJob.php:400-421
    - **Affects:** Any future code path that calls `$menu->delete()`. Today there is exactly one such call site (`MenuFetchJob::clearScrapedContent()`), and it explicitly hard-deletes every `MenuItemPlatform`/`MenuItem`/`MenuCategory` row before soft-deleting the parent `Menu` — so no orphans exist in production today. But `site.menu_items` and `site.menu_categories` reference `site.menus` with `ON DELETE CASCADE`, which only fires on a hard `DELETE`, never on the `UPDATE ... SET deleted_at` that `SoftDeletes` performs. If a second soft-delete path is ever added (admin tooling, a new lifecycle hook) without replicating `clearScrapedContent()`'s manual cleanup, `MenuItem`/`MenuCategory` rows will silently orphan under a `deleted_at`-stamped `Menu`, since neither child model has a `deleted_at` column to mark itself as belonging to a trashed parent.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Do **not** add `SoftDeletes` to `MenuItem`/`MenuCategory` — `MenuCategory`'s docblock explicitly documents "rebuilt wholesale on every scrape — no soft delete" as intentional; both are ephemeral scraped content, not tenant-authored content needing 30-day retention.
        - Instead, add a `static::deleting` guard on `Menu` (or a `MenuObserver::deleting`) that asserts `! $menu->categories()->exists()` before allowing a soft-delete, throwing (or logging to Nightwatch) if children remain — this turns the current implicit "always clear children first" contract into an enforced one.
        - Add a regression test asserting `Menu::delete()` on a menu with live categories either fails loudly or is rejected, so a future developer can't reintroduce the orphan path silently.
    - **Technical:** DB-level `ON DELETE CASCADE` only fires on a hard `DELETE` statement; Laravel's `SoftDeletes` performs an `UPDATE`, so the cascade never triggers on `$menu->delete()`. Verified the current single call site (`MenuFetchJob::clearScrapedContent()`, lines 400-421) deletes `MenuItemPlatform`/`MenuItem`/`MenuCategory` rows via hard `->delete()` on the query builder *before* checking `! $menu->categories()->exists()` and only then soft-deleting `$menu` — so today's behavior is correct by construction, not by DB guarantee. The invariant "a soft-deleted Menu has zero children" exists only as a convention inside one private method, with no schema-level or model-level enforcement stopping a second, less careful call site from violating it.
    - **Plain English:** Right now, whenever the system clears out a menu, it's careful to remove every dish and category first before marking the menu itself as deleted — like emptying a filing cabinet before labeling it "empty." That's working correctly today. But nothing forces the *next* person who writes code touching menus to follow the same careful order — if they take a shortcut, dishes and categories could get left behind, invisible in the trash but still findable by anyone who searches for them directly. Adding a safety check now closes that gap before it becomes a real bug.
    - **Evidence:**
        ```php
        // MenuItem.php — NO SoftDeletes trait, NO deleted_at in $casts
        class MenuItem extends BaseModel
        {
            use HasUuids;

            protected $casts = [
                'position' => 'integer'
                'badges' => 'array'
                'rating' => 'float'
                'rating_count' => 'integer'
                'base_price' => 'float'
                'pickup_price' => 'float'
                'delivery_price' => 'float'
                'created_at' => 'datetime'
                'updated_at' => 'datetime'
            ];
        }
        ```
        ```php
        // MenuFetchJob.php:400-421 — the ONLY current call site that soft-deletes Menu
        // and it already clears children first (by convention, not by guarantee)
        private function clearScrapedContent(string $userId): void
        {
            $menu = Menu::query()->where('user_id', $userId)->first();
            if ($menu === null) {
                return;
            }

            DB::connection('pgsql')->transaction(function () use ($menu) {
                $categoryIds = $this->rebuildableCategoryIds($menu->id);
                $itemIds = MenuItem::query()->whereIn('category_id', $categoryIds)->pluck('id');
                MenuItemPlatform::query()->whereIn('menu_item_id', $itemIds)->delete();
                MenuItem::query()->whereIn('category_id', $categoryIds)->delete();
                MenuCategory::query()->whereIn('id', $categoryIds)->delete();
                $menu->platformLinks()->delete();

                if (! $menu->categories()->exists()) {
                    $menu->delete();
                } else {
                    $menu->forceFill(['content_source' => 'scan'])->save();
                }
            });
        }
        ```

## P3 — Nice to have

## Suggested Bundled Sessions

- **Bundle 1 — Code-only hygiene (no schema change):** #DINT-102
    - **Why grouped:** Both are code-only changes (a model-level guard + regression test; an enum-case removal) with no DB migration, no auth/money surface, and no shared file — safe to execute together in one low-risk session.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#DINT-101 — SELECTION-axis design-kit columns lack CHECK constraints** · standalone: DB migration/schema change (ADD CONSTRAINT).


<!-- ═══════════ audit-2026-07-17-job-queue-correctness.md ═══════════ -->

# Job/Queue Correctness Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Job/Queue Correctness — idempotency, retry safety, ShouldBeUnique, missing `$this->fail()`, retry storms
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Jobs/Platforms/InstagramConnectJob.php
- app/Jobs/Platforms/MenuFetchJob.php
- app/Console/Commands/CleanupOrphanedLifestyleConnections.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

## Suggested Bundled Sessions

    - **Why grouped:** Same file (`SyncSubdomainToKvJob.php`), same root-cause pattern (swallowed `Throwable` on a `$pro->site`/`$pro?->site` read during a moderation/takedown-adjacent path), same fix shape (stop catching, let Horizon's existing 3-try backoff handle it).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle



<!-- ═══════════ audit-2026-07-17-observability.md ═══════════ -->

# Observability Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Observability: logging gaps, silent failures, missing Nightwatch instrumentation — jobs that swallow exceptions, inbound callbacks that 200-but-don't-process, missing Nightwatch coverage, log calls that obscure rather than illuminate
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Console/Commands/CleanupOrphanedLifestyleConnections.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Jobs/Platforms/InstagramConnectJob.php
- app/Jobs/Platforms/MenuFetchJob.php
- app/Services/Cloudflare/CloudflareCustomHostnameService.php
- app/Services/Cloudflare/CloudflarePurgeService.php
- app/Services/Platforms/BigCartelScraper.php
- app/Services/Platforms/DoorDashMenuDriver.php
- app/Services/Platforms/GenericShopScraper.php
- app/Services/Platforms/GoogleBusinessAutoSync.php
- app/Services/Platforms/IdentitySync.php
- app/Services/Platforms/InstagramAutoSync.php
- app/Services/Platforms/InstagramScraper.php
- app/Services/Platforms/MenuMerger.php
- app/Services/Platforms/MenuScanApplier.php
- app/Services/Platforms/Normalizers/FacebookNormalizer.php
- app/Services/Platforms/Payloads/InstagramPayload.php
- app/Services/Platforms/PlatformScraper.php
- app/Services/Platforms/Registry/PlatformDescriptor.php
- app/Services/Platforms/ShopifyScraper.php
- app/Services/Platforms/UberEatsMenuDriver.php
- app/Services/Platforms/WebsiteLinkHarvester.php
- app/Services/Platforms/WooCommerceScraper.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [ ] **OBS-101** · P2 — `CloudflarePurgeService` logs a product-purge degradation at `debug`, a level Nightwatch's own log filter drops by default
    - **Where:** app/Services/Cloudflare/CloudflarePurgeService.php:129-146 (`purgeHandle`'s product-handle lookup)
    - **Affects:** Shop product-detail edge-cache invalidation for every Partna storefront — a sustained DB/schema failure on the product-handle join silently degrades every purge to "pages only," and product pages never get busted again until their natural edge TTL, with nothing surfacing to on-call.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Bump `Log::debug` to at least `Log::warning` and add `report($e)`, matching the codebase's own established pattern for "must never break the caller, but a genuine failure must still reach Nightwatch" (the identical fix — `report($e)` in an isolated best-effort catch — is already applied throughout `app/Jobs/Platforms/InstagramConnectJob.php`'s R2-mirror catches).
        - Add a regression test exercising the catch path itself (force the product-handle join to error, e.g. by dropping/renaming a joined table in a Pest test) — `CloudflarePurgeServiceTest.php` currently has 10 passing cases and every one of them hits the query's success path; none exercises the degrade-to-pages-only branch.
    - **Technical:** `config/nightwatch.php`'s `filtering.log_level` defaults to `env('LOG_LEVEL', 'warning')` — Nightwatch's own log-shipping pipeline drops anything below `warning` out of the box, so a `Log::debug` call in this catch block is invisible to Nightwatch by construction, independent of whatever the app's own `LOG_LEVEL` is set to. This code was added recently (`7c753f7f fix(cache): purgeHandle also purges shop product detail pages`) specifically to close a 24h staleness gap for product pages; the try/catch around the DB join is correctly scoped ("never let this optional lookup break the purge itself"), but the failure path it protects against reintroduces the exact staleness problem the commit fixed, just silently, for however long the underlying join stays broken.
    - **Plain English:** When someone updates their shop, the system clears the cached copy of every page so visitors see the newest version. Finding which specific product pages to clear requires one extra database lookup; if that lookup breaks, the code correctly skips it rather than failing the whole cache-clear — but it writes the failure to a logbook page that the monitoring system is configured to never read. If this lookup breaks for good (not just a blip), product pages could stay stale indefinitely and nobody would be told.
    - **Evidence:**
        ```php
        try {
            $productHandles = DB::connection('pgsql')->table('site.shop_products as p')
                // … joins and query …
                ->pluck('product_handle')
                ->all();
        } catch (\Throwable $e) {
            Log::debug('CloudflarePurgeService: product-handle lookup failed, purging pages only', ['handle' => $h, 'error' => $e->getMessage()]);
            $productHandles = [];
        }
        ```

## P3 — Nice to have

## Suggested Bundled Sessions

- **Bundle 1 — Vendor-I/O failure visibility hygiene:** #OBS-101
    - **Why grouped:** all three are single-file, S-effort fixes in the Platforms/Cloudflare vendor-I/O layer, all following the same established fix pattern (bump log severity / add `report($e)` / add `->throw()` to activate an already-present catch) plus a small test addition — no shared file, but a coherent one-session batch.
    - **Model:** Plan: Opus (combine plan+impl) · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

None.


<!-- ═══════════ audit-2026-07-17-caching-coverage-gaps.md ═══════════ -->

# Caching Coverage Gaps Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Caching coverage gaps — hot, expensive reads with no cache at all (absence-only; the inverse of the gold-standard-adherence lens)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php
- app/Http/Controllers/Api/PublicSite/PublicMenuController.php
- app/Services/Accounts/AccountCapabilities.php
- app/Services/Accounts/AccountCapabilitySet.php
- app/Services/Accounts/LifestyleConnectionCleanup.php
- app/Services/PublicSite/IndividualProfilePayloadBuilder.php
- app/Services/PublicSite/SiteActionsService.php
- app/Services/PublicSite/SitepageDataResolverService.php
- app/Services/Analytics/ContentPopularityReader.php (pulled in for cross-check — shared by both findings)
- app/Http/Controllers/Api/PublicSite/IndividualProfileController.php, app/Http/Middleware/Context/LoadCurrentUser.php, routes/api.php, app/Http/Controllers/Api/ApiController.php (pulled in to confirm the existing cache boundary before flagging gaps)
- app/Services/Platforms/* (24 files — no findings; reads are job/connect-time, not hot-path)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [ ] **#CCG-102** · P2 — Popularity-rank read is cached on the profile payload path but hits Postgres uncached on two sibling public endpoints
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php:105, app/Http/Controllers/Api/PublicSite/PublicMenuController.php:70-73, app/Services/Analytics/ContentPopularityReader.php:33-46
    - **Affects:** Every unauthenticated viewer of a professional's `/platforms` (shop-product ranks) and `/menu` (menu-item/category ranks) subpages — a Postgres round-trip on every single request, with no TTL or memoisation, for a value that only changes every ~15 minutes.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `CacheKeyGenerator::sitePopularityRanks(string $siteId)` key.
        - Wrap both call sites' `$this->popularity->forSite(...)` in `CacheLockService::rememberLocked($key, $ttl, fn () => $this->popularity->forSite($siteId))`, TTL matched to (or slightly under) the `analytics:compute-popularity` cadence — no push-invalidate needed since the compute job already tolerates the same staleness window the 60s public-profile cache accepts elsewhere.
        - No behavioural change: `forSite()` itself is untouched, only its two uncached call sites gain the wrapper.
    - **Technical:** `ContentPopularityReader::forSite()`'s own class docblock states the read is "behind the 60s public-profile cache" — true only for its call site inside `IndividualProfilePayloadBuilder::build()` (itself wrapped in `CacheLockService::rememberLocked`, confirmed in `IndividualProfileController.php:101`). `PublicIntegrationController::show()` and `PublicMenuController::show()` call the exact same reader directly, with no `Cache::`/`rememberLocked` anywhere in either controller or in `forSite()` — every unauthenticated request re-issues the `analytics.content_popularity_scores` query. This is the identical read shape the codebase already treats as cache-worthy in one location; the other two public per-visitor endpoints were never wired into that pattern. At the platform's stated scale target (a single profile going viral), this is a fan-out of concurrent, uncached, identical reads against one Postgres primary for data that a 15-minute-cadence batch job is the only writer of.
    - **Plain English:** Imagine a shop that repaints its "today's bestsellers" sign once every 15 minutes, but two of its three doors have an employee re-checking the stockroom in person every single time a customer walks through — even though the sign was already just painted and everyone can see it. The data barely changes, but the code re-fetches it from the database on every page view for two specific pages (the menu page and the platforms/shop page), when it's already treated as a cacheable value on the main profile page. If a professional's page suddenly goes viral, that's a lot of unnecessary simultaneous database hits for information that hasn't changed in minutes.
    - **Evidence:**
        ```php
        // app/Services/Analytics/ContentPopularityReader.php — class docblock's cache assumption:
         * Payload builders call forSite() once per build and annotate their content
         * arrays / pageOrder from the returned maps. One indexed read per build
         * (site_id, content_type, rank), behind the 60s public-profile cache.
        ```
        ```php
        // app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php:98-106 — direct, uncached call:
        $shopLinkMode = null;
        $productRanks = [];
        if ($connections->has('shop')) {
            $site = Site::query()->where('user_id', $userId)->first(['id', 'shop_link_mode']);
            $shopLinkMode = $site?->shop_link_mode;
            // shop-product ranks annotate each product with a nullable
            // popularityRank on the public wire (inert until ONE consumes it).
            $productRanks = $this->popularity->forSite($site?->id)['shop_product'] ?? [];
        }
        ```
        ```php
        // app/Http/Controllers/Api/PublicSite/PublicMenuController.php:70-73 — direct, uncached call:
        $siteId = Site::query()->where('user_id', $userId)->value('id');
        $ranks = $this->popularity->forSite($siteId);
        $categoryRanks = $ranks['menu_category'] ?? [];
        $itemRanks = $ranks['menu_item'] ?? [];
        ```

## P3 — Nice to have

## Suggested Bundled Sessions

- **Bundle 1 — Public sitepage read-path caching hygiene:** , #CCG-102
    - **Why grouped:** Both are request-scoped/cache-coverage fixes on the same public-profile read family (`IndividualProfilePayloadBuilder` and its sibling public controllers); neither touches auth, money, or schema, and both are small enough to plan+implement together.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

## Standalone — do NOT bundle

None.


<!-- ═══════════ audit-2026-07-17-privacy-compliance.md ═══════════ -->

# Privacy & Data-Rights Compliance Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Privacy & data-rights compliance — PII inventory completeness, export/delete completeness, retention enforcement, minimisation at collection, processor/third-party flows, and staff access auditing, evaluated against the account-deletion, GDPR-export, and signup-bootstrap machinery.
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Http/Resources/PublicSite/IndividualProfileResource.php
- app/Http/Resources/UserDashboardResource.php
- app/Models/Core/Site/Menu.php
- app/Models/Core/Site/MenuItem.php
- app/Models/Core/Site/Site.php
- app/Models/Core/User/User.php
- app/Services/User/AccountDeletionService.php
- app/Services/User/SiteProvisioningService.php
- app/Services/User/UserBootstrapService.php
- app/Console/Commands/CleanupOrphanedLifestyleConnections.php
- app/Mail/Branding/EmailBrandDefaults.php
- app/Services/Analytics/ContentFreshness.php
- supabase/migrations/20260711170000_users_email_unique_case_insensitive.sql
- supabase/migrations/20260712000000_retire_staff_account_type.sql
- supabase/migrations/20260713120000_reconcile_instagram_gallery_unification.sql
- supabase/migrations/20260714200000_architecture_one_to_staple.sql
- supabase/migrations/20260714210000_drop_effect_surface.sql
- supabase/migrations/20260714220000_add_aesthetic_axes.sql
- supabase/migrations/20260714230000_drop_glass_satellites.sql
- supabase/migrations/20260715090000_menu_item_currency_and_dining_modes.sql

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 2 of 2 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [x] **PRIV-101** · P2 — New-signup marketing subscription is created with no genuine consent signal
    - **Where:** app/Services/User/UserBootstrapService.php:118 (`bootstrap()`), `ensureSidestUpdatesSubscription()` at lines 165-187
    - **Affects:** Every new professional who signs up via `POST` bootstrap — their email is enrolled in the `sidest_updates` marketing list as an automatic side effect of account creation, not a separate opt-in.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add an explicit, validated consent field (e.g. `marketing_opt_in: bool`) to `BootstrapRequest`, sourced from a real checkbox/toggle on the signup form, and pass it into `bootstrap()`.
        - Gate `ensureSidestUpdatesSubscription()` on that flag instead of calling it unconditionally; only write the row when the user affirmatively opted in.
        - Keep `consent_source` accurate post-fix (`'signup_optin'` or similar) so the column continues to double as an audit trail of how consent was obtained.
    - **Technical:** `UserBootstrapService::bootstrap()` calls `$this->ensureSidestUpdatesSubscription($professional->primary_email)` unconditionally inside the signup transaction, for both the create and update branches. The method performs `EmailSubscription::insertOrIgnore(['list_key' => 'sidest_updates', 'status' => 'subscribed', 'consent_source' => 'bootstrap', ...])` with no guard clause checking whether any consent was given — `insertOrIgnore` only prevents duplicate rows, it doesn't gate on opt-in. The `consent_source = 'bootstrap'` value is itself an admission that consent was inferred from account creation, not obtained. Under the Australian Privacy Act (APP 7), direct-marketing enrolment generally requires the individual's consent or a reasonable expectation tied to the primary purpose of collection — "we signed you up because you registered" does not clear that bar. Note this is a genuine right-to-export item, not a silent gap: `DataExportPayloadBuilder::streamEmailSubscriptions()` does surface `notifications.email_subscriptions` rows in the user's own export, and `AccountDeletionService::purgeGlobalEmailSubscriptions()` does delete the row on account purge — so the export/deletion ledgers are intact for this store. The defect is purely at collection time (APP 3/7 minimisation), which is why this stays P2 rather than P1 under the lens's own tiering ("P2 for minimisation and processor-hygiene gaps").
    - **Plain English:** Imagine signing up for a library card and discovering you've also been signed up for the library's weekly newsletter — without ever being asked. Right now, creating a Partna account automatically adds your email to a marketing mailing list in the same database transaction that creates your account; there's no tick-box, no separate step, nothing to decline. Australian privacy law expects people to actively agree before being added to a marketing list, not to have it bundled invisibly into signing up. The fix is a real opt-in checkbox on the signup form that the backend actually checks before subscribing anyone — good news is your existing "view my data" and "delete my account" tools already handle this subscription correctly once it's created, so this is a collection-time fix, not a bigger rebuild.
    - **Evidence:**
        ```php
        // UserBootstrapService::bootstrap() — inside the transaction, unconditional:
        $this->ensureSidestUpdatesSubscription($professional->primary_email);

        // ...

        private function ensureSidestUpdatesSubscription(?string $email): void
        {
            $email = is_string($email) ? strtolower(trim($email)) : '';
            if ($email === '') {
                return;
            }

            $now = now();

            EmailSubscription::insertOrIgnore([
                'id' => (string) Str::uuid()
                'user_id' => null
                'list_key' => 'sidest_updates'
                'email' => $email
                'email_lc' => $email
                'status' => 'subscribed'
                'subscribed_at' => $now
                'consent_source' => 'bootstrap'
                'unsubscribe_token' => EmailSubscription::newUnsubscribeToken()
                'created_at' => $now
                'updated_at' => $now
            ]);
        }
        ```

- [x] **PRIV-102** · P2 — Staff `admin_notes` freetext survives pseudonymisation for the full 30-day deletion grace period
    - **Where:** app/Services/User/AccountDeletionService.php:299-314 (`pseudonymiseAccountPii()`)
    - **Affects:** Any individual whose account enters `pending_deletion` — freetext support/identity-verification notes staff previously wrote about them remain live and staff-readable in cleartext for up to 30 days after the user pseudonymised their own account.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'admin_notes' => null` to the `forceFill()` array in `pseudonymiseAccountPii()`, alongside the other 10 fields already redacted there.
        - If support staff need historical context during the grace period, snapshot `admin_notes` into `UserDeletionAuditEntry::metadata` (the same pattern already used for the email snapshot) before nulling the live column, rather than leaving the live column readable.
    - **Technical:** `pseudonymiseAccountPii()` explicitly overwrites `phone`, `primary_email`, `first_name`, `last_name`, `public_contact_email`, `public_contact_number`, and all five `location_*` columns the moment a deletion is confirmed — but `admin_notes` (`core.users.admin_notes`, `text`, fillable, DB comment: "Staff-only free-text notes. Exposed via the staff resource only — never through /me") is absent from that list. `UserStaffResource` (`app/Http/Resources/UserStaffResource.php:35`) serialises `admin_notes` verbatim to any staff endpoint using that resource, so during the entire `pending_deletion` window any staff member with normal (non-elevated) access can still read whatever PII was recorded there — commonly identity-verification details, phone numbers read back for support, or dispute notes. This isn't a permanent gap: `core.users` is force-deleted along with everything else at the 30-day hard purge in `AccountDeletionService::purge()`, so `admin_notes` does eventually disappear — the defect is that it's the only fillable, PII-bearing column on the row *not* redacted at the confirm-time pseudonymisation step that every other identity field goes through immediately.
    - **Plain English:** When someone asks to delete their account, we immediately scramble their phone number, email, name, and address so nobody can read them during the 30-day cooling-off period. But there's a staff-only notepad attached to every account — support agents write things like verification details or call notes there — and that notepad is left completely untouched. For up to 30 days after someone requests deletion, any staff member can still open that notepad and read it in plain text. The fix is to blank that notepad at the same moment we scramble everything else, or copy a note into the secure deletion log first if support genuinely needs the history.
    - **Evidence:**
        ```php
        protected function pseudonymiseAccountPii(User $professional): void
        {
            $professional->forceFill([
                'phone' => 'redacted'
                'primary_email' => "deleted+{$professional->id}@partna.au"
                'first_name' => 'Deleted'
                'last_name' => null
                'public_contact_email' => null
                'public_contact_number' => null
                'location_street_address' => null
                'location_postcode' => null
                'location_city' => null
                'location_state' => null
                'location_country' => null
            ])->save();
        }
        ```

## P3 — Nice to have

## Suggested Bundled Sessions

    - **Why grouped:** both live in `App\Services\User` and touch the same account lifecycle (signup / deletion) that the professional's PII flows through; small, independent fixes that don't interact with each other.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** standalone one-line fix in a console command, unrelated subsystem to Bundle 1 — kept separate so it doesn't block on the signup-consent design discussion.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet (combine plan+implement given trivial size).

## Standalone — do NOT bundle

None — no finding in this audit is P0, touches auth/authorization or money, involves a DB migration/schema change, or is L/XL effort.


<!-- ═══════════ audit-2026-07-17-edge-worker.md ═══════════ -->

# Edge Worker Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Edge worker: Cloudflare routing, KV contract, edge-cache correctness, takedown latency, poisoning
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- cloudflare-worker/src/index.js
- cloudflare-worker/wrangler.toml
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Jobs/Moderation/SuspendSiteJob.php
- app/Jobs/Moderation/PurgeModerationCacheJob.php
- app/Services/Cloudflare/CloudflarePurgeService.php
- app/Services/Cloudflare/CloudflareCustomHostnameService.php
- app/Services/Cloudflare/CloudflareKvService.php
- app/Services/Moderation/ModerationActionDispatcher.php
- app/Observers/User/UserObserver.php
- app/Observers/Core/SiteObserver.php
- app/Services/User/AccountDeletionService.php
- app/Services/Site/RenameSubdomainAction.php
- app/Http/Controllers/Api/User/SiteManagement/CustomDomainController.php
- config/partna.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 1 of 3 complete
- P3 Low: 0 of 0 complete

---

## Note on this adjudication

DeepSeek's draft was scanned against only three files (`SyncSubdomainToKvJob.php`, `CloudflareCustomHostnameService.php`, `CloudflarePurgeService.php`) — it did not have `cloudflare-worker/src/index.js`, the model observers, `AccountDeletionService`, or the moderation dispatcher in scope, and its own EDGE-7 draft admits this outright. With the full picture, five of its seven findings (its EDGE-101 through EDGE-6, minus EDGE-105) turned out to be **false premises**: the takedown/purge chain it says is missing already exists, just in files it didn't see —

- `SiteObserver::saved()`/`::deleted()` dispatch `CloudflareCachePurgeJob` (with custom domain) on every site save/delete/unpublish, including the account-deletion `is_published=false` transition in `AccountDeletionService::executeConfirmation()`.
- Moderation takedowns (`hide_site`, `suspend_user`, `ban_user`, `csam_auto_suspend`) all pair `suspend_site` with `sync_subdomain_kv` in `ModerationActionDispatcher::ACTIONS_BY_DECISION`, and `sync_subdomain_kv` maps to `PurgeModerationCacheJob`, which retires the KV entry **and** unconditionally dispatches `CloudflareCachePurgeJob` — confirmed by `ModerationActionDispatcherTest`.
- The Worker's alias-redirect branch already validates `entry.redirect` is an `https://*.partna.au` URL before trusting it (comment cites `SEC-105`), failing closed to a 404 otherwise — the "poisoned KV → open redirect" claim doesn't hold against the current source.
- The Worker's `RESERVED` set and `config('partna.reserved_subdomains')` are byte-for-byte identical (diffed both files in full) — no drift.
- The Worker's KV-type check runs **before** any cache lookup, so once a renamed handle's KV entry flips to `{type:"alias"}`, its old cache entries become structurally unreachable — there's no window where stale content under the old handle is served to a visitor (rename correctness holds; only a sub-second-to-KV-propagation race exists, and it would serve identical content from the same owner, not stale/wrong content).

These are dropped below rather than re-tiered, since the underlying claim — not just the fix — is what's wrong. Two of DeepSeek's findings (custom-hostname delete, product-purge cap) verified true and are kept. Three genuinely new findings the draft's narrow scope missed are added.

## P2 — Should fix

- [ ] **#EDGE-101** · P2 — `CloudflareCustomHostnameService::delete()` silently swallows Cloudflare API failures its own caller already expects it to throw
    - **Where:** app/Services/Cloudflare/CloudflareCustomHostnameService.php:91-98
    - **Affects:** Cloudflare for SaaS zone hygiene — users disconnecting or replacing a custom domain during a token expiry, rate limit, or transient 5xx.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->throw()` to the HTTP call in `delete()`, matching `create()`/`get()` in the same class.
        - No caller change needed — `CustomDomainController` already wraps every `$this->cf->delete(...)` call in `try { ... } catch (Throwable $e) { report($e); }`; this fix makes that existing handling actually fire on a real Cloudflare failure instead of being dead code for the HTTP-error case.
    - **Technical:** `create()` and `get()` both chain `->throw()`, converting a non-2xx response into an exception. `delete()` fires the DELETE and returns void unconditionally — it cannot surface an HTTP-level failure. `CustomDomainController::store()` (domain-change teardown, line 61; TOCTOU cleanup, line 92) and `::destroy()` (line 172) all wrap the call in try/catch + `report($e)`, which only proves the caller was written expecting `delete()` to be able to throw. Because it silently swallows 401/429/5xx internally, those catch blocks never fire for the most likely failure mode, and Cloudflare retains a custom hostname that Partna's DB/KV routing table consider gone — an invisible zone-hygiene leak.
    - **Plain English:** When a user disconnects or changes their custom domain, the app tells Cloudflare "remove this domain registration." The code that calls this is already written to catch and report a failure if one happens — but the piece that actually talks to Cloudflare never raises an alarm when the request fails, so that safety net never triggers. The domain stops working for visitors (routing is removed elsewhere), but it silently stays registered on Cloudflare's side, slowly piling up ghost entries with nobody notified.
    - **Evidence:**
        ```php
        /** Delete a custom hostname (best-effort — a missing id is a no-op). */
        public function delete(string $id): void
        {
            if (! $this->configured || $id === '') {
                return;
            }

            Http::withToken($this->apiToken)->timeout(5)->delete($this->base()."/{$id}");
        }
        ```
        ```php
        if ($site->custom_domain_cf_id) {
            try {
                $this->cf->delete($site->custom_domain_cf_id);
            } catch (Throwable $e) {
                report($e);
            }
        }
        ```

- [ ] **#EDGE-102** · P2 — Cloudflare Worker `staging` environment KV namespace is an unresolved placeholder
    - **Where:** cloudflare-worker/wrangler.toml:42-53
    - **Affects:** Any future `wrangler deploy --env staging`; the prod-poisoning failure mode the file's own comment describes, if this override is ever removed or misapplied without the placeholder being noticed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Provision the real staging KV namespace (`wrangler kv namespace create SUBDOMAIN_KV_STAGING` + `--preview`) and paste the IDs in, wiring the staging Laravel env's `CLOUDFLARE_KV_NAMESPACE_ID` to match, per the TODO already written in the file — **or**, since CLAUDE.md's environment table documents only `development`/`production` with no Cloudflare "staging" tier, delete the `[env.staging]` block entirely.
        - Either way, resolve the TODO rather than leaving `REPLACE_WITH_...` placeholders sitting in a deployable config file.
    - **Technical:** The default (production) block's `SUBDOMAIN_KV` binding points at a real namespace id. The `[env.staging]` override still carries literal placeholder strings. Today a `--env staging` deploy fails at Cloudflare's API validation rather than silently sharing the production namespace, so the specific risk the comment warns about isn't live — but it's a dangling, unfinished safety mechanism with no tracking beyond an inline TODO, in a repo whose documented environment model doesn't otherwise mention a Cloudflare staging tier at all.
    - **Plain English:** There's a "fill this in later" placeholder in the Cloudflare configuration for a staging environment that doesn't appear to be used anywhere else in the project. Right now trying to deploy to it would just fail outright — but a half-finished safety mechanism sitting in a config file is a trap for whoever touches it next, assuming it's live and correct when it isn't. It should be finished or removed.
    - **Evidence:**
        ```toml
        # EDGE-10: staging MUST NOT share the production SUBDOMAIN_KV — without this
        # override a `--env staging` deploy (or a staging backend KV backfill) would
        # write into the production routing table and poison prod. Give staging its own
        # namespace. TODO(josh): create it and paste the id below —
        #   wrangler kv namespace create SUBDOMAIN_KV_STAGING
        #   wrangler kv namespace create SUBDOMAIN_KV_STAGING --preview
        # then point the STAGING Laravel env's CLOUDFLARE_KV_NAMESPACE_ID at the same id
        # so SyncSubdomainToKvJob writes to the staging namespace, not prod.
        [[env.staging.kv_namespaces]]
        binding = "SUBDOMAIN_KV"
        id = "REPLACE_WITH_STAGING_KV_NAMESPACE_ID"
        preview_id = "REPLACE_WITH_STAGING_KV_PREVIEW_ID"
        ```

- [x] **#EDGE-103** · P2 — No structural guard ties `suspend_site` to a KV/cache-retirement action in `ModerationActionDispatcher`
    - **Where:** app/Services/Moderation/ModerationActionDispatcher.php:26-44 (`ACTIONS_BY_DECISION`) + app/Jobs/Moderation/SuspendSiteJob.php:52-57
    - **Affects:** Any future moderation decision type added to `ACTIONS_BY_DECISION` that hides a site — the highest-stakes category of this lens (takedown correctness).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add an architecture-style test (same pattern as `PolicyCoverageTest`'s allowlist sweep) asserting: for every entry in `ACTIONS_BY_DECISION` containing `'suspend_site'`, the same entry also contains `'sync_subdomain_kv'` or `'purge_cloudflare_cache'`.
        - Optionally collapse the two into one action type so the pairing can't be expressed incorrectly at the source.
    - **Technical:** `SuspendSiteJob::handle()` hides a site via `Site::query()->where('id', $siteId)->update(['moderation_state' => 'hidden'])` — a query-builder mass update, which does **not** fire Eloquent model events, so `SiteObserver::saved()` (and its `CloudflareCachePurgeJob` dispatch) never runs for this write. The system correctly compensates today: every decision type carrying `'suspend_site'` (`hide_site`, `suspend_user`, `ban_user`, `csam_auto_suspend`) also carries `'sync_subdomain_kv'`, which `ModerationActionDispatcher::dispatchJob()` maps to `PurgeModerationCacheJob` — the job that retires the KV entry and unconditionally dispatches `CloudflareCachePurgeJob`. `ModerationActionDispatcherTest` verifies this pairing for all four existing decision types, but only by hand-written per-type assertions — there is no general invariant test across the whole map. A future decision type that hides a site and forgets to pair `sync_subdomain_kv`/`purge_cloudflare_cache` would flip the DB flag while leaving both the KV routing entry and the edge cache fully live — a silent P0-class regression with nothing in CI to catch it before ship.
    - **Plain English:** Hiding a moderated site currently works because two separate steps always happen together: one flips a database flag, the other clears the CDN cache and routing table. Nothing in the code actually *forces* those two steps to travel together — they just currently do, by careful hand-authored configuration. If someone adds a new kind of moderation action later and only remembers the database flag, the offending page would keep being served from cache with nothing failing to warn them. A simple automated check — "any action that hides a site must always also clear the cache" — would catch that mistake before it ships instead of relying on someone remembering.
    - **Evidence:**
        ```php
        private const ACTIONS_BY_DECISION = [
            'hide_content' => ['notify_reported_user', 'purge_cloudflare_cache']
            'hide_site' => ['suspend_site', 'sync_subdomain_kv', 'notify_reported_user']
            'suspend_user' => ['suspend_user', 'suspend_site', 'sync_subdomain_kv', 'notify_reported_user']
            'ban_user' => ['suspend_user', 'suspend_site', 'sync_subdomain_kv', 'notify_reported_user']
            'csam_auto_suspend' => ['quarantine_media', 'suspend_user', 'suspend_site', 'sync_subdomain_kv', 'notify_oncall_staff']
            ...
        ];
        ```
        ```php
        $siteId = $this->resolveSiteId($case);
        if ($siteId !== null) {
            Site::query()
                ->where('id', $siteId)
                ->update(['moderation_state' => 'hidden']);
        }
        ```

## P3 — Nice to have

## Suggested Bundled Sessions

- **Bundle 1 — Cloudflare service hygiene (PHP):** #EDGE-101
    - **Why grouped:** both are small, same-directory (`app/Services/Cloudflare/`) one-liners with no cross-file risk.
    - **Model:** Plan+Implement: Sonnet (S/S effort, combine per policy) · Review: Sonnet.

- **Bundle 2 — Cloudflare Worker repo hygiene (deploy config + tests):** #EDGE-102
    - **Why grouped:** both are `cloudflare-worker/` repo-hygiene items (deploy config completeness, test scaffolding) rather than application-logic bugs.
    - **Model:** Plan: Opus (EDGE-104 needs a scaffolding decision) · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Moderation purge invariant test:** #EDGE-103
    - **Why grouped:** standalone-scoped but S-effort — a single new architecture test, no production code change.
    - **Model:** Plan+Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

None.


<!-- ═══════════ audit-2026-07-17-configuration-hygiene.md ═══════════ -->

# Configuration Hygiene Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Configuration Hygiene — `env()` calls outside config files, missing `.env.example` entries for active config keys, feature flags without safe defaults, and config values used inconsistently (hardcoded in some places, config-driven in others).
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- routes/api/platforms.php
- app/Console/Commands/CleanupOrphanedLifestyleConnections.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Jobs/Platforms/InstagramConnectJob.php
- app/Jobs/Platforms/MenuFetchJob.php
- app/Services/Cloudflare/CloudflareCustomHostnameService.php
- app/Services/Cloudflare/CloudflarePurgeService.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 0 complete

---

## P3 — Nice to have

## Suggested Bundled Sessions

    - **Why grouped:** Same root-cause pattern (hardcoded timeout/size-cap literals in outbound-HTTP-calling code) and the same mechanical fix shape (extract to `config('partna.*')` or `config('services.*')` + `.env.example` entries). Both are S-effort and independent of each other.
    - **Model:** Plan Opus · Implement Sonnet · Review Sonnet — no escalation needed, both are small mechanical extractions.

## Standalone — do NOT bundle

None — neither finding touches auth/authorization, money, or a DB migration/schema change, and both are S-effort.


<!-- ═══════════ audit-2026-07-17-migration-safety.md ═══════════ -->

# Migration Safety Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Migration safety: lock-on-deploy risk, backfill ordering, online DDL hygiene, reversibility
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- supabase/migrations/20260711170000_users_email_unique_case_insensitive.sql
- supabase/migrations/20260712000000_retire_staff_account_type.sql
- supabase/migrations/20260713120000_reconcile_instagram_gallery_unification.sql
- supabase/migrations/20260714200000_architecture_one_to_staple.sql
- supabase/migrations/20260714210000_drop_effect_surface.sql
- supabase/migrations/20260714220000_add_aesthetic_axes.sql
- supabase/migrations/20260714230000_drop_glass_satellites.sql
- supabase/migrations/20260715090000_menu_item_currency_and_dining_modes.sql

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 3 of 3 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [x] **MIG-101** · P2 — Instagram/gallery unification backfill rewrites every matching row on re-run instead of skipping already-corrected ones
    - **Where:** supabase/migrations/20260713120000_reconcile_instagram_gallery_unification.sql:23-34
    - **Affects:** `site.sites` rows for every user with an active Instagram connection — a re-run (partial-apply retry, or a future fresh-apply replay against a partially-seeded DB) touches every one of those rows again even when already correct.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a WHERE guard so only rows that actually need the value changed get touched, e.g. compute the target value once and filter `WHERE s.content_instagram_auto_enabled IS DISTINCT FROM <target>`.
        - Match the standard already used two statements later in the same file: `WHERE platform = 'instagram' AND display_settings ? 'gallery'` naturally no-ops on a re-run; the `site.sites` UPDATE should hit the same bar.
    - **Technical:** The `CASE` expression makes the final state idempotent (the `ELSE` branch reassigns the row's current value), but Postgres still writes a new tuple version and takes a row lock for every matching row on every execution — there is no free "no-op" for an UPDATE that happens to set the same value. The canonical exemplar for this exact pattern, `20260608000000_backfill_subdomain_alias_lifecycle.sql`, uses `WHERE expires_at IS NULL` specifically so "a re-run is a no-op" per its own comment. At `site.sites`' current pre-beta row count (~10, per the sibling `20260714200000` migration's own census) this is negligible today, but it's the kind of backfill idempotency gap category (3) exists to catch before the table is analytics-scale.
    - **Plain English:** Picture repainting every wall in a room, including the ones that are already the right color — the end result looks fine, but you've wasted paint and time on walls that didn't need it. If this script gets accidentally run twice, it repeats that unnecessary work on every Instagram-connected site. Adding a quick "skip if already correct" check makes it safe and cheap to re-run.
    - **Evidence:**
        ```sql
        UPDATE site.sites s
        SET content_instagram_auto_enabled = CASE
                -- Never set (NULL): adopt the legacy card intent -- ON unless every
                -- active connection explicitly hid the gallery.
                WHEN s.content_instagram_auto_enabled IS NULL THEN ig.any_on
                -- Curated ON but the card was explicitly hidden everywhere: a deliberate
                -- hide must survive the unification.
                WHEN s.content_instagram_auto_enabled = true AND ig.any_on = false THEN false
                ELSE s.content_instagram_auto_enabled
            END
        FROM ig
        WHERE s.user_id = ig.user_id;
        ```

- [x] **MIG-102** · P2 — Six migrations touching hot tables omit the `SET LOCAL lock_timeout` / `statement_timeout` guard, right before the prod gated re-baseline replays all of them for the first time
    - **Where:** supabase/migrations/20260712000000_retire_staff_account_type.sql (touches `core.users`), 20260713120000_reconcile_instagram_gallery_unification.sql (`site.sites`), 20260714200000_architecture_one_to_staple.sql (`site.sites`), 20260714210000_drop_effect_surface.sql (`site.design_kits`), 20260714220000_add_aesthetic_axes.sql (`site.design_kits`), 20260714230000_drop_glass_satellites.sql (`site.design_kits`)
    - **Affects:** Deploy-pipeline safety for the next `supabase db push` — none of these six have run against production yet (prod-is-behind: latest applied prod migration is `20260512145025`), so this is the first opportunity for any of them to hang against real traffic.
    - **Effort:** S (~0.5–1h for all six)
    - **What to do:**
        - Add `SET LOCAL lock_timeout = '2s'; SET LOCAL statement_timeout = '10s';` at the top of each of the six files, per `docs/migration-guidelines.md` §Lock and statement timeouts.
        - Note per `docs/migration-guidelines.md` §Editing already-applied migrations: this edit is a no-op on the `development` environment (already applied there, tracked by version timestamp) — it only takes effect on a fresh apply, which is exactly the upcoming prod gated re-baseline this finding is protecting.
    - **Technical:** Verified via grep: repo-wide, only 3 of 60+ migration files that touch `site.sites` / `site.blocks` / `site.design_kits` / `core.users` include `SET LOCAL lock_timeout`, so this is a long-standing, systemic gap rather than a regression unique to these six files — but it specifically matters now because the "prod-is-behind" caveat means all six will execute against production for the first time as part of one large gated re-baseline event, where a stuck lock (e.g. a long-running analytics query holding a conflicting lock on `core.users`) would otherwise wait indefinitely rather than failing fast with a clear, retryable error. `20260711170000_users_email_unique_case_insensitive.sql` and `20260715090000_menu_item_currency_and_dining_modes.sql` are correctly excluded — the former uses `CONCURRENTLY` outside a transaction (self-limiting), the latter only touches `site.menu_items`/`site.menus`, which aren't on the hot-table list.
    - **Plain English:** Think of a delivery truck that pulls up to a loading dock with no timer — if the dock is busy, it just waits there forever, blocking every truck behind it. A 2-second "give up and retry" rule means a migration either gets in quickly or fails fast with a clear message, instead of silently hanging the whole deploy. This costs nothing to add and specifically protects the big one-time deploy where all of prod's pending migrations run together for the first time.
    - **Evidence:**
        ```sql
        -- Confirmed via grep: none of the 6 files above contain this text.
        -- Canonical form (docs/migration-guidelines.md §Lock and statement timeouts):
        SET LOCAL lock_timeout    = '2s';
        SET LOCAL statement_timeout = '10s';
        ```

- [x] **MIG-103** · P2 — `NOT VALID` + `VALIDATE CONSTRAINT` run in the same implicit transaction on `core.users`, defeating the two-step lock-weakening pattern
    - **Where:** supabase/migrations/20260712000000_retire_staff_account_type.sql:17-25
    - **Affects:** `core.users` — the primary user table read/written on every authenticated request (login, registration, profile resolution). This is the hottest table in the schema.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the `UPDATE` + `DROP CONSTRAINT` + `ADD CONSTRAINT ... NOT VALID` in an explicit `BEGIN; ... COMMIT;` block, then run `VALIDATE CONSTRAINT` in its own separate explicit `BEGIN; ... COMMIT;` block — either later in the same file or in a companion file, mirroring `CONVENTIONS.md` §2's example exactly (which wraps *each* step in its own `BEGIN`/`COMMIT` pair even when both live in one file).
        - Apply the same explicit-transaction-boundary fix template to any future `account_type` CHECK migration — this file is the reusable pattern other engineers will copy.
    - **Technical:** Postgres holds every lock acquired within a transaction until `COMMIT`, not until the individual statement finishes. This file has no `BEGIN`/`COMMIT` at all, so (per this repo's own convention of needing explicit transaction boundaries even within a single file — see `CONVENTIONS.md` §2's example, which wraps Step A and Step B in *separate* `BEGIN`/`COMMIT` pairs specifically to get separate lock windows) the `DROP CONSTRAINT`, `ADD CONSTRAINT ... NOT VALID`, and `VALIDATE CONSTRAINT` all run as one continuous transaction. That means the `ACCESS EXCLUSIVE` lock taken for the `DROP`/`ADD` catalog writes is still held for the entire duration of `VALIDATE CONSTRAINT`'s row scan — the whole point of splitting `NOT VALID` from `VALIDATE` (deferring the scan to a weaker `SHARE UPDATE EXCLUSIVE` lock) is lost. The file's own comment claims "Same DROP → ADD NOT VALID → VALIDATE dance (CONVENTIONS §2)," but doesn't realize the transaction-boundary half of that convention. At today's row count this is low-impact (the leading `UPDATE` only touches 3 rows), which is why this sits at P2 rather than P1 — but `core.users` is the one table where getting this pattern right matters most going forward.
    - **Plain English:** Imagine a road crew that needs to do two things on a busy street: put up a new sign, then inspect every car that passes. If they keep the road closed the whole time for both tasks, closing it once didn't actually save any time over just doing both tasks under one closure — even though the plan was "quick sign now, inspect cars later without closing the road again." The fix is making sure the road actually reopens between the two tasks, which today just requires writing the "reopen" step explicitly instead of assuming it happens automatically.
    - **Evidence:**
        ```sql
        ALTER TABLE core.users DROP CONSTRAINT IF EXISTS users_account_type_check;

        ALTER TABLE core.users
            ADD CONSTRAINT users_account_type_check CHECK (account_type IN ('partna', 'business')) NOT VALID;

        ALTER TABLE core.users VALIDATE CONSTRAINT users_account_type_check;
        ```

## P3 — Nice to have

## Suggested Bundled Sessions

None. Every finding above edits a `supabase/migrations/*.sql` file — per the fix-flow's own rule, any item touching a DB migration/schema change always runs standalone with its own plan + sign-off, never bundled with another finding.

## Standalone — do NOT bundle

- **MIG-101 — Instagram unification backfill idempotency guard** · DB migration/schema change.
- **MIG-102 — Missing lock/statement timeouts on 6 hot-table migrations** · DB migration/schema change; touches `core.users` among others.
- **MIG-103 — NOT VALID/VALIDATE transaction split on `core.users`** · DB migration/schema change touching the platform's hottest table (`core.users`), directly affects auth/session resolution.


<!-- ═══════════ audit-2026-07-17-api-contract.md ═══════════ -->

# API Contract & Resource Leakage Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** API Contract & Resource Leakage — raw model fields bleeding through, over-fetching, inconsistent pagination
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Http/Controllers/Api/User/Account/UserAccountDeletionController.php
- app/Http/Controllers/Api/User/Account/UserSelfController.php
- app/Http/Controllers/Api/User/Profile/SectorController.php
- app/Http/Controllers/Api/User/SiteManagement/CustomDomainController.php
- app/Http/Resources/PublicSite/IndividualProfileResource.php
- app/Http/Resources/UserDashboardResource.php
- app/Http/Controllers/Api/Platforms/BookingController.php
- app/Http/Controllers/Api/Platforms/DisplaySettingsController.php
- app/Http/Controllers/Api/Platforms/FreshaController.php
- app/Http/Controllers/Api/Platforms/GoogleBusinessController.php
- app/Http/Controllers/Api/Platforms/InstagramController.php
- app/Http/Controllers/Api/Platforms/MenuController.php
- app/Http/Controllers/Api/Platforms/OnlineOrderingController.php
- app/Http/Controllers/Api/Platforms/ReservationsController.php
- app/Http/Controllers/Api/Platforms/SquareController.php
- app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php
- app/Http/Controllers/Api/PublicSite/PublicMenuController.php
- app/Http/Controllers/Api/Staff/Analytics/StaffAggregateAnalyticsController.php
- app/Http/Controllers/Api/Staff/Feedback/StaffFeedbackController.php
- app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

## P3 — Nice to have

## Suggested Bundled Sessions

    - **Why grouped:** Same root cause (no Resource class for menu shaping) across the public and dashboard surfaces, with the same duplicated-field-mapping symptom — fix in one session so both surfaces land on shared Resource classes together.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** Single isolated controller, no dependency on the menu work.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle



<!-- ═══════════ audit-2026-07-17-test-coverage.md ═══════════ -->

# Test Coverage Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Test coverage: critical paths, idempotency, race-safety, policy abilities, mock-vs-integration discipline
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `app/Policies/*.php` (all 18 policy classes, cross-checked against `tests/Unit/Policies/`, `tests/Feature/Policies/`, `tests/Feature/Security/PolicyEnforcement/`)
- `tests/Pest.php`
- `tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php`, `tests/Feature/PublicSite/*.php`, `app/Http/Controllers/Api/PublicSite/IndividualProfileController.php`
- `tests/Feature/Staff/StaffUserSearchFiltersTest.php`, `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php`
- `tests/Feature/Site/CustomDomainTest.php`, `tests/Feature/Database/CheckConstraintsTest.php`, `tests/Feature/Database/IndexCoverageTest.php`
- `tests/Feature/Media/DesignSingletonMediaTest.php`, `tests/Feature/Api/User/SiteManagement/WriteDesignKitConcurrencyTest.php`
- `tests/Feature/Accounts/LifestyleConnectionCleanupTest.php`, `app/Observers/User/UserObserver.php`
- `tests/Feature/Platforms/ReservationProvidersTest.php`, `tests/Feature/Platforms/MenuTest.php`, `tests/Unit/Jobs/EnrichLinkCardJobTest.php`
- `tests/Unit/Jobs/SyncSubdomainToKvJobTest.php`, `tests/Unit/Analytics/RecordAnalyticsEventJobTest.php`, `tests/Feature/Moderation/StaffCase*.php`
- `tests/Feature/Bootstrap/SiteProvisioningSavepointTest.php`, `tests/Unit/Http/SafeUrlFetcherTest.php`
- Remaining scope files from the original draft bundle (`tests/Feature/Api/**`, `tests/Feature/Account*/**`, `tests/Feature/Design/**`, `tests/Feature/Platforms/**`, `tests/Unit/**` per the source list) — cross-checked via repo-wide `Glob`/`Grep` rather than trusted at face value

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 7 of 7 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [x] **#TEST-101** · P2 — `ContentSelectionPolicy` has zero test coverage
    - **Where:** `app/Policies/ContentSelectionPolicy.php` — no test file anywhere under `tests/` references this class.
    - **Affects:** The sitepage background-content-picker mutation surface (`replace`/`toggle`/`upload`/`delete` all authorize through `manage`). A regression that drops the `denyIfPendingDeletion` guard or the owner check would go undetected.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `tests/Feature/Security/PolicyEnforcement/ContentSelectionPolicyEnforcementTest.php` mirroring the existing `FeedbackPolicyEnforcementTest.php` / `StaffManageBlockPolicyTest.php` pattern.
        - Assert `view`: owner → allowed, non-owner → 404 (`denyAsNotFound`).
        - Assert `manage`: owner+active → allowed; non-owner → 404; owner+pending-deletion → 423.
    - **Technical:** This is the only policy in the 18-class `app/Policies/` set with no test file at all — every sibling policy (`SitePolicy`, `UserSelfPolicy`, `FeedbackPolicy`, `CasePolicy`, `DecisionPolicy`, `GdprPolicy`, etc.) has dedicated `allowed`/`denied` coverage in `tests/Unit/Policies/` or `tests/Feature/Security/PolicyEnforcement/`. `ContentSelectionPolicy` follows the identical owner-via-relation + `denyIfPendingDeletion` pattern documented in its own comments ("mirrors SitePolicy's SiteMedia resolution"), so the fix is a direct copy of an existing exemplar, not new pattern design.
    - **Plain English:** Every "who's allowed to touch this" rule in the app has an automatic test proving the lock works — except one: the rule that controls who can change the background content pictures on a professional's page. It's built the same way as all the other locks we've already tested, so writing the missing test is quick, but right now nothing would notice if that lock quietly stopped working.
    - **Evidence:**
        ```php
        public function manage(User $actor, Model $resource): bool|Response
        {
            if ($denied = $this->denyIfPendingDeletion($actor)) {
                return $denied;
            }

            return $this->ownerMatches($actor, $resource)
                ? true
                : $this->denyAsNotFound();
        }
        ```

- [x] **#TEST-102** · P2 — `FeatureAvailabilityPolicy` and `UserSegmentPolicy` staff abilities have no dedicated ability tests
    - **Where:** `app/Policies/FeatureAvailabilityPolicy.php`, `app/Policies/UserSegmentPolicy.php` — no test file references either class name anywhere in `tests/`.
    - **Affects:** Staff tooling for feature-availability rule management and user-segment management. `staffManage` (admin-only, create/update/delete rules or segments) and `staffView` (support+admin read) have no regression test proving the role split is enforced.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Security/PolicyEnforcement/FeatureAvailabilityPolicyEnforcementTest.php` and `.../UserSegmentPolicyEnforcementTest.php` following the `StaffEarlyAccessInviteTest.php` pattern (`it('rejects invite sends from support-role staff (admin-only power)')`) which already proves this exact pattern works for the sibling `EarlyAccessSignupPolicy`.
        - Assert `staffView`: support role → allowed, admin role → allowed, unknown role → denied.
        - Assert `staffManage`: admin → allowed, support → denied (403).
    - **Technical:** All three OV-A staff-only policies (`EarlyAccessSignupPolicy`, `FeatureAvailabilityPolicy`, `UserSegmentPolicy`) share an identical `staffView`/`staffManage` role-gate shape. `EarlyAccessSignupPolicy::staffManage` is already exercised end-to-end via `StaffEarlyAccessInviteTest.php` (admin-allowed + support-denied-with-403), but no equivalent HTTP or Gate-level test exists for `FeatureAvailabilityPolicy` or `UserSegmentPolicy` — `FeatureAvailabilityReadSideTest.php` and `SegmentResolverTest.php` only test the read-side rule-resolution logic, not the staff CRUD authorization gate. Both policies are registered in `AppServiceProvider::boot()` (`Gate::policy(UserSegment::class, ...)`, `Gate::policy(FeatureAvailabilityRule::class, ...)`), so `PolicyCoverageTest.php`'s registration sweep passes even though behavioral coverage doesn't exist.
    - **Plain English:** Two staff-only admin tools — one for turning features on/off platform-wide, one for managing customer segments — have a rule that says "only full admins can change these, support staff can only look." We've proven that exact rule works for a third, similar tool (the early-access waitlist), but never wrote the equivalent test for these two. If a future change accidentally let support staff make changes here, nothing would catch it.
    - **Evidence:**
        ```php
        public function staffView(PartnaStaff $actor, FeatureAvailabilityRule|string|null $rule = null): bool
        {
            return in_array($actor->role, [PartnaStaff::ROLE_SUPPORT, PartnaStaff::ROLE_ADMIN], true);
        }

        public function staffManage(PartnaStaff $actor, FeatureAvailabilityRule|string|null $rule = null): bool
        {
            return $actor->isAdmin();
        }
        ```

- [x] **#TEST-103** · P2 — Singleton design-media replace has no concurrent-request test
    - **Where:** `tests/Feature/Media/DesignSingletonMediaTest.php:151-189` (`it('replaces the existing singleton of the same purpose on re-upload')`)
    - **Affects:** Users uploading logos/cover images back-to-back or from two tabs/devices at once — a race between the soft-delete-old and insert-new steps could leave two "active" singleton rows for the same purpose.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a Postgres-only test (gated like `WriteDesignKitConcurrencyTest.php`, which already establishes the exact pattern for the sibling `design_kits` lock) that runs two interleaved singleton-replace calls against the same `(site_id, pool, purpose)` and asserts exactly one active row survives.
        - If the controller doesn't already take a row lock or rely on a unique constraint for this, note that as a companion production-code fix.
    - **Technical:** The existing test only proves sequential replacement works (soft-delete old, insert new, `toHaveCount(1)`). SQLite can't exercise real row-level locking, and `WriteDesignKitTest.php`/`WriteDesignKitConcurrencyTest.php` already show the house pattern for testing this class of race on a `pgsql`-only, driver-gated test. `DesignSingletonMediaTest.php` has no equivalent, so the singleton invariant for `SiteMedia` (unlike `design_kits`) is currently unverified under concurrency.
    - **Plain English:** If two uploads for the same profile photo slot happen at almost the same instant, the system should end up with exactly one photo in that slot, not two competing ones. We've proven "upload twice, one after the other" works cleanly. We haven't proven "upload twice at the same instant" works — and we already know how to write that kind of test, because we did it for a similar feature (the design-kit editor).
    - **Evidence:**
        ```php
        $active = SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', 'design')
            ->where('purpose', 'logo_full')
            ->get();

        expect($active)->toHaveCount(1);
        ```

- [x] **#TEST-104** · P2 — Staff user search `q` parameter path has zero automated test coverage
    - **Where:** `tests/Feature/Staff/StaffUserSearchFiltersTest.php:63-65` (explicit code comment acknowledging the gap)
    - **Affects:** Staff dashboard user search — every operator searching by handle, email, display name, or sector text via the `q` parameter.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a Postgres-only Pest test (gated on `DB::getDriverName() === 'pgsql'`, matching the house convention used elsewhere for ILIKE-dependent assertions) exercising the `q` path across handle/email/display_name/sector matches.
        - Alternatively, extract the ILIKE query into a small query-builder class that can be unit-tested independently of the driver-specific SQL.
    - **Technical:** `StaffUserController::index()` builds an `ILIKE` query across several columns when `q` is present. SQLite has no `ILIKE`, so the test author explicitly left this path untested with an inline comment. This is a genuine, self-acknowledged gap: a regression in the search query (wrong columns, broken sector join, malformed LIKE pattern) ships undetected on the primary internal search surface for the admin dashboard.
    - **Plain English:** The staff search box is how your team finds a user account. The code that powers it is admittedly untested — there's a comment in the test file that says as much — because the lightweight test database can't run the exact search syntax used in production. If a future change breaks the search, nobody finds out until a staff member complains.
    - **Evidence:**
        ```php
        // NOTE: the q ILIKE path (which now also matches sector) is Postgres-only
        // syntax — the SQLite test mirror can't execute it, same as the pre-existing
        // handle/email ILIKE branches, so it stays covered by prod behaviour only.
        ```

- [x] **#TEST-105** · P2 — Staff search filter tests bypass the HTTP stack entirely
    - **Where:** `tests/Feature/Staff/StaffUserSearchFiltersTest.php:35-41` (`ovaSearchIds()` helper)
    - **Affects:** Staff-only search endpoint — authorization enforcement, `staff`/`require.aal2` middleware, and response formatting are never exercised by these tests.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Convert the filter tests to real HTTP requests via `actingAsStaff()` + `getJson('/api/staff/professionals?...')`.
        - Add a companion test asserting a non-staff actor is rejected on this route.
        - Assert the response matches the `StaffUserResource` shape, not just the raw `id` column.
    - **Technical:** The test instantiates the controller directly (`app(StaffUserController::class)->index($request)`), skipping the JWT middleware, the `staff` gate, and any `authorizeForUser` call the controller makes. A controller refactor that drops the authorization check would still pass this entire test file. This is a textbook instance of category-4 "mock-vs-integration discipline" — the DB layer is real, but the HTTP/auth layer is bypassed entirely for every test in the file.
    - **Plain English:** Imagine testing a locked door by climbing in through an open window instead of trying the door. You can check what's inside the room, but you never actually test whether the lock works. These tests call the search function directly, skipping login and permission checks — so they can't tell you if someone without staff access could reach this search.
    - **Evidence:**
        ```php
        function ovaSearchIds(Request $request): array
        {
            $response = app(StaffUserController::class)->index($request);
            $body = json_decode($response->getContent(), true);

            return array_column($body['professionals'], 'id');
        }
        ```

- [x] **#TEST-106** · P2 — `CustomDomainTest` never exercises a Cloudflare API failure response
    - **Where:** `tests/Feature/Site/CustomDomainTest.php` — all 7 tests fake either a `200 success` Cloudflare response or a missing-config `503`; none fakes a Cloudflare error/outage response.
    - **Affects:** Users connecting a custom domain when Cloudflare is degraded or rejects the request — the failure path from the vendor HTTP client into the controller is completely unverified.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a test faking `Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false], 500)])` on connect, and assert the endpoint returns a clean error (not an unhandled 500) with a user-facing message.
        - Add an equivalent test for the `/verify` polling endpoint.
    - **Technical:** Every `Http::fake()` call in this file returns `'success' => true` except the single config-precheck test, which checks a *missing* `zone_id`/token (a 503 short-circuit before any HTTP call is made), not an actual Cloudflare API error after the precheck passes. If Cloudflare returns a 500 or the connection times out, whatever exception Laravel's HTTP client throws is untested end-to-end.
    - **Plain English:** The tests rehearse "everything goes perfectly with Cloudflare" several times over, but never rehearse "Cloudflare is having a bad day." If that happens while a real user is connecting their custom domain, they might see a generic crash page instead of a helpful "try again" message — and nobody would know until it happens live.
    - **Evidence:**
        ```php
        it('returns 503 when Cloudflare for SaaS is not configured', function () {
            config([
                'services.cloudflare.zone_id' => ''
                'services.cloudflare.saas_api_token' => ''
                'services.cloudflare.api_token' => ''
            ]);
            [$user] = domainUserWithSite('domu2');

            actingAsUser($user)->putJson('/api/site/custom-domain', ['domain' => 'bookwith.me'])
                ->assertStatus(503);
        });
        ```

- [x] **#TEST-107** · P2 — `LifestyleConnectionCleanup` observer test only covers the positive path; no test proves unrelated field updates don't trigger it
    - **Where:** `tests/Feature/Accounts/LifestyleConnectionCleanupTest.php:97-109`; guard lives in `app/Observers/User/UserObserver.php:99` (`if ($professional->wasChanged('account_type'))`)
    - **Affects:** Every user profile update — a regression that widens or drops the `wasChanged('account_type')` guard would silently soft-delete a user's active platform connections on an unrelated edit (e.g. changing their display name).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('does not clean up lifestyle connections when a non-account_type field is updated')`: update `display_name` (or another field) on a business-account user with active lifestyle connections, and assert the count is unchanged.
    - **Technical:** `UserObserver::updated()` gates the cleanup call on `$professional->wasChanged('account_type')` — a single boolean condition with no test proving the negative case. Since the cleanup path soft-deletes rows, a broken guard is a silent data-loss bug, not just a coverage gap: every `User::update()` call in the app (there are many) would start pruning connections on accounts that never changed type.
    - **Plain English:** There's a rule that says "only clean up a user's connected apps when they switch from a personal to a business account." Right now we've only proven the rule fires correctly when that switch happens — we've never proven it stays silent for any other kind of profile edit. If that guard ever breaks, editing your name could accidentally wipe out your connected apps, and nothing today would catch it.
    - **Evidence:**
        ```php
        it('cleans up lifestyle connections when a user switches to business (observer)', function () {
            $pro = lccTenant('lcc-switch', 'partna');
            lccConnection($pro->id, 'apple-music');
            lccConnection($pro->id, 'skool');
            lccConnection($pro->id, 'shop');
            expect(lccActiveCount($pro->id))->toBe(3);

            // The switch fires UserObserver::updated → cleanup.
            $pro->update(['account_type' => AccountType::Business->value]);
            AccountCapabilities::flushCache();

            expect(lccActiveCount($pro->id))->toBe(1); // only shop survives
        });
        ```

## P3 — Nice to have

## Suggested Bundled Sessions

- **Bundle 1 — Staff search test hygiene:** #TEST-104, #TEST-105
    - **Why grouped:** Same file (`StaffUserSearchFiltersTest.php`); fixing the HTTP-bypass issue naturally requires rewriting the tests as real HTTP calls, which is also the vehicle for adding the Postgres-only `q` coverage.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Custom domain test hardening:** #TEST-106
    - **Why grouped:** All three live in `tests/Feature/Site/CustomDomainTest.php` and touch the same custom-domain subsystem; a single session can add the Cloudflare-failure test, the KV-payload callback, and the migration cross-check together without re-loading context.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#TEST-101 — `ContentSelectionPolicy` has zero test coverage** · touches authorization/policy surface.
- **#TEST-102 — `FeatureAvailabilityPolicy`/`UserSegmentPolicy` staff ability tests missing** · touches authorization/policy surface.
- **#TEST-103 — Singleton design-media replace has no concurrent-request test** · isolated (no shared file/pattern with other findings); requires the same driver-gated-test care as a locking change.
- **#TEST-107 — `LifestyleConnectionCleanup` reverse-guard test missing** · isolated; protects a data-deletion code path, warrants its own review pass.


<!-- ═══════════ audit-2026-07-17-code-quality-slop.md ═══════════ -->

# AI Slop & Low-Value Code Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** AI Slop & Low-Value Code — comment noise, premature abstraction, dead code, defensive cruft, copy-paste drift (house style = `CLAUDE.md` Commenting / Simplicity-first / Do-NOT-over-engineer rules)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `app/Mail/Branding/EmailBrandDefaults.php`
- `app/Services/Platforms/BigCartelScraper.php`
- `app/Services/Platforms/DoorDashMenuDriver.php`
- `app/Services/Platforms/GenericShopScraper.php`
- `app/Services/Platforms/GoogleBusinessAutoSync.php`
- `app/Services/Platforms/IdentitySync.php`
- `app/Services/Platforms/InstagramAutoSync.php`
- `app/Services/Platforms/InstagramScraper.php`
- `app/Services/Platforms/MenuMerger.php`
- `app/Services/Platforms/MenuScanApplier.php`
- `app/Services/Platforms/Normalizers/FacebookNormalizer.php`
- `app/Services/Platforms/Payloads/InstagramPayload.php`
- `app/Services/Platforms/PlatformScraper.php`
- `app/Services/Platforms/Registry/PlatformDescriptor.php`
- `app/Services/Platforms/ShopifyScraper.php`
- `app/Services/Platforms/UberEatsMenuDriver.php`
- `app/Services/Platforms/WebsiteLinkHarvester.php`
- `app/Services/Platforms/WooCommerceScraper.php`
- `app/Services/User/AccountDeletionService.php`
- `app/Services/User/SiteProvisioningService.php`
- `app/Services/User/UserBootstrapService.php`
- `app/Console/Commands/CleanupOrphanedLifestyleConnections.php`
- `app/Http/Controllers/Api/User/Account/UserAccountDeletionController.php`
- `app/Http/Controllers/Api/User/Account/UserSelfController.php`
- `app/Http/Controllers/Api/User/Profile/SectorController.php`
- `app/Http/Controllers/Api/User/SiteManagement/CustomDomainController.php`
- `app/Http/Resources/PublicSite/IndividualProfileResource.php`
- `app/Http/Resources/UserDashboardResource.php`
- `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php`
- `app/Jobs/Platforms/InstagramConnectJob.php`
- `app/Jobs/Platforms/MenuFetchJob.php`
- `app/Observers/User/UserObserver.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 1 of 1 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [x] **SLOP-101** · P2 — `seededFinding`/`conflictFinding`/`write`/`applyFinding` duplicated byte-for-byte between `GoogleBusinessAutoSync` and `InstagramAutoSync`
    - **Where:** `app/Services/Platforms/GoogleBusinessAutoSync.php:627-663, 683-698` and `app/Services/Platforms/InstagramAutoSync.php:219-239, 262-290, 293-308`
    - **Affects:** Maintainers — the connect-modal finding contract and the `IntegrationConnection` write shape live in two places; a schema change (new finding field, new write column) must be made twice or silently diverges.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract `seededFinding`, `conflictFinding`, `write`, and the "remove-then-write" core of `applyFinding` into a shared trait (e.g. `Concerns/BuildsAutoSyncFindings`) used by both classes — a trait needs no constructor changes, since both classes already import `IntegrationConnection` directly.
        - Keep `GoogleBusinessAutoSync::applyFinding`'s Instagram-dispatch branch as a thin override that calls the shared remove-step, then either dispatches Instagram or falls through to the shared write.
    - **Technical:** Both classes build an identical finding array (`platform`/`resourceId`/`category`/`label`/`foundUrl`/`outcome`/`apply`) and write identically to `IntegrationConnection::updateOrCreate` with the same 6 fields. This isn't hypothetical drift risk — the adjacent `socialUsername()` method in both files carries a comment documenting that exact failure mode already happening: InstagramAutoSync's docblock says "this was a byte-for-byte copy of that same standalone regex, sharing its blind spot for reserved path segments," fixed by delegating both classes to the shared `FacebookNormalizer` (commits `5b1f4488`, `5d00fac6`, G4-4). The four methods flagged here are the same shape of duplication that bug already came from, just not yet extracted.
    - **Plain English:** Two departments share the same paperwork template but keep separate copies of it. This exact team already got burned once — a shared form (finding someone's Facebook username) drifted between the two copies and produced a real bug, which they fixed by making both departments read from one master copy. These four remaining forms haven't been consolidated yet, so the same kind of drift could happen again.
    - **Evidence:**
        ```php
        // GoogleBusinessAutoSync.php — identical to InstagramAutoSync.php:
        private function seededFinding(string $platform, string $resourceId, string $category, string $label, ?string $foundUrl): array
        {
            return [
                'platform' => $platform
                'resourceId' => $resourceId
                'category' => $category
                'label' => $label
                'foundUrl' => $foundUrl
                'outcome' => 'seeded'
                'apply' => null
            ];
        }

        private function conflictFinding(string $platform, string $resourceId, string $category, string $label, ?string $foundUrl, array $apply): array
        {
            return [
                'platform' => $platform
                'resourceId' => $resourceId
                'category' => $category
                'label' => $label
                'foundUrl' => $foundUrl
                'outcome' => 'conflict'
                'apply' => $apply
            ];
        }
        ```

## P3 — Nice to have

## Suggested Bundled Sessions

    - **Why grouped:** all three touch `app/Services/Platforms/PlatformScraper.php` plus the same set of subclasses (`ShopifyScraper`, `WooCommerceScraper`, `BigCartelScraper`, `GenericShopScraper`) — one small mechanical-cleanup PR.
    - **Model:** Plan Opus · Implement Sonnet · Review Sonnet (all S-effort, low complexity).

- **Bundle 2 — GoogleBusinessAutoSync consolidation:** #SLOP-101
    - **Why grouped:** both touch `GoogleBusinessAutoSync.php`; the trait extraction in #SLOP-101 is a natural place to also drop the dead `hasStoreKey`/`count` helpers from  in the same pass.
    - **Model:** Plan Opus · Implement Sonnet · Review Sonnet.

## Standalone — do NOT bundle

None.


<!-- ═══════════ audit-2026-07-17-semantic-correctness.md ═══════════ -->

# Semantic Correctness Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Semantic Correctness — code that compiles and type-checks but does the wrong thing (real-method-wrong-contract, config/flag misuse, plausible-but-wrong magic values, logic contradicting intent, codebase-idiom drift)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Services/Accounts/AccountCapabilities.php
- app/Services/Accounts/AccountCapabilitySet.php
- app/Services/Accounts/LifestyleConnectionCleanup.php
- app/Services/PublicSite/IndividualProfilePayloadBuilder.php
- app/Services/PublicSite/SiteActionsService.php
- app/Services/PublicSite/SitepageDataResolverService.php
- app/Services/User/AccountDeletionService.php
- app/Services/User/SiteProvisioningService.php
- app/Services/User/UserBootstrapService.php
- app/Support/BusinessName.php
- app/Http/Controllers/Api/User/Account/UserAccountDeletionController.php
- app/Http/Controllers/Api/User/Account/UserSelfController.php
- app/Http/Controllers/Api/User/Profile/SectorController.php
- app/Http/Controllers/Api/User/SiteManagement/CustomDomainController.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Jobs/Platforms/InstagramConnectJob.php
- app/Jobs/Platforms/MenuFetchJob.php
- app/Policies/EarlyAccessSignupPolicy.php
- app/Policies/FeatureAvailabilityPolicy.php
- app/Policies/FeedbackPolicy.php
- app/Policies/UserSegmentPolicy.php
- app/Policies/UserSelfPolicy.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 0 complete

---

## P3 — Nice to have

## Suggested Bundled Sessions

    - **Why grouped:** Single isolated finding, single file/method — nothing else in this audit shares its root cause.
    - **Model:** Plan: Opus (combine plan+implement given S effort) · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

None.



---
