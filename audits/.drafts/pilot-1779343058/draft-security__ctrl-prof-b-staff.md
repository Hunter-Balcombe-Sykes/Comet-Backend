- [ ] **#SEC-1** · P2 — Inline `abort_unless` ownership check instead of Policy in staff link-block controller
    - **Where:** app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffLinkBlockManagementController.php:75-80 and :84-89
    - **Affects:** Staff admin updating or deleting link blocks. Cross-tenant enforcement bypassed if Policy regressions are introduced at the model layer.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `abort_unless($linkBlock->professional_id === $professional->id ...)` with `$this->authorizeForUser($professional, 'update', $linkBlock)` / `'delete'`.
        - Register or confirm a `BlockPolicy` with `update`/`delete` methods that gate on `professional_id` via `BasePolicy`.
    - **Technical:** Per Partna doctrine category (2), authorization MUST go through Policies extending `BasePolicy`, never inline `abort_unless`. The current code adds a `block_group`/`block_type` gauze inside the same `abort_unless`, which duplicates what a Policy's type-gate would do. If a future refactor weakens `scopeBindings`, the inline check becomes the only line of defence — and it's not centrally testable. The Policy pattern ensures one auditable gate per model.
    - **Plain English:** There's a guard at the door checking IDs manually instead of using the building's standard keycard system. If someone changes the keycard rules later, this door won't pick up the change. The fix is to swap the manual check for the same keycard reader every other door uses.
    - **Evidence:**
        ```php
        // StaffLinkBlockManagementController.php:75-80 (update)
        abort_unless(
            $linkBlock->professional_id === $professional->id &&
            $linkBlock->block_group === 'links' &&
            $linkBlock->block_type === 'link',
            404
        );

        // StaffLinkBlockManagementController.php:84-89 (destroy)
        abort_unless(
            $linkBlock->professional_id === $professional->id &&
            $linkBlock->block_group === 'links' &&
            $linkBlock->block_type === 'link',
            404
        );
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SEC-2** · P2 — No factor-ownership verification before MFA unenrollment (defense-in-depth gap)
    - **Where:** app/Http/Controllers/Api/Professional/Account/MfaController.php:45-48
    - **Affects:** Authenticated professionals unenrolling MFA factors. An attacker who learns a victim's factor ID could attempt cross-user factor deletion if Supabase's admin API validation is ever bypassed or misconfigured.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Before calling `unenrollMfaFactor`, call Supabase's admin `getFactor` (or equivalent) to confirm the factor's `user_id` matches `$uid`.
        - Log and reject if the factor does not belong to the authenticated user.
    - **Technical:** Category (3) tenant isolation. `$uid` is resolved from the Supabase JWT (trusted), but `$factorId` comes directly from the URL segment with no application-level ownership check. The Supabase Admin API's `unenrollMfaFactor` likely validates ownership internally, making this defense-in-depth rather than an exploitable bug today. However, MFA factor manipulation is a high-sensitivity operation — if Supabase's API behavior changes or a configuration error removes that guard, this endpoint becomes an IDOR that lets any authenticated user strip MFA from any other account.
    - **Plain English:** When you ask the system to remove a security key from your account, it takes the key's serial number from the URL and trusts that the backend will verify it's yours. If that backend check ever fails — due to a bug, a misconfiguration, or an API change — someone could remove another person's security key just by guessing the serial number. The fix is to double-check ownership at our end before sending the removal command.
    - **Evidence:**
        ```php
        // MfaController.php:45-48
        $uid = (string) $request->attributes->get('supabase_uid');
        $sessionId = $request->attributes->get('supabase_session_id');

        try {
            $this->admin->unenrollMfaFactor($uid, $factorId);
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#SEC-3** · P3 — Inline `$pro->isBrand()` gate instead of `brand.only` middleware on professional-facing document endpoint
    - **Where:** app/Http/Controllers/Api/Professional/Account/ProfessionalDocumentController.php:66-69
    - **Affects:** Brand-account users hitting the generic document upload endpoint. They get a 403; the rejection is correct but the enforcement mechanism is inconsistent with the rest of the API surface.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the inline `if ($pro->isBrand())` check.
        - Apply the `brand.only` middleware to the document-upload route in `routes/api/professional.php` with an `except: []` or inverse middleware.
    - **Technical:** Per Partna doctrine category (2) item 6, brand-only route gating should live in middleware, not in controllers. The current inline check works correctly (brands are rejected), but it spreads authorization logic across the controller layer instead of keeping it centralized in the route definition where it's visible during route auditing. If three other controllers also have inline `isBrand()` checks, that's three places to miss when the brand/affiliate split evolves.
    - **Plain English:** There's a "brands not allowed" sign taped to one specific room's door instead of being on the hallway entrance where it belongs. The sign is correct, but if the building layout changes, someone has to remember to check every room for taped-up signs instead of just updating the hallway sign once.
    - **Evidence:**
        ```php
        // ProfessionalDocumentController.php:66-69
        // Brand accounts are excluded per product spec — they have Shopify
        // for catalogue assets and don't get the generic document slot.
        if ($pro->isBrand()) {
            return $this->error('Documents section not available for brand accounts.', 403);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SEC-4** · P3 — Inline `$professional->isBrand()` gate on affiliate-invite listing instead of middleware guard
    - **Where:** app/Http/Controllers/Api/Professional/Affiliate/AffiliateInviteController.php:27-29
    - **Affects:** Brand-account users accidentally hitting the affiliate-invite inbox endpoint. Returns 403; functionally correct but route-authoring inconsistency.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the inline `if ($professional->isBrand())` check.
        - Route the invite-listing endpoint behind a non-brand middleware guard (inverse of `brand.only`) or scope it to `affiliate.only` middleware.
    - **Technical:** Same category (2) item 6 violation as SEC-3 — inline professional-type gating instead of middleware. The check itself is harmless (returns 403 for brands, which is the desired behavior), but it's an ad-hoc authorization decision embedded in controller code rather than declared at the route layer where the full access-control picture is visible in one file.
    - **Plain English:** Same pattern as the document endpoint — a "brands prohibited" rule enforced inside one room rather than on the hallway entrance. Centralizing it in the route definition means the rule is visible when auditing which doors each account type can walk through.
    - **Evidence:**
        ```php
        // AffiliateInviteController.php:27-29
        if ($professional->isBrand()) {
            return $this->error('Brand accounts cannot view affiliate invites.', 403);
        }
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#SEC-5** · P2 — `return_url` and `refresh_url` passed to Stripe Connect without server-side allow-list validation
    - **Where:** app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php:101-105 and app/Http/Controllers/Api/Professional/Stripe/AffiliateStripeOnboardingController.php:30-35
    - **Affects:** Professionals initiating Stripe Connect onboarding. A malicious or compromised client could supply arbitrary URLs; Stripe validates against the platform's registered domain, but missing server-side validation removes a defense-in-depth layer.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a domain allow-list check in `OnboardRequest` (the Form Request): validate that `return_url` and `refresh_url` match the Partna frontend origin or a known set of allowed redirect targets.
        - Reject with 422 before the values reach Stripe's API.
    - **Technical:** Category (7) SSRF / open redirect. While Stripe validates that `return_url` and `refresh_url` match the platform's registered Connect redirect domains, relying solely on the vendor's validation means a misconfiguration in the Stripe dashboard (e.g., a wildcard or overly permissive domain entry) could open an open-redirect vector. Server-side validation keeps the allow-list in source control where it's reviewed and versioned alongside the code that uses it.
    - **Plain English:** You're handing Stripe a return address from the customer without checking it first. Stripe has its own address-verification system, so this isn't exploitable today. But if someone fat-fingers the Stripe settings and allows a broader set of addresses, your server would happily forward any address the customer types in. Adding your own address check means two locks have to fail instead of one.
    - **Evidence:**
        ```php
        // StripeConnectController.php:101-105
        $url = $this->connectService->createOnboardingLink(
            $pro,
            $request->input('return_url'),
            $request->input('refresh_url'),
        );

        // AffiliateStripeOnboardingController.php:30-35
        $url = $this->connectService->createOnboardingLink(
            $aff,
            $request->input('return_url'),
            $request->input('refresh_url'),
        );
        ```
    - `[DRAFT, confidence: 0.70]`
