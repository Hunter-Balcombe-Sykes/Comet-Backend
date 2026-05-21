- [ ] **#TXN-1** · P2 — Multiple DB writes in Shopify connect flow lack transaction boundary
    - **Where:** app/Http/Controllers/Api/Professional/Brand/ShopifyIntegrationController.php:170-210
    - **Affects:** Brands connecting Shopify; partial writes can leave integration row committed while BrandProfile or auto-filled profile/site data fails.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the `updateOrCreate`, `firstOrCreate`, and `fillFromShopData` calls in a single `DB::transaction(...)` so the integration row, brand profile, and auto-filled site/professional data commit or roll back atomically.
        - Move job dispatches outside the transaction (they already are — just ensure they stay outside after the wrap).
    - **Technical:** The `ProfessionalIntegration::updateOrCreate(...)` call at line ~183 commits immediately. `BrandProfile::firstOrCreate(...)` at line ~198 is a separate auto-commit. `ShopProfileAutoFillService::fillFromShopData(...)` at line ~207 may perform additional DB writes. If `fillFromShopData` throws, the integration and brand profile rows are already committed but the auto-filled data is missing — the brand gets a connected integration with stale/null profile fields. Wrapping all three in a single transaction makes the connect operation atomic. Category (6) — transaction scope too narrow.
    - **Plain English:** When a brand connects their Shopify store, the system writes to three different places in the database one after another. If the third write fails, the first two are already permanent and you're left with a half-set-up connection. Think of it like signing a three-page contract but only the first two pages are filed — the third page (the auto-filled profile data from Shopify) goes missing. The fix is to make all three writes happen as one unit: either they all succeed, or none of them do.
    - **Evidence:**
        ```php
        // Line ~183 — auto-committed immediately
        $integration = ProfessionalIntegration::query()->updateOrCreate(
            ['professional_id' => $targetBrandId, 'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY],
            [...]
        );

        // Line ~198 — separate auto-commit
        BrandProfile::firstOrCreate(
            ['professional_id' => $targetBrandId],
            ['setup_complete' => false]
        );

        // Line ~207 — may perform additional DB writes
        if (is_array($shopData) && $shopData !== []) {
            // ...
            app(ShopProfileAutoFillService::class)->fillFromShopData(
                $professional, $site, $brandProfile, $shopData, $integration
            );
        }
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#TXN-2** · P2 — Multi-step claim mutation spreads across three independent commits
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandAffiliateInviteController.php:288-320
    - **Affects:** Affiliates claiming invites; if account-type transition fails after invite is claimed, the affiliate is in an inconsistent state (invite accepted, account_type still individual).
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Determine whether `BrandAffiliateInviteService::claimInvite()` internally uses `DB::transaction`. If it does, the external `transitionService->transition()` and `syncSiteBrandPartnerSettings` calls are outside that boundary — wrap the entire claim + transition + sync sequence in a single outer transaction, or refactor `claimInvite` to accept a post-claim callback that runs inside its transaction.
        - Alternatively, add compensating logic: if `transitionService->transition()` throws `InvalidAccountTypeTransition`, roll back the invite claim (or mark it for manual review).
    - **Technical:** The `claimInvite($invite, $professional)` call at line ~299 likely marks the invite as `accepted` and creates the `BrandPartnerLink`. If that succeeds but the subsequent `$transitionService->transition($professional, AccountType::Partner)` at line ~308 throws `InvalidAccountTypeTransition` (caught as a 422), the invite is already claimed and the link exists, but the professional's `account_type` was never flipped to `partner`. The `syncSiteBrandPartnerSettings` at line ~316 also runs outside any guarantee. Category (6) — transaction scope too narrow. Also category (8) if `claimInvite` internally opens a transaction (SAVEPOINT semantics on nested transaction).
    - **Plain English:** Accepting an affiliate invite requires three steps: mark the invite as accepted, flip the account type, and update the site settings. Right now these three steps happen independently — if step two or three fails, step one has already been saved permanently. It's like stamping "ACCEPTED" on an invitation before checking if the guest actually has a valid ID. The system should either do all three together or undo the first if the later ones fail.
    - **Evidence:**
        ```php
        // Line ~299 — invite claimed, link created (likely in its own transaction)
        $claimedInvite = $inviteService->claimInvite($invite, $professional);

        // Line ~308 — separate mutation, no transaction wrapping
        try {
            $transitionService->transition(
                $professional->fresh() ?? $professional,
                AccountType::Partner
            );
        } catch (InvalidAccountTypeTransition $e) {
            return $this->error($e->getMessage(), 422);
        }

        // Line ~316 — yet another separate mutation
        $site = Site::query()->where('professional_id', $professional->id)->first();
        if ($site) {
            $this->syncSiteBrandPartnerSettings($site, $brandPartnerLinks, (string) $professional->id);
            app(ProfessionalCacheService::class)->invalidateProfessional($professional);
        }
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **#TXN-3** · P2 — Brand store settings update splits BrandStoreSettings + Site writes across two auto-commits
    - **Where:** app/Http/Controllers/Api/Professional/Store/BrandStoreSettingsController.php:78-115
    - **Affects:** Brands updating store settings; if the Site settings save fails (e.g. constraint violation, lock timeout), the BrandStoreSettings row is already committed with the new values, creating drift between the two data stores.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap `BrandStoreSettings::updateOrCreate(...)` and `$site->save()` in a single `DB::transaction(...)` so both tables commit or roll back together.
        - Keep the Shopify metafield sync (external I/O) outside the transaction — it already is, just ensure it stays outside.
    - **Technical:** The method writes to `brand.brand_store_settings` via `updateOrCreate` at line ~78-92, then writes to `core.sites.settings` via `$site->save()` at line ~105-115. These are two independent auto-committed writes. If the site save throws (e.g. a JSONB constraint violation on `settings`, or a Postgres lock timeout from a concurrent read), the `BrandStoreSettings` row already has the new `default_commission_rate`, `payout_hold_days`, or `theme_id`, but the site's `settings.design` mirror (which Hydrogen reads) still has the old values. Category (6) — transaction scope too narrow.
    - **Plain English:** The brand's store settings live in two places: a dedicated settings table and a JSON blob on their site record. When a brand changes their commission rate, the code updates the settings table first, then the site record second. If the second update fails, the settings table says 20% but the site still says 15% — and since different parts of the system read from different places, the brand sees inconsistent numbers. The fix is to update both as a single unit.
    - **Evidence:**
        ```php
        // Line ~78-92 — auto-committed immediately
        if (! empty($dbFields) || $hasOxygenToken) {
            $settings = BrandStoreSettings::updateOrCreate(
                ['professional_id' => $pro->id],
                $dbFields
            );
            // ...
        }

        // Line ~105-115 — separate auto-commit, can fail independently
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
    - `[DRAFT, confidence: 0.8]`

- [ ] **#TXN-4** · P2 — `ProfessionalSectionBlockController::upsert` calls `visibilityService->checkVisibilityRequirements` inside transaction; implementation not visible
    - **Where:** app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalSectionBlockController.php:167-175
    - **Affects:** Professionals toggling sections to Live; if `checkVisibilityRequirements` does external I/O, cache writes, or opens a nested transaction, it violates gold-standard rules 1/3/8 inside an advisory-locked transaction.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Audit `SectionVisibilityService::checkVisibilityRequirements()` to confirm it performs only DB queries (no HTTP calls, cache writes, queue dispatches, or event dispatches).
        - If it does perform any non-DB side effect, refactor to run the check BEFORE the transaction and pass the boolean result into the closure.
        - The same call site exists in `syncAllowedSections()` at line ~280 — apply the same fix there.
    - **Technical:** The transaction at line ~150 wraps an advisory lock, a `firstOrNew`, and a `save`. At line ~167, `$this->visibilityService->checkVisibilityRequirements(...)` is called and its result is written to `$block->is_enabled` before the save. Without seeing the service implementation (file not in audit scope), I cannot confirm it only does DB reads. If it makes any external HTTP call, writes to cache, or dispatches jobs, those run inside the transaction — holding the advisory lock and a Postgres connection open for the duration. Category (4) or (1) depending on implementation. Confidence is 0.5 because the service is likely DB-only based on its name and context, but the audit scope doesn't include the file to verify.
    - **Plain English:** There's a validation check that runs in the middle of a database save operation. The check asks "does this section have enough data to go live?" If that check happens to reach out to an external service or write to the cache, it would hold the database connection open while waiting — like keeping a bank teller on hold while you call your accountant. The fix is to run the check before starting the database operation, so the database work stays quick and self-contained.
    - **Evidence:**
        ```php
        $block = DB::transaction(function () use ($pro, $site, $data, $blockType, $nextIsLive) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["blocks-sections:{$site->id}"]);
            // ...
            // Called inside the transaction — implementation not in audit scope
            [$canBeEnabled] = $this->visibilityService->checkVisibilityRequirements(
                (string) $pro->id,
                (string) $site->id,
                $blockType,
                is_array($data['settings'] ?? null) ? $data['settings'] : null,
            );
            $block->is_enabled = $canBeEnabled;
            $block->save();
            return $block->fresh();
        });
        ```
    - `[DRAFT, confidence: 0.5]`
