- [ ] **#SEC-1** · P1 — SSRF via unsanitised shop domain in affiliate catalog query
    - **Where:** app/Services/Store/AffiliateProductCatalogService.php:328-333 (queryAdminCatalog)
    - **Affects:** Any brand's Shopify access token could be exfiltrated if `provider_metadata.shop_domain` is ever corrupted or written without validation.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace the raw `Http::post($url, ...)` call with `$this->client->graphql(ShopDomain::fromUntrusted($shopDomain), ...)` through the existing `ShopifyAdminClient`.
        - If a direct HTTP call is necessary for some reason, validate the domain with `ShopDomain::fromUntrusted()` before constructing the URL.
    - **Technical:** Every other Shopify API call in the codebase routes through `ShopifyAdminClient::graphql()` or `ShopifyStorefrontClient::graphql()`, both of which require a `ShopDomain` value object that has passed the `<handle>.myshopify.com` regex. `queryAdminCatalog` bypasses this guard entirely: it reads the raw string from `provider_metadata`, interpolates it into an HTTPS URL, and sends the brand's bearer token to whatever host it resolves to. If an attacker can poison the metadata — via a direct DB write, a compromised job payload, or a future unvalidated metadata merge — they receive the access token on their own server.
    - **Plain English:** Every door into Shopify's API has a lock that checks the shop address is a real `*.myshopify.com` domain. The affiliate catalog query is the one door that skips the lock — it reads the address from a sticky note in the database, drives there directly, and hands over the brand's keys. If someone swaps the sticky note, the keys go to a stranger's house.
    - **Evidence:**
        ```php
        $shopDomain = trim((string) Arr::get($metadata, 'shop_domain', ''));
        $accessToken = trim((string) ($integration->access_token ?? ''));
        // ...
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
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-2** · P2 — GitHub API error response body logged verbatim
    - **Where:** app/Services/Shopify/HydrogenDeploymentService.php:68-72
    - **Affects:** Nightwatch / log aggregator ingestion — GitHub sometimes echoes request fragments in error bodies.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `$response->body()` in the warning log with a fixed-length truncation (e.g. `substr($response->body(), 0, 500)`) or a set of safe fields (status + headers only).
        - Audit the same pattern across `ShopifyAdminClient::rest` and `ShopifyTeardownService` call-sites that log upstream bodies.
    - **Technical:** The `Log::warning` call captures `'body' => $response->body()`. GitHub's API error responses occasionally include request context in their `message` or `documentation_url` fields, and while the `Authorization` header is never reflected, a truncated body could still contain the repo path or workflow input values. Log persistence (Nightwatch, CloudWatch) means this data outlives the request by weeks or months.
    - **Plain English:** When the GitHub deploy button fails, the service writes the full reply from GitHub into the permanent log. That reply sometimes contains fragments of what we sent — not the password, but enough breadcrumbs that a log reader could piece together internal repo paths and workflow details. The fix is to only save the status code and a short summary, not the whole letter.
    - **Evidence:**
        ```php
        Log::warning('HydrogenDeployment: GitHub API returned non-2xx.', [
            'professional_id' => $professionalId,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SEC-3** · P2 — Public catalog-access methods accept raw brand ID without caller-authorization check
    - **Where:** app/Services/Store/AffiliateProductCatalogService.php:321 (`fetchActiveCatalog`), :538 (`isProductInCatalog`), :555 (`getEnabledVariantGidsForProduct`)
    - **Affects:** Any controller that calls these methods without first verifying the caller is authorized for `$brandProfessionalId` could leak catalog data or enable variant enumeration.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `Professional $caller` parameter to `fetchActiveCatalog`, `isProductInCatalog`, and `getEnabledVariantGidsForProduct`.
        - Inside each, call `resolveAffiliateBrandIntegration` (or a `BrandPartnerLink` existence check) to confirm the caller is connected to that brand before returning data.
        - Document the contract in the method docblock so future callers don't reintroduce the gap.
    - **Technical:** `getCatalogWithSelections` and `getStaleSelections` both call `resolveAffiliateBrandIntegration()` first, which validates the affiliate↔brand link exists. The three listed methods skip this check entirely — they accept a raw `$brandProfessionalId` string and immediately query or cache against it. If a future staff endpoint, webhook handler, or internal job calls `fetchActiveCatalog` with a caller-supplied brand ID, it would serve that brand's full product catalog without proving the caller has any relationship to the brand.
    - **Plain English:** Some rooms in the catalog library have a security guard who checks your ID before you enter. Three rooms have no guard — if you know the room number, you walk straight in. The fix is to put the same guard at every door, not just the main entrance.
    - **Evidence:**
        ```php
        public function fetchActiveCatalog(string $brandProfessionalId): array
        {
            return $this->cacheLock->rememberLocked(
                CacheKeyGenerator::brandActiveCatalog($brandProfessionalId),
                300,
                fn () => $this->queryAdminCatalog($brandProfessionalId),
            );
        }

        public function isProductInCatalog(string $brandProfessionalId, string $productGid): bool
        {
            $catalog = $this->fetchActiveCatalog($brandProfessionalId);
            return collect($catalog)->contains('gid', $productGid);
        }

        public function getEnabledVariantGidsForProduct(string $brandProfessionalId, string $productGid): array
        {
            $catalog = $this->fetchActiveCatalog($brandProfessionalId);
            // ...
        }
        ```
    - `[DRAFT, confidence: 0.7]`
