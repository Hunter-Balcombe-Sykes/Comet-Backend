<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\AddEventRequest;
use App\Jobs\Platforms\ConnectFetchJob;
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

        // CA-W5: the organiser branch deferred its scrape. Dispatch HERE, never in
        // EventsCatalog — this point is outside BOTH storeAccount()'s withPlatformLock
        // AND the ambient FetchBudget above. On QUEUE_CONNECTION=sync dispatch() runs
        // handle() inline: inside the lock it self-deadlocks on the same key, and
        // inside the budget its own FetchBudget::open() nests (the inner finally
        // clears the outer deadline — nesting fails OPEN).
        //
        // Deliberately does NOT use DefersBespokeConnect::deferredConnectResponse():
        // that helper's envelope is {status, id, statusUrl} for a single platform
        // controller, and this endpoint's contract is {status, selection, statusUrl}
        // with NO top-level id and a statusUrl on the DETECTED platform's route.
        $pending = $result['pending'] ?? null;
        if (is_array($pending)) {
            ConnectFetchJob::dispatch($pending['connectionId'], $pending['platform'])->afterCommit();

            return $this->success([
                'status' => 'pending',
                'selection' => $result['selection'] ?? null,
                'statusUrl' => url("/api/platforms/{$pending['platform']}/connect/status").'?account='.$pending['resourceId'],
            ], 202);
        }

        return $this->success(['selection' => $result['selection'] ?? null]);
    }

    // GET /api/platforms/events/selection — the unified accounts + events list.
    public function selection(Request $request): JsonResponse
    {
        return $this->success(['selection' => $this->catalog->selection($this->currentUser($request))]);
    }

    // PUT /api/platforms/events/order — persist the user's manual event order.
    // `ids` is the full desired order over the merged selection (account +
    // standalone + custom events); ids it omits keep the soonest-first
    // fallback after the listed ones. Same contract as custom links' reorder.
    public function reorder(Request $request): JsonResponse
    {
        $ids = $request->validate([
            'ids' => ['required', 'array', 'max:200'],
            'ids.*' => ['string', 'max:160'],
        ])['ids'];

        $result = $this->catalog->reorder($this->currentUser($request), $ids);

        // No `??` defaults here: reorder()'s return is a discriminated union
        // (ok:true+selection | ok:false+error+status), so the analyser proves
        // each key present in its own branch. Adding a fallback back would not
        // be defensive, it would be unreachable — and it is what made this
        // block report as *NEVER*. Widen the union, not the call site.
        if (! $result['ok']) {
            return $this->error($result['error'], $result['status']);
        }

        return $this->success(['selection' => $result['selection']]);
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
