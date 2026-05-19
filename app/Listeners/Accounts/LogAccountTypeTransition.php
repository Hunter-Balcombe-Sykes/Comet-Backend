<?php

namespace App\Listeners\Accounts;

use App\Events\Accounts\AccountTypeTransitionEvent;
use Illuminate\Support\Facades\Log;

/**
 * Emits a structured log entry on every account type transition.
 * Provides an audit trail for observability without requiring a dedicated DB table.
 */
class LogAccountTypeTransition
{
    public function handle(AccountTypeTransitionEvent $event): void
    {
        Log::info('account_type_transition', [
            'professional_id' => (string) $event->professional->id,
            'from' => $event->from->value,
            'to' => $event->to->value,
        ]);
    }
}
