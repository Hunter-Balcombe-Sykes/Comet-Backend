<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\AddEventRequest;
use App\Services\Http\FetchBudget;
use App\Services\Platforms\EventsCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// "Tickets & Events" smart-detect facade. One paste-a-URL card over Eventbrite +
// Humanitix + custom links: detect the platform, decide event-vs-account, and
// store via EventsCatalog. The per-platform controllers still own the account /
// standalone-event delete endpoints — the unified selection tags each row's
// removePath, so this facade only adds the custom-event delete.
class EventsController extends ApiController
{
    use ResolveCurrentUser;

    public function __construct(private readonly EventsCatalog $catalog, private readonly FetchBudget $budget) {}

    // POST /api/platforms/events/add — paste any event / organiser / link URL.
    public function add(AddEventRequest $request): JsonResponse
    {
        $seconds = (float) config('partna.http_fetch.connect_budget_seconds', 20);
        $result = $this->budget->open($seconds, fn () => $this->catalog->addByUrl($this->currentUser($request), $request->validated()['url']));

        if (! ($result['ok'] ?? false)) {
            return $this->error($result['error'] ?? 'Could not add that link.', $result['status'] ?? 422);
        }

        return $this->success(['selection' => $result['selection'] ?? null]);
    }

    // GET /api/platforms/events/selection — the unified accounts + events list.
    public function selection(Request $request): JsonResponse
    {
        return $this->success(['selection' => $this->catalog->selection($this->currentUser($request))]);
    }

    // DELETE /api/platforms/events/custom/{id} — remove one custom event card.
    public function removeCustom(Request $request, string $id): JsonResponse
    {
        $result = $this->catalog->removeCustom($this->currentUser($request), $id);

        if (! ($result['ok'] ?? false)) {
            return $this->error($result['error'] ?? 'Event not found.', $result['status'] ?? 404);
        }

        return $this->success(['selection' => $result['selection'] ?? null]);
    }
}
