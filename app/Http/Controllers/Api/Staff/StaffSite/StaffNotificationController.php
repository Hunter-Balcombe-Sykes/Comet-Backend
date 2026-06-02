<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use App\Jobs\Notifications\SendStaffBroadcastEmailsJob;
use App\Jobs\Notifications\SendTransactionalNotificationEmailJob;
use App\Http\Resources\NotificationListingResource;
use App\Models\Core\Notifications\Notification;
use App\Models\Core\User\User;
use App\Services\Notifications\NotificationListingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// V2: Staff creates global or targeted notifications with optional email broadcast,
// and acts on behalf of a professional to clear stuck banners (NOTIF-1).
class StaffNotificationController extends ApiController
{
    public function __construct(private readonly NotificationListingService $listing) {}

    /** POST /staff/notifications */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'uuid'],
            'type' => ['required', 'string', 'max:50'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'cta_url' => ['nullable', 'string', 'max:2048'],
            'primary_action_label' => ['nullable', 'string', 'max:255'],
            'secondary_action_label' => ['nullable', 'string', 'max:255'],
            'secondary_action_url' => ['nullable', 'string', 'max:2048'],
            'severity' => ['nullable', 'string', 'in:info,warning,critical'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'send_email' => ['nullable', 'boolean'],
            'email_list_key' => ['nullable', 'string', 'max:50'],
            // Whitelisted to the three staff-broadcast categories only. Categories
            // like 'commissions' / 'payouts' have canonical event sources and must
            // not be manually issuable via this endpoint.
            'category' => ['nullable', 'string', Rule::in(['policy_update', 'incident', 'feature_announcement'])],
        ]);

        $data['type'] = Notification::normalizeFrontendType($data['type'] ?? null, $data['severity'] ?? null);
        $data['severity'] = Notification::severityForFrontendType($data['type']);

        // Retention now follows the category (e.g. policy_update=365d, incident=14d)
        // when one is set; otherwise we fall back to the map's 'default' lifetime.
        // A null retention value is intentional — it means "keep until manually
        // ended/dismissed", so leave ends_at unset in that case.
        if (empty($data['ends_at'])) {
            $retentionKey = $data['category'] ?? 'default';
            $days = config("partna.notification_retention_days.{$retentionKey}", config('partna.notification_retention_days.default', 30));
            if ($days !== null) {
                $data['ends_at'] = now()->addDays((int) $days);
            }
        }

        $notification = Notification::query()->create([
            ...$data,
            'category' => $data['category'] ?? null,
        ]);

        $sendEmail = (bool) ($data['send_email'] ?? false);
        $emailListKey = $data['email_list_key'] ?? 'sidest_updates';

        if ($sendEmail) {
            if ($notification->user_id !== null && $notification->category !== null) {
                // Targeted, categorised: unified pipeline — respects user notification_email_preferences.
                SendTransactionalNotificationEmailJob::dispatch(
                    $notification->id,
                    $notification->category,
                    $notification->user_id,
                );
            } elseif ($notification->user_id === null) {
                // Global: newsletter-style mass email to email_list_key subscribers.
                // Bypasses per-category prefs by design — globals are announcement-class
                // (incidents, policy updates) that should reach the audience regardless.
                SendStaffBroadcastEmailsJob::dispatch($notification->id, $emailListKey);
            }
            // Targeted broadcast without category + send_email=true is a no-op today (no
            // dispatch path). Could log a warning but it's a niche case — leave silent.
        }

        return $this->success(['notification' => (new NotificationListingResource($notification))->resolve()], 201);
    }

    /**
     * GET /staff/professionals/{professional}/notifications
     * Mirror of NotificationController::index — same payload shape, same cache —
     * but keyed off the route-bound professional rather than the JWT subject.
     */
    public function indexForProfessional(Request $request, User $professional): JsonResponse
    {
        $limit = (int) $request->query('limit', 50);
        $limit = max(1, min($limit, 200));

        $includeDismissed = filter_var($request->query('include_dismissed', false), FILTER_VALIDATE_BOOLEAN);

        return $this->success($this->listing->index((string) $professional->id, $limit, $includeDismissed));
    }

    /**
     * POST /staff/professionals/{professional}/notifications/{notification}/read
     */
    public function markReadForProfessional(Request $request, User $professional, Notification $notification): JsonResponse
    {
        $this->assertVisibleTo($notification, $professional);
        $this->listing->assertActive($notification);

        $this->listing->markRead($notification, (string) $professional->id);

        return $this->success(['ok' => true]);
    }

    /**
     * POST /staff/professionals/{professional}/notifications/{notification}/dismiss
     */
    public function dismissForProfessional(Request $request, User $professional, Notification $notification): JsonResponse
    {
        $this->assertVisibleTo($notification, $professional);
        $this->listing->assertActive($notification);

        $this->listing->dismiss($notification, (string) $professional->id);

        return $this->success(['ok' => true]);
    }

    /**
     * Staff context can't use NotificationPolicy (the policy's actor is the
     * resource owner, not the staff member acting on their behalf). Replicate
     * the ownership check inline: 404 if the notification is neither global
     * nor targeted at this professional.
     */
    private function assertVisibleTo(Notification $notification, User $professional): void
    {
        if (! $this->listing->visibleTo($notification, (string) $professional->id)) {
            abort(404);
        }
    }
}
