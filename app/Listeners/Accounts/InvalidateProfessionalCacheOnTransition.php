<?php

namespace App\Listeners\Accounts;

use App\Events\Accounts\AccountTypeTransitionEvent;
use App\Services\Cache\ProfessionalCacheService;

/**
 * Busts all Redis cache entries for the professional after an account type
 * transition so stale capability / dashboard data isn't served.
 */
class InvalidateProfessionalCacheOnTransition
{
    public function __construct(private readonly ProfessionalCacheService $cache) {}

    public function handle(AccountTypeTransitionEvent $event): void
    {
        $this->cache->invalidateProfessional($event->professional);
    }
}
