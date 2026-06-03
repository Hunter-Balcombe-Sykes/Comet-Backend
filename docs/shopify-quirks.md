# Shopify Integration — Known Quirks

Load-bearing rules for working with the Shopify integration. If you're touching the catalog, pricing, or reinstall paths, read the relevant section first.

## Affiliate Catalog: Admin API, not Storefront API

`AffiliateProductCatalogService` queries Shopify via Admin API (`access_token`, header `X-Shopify-Access-Token`, URL `/admin/api/{ver}/graphql.json`). It was switched from Storefront API because custom-app storefront tokens are scoped to the **Online Store publication only** — they cannot see products published to Hydrogen sales channels, which is where every Partna brand's catalog lives. Do NOT revert this to Storefront API.

## Admin API `priceRange` amounts are 100× the display value

`priceRange.minVariantPrice.amount` (and max) from Shopify Admin GraphQL returns the price scaled by 100 — a $36.00 AUD product returns `"3600"`. The frontend divides by 100 before display (canonical pattern: `Partna-Frontend/lib/hooks/use-brand-catalog.ts:93` and `products-section.tsx`). **Do not add a backend divide** — the frontend divide is intentional and matches all consumers. Variant-level `ProductVariant.price` (a `Money` scalar) is NOT scaled — it returns the correct dollar string and should not be divided.

## Brand catalog and affiliate catalog are ACTIVE-only

`BrandCatalogService::PRODUCTS_WITH_METAFIELDS` passes `query: "status:active"` to Shopify's `products()`. DRAFT and ARCHIVED products are excluded from the brand commerce table and affiliate metafield map. To read all statuses, use `fetchAllProducts()` instead.

## `disconnected_at` cleared on reinstall

`EmbeddedSetupController::provisionShopifyIntegration` unsets `disconnected_at` from `professional_integrations.provider_metadata` on every provision call (PR #16). `BrandStatusService::determine()` checks `disconnected_at` first — if it's set, the brand stays in Disconnected status regardless of token state. If a brand is stuck in Disconnected after reinstall, confirm the provision-integration call ran and the field was cleared.

## Embedded auth (Comet side)

`/internal/embedded/*` uses Shopify-signed session JWT via `shopify.session` middleware (`VerifyShopifySessionToken`). Full flow, reject codes, config invariants, and forbidden patterns: see `docs/shopify-embedded-auth.md`.
