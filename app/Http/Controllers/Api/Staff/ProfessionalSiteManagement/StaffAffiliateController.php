<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// V2: Staff views affiliates linked to a brand. Read-only — disconnect is a brand-level action.
class StaffAffiliateController extends ApiController
{
    /**
     * GET /api/staff/professionals/{professional}/affiliates
     *
     * @return JsonResponse{ affiliates: array<int, array{id: string, full_name: string, handle: string, status: string, email: string|null, is_primary: bool, custom_photos_enabled: bool, connected_at: string|null}> }
     */
    public function index(Request $request, Professional $professional): JsonResponse
    {
        $rows = DB::table('brand.brand_partner_links as bpl')
            ->join('core.professionals as p', 'p.id', '=', 'bpl.affiliate_professional_id')
            ->where('bpl.brand_professional_id', $professional->id)
            ->whereNull('bpl.deleted_at')
            ->whereNull('p.deleted_at')
            ->orderByDesc('bpl.updated_at')
            ->get([
                'p.id',
                'p.first_name',
                'p.last_name',
                'p.display_name',
                'p.handle',
                'p.professional_type',
                'p.status',
                'p.primary_email',
                'p.public_contact_email',
                'p.phone',
                'p.public_contact_number',
                'bpl.slot',
                'bpl.custom_photos_enabled',
                'bpl.updated_at as connected_at',
            ]);

        $affiliates = $rows->map(function (object $row): array {
            $fullName = trim(implode(' ', array_filter([$row->first_name, $row->last_name])));

            return [
                'id' => $row->id,
                'full_name' => $fullName ?: ($row->display_name ?? $row->handle ?? 'Unknown'),
                'handle' => $row->handle,
                'professional_type' => $row->professional_type,
                'status' => $row->status,
                'email' => $row->primary_email ?? $row->public_contact_email,
                'phone' => $row->phone ?? $row->public_contact_number,
                'is_primary' => (int) $row->slot === 0,
                'custom_photos_enabled' => (bool) $row->custom_photos_enabled,
                'connected_at' => $row->connected_at,
            ];
        })->values()->all();

        return $this->success(['affiliates' => $affiliates]);
    }
}
