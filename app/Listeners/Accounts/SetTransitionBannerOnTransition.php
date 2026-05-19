<?php

namespace App\Listeners\Accounts;

use App\Events\Accounts\AccountTypeTransitionEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * §28.5 — Dashboard transition banner.
 *
 * Writes a one-shot Redis banner the dashboard surfaces on the next render:
 * "Your account type changed from X to Y." The frontend dismisses via a
 * future endpoint; the banner auto-expires after 7 days regardless.
 *
 * Payload shape (stable contract for Track B):
 *   { from: <enum value>, to: <enum value>, at: <ISO8601> }
 */
class SetTransitionBannerOnTransition
{
    private const CACHE_KEY_FMT = 'professional:%s:transition_banner';

    private const TTL_SECONDS = 604800; // 7 days

    public function handle(AccountTypeTransitionEvent $event): void
    {
        $key = sprintf(self::CACHE_KEY_FMT, (string) $event->professional->id);

        try {
            Cache::put($key, [
                'from' => $event->from->value,
                'to' => $event->to->value,
                'at' => now()->toIso8601String(),
            ], self::TTL_SECONDS);
        } catch (\Throwable $e) {
            Log::warning('SetTransitionBannerOnTransition: cache write failed', [
                'professional_id' => (string) $event->professional->id,
                'from' => $event->from->value,
                'to' => $event->to->value,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
