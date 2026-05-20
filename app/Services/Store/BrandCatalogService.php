<?php

namespace App\Services\Store;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use App\Services\Shopify\Client\ShopifyAdminClient;
use App\Services\Shopify\ShopDomain;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BrandCatalogService
{
    public function __construct(
        private readonly ShopifyAdminClient $client,
        private readonly CacheLockService $cacheLock,
    ) {}

    private const PRODUCTS_PER_PAGE = 50;

    // --- GraphQL Queries & Mutations ---

    private const ALL_PRODUCTS = <<<'GRAPHQL'
query allProducts($first: Int!, $after: String) {
  products(first: $first, after: $after, query: "status:active") {
    edges {
      node {
        id
        title
        handle
        status
        description
        featuredImage { url altText }
        # Gallery for the brand detail modal — five is plenty for the
        # modal carousel and fits within Shopify's 1000-point cost budget.
        # Cost math on this query: products(50) + variants(50·5=250) +
        # images(50·5=250) + metafields(50·5=250) = 800, safely under.
        images(first: 5) { edges { node { url altText } } }
        priceRange {
          minVariantPrice { amount currencyCode }
          maxVariantPrice { amount currencyCode }
        }
        # Variants + per-variant sidest.enabled state — needed by the brand
        # catalog table to render the expand chevron on multi-variant
        # products and the per-variant enable/disable toggle.
        #
        # Cost math on this query (Shopify's 1000-point-per-request budget):
        #   products(first: 50)             = 50
        #   variants(first: N) × 50         = 50·N
        #   images(first: 1) × 50           = 50
        #   5 metafields × 50               = 250
        #   ── total                       ≈ 350 + 50·N
        # Capping variants at 5 lands us at ~600, a comfortable margin. If a
        # brand has >5 variants on a product the catalog still shows the
        # ones it can and the single-product detail queries elsewhere in
        # this service fetch the full list on demand.
        variants(first: 5) {
          edges {
            node {
              id
              title
              availableForSale
              # Admin API's ProductVariant.price is a `Money` scalar (decimal
              # string like "29.99"), not a MoneyV2 object — doing subselections
              # here fails with "Selections can't be made on scalars". Currency
              # comes from the parent product's priceRange.minVariantPrice.
              price
              metafield_enabled: metafield(namespace: "partna", key: "enabled") { value }
            }
          }
        }
        metafield_active: metafield(namespace: "partna", key: "active") { value }
        metafield_commission: metafield(namespace: "partna", key: "commission_override") { value }
        metafield_discount: metafield(namespace: "partna", key: "affiliate_discount_pct") { value }
        metafield_custom_photos: metafield(namespace: "partna", key: "custom_photos_enabled") { value }
        metafield_has_enabled_variants: metafield(namespace: "partna", key: "has_enabled_variants") { value }
      }
      cursor
    }
    pageInfo { hasNextPage }
  }
}
GRAPHQL;

    private const PRODUCTS_WITH_METAFIELDS = <<<'GRAPHQL'
query products($first: Int!, $after: String) {
  products(first: $first, after: $after, query: "status:active") {
    edges {
      node {
        id
        title
        handle
        status
        featuredImage {
          url
          altText
        }
        priceRange {
          minVariantPrice { amount currencyCode }
          maxVariantPrice { amount currencyCode }
        }
        variants(first: 100) {
          edges {
            node {
              id
              title
              availableForSale
              # Admin API's ProductVariant.price is a `Money` scalar (decimal
              # string like "29.99"), not a MoneyV2 object — doing subselections
              # here fails with "Selections can't be made on scalars". Currency
              # comes from the parent product's priceRange.minVariantPrice.
              price
              metafield_enabled: metafield(namespace: "partna", key: "enabled") { value }
            }
          }
        }
        metafield_active: metafield(namespace: "partna", key: "active") { value }
        metafield_commission: metafield(namespace: "partna", key: "commission_override") { value }
        metafield_discount: metafield(namespace: "partna", key: "affiliate_discount_pct") { value }
        metafield_custom_photos: metafield(namespace: "partna", key: "custom_photos_enabled") { value }
        metafield_has_enabled_variants: metafield(namespace: "partna", key: "has_enabled_variants") { value }
      }
      cursor
    }
    pageInfo { hasNextPage }
  }
}
GRAPHQL;

    private const PRODUCT_VARIANT_GIDS = <<<'GRAPHQL'
query productVariantGids($productId: ID!) {
  product(id: $productId) {
    variants(first: 100) {
      edges { node { id } }
    }
  }
}
GRAPHQL;

    private const PRODUCT_CUSTOM_PHOTOS_QUERY = <<<'GRAPHQL'
query productCustomPhotos($productId: ID!) {
  product(id: $productId) {
    metafield(namespace: "partna", key: "custom_photos_enabled") { value }
  }
}
GRAPHQL;

    private const PRODUCT_ACTIVE_QUERY = <<<'GRAPHQL'
query productActive($productId: ID!) {
  product(id: $productId) {
    metafield(namespace: "partna", key: "active") { value }
  }
}
GRAPHQL;

    private const METAFIELDS_SET = <<<'GRAPHQL'
mutation metafieldsSet($metafields: [MetafieldsSetInput!]!) {
  metafieldsSet(metafields: $metafields) {
    metafields { id namespace key value }
    userErrors { field message }
  }
}
GRAPHQL;

    private const METAFIELD_DELETE = <<<'GRAPHQL'
mutation metafieldDelete($input: MetafieldDeleteInput!) {
  metafieldDelete(input: $input) {
    deletedId
    userErrors { field message }
  }
}
GRAPHQL;

    private const PRODUCT_METAFIELD_ID = <<<'GRAPHQL'
query productMetafield($productId: ID!, $namespace: String!, $key: String!) {
  product(id: $productId) {
    metafield(namespace: $namespace, key: $key) { id }
  }
}
GRAPHQL;

    private const SHOP_ID_QUERY = '{ shop { id } }';

    private const COMMISSION_OVERRIDES_QUERY = <<<'GRAPHQL'
query commissionOverrides($ids: [ID!]!) {
  nodes(ids: $ids) {
    ... on Product {
      id
      metafield(namespace: "partna", key: "commission_override") { value }
    }
  }
}
GRAPHQL;

    private const COLLECTIONS_QUERY = <<<'GRAPHQL'
query collections($query: String!, $first: Int!) {
  collections(query: $query, first: $first) {
    edges { node { id handle } }
  }
}
GRAPHQL;

    private const COLLECTION_PRODUCTS = <<<'GRAPHQL'
query collectionProducts($id: ID!, $first: Int!, $after: String) {
  collection(id: $id) {
    products(first: $first, after: $after) {
      edges {
        node {
          id
          title
          handle
          featuredImage { url altText }
          priceRange {
            minVariantPrice { amount currencyCode }
            maxVariantPrice { amount currencyCode }
          }
        }
        cursor
      }
      pageInfo { hasNextPage }
    }
  }
}
GRAPHQL;

    private const COLLECTION_ADD_PRODUCTS = <<<'GRAPHQL'
mutation collectionAddProducts($id: ID!, $productIds: [ID!]!) {
  collectionAddProducts(id: $id, productIds: $productIds) {
    collection { id }
    userErrors { field message }
  }
}
GRAPHQL;

    private const COLLECTION_REMOVE_PRODUCTS = <<<'GRAPHQL'
mutation collectionRemoveProducts($id: ID!, $productIds: [ID!]!) {
  collectionRemoveProducts(id: $id, productIds: $productIds) {
    job { id done }
    userErrors { field message }
  }
}
GRAPHQL;

    /**
     * Resolve the brand's Shopify integration credentials.
     *
     * @return array{integration: ProfessionalIntegration, shop_domain: string, access_token: string, metadata: array}
     *
     * @throws \RuntimeException
     */
    public function resolveBrandIntegration(Professional $brand): array
    {
        $integration = ProfessionalIntegration::query()
            ->where('professional_id', $brand->id)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            throw new \RuntimeException('Your Shopify store is not connected.', 422);
        }

        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $shopDomain = trim((string) Arr::get($metadata, 'shop_domain', ''));
        $accessToken = trim((string) $integration->access_token);

        if ($shopDomain === '' || $accessToken === '') {
            throw new \RuntimeException('Shopify integration is not fully configured.', 503);
        }

        return [
            'integration' => $integration,
            'shop_domain' => $shopDomain,
            'access_token' => $accessToken,
            'metadata' => $metadata,
        ];
    }

    /**
     * Fetch ALL products from the brand's Shopify store (no metafield dependencies).
     * Uses a lightweight query that only needs basic product read access.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAllProducts(Professional $brand): array
    {
        return $this->queryAllProducts($brand);
    }

    /**
     * Diagnostic probe: two modes.
     *
     *   mode = 'minimal' (default)
     *     Runs a hand-rolled minimal query (shop + products(first:3) with
     *     just id/title/status). Confirms auth works and returns a sample.
     *
     *   mode = 'all'
     *     Runs the exact same ALL_PRODUCTS query that /brand/catalog/all uses,
     *     with the same PRODUCTS_PER_PAGE variable, and returns the raw
     *     Shopify response — so we can see the full cost breakdown, any
     *     errors, and the actual edge count for the real query.
     *
     * Both modes surface Shopify's errors array, the extensions.cost
     * breakdown, the HTTP status, and the locally-stored scope list.
     */
    public function probeProductsQuery(Professional $brand, string $mode = 'minimal'): array
    {
        $resolved = $this->resolveBrandIntegration($brand);
        $shopDomain = $resolved['shop_domain'];
        $accessToken = $resolved['access_token'];
        $metadata = $resolved['metadata'];
        $apiVersion = (string) config('services.shopify.api_version', '2025-01');

        $minimalQuery = <<<'GRAPHQL'
        query probe {
          shop { id name myshopifyDomain }
          products(first: 3) {
            edges { node { id title status } }
            pageInfo { hasNextPage }
          }
        }
        GRAPHQL;

        [$query, $variables] = $mode === 'all'
            ? [self::ALL_PRODUCTS, ['first' => self::PRODUCTS_PER_PAGE]]
            : [$minimalQuery, []];

        $httpStatus = 0;
        $responseBody = [];
        try {
            $response = $this->client->graphql(
                ShopDomain::fromUntrusted($shopDomain),
                $accessToken,
                $apiVersion,
                $query,
                $variables,
                timeoutSeconds: 15,
            );
            $httpStatus = $response->status();
            $responseBody = $response->json() ?? [];
        } catch (\App\Exceptions\Shopify\ShopifyTransportException $e) {
            $httpStatus = $e->status;
            Log::warning('Shopify probe: request failed', ['error' => $e->getMessage(), 'mode' => $mode]);
        } catch (\App\Exceptions\Shopify\ShopifyGraphQLException $e) {
            $httpStatus = 200;
            $responseBody = ['errors' => $e->graphqlErrors];
            Log::warning('Shopify probe: GraphQL errors', ['errors' => $e->graphqlErrors, 'mode' => $mode]);
        } catch (\Throwable $e) {
            Log::warning('Shopify probe: request failed', ['error' => $e->getMessage(), 'mode' => $mode]);
        }

        $edges = Arr::get($responseBody, 'data.products.edges', []);
        $sample = array_map(
            fn (array $edge) => [
                'id' => (string) Arr::get($edge, 'node.id', ''),
                'title' => (string) Arr::get($edge, 'node.title', ''),
                'status' => (string) Arr::get($edge, 'node.status', ''),
            ],
            is_array($edges) ? array_slice($edges, 0, 5) : []
        );

        return [
            'mode' => $mode,
            'shop' => Arr::get($responseBody, 'data.shop'),
            'products_total_edges' => is_array($edges) ? count($edges) : 0,
            'products_sample' => $sample,
            'page_info' => Arr::get($responseBody, 'data.products.pageInfo'),
            'errors' => Arr::get($responseBody, 'errors', []),
            'cost' => Arr::get($responseBody, 'extensions.cost'),
            'http_status' => $httpStatus,
            'scopes_granted' => is_array(Arr::get($metadata, 'scopes')) ? Arr::get($metadata, 'scopes') : [],
        ];
    }

    /**
     * Fetch the brand's full product catalog with sidest.* metafield values (cached).
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchBrandCatalog(Professional $brand): array
    {
        return $this->cacheLock->rememberLocked(
            CacheKeyGenerator::brandAdminCatalog((string) $brand->id),
            (int) config('partna.cache.ttls.brand_admin_catalog'),
            fn () => $this->queryAdminCatalog($brand),
        );
    }

    /**
     * Fetch the sidest.commission_override metafield for a set of product GIDs
     * in a single Admin API call. Returns a map keyed by product GID; value is
     * the float override or null when the metafield is unset.
     *
     * Used by ProcessShopifyOrderWebhookJob to resolve commission rates
     * server-side instead of trusting buyer-set cart line attributes.
     *
     * @param  array<int, string>  $productGids
     * @return array<string, float|null>
     */
    public function fetchCommissionOverridesForProducts(ProfessionalIntegration $integration, array $productGids): array
    {
        $productGids = array_values(array_unique(array_filter($productGids)));
        if (empty($productGids)) {
            return [];
        }

        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $shopDomain = trim((string) Arr::get($metadata, 'shop_domain', ''));
        $accessToken = trim((string) $integration->access_token);

        if ($shopDomain === '' || $accessToken === '') {
            return array_fill_keys($productGids, null);
        }

        try {
            $response = $this->graphql(
                $shopDomain,
                $accessToken,
                self::COMMISSION_OVERRIDES_QUERY,
                ['ids' => $productGids]
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch commission overrides.', [
                'integration_id' => (string) $integration->id,
                'error' => $e->getMessage(),
            ]);

            return array_fill_keys($productGids, null);
        }

        $nodes = $response->json('data.nodes', []);
        $out = array_fill_keys($productGids, null);

        if (is_array($nodes)) {
            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    continue;
                }
                $gid = (string) ($node['id'] ?? '');
                if ($gid === '') {
                    continue;
                }
                $val = Arr::get($node, 'metafield.value');
                $out[$gid] = $val !== null ? (float) $val : null;
            }
        }

        return $out;
    }

    /**
     * Fetch the partna.active metafield for one product, scoped to one product
     * per Shopify call (no full-catalog pagination). Caller is responsible for
     * caching — the controller wraps this with rememberLockedNullable so a cold
     * miss for a single product never fans out into a paginated catalog fetch.
     * Master Pattern 17 / DB-F#SCALE-6.
     *
     * @throws \App\Exceptions\Shopify\ShopifyClientException on transport, throttle, or GraphQL error
     */
    public function fetchProductActiveMetafield(ProfessionalIntegration $integration, string $productGid): ?bool
    {
        $resolved = $this->resolveCredentials($integration);

        if ($resolved['shop_domain'] === '' || $resolved['access_token'] === '') {
            return null;
        }

        $response = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::PRODUCT_ACTIVE_QUERY, [
            'productId' => $productGid,
        ]);

        $value = Arr::get($response->json(), 'data.product.metafield.value');

        if ($value === null) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Fetch the per-product custom_photos_enabled metafield with a short cache.
     * Returns true/false if set, null if not set on the product.
     */
    public function fetchProductCustomPhotosMetafield(ProfessionalIntegration $integration, string $productGid): ?bool
    {
        // Cache as string sentinel so "unset" cases still benefit from the TTL
        // (Cache::remember treats a cached null as a miss and re-runs the closure).
        $cacheKey = CacheKeyGenerator::brandProductCustomPhotos((string) $integration->professional_id, $productGid);
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return match ($cached) {
                'true' => true,
                'false' => false,
                default => null,
            };
        }

        $resolved = $this->resolveCredentials($integration);

        try {
            $response = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::PRODUCT_CUSTOM_PHOTOS_QUERY, [
                'productId' => $productGid,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch product custom_photos_enabled metafield.', [
                'brand_id' => (string) $integration->professional_id,
                'product_gid' => $productGid,
                'error' => $e->getMessage(),
            ]);

            Cache::put($cacheKey, 'unset', now()->addSeconds((int) config('partna.cache.ttls.product_custom_photos')));

            return null;
        }

        $value = Arr::get($response->json(), 'data.product.metafield.value');

        if ($value === null) {
            Cache::put($cacheKey, 'unset', now()->addSeconds((int) config('partna.cache.ttls.product_custom_photos')));

            return null;
        }

        $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        Cache::put($cacheKey, $bool ? 'true' : 'false', now()->addSeconds((int) config('partna.cache.ttls.product_custom_photos')));

        return $bool;
    }

    /**
     * Clear every `sidest.enabled = false` metafield on the product's variants.
     * Called when the product is activated (sidest.active flipped to true) so
     * the brand's expectation — "making the product available re-enables all
     * its variants" — is honoured in Shopify data, not just the dashboard UI.
     *
     * Strategy: read every variant with its current sidest.enabled value, then
     * flip any that are explicitly false back to true via metafieldsSet (one
     * batched call). Variants whose metafield is missing stay missing (dynamic
     * default = enabled). After the sweep, the product's has_enabled_variants
     * derived flag is rewritten to true since every variant now resolves to
     * enabled (or the product has no variants at all).
     *
     * Idempotent: if no variants are disabled, makes no writes.
     *
     * @return array{success: bool, cleared: int, userErrors: array}
     */
    public function clearVariantDisablesForProduct(ProfessionalIntegration $integration, string $productGid): array
    {
        $resolved = $this->resolveCredentials($integration);

        // Fetch variant GIDs + current sidest.enabled metafield values in one go.
        // Same shape as the catalog query but scoped to a single product so
        // cost stays tiny regardless of catalog size.
        $query = <<<'GRAPHQL'
        query variantEnabledStates($productId: ID!) {
          product(id: $productId) {
            variants(first: 100) {
              edges {
                node {
                  id
                  metafield_enabled: metafield(namespace: "partna", key: "enabled") { value }
                }
              }
            }
          }
        }
        GRAPHQL;

        $response = $this->graphql($resolved['shop_domain'], $resolved['access_token'], $query, [
            'productId' => $productGid,
        ]);

        $edges = Arr::get($response->json(), 'data.product.variants.edges', []);
        if (! is_array($edges)) {
            return ['success' => true, 'cleared' => 0, 'userErrors' => []];
        }

        // Collect every variant currently marked explicitly disabled.
        $disabledVariantGids = [];
        foreach ($edges as $edge) {
            $node = $edge['node'] ?? [];
            $id = (string) Arr::get($node, 'id', '');
            $enabledRaw = Arr::get($node, 'metafield_enabled.value');
            if ($id === '' || $enabledRaw === null) {
                continue;
            }
            if (filter_var($enabledRaw, FILTER_VALIDATE_BOOLEAN) === false) {
                $disabledVariantGids[] = $id;
            }
        }

        // Nothing to do — product's variants are all already enabled (or have
        // no metafield, which means default-enabled anyway).
        if (empty($disabledVariantGids)) {
            // Still refresh the derived flag so re-activating a product whose
            // has_enabled_variants was previously false (e.g. from an earlier
            // bulk-disable) lands in a correct smart-collection state.
            $this->writeHasEnabledVariants($integration, $productGid, true);

            return ['success' => true, 'cleared' => 0, 'userErrors' => []];
        }

        // Flip each previously-false sidest.enabled to true.
        $metafields = array_map(fn (string $variantGid) => [
            'namespace' => 'partna',
            'key' => 'enabled',
            'value' => 'true',
            'type' => 'boolean',
            'ownerId' => $variantGid,
        ], $disabledVariantGids);

        foreach (array_chunk($metafields, 25) as $batch) {
            $writeResponse = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::METAFIELDS_SET, [
                'metafields' => $batch,
            ]);

            $userErrors = Arr::get($writeResponse->json(), 'data.metafieldsSet.userErrors', []);
            if (! empty($userErrors)) {
                return ['success' => false, 'cleared' => 0, 'userErrors' => $userErrors];
            }
        }

        // Cache bust — variant state affects both brand and affiliate catalog views.
        // Forget :stale twins so the CacheLockService SWR fast path doesn't keep
        // serving the pre-bust value for up to 10× the base TTL.
        $this->bustCatalogKeyWithStale(CacheKeyGenerator::brandAdminCatalog((string) $integration->professional_id));
        $this->bustCatalogKeyWithStale(CacheKeyGenerator::brandActiveCatalog((string) $integration->professional_id));

        // Recompute the derived has_enabled_variants flag — now trivially true.
        $this->writeHasEnabledVariants($integration, $productGid, true);

        return ['success' => true, 'cleared' => count($disabledVariantGids), 'userErrors' => []];
    }

    /**
     * Fetch the full set of variant GIDs for a single product. Used by the write path
     * to verify brand-submitted disabled_variant_gids only contains GIDs that
     * actually belong to this product. One GraphQL call regardless of catalog size.
     *
     * Limited to the first 100 variants — the realistic per-product cap. Raise the
     * limit here if a brand ever ships a product with more than 100 variants.
     *
     * @return array<int, string> Variant GID strings, e.g. ["gid://shopify/ProductVariant/1", ...]
     */
    public function fetchProductVariantGids(ProfessionalIntegration $integration, string $productGid): array
    {
        $resolved = $this->resolveCredentials($integration);

        $response = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::PRODUCT_VARIANT_GIDS, [
            'productId' => $productGid,
        ]);

        $edges = Arr::get($response->json(), 'data.product.variants.edges', []);

        if (! is_array($edges)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($edge) => Arr::get($edge, 'node.id'),
            $edges
        )));
    }

    /**
     * Set variant-level sidest.enabled metafields. Writes `false` on disabled variants
     * and `true` on all others to clear any previous restriction. Uses a single
     * metafieldsSet mutation (batched at 25 if the product has many variants).
     *
     * Also recomputes and writes sidest.has_enabled_variants on the parent product:
     * true if at least one variant is not disabled, false if every variant is
     * disabled. This drives the Active Products smart collection condition so a
     * product with no enabled variants falls out of affiliate-facing surfaces
     * automatically, without the brand having to flip sidest.active themselves.
     *
     * @param  string  $productGid  Parent product GID (used to write the derived product metafield)
     * @param  array<int, string>  $allVariantGids  Every variant GID for the product (pre-fetched for validation)
     * @param  array<int, string>  $disabledVariantGids  Variant GIDs to mark as disabled
     * @return array{success: bool, userErrors: array}
     */
    public function setVariantEnabledStates(ProfessionalIntegration $integration, string $productGid, array $allVariantGids, array $disabledVariantGids = []): array
    {
        if (empty($allVariantGids)) {
            // No variants on this product — has_enabled_variants is trivially true.
            // Write it anyway so newly created products get an explicit value and the
            // Active Products smart collection picks them up.
            return $this->writeHasEnabledVariants($integration, $productGid, true);
        }

        $disabled = array_flip($disabledVariantGids);
        $resolved = $this->resolveCredentials($integration);

        $metafields = array_map(fn (string $variantGid) => [
            'namespace' => 'partna',
            'key' => 'enabled',
            'value' => isset($disabled[$variantGid]) ? 'false' : 'true',
            'type' => 'boolean',
            'ownerId' => $variantGid,
        ], $allVariantGids);

        // Shopify limits metafieldsSet to 25 inputs per call
        foreach (array_chunk($metafields, 25) as $batch) {
            $response = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::METAFIELDS_SET, [
                'metafields' => $batch,
            ]);

            $userErrors = Arr::get($response->json(), 'data.metafieldsSet.userErrors', []);

            if (! empty($userErrors)) {
                return ['success' => false, 'userErrors' => $userErrors];
            }
        }

        // Bust caches — variant state affects both brand and affiliate views.
        // :stale twins must die alongside the primary or SWR keeps serving stale.
        $this->bustCatalogKeyWithStale(CacheKeyGenerator::brandAdminCatalog((string) $integration->professional_id));
        $this->bustCatalogKeyWithStale(CacheKeyGenerator::brandActiveCatalog((string) $integration->professional_id));

        // Recompute and persist the derived product-level flag
        $hasEnabled = count($disabledVariantGids) < count($allVariantGids);

        return $this->writeHasEnabledVariants($integration, $productGid, $hasEnabled);
    }

    /**
     * Write the derived sidest.has_enabled_variants metafield on a product. Separated
     * out so the backfill command can call it with a precomputed value without
     * re-writing every variant metafield.
     *
     * @return array{success: bool, userErrors: array}
     */
    public function writeHasEnabledVariants(ProfessionalIntegration $integration, string $productGid, bool $value): array
    {
        $resolved = $this->resolveCredentials($integration);

        $response = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::METAFIELDS_SET, [
            'metafields' => [[
                'namespace' => 'partna',
                'key' => 'has_enabled_variants',
                'value' => $value ? 'true' : 'false',
                'type' => 'boolean',
                'ownerId' => $productGid,
            ]],
        ]);

        $userErrors = Arr::get($response->json(), 'data.metafieldsSet.userErrors', []);

        // Writing has_enabled_variants moves the product in/out of the Active Products
        // smart collection, so the affiliate-facing cache must be invalidated too.
        // :stale twins die with the primary or SWR keeps serving the pre-write state.
        $this->bustCatalogKeyWithStale(CacheKeyGenerator::brandAdminCatalog((string) $integration->professional_id));
        $this->bustCatalogKeyWithStale(CacheKeyGenerator::brandActiveCatalog((string) $integration->professional_id));

        return [
            'success' => empty($userErrors),
            'userErrors' => $userErrors,
        ];
    }

    /**
     * Batch-write the derived has_enabled_variants metafield across many products
     * in a single metafieldsSet round-trip. Each MetafieldsSetInput carries its
     * own ownerId + value, so a batch can mix true/false freely — no grouping by
     * value is needed.
     *
     * Shopify caps the metafieldsSet input array at 25, so callers MUST pass <= 25
     * entries. The chunking + cursor checkpointing lives in the caller
     * (BackfillBrandHasEnabledVariantsJob) so a timeout mid-catalog still makes
     * forward progress instead of replaying from zero.
     *
     * @param  array<string, bool>  $productValues  product GID => desired flag
     * @return array{success: bool, userErrors: array}
     */
    public function writeHasEnabledVariantsBatch(ProfessionalIntegration $integration, array $productValues): array
    {
        if (empty($productValues)) {
            return ['success' => true, 'userErrors' => []];
        }

        // Enforce the contract loudly: an over-cap batch would otherwise be
        // rejected wholesale by Shopify as a userError and silently logged as
        // a partial failure — a caller bug should crash, not degrade quietly.
        if (count($productValues) > 25) {
            throw new \InvalidArgumentException(
                'writeHasEnabledVariantsBatch accepts at most 25 products per call; got '.count($productValues)
            );
        }

        $resolved = $this->resolveCredentials($integration);

        $metafields = [];
        foreach ($productValues as $productGid => $value) {
            $metafields[] = [
                'namespace' => 'partna',
                'key' => 'has_enabled_variants',
                'value' => $value ? 'true' : 'false',
                'type' => 'boolean',
                'ownerId' => (string) $productGid,
            ];
        }

        $response = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::METAFIELDS_SET, [
            'metafields' => $metafields,
        ]);

        $userErrors = Arr::get($response->json(), 'data.metafieldsSet.userErrors', []);

        // A has_enabled_variants change moves products in/out of the Active
        // Products smart collection — bust both catalog views (and their :stale
        // twins, or SWR keeps serving the pre-write state).
        $this->bustCatalogKeyWithStale(CacheKeyGenerator::brandAdminCatalog((string) $integration->professional_id));
        $this->bustCatalogKeyWithStale(CacheKeyGenerator::brandActiveCatalog((string) $integration->professional_id));

        return [
            'success' => empty($userErrors),
            'userErrors' => $userErrors,
        ];
    }

    /**
     * Set metafield values on a product.
     *
     * @param  array  $metafields  e.g. [['key' => 'active', 'value' => 'true', 'type' => 'boolean'], ...]
     * @return array{success: bool, userErrors: array}
     */
    public function setProductMetafields(ProfessionalIntegration $integration, string $productGid, array $metafields): array
    {
        $resolved = $this->resolveCredentials($integration);

        $metafieldsInput = array_map(fn (array $mf) => [
            'namespace' => 'partna',
            'key' => $mf['key'],
            'value' => (string) $mf['value'],
            'type' => $mf['type'],
            'ownerId' => $productGid,
        ], $metafields);

        $response = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::METAFIELDS_SET, [
            'metafields' => $metafieldsInput,
        ]);

        $data = $response->json();
        $userErrors = Arr::get($data, 'data.metafieldsSet.userErrors', []);

        // Bust caches
        $this->bustCatalogCaches($integration, $metafields, $productGid);

        return [
            'success' => empty($userErrors),
            'userErrors' => $userErrors,
        ];
    }

    /**
     * Delete a metafield from a product (for clearing overrides).
     */
    public function deleteProductMetafield(ProfessionalIntegration $integration, string $productGid, string $key): bool
    {
        $resolved = $this->resolveCredentials($integration);

        // First get the metafield ID
        $idResponse = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::PRODUCT_METAFIELD_ID, [
            'productId' => $productGid,
            'namespace' => 'partna',
            'key' => $key,
        ]);

        $metafieldId = Arr::get($idResponse->json(), 'data.product.metafield.id');

        if (! $metafieldId) {
            return true; // Already doesn't exist
        }

        $deleteResponse = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::METAFIELD_DELETE, [
            'input' => ['id' => $metafieldId],
        ]);

        $userErrors = Arr::get($deleteResponse->json(), 'data.metafieldDelete.userErrors', []);

        // Bust caches — :stale twin must die alongside the primary so the SWR
        // fast path can't keep serving the pre-delete value.
        $this->bustCatalogKeyWithStale(CacheKeyGenerator::brandAdminCatalog((string) $integration->professional_id));

        if ($key === 'custom_photos_enabled') {
            // brandProductCustomPhotos is a raw Cache::put (no SWR layer) — single forget is sufficient.
            Cache::forget(CacheKeyGenerator::brandProductCustomPhotos((string) $integration->professional_id, $productGid));
        }

        return empty($userErrors);
    }

    /**
     * Set shop-level metafields.
     *
     * @param  array  $metafields  e.g. [['key' => 'default_commission_rate', 'value' => '15', 'type' => 'number_decimal'], ...]
     * @return array{success: bool, userErrors: array}
     */
    public function setShopMetafields(ProfessionalIntegration $integration, array $metafields): array
    {
        $resolved = $this->resolveCredentials($integration);

        // Get shop GID
        $shopResponse = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::SHOP_ID_QUERY, []);
        $shopGid = Arr::get($shopResponse->json(), 'data.shop.id', '');

        if ($shopGid === '') {
            return ['success' => false, 'userErrors' => [['message' => 'Could not resolve shop ID.']]];
        }

        $metafieldsInput = array_map(fn (array $mf) => [
            'namespace' => 'partna',
            'key' => $mf['key'],
            'value' => (string) $mf['value'],
            'type' => $mf['type'],
            'ownerId' => $shopGid,
        ], $metafields);

        $response = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::METAFIELDS_SET, [
            'metafields' => $metafieldsInput,
        ]);

        $userErrors = Arr::get($response->json(), 'data.metafieldsSet.userErrors', []);

        return [
            'success' => empty($userErrors),
            'userErrors' => $userErrors,
        ];
    }

    /**
     * Resolve collection GID from handle (cached).
     */
    public function resolveCollectionGid(ProfessionalIntegration $integration, string $handle): ?string
    {
        $cacheKey = CacheKeyGenerator::brandCollectionGid((string) $integration->professional_id, $handle);

        // Int TTL so writeWithJitter can apply ±20% jitter; the config value
        // is already int seconds.
        return $this->cacheLock->rememberLocked($cacheKey, (int) config('partna.cache.ttls.collection_gid'), function () use ($integration, $handle) {
            $resolved = $this->resolveCredentials($integration);

            $response = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::COLLECTIONS_QUERY, [
                'query' => "handle:{$handle}",
                'first' => 1,
            ]);

            return Arr::get($response->json(), 'data.collections.edges.0.node.id');
        });
    }

    /**
     * Fetch products from a collection.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchCollectionProducts(ProfessionalIntegration $integration, string $collectionGid): array
    {
        $resolved = $this->resolveCredentials($integration);
        $products = [];
        $cursor = null;

        do {
            $variables = ['id' => $collectionGid, 'first' => self::PRODUCTS_PER_PAGE];
            if ($cursor !== null) {
                $variables['after'] = $cursor;
            }

            $response = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::COLLECTION_PRODUCTS, $variables);
            $data = $response->json();

            $edges = Arr::get($data, 'data.collection.products.edges', []);
            if (! is_array($edges)) {
                break;
            }

            foreach ($edges as $edge) {
                $node = $edge['node'] ?? [];
                $cursor = $edge['cursor'] ?? null;

                $products[] = [
                    'gid' => $node['id'] ?? '',
                    'title' => $node['title'] ?? '',
                    'handle' => $node['handle'] ?? '',
                    'featured_image' => $node['featuredImage'] ?? null,
                    'price_range' => [
                        'min' => Arr::get($node, 'priceRange.minVariantPrice'),
                        'max' => Arr::get($node, 'priceRange.maxVariantPrice'),
                    ],
                ];
            }

            $hasNextPage = Arr::get($data, 'data.collection.products.pageInfo.hasNextPage', false);
        } while ($hasNextPage && $cursor !== null);

        return $products;
    }

    /**
     * Add products to a manual collection.
     *
     * @return array{success: bool, userErrors: array}
     */
    public function addProductsToCollection(ProfessionalIntegration $integration, string $collectionGid, array $productGids): array
    {
        $resolved = $this->resolveCredentials($integration);

        $response = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::COLLECTION_ADD_PRODUCTS, [
            'id' => $collectionGid,
            'productIds' => $productGids,
        ]);

        $userErrors = Arr::get($response->json(), 'data.collectionAddProducts.userErrors', []);

        return [
            'success' => empty($userErrors),
            'userErrors' => $userErrors,
        ];
    }

    /**
     * Remove products from a manual collection.
     *
     * @return array{success: bool, userErrors: array}
     */
    public function removeProductsFromCollection(ProfessionalIntegration $integration, string $collectionGid, array $productGids): array
    {
        $resolved = $this->resolveCredentials($integration);

        $response = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::COLLECTION_REMOVE_PRODUCTS, [
            'id' => $collectionGid,
            'productIds' => $productGids,
        ]);

        $userErrors = Arr::get($response->json(), 'data.collectionRemoveProducts.userErrors', []);

        return [
            'success' => empty($userErrors),
            'userErrors' => $userErrors,
        ];
    }

    // --- Private Helpers ---

    /**
     * @return array{shop_domain: string, access_token: string}
     */
    private function resolveCredentials(ProfessionalIntegration $integration): array
    {
        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];

        return [
            'shop_domain' => trim((string) Arr::get($metadata, 'shop_domain', '')),
            'access_token' => trim((string) $integration->access_token),
        ];
    }

    private function graphql(string $shopDomain, string $accessToken, string $query, array $variables): \Illuminate\Http\Client\Response
    {
        $apiVersion = config('services.shopify.api_version', '2025-01');

        // Client throws ShopifyTransportException on non-2xx and ShopifyGraphQLException
        // on top-level errors — both propagate as RuntimeException for the caller.
        return $this->client->graphql(
            ShopDomain::fromUntrusted($shopDomain),
            $accessToken,
            $apiVersion,
            $query,
            $variables,
        );
    }

    /**
     * Bust relevant caches after a metafield write.
     */
    private function bustCatalogCaches(ProfessionalIntegration $integration, array $metafields, ?string $productGid = null): void
    {
        $brandId = (string) $integration->professional_id;

        // Always bust the admin catalog cache (primary + SWR :stale twin).
        $this->bustCatalogKeyWithStale(CacheKeyGenerator::brandAdminCatalog($brandId));

        $touchedKeys = array_column($metafields, 'key');

        // If 'active' was changed, also bust the affiliate-facing catalog cache
        // and the per-product active cache (embedded:product-active:*).
        if (in_array('active', $touchedKeys, true)) {
            $this->bustCatalogKeyWithStale(CacheKeyGenerator::brandActiveCatalog($brandId));

            if ($productGid !== null) {
                $productId = preg_replace('#^gid://shopify/Product/#', '', $productGid);
                // embedded:product-active:* is written by rememberLockedNullable
                // with no `:stale` twin — single Cache::forget is sufficient.
                Cache::forget(CacheKeyGenerator::embeddedProductActive($brandId, (string) $productId));
            }
        }

        // If per-product custom_photos_enabled was changed, bust the targeted lookup cache.
        // brandProductCustomPhotos uses raw Cache::put (no SWR) — single forget is sufficient.
        if ($productGid !== null && in_array('custom_photos_enabled', $touchedKeys, true)) {
            Cache::forget(CacheKeyGenerator::brandProductCustomPhotos($brandId, $productGid));
        }
    }

    /**
     * Forget both the primary cache key and its CacheLockService SWR `:stale` twin.
     *
     * CacheLockService::writeWithJitter writes every value to `$key` (primary,
     * jittered TTL) AND `$key:stale` (10× TTL, last-good window). If only the
     * primary is forgotten the SWR fast path keeps serving the pre-bust value
     * for up to 10× the base TTL, defeating the invalidation.
     */
    private function bustCatalogKeyWithStale(string $key): void
    {
        Cache::forget($key);
        Cache::forget($key.':stale');
    }

    /**
     * Query Admin API for all products with sidest metafield values.
     *
     * @return array<int, array<string, mixed>>
     */
    private function queryAdminCatalog(Professional $brand): array
    {
        $resolved = $this->resolveBrandIntegration($brand);
        $shopDomain = $resolved['shop_domain'];
        $accessToken = $resolved['access_token'];

        $products = [];
        $cursor = null;

        do {
            $variables = ['first' => self::PRODUCTS_PER_PAGE];
            if ($cursor !== null) {
                $variables['after'] = $cursor;
            }

            try {
                $response = $this->graphql($shopDomain, $accessToken, self::PRODUCTS_WITH_METAFIELDS, $variables);
                $data = $response->json();

                // Log GraphQL errors if present
                if (! empty($data['errors'])) {
                    Log::error('Shopify catalog GraphQL errors.', [
                        'brand_id' => (string) $brand->id,
                        'errors' => $data['errors'],
                    ]);
                }

                $edges = Arr::get($data, 'data.products.edges', []);
                if (! is_array($edges)) {
                    Log::warning('Shopify catalog returned no edges.', [
                        'brand_id' => (string) $brand->id,
                        'response_keys' => array_keys($data ?? []),
                    ]);
                    break;
                }

                foreach ($edges as $edge) {
                    $node = $edge['node'] ?? [];
                    $cursor = $edge['cursor'] ?? null;

                    // Admin API's variant.price is a Money scalar (string), while
                    // our downstream shape (and the frontend) expect a {amount,
                    // currencyCode} object. Borrow currency from the product's
                    // priceRange so the object shape stays intact.
                    $productCurrency = (string) Arr::get($node, 'priceRange.minVariantPrice.currencyCode', 'AUD');

                    $variants = [];
                    foreach (Arr::get($node, 'variants.edges', []) as $variantEdge) {
                        $v = $variantEdge['node'] ?? [];
                        $enabledVal = Arr::get($v, 'metafield_enabled.value');
                        $priceAmount = $v['price'] ?? null;
                        $variants[] = [
                            'gid' => $v['id'] ?? '',
                            'title' => $v['title'] ?? '',
                            'available_for_sale' => $v['availableForSale'] ?? false,
                            'price' => $priceAmount !== null
                                ? ['amount' => (string) $priceAmount, 'currencyCode' => $productCurrency]
                                : null,
                            'enabled' => $enabledVal !== null ? filter_var($enabledVal, FILTER_VALIDATE_BOOLEAN) : null,
                        ];
                    }

                    $activeVal = Arr::get($node, 'metafield_active.value');
                    $commissionVal = Arr::get($node, 'metafield_commission.value');
                    $discountVal = Arr::get($node, 'metafield_discount.value');
                    $customPhotosVal = Arr::get($node, 'metafield_custom_photos.value');
                    $hasEnabledVariantsVal = Arr::get($node, 'metafield_has_enabled_variants.value');

                    $products[] = [
                        'gid' => $node['id'] ?? '',
                        'title' => $node['title'] ?? '',
                        'handle' => $node['handle'] ?? '',
                        'status' => $node['status'] ?? 'ACTIVE',
                        'featured_image' => $node['featuredImage'] ?? null,
                        'price_range' => [
                            'min' => Arr::get($node, 'priceRange.minVariantPrice'),
                            'max' => Arr::get($node, 'priceRange.maxVariantPrice'),
                        ],
                        'variants' => $variants,
                        'metafields' => [
                            'active' => $activeVal !== null ? filter_var($activeVal, FILTER_VALIDATE_BOOLEAN) : null,
                            'commission_override' => $commissionVal !== null ? (float) $commissionVal : null,
                            'affiliate_discount_pct' => $discountVal !== null ? (float) $discountVal : null,
                            'custom_photos_enabled' => $customPhotosVal !== null ? filter_var($customPhotosVal, FILTER_VALIDATE_BOOLEAN) : null,
                            // null = unwritten (pre-backfill); true/false = explicit. The backfill
                            // command populates this for existing products; new writes go through
                            // writeHasEnabledVariants on every variant-state change.
                            'has_enabled_variants' => $hasEnabledVariantsVal !== null ? filter_var($hasEnabledVariantsVal, FILTER_VALIDATE_BOOLEAN) : null,
                        ],
                    ];
                }

                $hasNextPage = Arr::get($data, 'data.products.pageInfo.hasNextPage', false);
            } catch (\Throwable $e) {
                Log::error('Failed to fetch brand admin catalog.', [
                    'brand_id' => (string) $brand->id,
                    'error' => $e->getMessage(),
                ]);
                break;
            }
        } while ($hasNextPage && $cursor !== null);

        return $products;
    }

    /**
     * Query Admin API for all products without metafield dependencies.
     *
     * @return array<int, array<string, mixed>>
     */
    private function queryAllProducts(Professional $brand): array
    {
        $resolved = $this->resolveBrandIntegration($brand);
        $shopDomain = $resolved['shop_domain'];
        $accessToken = $resolved['access_token'];

        $products = [];
        $cursor = null;

        do {
            $variables = ['first' => self::PRODUCTS_PER_PAGE];
            if ($cursor !== null) {
                $variables['after'] = $cursor;
            }

            try {
                $response = $this->graphql($shopDomain, $accessToken, self::ALL_PRODUCTS, $variables);
                $data = $response->json();

                if (! empty($data['errors'])) {
                    Log::error('Shopify all-products GraphQL errors.', [
                        'brand_id' => (string) $brand->id,
                        'errors' => $data['errors'],
                    ]);
                }

                $edges = Arr::get($data, 'data.products.edges', []);
                if (! is_array($edges)) {
                    break;
                }

                foreach ($edges as $edge) {
                    $node = $edge['node'] ?? [];
                    $cursor = $edge['cursor'] ?? null;

                    $activeVal = Arr::get($node, 'metafield_active.value');
                    $commissionVal = Arr::get($node, 'metafield_commission.value');
                    $discountVal = Arr::get($node, 'metafield_discount.value');
                    $customPhotosVal = Arr::get($node, 'metafield_custom_photos.value');
                    $hasEnabledVariantsVal = Arr::get($node, 'metafield_has_enabled_variants.value');

                    // Map product images from GraphQL edges
                    $images = array_map(
                        fn ($imgEdge) => $imgEdge['node'] ?? null,
                        Arr::get($node, 'images.edges', [])
                    );
                    $images = array_values(array_filter($images));

                    // Variants + per-variant sidest.enabled flag. Null enabled
                    // means the metafield is absent (dynamic default = enabled);
                    // the frontend treats null as "not explicitly disabled".
                    // Admin API's variant.price is a Money scalar (string);
                    // reshape into {amount, currencyCode} using the product's
                    // priceRange currency so the downstream shape matches the
                    // Storefront API's MoneyV2-native output.
                    $productCurrency = (string) Arr::get($node, 'priceRange.minVariantPrice.currencyCode', 'AUD');
                    $variants = [];
                    foreach (Arr::get($node, 'variants.edges', []) as $variantEdge) {
                        $v = $variantEdge['node'] ?? [];
                        $enabledVal = Arr::get($v, 'metafield_enabled.value');
                        $priceAmount = $v['price'] ?? null;
                        $variants[] = [
                            'gid' => $v['id'] ?? '',
                            'title' => $v['title'] ?? '',
                            'available_for_sale' => $v['availableForSale'] ?? false,
                            'price' => $priceAmount !== null
                                ? ['amount' => (string) $priceAmount, 'currencyCode' => $productCurrency]
                                : null,
                            'enabled' => $enabledVal !== null ? filter_var($enabledVal, FILTER_VALIDATE_BOOLEAN) : null,
                        ];
                    }

                    $products[] = [
                        'gid' => $node['id'] ?? '',
                        'title' => $node['title'] ?? '',
                        'handle' => $node['handle'] ?? '',
                        'status' => $node['status'] ?? 'ACTIVE',
                        'description' => $node['description'] ?? '',
                        'featured_image' => $node['featuredImage'] ?? null,
                        'images' => $images,
                        'price_range' => [
                            'min' => Arr::get($node, 'priceRange.minVariantPrice'),
                            'max' => Arr::get($node, 'priceRange.maxVariantPrice'),
                        ],
                        'variants' => $variants,
                        'metafields' => [
                            'active' => $activeVal !== null ? filter_var($activeVal, FILTER_VALIDATE_BOOLEAN) : null,
                            'commission_override' => $commissionVal !== null ? (float) $commissionVal : null,
                            'affiliate_discount_pct' => $discountVal !== null ? (float) $discountVal : null,
                            'custom_photos_enabled' => $customPhotosVal !== null ? filter_var($customPhotosVal, FILTER_VALIDATE_BOOLEAN) : null,
                            'has_enabled_variants' => $hasEnabledVariantsVal !== null ? filter_var($hasEnabledVariantsVal, FILTER_VALIDATE_BOOLEAN) : null,
                        ],
                    ];
                }

                $hasNextPage = Arr::get($data, 'data.products.pageInfo.hasNextPage', false);
            } catch (\Throwable $e) {
                Log::error('Failed to fetch all products.', [
                    'brand_id' => (string) $brand->id,
                    'error' => $e->getMessage(),
                ]);
                break;
            }
        } while ($hasNextPage && $cursor !== null);

        return $products;
    }
}
