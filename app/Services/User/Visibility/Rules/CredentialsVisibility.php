<?php

namespace App\Services\User\Visibility\Rules;

use App\Models\Core\Site\Block;
use App\Models\Core\User\UserCredential;
use App\Services\User\Visibility\SectionVisibilityContract;
use Illuminate\Support\Facades\DB;

// Credentials is publishable once there is at least one credential with a
// non-empty title. PR3 (FOUND-5) moved credentials from core.users.about JSONB to
// the core.user_credentials child table — this reads the table, not the JSONB.
class CredentialsVisibility implements SectionVisibilityContract
{
    public function blockType(): string
    {
        return 'credentials';
    }

    public function contextSubqueries(string $userId, string $siteId): array
    {
        return [
            'has_credential' => UserCredential::query()
                ->select(DB::raw('1'))
                ->where('user_id', $userId)
                ->whereNotNull('title')
                ->where('title', '<>', '')
                ->getQuery(),
        ];
    }

    public function resolve(Block $block, array $context, ?array $pendingSettings = null): array
    {
        return ($context['has_credential'] ?? false)
            ? [true, null]
            : [false, 'Credentials section requires at least 1 credential with a title.'];
    }
}
