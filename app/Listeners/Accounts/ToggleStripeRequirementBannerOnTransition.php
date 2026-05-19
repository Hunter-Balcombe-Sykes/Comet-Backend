<?php

namespace App\Listeners\Accounts;

use App\Events\Accounts\AccountTypeTransitionEvent;
use App\Services\Accounts\AccountCapabilities;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * §28.5 — Stripe activation toggle.
 *
 * Sets or clears the `stripe_setup_required` Redis flag based on whether the
 * new account_type requires Stripe Connect AND the pro hasn't already
 * activated. Dashboard reads this key to decide whether to show the
 * "complete Stripe setup" banner. Stripe state itself is owned by webhooks —
 * this listener never mutates Stripe.
 *
 * Cache TTL: 1 hour. Refreshed naturally on each dashboard render.
 */
class ToggleStripeRequirementBannerOnTransition
{
    private const CACHE_KEY_FMT = 'professional:%s:stripe_setup_required';

    private const TTL_SECONDS = 3600;

    public function handle(AccountTypeTransitionEvent $event): void
    {
        $pro = $event->professional;
        $key = sprintf(self::CACHE_KEY_FMT, (string) $pro->id);

        try {
            $caps = AccountCapabilities::for($pro);
            $hasConnected = in_array((string) ($pro->stripe_connect_status ?? ''), ['active', 'enabled'], true);

            if ($caps->requires_stripe_connect && ! $hasConnected) {
                Cache::put($key, true, self::TTL_SECONDS);
            } else {
                Cache::forget($key);
            }
        } catch (\Throwable $e) {
            // Banner is purely informational — never let it break the transition.
            Log::warning('ToggleStripeRequirementBannerOnTransition failed', [
                'professional_id' => (string) $pro->id,
                'from' => $event->from->value,
                'to' => $event->to->value,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
