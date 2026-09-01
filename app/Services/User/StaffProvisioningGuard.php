<?php

namespace App\Services\User;

use App\Models\Core\Staff\PartnaStaff;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Refuses to mint a core.users row for an auth user that holds a
 * core.partna_staff row.
 *
 * The platform's own admin acquired a professional profile and a published
 * sitepage he never asked for, because a staff-only session had no way to boot
 * the dashboard except to become a professional (see
 * LoadCurrentUser::handleMissingProfile). That boot hole is closed, but the
 * closing alone is a policy the frontend has to keep honouring. This is the
 * same rule expressed where it cannot be bypassed: whatever lane later decides
 * to bind an auth user to a professional profile — a resumed signup, a claim,
 * an internal reuse of the bootstrap create branch — asks here first.
 *
 * It guards the CREATE/BIND direction only. An existing hybrid (a professional
 * who was later made staff) keeps refreshing its profile normally: the row
 * already exists and revoking it is a data decision, not a request-time one.
 */
class StaffProvisioningGuard
{
    /** Thrown message; callers map it to their own HTTP shape. */
    public const REJECTION = 'STAFF_ACCOUNT_NO_PROFILE';

    /**
     * @param  string  $lane  Short name of the calling path, for the log line.
     *
     * @throws RuntimeException STAFF_ACCOUNT_NO_PROFILE
     */
    public function assertMayHoldProfile(string $authUserId, string $lane): void
    {
        if ($authUserId === '') {
            return;
        }

        $staff = PartnaStaff::query()
            ->where('auth_user_id', $authUserId)
            ->first();

        if (! $staff) {
            return;
        }

        // warning, not info: nothing should ever reach this. A hit means a lane
        // tried to hand a staff account a sitepage, and the interesting question
        // is which lane — so the skip has to be visible in Nightwatch, not just
        // inferable from the 409 the caller returns.
        Log::warning('Refused to provision a professional profile for a staff account', [
            'auth_user_id' => $authUserId,
            'staff_id' => (string) $staff->id,
            'staff_role' => (string) $staff->role,
            'lane' => $lane,
        ]);

        throw new RuntimeException(self::REJECTION);
    }
}
