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

- [ ] **#TXN-2** · P2 — Menu row's `fetch_status` write lands outside the child-rebuild transaction, creating a transient status/content desync on rollback
    - **Where:** app/Jobs/Platforms/MenuFetchJob.php:139-147, 229-256
    - **Affects:** The dashboard poller reading `fetch_status` for a menu whose scrape returned data but whose `persist()` transaction subsequently fails (constraint violation, deadlock) — shows a stuck "syncing" state for the duration of the job's retry/backoff window rather than reflecting the rolled-back state immediately.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the `Menu::updateOrCreate(...)` status-to-`'pending'` write and the `MenuPlatformLink::updateOrCreate` loop inside `persist()`'s transaction, or restructure so the whole mutation sequence (status flip + platform-link upserts + child rebuild) is one atomic unit.
        - Alternatively, since `failed()` already self-heals `fetch_status` to `'unavailable'` on terminal job failure, this can be accepted as bounded risk and closed as a documented trade-off rather than fixed — note the decision either way.
    - **Technical:** `handle()` writes `Menu::updateOrCreate(..., ['fetch_status' => 'pending', ...])` (line 139-147) and upserts `MenuPlatformLink` rows *before* calling `persist()`, which is the only step wrapped in `DB::connection('pgsql')->transaction(...)`. If `persist()`'s delete-then-insert of categories/items/platform-availability rows fails, that transaction rolls back — restoring the old child rows — but the outer `fetch_status = 'pending'` write, having already committed via its own implicit transaction, is untouched. The exposure window is bounded, not indefinite: `maxExceptions = 2` with `backoff = [30, 120]` means `failed()` (which sets `fetch_status = 'unavailable'`) fires within a few minutes of the second exception, self-healing the status field. This narrows DeepSeek's original "indefinitely stuck at pending" claim to a transient (sub-5-minute) desync — real, but bounded and self-correcting, which is why this sits at P2 rather than P1.
    - **Plain English:** Imagine updating a restaurant's menu board: you write "updating menu…" on the whiteboard, then go swap the physical pages. If you trip and have to put the old pages back, the whiteboard still says "updating…" for a little while even though nothing actually changed — until the system notices the whole update failed and corrects the whiteboard itself a few minutes later. It's a real, if temporary, wrong sign — not a permanently broken one.
    - **Evidence:**
        ```php
        $menu = Menu::updateOrCreate(
            ['user_id' => $this->userId],
            [
                'content_source' => $plan['contentSource'],
                'pickup_platform' => $plan['pickupPlatform'],
                'delivery_platform' => $plan['deliveryPlatform'],
                'fetch_status' => 'pending',
            ],
        );
        ```
        ```php
        private function persist(Menu $menu, string $contentSource, array $merged, Carbon $now): void
        {
            DB::connection('pgsql')->transaction(function () use ($menu, $contentSource, $merged, $now) {
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Status/flag flip not atomic with dependent child-row rebuild:** #TXN-1, #TXN-2
    - **Why grouped:** Same root-cause pattern in two different subsystems — a flag/status column commits in its own transaction ahead of a dependent child-row rebuild that runs in a second, separate transaction, so a failure in the second leaves the flag/status pointing at content that doesn't exist. Same fix shape (widen the transaction to cover both writes) applies to both.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

## Standalone — do NOT bundle

None.
