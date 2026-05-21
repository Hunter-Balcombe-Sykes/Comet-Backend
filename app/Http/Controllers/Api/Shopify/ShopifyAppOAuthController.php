<?php

namespace App\Http\Controllers\Api\Shopify;

use App\Exceptions\Shopify\ShopifyTransportException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\NormalizesShopDomain;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Shopify\BrandSignupService;
use App\Services\Shopify\Client\ShopifyAdminClient;
use App\Services\Shopify\ShopDomain;
use App\Services\Shopify\ShopifySetupTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifyAppOAuthController extends ApiController
{
    use NormalizesShopDomain;

    public function __construct(
        private readonly BrandSignupService $brandSignup,
        private readonly ShopifySetupTokenService $setupTokens,
        private readonly ShopifyAdminClient $shopifyClient,
    ) {}

    public function install(Request $request): RedirectResponse|JsonResponse
    {
        $shop = $this->normalizeShopDomain((string) $request->query('shop', ''));

        if ($shop === '') {
            return $this->error('Missing shop parameter.', 400);
        }

        if (! $this->isValidShopDomain($shop)) {
            return $this->error('Invalid shop domain.', 400);
        }

        $apiKey = (string) config('services.shopify.api_key');
        $redirectUri = rtrim((string) config('app.url'), '/').'/api/shopify/callback';
        $nonce = bin2hex(random_bytes(16));

        cache()->put("shopify_oauth_nonce_{$shop}", $nonce, now()->addMinutes(10));

        // Managed installation: omit the `scope` parameter so Shopify grants
        // every scope declared in Sidest-Embedded/shopify.app.toml
        // access_scopes block (pushed via `shopify app deploy`). Passing a
        // narrower scope= here would override the toml and short-grant the
        // merchant — that's how previous installs ended up without
        // read_products despite the toml listing it.
        //
        // Ref: https://shopify.dev/docs/apps/build/authentication-authorization/access-tokens/authorization-code-grant
        $authUrl = "https://{$shop}/admin/oauth/authorize?"
            .http_build_query([
                'client_id' => $apiKey,
                'redirect_uri' => $redirectUri,
                'state' => $nonce,
            ]);

        return redirect()->away($authUrl);
    }

    /**
     * Signup-context install kickoff. Used when a brand picks "Continue with
     * Shopify" on the Partna signup form (POST from app.partna.au, behind
     * supabase.jwt middleware so we know who initiated). Mints an OAuth nonce
     * AND a sibling "meta" cache entry binding the install to the calling
     * supabase_uid. The callback uses that binding to (a) decide to redirect
     * back to /sign-up instead of the embedded wizard, and (b) bind the
     * setup token so only the same uid can consume it at /bootstrap.
     *
     * Returns JSON `{ install_url }` rather than a 302; the browser navigates
     * client-side via window.location.href to avoid CORS preflight issues on
     * a cross-origin POST+JSON.
     */
    public function installFromSignup(Request $request): JsonResponse
    {
        $shop = $this->normalizeShopDomain((string) $request->input('shop', ''));
        $supabaseUid = (string) $request->attributes->get('supabase_uid', '');

        if ($shop === '') {
            return $this->error('Missing shop parameter.', 400);
        }

        if (! $this->isValidShopDomain($shop)) {
            return $this->error('Invalid shop domain.', 400);
        }

        if ($supabaseUid === '') {
            return $this->error('Authentication required.', 401);
        }

        $apiKey = (string) config('services.shopify.api_key');
        $redirectUri = rtrim((string) config('app.url'), '/').'/api/shopify/callback';
        $nonce = bin2hex(random_bytes(16));

        cache()->put("shopify_oauth_nonce_{$shop}", $nonce, now()->addMinutes(10));
        cache()->put(
            "shopify_oauth_nonce_meta_{$shop}",
            ['signup_context' => true, 'supabase_uid' => $supabaseUid],
            now()->addMinutes(10),
        );

        $authUrl = 'https://'.$shop.'/admin/oauth/authorize?'.http_build_query([
            'client_id' => $apiKey,
            'redirect_uri' => $redirectUri,
            'state' => $nonce,
        ]);

        return $this->success(['install_url' => $authUrl]);
    }

    public function callback(Request $request): RedirectResponse|JsonResponse
    {
        $shop = $this->normalizeShopDomain((string) $request->query('shop', ''));
        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');
        $hmac = (string) $request->query('hmac', '');

        if ($shop === '' || $code === '' || $state === '' || $hmac === '') {
            return $this->error('Missing required OAuth parameters.', 400);
        }

        if (! $this->isValidHmac($request->query(), (string) config('services.shopify.api_secret'))) {
            Log::warning('Shopify OAuth: invalid HMAC', ['shop' => $shop]);

            return $this->error('Invalid HMAC signature.', 400);
        }

        $expectedNonce = cache()->pull("shopify_oauth_nonce_{$shop}");
        if ($expectedNonce === null || ! hash_equals($expectedNonce, $state)) {
            Log::warning('Shopify OAuth: invalid nonce', ['shop' => $shop]);

            return $this->error('Invalid state parameter.', 400);
        }

        $tokenResponse = Http::post("https://{$shop}/admin/oauth/access_token", [
            'client_id' => config('services.shopify.api_key'),
            'client_secret' => config('services.shopify.api_secret'),
            'code' => $code,
        ]);

        if (! $tokenResponse->successful()) {
            Log::error('Shopify OAuth: token exchange failed', [
                'shop' => $shop,
                'status' => $tokenResponse->status(),
            ]);

            return $this->error('Failed to exchange OAuth code for access token.', 502);
        }

        $accessToken = (string) ($tokenResponse->json('access_token') ?? '');
        $scopes = (array) ($tokenResponse->json('scope') ? explode(',', (string) $tokenResponse->json('scope')) : []);

        if ($accessToken === '') {
            return $this->error('Empty access token received from Shopify.', 502);
        }

        $shopData = [];
        try {
            $apiVersion = (string) config('services.shopify.api_version', '2025-01');
            $shopResponse = $this->shopifyClient->rest(
                method: 'GET',
                shop: ShopDomain::fromUntrusted($shop),
                accessToken: $accessToken,
                path: "/admin/api/{$apiVersion}/shop.json",
            );
            $shopData = (array) ($shopResponse->json('shop') ?? []);
        } catch (ShopifyTransportException $e) {
            Log::warning('Shopify OAuth: shop fetch failed', ['shop' => $shop, 'status' => $e->status]);
        }

        $shopEmail = strtolower(trim((string) Arr::get($shopData, 'email', '')));
        $shopHandle = str_replace('.myshopify.com', '', $shop);
        $appHandle = (string) config('services.shopify.app_handle', 'side-st');
        $basePath = "https://admin.shopify.com/store/{$shopHandle}/apps/{$appHandle}";

        try {
            // Path A: Reinstall — existing integration for this shop domain. Safe to
            // auto-link because Shopify already proved ownership of the previously-
            // installed shop (the access token issued at OAuth time replaces the old one).
            $existingIntegration = ProfessionalIntegration::query()
                ->where('shopify_shop_domain', $shop)
                ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
                ->first();

            if ($existingIntegration) {
                $result = $this->brandSignup->handleReinstall($existingIntegration, $accessToken, $shopData, $scopes);

                Log::info('Shopify OAuth: reinstall', [
                    'professional_id' => (string) $result->professional->id,
                    'shop_domain' => $shop,
                ]);

                return redirect()->away($basePath);
            }

            // Path B (email auto-match) was removed for SEC-B#3 / SEC-F#1: shopData.email
            // is a Shopify-controlled, editable string and cannot prove ownership of a
            // Partna account. Every non-reinstall install now uses the setup-token flow
            // below — the merchant must Supabase-authenticate inside the setup wizard
            // (legacy path) or already has a Supabase session (signup-context path)
            // before BootstrapController attaches the integration to a Professional.

            // Signup-context branch: this OAuth flight was kicked off by
            // POST /api/shopify/install-from-signup (the brand picked "Continue
            // with Shopify" on app.partna.au/account/sign-up). Bind the setup
            // token to that supabase_uid and redirect the brand back to /sign-up
            // instead of into the embedded admin wizard, so they can finish
            // creating their Partna account with the prefilled Shopify data.
            $nonceMeta = cache()->pull("shopify_oauth_nonce_meta_{$shop}");
            $isSignupContext = is_array($nonceMeta) && ($nonceMeta['signup_context'] ?? false) === true;
            $boundUid = $isSignupContext && is_string($nonceMeta['supabase_uid'] ?? null)
                ? (string) $nonceMeta['supabase_uid']
                : null;

            $setupToken = $this->setupTokens->create($shop, $accessToken, $shopData, $scopes, $shopEmail, $boundUid);

            if ($isSignupContext) {
                Log::info('Shopify OAuth: signup-context install, redirecting to Partna sign-up', [
                    'shop_domain' => $shop,
                ]);

                $frontendUrl = rtrim((string) config('services.partna.frontend_url', 'https://app.partna.au'), '/');

                return redirect()->away("{$frontendUrl}/account/sign-up?shopify_setup_token={$setupToken}");
            }

            Log::info('Shopify OAuth: fresh install, redirecting to setup', [
                'shop_domain' => $shop,
            ]);

            return redirect()->away("{$basePath}/setup?shopify_setup_token={$setupToken}");
        } catch (\Throwable $e) {
            Log::error('Shopify OAuth: callback failed', [
                'shop' => $shop,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Failed to process Shopify app installation.', 500);
        }
    }

    public function setupPrefill(Request $request): JsonResponse
    {
        $token = trim((string) $request->query('token', ''));

        if ($token === '') {
            return $this->error('Missing token.', 400);
        }

        $data = $this->setupTokens->peek($token);

        if ($data === null) {
            return $this->error('Setup session not found or expired.', 404);
        }

        $shopData = $data['shop_data'] ?? [];

        return $this->success([
            'shop_name' => trim((string) Arr::get($shopData, 'name', '')),
            'shop_domain' => $data['shop_domain'] ?? '',
            'phone' => trim((string) Arr::get($shopData, 'phone', '')),
            'address' => [
                'address1' => trim((string) Arr::get($shopData, 'address1', '')),
                'city' => trim((string) Arr::get($shopData, 'city', '')),
                'province' => trim((string) Arr::get($shopData, 'province', '')),
                'zip' => trim((string) Arr::get($shopData, 'zip', '')),
                'country' => trim((string) Arr::get($shopData, 'country_name', '')),
            ],
            'country_code' => trim((string) Arr::get($shopData, 'country_code', '')),
            'timezone' => trim((string) Arr::get($shopData, 'iana_timezone', '')),
        ]);
    }

    private function isValidShopDomain(string $shop): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9\-]+\.myshopify\.com$/', $shop);
    }

    private function isValidHmac(array $params, string $secret): bool
    {
        $actual = (string) ($params['hmac'] ?? '');
        $filtered = array_filter($params, static fn ($key) => $key !== 'hmac', ARRAY_FILTER_USE_KEY);
        ksort($filtered);
        $message = http_build_query($filtered);
        $expected = hash_hmac('sha256', $message, $secret);

        return $actual !== '' && hash_equals($expected, $actual);
    }
}
