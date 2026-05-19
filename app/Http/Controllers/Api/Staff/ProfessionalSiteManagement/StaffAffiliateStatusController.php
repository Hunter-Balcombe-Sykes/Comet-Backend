<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// V2: Staff admin suspends or reactivates an affiliate. Verifies brand ownership before
// touching the affiliate's record — prevents accidentally acting on unrelated professionals.
class StaffAffiliateStatusController extends ApiController
{
    /**
     * PATCH /api/staff/professionals/{professional}/affiliates/{affiliate}/status
     *
     * Body: { "status": "active" | "suspended" }
     */
    public function update(Request $request, Professional $professional, Professional $affiliate): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:active,suspended'],
        ]);

        $linked = DB::table('brand.brand_partner_links')
            ->where('brand_professional_id', $professional->id)
            ->where('affiliate_professional_id', $affiliate->id)
            ->whereNull('deleted_at')
            ->exists();

        if (! $linked) {
            return $this->error('Affiliate not found for this brand.', 404);
        }

        $affiliate->status = $data['status'];
        $affiliate->save();

        return $this->success(['professional' => $affiliate->fresh()]);
    }
}
