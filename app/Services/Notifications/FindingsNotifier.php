<?php

namespace App\Services\Notifications;

use App\Models\Core\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Bell notification for integration findings discovered by ASYNC scans
 * (link-in-bio unroll, previous-website scan) — the connect modal that shows
 * findings live is long closed by the time these jobs finish, so without a
 * notification the user never learns anything was found. Same atomic
 * insertOrIgnore dedupe discipline as SignupSideEffects::createWelcomeNotification
 * (notifications_dedupe_key_per_pro_uq): a re-run of the same scan can't stack
 * duplicate rows.
 */
class FindingsNotifier
{
    public function notify(string $userId, string $dedupeKey, string $title, string $body): void
    {
        $now = now();

        Notification::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'type' => 'Info',
            'title' => $title,
            'body' => $body,
            'cta_url' => '/account/integrations',
            'severity' => 'info',
            'starts_at' => $now,
            'ends_at' => null,
            'dedupe_key' => $dedupeKey,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
