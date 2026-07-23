<?php

namespace App\Services\Segments;

use App\Models\Core\Segments\UserSegment;
use App\Models\Core\User\User;
use App\Services\Segments\Criteria\SegmentCriteria;
use App\Services\Segments\Criteria\SegmentCriterion;
use Illuminate\Database\Eloquent\Builder;

/**
 * Turns a UserSegment into a live user-id set: dynamic filters evaluated as one
 * query on core.users, UNIONed with the segment's manual members.
 *
 * The available filter keys and their query semantics live in
 * App\Services\Segments\Criteria\SegmentCriteria — one class per criterion.
 *
 * Filter semantics (filters JSONB — see UserSegment):
 *   - missing/null key           → unconstrained
 *   - keys AND-combine
 *   - ZERO dynamic keys set      → dynamic set is EMPTY (manual-members-only
 *     segment). Deliberate: prevents `{}` accidentally meaning "all users".
 *   - soft-deleted users are always excluded (manual members included).
 */
class SegmentResolver
{
    /** @return list<string> distinct user ids in the segment */
    public function userIds(UserSegment $segment): array
    {
        $ids = [];

        if ($dynamic = $this->dynamicQuery($segment)) {
            $ids = $dynamic->pluck('id')->all();
        }

        if ($this->includesManualMembers($segment)) {
            $manual = User::query()
                ->whereIn('id', $segment->members()->pluck('user_id'))
                ->pluck('id')
                ->all();

            $ids = array_merge($ids, $manual);
        }

        return array_values(array_unique($ids));
    }

    public function count(UserSegment $segment): int
    {
        return count($this->userIds($segment));
    }

    /**
     * Query for the DYNAMIC part of the segment (null when no dynamic filter is
     * set). Callers wanting the full set must still union manual members —
     * use userIds() unless you specifically need a Builder.
     */
    public function dynamicQuery(UserSegment $segment): ?Builder
    {
        $filters = is_array($segment->filters) ? $segment->filters : [];

        $active = array_values(array_filter(
            SegmentCriteria::all(),
            fn (SegmentCriterion $criterion) => $criterion->isActive($filters)
        ));

        if ($active === []) {
            return null;
        }

        $query = User::query()->select('id'); // SoftDeletes global scope excludes deleted rows

        foreach ($active as $criterion) {
            $criterion->apply($query, $filters);
        }

        return $query;
    }

    private function includesManualMembers(UserSegment $segment): bool
    {
        $filters = is_array($segment->filters) ? $segment->filters : [];

        return ($filters['include_manual_members'] ?? true) !== false;
    }
}
