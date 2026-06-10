<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Requests\Platforms\ConnectTimelyRequest;
use Illuminate\Http\JsonResponse;

// Test-mode endpoints for the Timely booking link (book.gettimely.com / bookings.gettimely.com). A
// validated link card — URL + optional label — that powers the sitepage
// Book action. No scraping (Timely booking pages are JS-rendered).
class TimelyController extends ExternalLinkController
{
    protected function platform(): string
    {
        return 'timely';
    }

    // POST /api/platforms/timely/connect
    public function connect(ConnectTimelyRequest $request): JsonResponse
    {
        return $this->storeLink($request, $request->validated());
    }
}
