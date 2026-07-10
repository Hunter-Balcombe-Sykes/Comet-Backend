<?php

namespace App\Services\Segments;

use App\Models\Core\Segments\UserSegment;
use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Turns a UserSegment into a live user-id set: dynamic filters evaluated as one
 * query on core.users, UNIONed with the segment's manual members.
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
    private const DYNAMIC_KEYS = ['account_type', 'sector', 'created_from', 'created_to', 'has_integration', 'early_access'];

    /** @return list<string> distinct user ids in the segment */
    public function userIds(UserSegment $segment): array
    {
        $ids = [];

        if ($dynamic = $this->dynamicQuery($segment)) {
            $ids = $dynamic->pluck('id')->map(fn ($id) => (string) $id)->all();
        }

        if ($this->includesManualMembers($segment)) {
            $manual = User::query()
                ->whereIn('id', $segment->members()->pluck('user_id'))
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
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

        $active = array_filter(
            self::DYNAMIC_KEYS,
            fn (string $key) => array_key_exists($key, $filters) && $filters[$key] !== null && $filters[$key] !== ''
        );

        if ($active === []) {
            return null;
        }

        $query = User::query()->select('id'); // SoftDeletes global scope excludes deleted rows

        if (in_array('account_type', $active, true)) {
            $query->where('account_type', (string) $filters['account_type']);
        }

        if (in_array('sector', $active, true)) {
            $sectors = array_values(array_filter(array_map(
                fn ($s) => is_string($s) ? trim($s) : null,
                is_array($filters['sector']) ? $filters['sector'] : [$filters['sector']]
            )));
            if ($sectors !== []) {
                $query->whereIn('sector', $sectors);
            }
        }

        if (in_array('created_from', $active, true)) {
            $query->where('created_at', '>=', Carbon::parse((string) $filters['created_from'])->startOfDay());
        }

        if (in_array('created_to', $active, true)) {
            $query->where('created_at', '<=', Carbon::parse((string) $filters['created_to'])->endOfDay());
        }

        if (in_array('has_integration', $active, true)) {
            // true → any active platform connection; string → that platform.
            $platform = is_string($filters['has_integration']) ? $filters['has_integration'] : null;
            $query->whereHas('integrationConnections', function ($q) use ($platform): void {
                $q->where('is_active', true);
                if ($platform !== null) {
                    $q->where('platform', $platform);
                }
            });
        }

        if (in_array('early_access', $active, true)) {
            // Membership in the early-access programme, keyed by primary email.
            $exists = 'EXISTS (SELECT 1 FROM core.early_access_signups eas WHERE eas.email_lc = LOWER(core.users.primary_email))';
            (bool) $filters['early_access']
                ? $query->whereRaw($exists)
                : $query->whereRaw("NOT {$exists}");
        }

        return $query;
    }

    private function includesManualMembers(UserSegment $segment): bool
    {
        $filters = is_array($segment->filters) ? $segment->filters : [];

        return ($filters['include_manual_members'] ?? true) !== false;
    }
}
