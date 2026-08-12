<?php

namespace App\Mail\Support;

use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Facades\URL;

/**
 * One place that decides whether a notification category is opt-out-able and
 * builds its signed one-click unsubscribe URL. Shared by
 * CategoryNotificationMail and any custom mailable that carries a
 * category-scoped unsubscribe (the weekly digest).
 */
final class CategoryUnsubscribe
{
    public static function urlFor(?string $userId, string $category): ?string
    {
        // critical always emails; mandatory categories resolve to enabled at
        // send time regardless of any stored preference row.
        if ($category === '' || $category === 'critical') {
            return null;
        }

        if (NotificationPublisher::isMandatory($category)) {
            return null;
        }

        if (! is_string($userId) || $userId === '') {
            return null;
        }

        return URL::signedRoute('public.notification-unsubscribe', [
            'userId' => $userId,
            'category' => $category,
        ]);
    }
}
