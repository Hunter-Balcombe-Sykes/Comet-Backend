<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Requests\Platforms\ConnectTicketekRequest;
use Illuminate\Http\JsonResponse;

// Test-mode endpoints for the Ticketek "Tickets" link. Ticketek is bot-walled (Akamai), so this is a
// validated link card — URL + optional label — rendered in the events
// section of the sitepage. No scraping.
class TicketekController extends ExternalLinkController
{
    protected function platform(): string
    {
        return 'ticketek';
    }

    // POST /api/platforms/ticketek/connect
    public function connect(ConnectTicketekRequest $request): JsonResponse
    {
        return $this->storeLink($request, $request->validated());
    }
}
