<?php

namespace App\Http\Resources\Staff;

use App\Http\Resources\ApiResource;
use App\Models\Core\Staff\PartnaStaff;
use Illuminate\Http\Request;

// Minimal allowlist for the staff identity. Exposes only identity fields —
// role-escalation paths (phone, auth_user_id) stay hidden per PartnaStaff::$hidden.
//
// Served from TWO places since 2026-09-01, and the second one is the point:
//   - GET /staff/me   (aal2-gated)
//   - GET /me         (aal1) — the dashboard's boot call
//
// Staff-only is the intended shape now. A staff account is not a professional
// who also has a badge: it has no core.users row, no handle and no sitepage, so
// /me has nothing else to answer with. Its whole session identity IS this
// resource, which is why the boot envelope carries the record and not just the
// `is_staff` boolean UserDashboardResource exposes for the hybrid.
//
// That does widen `role` from aal2 to aal1, deliberately. It is the caller's OWN
// role, so it discloses nothing they cannot already read by completing MFA, and
// it buys nothing: EnsurePartnaStaff + require.aal2 gate every staff route
// server-side, so this value only decides which shell the client renders. What
// it prevents is a staff-only session booting into a professional dashboard it
// has no data for — which is the state that pushed staff into minting a
// sitepage in the first place.
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
