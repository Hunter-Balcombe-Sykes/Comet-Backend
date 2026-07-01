<?php

namespace App\Services\User\Visibility\Rules;

use App\Models\Core\Site\Block;
use App\Models\Core\User\User;
use App\Services\User\Visibility\SectionVisibilityContract;
use Illuminate\Support\Facades\DB;

// Public contact info goes live once at least one of the opt-in fields
// (public_contact_number / public_contact_email) is non-empty.
class PublicContactVisibility implements SectionVisibilityContract
{
    public function blockType(): string
    {
        return 'public_contact';
    }

    public function contextSubqueries(string $userId, string $siteId): array
    {
        return [
            'has_public_contact' => User::query()
                ->select(DB::raw('1'))
                ->where('id', $userId)
                ->whereNull('deleted_at')
                ->where(function ($q) {
                    $q->whereRaw("COALESCE(public_contact_number, '') <> ''")
                        ->orWhereRaw("COALESCE(public_contact_email, '') <> ''");
                })
                ->getQuery(),
        ];
    }

    public function resolve(Block $block, array $context, ?array $pendingSettings = null): array
    {
        return ($context['has_public_contact'] ?? false)
            ? [true, null]
            : [false, 'Public contact info requires a phone number or email before it can go live.'];
    }
}
