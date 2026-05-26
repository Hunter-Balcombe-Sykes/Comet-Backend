`★ Insight ─────────────────────────────────────`
The DeepSeek draft has two systematic errors here: (1) it assumed `AccountTypeTransitionEvent` fires inside the transaction without checking `AccountTypeTransitionService` — the real code dispatches it at line 136, explicitly **after** the `DB::transaction` block closes. (2) The comment in `ShopifyDataResyncService` (`"queues hold them until commit"`) is factually wrong — Laravel dispatches to Redis immediately, the comment reflects a misconception but the finding is real. These opposite failure modes (false positive from missing context; true positive hiding behind a wrong comment) are typical DeepSeek miss patterns.
`─────────────────────────────────────────────────`

# Transaction Boundary Audit — 2026-05-21

**Branch:** development
**Lens:** Whole-backend PILOT audit — 'txn' lens
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/Services/Professional/DataExport/DataExportService.php`
- `app/Services/Shopify/ShopifyDataResyncService.php`
- `app/Services/Stripe/CommissionPayoutRefundService.php`
- `app/Services/Cache/SiteCacheService.php`
- `app/Services/Cache/ProfessionalCacheService.php`
- `app/Http/Controllers/Api/PublicSite/BootstrapController.php`
- `app/Http/Controllers/Api/Professional/Brand/ShopifyIntegrationController.php`
- `app/Http/Controllers/Api/Professional/Brand/BrandAffiliateInviteController.php`
- `app/Http/Controllers/Api/Professional/Store/BrandStoreSettingsController.php`
- `app/Services/Professional/Brand/BrandAffiliateInviteService.php`
- `app/Services/Professional/Brand/BrandPartnerLinkService.php`
- `app/Services/Professional/Brand/BrandPartnerLinkLifecycleService.php`
- `app/Listeners/Accounts/` (three listeners)
- `app/Listeners/RecordCacheMetrics.php`
- `app/Jobs/Shopify/ProcessShopifyOrderUpdatedWebhookJob.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 2 complete
- P2 Medium: 0 of 8 complete
- P3 Low: 0 of 3 complete

---

## P1 — Fix before pilot launch

- [ ] **#TXN-1** · P1 — `ExportProfessionalDataJob` dispatched inside `DB::transaction` in GDPR export flow
    - **Where:** `app/Services/Professional/DataExport/DataExportService.php:59`
    - **Affects:** GDPR data-export initiation path. If the transaction rolls back after the Redis dispatch (e.g. a future validation step throws before `return $audit`), the job fires against a non-existent `DataExportAudit` row and the export silently never completes — the user receives no data and no error.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the dispatch in `DB::afterCommit(fn() => ExportProfessionalDataJob::dispatch($audit->id))` inside the transaction closure, OR move the dispatch to immediately after the `DB::transaction(...)` call returns.
        - Either approach guarantees the audit row is durable in Postgres before the worker tries to read it.
    - **Technical:** `ExportProfessionalDataJob::dispatch($audit->id)` at line 59 pushes to the Redis queue immediately — Laravel does not hold queue writes until commit. The surrounding `DB::connection('pgsql')->transaction(...)` starts at line 35 and ends at line 62. If anything between `DataExportAudit::create(...)` (line 49) and `return $audit` (line 61) throws — or if a future developer adds a post-create validation step that fails — the transaction rolls back, the audit row disappears, and the job is already in Redis. The job calls `DataExportAudit::findOrFail($audit->id)` and will either throw (job fails silently) or, if retry logic re-queues it, hammer the DB until the retry limit is hit. The dedup lock-for-update guard on line 38 provides no protection since it only fires on a second request. The canonical fix is `DB::afterCommit()`.
    - **Plain English:** When a user asks to download their data, the system creates a paper trail (audit record) and immediately hands off to a worker to do the actual export — but it does this in the wrong order. It tells the worker to start before confirming the paper trail was successfully filed. If filing the paper trail fails for any reason, the worker is already running with instructions referencing a record that doesn't exist. The fix: file the paper first, then hand off to the worker.
    - **Evidence:**
        ```php
        return DB::connection('pgsql')->transaction(function () use ($professional, $triggeredBy, $staffId, $sendTo, $recipient) {
            // ...lockForUpdate dedup check...
            $audit = DataExportAudit::create([
                'professional_id' => $professional->id,
                // ...
            ]);

            ExportProfessionalDataJob::dispatch($audit->id);  // ← fires to Redis immediately

            return $audit;
        });
        ```

- [ ] **#TXN-2** · P1 — `SyncShopifyBrandDesignJob` dispatched inside `DB::transaction` with an incorrect comment claiming Laravel holds the dispatch until commit
    - **Where:** `app/Services/Shopify/ShopifyDataResyncService.php:58–60`
    - **Affects:** Shopify brand resync path. If the wrapping transaction rolls back (constraint violation, model save failure, etc.), the `SyncShopifyBrandDesignJob` has already been queued. It runs against a brand integration that may still carry the pre-resync `last_resynced_at` value, silently producing a no-op resync or corrupting the brand's design state.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `SyncShopifyBrandDesignJob::dispatch($integrationId)` with `DB::afterCommit(fn() => SyncShopifyBrandDesignJob::dispatch($integrationId))`.
        - Remove the comment `"Dispatched inside the transaction on purpose — queues hold them until commit"` — this is factually wrong; Laravel dispatches to Redis synchronously regardless of transaction state.
    - **Technical:** Laravel's queue dispatcher writes directly to Redis at the point of the `dispatch()` call. There is no deferred-until-commit behaviour for non-database queue drivers. The comment on line 58 is categorically incorrect and will mislead future maintainers into believing this pattern is safe. If the `DB::transaction` closure rolls back (e.g. `$integration->mergeProviderMetadata(...)` throws a model exception), the job has already been enqueued. The `SyncShopifyBrandDesignJob` worker reads the integration row — which still holds the old `last_resynced_at` — and may overwrite design settings with stale data. The fix is `DB::afterCommit(...)`, which fires immediately when no transaction is open and defers when one is.
    - **Plain English:** The code has a note in it that says "don't worry, this task won't start until we've confirmed everything is saved." That note is wrong — the task starts immediately, even if the save is later cancelled. If the save gets cancelled (something goes wrong during the database write), the task is already running using outdated information, and it quietly overwrites the brand's design settings with stale data from Shopify. Removing the wrong note and using the correct mechanism is a one-line fix.
    - **Evidence:**
        ```php
        $diff = DB::connection('pgsql')->transaction(function () use ($integration, $integrationId, $shopData, $lastResyncedAt) {
            $diff = $this->autoFill->resyncFromShopData($integration, $shopData);
            $integration->mergeProviderMetadata(['last_resynced_at' => $lastResyncedAt]);
            // Dispatched inside the transaction on purpose — queues hold them until commit,
            // so a rollback prevents an orphaned brand-design job from firing.
            SyncShopifyBrandDesignJob::dispatch($integrationId);
            return $diff;
        });
        ```

---

## P2 — Should fix

- [ ] **#TXN-3** · P2 — `bustPayoutCaches` called twice inside `DB::transaction` in commission refund path
    - **Where:** `app/Services/Stripe/CommissionPayoutRefundService.php:107, 121`
    - **Affects:** Affiliate payout dashboard. On a transaction rollback (e.g. a concurrent status change causes a constraint violation), the cache keys for the affiliate's payout state have already been evicted. The next read re-warms from a DB that still holds the pre-rollback state — correct data, but an unnecessary cold read that briefly exposes a "cache miss" window. On the more likely path (no rollback), this is safe.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap both `$this->bustPayoutCaches($order)` calls in `DB::afterCommit(fn() => $this->bustPayoutCaches($order))` so cache eviction only happens after the transaction commits.
        - Alternatively, move the calls to after the `DB::transaction(...)` block returns (they are currently the last statement in each branch so this is a simple hoist).
    - **Technical:** `bustPayoutCaches()` calls `Cache::forget($stateKey)` and `Cache::forget($stateKey.':stale')` (lines 149–150). These fire synchronously inside the `DB::transaction` closure. On rollback, the cache is already cleared but the DB state is unchanged — the next SWR reader re-queries and re-warms correctly, so this is a churn bug rather than a stale-data bug. The risk is low frequency (rollbacks on this path are uncommon) but the pattern directly contradicts the "no cache mutations inside transactions" discipline.
    - **Plain English:** When a refund is processed, the system clears a cache so the affiliate's payout dashboard shows fresh data. It does this erasing inside a locked room (the database transaction) before it's confirmed the refund was actually saved. If the save fails and the room gets unlocked with the original contents, the erasing already happened — the dashboard cache is blank. The next person who asks gets correct data (pulled fresh from the database), but they had to wait longer than necessary for no good reason. The fix is to erase the cache only after the save is confirmed.
    - **Evidence:**
        ```php
        $clawbackPlan = DB::transaction(function () use ($order, $incrementalRefundCents, $shopifyRefundId): ?array {
            // ...
            if ($payout->status === 'processing') {
                $this->flagMidFlight($payout, $order);
                $this->bustPayoutCaches($order);  // ← cache write inside transaction
                return null;
            }
            // ...
            $this->bustPayoutCaches($order);  // ← cache write inside transaction
            return null;
        });
        ```

- [ ] **#TXN-4** · P2 — `NotificationPublisher::publish` dispatches inside `DB::transaction` via `flagMidFlight`
    - **Where:** `app/Services/Stripe/CommissionPayoutRefundService.php:104` (call to `flagMidFlight`) → `app/Services/Stripe/CommissionPayoutRefundService.php:165` (publish inside `flagMidFlight`)
    - **Affects:** Brand dashboard notifications. If the outer `DB::transaction` rolls back after `flagMidFlight` saves `needs_manual_refund = true` and calls `publish()`, the brand receives a "Refund flagged for manual review" notification for a payout state change that was never committed. Ops would investigate a flag that doesn't exist.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the `NotificationPublisher::publish(...)` call inside `flagMidFlight` with `DB::afterCommit(fn() => app(NotificationPublisher::class)->publish(...))` so the notification fires only if the `needs_manual_refund` save actually commits.
        - The `$payout->forceFill(...)->save()` call can stay inside the transaction — only the publish needs deferring.
    - **Technical:** `flagMidFlight()` is called at line 104 inside a `DB::transaction` closure. Inside `flagMidFlight`, `$payout->forceFill(['needs_manual_refund' => true])->save()` writes the flag (correctly inside the transaction), then `NotificationPublisher::publish(...)` fires at line 165. `NotificationPublisher` inserts a notification row AND dispatches `SendTransactionalNotificationEmailJob`. If the outer transaction rolls back (rare but possible on concurrent status mutation), the notification row rolls back with the transaction — but the queued email job was already handed to Redis. The job either sends an email referencing a non-existent notification row or, if it does a lookup, fails silently and the email is dropped. Either outcome is wrong for a financial path.
    - **Plain English:** When a brand's payout gets flagged for manual review during a refund, the system sends them a warning notification. The warning goes out inside the locked room (the transaction) before the flag has been officially saved. If the flag gets cancelled (the transaction rolls back), the warning is already on its way. The brand's inbox says "your payout needs manual attention" but there's no flag anywhere in the system. For a money path, phantom financial notifications erode trust. The fix is to send the warning only after the flag is confirmed saved.
    - **Evidence:**
        ```php
        // Inside handleOrderRefund's DB::transaction:
        if ($payout->status === 'processing') {
            $this->flagMidFlight($payout, $order);  // ← publish() fires inside here
            $this->bustPayoutCaches($order);
            return null;
        }

        // Inside flagMidFlight:
        private function flagMidFlight(CommissionPayout $payout, Order $order): void
        {
            $wasFlagged = (bool) $payout->needs_manual_refund;
            $payout->forceFill(['needs_manual_refund' => true])->save();

            if (! $wasFlagged) {
                try {
                    app(NotificationPublisher::class)->publish(
                        professionalId: (string) $payout->brand_professional_id,
                        frontendType: 'Warning',
                        category: 'commissions',
                        title: 'Refund flagged for manual review',
                        // ...
                    );
                } catch (\Throwable $notifyEx) { ... }
            }
        }
        ```

- [ ] **#TXN-5** · P2 — `SiteCacheService::invalidateSite()` dispatches `InvalidateBrandAffiliatesCacheJob` without `DB::afterCommit` guard
    - **Where:** `app/Services/Cache/SiteCacheService.php` (closing lines of `invalidateSite()`)
    - **Affects:** Any call site that invokes `invalidateSite()` directly inside a `DB::transaction` — including `ProfessionalCacheService::invalidateProfessional()` when called from non-observer contexts. On transaction rollback, the job is queued and re-reads stale site state to re-poison affiliate caches.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the dispatch with `DB::afterCommit(fn() => InvalidateBrandAffiliatesCacheJob::dispatch($professionalId))`. `afterCommit` fires immediately when no transaction is active, so observer-called paths (which are already deferred via `$afterCommit = true`) are unaffected.
        - This single change makes `invalidateSite()` safe to call from any context.
    - **Technical:** Observers set `$afterCommit = true`, which defers the entire observer method until after the transaction commits — so observer-originated calls to `invalidateSite()` are safe today. However, `invalidateSite()` is also reachable from `ProfessionalCacheService::invalidateProfessional()`, which is called from event listeners and controllers that may or may not be inside a transaction. Adding `DB::afterCommit()` at the dispatch site makes the method defensively safe regardless of the calling context, following the principle that side-effect methods shouldn't require callers to reason about transaction state.
    - **Plain English:** A function that clears affiliate caches currently fires its background worker immediately when called. This is safe when called from model event hooks (which already wait for the database save to finish), but unsafe when called from other parts of the code mid-transaction. Adding a one-line "wait until the save is confirmed" wrapper around the dispatch makes it safe everywhere without any other changes.
    - **Evidence:**
        ```php
        // app/Services/Cache/SiteCacheService.php — closing lines of invalidateSite()
        if ($professionalId !== '') {
            InvalidateBrandAffiliatesCacheJob::dispatch($professionalId);
        }
        ```

- [ ] **#TXN-6** · P2 — `ProfessionalCacheService::invalidateProfessional` called inside `BootstrapController`'s `DB::transaction`
    - **Where:** `app/Http/Controllers/Api/PublicSite/BootstrapController.php:451`
    - **Affects:** New account creation and account-update flows. If the transaction rolls back after line 451 (e.g. `ensureFreeSubscription` or `createWelcomeNotification` throws), the Professional's cache keys are already evicted. The next read re-warms from DB with the pre-bootstrap state — correct data, but an unnecessary cold path that also triggers the `invalidateSite()` → `InvalidateBrandAffiliatesCacheJob` dispatch chain (see TXN-5) for a Professional that may not yet exist.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `app(ProfessionalCacheService::class)->invalidateProfessional($professional)` to after the `DB::transaction(...)` block returns (it currently appears before `ensureFreeSubscription` and `createWelcomeNotification` inside the closure).
        - Cache invalidation on account creation is non-critical (the Professional wasn't in cache yet), so moving it post-commit has no semantic downside.
    - **Technical:** The `DB::transaction(...)` closure at line 155 closes at line 465. `invalidateProfessional()` fires at line 451, before the closing `return [...]`. If `ensureFreeSubscription()` (line 454) or `createWelcomeNotification()` (line 457) throws, the transaction rolls back all DB mutations — but the cache invalidation already fired, including a `InvalidateBrandAffiliatesCacheJob` dispatch (via TXN-5) for a Professional that was just rolled back. Moving the invalidation outside the transaction costs nothing and follows the "no cache writes inside transactions" discipline.
    - **Plain English:** During account setup, the system is doing many things at once inside a locked room: creating the account, setting up a subscription, writing a welcome notification. Near the end of this process — but before the room is unlocked — it also tells the cache "forget everything you know about this user." If the welcome notification step fails and the room gets reset to its original state (the whole signup is rolled back), the cache has already been wiped. The user never got created, but the cache is acting like they were. Moving the cache wipe to after the room is unlocked (the transaction commits) costs nothing.
    - **Evidence:**
        ```php
        $result = DB::transaction(function () use (...) {
            // ~300 lines of Professional creation, Site creation, brand-attach, etc.

            app(ProfessionalCacheService::class)->invalidateProfessional($professional);  // ← line 451

            // Ensure the professional has a subscription – seed the free plan if none exists
            $this->siteProvisioning->ensureFreeSubscription($professional);  // ← line 454, can throw

            if ($createdProfessional) {
                $this->createWelcomeNotification($professional);  // ← line 457, can throw
            }

            return [...];
        });  // closes line 465
        ```

- [ ] **#TXN-7** · P2 — `BootstrapController` transaction scope too coarse — welcome notification failure rolls back entire account creation
    - **Where:** `app/Http/Controllers/Api/PublicSite/BootstrapController.php:155–465`
    - **Affects:** All new signups. If `createWelcomeNotification()` (a non-critical `Notification::firstOrCreate`) throws — e.g. a DB constraint, a lock timeout — the entire 300-line transaction rolls back, destroying the newly created `Professional`, `Site`, and all associated rows. The user gets a 500 and must restart signup from scratch.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Identify the minimal atomic unit: `Professional` + `Site` + `AccountTypeDefaults` + `BrandProfile` creation must be atomic. These are the rows that don't make sense in isolation.
        - Extract `ensureFreeSubscription` and `createWelcomeNotification` to run **after** the transaction returns. Both are idempotent (`firstOrCreate`) and non-critical; a failure in either should not destroy the account.
        - Consider the same extraction for `syncSiteBrandPartnerSettings` on the brand-attach paths — site settings sync is not a core creation concern.
    - **Technical:** The `DB::transaction` closure at line 155 wraps ~310 lines covering Professional/Site creation, all three brand-attach branches, Shopify integration creation, `ShopProfileAutoFillService::fillFromShopData`, cache invalidation, `SiteProvisioningService::ensureFreeSubscription` (EmailSubscription upsert), and `Notification::firstOrCreate`. Any exception in the last two operations rolls back every preceding write. These operations are idempotent (`firstOrCreate`, `updateOrCreate`) and have no FK dependencies on rows created inside the transaction — they can safely run post-commit. The narrow transaction (just Professional + Site creation) also reduces deadlock surface under concurrent signups hitting `core.professionals` and `core.sites`.
    - **Plain English:** When a new user signs up, the system does everything in one giant "all or nothing" box: create the account, set up the subscription, and send a welcome notification. If writing the welcome notification fails for any reason — even a tiny database hiccup — the account itself gets deleted too, as if they never signed up. The welcome notification is the least important step; there's no reason it should have veto power over account creation. The fix is to take the welcome notification (and the subscription setup) out of the all-or-nothing box — if they fail, the account still gets created and those steps can be retried independently.
    - **Evidence:**
        ```php
        $result = DB::transaction(function () use (...) {
            // Professional creation/update + save()
            // ensureSidestUpdatesSubscription() — EmailSubscription upsert
            // Site creation via createSiteWithRetry()
            // AccountTypeDefaultsService::applyDefaults()
            // BrandProfile firstOrCreate
            // 3 brand-attach branches with claimInvite / connectBrandToAffiliate
            // brand_signup_code resolution + claim
            // Shopify ProfessionalIntegration::create()
            // ShopProfileAutoFillService::fillFromShopData()
            // ProfessionalCacheService::invalidateProfessional()
            $this->siteProvisioning->ensureFreeSubscription($professional);  // idempotent, non-critical
            if ($createdProfessional) {
                $this->createWelcomeNotification($professional);  // idempotent, non-critical
            }
            return [...];
        });
        ```

- [ ] **#TXN-8** · P2 — Shopify connect flow writes three tables without a wrapping transaction
    - **Where:** `app/Http/Controllers/Api/Professional/Brand/ShopifyIntegrationController.php:~183, ~198, ~207`
    - **Affects:** Brands connecting a Shopify store. If `ShopProfileAutoFillService::fillFromShopData()` throws after `ProfessionalIntegration::updateOrCreate` and `BrandProfile::firstOrCreate` succeed, the brand has a committed integration row and brand-profile row but no auto-filled site/profile data — a partially-connected state that appears fully connected.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap `ProfessionalIntegration::updateOrCreate(...)`, `BrandProfile::firstOrCreate(...)`, and `ShopProfileAutoFillService::fillFromShopData(...)` in a single `DB::transaction(...)`.
        - Keep any job dispatches (e.g. KV sync, Shopify webhook registration) **outside** the transaction as they already are.
    - **Technical:** Three independent auto-committed writes currently execute in sequence. If the third (`fillFromShopData`) throws — e.g. a JSONB validation failure, a unique constraint on auto-filled data, or a Shopify API error inside the service — the first two rows are permanently committed. The brand's dashboard shows them as connected with a `BrandProfile` row, but `site.settings` and professional fields are at their default/stale values. A subsequent reconnect would be idempotent (`updateOrCreate`/`firstOrCreate`) but the auto-fill data would still be missing if the underlying error persists. Wrapping in a transaction makes the connection operation atomic at no performance cost.
    - **Plain English:** Connecting a Shopify store involves three database writes: recording the connection, creating a brand profile, and filling in shop details. These three steps happen one after another with no coordination. If the third step fails, the first two are already permanent — the system thinks the brand is connected but their profile is missing the Shopify data. Wrapping all three in one "all or nothing" block means a failure in any step undoes all three, and the brand can safely retry the connection.
    - **Evidence:**
        ```php
        // ← auto-committed immediately
        $integration = ProfessionalIntegration::query()->updateOrCreate(
            ['professional_id' => $targetBrandId, 'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY],
            [...]
        );

        // ← separate auto-commit
        BrandProfile::firstOrCreate(
            ['professional_id' => $targetBrandId],
            ['setup_complete' => false]
        );

        // ← additional DB writes, no transaction wrapping
        if (is_array($shopData) && $shopData !== []) {
            app(ShopProfileAutoFillService::class)->fillFromShopData(
                $professional, $site, $brandProfile, $shopData, $integration
            );
        }
        ```

- [ ] **#TXN-9** · P2 — Affiliate invite claim splits three mutations across independent commits
    - **Where:** `app/Http/Controllers/Api/Professional/Brand/BrandAffiliateInviteController.php:~266, ~275, ~283`
    - **Affects:** Affiliates claiming brand invites. If `AccountTypeTransitionService::transition()` throws `InvalidAccountTypeTransition` after `claimInvite()` succeeds, the invite is permanently marked `accepted` and a `BrandPartnerLink` row exists, but the affiliate's `account_type` is still `individual` — an inconsistent state that breaks the affiliate's dashboard.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap `$inviteService->claimInvite(...)` and `$transitionService->transition(...)` in an outer `DB::transaction(...)`. Both methods internally open their own transactions (which nest as SAVEPOINTs), so the outer transaction makes the pair atomic.
        - The subsequent `syncSiteBrandPartnerSettings` call can remain outside the transaction since it only writes `site.settings` and is idempotent.
        - Alternatively, move the `account_type` transition inside `claimInvite`'s existing transaction.
    - **Technical:** `claimInvite()` marks the invite `accepted` and creates a `BrandPartnerLink` inside its own `DB::transaction`. If this succeeds but the subsequent `transition($professional, AccountType::Partner)` throws `InvalidAccountTypeTransition` (e.g. a race condition where the professional transitioned to brand via another path), the invite claim is committed but the type flip is not. The controller catches `InvalidAccountTypeTransition` and returns a 422 — the invite is consumed, the link exists, but the professional is still `individual`. The affiliate cannot re-claim the invite and the brand cannot revoke it in normal UI flows.
    - **Plain English:** Accepting a brand invite requires three things to happen: mark the invitation as used, create the affiliate relationship, and flip the account type to "partner." Right now these happen in sequence with no safety net. If flipping the account type fails, the invitation is already permanently marked as used and the relationship already exists — but the user's account type never changed. Their account is now in a broken state: the system thinks they're affiliated but their account type says otherwise. Wrapping the first two steps in an all-or-nothing block prevents this split.
    - **Evidence:**
        ```php
        // Step 1: invite claimed, link created — has its own internal transaction
        $claimedInvite = $inviteService->claimInvite($invite, $professional);

        // Step 2: account_type flip — separate, can throw after step 1 commits
        try {
            $transitionService->transition(
                $professional->fresh() ?? $professional,
                AccountType::Partner
            );
        } catch (InvalidAccountTypeTransition $e) {
            return $this->error($e->getMessage(), 422);
        }

        // Step 3: site settings sync — separate, idempotent
        $site = Site::query()->where('professional_id', $professional->id)->first();
        if ($site) {
            $this->syncSiteBrandPartnerSettings($site, $brandPartnerLinks, (string) $professional->id);
        }
        ```

- [ ] **#TXN-10** · P2 — `BrandStoreSettings` and `Site` writes are two independent auto-commits
    - **Where:** `app/Http/Controllers/Api/Professional/Store/BrandStoreSettingsController.php:~78–92, ~105–115`
    - **Affects:** Brands updating store settings (commission rate, payout hold days, design theme). If `$site->save()` fails after `BrandStoreSettings::updateOrCreate(...)` commits, the settings table holds the new values but `site.settings.design` (which Hydrogen reads for storefront rendering) still reflects the old values — the brand's dashboard and their live storefront show different settings.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap `BrandStoreSettings::updateOrCreate(...)` and `$site->save()` in a single `DB::transaction(...)`.
        - Keep the Shopify metafield sync (external HTTP) outside the transaction as it already is.
    - **Technical:** `BrandStoreSettings::updateOrCreate` (line ~78) is a standalone auto-committed write to `brand.brand_store_settings`. `$site->save()` (line ~105) is a separate auto-committed write to `core.sites.settings` JSONB. These two tables are the dual source of truth for store settings: the settings table is the canonical DB record; the site JSON is what Hydrogen reads for edge rendering. On a JSONB constraint violation or Postgres lock timeout on the `sites` row, the BrandStoreSettings row commits with new `default_commission_rate` or `payout_hold_days` while the site JSON mirrors them at the old value. Different parts of the system reading different sources will disagree until the site JSON is patched.
    - **Plain English:** A brand's store settings live in two places: a dedicated row in a settings table, and a JSON field on their site record. When a brand changes their commission rate, the system updates the settings table first, then the site record. If the site record update fails for any reason, the settings table says the new rate but the site record — which controls how the storefront works — still shows the old rate. Depending on which part of the system you ask, you get different answers. Wrapping both updates in an all-or-nothing block means they always stay in sync.
    - **Evidence:**
        ```php
        // ← auto-committed immediately
        if (! empty($dbFields) || $hasOxygenToken) {
            $settings = BrandStoreSettings::updateOrCreate(
                ['professional_id' => $pro->id],
                $dbFields
            );
        }

        // ← separate auto-commit, can fail independently
        if (! empty($designUpdates) && $site) {
            $settings = is_array($site->settings) ? $site->settings : [];
            $design = is_array($settings['design'] ?? null) ? $settings['design'] : [];
            foreach ($designUpdates as $key => $value) {
                $design[$key] = $value;
            }
            $settings['design'] = $design;
            $site->settings = $settings;
            $site->save();
        }
        ```

---

## P3 — Nice to have

- [ ] **#TXN-11** · P3 — Nested transactions in `claimOpenInvite` and `claimInvite` via `connectBrandToAffiliate` are undocumented
    - **Where:** `app/Services/Professional/Brand/BrandAffiliateInviteService.php:~274, ~310` and `app/Services/Professional/Brand/BrandPartnerLinkService.php:~83`
    - **Affects:** Future maintainers reasoning about rollback behaviour in the invite claim path.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a comment above the `connectBrandToAffiliate(...)` call in both `claimOpenInvite` and `claimInvite` noting that the inner `DB::transaction` becomes a SAVEPOINT under the outer transaction, and that a rollback of the outer transaction also rolls back link creation — which is the desired behaviour.
    - **Technical:** Laravel converts inner `DB::transaction(...)` calls into `SAVEPOINT` / `RELEASE SAVEPOINT` when an outer transaction is already active. This means `connectBrandToAffiliate`'s internal transaction is not independently atomic when called from `claimOpenInvite` or `claimInvite` — it succeeds or fails with the outer call. This is correct behaviour (you can't have an accepted invite without a link), but it's not obvious from reading either method in isolation. No code change required; documentation prevents a future refactor from breaking the atomicity assumption.
    - **Plain English:** Two methods each call a third method that has its own "all or nothing" wrapper — but when called from inside another "all or nothing" block, the inner wrapper is automatically promoted to a "undo-this-part-only" checkpoint. This works correctly, but it's invisible to anyone reading the inner method by itself. A short comment explaining the intent prevents future confusion.
    - **Evidence:**
        ```php
        // claimOpenInvite — outer transaction
        return DB::transaction(function () use ($brandProfessional, $affiliate): BrandAffiliateInvite {
            $this->brandPartnerLinks->connectBrandToAffiliate($affiliateId, $brandId);  // ← nests as SAVEPOINT
            $invite->save();
            return $invite->fresh([...]);
        });

        // connectBrandToAffiliate — inner transaction (same code, both call sites)
        return DB::transaction(function () use ($affiliateProfessionalId, $brandProfessionalId): BrandPartnerLink {
            // ...
        });
        ```

- [ ] **#TXN-12** · P3 — `BrandPartnerLinkLifecycleService::disconnect()` has undocumented two-level nested transaction chain
    - **Where:** `app/Services/Professional/Brand/BrandPartnerLinkLifecycleService.php:~57` (outer) → `CommissionVoidService::voidOrder` + `BrandPartnerLinkService::disconnectBrandFromAffiliate` (inner)
    - **Affects:** Future maintainers debugging partial-disconnect failures.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a doc-comment block above `disconnect()` listing the full transaction chain: outer wraps void-loop (which opens SAVEPOINTs per order) and link deletion (another SAVEPOINT). Clarify that an exception from the deepest nesting level propagates upward and rolls back the entire outer transaction, which is the desired all-or-nothing behaviour.
    - **Technical:** Two levels of SAVEPOINT nesting: the outer `DB::transaction` in `disconnect()` wraps `voidPendingForAffiliateBrand()` which calls `voidOrder()` (which opens its own `DB::transaction` → SAVEPOINT), and `disconnectBrandFromAffiliate()` (also its own `DB::transaction` → SAVEPOINT). The logic is correct — void + disconnect must be atomic. The documentation gap means that when a deep SAVEPOINT fails and the exception propagates, the rollback semantics aren't obvious without tracing through three service files.
    - **Plain English:** The disconnect flow involves three nested "all or nothing" operations stacked inside each other. This is intentional — you want the void and the disconnect to either both happen or both not happen. But the nesting is invisible to anyone reading any single method. A brief comment at the top of the disconnect method explaining the chain saves the next engineer 30 minutes of tracing.
    - **Evidence:**
        ```php
        // BrandPartnerLinkLifecycleService — outer
        return DB::transaction(function () use ($req): DisconnectResult {
            $voidResult = $this->commissionVoid->voidPendingForAffiliateBrand(  // → DB::transaction per voidOrder
                $req->affiliate->id, $req->brand->id, $voidReason,
            );
            $this->linkService->disconnectBrandFromAffiliate(  // → DB::transaction
                $req->affiliate->id, $req->brand->id,
            );
        });
        ```

- [ ] **#TXN-13** · P3 — `RecordCacheMetrics` listener counts Redis operations from rolled-back transactions
    - **Where:** `app/Listeners/RecordCacheMetrics.php:35–50`
    - **Affects:** Cache hit-rate dashboards. `Redis::hIncrBy` fires on every `CacheHit`, `CacheMissed`, and `KeyWritten` event — including those triggered inside a `DB::transaction` that later rolls back. The metric bucket slightly overcounts writes that didn't result in committed state.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Accept the fractional skew (rollbacks are rare and the overcounting is bounded by the rollback rate), OR wrap the `Redis::hIncrBy` in `DB::afterCommit(fn() => ...)` so only committed cache operations are counted. The latter is semantically cleaner for an SLO dashboard.
    - **Technical:** The listener fires synchronously on `KeyWritten` events. If a cache write occurs inside a `DB::transaction` that subsequently rolls back (e.g. the `Cache::put` inside `ToggleStripeRequirementBannerOnTransition`), the `KeyWritten` event fires and the metrics bucket is incremented before the transaction outcome is known. The cache write itself is a rollback victim (in other findings), but the Redis counter is not — it records a write that logically never happened. The skew is proportional to the application's transaction rollback rate, which is low in normal operation.
    - **Plain English:** The system keeps a running tally of how often the cache is read from vs. written to — this is used for performance dashboards. The tally is updated the moment a cache write happens, even if the cache write is later cancelled because the surrounding database operation failed. The tally ends up slightly inflated. It's a minor bookkeeping imperfection — nobody gets charged, no data is corrupted — but the numbers on the dashboard are slightly optimistic.
    - **Evidence:**
        ```php
        public function handle(CacheHit|CacheMissed|KeyWritten $event): void
        {
            $prefix = $this->extractPrefix($event->key);
            if ($prefix === null) { return; }
            $bucket = now('UTC')->format('Y-m-d-H');
            $type = match (true) {
                $event instanceof CacheHit => 'hits',
                $event instanceof CacheMissed => 'misses',
                default => 'writes',
            };
            try {
                $bucketKey = "cache_metrics:{$bucket}";
                $newValue = Redis::hIncrBy($bucketKey, "{$prefix}:{$type}", 1);
                if ($newValue === 1) {
                    Redis::expire($bucketKey, self::BUCKET_TTL_SECONDS);
                }
            } catch (\Throwable $e) { /* ... */ }
        }
        ```
