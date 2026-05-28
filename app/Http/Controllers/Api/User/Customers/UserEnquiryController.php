<?php

namespace App\Http\Controllers\Api\User\Customers;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Controllers\Concerns\ReturnsPaginatedResponse;
use App\Http\Resources\EnquiryResource;
use App\Models\Core\Site\Enquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2: Dashboard inbox for visitor-submitted enquiries. Read-only list + mark read/unread + soft-delete, scoped to the current professional.
class UserEnquiryController extends ApiController
{
    use ResolveCurrentUser;
    use ReturnsPaginatedResponse;

    public function index(Request $request): JsonResponse
    {
        $pro = $this->currentUser($request);

        $page = Enquiry::query()
            ->where('user_id', $pro->id)
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
        $pro = $this->currentUser($request);

        $enquiry = Enquiry::query()
            ->where('user_id', $pro->id)
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

    /**
     * Return a count per status for the current professional's enquiries.
     *
     * All five statuses are always present in the response (zero-filled),
     * so the client can render unread badges without null-checking.
     *
     * @return JsonResponse{new:int,read:int,replied:int,archived:int,spam:int}
     */
    public function counts(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        // toBase() skips Eloquent model hydration so the status key comes back
        // as a plain string rather than an EnquiryStatus enum instance.
        $rows = Enquiry::query()
            ->where('user_id', $user->id)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->toBase()
            ->pluck('c', 'status');

        return $this->success([
            'new' => (int) ($rows['new'] ?? 0),
            'read' => (int) ($rows['read'] ?? 0),
            'replied' => (int) ($rows['replied'] ?? 0),
            'archived' => (int) ($rows['archived'] ?? 0),
            'spam' => (int) ($rows['spam'] ?? 0),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $pro = $this->currentUser($request);

        $enquiry = Enquiry::query()
            ->where('user_id', $pro->id)
            ->find($id);

        if (! $enquiry) {
            return $this->error('Enquiry not found.', 404);
        }

        $enquiry->delete();

        return $this->success(['ok' => true]);
    }
}
