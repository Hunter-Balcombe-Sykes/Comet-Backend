<?php

namespace App\Http\Controllers\Api\Staff\Feedback;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\NormalizesPerPage;
use App\Http\Controllers\Concerns\ReturnsPaginatedResponse;
use App\Http\Requests\Api\Staff\Feedback\StaffFeedbackUpdateRequest;
use App\Http\Resources\Staff\StaffFeedbackResource;
use App\Models\Core\Feedback;
use App\Services\Feedback\FeedbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * OV-D: staff triage surface for all users' feedback — consumed by the staff
 * dashboard's Feedback page (OV-A-FE). List + status write for any staff role
 * (FeedbackPolicy::staffView / staffTriage on the core.partna_staff role);
 * junk removal is admin-only (staffDelete + the staff.admin route group).
 *
 * No detail/show route. StaffFeedbackResource already returns every column
 * (message, reply_email, tags, internal_notes, ip_hash, …) so the list
 * response is self-sufficient for the staff UI.
 */
class StaffFeedbackController extends ApiController
{
    use NormalizesPerPage;
    use ReturnsPaginatedResponse;

    private const VALID_TYPES = ['error', 'good', 'bad_ui', 'idea'];

    public function __construct(private readonly FeedbackService $service) {}

    /** GET /staff/feedback?type=&area=&from=&to=&per_page= */
    public function index(Request $request): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffView', Feedback::class);

        $query = Feedback::query()
            ->with('user:id,handle,display_name,primary_email')
            ->orderByDesc('created_at');

        // Unrecognised type values are silently ignored (no filter applied)
        // rather than a 422 — matches StaffEarlyAccessController's `status`
        // filter convention.
        $type = $request->query('type');
        if (is_string($type) && in_array($type, self::VALID_TYPES, true)) {
            $query->where('type', $type);
        }

        $area = $request->query('area');
        if (is_string($area) && $area !== '') {
            $query->where('area', $area);
        }

        try {
            $from = $request->query('from');
            if (is_string($from) && $from !== '') {
                $query->where('created_at', '>=', Carbon::parse($from)->startOfDay());
            }

            $to = $request->query('to');
            if (is_string($to) && $to !== '') {
                $query->where('created_at', '<=', Carbon::parse($to)->endOfDay());
            }
        } catch (Throwable) {
            return $this->error('Invalid date range. Use YYYY-MM-DD for from/to.', 422);
        }

        $page = $query->paginate($this->normalizePerPage(
            $request,
            (int) config('partna.staff.pagination.per_page', 25),
            (int) config('partna.staff.pagination.per_page_max', 100),
        ));
        $page->through(fn (Feedback $feedback) => StaffFeedbackResource::make($feedback)->resolve());

        return $this->success($this->paginatedResponse($page, 'feedback'));
    }

    /** PATCH /staff/feedback/{feedback} — set triage status (support or admin). */
    public function update(StaffFeedbackUpdateRequest $request, Feedback $feedback): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffTriage', $feedback);

        $feedback = $this->service->updateStatus($feedback, $request->validated()['status']);

        return $this->success([
            'feedback' => StaffFeedbackResource::make(
                $feedback->load('user:id,handle,display_name,primary_email')
            )->resolve(),
        ]);
    }
}
