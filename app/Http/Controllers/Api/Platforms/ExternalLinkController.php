<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Resources\Platforms\ExternalLinkConnectionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Shared base for the plain external-link platforms (Ticketek tickets, Square
// + Timely booking links): a host-validated URL plus an optional label, no
// scraping. Each subclass supplies platform() and a typed connect() request —
// the storage/selection/forget cycle is identical.
abstract class ExternalLinkController extends ApiController
{
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    /** Persist a validated {url, label?} payload and wrap it. */
    protected function storeLink(Request $request, array $validated): JsonResponse
    {
        $user = $this->currentUser($request);

        $label = isset($validated['label']) ? trim((string) $validated['label']) : '';
        $selection = [
            'url' => trim($validated['url']),
            'label' => $label !== '' ? $label : null,
        ];
        $this->writeConnection($user, $selection);

        return $this->success((new ExternalLinkConnectionResource($selection))->resolve());
    }

    // GET /api/platforms/{platform}/selection
    public function selection(Request $request): JsonResponse
    {
        $payload = $this->readConnection($this->currentUser($request));

        return $this->success(['selection' => $payload ? (new ExternalLinkConnectionResource($payload))->resolve() : null]);
    }

    // DELETE /api/platforms/{platform}
    public function forget(Request $request): JsonResponse
    {
        $this->forgetConnection($this->currentUser($request));

        return $this->success(['selection' => null]);
    }
}
