<?php

namespace App\Http\Controllers\Api\User\Feedback;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\NormalizesPerPage;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Controllers\Concerns\ReturnsPaginatedResponse;
use App\Http\Requests\Api\User\Feedback\SubmitFeedbackRequest;
use App\Http\Resources\FeedbackResource;
use App\Models\Core\Feedback;
use App\Services\Feedback\FeedbackService;
use Illuminate\Http\Request;

/**
 * User-facing endpoints for submitting and reviewing own feedback.
 *
 * Routes use the `professional.api` middleware group (JWT + current.pro
 * loader) for consistency with every other authenticated dashboard endpoint.
 * The POST is exempt from EnforcePendingDeletionReadOnly at the route level
 * so accounts in their grace period can still submit feedback (they may be
 * giving feedback about the deletion flow itself).
 */
class FeedbackController extends ApiController
{
    use NormalizesPerPage;
    use ResolveCurrentUser;
    use ReturnsPaginatedResponse;

    public function __construct(
        private readonly FeedbackService $service,
    ) {}

    public function store(SubmitFeedbackRequest $request)
    {
        $pro = $this->currentUser($request);

        $skeleton = new Feedback(['user_id' => $pro->id]);
        $this->authorizeForUser($pro, 'create', $skeleton);

        $feedback = $this->service->submit($pro, $request->validated(), $request);

        return $this->success(['feedback' => new FeedbackResource($feedback)], 201);
    }

    public function index(Request $request)
    {
        $pro = $this->currentUser($request);
        $this->authorizeForUser($pro, 'viewAny', Feedback::class);

        $perPage = $this->normalizePerPage($request, 20, 100);

        $paginator = Feedback::query()
            ->where('user_id', $pro->id)
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->appends($request->query());

        $payload = $this->paginatedResponse($paginator, 'feedback');
        $payload['feedback'] = FeedbackResource::collection($paginator->items())->resolve();

        return $this->success($payload);
    }

    public function show(Request $request, Feedback $feedback)
    {
        $pro = $this->currentUser($request);
        $this->authorizeForUser($pro, 'view', $feedback);

        // Route-model binding already applies the SoftDeletes default scope —
        // soft-deleted rows return 404 before this method runs.
        return $this->success(['feedback' => new FeedbackResource($feedback)]);
    }
}
