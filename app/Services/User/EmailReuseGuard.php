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
            // An UNBOUND row still owns its address. `auth_user_id != $uid`
            // alone can never match one: in SQL `NULL != 'uid'` is NULL, not
            // true, so a row holding this email with auth_user_id IS NULL fell
            // straight through the guard. That state is reachable whenever the
            // Supabase auth user is deleted out from under a claimed row (the
            // FK nulls the link but leaves status/primary_email), and it made
            // the claim proceed into `users_email_unique` — a 23505 that
            // ClaimSiteService's own post-23505 re-check then ALSO failed to
            // recognise, so it rethrew and the signup's Finish step answered a
            // bare 500 "An error occurred" (2026-09-03).
            ->where(fn ($query) => $query
                ->whereNull('auth_user_id')
                ->orWhere('auth_user_id', '!=', $uid))
            ->exists();
    }
}
