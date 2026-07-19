<?php

namespace App\Services\User;

use App\Models\Core\User\User;

// Extracted from UserBootstrapService::emailIsClaimedByAnotherAuthUser so the
// claim flow shares the exact same case-insensitive reuse check.
class EmailReuseGuard
{
    /**
     * Queries the DB live — deliberately impure. ClaimSiteService calls this
     * twice in the same request (pre-check, then a post-23505 re-check inside
     * the catch block) to settle a genuine insert race; without this tag
     * PHPStan assumes the second call must return the same result as the
     * first and flags the re-check as always-false dead code.
     *
     * @phpstan-impure
     */
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
