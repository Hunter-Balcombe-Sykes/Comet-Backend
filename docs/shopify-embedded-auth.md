## Shopify embedded auth (Comet side)

`/internal/embedded/*` is the API surface for the Partna-Shopify-App embedded app. Tenant identity comes from a Shopify-signed session JWT forwarded by Remix — NOT from a shared static key or trusted header. See `../Partna-Shopify-App/CLAUDE.md` for the Remix-side flow and `../CLAUDE.md` for the cross-repo picture.

### Middleware (`shopify.session` group)

`app/Http/Middleware/Auth/VerifyShopifySessionToken.php` (aliased as `shopify.session` in `bootstrap/app.php`) runs the full validation order on every `/internal/embedded/*` request:

1. Extract Bearer token from `Authorization` header — 401 `token_missing` if absent.
2. `JWT::decode($token, new Key($secret, 'HS256'))` with 10s leeway — 401 `sig_invalid` on any throw (covers signature mismatch, `exp`, `nbf` via `firebase/php-jwt ^6`).
3. `aud == config('services.shopify.api_key')` — 401 `aud_mismatch`.
4. `dest` host (lowercase, parsed) ends with `.myshopify.com` — 401 `dest_invalid`.
5. `iss` host equals `dest` host — 401 `iss_mismatch`. (Both should point at the same Shopify shop URL; mismatch indicates a forged or replayed token.)
6. `jti` claim present and unseen in `Cache::add("partna:shopify-jti:{$jti}", 1, 120)` — 401 `jti_missing` if absent, 401 `jti_replay` if already cached. If the cache backend throws → 503 `cache_unavailable` (fail-closed; never fail-open).
7. Resolve professional via `ShopifyShopResolver::resolveByShopDomain($destHost)` — 404 `shop_unlinked` if absent.
8. Stash on the request attributes: `embedded_professional_id`, `embedded_shop_domain`, `embedded_shopify_user_id` (from `sub`).

Every reject path logs `shopify.session.failed { reason, path, duration_ms, ... }`. Successes log `shopify.session.ok { shop, duration_ms }`. Reason codes are a fixed enum: `sig_invalid | exp | nbf | aud_mismatch | dest_invalid | iss_mismatch | jti_missing | jti_replay | shop_unlinked | cache_unavailable | token_missing | ok`. Controllers read tenant identity from `$request->attributes->get('embedded_professional_id')` etc. — never re-decode the token.

### Configuration invariants

- `SHOPIFY_API_SECRET` (`.env`) matches the secret on the Partna-Shopify-App Vercel deployment exactly. Symmetric HS256 secret. Rotation: synchronised env updates across both platforms within ~60s + `redis-cli --scan --pattern 'partna:shopify-jti:*' | xargs redis-cli del`. Full runbook in the rebuild plan (`~/.claude/plans/we-spent-a-long-humming-phoenix.md`).
- `CACHE_STORE=redis` in production. The middleware fails closed (503) if Redis is unreachable. File/array drivers leak across pods and are unsafe in multi-pod environments.
- `firebase/php-jwt ^6` (composer) — required for `nbf`/`exp` claim handling and the `JWT::decode($token, new Key($secret, 'HS256'))` API.
- Rate limit: `throttle:embedded-by-shop` (60 req/min keyed by `dest` shop domain) applied alongside `shopify.session` on every `/internal/embedded/*` route. The limiter is registered in `RouteServiceProvider::boot()`.
- Admin API version for per-shop access-token validation: `2026-04`. `validateShopifyAccessToken()` hits `https://{shop}/admin/api/2026-04/shop.json` and asserts `data.shop.myshopify_domain === $destHost` (the JWT's `dest` claim). Domain mismatch → reject; 401 → reject; 5xx / network error → allow on the assumption of transient outage.

### Forbidden patterns

- `embedded.key` middleware — DEPRECATED. Deleted in Phase 8 of the rebuild plan.
- `VerifyEmbeddedApiKey` — DELETED. The class no longer exists; the only auth on `/internal/embedded/*` is `shopify.session` (JWT).
- `embedded.dual` / `EmbeddedDualAuth` — DELETED. The cutover-window dispatcher was removed once the Remix side moved to JWT-only.
- `PARTNA_EMBEDDED_API_KEY` env var — DELETED from `.env.example` and `config/services.php`. Remove from Laravel Cloud envs too.
- `if ($expected !== '')` inline auth checks (the historic fail-open pattern in `EmbeddedConnectController`) — replaced by middleware + `$request->attributes->get('embedded_*')`.
- Trusting `X-Shopify-Shop` for tenant identity — the `dest` claim is the sole source. The header may still appear during the dual-auth window for backward compatibility but it is not authoritative.

### Webhook context

`app/uninstalled` is HMAC-validated directly at the Laravel `/api/webhooks/shopify/app-uninstalled` receiver. It does NOT pass through `shopify.session` (no App Bridge JWT in webhook context). The Remix-side handler (`Partna-Shopify-App/app/routes/webhooks.app.uninstalled.tsx`) returns 200 from `authenticate.webhook(request)` only and must not call `/internal/embedded/*` — webhook context carries no token.
