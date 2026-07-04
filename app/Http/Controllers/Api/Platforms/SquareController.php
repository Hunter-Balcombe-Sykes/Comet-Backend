<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\PlatformConnectRequest;
use App\Services\Platforms\Payloads\SelectionPayload;
use App\Services\Platforms\Registry\Platform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Square Appointments — a "Book now" deep link. Unlike Fresha there is no
// scraping: the user pastes their public Square booking URL and the public site
// renders a button that opens it. Fresha and Square are mutually exclusive
// booking providers (XOR) — only one may be connected at a time.
class SquareController extends ApiController
{
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    protected function platform(): string
    {
        return Platform::Square->value;
    }

    // POST /api/platforms/square/connect
    public function connect(PlatformConnectRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        if ($this->hasConflictingConnection($user, Platform::Fresha->value)) {
            return $this->error('Disconnect Fresha before connecting Square — only one booking provider can be active at a time.', 409);
        }

        $url = $request->validated()['url'];
        $this->writeConnection($user, ['url' => $url]);

        return $this->success(['url' => $url]);
    }

    // GET /api/platforms/square/selection — read the saved booking URL.
    public function selection(Request $request): JsonResponse
    {
        return $this->success([
            'url' => SelectionPayload::fromArray($this->readConnection($this->currentUser($request)) ?? [])->url,
        ]);
    }

    // DELETE /api/platforms/square — clear the saved URL.
    public function forget(Request $request): JsonResponse
    {
        $this->forgetConnection($this->currentUser($request));

        return $this->success(['url' => null]);
    }
}
