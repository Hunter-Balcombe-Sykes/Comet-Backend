- [ ] **#TXN-1** · P2 — Cache invalidation inside BootstrapController's `DB::transaction` closure
    - **Where:** app/Http/Controllers/Api/PublicSite/BootstrapController.php:bootstrap() — inside the `DB::transaction(function () use (...) { ... })` closure
    - **Affects:** All new account creation and account-update flows. On transaction rollback the Professional cache is already invalidated, causing unnecessary cache misses on the next read.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `app(ProfessionalCacheService::class)->invalidateProfessional($professional)` to AFTER the `DB::transaction` block returns.
        - Alternatively, wrap it in `DB::afterCommit(fn() => app(ProfessionalCacheService::class)->invalidateProfessional($professional))`.
    - **Technical:** The `ProfessionalCacheService::invalidateProfessional` call flushes Redis cache entries for the Professional. It sits inside the `DB::transaction` closure. If any DB operation after this point throws (e.g. `ensureFreeSubscription` hits a constraint violation, or `createWelcomeNotification` fails), the transaction rolls back all DB mutations — but the cache has already been cleared. The next read will miss cache, query the DB, and correctly re-warm with the pre-bootstrap state (since the transaction rolled back). This is a "cache churn on rollback" bug rather than a "stale data served" bug, because `invalidateProfessional` only clears — it doesn't write a new value. However, it still violates the gold-standard rule: **no cache writes inside a transaction**, because a rollback path makes the cache operation pointless and in other service patterns (where writes, not invalidations, are involved) the same placement would serve stale data.
    - **Plain English:** Imagine you're filling out a paper form, and halfway through you tell the receptionist "throw away my old file." Then you make a mistake on the form, crumple it up, and start over. The receptionist already threw away your old file — now there's no file at all until you finish the new form. The next person who asks for your file has to go find it in the archive instead of just grabbing it from the front desk. The data is still correct (the archive has the right version), but it's slower for no good reason. The fix is simple: only tell the receptionist to toss the old file AFTER you've handed in the finished form.
    - **Evidence:**
        ```php
        $result = DB::transaction(function () use ($uid, $data, $brandAffiliateInviteService, $brandPartnerLinks, $accountTypeDefaultsService, $resolveProfessionalType, $request, $resolvedSignupCodeBrand, &$brandSignupCodeError) {
            // ... Professional creation/update, Site creation, brand-attach branches,
            //     Shopify integration creation, etc. ...

            app(ProfessionalCacheService::class)->invalidateProfessional($professional);

            // Ensure the professional has a subscription – seed the free plan if none exists
            $this->siteProvisioning->ensureFreeSubscription($professional);

            if ($createdProfessional) {
                $this->createWelcomeNotification($professional);
            }

            return [
                'professional' => new ProfessionalDashboardResource($professional->fresh()),
                'site' => $site->fresh(),
                'shopify_integration_id' => $shopifyIntegrationId,
            ];
        });
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#TXN-2** · P2 — BootstrapController transaction scope is too coarse (Category 6)
    - **Where:** app/Http/Controllers/Api/PublicSite/BootstrapController.php:bootstrap() — the entire account-creation flow is wrapped in a single `DB::transaction`
    - **Affects:** New account creation and account-update flows. Increased lock contention on the `core.professionals`, `core.sites`, and related tables during bootstrap. Harder to debug partial failures because everything is rolled back together.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Split into two transactions: (a) Professional + Site creation, (b) brand-attach + Shopify integration provisioning.
        - Fetch-validate-decode Shopify setup token data before the transaction, not inside it — the `peek()` call is already correctly placed before the closure, but the `create()` of `ProfessionalIntegration` using that data is inside.
        - Consider whether `ensureSidestUpdatesSubscription` and `createWelcomeNotification` need to be atomic with the Professional row — if not, move them after commit.
    - **Technical:** The `DB::transaction` closure spans ~150+ lines and covers: Professional creation/update, `ensureSidestUpdatesSubscription` (EmailSubscription upsert), Site creation with retry, `AccountTypeDefaultsService::applyDefaults`, three possible brand-attach branches (each calling `syncSiteBrandPartnerSettings` which mutates Site settings), brand signup code claim, Shopify integration creation (`ProfessionalIntegration::create`), `ShopProfileAutoFillService::fillFromShopData`, cache invalidation, `ensureFreeSubscription`, and `createWelcomeNotification` (Notification creation). While bootstrap is conceptually "all-or-nothing," this scope means that a failure in the welcome notification (a non-critical concern) rolls back the entire Professional + Site creation, forcing the user to restart from scratch. The transaction also holds row locks on every table touched by the various service calls, increasing deadlock surface under concurrent signups. Narrowing the transaction to just the critical atomic unit (Professional + Site + essential defaults) and moving secondary operations outside would improve resilience and debuggability.
    - **Plain English:** Think of this as packing an entire house into one shipping container — furniture, appliances, decorations, the welcome mat, and the "congratulations" card. If the welcome mat gets snagged on the door, the entire container gets sent back to the warehouse, including the house itself. The fix is to ship the house in one container (the stuff that MUST arrive together), and put the decorations and greeting card in a separate box — if the card gets lost, the house is still delivered and the family can move in.
    - **Evidence:**
        ```php
        $result = DB::transaction(function () use ($uid, $data, ...) {
            // ~150 lines including:
            //   - Professional creation/update + save()
            //   - ensureSidestUpdatesSubscription() — EmailSubscription upsert
            //   - Site creation via createSiteWithRetry()
            //   - AccountTypeDefaultsService::applyDefaults()
            //   - BrandProfile firstOrCreate
            //   - 3 brand-attach branches (invite_token, brand_partner_professional_id, join_brand_handle)
            //     each with claimInvite / connectBrandToAffiliate + syncSiteBrandPartnerSettings
            //   - brand_signup_code resolution + claim
            //   - Shopify ProfessionalIntegration::create()
            //   - ShopProfileAutoFillService::fillFromShopData()
            //   - ProfessionalCacheService::invalidateProfessional()
            //   - SiteProvisioningService::ensureFreeSubscription()
            //   - createWelcomeNotification() — Notification firstOrCreate
            //   - Professional promotion to 'partner' account_type

            return [
                'professional' => new ProfessionalDashboardResource($professional->fresh()),
                'site' => $site->fresh(),
                'shopify_integration_id' => $shopifyIntegrationId,
            ];
        });
        ```
    - `[DRAFT, confidence: 0.85]`
