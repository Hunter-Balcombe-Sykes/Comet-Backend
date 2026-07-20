<?php

namespace App\Http\Resources\Staff;

use App\Http\Resources\ApiResource;
use App\Models\Core\Staff\PartnaStaff;
use Illuminate\Http\Request;

// Minimal allowlist for the staff /me endpoint. Exposes only identity fields —
// role-escalation paths (phone, auth_user_id) stay hidden per PartnaStaff::$hidden.
/**
 * @mixin PartnaStaff
 */
class PartnaStaffResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'primary_email' => $this->primary_email,
            'role' => $this->role,
        ];
    }
}
