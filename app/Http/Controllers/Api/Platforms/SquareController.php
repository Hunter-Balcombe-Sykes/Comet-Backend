<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Requests\Platforms\ConnectSquareRequest;
use Illuminate\Http\JsonResponse;

// Test-mode endpoints for the Square booking link (book.squareup.com / Square Online sites). A
// validated link card — URL + optional label — that powers the sitepage
// Book action. No scraping (Square booking pages are JS-rendered).
class SquareController extends ExternalLinkController
{
    protected function platform(): string
    {
        return 'square';
    }

    // POST /api/platforms/square/connect
    public function connect(ConnectSquareRequest $request): JsonResponse
    {
        return $this->storeLink($request, $request->validated());
    }
}
