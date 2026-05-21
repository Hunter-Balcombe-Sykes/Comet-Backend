<?php

namespace App\Services\Shopify;

use Illuminate\Support\Facades\Cache;

class ShopifySetupTokenService
{
    private const CACHE_PREFIX = 'shopify_setup:';

    private const TTL_MINUTES = 60;

    /**
     * Cache the Shopify OAuth result keyed by a one-shot setup token returned to
     * the merchant. The token is then consumed by BootstrapController during the
     * signup-completion POST, which writes the access token into a
     * ProfessionalIntegration row inside the same DB transaction as the
     * Professional creation.
     *
     * @param  string|null  $boundUid  Optional Supabase uid this setup token is
     *   bound to. When set, BootstrapController must verify the calling uid
     *   matches before consuming. Used for the signup-flow Shopify install
     *   (POST /api/shopify/install-from-signup) where we know upfront which
     *   Supabase user kicked off the OAuth — prevents another caller who
     *   obtains the token from binding the integration to a different account.
     *   Null for the legacy/manual flow (Shopify App Store install) where the
     *   merchant authenticates inside the embedded wizard.
     */
    public function create(
        string $shopDomain,
        string $accessToken,
        array $shopData,
        array $scopes,
        string $shopEmail,
        ?string $boundUid = null,
    ): string {
        $token = bin2hex(random_bytes(32));

        Cache::put(self::CACHE_PREFIX.$token, [
            'shop_domain' => $shopDomain,
            'access_token' => encrypt($accessToken),
            'shop_data' => $shopData,
            'scopes' => $scopes,
            'shop_email' => $shopEmail,
            'bound_uid' => $boundUid,
            'created_at' => now()->toIso8601String(),
        ], now()->addMinutes(self::TTL_MINUTES));

        return $token;
    }

    public function peek(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $data = Cache::get(self::CACHE_PREFIX.$token);
        if (! is_array($data)) {
            return null;
        }

        return $this->decryptPayload($data);
    }

    public function consume(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $data = Cache::pull(self::CACHE_PREFIX.$token);
        if (! is_array($data)) {
            return null;
        }

        return $this->decryptPayload($data);
    }

    private function decryptPayload(array $data): array
    {
        $data['access_token'] = decrypt($data['access_token']);

        return $data;
    }
}
