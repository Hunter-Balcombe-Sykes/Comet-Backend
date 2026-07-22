<?php

namespace App\Http\Controllers\Api\User\Notifications;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Models\Core\Notifications\Notification;
use App\Services\Notifications\NotificationListingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2: In-app notification listing, mark-as-read, and dismiss for the authenticated professional.
class NotificationController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function __construct(private readonly NotificationListingService $listing) {}

    /**
     * GET /me/notifications
     * Returns notifications targeted to the current pro + broadcasts.
     * Read/dismiss state is stored per-user in notifications.notification_receipts.
     *
     * Pagination divergence (#API-2): this endpoint uses `?limit=` +
     * `has_more` instead of the project-standard `paginate()` shape. The
     * dashboard bell polls every few seconds and always renders from the top
     * of the list — total-page metadata adds no value for that UI, and
     * recomputing COUNT(*) on every poll is wasted DB work. Clients that
     * need to scroll past the first page should bump `?limit=`; deep paging
     * is not a supported flow for in-app notifications.
     */
    public function index(Request $request): JsonResponse
    {
        $pro = $this->currentUser($request);

        $limit = (int) $request->query('limit', config('partna.limits.pagination.notifications_limit_default', 50));
        $limit = max(1, min($limit, (int) config('partna.limits.pagination.notifications_limit_max', 200)));

        $includeDismissed = filter_var($request->query('include_dismissed', false), FILTER_VALIDATE_BOOLEAN);

        return $this->success($this->listing->index($pro->id, $limit, $includeDismissed));
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        $pro = $this->currentUser($request);
        $this->authorizeForUser($pro, 'view', $notification);
        $this->listing->assertActive($notification);

        $this->listing->markRead($notification, $pro->id);

        return $this->success(['ok' => true]);
    }

    public function dismiss(Request $request, Notification $notification): JsonResponse
    {
        $pro = $this->currentUser($request);
        $this->authorizeForUser($pro, 'view', $notification);
        $this->listing->assertActive($notification);

        $this->listing->dismiss($notification, $pro->id);

        return $this->success(['ok' => true]);
    }
}
