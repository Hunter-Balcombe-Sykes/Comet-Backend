<?php

namespace App\Http\Controllers\Api\Professional\Customers;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ReturnsPaginatedResponse;
use App\Http\Resources\EnquiryResource;
use App\Models\Core\Site\Enquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2: Dashboard inbox for visitor-submitted enquiries. Read-only list + mark read/unread + soft-delete, scoped to the current professional.
class ProfessionalEnquiryController extends ApiController
{
    use ResolveCurrentProfessional;
    use ReturnsPaginatedResponse;

    public function index(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $page = Enquiry::query()
            ->where('professional_id', $pro->id)
            ->orderByDesc('created_at')
            ->paginate((int) $request->integer('per_page', 20));

        // through() transforms items in place; the paginator's count metadata
        // is untouched so paginatedResponse() still emits the canonical
        // current_page/last_page/total/per_page block (#P2-34).
        $page->through(fn (Enquiry $e) => EnquiryResource::make($e)->resolve());

        return $this->success($this->paginatedResponse($page));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $enquiry = Enquiry::query()
            ->where('professional_id', $pro->id)
            ->find($id);

        if (! $enquiry) {
            return $this->error('Enquiry not found.', 404);
        }

        $request->validate([
            'read' => ['required', 'boolean'],
        ]);

        $enquiry->read_at = $request->boolean('read') ? now() : null;
        $enquiry->save();

        return $this->success([
            'enquiry' => (new EnquiryResource($enquiry))->toArray($request),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $enquiry = Enquiry::query()
            ->where('professional_id', $pro->id)
            ->find($id);

        if (! $enquiry) {
            return $this->error('Enquiry not found.', 404);
        }

        $enquiry->delete();

        return $this->success(['ok' => true]);
    }
}
