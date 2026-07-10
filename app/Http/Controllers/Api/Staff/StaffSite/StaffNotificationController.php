<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Staff\Notifications\StaffStoreNotificationRequest;
use App\Http\Resources\NotificationListingResource;
use App\Jobs\Notifications\SendStaffBroadcastEmailsJob;
use App\Jobs\Notifications\SendTransactionalNotificationEmailJob;
use App\Models\Core\Notifications\Notification;
use App\Models\Core\Segments\UserSegment;
use App\Models\Core\User\User;
use App\Services\Notifications\NotificationListingService;
use App\Services\Segments\SegmentResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// V2: Staff creates global or targeted notifications with optional email broadcast,
// and acts on behalf of a professional to clear stuck banners (NOTIF-1).
// OV-A: audience targeting — all (one global row) / segment / selected (per-user
// rows, bulk-inserted) + the `critical` delivery flag (OV-H's email dispatcher
// reads it; tonight these fan-outs write in-app rows only).
class StaffNotificationController extends ApiController
{
    public function __construct(
        private readonly NotificationListingService $listing,
        private readonly SegmentResolver $resolver,
    ) {}

    /** POST /staff/notifications */
    public function store(StaffStoreNotificationRequest $request): JsonResponse
    {
        // Defence-in-depth: the route already sits in the staff.admin group, but
        // gate the broadcast power at the policy layer too (admin only), matching
        // the other staff write controllers. staff.admin middleware could be
        // removed/misconfigured; this keeps the authorization co-located.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', Notification::class);

        $data = $request->validated();

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

        // Resolve the audience → per-user fan-out list, or null = single global row.
        // Precedence: explicit audience block > legacy user_id > global.
        $audience = $data['audience'] ?? null;
        $fanOutUserIds = null;

        if (is_array($audience) && $audience['type'] === 'segment') {
            $segment = UserSegment::query()->findOrFail($audience['segment_id']);
            $fanOutUserIds = $this->resolver->userIds($segment);
        } elseif (is_array($audience) && $audience['type'] === 'selected') {
            $fanOutUserIds = array_values(array_unique($audience['user_ids']));
        } elseif (! is_array($audience) && ! empty($data['user_id'])) {
            $fanOutUserIds = [$data['user_id']];
        }
        // audience.type === 'all' (or nothing targeted) → global row below.

        $row = collect($data)
            ->only(['type', 'category', 'title', 'body', 'cta_url', 'primary_action_label', 'secondary_action_label', 'secondary_action_url', 'severity', 'starts_at', 'ends_at'])
            ->merge(['critical' => (bool) ($data['critical'] ?? false), 'category' => $data['category'] ?? null])
            ->all();

        if ($fanOutUserIds === null) {
            $notification = Notification::query()->create([...$row, 'user_id' => null]);
            $createdCount = 1;
            $audienceSize = null; // global — reaches everyone
        } elseif (count($fanOutUserIds) === 1) {
            $notification = Notification::query()->create([...$row, 'user_id' => $fanOutUserIds[0]]);
            $createdCount = 1;
            $audienceSize = 1;
        } else {
            $notification = $this->fanOut($row, $fanOutUserIds);
            $createdCount = count($fanOutUserIds);
            $audienceSize = count($fanOutUserIds);
        }

        $sendEmail = (bool) ($data['send_email'] ?? false);
        $emailListKey = $data['email_list_key'] ?? 'sidest_updates';

        if ($sendEmail && $createdCount === 1) {
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
        // Multi-row fan-outs deliberately skip the legacy send_email paths — the
        // critical flag + OV-H dispatcher own email for those.

        return $this->success([
            'notification' => (new NotificationListingResource($notification))->resolve(),
            'created_count' => $createdCount,
            'audience_size' => $audienceSize,
        ], 201);
    }

    /**
     * Bulk-insert one per-user row per audience member (chunked); returns the
     * first created model for the response payload.
     */
    private function fanOut(array $row, array $userIds): Notification
    {
        $now = now();
        $firstId = null;

        foreach (array_chunk($userIds, 500) as $chunk) {
            $rows = [];
            foreach ($chunk as $userId) {
                $id = (string) Str::uuid();
                $firstId ??= $id;
                $rows[] = [
                    ...$row,
                    'id' => $id,
                    'user_id' => $userId,
                    'starts_at' => $row['starts_at'] ?? null,
                    'ends_at' => $row['ends_at'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            Notification::query()->insert($rows);
        }

        return Notification::query()->findOrFail($firstId);
    }

    /**
     * GET /staff/notifications — recent notifications for the staff dashboard
     * composer/history view. ?global=1 → global rows only; ?critical=1 → the
     * escalated ones; targeted fan-out rows appear individually.
     */
    public function index(Request $request): JsonResponse
    {
        $limit = max(1, min((int) $request->query('limit', 50), 200));

        $query = Notification::query()->orderByDesc('created_at')->limit($limit);

        if (filter_var($request->query('global', false), FILTER_VALIDATE_BOOLEAN)) {
            $query->whereNull('user_id');
        }
        if (filter_var($request->query('critical', false), FILTER_VALIDATE_BOOLEAN)) {
            $query->where('critical', true);
        }

        return $this->success([
            'notifications' => $query->get()
                ->map(fn (Notification $n) => (new NotificationListingResource($n))->resolve())
                ->values(),
        ]);
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
