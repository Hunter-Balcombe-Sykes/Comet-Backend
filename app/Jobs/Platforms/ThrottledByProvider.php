<?php

namespace App\Jobs\Platforms;

// Marks a queued CONNECT job that draws on a paid, rate-limited upstream (an Apify
// actor). The 'platform-connect' RateLimiter (registered in
// PlatformRegistryServiceProvider::boot) keys its per-minute budget by the value
// returned here, so a signup spike can't stampede a single actor. Mirrors the
// per-platform keying RefreshConnectionJob gets from the 'platform-refresh' limiter,
// but keyed by Apify ACTOR (connect and refresh never share a vendor: connect =
// Apify, refresh = the official APIs).
interface ThrottledByProvider
{
    /** Stable rate-budget key — the Apify actor slug ('instagram' | 'menu' | 'google-business'). */
    public function providerRateKey(): string;
}
