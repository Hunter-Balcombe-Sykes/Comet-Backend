- [ ] **#SEC-1** · P2 — BrandAffiliateController::index leaks affiliate email and phone to all connected brands
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandAffiliateController.php:67-70
    - **Affects:** Every brand can read the personal email and phone of every affiliate they've connected with, regardless of whether the affiliate expects that information to be shared.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `email` and `phone` from the response array unless explicitly required by the business model.
        - If contact details are needed, gate them behind a dedicated endpoint with an “expose contact info to brand” consent toggle, so affiliates can opt in.
    - **Technical:** The `index` method maps `BrandPartnerLink` rows to affiliate identities and includes `primary_email`/`public_contact_email` and `phone`/`public_contact_number`. No consent check is performed. Under GDPR and Australian privacy principles, sharing PII with a data controller (the brand) without a clear purpose or consent is a PII exposure.
    - **Plain English:** Imagine every shop you’ve ever partnered with could see your personal phone number and email address in their dashboard, even if you only intended to share a public handle. This finds that until that exposure is removed or made opt-in, all brands can see all affiliates’ private contact details.
    - **Evidence:**
        ```php
        return [
            'id' => $connectedProfessional?->id,
            // ...
            'email' => $connectedProfessional?->primary_email ?? $connectedProfessional?->public_contact_email,
            'phone' => $connectedProfessional?->phone ?? $connectedProfessional?->public_contact_number,
            // ...
        ];
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-2** · P3 — Inline `abort_unless` replaces Policy for custom-link feature gating
    - **Where:** app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalLinkBlockController.php:47-55
    - **Affects:** Any attempt to create a custom link; currently rejected by a hardcoded config check rather than a policy ability.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the allow/deny logic into a Policy method (e.g., `allowCustomLinks`) on `BlockPolicy`.
        - Replace `abort_unless(…)` with `$this->authorizeForUser($pro, 'allowCustomLinks', $someModel)`.
    - **Technical:** The Partna authorization doctrine requires all gating to go through Policies. The current inline `abort_unless` on a config key deviates from that standard. It also couples the controller to a configuration detail that should be isolated in the policy layer.
    - **Plain English:** The front door has a security guard checking IDs, but this particular gate is being checked by a handwritten sign taped to the wall. Put a proper lock on it that follows the same rules as every other door.
    - **Evidence:**
        ```php
        abort_unless(
            (bool) config("partna.account_type_defaults.{$type}.custom_links_allowed", false),
            403,
            'Custom links are not available on your account type.'
        );
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#SEC-3** · P3 — Delete product selection skips `authorizeForUser`, uses inline ownership check
    - **Where:** app/Http/Controllers/Api/Professional/Store/AffiliateProductController.php:207-225
    - **Affects:** Affiliates removing a product selection. The inline query is currently scoped by `affiliate_professional_id`, but a policy would add defence-in-depth (e.g., pending-deletion 423).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Call `$this->authorizeForUser($pro, 'delete', $selection)` after fetching the selection.
        - Ensure `AffiliateProductSelectionPolicy` has a `delete` method that replicates the ownership check.
    - **Technical:** The controller uses `AffiliateProductSelection::query()->where('affiliate_professional_id', $pro->id)->first()` and then immediately deletes. While this scopes the delete to the authenticated affiliate, it bypasses the Policy layer, meaning any future guard (e.g., soft-delete grace period, pending-deletion lock) would not be enforced.
    - **Plain English:** The door has a working lock, but the security guard isn't logging the entry. If we ever need to add an extra check — like “can’t delete while a payout is pending” — this code wouldn’t apply it.
    - **Evidence:**
        ```php
        $selection = AffiliateProductSelection::query()
            ->where('affiliate_professional_id', $pro->id)
            ->where('shopify_product_gid', $gid)
            ->first();

        if (! $selection) {
            return $this->error('Selection not found.', 404);
        }

        $selection->delete();
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-4** · P3 — Product photo operations rely on inline selection check instead of `authorizeForUser`
    - **Where:** app/Http/Controllers/Api/Professional/Store/AffiliateProductPhotoController.php:48-50, 74-80, 104-106, 130-132
    - **Affects:** Affiliates uploading, viewing, deleting, or reordering custom product photos. The checks are correct but should be routed through Policies for consistency and future-proofing.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Introduce `affiliate_product_selection_id` on the `SiteMedia` row or load the selection and call `$this->authorizeForUser($pro, 'view/update/delete', $selection)`.
        - Centralize the “affiliate owns this selection” check in `AffiliateProductSelectionPolicy`.
    - **Technical:** Each method queries `AffiliateProductSelection::query()->where('affiliate_professional_id', $pro->id)->where('shopify_product_gid', $gid)->exists()` to gate access. This repeats the ownership logic in four places. A Policy would make the check testable, auditable, and respect the project-wide authorization doctrine.
    - **Plain English:** Instead of installing a lock on the door, we’re sending a different security guard to manually verify every person who tries to open it. Standardising the locks means future doors are secured the same way without rewriting the guard’s instructions each time.
    - **Evidence:**
        ```php
        $hasSelection = AffiliateProductSelection::query()
            ->where('affiliate_professional_id', $pro->id)
            ->where('shopify_product_gid', $gid)
            ->exists();

        if (! $hasSelection) {
            return $this->error('You can only upload photos for products you have selected.', 422);
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-5** · P3 — BrandAffiliateController::disconnect does not call `authorizeForUser`
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandAffiliateController.php:93-114
    - **Affects:** Brands trying to disconnect an affiliate. The service layer may already validate the relationship, but the controller does not enforce a Policy on the link itself.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Load the `BrandPartnerLink` record before calling `disconnect` and call `$this->authorizeForUser($pro, 'delete', $link)`.
        - Return 404 if the link doesn't exist (policy will handle).
    - **Technical:** The method fetches the affiliate `Professional` by ID without verifying they are linked to the acting brand. The `DisconnectRequest` is passed to a service that presumably checks, but a defender is missing at the controller layer. A Policy would enforce the same ownership contract used elsewhere.
    - **Plain English:** We’re passing a note to the back office saying “detach this person” without first checking that the person is actually attached. The back office double-checks, but the front desk should too.
    - **Evidence:**
        ```php
        $affiliate = Professional::query()->whereKey($affiliateId)->first();
        if (! $affiliate) {
            return $this->error('Affiliate not found.', 404);
        }

        $result = $lifecycle->disconnect(DisconnectRequest::forBrand(
            brand: $professional,
            affiliate: $affiliate,
            reason: $data['reason'] ?? null,
        ));
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-6** · P3 — Inline `isBrand()` checks duplicate middleware gates
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandAffiliateInviteController.php:28,56,78,110,132,177,197
    - **Affects:** Several invite endpoints; the middleware already restricts access to brand accounts, so the inline checks are redundant and break the single-source-of-truth principle.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove all `if (! $professional->isBrand()) { return error }` blocks, trusting `brand.only` middleware.
        - If any endpoint needs an additional capability check, express it in a Policy.
    - **Technical:** The `brand.only` middleware (EnsureBrandAccount) already gates these routes. The inline `isBrand()` calls create a second, inconsistent authorization path that could drift from middleware logic and are disallowed by the authorization doctrine.
    - **Plain English:** We’re posting a guard at the gate and then stopping each person again inside the building to re-check the same ID. Remove the second check so everyone follows the same entrance rules.
    - **Evidence:**
        ```php
        if (! $professional->isBrand()) {
            return $this->error('Only brand accounts can view affiliate invites.', 403);
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-7** · P3 — BrandStoreSettingsController::update skips Policy authorisation
    - **Where:** app/Http/Controllers/Api/Professional/Store/BrandStoreSettingsController.php:83-175
    - **Affects:** Brands updating store settings (commission rate, payout hold, storefront config). The update is gated only by the authenticated professional; a Policy should confirm the brand can manage its own settings.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - At the top of `update`, call `$this->authorizeForUser($pro, 'update', BrandStoreSettings::where('professional_id', $pro->id)->first())` or the policy on the BrandStoreSettings model.
        - Register `BrandStoreSettingsPolicy` if not yet present.
    - **Technical:** The `update` method creates or updates a `BrandStoreSettings` row scoped to `$pro->id` without any explicit authorisation check. The Partna doctrine requires all write operations on tenant-owned models to pass through a Policy.
    - **Plain English:** The settings panel is behind the login wall but doesn’t actually ask “are you allowed to change this?” Putting a formal permission check on it makes it testable and safe if we later add sub‑roles.
    - **Evidence:**
        ```php
        $pro = $this->currentProfessional($request);
        $validated = $request->validated();
        // ... directly updates BrandStoreSettings ...
        $settings = BrandStoreSettings::updateOrCreate(
            ['professional_id' => $pro->id],
            $dbFields
        );
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-8** · P3 — ProfessionalGoogleBusinessProfileController writes site settings without Policy enforcement
    - **Where:** app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalGoogleBusinessProfileController.php:29-47
    - **Affects:** Professionals saving Google Business Profile data; no explicit ownership check beyond loading the site via `currentSite()`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Insert `$this->authorizeForUser($professional, 'update', $site)` before modifying site settings.
        - Ensure `SitePolicy` has an `update` ability.
    - **Technical:** The method calls `$site = $this->currentSite($professional)` which guarantees the site belongs to the professional, but then writes to `settings` without a Policy gate. The doctrine requires all mutations to be wrapped in `authorizeForUser`.
    - **Plain English:** The person is allowed inside the room, but we haven’t checked whether they have a key to the specific filing cabinet they’re opening.
    - **Evidence:**
        ```php
        $professional = $this->currentProfessional($request);
        $site = $this->currentSite($professional);
        // ...
        $settings = is_array($site->settings) ? $site->settings : [];
        $settings[self::SETTINGS_KEY] = $profile;
        $site->settings = $settings;
        $site->save();
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-9** · P2 — No rate limiting on signup code rotation endpoint
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandSignupCodeController.php:38-44
    - **Affects:** The `POST …/signup-code/rotate` endpoint. A malicious actor (or a misbehaving UI) could rotate the code repeatedly, invalidating any in-flight invites and causing confusion.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Apply `throttle:5,1` (or similar) middleware to the rotate route group, keyed by brand professional id.
        - Consider adding the same protection to the deactivate/reactivate endpoints.
    - **Technical:** The `rotate` method immediately replaces the signup code with no rate limit. Unlike the resync endpoint, which uses `RateLimiter`, this operation is meant to be rare. Without throttling, an attacker who obtains a valid session could rapidly cycle codes, breaking the affiliate onboarding flow.
    - **Plain English:** This is like letting someone change the front-door combination as fast as they can type. Putting a 5‑attempts‑per‑minute cap on it makes accidental or malicious rapid changes impossible.
    - **Evidence:**
        ```php
        public function rotate(Request $request): JsonResponse
        {
            $professional = $this->currentProfessional($request);
            $profile = $this->requireBrandProfile($professional->id);
            $this->codeService->rotate($profile);
            return $this->success($this->buildResponse($profile->refresh()));
        }
        ```
    - `[DRAFT, confidence: 0.9]`
