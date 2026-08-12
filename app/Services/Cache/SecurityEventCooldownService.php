<?php

namespace App\Services\Cache;

use Illuminate\Support\Facades\Cache;

/**
 * Replay-spam guard for SecurityEventController: a stolen token pinging the
 * same event repeatedly must not flood the user's inbox. One claim per user
 * per event per cooldown window — routed through here (not a raw Cache::add
 * in the controller) so the key flows through CacheKeyGenerator like every
 * other cache write (GS-1).
 */
class SecurityEventCooldownService
{
    private const COOLDOWN_SECONDS = 300;

    /** True the first time this (user, event) pair is claimed within the window; false on a repeat. */
    public function claim(string $userId, string $event): bool
    {
        return Cache::add(CacheKeyGenerator::securityEventCooldown($userId, $event), 1, self::COOLDOWN_SECONDS);
    }
}
