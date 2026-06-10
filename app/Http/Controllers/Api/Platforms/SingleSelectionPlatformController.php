<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Base for the connect/selection/forget platforms (one stored selection per
// user, no picker step). Subclasses implement connect() — the only part that
// differs — plus platform() and the resource class that shapes responses.
abstract class SingleSelectionPlatformController extends ApiController
{
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    /** @return class-string The connection resource that shapes responses. */
    abstract protected function resourceClass(): string;

    // GET /api/platforms/{platform}/selection
    public function selection(Request $request): JsonResponse
    {
        $payload = $this->readConnection($this->currentUser($request));
        $resource = $this->resourceClass();

        return $this->success(['selection' => $payload ? (new $resource($payload))->resolve() : null]);
    }

    // DELETE /api/platforms/{platform}
    public function forget(Request $request): JsonResponse
    {
        $this->forgetConnection($this->currentUser($request));

        return $this->success(['selection' => null]);
    }

    /** Store + echo the freshly-connected selection. */
    protected function connected(\App\Models\Core\User\User $user, array $selection): JsonResponse
    {
        $this->writeConnection($user, $selection);
        $resource = $this->resourceClass();

        return $this->success((new $resource($selection))->resolve());
    }
}
