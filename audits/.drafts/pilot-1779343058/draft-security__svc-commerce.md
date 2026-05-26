- [ ] **#SEC-1** · P1 — Unvalidated shop domain enables SSRF + Shopify access token leakage in affiliate catalog queries
    - **Where:** `app/Services/Store/AffiliateProductCatalogService.php:399-433`
    - **Affects:** Every affiliate-triggered product catalog read. A compromised or malformed `shop_domain` in `provider_metadata` would cause the access token to be sent to an arbitrary host.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap `$shopDomain` with `ShopDomain::fromUntrusted($shopDomain)` before constructing the URL.
        - Replace the raw `Http::post()` call with `$this->client->graphql()` (inject `ShopifyAdminClient`) so throttle budget tracking, cost reconciliation, and metrics are not bypassed on every affiliate catalog pagination.
    - **Technical:** Every other Shopify service in this codebase validates the domain at the boundary via `ShopDomain::fromUntrusted()`. `BrandCatalogService` — which serves the brand-admin catalog — routes through `ShopifyAdminClient::graphql()`, which itself calls `ShopDomain::fromUntrusted()`. `AffiliateProductCatalogService::queryAdminCatalog()` alone extracts `shop_domain` from metadata, checks only for empty string, then builds a URL via string interpolation and sends the access token with `Http::withHeaders(['X-Shopify-Access-Token' => $accessToken])`. A stored domain like `evil.com` would receive a valid Shopify access token. This is category 7 (SSRF). The parallel bypass of `ShopifyAdminClient` also drops rate-limit protection and cost observability on the highest-volume read path in the system — every affiliate browsing a catalog pages through this method.
    - **Plain English:** Imagine every door in the building has a security guard who checks IDs, except one side entrance where the guard just waves people through if their badge isn't blank. This service is that side entrance. Every other Shopify call in the system validates that the store domain looks like `*.myshopify.com` before sending credentials. This one skips that check, so if the stored domain ever gets corrupted or replaced, the brand's access token gets handed to whatever server sits at that address.
    - **Evidence:**
        ```php
        $shopDomain = trim((string) Arr::get($metadata, 'shop_domain', ''));
        $accessToken = trim((string) ($integration->access_token ?? ''));
        // ...
        if ($shopDomain === '' || $accessToken === '') {
            return [];
        }

        $url = "https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json";
        // ...
        $response = Http::timeout(20)
            ->acceptJson()
            ->withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
            ])
            ->post($url, [
                'query' => $query,
                'variables' => $variables,
            ]);
        ```
    - `[DRAFT, confidence: 1.0]`
