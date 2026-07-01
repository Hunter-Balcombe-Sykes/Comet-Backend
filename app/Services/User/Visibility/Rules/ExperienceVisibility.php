<?php

namespace App\Services\User\Visibility\Rules;

use App\Models\Core\Site\Block;
use App\Models\Core\User\UserExperience;
use App\Services\User\Visibility\SectionVisibilityContract;
use Illuminate\Support\Facades\DB;

// Experience is publishable once there is at least one entry with a non-empty
// role. PR3 (FOUND-5) moved experience from core.users.about JSONB to the
// core.user_experience child table — this reads the table, not the JSONB.
class ExperienceVisibility implements SectionVisibilityContract
{
    public function blockType(): string
    {
        return 'experience';
    }

    public function contextSubqueries(string $userId, string $siteId): array
    {
        return [
            'has_experience' => UserExperience::query()
                ->select(DB::raw('1'))
                ->where('user_id', $userId)
                ->whereNotNull('role')
                ->where('role', '<>', '')
                ->getQuery(),
        ];
    }

    public function resolve(Block $block, array $context, ?array $pendingSettings = null): array
    {
        return ($context['has_experience'] ?? false)
            ? [true, null]
            : [false, 'Experience section requires at least 1 entry with a role.'];
    }
}
