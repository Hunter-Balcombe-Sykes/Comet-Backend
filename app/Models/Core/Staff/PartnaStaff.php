<?php

namespace App\Models\Core\Staff;

use App\Models\BaseModel;
use Database\Factories\Core\Staff\PartnaStaffFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $auth_user_id
 * @property string $role One of ROLE_SUPPORT|ROLE_ADMIN — DB-enforced by the
 *                        partna_staff_role_check CHECK constraint (@see supabase/migrations/20260726000000_baseline_pilot.sql).
 * @property string|null $primary_email
 * @property string|null $name
 * @property string|null $phone
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */

// V2: Internal Partna staff member. Linked to a Supabase auth user; role-based access for admin operations.
//
// Mass-assignment posture (SEC-1):
//   `role` is intentionally NOT $fillable. It's an admin-only state transition,
//   not a user-settable attribute. Use promoteToAdmin() / demoteToSupport() —
//   never $staff->update(['role' => ...]) from a request-derived array.
//
// Serialization posture (SEC-2):
//   primary_email, name, phone are hidden so toArray() / toJson() / log dumps
//   never broadcast staff identity. Any staff-facing endpoint that legitimately
//   needs these fields must expose them via a dedicated StaffResource.
//
// ROLE_* constants are enforced at the DB level by partna_staff_role_check.
// @see supabase/migrations/20260726000000_baseline_pilot.sql
class PartnaStaff extends BaseModel
{
    use HasFactory, HasUuids;

    const ROLE_SUPPORT = 'support';

    const ROLE_ADMIN = 'admin';

    protected $table = 'core.partna_staff';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = [
        'auth_user_id',
        'primary_email',
        'name',
        'phone',
    ];

    protected $fillable = [
        'primary_email',
        'name',
        'phone',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * Promote this staff member to the admin role.
     *
     * This is the sole sanctioned path for setting role = admin. Callers must
     * authorize the actor via PartnaStaffPolicy::update before invoking, and
     * must record the transition in audit.staff_audit_log.
     */
    public function promoteToAdmin(): void
    {
        $this->role = self::ROLE_ADMIN;
        $this->save();
    }

    /**
     * Demote this staff member to the support role.
     *
     * Same gating discipline as promoteToAdmin: caller authorizes + audits.
     */
    public function demoteToSupport(): void
    {
        $this->role = self::ROLE_SUPPORT;
        $this->save();
    }

    protected static function newFactory(): PartnaStaffFactory
    {
        return PartnaStaffFactory::new();
    }
}
