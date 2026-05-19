<?php

namespace App\Listeners\Accounts;

use App\Events\Accounts\AccountTypeTransitionEvent;
use App\Models\Core\Notifications\NotificationEmailPreference;
use App\Services\Accounts\AccountCapabilities;
use Illuminate\Support\Facades\Log;

/**
 * §28.5 — When account_type changes, drop NotificationEmailPreference rows for
 * categories that are no longer in the new capability set. The dispatcher
 * already capability-gates outbound mail (§28.10), so this is defence-in-depth:
 * it keeps the user's saved preferences in sync with what they can actually
 * receive, which surfaces correctly in `GET /me/notification-email-preferences`.
 *
 * Idempotent — deleting rows that no longer apply is safe to re-run.
 */
class SyncNotificationPreferencesOnTransition
{
    public function handle(AccountTypeTransitionEvent $event): void
    {
        $pro = $event->professional;
        $allowed = AccountCapabilities::for($pro)->notification_categories;

        // 'full' means every category — nothing to prune.
        if ($allowed === 'full' || trim($allowed) === '') {
            return;
        }

        $allowedSet = array_filter(array_map('trim', explode(',', $allowed)), fn ($k) => $k !== '');

        try {
            NotificationEmailPreference::query()
                ->where('professional_id', $pro->id)
                ->whereNotIn('category_key', $allowedSet)
                ->delete();
        } catch (\Throwable $e) {
            // Don't fail the transition because pref pruning hit a transient DB
            // issue — the dispatcher will still gate on capabilities at send time.
            Log::warning('SyncNotificationPreferencesOnTransition: prune failed', [
                'professional_id' => (string) $pro->id,
                'from' => $event->from->value,
                'to' => $event->to->value,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
