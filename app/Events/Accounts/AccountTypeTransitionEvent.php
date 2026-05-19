<?php

namespace App\Events\Accounts;

use App\Enums\AccountType;
use App\Models\Core\Professional\Professional;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by `AccountTypeTransitionService` after the DB transaction commits.
 * Carries the professional whose type changed, the prior type, and the new type.
 * Listeners use this for cache invalidation, observability, and future
 * downstream feature toggles (notification prefs, Stripe activation, etc.).
 */
class AccountTypeTransitionEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Professional $professional,
        public readonly AccountType $from,
        public readonly AccountType $to,
    ) {}
}
