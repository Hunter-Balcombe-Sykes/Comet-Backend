<?php

namespace App\Services\User;

use App\Models\Core\User\User;

// Extracted from UserBootstrapService::emailIsClaimedByAnotherAuthUser so the
// claim flow shares the exact same case-insensitive reuse check.
class EmailReuseGuard
{
    public function isClaimedByAnotherAuthUser(string $email, string $uid): bool
    {
        $emailLc = strtolower(trim($email));
        if ($emailLc === '') {
            return false;
        }

        return User::query()
            ->whereRaw('lower(primary_email) = ?', [$emailLc])
            ->where('auth_user_id', '!=', $uid)
            ->exists();
    }
}
