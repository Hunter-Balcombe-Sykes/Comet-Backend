<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Http\Resources\Moderation\CaseResource;
use App\Models\Moderation\ModerationCase;
use Illuminate\Http\Request;

/**
 * Staff moderation queue management.
 * All routes require AAL2 (enforced by require.aal2 middleware on the staff route group).
 */
class StaffCaseController extends Controller
{
    public function index(Request $request)
    {
        $query = ModerationCase::query();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($type = $request->query('case_type')) {
            $query->where('case_type', $type);
        }
        if ($sev = $request->query('severity_gte')) {
            $query->where('severity', '>=', (int) $sev);
        }

        $query->orderByDesc('severity')->orderBy('priority')->orderBy('created_at');

        return CaseResource::collection($query->paginate(25));
    }
}
