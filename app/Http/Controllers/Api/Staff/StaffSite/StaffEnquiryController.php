<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ReturnsPaginatedResponse;
use App\Http\Resources\EnquiryResource;
use App\Models\Core\Professional\User;
use App\Models\Core\Site\Enquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Staff inspector for a brand's contact-form enquiries inbox (#ENQUIRY-1, read part).
// Mirror of ProfessionalEnquiryController::index. Delete/mark-read are admin writes
// and intentionally out of scope for this read-only bundle.
class StaffEnquiryController extends ApiController
{
    use ReturnsPaginatedResponse;

    /**
     * GET /staff/professionals/{professional}/enquiries
     */
    public function index(Request $request, User $professional): JsonResponse
    {
        $page = Enquiry::query()
            ->where('professional_id', $professional->id)
            ->orderByDesc('created_at')
            ->paginate((int) $request->integer('per_page', 20));

        // See ProfessionalEnquiryController::index for the rationale on
        // ->through() + paginatedResponse() (#P2-34).
        $page->through(fn (Enquiry $e) => EnquiryResource::make($e)->resolve());

        return $this->success($this->paginatedResponse($page));
    }
}
