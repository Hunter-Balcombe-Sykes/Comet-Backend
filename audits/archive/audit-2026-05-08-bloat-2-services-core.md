`★ Insight ─────────────────────────────────────`
**Evidence-first adjudication:** Both DeepSeek findings have verbatim matches in the provided source — rare (DeepSeek hallucinates ~15–20% of quoted evidence). This lets us focus effort on tier calibration and missed patterns rather than evidence scrubbing.

**Cross-service duplication often hides at the gate layer:** The BLOT-2 duplicate-query pattern is a classic symptom of a service that was written before a centralised authority existed, then never refactored once the authority stabilised. The debt is currently invisible because the two implementations happen to be identical — but divergence is only one threshold-change away.

**Dead parameters are stronger signals than dead methods:** A dead method is easy to spot with static analysis. A dead parameter actively misleads callers about the contract being offered and survives code reviews because the signature looks intentional.
`─────────────────────────────────────────────────`

# Unused Methods, Dead Code, and Unreferenced Bloat in Core Services Audit — 2026-05-08

**Branch:** development
**Lens:** Unused methods, dead code, and unreferenced bloat in core services
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Professional/AccountTypeDefaultsService.php
- app/Services/Professional/BrandAffiliateInviteService.php
- app/Services/Professional/BrandOnboardingReadinessService.php
- app/Services/Professional/BrandPartnerLinkAuditor.php
- app/Services/Professional/BrandPartnerLinkLifecycleService.php
- app/Services/Professional/BrandPartnerLinkNotifier.php
- app/Services/Professional/BrandPartnerLinkService.php
- app/Services/Professional/BrandPartnerSiteSettingsSync.php
- app/Services/Professional/BrandStatusService.php
- app/Services/Professional/ConfirmationPreferenceService.php
- app/Services/Professional/DataExportPayloadBuilder.php
- app/Services/Professional/DataExportService.php
- app/Services/Professional/DataExportZipWriter.php
- app/Services/Professional/DTO/DisconnectRequest.php
- app/Services/Professional/DTO/DisconnectResult.php
- app/Services/Professional/Enums/CommissionHandling.php
- app/Services/Professional/Enums/DisconnectActor.php
- app/Services/Professional/SectionVisibilityService.php
- app/Services/Professional/SiteProvisioningService.php
- app/Services/Store/AffiliateProductCatalogService.php
- app/Services/Store/BrandAccessService.php
- app/Services/Store/BrandCatalogService.php
- app/Services/Store/BrandPricingService.php
- app/Services/Store/CustomPhotoPermissionService.php
- app/Services/Store/SelectionCleanupService.php
- app/Services/Shopify/BrandDesignImporter.php
- app/Services/Shopify/BrandSignupResult.php
- app/Services/Shopify/BrandSignupService.php
- app/Services/Shopify/Client/ShopifyAdminClient.php
- app/Services/Shopify/Client/ShopifyBudgetTracker.php
- app/Services/Shopify/Client/ShopifyBulkOperationLock.php
- app/Services/Shopify/Client/ShopifyCostTracker.php
- app/Services/Shopify/Client/ShopifyMetrics.php
- app/Services/Shopify/ShopifyDataResyncService.php
- app/Services/Shopify/ShopifySetupTokenService.php
- app/Services/Shopify/ShopifyShopResolver.php
- app/Services/Shopify/ShopifyTeardownService.php
- app/Services/Shopify/ShopProfileAutoFillService.php
- app/Services/Professional/AccountDeletionService.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 1 of 1 complete ✅
- P3 Low: 1 of 3 complete

---

## P2 — Should fix

- [x] **#BLOT-1** · P2 — Duplicate readiness-gate queries split across `BrandOnboardingReadinessService` and `BrandStatusService`
    - **Where:** app/Services/Professional/BrandOnboardingReadinessService.php:78–117 (`checkSiteImages`, `checkShopifyConnected`, `checkStripeConnected`) and app/Services/Professional/BrandStatusService.php:258–280 (`hasMinimumImages`, `hasShopifyConnected`, `hasStripeConnected`)
    - **Affects:** Future maintainers of readiness gate logic — any threshold change (e.g., image minimum, Shopify detection criteria) must be applied in two independent places. Also causes duplicate database hits on every call to `getChecklist()`: the Shopify `EXISTS` query fires once via `checkShopifyConnected` and a second time inside `BrandStatusService::sync() → determine() → isOnboardingReady() → hasShopifyConnected()`.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace the three private check methods in `BrandOnboardingReadinessService` (`checkSiteImages`, `checkShopifyConnected`, `checkStripeConnected`) with calls that delegate to `BrandStatusService`'s gate logic — either by making the equivalent helpers `internal`/`protected`, or by introducing a shared `BrandReadinessSnapshot` value object that `BrandStatusService` populates once per request.
        - Preserve the checklist's richer response shape (`label`, `current`, `required`, `complete`) by constructing those arrays from the boolean gate results rather than duplicating raw query builders.
        - Resolve `BrandStatusService` as a shared instance (inject it into `BrandOnboardingReadinessService` via the constructor) so the per-instance `$shopifyConnectedCache` survives across both the direct check call and the downstream `sync()` call.
    - **Technical:** `BrandOnboardingReadinessService::getChecklist()` calls `checkShopifyConnected($professionalId)`, which runs a `ProfessionalIntegration EXISTS` query directly. It then calls `$this->syncBrandStatus($professional, $isComplete)`, which resolves a fresh `BrandStatusService` instance via `app(BrandStatusService::class)` — a new object with an empty `$shopifyConnectedCache`. That fresh instance's `sync()` → `determine()` → `isOnboardingReady()` → `hasShopifyConnected()` runs the identical query a second time. The image count query (`SiteMedia count()`) and Stripe status check (`stripe_connect_status` property read) are also duplicated, though the latter is free. The root cause is that `BrandOnboardingReadinessService` predates the centralised `BrandStatusService` and was never wired to delegate to it.
    - **Plain English:** Two separate people each maintain their own copy of the supplier-approval checklist. When the rules change — say, "10 photos required instead of 5" — someone has to brief both people, and if only one is briefed, the two checklists start giving different answers. Right now the system runs the same three checks twice on every checklist page load: once by the checklist itself, and once by the status engine it calls. Consolidate to a single shared set of checks so the answer is always consistent and only computed once.
    - **Evidence:**
        ```php
        // BrandOnboardingReadinessService::checkShopifyConnected — runs its own EXISTS query
        $connected = ProfessionalIntegration::query()
            ->where('professional_id', $professionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->whereNotNull('access_token')
            ->whereNotNull('external_account_id')
            ->exists();
        ```
        ```php
        // BrandStatusService::hasShopifyConnected — identical query, instance-cached,
        // but called via a fresh app() instance so the cache does not carry over
        return $this->shopifyConnectedCache[$professionalId] = ProfessionalIntegration::query()
            ->where('professional_id', $professionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->whereNotNull('access_token')
            ->whereNotNull('external_account_id')
            ->exists();
        ```

---

## P3 — Nice to have

- [x] **#BLOT-2** · P3 — Stale "custom domain" comment in `BrandPartnerSiteSettingsSync::syncWithoutPersist` after custom-domain removal
    - **Where:** app/Services/Professional/BrandPartnerSiteSettingsSync.php (comment block inside `syncWithoutPersist`, above `$brandPartner['storefront_base_url'] = ...`)
    - **Affects:** Developers reading the brand-partner settings sync code — the comment implies a conditional custom-domain branch that no longer exists, causing a search for code that was deleted weeks ago.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Update the comment to accurately describe current behaviour: `storefront_base_url` is always derived from the platform subdomain (`{subdomain}.partna.au`).
        - Remove or reword the phrase "Uses the brand's custom domain when fully provisioned, else platform subdomain."
    - **Technical:** Commits `902cc42` (feat(cleanup): drop custom-domain capability entirely) and `2a752fa`/`7aa5d40` (feat: Cloudflare routing + custom-domain removal) removed all custom-domain logic from the codebase. The comment "Uses the brand's custom domain when fully provisioned, else platform subdomain" now describes a branch that does not exist. The code unconditionally assigns `$subdomain = (string) $brand->site->subdomain` and builds `storefront_base_url` from the platform domain config — there is no conditional, no custom-domain lookup, and no fallback. A developer hunting for the custom-domain path will waste time and may incorrectly conclude the logic is hidden elsewhere.
    - **Plain English:** A sign on the door says "use the VIP entrance when it's open, otherwise use the main entrance." The VIP entrance was permanently sealed several weeks ago, but no one updated the sign. The sign doesn't break anything, but it wastes time for anyone who goes looking for the VIP entrance.
    - **Evidence:**
        ```php
        // Comment claims custom domain is used "when fully provisioned":
        // Uses the brand's custom domain when fully provisioned, else platform subdomain.
        $brand = Professional::query()->with('site')->find($primary->brand_professional_id);
        if ($brand && $brand->site) {
            $subdomain = (string) $brand->site->subdomain;

            $brandPartner['subdomain'] = $subdomain;
            // Only the platform-subdomain path remains after commits 902cc42 + 2a752fa.
            $brandPartner['storefront_base_url'] = 'https://'.$subdomain.'.'.config('partna.public_domain', 'partna.au');
        }
        ```

- [x] **#BLOT-3** · P3 — Dead `$onboardingComplete` parameter in `BrandOnboardingReadinessService::syncBrandStatus`
    - **Where:** app/Services/Professional/BrandOnboardingReadinessService.php:62 (private method `syncBrandStatus`)
    - **Affects:** Developers maintaining the checklist — the parameter implies a short-circuit optimisation (skip recompute when already known) that was never implemented.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `bool $onboardingComplete` from the `syncBrandStatus` private method signature.
        - Update the call site in `getChecklist` from `$this->syncBrandStatus($professional, $isComplete)` to `$this->syncBrandStatus($professional)`.
    - **Technical:** `syncBrandStatus` accepts `$onboardingComplete` but the parameter is never read inside the method body — not as a guard clause, not in a conditional, not in a log field. The method immediately calls `app(BrandStatusService::class)->sync($professional)` which recomputes all lifecycle gates from scratch regardless of the passed value. The parameter is a vestige from a prior design where `BrandOnboardingReadinessService` wrote its own binary `active`/`deactivated` status row; when delegation to `BrandStatusService`'s full lifecycle engine was introduced, the parameter became dead but the signature was not cleaned up.
    - **Plain English:** Imagine a calculator function that asks you to hand it the answer before it starts, then ignores your answer and works through all the arithmetic itself anyway. The extra argument is harmless clutter, but it advertises a contract — "I can skip work if you already know the result" — that was planned and then silently abandoned.
    - **Evidence:**
        ```php
        private function syncBrandStatus(Professional $professional, bool $onboardingComplete): string
        {
            // Delegate to BrandStatusService — it evaluates the full lifecycle
            // (building → preview → live) instead of just the binary active/deactivated
            // that the readiness checklist previously set.
            $statusService = app(BrandStatusService::class);
            $newStatus = $statusService->sync($professional);

            return $newStatus ?? BrandProfile::where('professional_id', $professional->id)
                ->value('brand_status') ?? BrandStatus::Onboarding->value;
        }
        ```

- [x] **#BLOT-4** · P3 — Dead `$brandProfessionalId` parameter in `AccountTypeDefaultsService::applyAffiliateDefaults`
    - **Where:** app/Services/Professional/AccountTypeDefaultsService.php (public method `applyAffiliateDefaults`, third parameter)
    - **Affects:** Callers of `applyAffiliateDefaults` — the public signature advertises brand-specific affiliate defaults, but the method body is entirely brand-agnostic. Any future caller that passes brand-specific data expecting it to be used will be silently ignored.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `string $brandProfessionalId` from the `applyAffiliateDefaults` public method signature.
        - Update all call sites (typically in the affiliate connection flow) to drop the third argument.
        - If brand-specific block defaults are genuinely needed in future, add the parameter back with an implementation.
    - **Technical:** `applyAffiliateDefaults` declares `string $brandProfessionalId` as its third parameter, but the method body references only `$professional` (for `professional_id` filters) and `$site` (for `site_id` and `sort_order` queries). `$brandProfessionalId` is not passed to any sub-call, not used in a query `WHERE` clause, and is not logged. The parameter almost certainly survived from an early design where brand-specific section presets (e.g., "this brand's affiliates always start with the shop section pinned second") were planned; the implementation was never completed, but the signature was not cleaned up. As a public method that participates in the affiliate connection flow, the misleading signature is more dangerous than a dead private-method parameter — callers may assume brand context is being applied.
    - **Plain English:** A form has a "Company name" field, but the back-office system that processes submissions never reads what you typed there — it always applies the same defaults regardless of company. The field exists because a feature was planned and quietly dropped without removing the field. Anyone filling out the form wastes effort typing their company name, and anyone reading the form handler reasonably assumes the company name must matter somewhere.
    - **Evidence:**
        ```php
        public function applyAffiliateDefaults(
            Professional $professional,
            Site $site,
            string $brandProfessionalId  // declared but never referenced below
        ): void {
            $config = config('partna.account_type_defaults.affiliate', []);

            $autoSections = $config['auto_enable_sections'] ?? [];
            foreach ($autoSections as $blockType) {
                $block = Block::query()->firstOrNew([
                    'professional_id' => $professional->id,
                    'site_id' => $site->id,
                    'block_group' => 'sections',
                    'block_type' => $blockType,
                ]);
                [$canBeEnabled] = $this->visibilityService->checkVisibilityRequirements(
                    (string) $professional->id,
                    (string) $site->id,
                    $blockType,
                );
                $block->is_enabled = $canBeEnabled;
                $block->is_active = true;
                // ... $brandProfessionalId never appears anywhere in the method body
            }
        }
        ```

`★ Insight ─────────────────────────────────────`
**The "dead parameter on a public method" (BLOT-4) is meaningfully more dangerous than the equivalent on a private method (BLOT-3):** Private dead parameters mislead maintainers of the class; public dead parameters mislead the entire call graph, including future features that may supply a real brand ID expecting it to have effect.

**Instance lifecycle matters for cache design:** The double-query in BLOT-1 would disappear if `BrandStatusService` were registered as a request-scoped singleton in the service container. Injecting it via constructor rather than resolving it ad-hoc with `app()` inside the method body is the correct fix — it also makes the dependency visible and testable.
`─────────────────────────────────────────────────`
