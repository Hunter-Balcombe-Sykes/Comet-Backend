<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Notifications\NotificationEmailPreference;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * One-click unsubscribe for category notification emails (P3, 2026-08-12).
 *
 * The link is a SIGNED route (userId + category are in the path, the
 * signature covers both), generated per-send by CategoryNotificationMail.
 * GET serves the footer link; POST satisfies RFC 8058 one-click
 * (List-Unsubscribe-Post) — mailbox providers POST it directly, so it is
 * CSRF-exempt, idempotent and always 2xx once the signature checks out.
 */
class PublicNotificationEmailUnsubscribeController extends ApiController
{
    public function unsubscribe(Request $request, string $userId, string $category): JsonResponse
    {
        // Only registered, non-mandatory categories can be opted out of.
        // (Mandatory ones resolve to enabled at send time regardless of any
        // stored row — refusing here keeps the stored state honest.)
        $registry = (array) config('partna.notifications.mailables', []);
        if (! array_key_exists($category, $registry)
            || $category === 'critical'
            || NotificationPublisher::isMandatory($category)) {
            return $this->error('Unknown or non-optional notification category.', 404);
        }

        if (! Str::isUuid($userId)) {
            return $this->error('Invalid unsubscribe link.', 404);
        }

        NotificationEmailPreference::query()->updateOrCreate(
            ['user_id' => $userId, 'category_key' => $category],
            ['enabled' => false],
        );

        NotificationPublisher::forget($userId);

        return $this->success(['ok' => true, 'unsubscribed' => true, 'category' => $category]);
    }
}
