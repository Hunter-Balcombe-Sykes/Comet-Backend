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

- [ ] **#TXN-1** · P2 — `MenuFetchJob` writes platform sync-status metadata ("ok"/"unavailable" + `synced_at`) before the content-rebuild transaction, so a rolled-back rebuild leaves a false "synced" marker
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
                ['menu_id' => $menu->id, 'platform' => $platform],
                ['store_url' => $url],
            );
        }
        ```
        ```php
        foreach ($storeLinks as $platform => $link) {
            MenuPlatformLink::updateOrCreate(
                ['menu_id' => $menu->id, 'platform' => $platform],
                ['synced_at' => $now, 'status' => ($menus[$platform] ?? null) !== null ? 'ok' : 'unavailable'],
            );
        }
        ```
        ```php
        $this->persist($menu, $contentSource, $merged, $now);
        ```

- [ ] **#TXN-2** · P2 — `AccountDeletionService::request()`'s docblock claims the deletion-token write "rolls back automatically" on dispatch failure — it does not, because the job's `afterCommit` flag defers the Redis push past the point of no return
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
         * Initiate a deletion request. Checks preconditions, stores hashed token,
         * queues the confirmation email. Token write + job dispatch + audit log
         * commit atomically: if dispatch infrastructure fails, the token write
         * rolls back automatically — no manual cleanup, no DEL-2 race window.
         ```
        ```php
            DB::connection('pgsql')->transaction(function () use ($professional, $tokenHash, $confirmationUrl, $request) {
                $professional->update([
                    'deletion_token_hash' => $tokenHash,
                    'deletion_requested_at' => now(),
                    'deletion_mail_sent_at' => null,
                ]);

                SendAccountDeletionRequestMailJob::dispatch(
                    $professional->id,
                    $confirmationUrl,
                    $tokenHash,
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

- **#TXN-1 — MenuFetchJob sync-status metadata outside transaction** · standalone: single unrelated S-effort fix, no shared subsystem with #TXN-2.
- **#TXN-2 — AccountDeletionService misleading rollback comment / dispatch ordering** · standalone: touches the account-deletion flow (highest-stakes path per audit doctrine) — run and verify it in isolation even though effort is S.
