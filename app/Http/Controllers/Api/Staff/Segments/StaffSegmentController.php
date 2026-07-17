<?php

namespace App\Http\Controllers\Api\Staff\Segments;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\NormalizesPerPage;
use App\Http\Controllers\Concerns\ReturnsPaginatedResponse;
use App\Http\Requests\Api\Staff\Segments\SegmentMembersRequest;
use App\Http\Requests\Api\Staff\Segments\StoreSegmentRequest;
use App\Http\Requests\Api\Staff\Segments\UpdateSegmentRequest;
use App\Http\Resources\Staff\UserSegmentResource;
use App\Jobs\Platforms\ReconcilePlatformTakedownJob;
use App\Models\Core\FeatureAvailabilityRule;
use App\Models\Core\Segments\UserSegment;
use App\Models\Core\Segments\UserSegmentMember;
use App\Models\Core\User\User;
use App\Services\FeatureAvailability\FeatureAvailability;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Segments\SegmentResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// OV-A: staff CRUD for user segments + manual member management + resolved
// user preview. Routes live in the staff.admin group; UserSegmentPolicy adds
// the role gate (support=view / admin=manage) as defence-in-depth.
class StaffSegmentController extends ApiController
{
    use NormalizesPerPage;
    use ReturnsPaginatedResponse;

    public function __construct(private readonly SegmentResolver $resolver) {}

    /** GET /staff/segments */
    public function index(Request $request): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffView', UserSegment::class);

        $segments = UserSegment::query()
            ->withCount('members')
            ->orderBy('name')
            ->paginate($this->normalizePerPage($request, (int) config('partna.staff.pagination.per_page', 25), (int) config('partna.staff.pagination.per_page_max', 100)));

        $segments->through(fn (UserSegment $segment) => UserSegmentResource::make($segment)->resolve());

        return $this->success($this->paginatedResponse($segments, 'segments'));
    }

    /** POST /staff/segments */
    public function store(StoreSegmentRequest $request): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', UserSegment::class);

        $data = $request->validated();

        $segment = UserSegment::query()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'filters' => $data['filters'] ?? [],
            'created_by' => $staff?->id,
        ]);

        FeatureAvailability::flush();
        $segment->loadCount('members');
        $segment->resolved_count = $this->resolver->count($segment);

        return $this->success(['segment' => UserSegmentResource::make($segment)->resolve()], 201);
    }

    /** GET /staff/segments/{segment} — includes live resolved_count. */
    public function show(Request $request, UserSegment $segment): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffView', $segment);

        $segment->loadCount('members');
        $segment->resolved_count = $this->resolver->count($segment);

        return $this->success(['segment' => UserSegmentResource::make($segment)->resolve()]);
    }

    /** PATCH /staff/segments/{segment} */
    public function update(UpdateSegmentRequest $request, UserSegment $segment): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $segment);

        $data = $request->validated();

        // Replace filters wholesale when present — merging would make it
        // impossible to clear a filter key from the dashboard.
        $segment->fill([
            'name' => $data['name'] ?? $segment->name,
            'description' => array_key_exists('description', $data) ? $data['description'] : $segment->description,
            'filters' => array_key_exists('filters', $data) ? ($data['filters'] ?? []) : $segment->filters,
        ])->save();

        // Availability rules scope by segment — membership rules may have changed.
        FeatureAvailability::flush();

        $segment->loadCount('members');
        $segment->resolved_count = $this->resolver->count($segment);

        return $this->success(['segment' => UserSegmentResource::make($segment)->resolve()]);
    }

    /** DELETE /staff/segments/{segment} — cascades members + availability rules. */
    public function destroy(Request $request, UserSegment $segment): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $segment);

        $segment->delete();
        FeatureAvailability::flush();

        return response()->json(null, 204);
    }

    /** POST /staff/segments/{segment}/members — add manual members. */
    public function addMembers(SegmentMembersRequest $request, UserSegment $segment): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $segment);

        $added = 0;
        foreach ($request->validated('user_ids') as $userId) {
            $member = UserSegmentMember::query()->firstOrCreate(
                ['segment_id' => $segment->id, 'user_id' => $userId],
                ['added_by' => $staff?->id],
            );
            if ($member->wasRecentlyCreated) {
                $added++;
            }
        }

        FeatureAvailability::flush();

        // OV-A: new members of a segment that already disables an integration inherit
        // the takedown. Re-run the segment takedown (idempotent — only still-active
        // rows flip). Only when members were actually added.
        if ($added > 0) {
            $registry = app(PlatformRegistry::class);
            FeatureAvailabilityRule::query()
                ->where('segment_id', $segment->id)
                ->where('mode', FeatureAvailabilityRule::MODE_DISABLED)
                ->get()
                ->each(function (FeatureAvailabilityRule $rule) use ($registry): void {
                    $platform = $rule->integrationPlatform();
                    if ($platform !== null && $registry->has($platform)) {
                        ReconcilePlatformTakedownJob::dispatch($platform, $rule->segment_id)->afterCommit();
                    }
                });
        }

        return $this->success([
            'added_count' => $added,
            'manual_member_count' => $segment->members()->count(),
        ]);
    }

    /** DELETE /staff/segments/{segment}/members — remove manual members. */
    public function removeMembers(SegmentMembersRequest $request, UserSegment $segment): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $segment);

        $removed = UserSegmentMember::query()
            ->where('segment_id', $segment->id)
            ->whereIn('user_id', $request->validated('user_ids'))
            ->delete();

        FeatureAvailability::flush();

        return $this->success([
            'removed_count' => (int) $removed,
            'manual_member_count' => $segment->members()->count(),
        ]);
    }

    /** GET /staff/segments/{segment}/users — resolved membership preview. */
    public function users(Request $request, UserSegment $segment): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffView', $segment);

        $perPage = $this->normalizePerPage($request, (int) config('partna.staff.pagination.per_page', 25), (int) config('partna.staff.pagination.per_page_max', 100));
        $ids = $this->resolver->userIds($segment);

        $page = User::query()
            ->whereIn('id', $ids)
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $users = collect($page->items())->map(fn (User $user) => [
            'id' => $user->id,
            'handle' => $user->handle,
            'display_name' => $user->display_name,
            'account_type' => $user->account_type?->value,
            'sector' => $user->sector,
            'created_at' => $user->created_at?->toIso8601String(),
        ]);

        $payload = $this->paginatedResponse($page, 'users', ['resolved_count' => count($ids)]);
        $payload['users'] = $users;

        return $this->success($payload);
    }
}
