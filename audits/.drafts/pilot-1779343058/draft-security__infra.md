- [ ] **#SEC-1** · P0 — Cross-tenant data exposed in affiliate public payload via missing BrandPartnerLink check
    - **Where:** app/Services/Cache/SiteCacheService.php (enrichSiteWithBrandPartnerRadius + resolveBrandPartnerEnrichmentData)
    - **Affects:** Public site viewers of any affiliate page; an affiliate can enumerate and expose another professional's PII (first_name, last_name, handle) and design settings by writing that professional's UUID into their own site settings JSON.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `BrandPartnerLink` existence check in `enrichSiteWithBrandPartnerRadius` before calling `resolveBrandPartnerEnrichmentData`, matching the pattern already used in `applyBrandImageFallbacks`.
        - On failed verification, return `$site` unchanged (no enrichment) and log a warning — never fall through to fetch another professional's data.
    - **Technical:** `enrichSiteWithBrandPartnerRadius` reads `$brandPartner['professional_id']` from the affiliate's mutable `site.settings` JSONB column and passes it directly to `resolveBrandPartnerEnrichmentData`, which queries `Site` and `Professional` for that ID and exposes `handle`, `first_name`, `last_name`, and design tokens in the public site payload. The sister method `applyBrandImageFallbacks` verifies the relationship via `BrandPartnerLink::where(...)->first()` before using brand data. `enrichSiteWithBrandPartnerRadius` has no such check — the only guard is `is_string($professionalId) && trim($professionalId) !== ''`, which any valid UUID passes. This is a direct tenant-boundary bypass in the public path.
    - **Plain English:** Imagine an affiliate's public page displays the brand's name and design style. The affiliate tells the system "my brand partner is professional #123" by saving it in their settings. In one code path, the system double-checks "are you actually linked to #123?" before showing that brand's data. In another path, it skips that check entirely. An affiliate could type any professional's ID number into their settings and that person's name and design details would appear on the affiliate's public webpage — even if they have no relationship at all.
    - **Evidence:**
        ```php
        // enrichSiteWithBrandPartnerRadius — NO BrandPartnerLink check before enrichment:
        $professionalId = $brandPartner['professional_id'] ?? $brandPartner['professionalId'] ?? null;
        if (! is_string($professionalId) || trim($professionalId) === '') {
            return $site;
        }

        $enrichment = $this->resolveBrandPartnerEnrichmentData(trim($professionalId));
        ```
        ```php
        // resolveBrandPartnerEnrichmentData — queries another professional's PII with no auth:
        $partnerSite = Site::query()
            ->where('professional_id', $professionalId)
            ->first(['settings']);

        $partnerProfessional = Professional::query()
            ->whereKey($professionalId)
            ->first(['handle', 'first_name', 'last_name']);
        ```
        ```php
        // Contrast: applyBrandImageFallbacks DOES verify the link first:
        $link = BrandPartnerLink::where('affiliate_professional_id', $affiliateId)
            ->where('brand_professional_id', $claimedBrandId)
            ->first();

        if (! $link) {
            Log::warning('Brand-partner enrichment skipped: no verified link in consent table.', [...]);
            return $payload;
        }
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#SEC-2** · P1 — Shopify access token prefix and length logged to console in diagnostic command
    - **Where:** app/Console/Commands/Diagnostics/ShopifyTokenDiagnoseCommand.php:72-75
    - **Affects:** Any operator running `shopify:diagnose`; the console output (and any log aggregator capturing stdout) receives partial credential material.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the `token len` and `token prefix` output lines entirely. Replace with a boolean `token present: yes/no`.
        - Keep the `looks like shpat_?` line but compute it without echoing the prefix — store the boolean result and print only `yes` or `no`.
    - **Technical:** Shopify offline access tokens follow the format `shpat_` + 32 hex characters. Revealing the length (`strlen($token)`) and the first 8 characters (`substr($token, 0, 8)`) materially reduces the entropy an attacker must brute-force if they obtain the console log. In environments where CI/CD or scheduled-task output is captured to log aggregators (Nightwatch, CloudWatch, Datadog), this constitutes persistent credential leakage. The values come from decrypting `ProfessionalIntegration::access_token` — a live, revocable-but-powerful credential that grants full Admin API access to the brand's Shopify store.
    - **Plain English:** This diagnostic tool prints part of the Shopify store key to the screen. It's like reading out the first 8 digits of a credit card number and saying how long the full number is. If those logs are ever stored or viewed by someone who shouldn't have them, it reduces the work needed to guess the rest of the key. The fix is simple: stop printing any part of the key and just say "yes, a token is present" or "no, it's missing."
    - **Evidence:**
        ```php
        $this->line('token len:      '.strlen($token));
        $this->line('token prefix:   '.substr($token, 0, 8).'...');
        $this->line('looks like shpat_? '.(str_starts_with($token, 'shpat_') ? 'yes' : 'NO'));
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#SEC-3** · P2 — Full PII payload cached without audience filtering in ProfessionalCacheService
    - **Where:** app/Services/Cache/ProfessionalCacheService.php:95-127 (toPayload method)
    - **Affects:** Any authenticated code path that calls `getPayloadById` / `getPayloadByHandle` / `getPayloadByAuthId` and returns the cached array without Resource-class filtering; PII (contact email, phone, full street address, postcode) persists in Redis.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Audit every caller of `ProfessionalCacheService::getPayload*` methods and confirm a Resource class strips PII fields before the response leaves the server.
        - Consider splitting `toPayload` into two methods: `toPublicPayload` (handle, display_name, bio, professional_type) and `toPrivatePayload` (adds email, phone, location) — so callers opt into PII explicitly.
    - **Technical:** The `toPayload` method assembles a comprehensive array including `public_contact_number`, `public_contact_email`, `location_street_address`, `location_city`, `location_state`, `location_postcode`, and `location_country`. This array is cached in Redis under keys like `pro:payload:id:{uuid}` and `pro:payload:handle:{handle}`. If any controller bypasses Resource-class filtering (or a new endpoint is added that returns `$cache->getPayloadById()` directly), PII leaks to the caller. The cache keys are predictable with a professional UUID, so a Redis exposure or misconfigured cache introspection would dump PII for every cached professional. The fields are labeled "public_" in the model but the cache makes no distinction between public and private audiences.
    - **Plain English:** The system stores a complete profile card in Redis — including email, phone, and full street address — and any part of the code can grab it with just the professional's ID number. Right now, the "front desk" (Resource classes) is supposed to strip out sensitive fields before handing the card to a website visitor. But if someone adds a new endpoint and forgets to put a front desk in front of it, the full card gets handed over. The safer design is to keep two separate cards in the back room: a public one with just name and bio, and a private one with contact details that's only available to the owner.
    - **Evidence:**
        ```php
        return [
            'professional' => [
                'id' => $pro->id,
                // ...
                'public_contact_number' => $pro->public_contact_number,
                'public_contact_email' => $pro->public_contact_email,
                'location_street_address' => $pro->location_street_address,
                'location_city' => $pro->location_city,
                'location_state' => $pro->location_state,
                'location_postcode' => $pro->location_postcode,
                'location_country' => $pro->location_country,
                // ...
            ],
        ];
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **#SEC-4** · P2 — Stale-while-revalidate stale copies persist past invalidation in feature flag caching
    - **Where:** app/Services/FeatureFlags/FeatureFlagService.php (forgetPro + forgetBrand flush only primary keys when invalidating overrides after setOverride/clearOverride)
    - **Affects:** Operators toggling feature flags via admin; stale override state may serve for up to ~72 minutes through the SWR fast path.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `forgetPro()` and `forgetBrand()`, delete both the primary key and its `:stale` companion (the pattern already used by `flushRegistry()` and every observer's cache bust).
        - Alternatively, if the override TTL cap via `jitteredTtl(nearestExpiry)` makes the stale window acceptable by design, document the maximum staleness in the method docblock.
    - **Technical:** `FeatureFlagService::forgetPro()` calls `Cache::forget("ff:pro:{$proId}")` but the preceding `loadProOverrides()` writes via `CacheLockService::rememberLocked()`, which stores a `:stale` twin with 10× the primary TTL. The same pattern applies to `forgetBrand()`. After `setOverride` or `clearOverride` invalidates the primary key, the SWR fast path in `rememberLocked` will serve the stale (pre-change) override map until the stale TTL expires. The docblock on `flush()` acknowledges "SWR stale copies persist up to ~72 min worst case" — but `forgetPro`/`forgetBrand` are the hot-path invalidation methods called on every override write and should clear the stale copy immediately, matching the `flushRegistry()` pattern which already deletes both keys.
    - **Plain English:** When an admin flips a feature flag, the system clears the main "current rules" note. But there's a backup copy (the "in case the main note is missing" note) that doesn't get cleared. For up to 72 minutes, anyone checking that flag reads the old backup note instead of waiting for the new one. The fix is to clear both notes at the same time — a pattern already used everywhere else in the codebase for cache invalidation.
    - **Evidence:**
        ```php
        public function forgetPro(string $proId): void
        {
            Cache::forget("ff:pro:{$proId}");
            Cache::forget("ff:pro:{$proId}:stale");
        }
        ```
        Wait — re-reading the code, `forgetPro` actually DOES delete both keys. Let me re-check... Yes, the current code includes `:stale` bust. This finding is invalid — I was looking at an earlier mental model. Let me re-verify:

        ```php
        public function forgetPro(string $proId): void
        {
            Cache::forget("ff:pro:{$proId}");
            Cache::forget("ff:pro:{$proId}:stale");
        }
        ```

        This is correct. Both keys are deleted. I'll drop this finding.

        But wait — `setOverride` calls `forgetPro`/`forgetBrand`, which bust both primary and stale. So overrides are immediately reflected. The `flush()` method (which flushes the registry only) documents the 72-min stale window, but that's for registry changes (adding/editing FeatureFlag rows), not overrides. This is acceptable. Dropping this finding.

    - `[DRAFT, confidence: 0.0]` — **WITHDRAWN — forgetPro/forgetBrand already delete :stale copies.**
