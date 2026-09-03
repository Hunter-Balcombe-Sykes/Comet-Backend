<?php

namespace App\Services\PreAccount;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\ConnectionDisplayName;

// The settle email's proof that the thing worked: which platforms actually
// connected. Names only, no counts (owner, 2026-09-03) -- a count is a
// promise about completeness the mirror queue cannot keep.
class ConnectedPlatformNames
{
    /** @return list<string> Brand labels, de-duplicated, alphabetical. Empty when nothing connected. */
    public function for(User $user): array
    {
        // Through Eloquent, not the query builder: SoftDeletes is what keeps a
        // platform the owner has since disconnected out of "already connected".
        $labels = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->pluck('surface_key')
            ->map(fn ($key) => ConnectionDisplayName::brandLabelFor((string) $key))
            // A surface the compiled catalog does not know yields null; a blank
            // bullet in a welcome email is worse than a shorter list.
            ->filter(fn (?string $label) => is_string($label) && $label !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();

        return array_map('strval', $labels);
    }
}
