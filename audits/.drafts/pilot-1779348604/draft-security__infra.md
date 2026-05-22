- [ ] **#SEC-1** · P0 — Public site payload enrichment can leak another professional’s design data without verifying the brand‑partner link
    - **Where:** app/Services/Cache/SiteCacheService.php:289‑330 (method `enrichSiteWithBrandPartnerRadius`), and called from `safeHydrateSitePayload` at line 237‑240.
    - **Affects:** Unauthenticated public‑site visitors; any affiliate can set `site.settings.brand_partner.professional_id` to an arbitrary UUID and have the other professional’s `handle`, `first_name`, `last_name`, and design token values served on their public page.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `BrandPartnerLink` existence check inside `enrichSiteWithBrandPartnerRadius` (mirror the pattern already used in `applyBrandImageFallbacks`) before resolving the brand’s data.
        - Alternatively, validate the `professional_id` at write time in the controller/Form Request so the setting can only contain the UUID of a verified linked brand.
    - **Technical:** `SiteCacheService::enrichSiteWithBrandPartnerRadius` reads `brand_partner.professional_id` from the affiliate‑controlled `site.settings` JSONB and blindly queries `Site` and `Professional` with that ID. No check is made that a `BrandPartnerLink` row confirms the relationship. In contrast, `applyBrandImageFallbacks` (which enriches only image placeholders) does verify the link before using the brand’s data. This discrepancy means an affiliate can inject any professional’s design tokens and name into their public‑site payload, bypassing tenant isolation. The fix is to add the same `BrandPartnerLink` guard to `enrichSiteWithBrandPartnerRadius` or to reject untrusted values at the API boundary.
    - **Plain English:** Imagine you’re an affiliate and you can write a note on your profile that says “my brand partner is X.” The system currently trusts that note without checking whether X has actually agreed to partner with you. A malicious affiliate could write any brand’s ID in that note and the system will fetch that brand’s logo styling, border colour, and name and show them publicly on the affiliate’s page. That’s like being able to claim to be endorsed by anyone just by writing their name on your storefront, with no verification. The fix is to do the same check we already do for images: confirm the partnership exists before showing the data.
    - **Evidence:**
        ```php
        // In enrichSiteWithBrandPartnerRadius()
        $professionalId = $brandPartner['professional_id'] ?? $brandPartner['professionalId'] ?? null;
        if (! is_string($professionalId) || trim($professionalId) === '') {
            return $site;
        }
        $enrichment = $this->resolveBrandPartnerEnrichmentData(trim($professionalId));
        // No BrandPartnerLink check here

        // Contrast with applyBrandImageFallbacks(), which does verify:
        $link = BrandPartnerLink::where('affiliate_professional_id', $affiliateId)
            ->where('brand_professional_id', $claimedBrandId)
            ->first();
        if (! $link) { … return $payload; }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-2** · P2 — Diagnostic console command leaks Shopify access token prefix
    - **Where:** app/Console/Commands/Diagnostics/ShopifyTokenDiagnoseCommand.php:51‑53
    - **Affects:** Administrators running the command; the token prefix (first 8 characters) and a hint of the token type appear in console output that may be captured in logs or screen recordings.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the lines that print the token prefix (`substr($token, 0, 8)`) and the `shpat_` presence hint.
        - Only show the token length and whether the ping succeeded, keeping the session completely free of secret material.
    - **Technical:** The `ShopifyTokenDiagnoseCommand` uses `$this->line` to output `'token prefix:   '.substr($token, 0, 8).'...'` and `'looks like shpat_? '.(str_starts_with($token, 'shpat_') ? 'yes' : 'NO')`. While the full token is never printed, exposing the first 8 characters and the knowledge that the token starts with `shpat_` reduces the search space for an attacker who might later obtain hashed or partial data. Console output is often redirected/captured (CI logs, operational dashboards), so any secret material should be avoided. The fix is to drop those lines entirely; the token length and HTTP status already provide enough diagnostic signal.
    - **Plain English:** A mechanic’s diagnostic tool that shows the first few digits of a customer’s credit card number to “prove the card is real” — even though the whole number isn’t shown, those digits can still be misused if someone later finds the receipt. This command does the same thing with Shopify access tokens, printing a brief snippet. The safe fix is to stop showing any part of the token at all and just report that the test succeeded or failed.
    - **Evidence:**
        ```php
        $this->line('token len:      '.strlen($token));
        $this->line('token prefix:   '.substr($token, 0, 8).'...');
        $this->line('looks like shpat_? '.(str_starts_with($token, 'shpat_') ? 'yes' : 'NO'));
        ```
    - `[DRAFT, confidence: 0.7]`
