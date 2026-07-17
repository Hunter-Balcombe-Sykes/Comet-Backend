# Triaged execute-audit — P0 + P1 — reconciled 2026-07-11+17 full-work sweep

> **▶ To run this file:** `execute audit audits/sweeps/2026-07-11-full-work-sweep/TRIAGE-1-P0-P1.md`
> Fires the fix-flow: branch off `development`, then for each work unit (every **bundle** + every **standalone**, in tier order) **plan (Opus) → implement (Sonnet) → independent Sonnet review → commit** — ticking the box only after tests pass AND review says PASS. **Blocker gate:** P0 · auth · money · DB/migration · L/XL → present the plan and WAIT for Josh's sign-off before implementing. Auto-archives when every box is `[x]`. Full runbook: `scripts/audit/fix-flow.md`.

## This file
- **Tier(s):** P0 + P1  ·  **Findings:** 22  (16 carried from 07-11 · 6 new from 07-17)
- **IDs ≥ 100 = 2026-07-17** changed-file findings; **IDs < 100 = carried 2026-07-11**. Same file, same lens sections as the master; sliced to this tier.
- Full context + reconciliation ledger: `CONSOLIDATED.md` (same folder).

## Execution policy
- **Plan:** Opus 4.8 · **Implement:** Sonnet 4.6 · **Review:** separate Sonnet 4.6 (never the implementer).
- **Combine plan+impl:** YES for S/XS · NO for P0/P1 or L/XL. Per-item escalate to Opus for gnarly logic/blast radius.

## Execution grouping — P0 + P1 (triage 2026-07-17)

**Merge as one fix (duplicates / same root cause):**
- `SEM-1` ← `TXN-1` — both are `ContentSelectionService::setInstagramAuto()` committing the `content_instagram_auto_enabled` flag write (`$site->save()`) and `persist()`'s reserved-slot rebuild as two separate `DB::connection('pgsql')->transaction()` calls; identical file/lines/evidence, same fix (wrap both in one transaction). `SEM-1` is the more complete write-up — use it as primary.

**Work units, in recommended execution order:**
1. **Migration lock-window split** — `MIG-1` · `supabase/migrations/20260705120000_drop_dead_profile_features.sql` · effort M · 🔒 blocker: P0 + DB migration
2. **Migration numeric-cast guard** — `MIG-2` · `supabase/migrations/20260710190000_semantic_text_scale_and_vocab_remap.sql` · effort S · 🔒 blocker: DB migration
3. **Signup email-conflict: stop string-matching driver text** — `LIFE-101` · `UserBootstrapService.php` · effort M · 🔒 blocker: auth/identity path, Standalone
4. **Site-settings PATCH concurrency lock** — `LIFE-3` · `UpdateSiteAction.php` (highest-traffic authenticated write) · effort M · 🔒 blocker: Standalone, core write-path correctness
5. **Instagram-auto flag+slot rebuild atomicity** — `SEM-1`, `TXN-1` · `ContentSelectionService.php` · effort S · 🔒 blocker: Standalone (DB write-path invariant)
6. **Staff PII/admin_notes leak on show()** — `SEC-101` · `StaffUserController.php` / `UserStaffResource.php` · effort M · 🔒 blocker: auth/PII exposure, Standalone
7. **Content-image upload MIME byte-sniff** — `SEC-1` · `UploadContentImageRequest.php` · effort S · 🔒 blocker: upload/injection safety, Standalone
8. **UserSelfController::update() missing relation preload** — `API-101` · `UserSelfController.php` / `UserDashboardResource.php` · effort S · 🔒 blocker: plausible live-prod crash path, Standalone
9. **CloudflareCachePurgeJob fail loudly on empty handle** — `JOB-1` · `CloudflareCachePurgeJob.php:76-79` · effort S · 🔒 blocker: Standalone; do before unit 10 (same file)
10. **hide_content KV-purge redundancy/reconciliation** — `EDGE-1` · `ModerationActionDispatcher.php` / `PurgeModerationCacheJob.php` / `CloudflareCachePurgeJob.php:29-35` · effort M · 🔒 blocker: Standalone, moderation-enforcement blast radius
11. **Platform-scraper silent-degradation (Fresha + Shop)** — `OBS-1`, `OBS-2` · `FreshaScraper.php` / `ShopCatalog.php` / `ShopFetch.php` / `PlatformRefresher.php` circuit-breaker · effort M · 🔒 blocker: `OBS-2` Standalone (shared failure-classification logic)
12. **MenuFetchJob timeout vs. redis retry_after** — `JOB-103` · `MenuFetchJob.php` / `config/queue.php` / `config/horizon.php` · effort M · 🔒 blocker: Standalone, shared queue-connection blast radius
13. **SyncSubdomainToKvJob swallowed-exception hardening** — `JOB-101`, `JOB-102` · `SyncSubdomainToKvJob.php` (`handle()` + `retire()`, same swallow pattern) · effort S · autonomous
14. **GDPR export: integrations + analytics sections** — `PRIV-3`, `PRIV-4` · `DataExportPayloadBuilder.php::sectionDescriptors()` · effort M · autonomous
15. **Handle-audit-log retention enforcement job** — `PRIV-2` · `config/partna.php` + `routes/console.php` (new `handles:prune-audit-logs`) · effort S · autonomous
16. **FeatureAvailability single-flight caching** — `CCH-1` · `FeatureAvailability.php` → `CacheLockService::rememberLocked` · effort S · autonomous
17. **Waitlist signup race (unique-constraint 500)** — `LIFE-1` · `EarlyAccessService.php` · effort S · autonomous
18. **Enquiry-notification idempotency guard reordering** — `LIFE-2` · `DispatchEnquiryNotificationsJob.php` · effort S · autonomous

**Ordering / dependencies:**
- Tier order forces `MIG-1` (P0) first; `MIG-2` next (only other pure-migration item).
- Unit 9 (`JOB-1`) before unit 10 (`EDGE-1`) — both edit `CloudflareCachePurgeJob.php` (lines 76-79 vs 29-35); the fail-fast fix gives EDGE-1's escalation a clean `failed()` to build on.
- No other unit shares a file, so units 3-8 and 11-18 can run in any order/parallel once sign-offs land — the order above is a recommendation (auth/identity + highest-traffic first), not a hard chain.
- 10 of 18 units are blockers needing a presented plan + sign-off; since they mostly don't share files, **batch-presenting all 10 plans up front is more efficient than serializing sign-off one at a time.**

**Possibly already addressed (verify at fix time):**
- `LIFE-101` — `UserBootstrapService.php` was recently touched by commit `4752fb4a` ("case-insensitive email uniqueness + bootstrap race-safety"). Confirm the specific string-matching-on-driver-message bug is still present (distinct from the TOCTOU pre-check already fixed there) before implementing.
- `MIG-1`, `MIG-2` — both note their target files are no-ops on dev (already applied) and only bite on prod's still-pending fresh apply. Given the known dev/prod migration drift, confirm apply-state on both envs before treating the risk as live.

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
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 4 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#SEC-1** · P1 — Content-library image upload trusts client-declared MIME instead of byte-sniffing
    - **Where:** app/Http/Requests/Api/User/Content/UploadContentImageRequest.php:16-23
    - **Affects:** Content library image uploads (`POST` to the content-image endpoint) — a disguised file (e.g. a script renamed `.png`) can pass validation.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `use SniffsFileMimeType;` to `UploadContentImageRequest` and call `$this->assertImageMimeBytes($this->file('image'), $v, 'image')` from a `withValidator()` hook, matching `UploadImageRequest` and `UploadDesignMediaRequest` exactly.
        - No pixel-bomb guard needs adding here — `ImageVariantService::loadImage()` (used downstream by `ContentController::storeUpload()`) already enforces `partna.image_max_pixels` via a header-only `getimagesize()` before any GD decode, so that protection already applies to this path once the file reaches variant processing.
    - **Technical:** The rules array uses only Laravel's `'image'` + `'mimes:jpeg,png,webp'`, both of which trust client-supplied metadata (extension / declared Content-Type) rather than the file's actual bytes. The two sibling upload Form Requests (`UploadImageRequest`, `UploadDesignMediaRequest`) both additionally apply `SniffsFileMimeType::assertImageMimeBytes()`, an `finfo`-based magic-byte check — this file is the one upload path in the codebase that skips it, a genuine deviation from the house pattern documented in the lens's category 6.
    - **Plain English:** When someone uploads a photo to their content library, the system currently checks the file's label (like "this is a .png") but not what's actually inside the file — like accepting a package because the shipping label says "books" without opening it. Every other upload spot in the app double-checks the actual contents; this one form forgot to add that extra check.
    - **Evidence:**
        ```php
        public function rules(): array
        {
            $imageMaxKb = (int) config('partna.image_max_upload_size', 10240);

            return [
                'image' => [
                    'required'
                    'file'
                    'image'
                    'mimes:jpeg,png,webp'
                    "max:{$imageMaxKb}"
                ]
                'alt_text' => ['sometimes', 'nullable', 'string', 'max:255']
                'caption' => ['sometimes', 'nullable', 'string', 'max:200']
            ];
        }
        ```

## P2 — Should fix

## P3 — Nice to have

## Suggested Bundled Sessions

    - **Why grouped:** Both are `VerifySupabaseJwt` / `authorizeForUser` doctrine-hardening items with no live exploit path today; can land as one review pass over the auth-adjacent controller layer.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** Identical mechanical fix (inline `$request->validate()` → dedicated `FormRequest` class) across five endpoints in the Platforms controller family.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#SEC-1 — Content-image upload MIME byte-sniff** · standalone: touches the upload/injection-safety surface (Form Request + trait wiring) and should get its own focused review given the file-upload attack surface it closes.


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
- P1 High: 0 of 3 complete
- P2 Medium: 0 of 25 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **LIFE-1** · P1 — Waitlist signup races on read-then-create; the DB's own UNIQUE constraint 500s instead of degrading gracefully
    - **Where:** app/Services/EarlyAccess/EarlyAccessService.php:32-49
    - **Affects:** Public marketing waitlist form — any double-click, form re-submit, or client retry for the same email crashes the request instead of returning the existing signup.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Catch `Illuminate\Database\UniqueConstraintViolationException` around the `create()` call and re-fetch/return the existing row on conflict.
        - Alternatively use `firstOrCreate` scoped on `email_lc`, or an `insertOrIgnore` + follow-up select, matching the pattern `NotificationPublisher::publish()` already uses for its dedupe key.
    - **Technical:** Category 1 — `UniqueConstraintViolationException`. `supabase/migrations/20260711000300_early_access_signups.sql` correctly backs `email_lc` with `CONSTRAINT early_access_signups_email_lc_unique UNIQUE (email_lc)` — the DB-level guard exists — but `signupFromMarketing()` does an uncaught `where('email_lc', ...)->first()` then `create([...])` with no lock and no `catch`. Two requests that both observe `null` both attempt the INSERT; the loser's `UniqueConstraintViolationException` is never caught and propagates as an unhandled 500. This is a public, unauthenticated form — double-submits are a routine UX pattern (slow network, impatient double-click), so this isn't a rare edge case but a "known scenario" that will recur as signup volume grows.
    - **Plain English:** Someone fills out the waitlist form and, because the page is slow to respond, clicks "submit" twice. The system checks "does this email already exist?", sees no both times, and tries to save two entries. The database itself refuses the second save — but nobody told the code to expect that refusal, so instead of quietly saying "you're already on the list," the site shows the visitor a broken error page.
    - **Evidence:**
        ```php
        $existing = EarlyAccessSignup::query()->where('email_lc', $emailLc)->first();

        if ($existing === null) {
            $signup = EarlyAccessSignup::query()->create([
                'email' => $emailLc
                'email_lc' => $emailLc
                'type' => $data['type']
                ...
        ```

- [ ] **LIFE-2** · P1 — Enquiry-notification idempotency guard is set AFTER the side-effect, not before — a job retry can double-send
    - **Where:** app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php:64-77
    - **Affects:** Site owners receiving enquiry notifications — a retry after a mid-flight crash (worker OOM-kill, deploy restart) re-sends the enquiry email/in-app notification.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `Cache::has()` / `Cache::put()` pair with a single atomic `Cache::add('enquiry:notified:'.$this->enquiryId, true, now()->addDay())` called BEFORE `$dispatcher->dispatch(...)`. `add()` returns `false` if the key already exists — if so, return early without dispatching.
    - **Technical:** Category 1 — the job's own idempotency guard is a check-then-act race: `Cache::has()` is read, `$dispatcher->dispatch($enquiry, $block)` fires the side-effect, and only afterward does `Cache::put()` record that it happened. `ShouldBeUnique` (`uniqueFor=300`) only prevents two copies of the *same job attempt* from running concurrently — it does not survive a crash between `dispatch()` and `Cache::put()`. Laravel releases the unique lock when the job attempt completes (success or exception), so `$tries=3` with `$backoff=[30,90,180]` will retry after exactly this kind of mid-flight failure, re-check `Cache::has()` (still false), and re-send. `Cache::add()` is a single atomic SETNX and closes the window regardless of where the crash lands — this is the same "guard before side-effect" principle `NotificationPublisher::publish()` already applies correctly via its `insertOrIgnore` on `(user_id, dedupe_key)`.
    - **Plain English:** After someone submits an enquiry form, the system sends the site owner an email. To avoid sending it twice, it writes a "sent" note to its scratchpad — but only AFTER the email goes out. If the worker crashes in that gap and the job retries, it checks the scratchpad, sees no note, and sends a second email. Writing the note first (and skipping the send if the note's already there) closes that gap completely.
    - **Evidence:**
        ```php
        // Idempotency guard — a retry after partial success must not re-send the notification.
        if (Cache::has('enquiry:notified:'.$this->enquiryId)) {
            return;
        }

        $dispatcher->dispatch($enquiry, $block);
        ...
        Cache::put('enquiry:notified:'.$this->enquiryId, true, now()->addDay());
        ```

- [ ] **LIFE-3** · P1 — Site-settings PATCH merges the JSONB blob outside any lock — two concurrent saves silently drop one set of changes
    - **Where:** app/Services/Site/UpdateSiteAction.php:51-102, 121-141
    - **Affects:** Every authenticated user editing their site settings — two concurrent dashboard saves (multi-tab editing, a slow save race against a second field change) can lose one write entirely, with no error surfaced to either request.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Re-read `$site` with `lockForUpdate()` inside the transaction, re-derive `$existing`/`$merged` from that locked read (not the pre-transaction snapshot), then `fill()`/`save()` — this narrows the window doctrine calls for without moving the whole PATCH-merge computation back into the lock.
        - Alternatively, adopt a conditional `UPDATE ... WHERE updated_at = ?` compare-and-swap and surface a 409 to the losing request so the client can refresh and retry instead of silently losing data.
    - **Technical:** Category 2 — race-safe read-modify-write. The settings JSONB merge (`array_replace_recursive($existing, $incoming)`) reads `$site->settings` from a model instance loaded before the transaction opens (`$professional->loadMissing('site')` at the top of `execute()`), then — well outside any lock — computes `$data['settings']` in pure PHP. Only afterward does the method open `DB::connection('pgsql')->transaction(...)` and call `$site->fill($data); $site->save();` with no `lockForUpdate()` on the row. Two concurrent PATCHes (e.g. toggling a section live in one tab while updating a booking URL in another) both read the same starting `settings`, each merges its own change, and whichever transaction commits last silently overwrites the other's write — there is no conflict signal to either client. The `UniqueConstraintViolationException` catch on `$site->save()` only guards the subdomain-rename path, not this merge. This is the platform's single highest-traffic authenticated write path (every dashboard save), so the "two tabs" scenario is a documented, expected usage pattern, not a rare edge case.
    - **Plain English:** A professional has their dashboard open in two browser tabs — maybe one on their phone and one on their laptop. In one they turn on their photo gallery; in the other they update their booking link. Both tabs read the current settings, add their own change, and save. Whichever save reaches the database second completely overwrites the first — there's no merge, no warning, nothing. The gallery toggle just silently reverts, and the professional has no idea why. The fix makes the database hold a lock while reading-and-writing so the second save can't blindly stomp on the first.
    - **Evidence:**
        ```php
        // Hoist pure-PHP work out of the transaction to keep the lock window narrow.
        if (array_key_exists('settings', $data)) {
            $existing = is_array($site->settings) ? $site->settings : [];
            $incoming = is_array($data['settings']) ? $data['settings'] : [];
            ...
            $merged = array_replace_recursive($existing, $incoming);
            ...
            $data['settings'] = $merged;
        }
        ...
        return DB::connection('pgsql')->transaction(function () use ($professional, $site, $data, $options): Site {
            ...
            $site->fill($data);
            try {
                $site->save();
            } catch (UniqueConstraintViolationException $e) {
                // Final safety net for the unique index on subdomain
                ...
        ```

## P2 — Should fix

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

- **LIFE-3 — Site-settings PATCH concurrency:** standalone — the platform's single highest-traffic authenticated write path; M-effort correctness fix to the core `Site` update flow warrants its own plan + sign-off rather than folding into an unrelated bundle.


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
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

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
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

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
- P2 Medium: 0 of 5 complete
- P3 Low: 0 of 8 complete

---

## P2 — Should fix

## P3 — Nice to have

## Suggested Bundled Sessions

None — every finding in this audit is a `supabase/migrations/` schema change, and per the fix-flow doctrine every DB migration/schema change runs standalone with its own plan + sign-off, never bundled.

## Standalone — do NOT bundle



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
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 4 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#CCH-1** · P1 — `FeatureAvailability::for()` uses bare `Cache::remember` without single-flight lock
    - **Where:** app/Services/FeatureAvailability/FeatureAvailability.php:41-45
    - **Affects:** Every professional whose dashboard hits `GET /platforms/meta` (`IntegrationsMetaController`), which resolves availability for every registry platform on load. After a staff member edits a feature-availability rule (`flush()` bumps the version token), every connected user's next dashboard load is a simultaneous cold miss that independently re-queries `feature_availability_rules` + resolves segment membership.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Inject `CacheLockService` into `FeatureAvailability` (or resolve it via the container) and replace `Cache::remember(...)` with `$cacheLock->rememberLocked($key, self::CACHE_TTL_SECONDS, fn () => self::resolveOverrides($user))`.
        - This single change also closes CCH-2 (jitter) and CCH-3 (SWR) below — `rememberLocked` applies both automatically.
    - **Technical:** `FeatureAvailability::for()` is the only read path for `core.feature_availability_rules` and is invoked from `IntegrationsMetaController::__invoke` on every dashboard-index load. After `flush()` increments the version token (staff CRUD), every per-user cache key becomes a fresh miss simultaneously; with bare `Cache::remember`, concurrent requests (a user's own polling/tabs, or the fleet-wide effect of a staff-managed global rule change) each independently execute the query + `SegmentResolver` resolution instead of blocking on a single regenerator. `CacheLockService::rememberLocked` wraps this in `Cache::lock` (on the `cache_locks` connection) so exactly one caller regenerates while the rest block briefly and read the fresh fill.
    - **Plain English:** When staff flip a feature flag, every user's saved answer to "can I use this?" goes stale at the same instant. Right now, if several people load their dashboard in that moment, each one independently re-asks the database the same question at the same time — like a hundred people all ringing the same doorbell simultaneously instead of one person ringing it and everyone else waiting for the door to open.
    - **Evidence:**
        ```php
        $overrides = Cache::remember(
            "feature-availability:user:{$user->id}:v{$version}"
            self::CACHE_TTL_SECONDS
            fn () => self::resolveOverrides($user)
        );
        ```

## P2 — Should fix

## P3 — Nice to have

## Suggested Bundled Sessions

- **Bundle 1 — FeatureAvailability caching hardening:** #CCH-1, 
    - **Why grouped:** Same file (`app/Services/FeatureAvailability/FeatureAvailability.php`), same root fix — routing `for()` through `CacheLockService::rememberLocked` closes the single-flight, jitter, and SWR gaps in one edit; the exception-swallowing () and key-centralisation () cleanups are trivial adjacent changes to the same ~10 lines.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet (all S-effort; no escalation needed).

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
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

## P2 — Should fix

## Suggested Bundled Sessions

    - **Why grouped:** same route group (`/me/deletion/*`), same middleware file, same underlying subsystem — fixing enforcement and reordering can land in one pass over `routes/api/user.php` + `IdempotencyKey.php` + `AccountDeletionService.php`.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle



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
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#TXN-1** · P1 — Instagram-auto flag flip and its reserved-slot rebuild commit as two separate transactions
    - **Where:** app/Services/Site/ContentSelectionService.php:222-225, 347-356
    - **Affects:** Any user connecting Instagram or toggling Instagram-auto content. If `persist()` fails after the flag save already committed (constraint violation, deadlock, transient DB error), `content_instagram_auto_enabled` is `true` but no ig-reel/ig-post slots exist — the sitepage silently shows nothing in the reserved positions with no error surfaced to the user.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the whole mutation — the `$site->content_instagram_auto_enabled` assignment, `$site->save()`, and the `persist($site, $rows)` call — in a single `DB::connection('pgsql')->transaction(...)` in `setInstagramAuto()`. `persist()`'s own `DB::transaction(...)` call then auto-converts to a SAVEPOINT (the same pattern already used deliberately in `SiteProvisioningService::tryCreateSite`), so no restructuring of `persist()` itself is required.
        - Apply the same wrap to the other two callers that mutate `$site` state ahead of `persist()` — `maybeSeedFromGoogle()` doesn't flip a site column so it's unaffected; only `setInstagramAuto()` has this shape.
    - **Technical:** `setInstagramAuto()` sets `$site->content_instagram_auto_enabled` and calls `$site->save()`, which commits immediately (and fires `SiteObserver::saved()`, itself `afterCommit`-gated so it runs correctly after that first commit). It then computes the reserved ig-reel/ig-post rows and calls `persist()`, which opens a *second*, independent `DB::connection('pgsql')->transaction(...)` to delete-then-insert the `ContentSelection` rows. These two writes are not part of one atomic unit: a failure in the second transaction leaves the flag durably `true` with no slots reserved. The fix is to make the flag write and the row rebuild a single atomic unit, matching the gold-standard "the database transaction is the unit of atomic state change" rule.
    - **Plain English:** Think of a restaurant flipping a sign to "We serve breakfast now!" and only afterward walking to the kitchen to check whether they actually have eggs. If the kitchen turns out to be out of eggs, the sign is already up — customers show up expecting breakfast that was never prepared. Here, the site's "auto-fill Instagram content" switch gets saved to the database as ON before the system finishes setting up the actual Instagram slots. If that setup step fails, the switch stays on but nothing ever appears in those slots, and nothing tells anyone it broke.
    - **Evidence:**
        ```php
        public function setInstagramAuto(Site $site, bool $enabled): void
        {
            $site->content_instagram_auto_enabled = $enabled;
            $site->save();
        ```
        ```php
        private function persist(Site $site, array $rows): void
        {
            DB::connection('pgsql')->transaction(function () use ($site, $rows) {
                ContentSelection::query()->where('site_id', $site->id)->delete();

                foreach ($rows as $row) {
                    ContentSelection::create($row);
                }
            });
        }
        ```

## P2 — Should fix

## Suggested Bundled Sessions

- **Bundle 1 — Status/flag flip not atomic with dependent child-row rebuild:** #TXN-1
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
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 1 complete

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
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#JOB-1** · P1 — CloudflareCachePurgeJob silently succeeds on an empty handle instead of failing loudly
    - **Where:** app/Jobs/Cloudflare/CloudflareCachePurgeJob.php:76-79
    - **Affects:** Edge cache coherence for every professional's public sitepage — `CloudflareCachePurgeJob` is the only job responsible for busting the router's cache after a visible site mutation
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the bare `return;` in the empty-handle branch with `$this->fail(new \RuntimeException('Empty handle dispatched to CloudflareCachePurgeJob'));` then `return;`.
        - No other change needed — `failed()` already calls `report($e)` + `Log::error(...)`, which is what makes the failure visible to Nightwatch (Nightwatch alerts on exceptions, not log lines).
    - **Technical:** `handle()` trims/lowercases `$this->handle` and returns immediately when the result is empty. Horizon records an empty-handle dispatch as a *succeeded* job, so the failed-jobs counter never increments and no exception reaches Nightwatch. Because this job is the sole edge-purge writer for the platform (dispatched from `SiteObserver::saved`, account-type transitions, and other mutation paths), a caller bug that ever produces an empty/blank handle means the affected site's public page keeps serving a stale render with no signal that a purge was dropped — exactly the "consequential job with a silent no-fail path" pattern this lens calls out for the named highest-stakes jobs.
    - **Plain English:** If someone hands this job a work order with no address on it, right now it just quietly closes the ticket as "done" instead of raising a hand and saying "I can't do this, something's wrong upstream." That means a bug that produces a blank site handle would never get noticed — the affected person's public page could keep showing old content indefinitely with nobody alerted.
    - **Evidence:**
        ```php
        $h = strtolower(trim($this->handle));
        if ($h === '') {
            return;
        }
        ```

## P2 — Should fix

## P3 — Nice to have

## Suggested Bundled Sessions

    - **Why grouped:** Identical root cause (paid vendor scrape re-run on a job's own retry because no pre-call "processing" marker exists) across three sibling files in `app/Jobs/Platforms/`; the fix pattern is the same state-machine addition in each.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet (per file's Execution policy).

    - **Why grouped:** Single small hygiene fix, no shared file/pattern with other findings.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet (combine plan+implement — S effort).

## Standalone — do NOT bundle

- **#JOB-1 — CloudflareCachePurgeJob silent success on empty handle** · standalone: distinct subsystem (edge-cache job hygiene) from the Platforms-scrape bundle and the notification bundle; small enough to land independently without waiting on either.


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
- P1 High: 0 of 2 complete
- P2 Medium: 0 of 5 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

- [ ] **OBS-1** · P1 — FreshaScraper's per-employee menu falls back to whole-location and reports 'ok' forever; the code's own comment promises Nightwatch visibility it never delivers
    - **Where:** app/Services/Platforms/FreshaScraper.php:203-224
    - **Affects:** Every Fresha connection in per-employee booking mode. A rotated `BOOKING_INIT_HASH`/client version silently and *permanently* downgrades the per-stylist menu to the whole-location menu with zero operator signal.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In the `catch (Throwable $e)` block, call `report($e)` in addition to the existing `Log::warning` so a transport-level failure reaches Nightwatch.
        - On the non-2xx branch (`! $response->ok()`), call `report(new \RuntimeException('fresha.employee_services.http_error: '.$response->status()))` so a persisted hash/version rotation is distinguishable from a one-off blip.
        - Do not rely on `PlatformRefresher`'s circuit breaker here — it can't see this failure (see Technical).
    - **Technical:** `fetchEmployeeServices` catches `Throwable` and non-2xx responses and returns `null` with only `Log::warning` calls — the comment directly above the catch block explicitly states the intent ("Surface silent failures so a rotated BOOKING_INIT_HASH/client version is visible in Nightwatch"), but `Log::warning` is a breadcrumb, not a Nightwatch signal (per the canonical alert model, only exceptions/`report()`/auto-detected slow paths page). Worse, this failure is *not* caught by the platform's existing circuit breaker: in `FreshaFetch::fetch()` (app/Services/Platforms/Strategies/Fetch/FreshaFetch.php:43-53), a `null` return from `fetchEmployeeServices` triggers a fallback to `fetchLocation()`/`extractServices()` (the whole-location scrape), which typically succeeds since it's a more stable public page. Because `$services` ends up non-empty, `FetchUnavailableException` is never thrown, so `PlatformRefresher::recordFailure()` (app/Services/Platforms/PlatformRefresher.php:60-61, 88-109) never increments `consecutive_failures` and the connection is recorded as `last_refresh_status = 'ok'` on every subsequent refresh. Since the code comment itself calls a hash rotation "the documented rotation inevitability," this WILL happen, and once it does the connection will silently and permanently serve a coarser menu while reporting healthy — there is no path (Nightwatch or user-facing) by which anyone learns of it.
    - **Plain English:** Fresha (the booking platform) occasionally changes an internal security code your Fresha integration depends on — the code even admits this is inevitable. When it happens, the system quietly switches from showing a stylist's own specific services to showing the whole shop's full menu instead, and marks the connection as "all good" forever after. Nobody — not the engineering team, not the business owner — ever finds out unless a customer complains that their booking page looks wrong.
    - **Evidence:**
        ```php
        try {
            $response = Http::withHeaders([
                // ...
            ])->timeout(12)->post(self::GRAPHQL_URL, $payload);
        } catch (Throwable $e) {
            // Surface silent failures so a rotated BOOKING_INIT_HASH/client version
            // is visible in Nightwatch instead of silently degrading to the
            // whole-location menu (the documented rotation inevitability).
            Log::warning('fresha.employee_services.failed', [
                'reason' => 'exception'
                'slug' => $slug
                'employee_id' => $employeeId
                'error' => $e->getMessage()
            ]);

            return null;
        }
        ```
    - `[Adjudicated: elevated from vendor-services-1 DeepSeek draft OBS-1 (P1, confidence 0.9); tier confirmed after tracing FreshaFetch/PlatformRefresher interaction]`

- [ ] **OBS-2** · P1 — ShopCatalog::syncLatest swallows fetch failures as "nothing changed," resetting the failure counter and defeating the platform's own circuit breaker
    - **Where:** app/Services/Platforms/ShopCatalog.php:77-83
    - **Affects:** Every shop brand with "auto-latest" enabled. A persistently-blocking store (bot detection, dead URL) never surfaces as a failure — the scheduled sync silently no-ops forever, and the "the scheduled refresh retries" promise made to the user in `ShopController` is never honoured with a retry that actually succeeds or an alert that it can't.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - In `syncLatest`, log the swallowed `HttpException` with structured context (`brand_id`, `url`, exception message) instead of a bare `return null`.
        - In `ShopFetch::fetch()` (app/Services/Platforms/Strategies/Fetch/ShopFetch.php:57-61), distinguish "every brand's `syncLatest` failed" from "nothing to sync": throw `FetchUnavailableException` on the former so `PlatformRefresher::recordFailure()` actually increments `consecutive_failures` and the existing `PlatformHealthNotifier` circuit breaker can trip, rather than the current `FetchNotModifiedException` which routes through `recordNotModified()` and *resets* `consecutive_failures` to 0 on every failed cycle.
    - **Technical:** `syncLatest()` catches `HttpException` from `providerProducts()` and returns `null` with no log call. The caller, `ShopFetch::fetch()`, treats every `syncLatest() === null` brand as merely "not synced this round" and — when *all* brands in the batch return null — throws `FetchNotModifiedException('shop')` (line 60), not `FetchUnavailableException`. `PlatformRefresher::refresh()` (app/Services/Platforms/PlatformRefresher.php:50-51) routes `FetchNotModifiedException` to `recordNotModified()`, which sets `last_refresh_status = 'ok'` and explicitly `consecutive_failures = 0` — the exact opposite of what should happen on a fetch failure. This is a genuine bug, not just a missing log: it actively erases the failure signal the codebase's own circuit-breaker design (documented in `PlatformRefresher`'s class comment) depends on, so `PlatformHealthNotifier::connectionRefreshFailing()` can never trip for a permanently-blocked store. Compounding this, `ShopController::setProducts()` (app/Http/Controllers/Api/Platforms/ShopController.php:242-245) tells the dashboard "the scheduled refresh retries" when a manual sync fails — a promise this bug silently breaks, since the retries never accumulate a distinguishable failure state.
    - **Plain English:** When a shop's automatic "always show the newest products" feature can't reach the store (the store is blocking automated requests, for example), the system currently records this exactly the same as "nothing new to update" — a perfectly healthy outcome. That's like a delivery service marking a package "delivered" every time the customer refuses to answer the door. The shop's products silently freeze in place, the owner is told a retry is happening, but nothing ever actually recovers or gets flagged.
    - **Evidence:**
        ```php
        public function syncLatest(ShopBrand $brand): ?int
        {
            try {
                $catalog = $this->providerProducts($brand->toBrandArray());
            } catch (HttpException) {
                return null;
            }
        ```
        ```php
        // ShopFetch::fetch()
        if ($synced === 0) {
            // Every latest-mode store was unreachable this cycle — selections
            // untouched, nothing to publish.
            throw new FetchNotModifiedException('shop');
        }
        ```
    - `[Adjudicated: vendor-services-1 DeepSeek draft OBS-2 (P2, confidence 0.85) elevated to P1 after discovering the recordNotModified/consecutive_failures interaction — a cross-file invariant DeepSeek missed]`

## P2 — Should fix

## Suggested Bundled Sessions

- **Bundle 1 — Platform-scraper silent-degradation fixes:** #OBS-1, #OBS-2
    - **Why grouped:** Same root-cause pattern (a fetch/scrape failure returns null/empty and is recorded as a quiet non-alerting status) across the Platforms scraper/job layer; #OBS-1 and #OBS-2 additionally require tracing into `PlatformRefresher`/`ShopFetch`/`FreshaFetch`, so reviewing them together avoids re-deriving that context twice.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet). #OBS-2 touches the shared `PlatformRefresher` failure-classification path used by every platform — escalate implement → Opus for that item specifically, or split it into its own standalone session (see below) if the plan reveals broader blast radius.

    - **Why grouped:** All three are "add `report()`/`$timeout`, keep existing behavior" changes with no logic changes to the surrounding fail-open design — low-risk, mechanical, and independent of each other.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet); combine plan+impl given the small size of each change.

## Standalone — do NOT bundle

- **#OBS-2 — ShopCatalog::syncLatest defeats the circuit breaker** · standalone: this is a correctness fix to shared failure-classification logic in `PlatformRefresher`/`ShopFetch` (not just a missing log), used by every platform's scheduled refresh — changing how failures are classified needs its own plan + sign-off given the blast radius, and it is the most consequential P1 in this run.


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
- P1 High: 0 of 4 complete
- P2 Medium: 0 of 5 complete
- P3 Low: 0 of 4 complete

---

## P1 — Fix before pilot launch

- [ ] **PRIV-2** · P1 — 7-year handle-audit retention is declared in config but no job enforces it
    - **Where:** `config/partna.php:56` (`handle.audit_retention_years`) and `routes/console.php` (no matching `Schedule::command()`)
    - **Affects:** Every row in `audit.handle_change_log` — every historical handle a professional has ever held, tied to their identity, retained forever with no expiry mechanism.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `handles:prune-audit-logs` command that hard-deletes `audit.handle_change_log` rows older than `config('partna.handle.audit_retention_years')` years.
        - Register it in `routes/console.php` on a daily cadence with `onOneServer()`, `withoutOverlapping()`, and the shared `$reportScheduledFailure` handler, and have it log the deleted row count (not contents).
    - **Technical:** `config('partna.handle.audit_retention_years')` defaults to 7 with the comment "matches typical fraud-investigation retention," but `routes/console.php` contains no command reading that key or targeting `audit.handle_change_log`. The two handle-related scheduled commands present (`handles:prune-expired-aliases`, `handles:notify-expiry`) both operate on the *alias* lifecycle (the 90-day `redirect_days` window via `->active()` scope), not the audit log. This is a declared retention rule with zero enforcement — the config lies about what actually happens to the data.
    - **Plain English:** The platform promises to keep a permanent log of every handle change for 7 years and then delete it. Nobody wrote the cleanup job. In practice every handle-change record — including handles from years ago — sits in the database forever, with no code anywhere that would ever remove it. If a regulator or a user asks "what old handles do you still hold on me," the honest answer today is "all of them, indefinitely," which contradicts our own stated policy.
    - **Evidence:**
        ```php
        // config/partna.php
        // Years to retain handle_change_log rows. 7y matches typical fraud-investigation retention.
        'audit_retention_years' => (int) env('SIDEST_HANDLE_AUDIT_RETENTION_YEARS', 7)
        ```
        ```php
        // routes/console.php — the only two handle-related schedule entries; neither touches handle_change_log
        Schedule::command('handles:prune-expired-aliases')->dailyAt('03:15')...
        Schedule::command('handles:notify-expiry')->dailyAt('09:00')...
        ```

- [ ] **PRIV-3** · P1 — Platform integration connections entirely absent from GDPR export
    - **Where:** `app/Services/User/DataExport/DataExportPayloadBuilder.php:245-249` (`streamIntegrations`)
    - **Affects:** Any professional with connected platforms (Instagram, YouTube, Spotify, shop, etc.) — their stored platform usernames, profile data, and connection metadata never appear in a DSAR.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace the no-op `streamIntegrations()` with a real generator streaming `site.platform_connections` rows scoped to `user_id`.
        - Add an explicit CSV column allow-list in `sectionDescriptors()`, excluding internal-only `payload` keys (`refresh_etag`, `apify_status`, `refresh_last_modified`) that aren't user-facing data.
    - **Technical:** `DataExportPayloadBuilder::streamIntegrations()` yields nothing, with the comment "No integrations for individual-standalone accounts." That premise is false today: `IntegrationConnection` (`site.platform_connections`), `PublicIntegrationConnectionResource`, and ~20 platform controllers are all active individual-account features (per the platform registry work shipped through `2ad3d7cb`/`973303c5`). `payload` stores platform usernames, profile data, follower counts, and business categories — personal data the professional supplied. This is a stale guard from a pre-pivot era that was never updated after the individual-only pivot.
    - **Plain English:** A professional connects their Instagram and Spotify to their Partna page, then asks for a copy of everything we hold on them. We send their profile and photos but silently skip every connected account — because a leftover comment in the code still says "individual accounts don't have integrations," which hasn't been true for a while. The checklist needs to catch up to the product.
    - **Evidence:**
        ```php
        private function streamIntegrations(string $userId): Generator
        {
            // No integrations for individual-standalone accounts; yield nothing.
            yield from [];
        }
        ```

- [ ] **PRIV-4** · P1 — Site analytics (visits, clicks, section views, item views) entirely absent from GDPR export
    - **Where:** `app/Services/User/DataExport/DataExportPayloadBuilder.php:123-169` (`sectionDescriptors` — no analytics entries)
    - **Affects:** Every professional requesting a DSAR — their business traffic analytics (visit counts, referrers, UTM data, geo breakdowns, device splits, link-click destinations) is invisible to the export, despite it being the professional's own business data collected from their sitepage.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `streamSiteVisits()`, `streamLinkClicks()`, `streamSectionViews()`, and `streamItemViews()` generators scoped by `user_id`, registered under an `analytics` group in `sectionDescriptors()`.
        - Redact visitor-identifying columns (`ip_hash`, `visitor_id`, `session_id`) in the export — the professional is entitled to their own aggregate business data, not a bulk transfer of third-party visitor fingerprints.
    - **Technical:** `sectionDescriptors()` enumerates ~24 sections (profile, site, media, customers, enquiries, feedback, notifications, audit logs...) but none for `analytics.site_visits` / `link_clicks` / `section_views` / `item_views`. This is the professional's own generated business data under Article 15 / APP 12, and its complete absence is a clean, verifiable export gap — distinct from the already-well-handled analytics retention/deletion side (see dropped finding note below: the FK `ON DELETE CASCADE` from these tables to `core.users` already handles erasure correctly).
    - **Plain English:** A professional uses their Partna page as their online storefront. When they ask for all their data, we hand over their profile and customer list but leave out their own website analytics — how many visitors, which links got clicked, where visitors came from. That's information about *their* business, generated from *their* page; it belongs in the export, just with individual visitor fingerprints (raw IP hashes, session IDs) stripped out so we're not handing over other people's data along with it.
    - **Evidence:**
        ```php
        private function sectionDescriptors(User $professional, ?string $lookupEmail, ?string $siteId): array
        {
            $userId = $professional->id;
            return [
                ['name' => 'metadata', ...], ['name' => 'profile', ...], ['name' => 'site', ...]
                ['name' => 'waitlist', ...], ['name' => 'media.site_media', ...], ['name' => 'design_kit', ...]
                ['name' => 'integrations', ...], ['name' => 'customers', ...], ['name' => 'services', ...]
                // ... no 'analytics.site_visits', 'analytics.link_clicks', 'analytics.section_views'
                // or 'analytics.item_views' entries anywhere in this array.
            ];
        }
        ```

## P2 — Should fix

## P3 — Nice to have

## Suggested Bundled Sessions

    - **Why grouped:** All are additive entries/fields to the same file (`DataExportPayloadBuilder::sectionDescriptors()` / `site()` / `streamFeedback()`) — one coherent pass over the export manifest.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** Identical root-cause pattern (a declared `config/partna.php` retention value with no matching `routes/console.php` command) — same fix shape, same files.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** All are small, low-risk config/ingest-layer cleanups (precision truncation, stale docs, placeholder values, retention-window tuning) with no cross-file coordination needed.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle



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
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 2 complete

---

## P1 — Fix before pilot launch

- [ ] **EDGE-1** · P1 — `hide_content` moderation decisions have no KV-level redundancy; a failed edge purge leaves the reported content live for up to 7 days (Category 3)
    - **Where:** `app/Services/Moderation/ModerationActionDispatcher.php:31` (`ACTIONS_BY_DECISION`), `app/Jobs/Moderation/PurgeModerationCacheJob.php:45-66`, `app/Jobs/Cloudflare/CloudflareCachePurgeJob.php:29-35`
    - **Affects:** Any case resolved with `decision_type = hide_content` (the standard "take this specific content down" outcome) — visitors to the professional's page, and the reporter/staff who believe the content is gone.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a scheduled reconciliation sweep (mirroring `handles:prune-expired-aliases`) that finds `action_log` rows with `action_type='purge_cloudflare_cache'` still not confirmed complete after N minutes and re-dispatches `CloudflareCachePurgeJob`.
        - Alternatively/additionally, escalate `CloudflareCachePurgeJob::failed()` to page on-call the same way `NotifyOnCallStaffJob` does for CSAM, rather than a log-only `report($e)` + `Log::error`.
        - Document the asymmetry explicitly in `ModerationActionDispatcher`'s docblock so a future decision-type addition doesn't assume KV retirement is a universal safety net.
    - **Technical:** `ACTIONS_BY_DECISION['hide_content'] = ['notify_reported_user', 'purge_cloudflare_cache']` — unlike `hide_site`/`suspend_user`/`ban_user`/`csam_auto_suspend`, it does **not** dispatch `sync_subdomain_kv`'s site-suspending sibling actions. `PurgeModerationCacheJob::handle()` does call `SyncSubdomainToKvJob::dispatch($ownerId)` unconditionally, but that job only retires the KV entry when `!$pro->isActive() || $site->moderation_state === 'hidden'` (see `SyncSubdomainToKvJob.php:94,114`) — neither is true for a `hide_content` decision, so the KV entry stays `{"type":"individual"}` and the Worker keeps consulting `caches.default` on every request (`index.js:519-521` → `serveIndividual`). The **only** thing that can evict the pre-takedown render from the edge is `CloudflareCachePurgeJob` succeeding. That job's own retry ceiling (`$tries = 3`, `$backoff = [5, 15, 60]`, `$maxExceptions = 2`) is real but finite, and on exhaustion `failed()` only logs + reports — there is no automatic re-attempt and no secondary gate (unlike the whole-site-hide paths, which get the KV-retirement backstop for free). Worst case: the reported content — the exact thing staff decided to take down — continues rendering from the primary cache (24h) and then the stale shadow (7d) with zero further signal beyond one Nightwatch alert.
    - **Plain English:** When staff decide to hide one piece of reported content (not the whole page), the system's only way to make that change show up for visitors is to tell the edge network "please forget your copy of this page." If that one instruction fails to get through — say, a temporary hiccup talking to Cloudflare — nothing else catches it. The whole-site takedown path (suspending an entire account) has a second independent lock that also blocks the old page from being shown, but the single-piece-of-content takedown doesn't get that backup. So a reported item that staff believe is gone could keep showing to the public for up to a week, with only a quiet error log — no second check confirms it actually disappeared.
    - **Evidence:**
        ```php
        // app/Services/Moderation/ModerationActionDispatcher.php
        'hide_content' => ['notify_reported_user', 'purge_cloudflare_cache']
        ```
        ```php
        // app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
        public int $tries = 3;
        public array $backoff = [5, 15, 60];
        public int $maxExceptions = 2;
        ```
        ```javascript
        // cloudflare-worker/src/index.js — cache is still consulted whenever KV says "individual"
        if (entry.type === "individual") {
          return serveIndividual(env, ctx, request, null);
        }
        ```

## P2 — Should fix

## P3 — Nice to have

## Suggested Bundled Sessions

    - **Why grouped:** All three are documentation/enforcement gaps between the Worker and its backend/config mirrors (RESERVED list, domain/TTL constants, staging namespace) — same files (`index.js` + `wrangler.toml` + `config/partna.php`), no behavioral risk, low effort each.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** Both are minor Worker response-shape tweaks (fail-open branded 404 + CTA link) with no cross-cutting risk; both touch only `index.js`.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#EDGE-1 — `hide_content` purge-only redundancy gap:** standalone — P1, touches the moderation enforcement pipeline (`ModerationActionDispatcher`/`PurgeModerationCacheJob`), needs its own plan for the reconciliation-sweep or escalation design and its own sign-off given the content it protects is, by definition, already flagged as reported/harmful.


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
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 5 complete

---

## P2 — Should fix

## P3 — Nice to have

## Suggested Bundled Sessions

    - **Why grouped:** Same root cause — a `*_ENABLED` flag in `config/partna.php` deliberately defaulting `true` with a documented, verified safety net elsewhere. Same file, same decision to make (fix vs. leave documented-as-is).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet (trivial change if approved).

    - **Why grouped:** Same pattern (a hardcoded value that should route through `config()`), different files, no cross-dependency between them — safe to implement and review together.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle



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

- P0 Blockers: 1 of 1 complete
- P1 High: 1 of 1 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 1 complete

---

## P0 — Must fix before any real user touches the system

- [x] **#MIG-1** · P0 — One transaction holds `ACCESS EXCLUSIVE` on `site.blocks`/`site.public_site_payload` across a DELETE, two view rebuilds, a CHECK swap+validate, and seven column/table drops
    - **Where:** `supabase/migrations/20260705120000_drop_dead_profile_features.sql:22-360`
    - **Affects:** Every public sitepage read during deploy — `site.public_site_payload` and `site.all_site_data` are the views every sitepage GET resolves through; `site.blocks` backs both.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Split into two files: (1) `DELETE FROM site.blocks` + view rebuild + CHECK swap/validate, (2) the `site.sites`/`core.users` `DROP COLUMN`s + child-table drops. This shortens how long the view-drop's lock is held before COMMIT.
        - Add `SET LOCAL lock_timeout = '2s'; SET LOCAL statement_timeout = '10s';` to both resulting files per `docs/migration-guidelines.md` §Lock and statement timeouts.
        - Per `docs/migration-guidelines.md` §Editing already-applied migrations, the Supabase CLI tracks applied versions by timestamp, not content hash — editing this file's SQL is safe even if it's already applied on dev (it no-ops there) and only changes behavior on prod's still-pending fresh apply (prod is on the pre-standalone schema per the "prod-is-behind" caveat), so this split can land now without a new migration file.
    - **Technical:** Category 1 + 6. Postgres holds every lock acquired in a transaction until COMMIT, regardless of which statement acquired it. `DROP VIEW IF EXISTS site.public_site_payload;` (line 35) takes `ACCESS EXCLUSIVE` on the view every sitepage read queries, and `ALTER TABLE site.blocks DROP CONSTRAINT blocks_group_type_check;` (line 331) takes `ACCESS EXCLUSIVE` on `site.blocks` itself — both locks stay held through the `VALIDATE CONSTRAINT` scan, the five `site.sites` column drops, the `core.users` column drop, and the two `DROP TABLE`s that follow, all the way to `COMMIT` (line 360). Any concurrent sitepage render blocks for the full duration of that chain, not just the individual DDL statement's own runtime. The file already has a well-written rollback block (lines 362-408) and correct `NOT VALID`/`VALIDATE` split for the CHECK — the fix here is purely about shortening the lock window by splitting the transaction, not about correctness.
    - **Plain English:** Imagine a shop owner rearranging the front window display — but instead of doing it in a few quick minutes, they pull the curtain shut, then also go rearrange three storage rooms in the back, all before opening the curtain again. Every customer standing outside is stuck waiting the whole time, not just for the window part. Splitting the job into "quick curtain change" and "backroom reorganizing later" means customers only wait for the short part.
    - **Evidence:**
        ```sql
        DELETE FROM site.blocks
        WHERE block_type IN ('bio', 'credentials', 'experience', 'countdown', 'sitepage_analytics');

        DROP VIEW IF EXISTS site.public_site_payload;
        DROP VIEW IF EXISTS site.all_site_data;
        ```
        ```sql
        ALTER TABLE site.blocks DROP CONSTRAINT blocks_group_type_check;

        ALTER TABLE site.blocks ADD CONSTRAINT blocks_group_type_check
            CHECK (
                (block_group = 'links' AND block_type = 'link')
                OR (block_group = 'sections' AND block_type IN (
                    'gallery', 'services', 'booking', 'contacts_collection'
                    'barbershop_info', 'documents', 'newsletter'
                    'contact', 'public_contact', 'workplace'
                ))
            ) NOT VALID;

        ALTER TABLE site.blocks VALIDATE CONSTRAINT blocks_group_type_check;
        ```

## P1 — Fix before pilot launch

- [x] **#MIG-2** · P1 — Non-atomic numeric cast in a design-kit vocabulary backfill can abort mid-file, leaving prior DDL committed
    - **Where:** `supabase/migrations/20260710190000_semantic_text_scale_and_vocab_remap.sql:143-152`
    - **Affects:** `site.design_kits` / `site.design_kit_contributions` reads during a future apply — a cast failure here leaves the earlier 7 `DROP COLUMN`s and prior `UPDATE`s in this same file committed while this final scrub fails, since the file has no `BEGIN`/`COMMIT` wrapper.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Tighten the `WHERE` guard to reject values that survive `regexp_replace` but still can't cast (e.g. multiple decimal points): add `AND regexp_replace(value, '[^0-9.]', '', 'g') ~ '^[0-9]+(\.[0-9]+)?$'` alongside the existing `is not null` check.
        - Per `docs/migration-guidelines.md` §Editing already-applied migrations, this edit is safe to make now (no-ops on envs where the file already ran; only changes the still-pending fresh-apply behavior).
    - **Technical:** Category 3 + 9. The final `UPDATE` extracts a numeric value via `regexp_replace(value, '[^0-9.]', '', 'g')::numeric`. The existing `WHERE ... is not null` guard correctly excludes values with no digits at all (e.g. `var(--x)`), but a malformed value that still contains digits/dots in a non-numeric shape (e.g. `1.2.3rem`) passes the `WHERE` clause and then throws `invalid input syntax for type numeric` inside the `CASE`, aborting the statement. Because this file (unlike its sibling `20260705120000_drop_dead_profile_features.sql`) has no explicit transaction wrapper, the 7 `text_*` column drops and earlier vocabulary `UPDATE`s above it in the same file are already committed by the time this statement would fail — a half-applied migration.
    - **Plain English:** This migration relabels items using handwritten tags, and the very last step tries to read the trickiest handwriting after already throwing out the old shelves. If one tag is garbled in a way the simple check doesn't catch, the relabeling crashes — but the shelves are already gone. Checking the handwriting more carefully before starting avoids a half-finished cleanup.
    - **Evidence:**
        ```sql
        update site.design_kit_contributions
           set value = case
               when nullif(regexp_replace(value, '[^0-9.]', '', 'g'), '')::numeric < 0.125 then '0'
               when nullif(regexp_replace(value, '[^0-9.]', '', 'g'), '')::numeric < 0.55  then '0.25rem'
               when nullif(regexp_replace(value, '[^0-9.]', '', 'g'), '')::numeric <= 1.175 then '0.85rem'
               else '1.5rem'
             end
         where target_var = 'border_radius'
           and value not in ('0', '0.25rem', '0.85rem', '1.5rem')
           and nullif(regexp_replace(value, '[^0-9.]', '', 'g'), '') is not null;
        ```

## P2 — Should fix

## P3 — Nice to have

## Suggested Bundled Sessions

None.

## Standalone — do NOT bundle

- **#MIG-1 — One transaction holds `ACCESS EXCLUSIVE` on `site.blocks`/views across a DELETE + drops:** P0, and edits a `supabase/migrations/` DDL file whose behavior applies to prod's still-pending schema catch-up.
- **#MIG-2 — Non-atomic numeric cast in a design-kit vocabulary backfill:** edits a `supabase/migrations/` file affecting eventual prod schema state; data-scrub correctness change.


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
- P3 Low: 0 of 9 complete

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
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 4 complete

---

## P2 — Should fix

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
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 5 complete

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
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#SEM-1** · P1 — `ContentSelectionService::setInstagramAuto` commits the auto-flag and the reserved-slot rebuild as two separate transactions
    - **Where:** app/Services/Site/ContentSelectionService.php:222-241 (flag write), 347-356 (`persist()`)
    - **Affects:** Any user connecting Instagram or toggling Instagram-auto content. A transient DB error inside `persist()` (constraint violation, deadlock, timeout) leaves `content_instagram_auto_enabled = true` already committed while the ig-reel/ig-post slots at positions 1–2 were never written — the sitepage silently renders without the reserved Instagram content, with no error surfaced to the user or caller.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the whole mutation in `setInstagramAuto()` — the `$site->content_instagram_auto_enabled` assignment, `$site->save()`, and the `persist($site, $rows)` call — in a single `DB::connection('pgsql')->transaction(...)`. `persist()`'s own internal `DB::connection('pgsql')->transaction(...)` then nests as a SAVEPOINT under Laravel's transaction-nesting semantics, so `persist()` itself needs no restructuring.
        - Leave `IntegrationConnectionObserver::enableContentInstagramAuto()` (app/Observers/Core/IntegrationConnectionObserver.php:131-147) as-is — it deliberately flips the raw column without reconciling slots, documented as self-healing on the next explicit toggle/edit; that pattern is intentional and out of scope here.
    - **Technical:** Category 4 (logic contradicts intent). `$site->content_instagram_auto_enabled = $enabled; $site->save();` commits immediately at lines 224-225, before the existing `ContentSelection` rows are read and before `$this->persist($site, $rows)` runs its own separate `DB::connection('pgsql')->transaction(...)` at line 349. If `persist()` throws, the flag is durably `true` with no matching ig-* rows — a torn state that only heals on the next call to `setInstagramAuto()` or `replace()`. `resolve()` still serves whatever rows actually exist (no crash), but the positional guarantee the flag is meant to enforce (ig content pinned to slots 1–2) silently doesn't hold, and nothing signals the caller/user that the write partially failed.
    - **Plain English:** Flipping the "auto-fill my Instagram content" switch and actually reserving the two photo slots for it are saved to the database as two separate steps instead of one all-or-nothing step. If the second step trips up — a rare database hiccup — the switch stays "on" but the slots never get set aside, and nobody is told anything went wrong. The fix is to make both steps succeed or fail together, like a single atomic save.
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
        ```php
        private function persist(Site $site, array $rows): void
        {
            DB::connection('pgsql')->transaction(function () use ($site, $rows) {
                ContentSelection::query()->where('site_id', $site->id)->delete();

                foreach ($rows as $row) {
                    ContentSelection::create($row);
                }
            });
        }
        ```

## P3 — Nice to have

## Suggested Bundled Sessions

None — the two surviving findings touch unrelated subsystems (content-selection transactions vs. a dev-only analytics const) and neither shares a file, subsystem, or root cause with the other.

## Standalone — do NOT bundle

- **#SEM-1 — Instagram-auto flag/slot transaction gap** · standalone: touches a DB write-path correctness invariant on a user-facing content-selection mutation; run with its own plan + sign-off even though effort is S.


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
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 7 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

- [ ] **#SEC-101** · P1 — `StaffUserController::show()` leaks full PII + admin notes to non-admin staff, bypassing the file's own documented PII gate
    - **Where:** app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php:96-138 (`show()`), contrasted with `index()`:76-89; leaked fields in app/Http/Resources/UserStaffResource.php:13-42
    - **Affects:** Every professional's `primary_email`, `phone`, `public_contact_number`, full location, `auth_user_id`, and `admin_notes` — exposed to any authenticated staff account (support-tier included), not just admins.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Give `UserStaffResource` the same audience-split constructor `StaffUserListResource` already uses: `new UserStaffResource($professional, bool $showPii)`, redacting `primary_email`, `phone`, `location_*`, `auth_user_id`, and `admin_notes` when `$showPii` is false.
        - In `show()`, derive `$showPii` from `$request->attributes->get('partna_staff')->isAdmin()` exactly as `index()` already does, and pass it through.
        - Add an explicit `authorizeForUser($staff, 'staffManage', $professional)`-style gate is not appropriate for a *read* (that ability is admin-only and would 403 support staff out of a page they're meant to use) — instead add a new `staffView(PartnaStaff $actor, User $target): bool { return true; }` ability on `UserSelfPolicy` (mirrors the `staffManage`/`staffForceDelete` staff-actor pattern already in that file) and call `authorizeForUser($staff, 'staffView', $professional)` in both `index()` and `show()`, so the read path is structurally covered per the audit doctrine and every future read-path addition to this controller inherits the same seam.
    - **Technical:** `index()` explicitly derives `$showPii = $staff && $staff->isAdmin();` and threads it into `StaffUserListResource($p, $showPii)` — the code comment states the intent plainly: *"PII gate: only admin staff may see raw email + phone in the list view."* `show()`, reached via the identical `staff` middleware group (not `staff.admin` — see `routes/api/staff.php:35-67`), has no such gate: it calls `new UserStaffResource($professional)` unconditionally, and `UserStaffResource::toArray()` unconditionally includes `phone`, `primary_email`, `auth_user_id`, full location fields, and `admin_notes` (explicitly commented "Staff-only tribal knowledge"). A support-tier staff account that is deliberately blocked from seeing raw PII on the list view can trivially pivot to `GET /staff/professionals/{id}` and get everything, including internal `auth_user_id` and admin notes never intended for non-admin eyes. Neither `index()` nor `show()` calls `authorizeForUser` at all — all seven of this controller's *mutating* methods (`updateStatus`, `update`, `destroy`, `restore`, `forceDestroy`, `bulkUpdateStatus`) do, several explicitly as "defence-in-depth ... even if the route group ever grants access to support staff" — the same reasoning applies to reads and was simply missed here.
    - **Plain English:** The staff dashboard has a list page that deliberately hides a professional's email and phone number from regular support staff — only admins see that. But the single-professional detail page (one click away from that same list) hands over the email, phone, home address, and private internal staff notes to ANY staff member, admin or not. It's like a filing cabinet where the drawer labelled "summary" is locked for junior staff, but the drawer right next to it labelled "full file" isn't locked at all — and it has the exact same key. Any support agent can open a professional's full record today.
    - **Evidence:**
        ```php
        // index() — the intended PII boundary:
        $staff = $request->attributes->get('partna_staff');
        $showPii = $staff && $staff->isAdmin();
        $professionals = $page->getCollection()->map(
            fn (User $p) => (new StaffUserListResource($p, $showPii))->toArray($request)
        );
        ```
        ```php
        // show() — no gate, no PII split:
        public function show(User $professional): JsonResponse
        {
            $professional->load(['site']);
            $integrations = $professional->integrationConnections()
                ->orderBy('platform')
                ->get(['id', 'platform', 'is_active', 'last_refreshed_at', 'last_refresh_status'])
            // ...
            return $this->success([
                'professional' => new UserStaffResource($professional)
        ```
        ```php
        // UserStaffResource::toArray() — unconditional PII + admin_notes
        'phone' => $this->phone
        'primary_email' => $this->primary_email
        // ...
        // Staff-only tribal knowledge — must NEVER appear in UserDashboardResource (/me).
        'admin_notes' => $this->admin_notes
        ```

## P2 — Should fix

## Suggested Bundled Sessions

None.

## Standalone — do NOT bundle

Every finding in this audit is an authorization-boundary or PII-exposure fix — per policy, all run standalone with their own plan + sign-off, never bundled.

- **#SEC-101 — StaffUserController::show() PII/admin_notes leak** · auth/authorization + PII exposure; touches a shared Resource class used elsewhere.


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
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 9 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#LIFE-101** · P1 — Signup email-conflict handling discriminates constraints by string-matching the Postgres driver message
    - **Where:** app/Services/User/UserBootstrapService.php:100-116
    - **Affects:** Every new-user and existing-user bootstrap call (`POST` signup/profile-bootstrap) — the hottest identity-creation path in the app.
    - **Effort:** M (~2–4h, needs a real-Postgres regression test since SQLite doesn't abort transactions on constraint violation the same way)
    - **What to do:**
        - Mirror the pattern `SiteProvisioningService::tryCreateSite` already uses in this codebase: wrap `$professional->save()` in a nested `DB::connection('pgsql')->transaction()` so Postgres emits a SAVEPOINT instead of aborting the whole outer bootstrap transaction on a `23505`. That lets the catch block re-query (`User::query()->whereRaw('lower(primary_email) = ?', [$emailLc])->exists()`) to determine the real cause deterministically instead of parsing driver text.
        - If the savepoint restructure is deferred, at minimum stop matching the loose `'primary_email'` substring (which can false-positive on unrelated messages that happen to mention that column name) and anchor purely on the fixed index name `users_email_unique` (confirmed at `supabase/migrations/20260526000000_baseline_standalone_user.sql:363`).
        - Add a Postgres-gated regression test (same gating as `SiteProvisioningSavepointTest`) so a future Postgres minor/major version upgrade that reflows the unique-violation message text fails CI instead of silently turning `EMAIL_ALREADY_REGISTERED` into a raw 500 in production.
    - **Technical:** The code already correctly catches the typed `UniqueConstraintViolationException` (not a bare `QueryException`), but then discriminates *which* constraint fired by `str_contains()` on `$e->getMessage()` — the same version-unstable pattern the house doctrine's `UniqueConstraintViolationException` canonical pattern exists to eliminate, just one layer deeper. The comment above the catch block (`// LIFE-106: guardAgainstEmailReuseByDifferentAuthUser is a TOCTOU-racy pre-check...`) already documents that this exact race is expected to happen in production (two concurrent signups for the same email), meaning this code path executes for real users today, not just in theory. `SiteProvisioningService::tryCreateSite` in the same codebase already demonstrates the correct fix — a nested transaction (SAVEPOINT) that survives the constraint violation and lets the caller re-query cleanly, with no string matching at all.
    - **Plain English:** When someone signs up with an email that's already registered, the system currently reads the raw error text Postgres hands back to decide "was this an email clash, or something else entirely?" That's like sorting mail by skimming the first sentence of each letter — if the postal service (Postgres) ever rewords its form letters during an upgrade, the sorting rule silently breaks and a user trying to sign up with a taken email gets a confusing crash page instead of "this email is already registered." The fix makes the check ask the database directly instead of guessing from wording.
    - **Evidence:**
        ```php
        } catch (UniqueConstraintViolationException $e) {
            // LIFE-106: guardAgainstEmailReuseByDifferentAuthUser is a TOCTOU-racy pre-check;
            // the lower(primary_email) unique index is the real backstop. ...
            if (str_contains($e->getMessage(), 'users_email_unique')
                || str_contains($e->getMessage(), 'primary_email')) {
                throw new RuntimeException('EMAIL_ALREADY_REGISTERED', 0, $e);
            }

            throw $e;
        }
        ```

## P2 — Should fix

## P3 — Nice to have

## Suggested Bundled Sessions

    - **Why grouped:** Same root-cause pattern (missing `user_id`/discriminator in a lifecycle log's catch block) across two files; all S-effort, no auth/money/schema involvement.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** All live in `app/Services/Platforms/` + `app/Jobs/Platforms/`, all stem from unlocked read-modify-write races in the Google Business / Instagram auto-sync pipeline, and / share one fix (same lock key) by design.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** Same file (`SyncSubdomainToKvJob.php`), same subsystem (subdomain routing sync), both touch the alias/TTL and uniqueness logic that a single reviewer should reason about together.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#LIFE-101 — Signup email-conflict string-matching:** standalone — sits on the primary signup/account-creation path; a regression here can break new-user onboarding platform-wide, and the recommended fix (nested transaction + Postgres-gated test) touches transaction semantics that warrant isolated review rather than being bundled with unrelated work.


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
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

## P3 — Nice to have

## Suggested Bundled Sessions

    - **Why grouped:** Same vendor (Cloudflare), same root theme (outbound write-rate defense-in-depth), touch adjacent files in `app/Services/Cloudflare` and `app/Jobs/Cloudflare`.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

## Standalone — do NOT bundle



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
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 4 complete

---

## P2 — Should fix

## P3 — Nice to have

## Suggested Bundled Sessions

None — every finding in this audit is a direct DB migration/schema change, which the fix-flow policy always routes to standalone execution (see below), matching how the prior schema-rls audit (`audits/sweeps/2026-07-10-new-work-sweep/CONSOLIDATED.md`) handled the same category of findings.

## Standalone — do NOT bundle



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
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

## Suggested Bundled Sessions

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
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

## Suggested Bundled Sessions

None — the two surviving findings touch unrelated files and subsystems (auth-hook audit trail vs. account-deletion lifecycle) with no shared root cause.

## Standalone — do NOT bundle



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
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

## Suggested Bundled Sessions

None — the two surviving findings touch unrelated subsystems (menu-scraper sync metadata vs. account-deletion mail dispatch) with no shared file, subsystem, or root cause.

## Standalone — do NOT bundle



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
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

## P3 — Nice to have

## Suggested Bundled Sessions

    - **Why grouped:** Both are code-only changes (a model-level guard + regression test; an enum-case removal) with no DB migration, no auth/money surface, and no shared file — safe to execute together in one low-risk session.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle



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
- P1 High: 0 of 3 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

- [ ] **JOB-101** · P1 — Swallowed exception in `SyncSubdomainToKvJob::handle()` bypasses the moderation-hide gate on a transient DB error
    - **Where:** app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:105-118
    - **Affects:** Any site whose owner is active but whose SITE has just been hidden by moderation (`site.sites.moderation_state = 'hidden'`) — including CSAM-triggered hides (`SuspendSiteJob::resolveSiteId` resolves both `Site` and `SiteMedia` reportable types to the same hide). This job is the exact job `PurgeModerationCacheJob` dispatches right after a hide to retire the route (`app/Jobs/Moderation/PurgeModerationCacheJob.php:51`), so a transient failure reading the just-hidden site's relationship (deadlock, connection blip, replica lag) causes this same sync to silently re-publish the handle instead.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Stop swallowing the exception: remove the `try/catch` around `$pro->site` (or catch, call `$this->fail($e); return;`) so a genuine read failure propagates to Horizon's normal retry path instead of falling through to the publish branch.
        - `SyncSubdomainToKvJob` already carries `$tries = 3` / `$backoff = [10, 30, 60]` / `$maxExceptions = 2` via `HasCloudflareRetryPolicy` — letting the exception surface uses infrastructure that already exists; no new retry policy is needed.
        - Add a regression test that mocks `User::site()` to throw and asserts the job does NOT call `$kv->put($current, ['type' => 'individual'], ...)` when the site read fails.
    - **Technical:** `User::isActive()` (app/Models/Core/User/User.php:128-131) only checks the user-level `status` column — it has no knowledge of `site.sites.moderation_state`, which is the ONLY gate stopping a moderation-hidden site from resolving once the user account itself is still `active`. The `try { $site = $pro->site; } catch (Throwable $e) { report($e); }` block at handle():105-110 leaves `$site = null` on any exception, which makes the subsequent `if ($site && ... === 'hidden')` check false-negative, and execution falls through to `$kv->put($current, ['type' => 'individual'], null)` at line 122 — republishing the handle to the edge. Because `report()` alone doesn't raise a Nightwatch alert (exceptions only alert when they actually propagate), this is invisible to on-call. This job is dispatched precisely in the moderation-hide flow (`PurgeModerationCacheJob::handle()`), so the trigger condition (hide event + a DB blip on the very next relation read) is a real, documented lifecycle path, not a hypothetical.
    - **Plain English:** Imagine a bouncer checking a banned list at the door. If the bouncer's tablet glitches while loading the list, instead of stopping and getting a manager, the bouncer just shrugs and waves everyone through — including people who were supposed to be turned away. That's what happens here: if reading a site's moderation status fails for any reason right after a moderator hides it, the background job republishes the page to the public internet instead of keeping it hidden, and nobody gets notified that it happened.
    - **Evidence:**
        ```php
        $site = null;
        try {
            $site = $pro->site;
        } catch (Throwable $e) {
            report($e);
        }

        // Moderation has hidden the site — retire the route so a hide_site
        // takedown (which hides the SITE, not the user) also stops resolving.
        if ($site && ($site->moderation_state ?? 'active') === 'hidden') {
            $this->retire($kv, $pro);

            return;
        }
        ```

- [ ] **JOB-102** · P1 — Swallowed exception in `SyncSubdomainToKvJob::retire()` can leave a taken-down user's custom domain fully serving their page
    - **Where:** app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:173-185
    - **Affects:** Users who are soft-deleted, suspended, or moderation-hidden AND have an active custom domain (`custom_domain_status = 'active'`). Per the Cloudflare Worker's own routing contract (`cloudflare-worker/src/index.js:427-441`), a `domain:<host>` KV entry carries `{type:'individual', handle:<handle>}` and is resolved **independently** of whether the plain `<handle>` KV entry exists — the Worker forwards straight to `partna-pages` using the handle embedded in the domain entry. This means if `retire()`'s custom-domain delete is skipped, the takedown/hide is INCOMPLETE for anyone visiting via their custom domain: the page stays fully live, even though the handle-based `<handle>.partna.au` route was correctly retired.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Stop swallowing the exception here too: let it propagate (or `$this->fail($e); return;`) instead of `report($e); $site = null;`.
        - The unconditional `$kv->delete($handle)` earlier in `retire()` already ran and is idempotent on retry (a missing-key delete is a no-op at Cloudflare, per the method's own docblock), so retrying the whole `retire()` call is safe.
        - Add a regression test asserting that when the site-relation read throws inside `retire()`, the job does not silently succeed while `domain:<host>` remains undeleted.
    - **Technical:** This shares the exact swallow pattern as JOB-101 (`try { $site = $pro?->site; } catch (Throwable $e) { report($e); $site = null; }`), applied to the takedown path instead of the active-publish path. Because the Worker's custom-domain branch (`index.js:430-442`) never checks for a corresponding `<handle>` KV key — it serves directly off the `domain:<host>` value's own `handle` field — a failed domain delete is not a cosmetic "stale KV slot" as originally assessed; it is a live route that keeps rendering the taken-down page. Re-tiered from the draft's P2 to match JOB-101: same root cause (swallowed `Throwable` on a site-relation read during a takedown-adjacent code path), same class of consequence (a moderation/deletion/suspension action is left incomplete for a real subset of users — anyone on a custom domain).
    - **Plain English:** When an account gets taken down, the system needs to remove two "street signs" pointing at it: the `handle.partna.au` address and, if the user has one, their own custom domain. This bug means that if reading the domain details crashes at the wrong moment, only the first sign comes down — visitors using the person's own custom domain (e.g. `janedoe.com`) still land on the fully live page, defeating the whole point of the takedown.
    - **Evidence:**
        ```php
        try {
            $site = $pro?->site;
        } catch (Throwable $e) {
            report($e);
            $site = null;
        }

        if ($site) {
            $customDomain = strtolower(trim((string) ($site->custom_domain ?? '')));
            if ($customDomain !== '' && ($site->custom_domain_status ?? null) === 'active') {
                $kv->delete("domain:{$customDomain}");
            }
        }
        ```

- [ ] **JOB-103** · P1 — `MenuFetchJob`'s 600s `$timeout` exceeds the `redis` connection's 360s `retry_after`, risking a duplicate concurrent scrape/rebuild
    - **Where:** app/Jobs/Platforms/MenuFetchJob.php:48 (cross-referenced against config/queue.php:70-79)
    - **Affects:** Any user whose menu fetch genuinely runs long — the exact scenario the job's own 600s timeout exists to cover ("Up to two real store scrapes (UE + DD), each retried on empty"). `MenuFetchJob` is dispatched on `config('partna.queues.scraping', 'scraping')`, which runs on Horizon's `supervisor-scraping` using `'connection' => 'redis'` (config/horizon.php:219-231) — the default `redis` queue connection, whose `retry_after` is 360s. Laravel's worker uses the JOB's own `$timeout` (600s) to decide when to `pcntl_alarm`-kill the process, which is correctly *longer* than Horizon's per-supervisor `timeout: 180`, but that same 600s figure is what the job is actually allowed to run for — well past the 360s point at which Redis considers the job's reservation abandoned and hands the identical queued entry to a second free worker (2 processes configured on `supervisor-scraping`). Two workers then run `handle()` concurrently for the same user: two Apify scrapes are billed, and `persist()`'s delete-then-reinsert transaction (app/Jobs/Platforms/MenuFetchJob.php:263-363) is not safe against a second concurrent execution — each worker computes its own `rebuildableCategoryIds()` snapshot before either commits, so both transactions insert their own full set of categories/items, leaving duplicate menu content live on the user's sitepage.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Give `MenuFetchJob` (and the `scraping` queue generally) a connection whose `retry_after` comfortably exceeds 600s, mirroring the pattern this codebase already uses elsewhere: `redis_gdpr` sets `retry_after = 660` specifically because `RedactShopJob::$timeout = 600` (config/queue.php:94-101), and `redis_video` uses `3600` for long ffmpeg encodes. Either route `scraping` onto a comparable dedicated connection, or raise the shared `redis` connection's `REDIS_QUEUE_RETRY_AFTER` default past 600s after confirming no other job sharing that connection depends on faster abandoned-job recovery.
        - Add a test (or extend the existing `JobHygienePolicyTest` family) asserting every job's `$timeout` stays below its resolved connection's `retry_after` — this exact invariant is already documented in three separate places in `config/queue.php` but isn't enforced anywhere.
    - **Technical:** `config/queue.php`'s own comments state the rule explicitly ("Must exceed the longest job $timeout... so a slow job is never re-queued while still running") and the codebase has applied it correctly for every other long-running job (GDPR, video, image aggregates) — `MenuFetchJob` is the one instance where a 600s job timeout was added without also adjusting (or isolating from) the connection's `retry_after`. This is a genuine cross-file invariant violation the exhaustiveness pass is meant to catch, not a duplicate of an existing finding: `ShouldBeUnique` is present on `MenuFetchJob` and correctly prevents a second *dispatch* from being enqueued, but it does nothing to prevent the underlying Redis queue driver from redelivering the *same already-reserved* job payload to a second worker once its invisibility window lapses — that failure mode bypasses the Bus-level uniqueness lock entirely.
    - **Plain English:** Picture a food order that gets handed to a second cook if the first cook hasn't checked in within a fixed time — even though the first cook is still actively cooking it, just taking a bit longer than usual. Now two cooks are making the same dish at once, and both get plated. For this job, "plating" means writing a user's menu to the database — if two copies run at the same time, the customer's live menu page can end up with duplicate dishes.
    - **Evidence:**
        ```php
        // Up to two real store scrapes (UE + DD), each retried on empty; allow
        // headroom for MAX_ATTEMPTS × ATTEMPT_TIMEOUT per platform in MenuApifyScraper.
        public int $timeout = 600;
        ```
        ```php
        'redis' => [
            'driver' => 'redis'
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default')
            'queue' => env('REDIS_QUEUE', 'default')
            // Must exceed the longest job $timeout. RebuildProfessionalHourlyAggregatesJob
            // and RebuildBrandHourlyAggregatesJob have $timeout = 300; use 360 for a
            // 60-second safety margin so a slow job is never re-queued while still running.
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 360)
            'block_for' => null
            'after_commit' => false
        ]
        ```

## Suggested Bundled Sessions

    - **Why grouped:** Same file (`SyncSubdomainToKvJob.php`), same root-cause pattern (swallowed `Throwable` on a `$pro->site`/`$pro?->site` read during a moderation/takedown-adjacent path), same fix shape (stop catching, let Horizon's existing 3-try backoff handle it).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **JOB-103 — `MenuFetchJob` timeout vs. `redis` connection `retry_after`** · standalone because the fix touches shared queue-connection configuration (`config/queue.php` `redis` connection and/or a new dedicated connection wired into `config/horizon.php`) used by every other queue on that connection — a distinct subsystem from the Cloudflare KV findings above, with a blast radius wide enough to warrant its own plan and sign-off rather than bundling.


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
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

## P3 — Nice to have

## Suggested Bundled Sessions

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
- P3 Low: 0 of 1 complete

---

## P2 — Should fix

## P3 — Nice to have

## Suggested Bundled Sessions

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
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 1 complete

---

## P2 — Should fix

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
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 2 complete

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

## P3 — Nice to have

## Suggested Bundled Sessions

    - **Why grouped:** both are small, same-directory (`app/Services/Cloudflare/`) one-liners with no cross-file risk.
    - **Model:** Plan+Implement: Sonnet (S/S effort, combine per policy) · Review: Sonnet.

    - **Why grouped:** both are `cloudflare-worker/` repo-hygiene items (deploy config completeness, test scaffolding) rather than application-logic bugs.
    - **Model:** Plan: Opus (EDGE-104 needs a scaffolding decision) · Implement: Sonnet · Review: Sonnet.

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
- P3 Low: 0 of 2 complete

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
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

## P3 — Nice to have

## Suggested Bundled Sessions

None. Every finding above edits a `supabase/migrations/*.sql` file — per the fix-flow's own rule, any item touching a DB migration/schema change always runs standalone with its own plan + sign-off, never bundled with another finding.

## Standalone — do NOT bundle



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
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 3 complete

---

## P1 — Fix before pilot launch

- [ ] **#API-101** · P1 — `UserSelfController::update()` returns a Resource that unconditionally reads two relations neither `fresh()` nor the controller preloads
    - **Where:** app/Http/Controllers/Api/User/Account/UserSelfController.php:73-88, app/Http/Resources/UserDashboardResource.php:41,47-49
    - **Affects:** Every authenticated user calling `PATCH /api/user/me` (the profile-settings save flow — display name, contact info, account type, sector) — the single most common self-service dashboard write.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Pull Cloud logs first (`cloud env:logs partna development --minutes 60 | grep -i lazy`) to confirm whether this is already firing in the live-serving "development" Cloud env before treating it as theoretical.
        - Mirror `show()`'s pattern in `update()`: reload with the `site` relation explicit (`$professional->fresh(['site'])`) and re-set `partnaStaff` via the same fresh `PartnaStaff` lookup `show()` already does, instead of handing `UserDashboardResource` a relation-less `fresh()` model.
        - Add a `PATCH /api/me` regression test that runs in the default (non-production) test environment and asserts `assertOk()` specifically to catch any future unguarded relation access in `UserDashboardResource` the same way.
    - **Technical:** `AppServiceProvider.php:315` sets `Model::preventLazyLoading(! app()->isProduction())` — in every non-production environment (local, CI/testing, and per this repo's own 2026-06-16 note, plausibly the "development" Laravel Cloud environment that currently serves *both* API domains including production sitepages), accessing an unloaded Eloquent relation throws `LazyLoadingViolationException` instead of silently querying. `Model::fresh()` never carries over previously-loaded relations regardless of what was loaded on the original instance — it always issues a brand-new query with zero eager loads unless explicitly passed via `$with`. `UserSelfController::update()` returns `new UserDashboardResource($professional->fresh())` with no `$with` argument, and `UserDashboardResource::toArray()` reads `$this->partnaStaff` (line 41) and `$this->site` (lines 47-49) unconditionally — neither guarded by `whenLoaded()` — so both trips attempt a lazy load on a model that has no relations loaded. In strict-lazy-loading environments this 500s; in production it silently costs two extra queries per update instead of the intended zero (the doc comment on `partnaStaff` explicitly says "never lazy-loaded here", confirming this was a known constraint the `update()` path violates). DeepSeek's draft caught the `partnaStaff` half of this (tiered P3, "the load succeeds") but missed that `site` is affected by the identical gap and that the codebase's own strict-mode guard turns this from "wasteful" into "throws" outside production.
    - **Plain English:** When someone saves their profile settings (name, phone, account type), the server rebuilds a fresh copy of their record from the database before sending back a confirmation. But that fresh copy is deliberately "bare" — none of the related information (their site, their staff status) comes along automatically — and the confirmation-building code reads both of those without checking whether they're actually there first. In every environment except the real live server, the app is configured to crash loudly the instant this happens, specifically so problems like this get caught before real users see them. Because the environment that's actually serving live customer traffic right now is technically not flagged as "production" in this app's config, there's a real chance this affects real profile-save attempts, not just testing. The fix is small: explicitly fetch the related data before building the response, exactly like the "view profile" endpoint already does correctly.
    - **Evidence:**
        ```php
        public function update(UpdateUserRequest $request)
        {
            $professional = $this->currentUser($request);
            $this->authorizeForUser($professional, 'update', $professional);

            $validated = $request->validated();

            DB::transaction(function () use ($professional, $validated): void {
                $professional->fill($validated);
                $professional->save();
            });

            return $this->success([
                'professional' => new UserDashboardResource($professional->fresh())
            ]);
        }
        ```
        ```php
        'is_staff' => $this->partnaStaff !== null
        ...
        'custom_domain' => $this->site?->custom_domain
        'custom_domain_status' => $this->site?->custom_domain_status
        'custom_domain_primary' => (bool) ($this->site?->custom_domain_primary ?? false)
        ```
        ```php
        // Strict-mode N+1 trap: throw on unloaded relation access outside production
        // so tests/local catch lazy loading instead of leaking slow queries to prod.
        Model::preventLazyLoading(! app()->isProduction());
        ```

## P3 — Nice to have

## Suggested Bundled Sessions

    - **Why grouped:** Same root cause (no Resource class for menu shaping) across the public and dashboard surfaces, with the same duplicated-field-mapping symptom — fix in one session so both surfaces land on shared Resource classes together.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** Single isolated controller, no dependency on the menu work.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#API-101 — `UserSelfController::update()` missing relation preload** · P1 with a plausible live-production crash path (the environment CLAUDE.md documents as currently serving real sitepage traffic is not confirmed to run with `APP_ENV=production`). Needs its own Cloud-log verification pass before implementation, and its own sign-off given the crash-on-a-common-path risk profile.


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
- P2 Medium: 0 of 7 complete
- P3 Low: 0 of 3 complete

---

## P2 — Should fix

## P3 — Nice to have

## Suggested Bundled Sessions

    - **Why grouped:** Same file (`StaffUserSearchFiltersTest.php`); fixing the HTTP-bypass issue naturally requires rewriting the tests as real HTTP calls, which is also the vehicle for adding the Postgres-only `q` coverage.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** All three live in `tests/Feature/Site/CustomDomainTest.php` and touch the same custom-domain subsystem; a single session can add the Cloudflare-failure test, the KV-payload callback, and the migration cross-check together without re-loading context.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle



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
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 4 complete

---

## P2 — Should fix

## P3 — Nice to have

## Suggested Bundled Sessions

    - **Why grouped:** all three touch `app/Services/Platforms/PlatformScraper.php` plus the same set of subclasses (`ShopifyScraper`, `WooCommerceScraper`, `BigCartelScraper`, `GenericShopScraper`) — one small mechanical-cleanup PR.
    - **Model:** Plan Opus · Implement Sonnet · Review Sonnet (all S-effort, low complexity).

    - **Why grouped:** both touch `GoogleBusinessAutoSync.php`; the trait extraction in  is a natural place to also drop the dead `hasStoreKey`/`count` helpers from  in the same pass.
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
- P3 Low: 0 of 1 complete

---

## P3 — Nice to have

## Suggested Bundled Sessions

    - **Why grouped:** Single isolated finding, single file/method — nothing else in this audit shares its root cause.
    - **Model:** Plan: Opus (combine plan+implement given S effort) · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

None.



---
