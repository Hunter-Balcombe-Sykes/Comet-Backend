<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\ConnectSocialLinkRequest;
use App\Services\Platforms\Payloads\LinkPayload;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Registry\PlatformRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Registry-driven controller for the link-only archetype. The route group injects
// the platform slug as a route default ('platform' => '<slug>'); this controller
// resolves the matching PlatformDescriptor and serves the uniform
// connect / selection / forget shape that the per-platform controllers used to.
//
// Storage + authorization come from ManagesIntegrationConnection (unchanged); the
// platform's URL/handle parsing comes from the descriptor's ConnectStrategy; the
// response shape comes from the descriptor's resourceClass(). LinkPayload is the
// typed boundary between the stored jsonb and the (contract-frozen) resource.
class GenericPlatformController extends ApiController
{
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    public function __construct(private readonly PlatformRegistry $registry) {}

    // The platform key for the ManagesIntegrationConnection trait. Read from the
    // route default the integrations group sets per migrated platform.
    protected function platform(): string
    {
        $platform = request()->route('platform');

        // Should never happen — every generic route sets the default — but fail
        // closed rather than write under a null platform.
        abort_if(! is_string($platform) || $platform === '', 404);

        return $platform;
    }

    // POST /api/platforms/{platform}/connect — parse the input via the descriptor's
    // connect strategy, store the canonical {username,url}, echo it.
    public function connect(ConnectSocialLinkRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $descriptor = $this->descriptor();

        // Capability checkpoint (spec §9) — true for everyone today; the gate
        // exists so future paid-tier/account-type rules are a per-descriptor flag.
        abort_unless($descriptor->availableFor($user), 403);

        $strategy = $descriptor->connectStrategy();
        abort_if($strategy === null, 404);

        $selection = $strategy->normalize($request->validated()['username']);
        if ($selection === null) {
            return $this->error($descriptor->connectErrorMessage() ?? 'Enter a valid link.', 422);
        }

        // Round-trip through the typed boundary, then store the canonical shape.
        $payload = LinkPayload::fromArray($selection)->toArray();
        $this->writeConnection($user, $payload);

        $resourceClass = $descriptor->resourceClass();

        return $this->success((new $resourceClass($payload))->resolve());
    }

    // GET /api/platforms/{platform}/selection — the saved link (or null).
    public function selection(Request $request): JsonResponse
    {
        $descriptor = $this->descriptor();
        $payload = $this->readConnection($this->currentUser($request));

        if ($payload === null) {
            return $this->success(['selection' => null]);
        }

        $resourceClass = $descriptor->resourceClass();
        $selection = (new $resourceClass(LinkPayload::fromArray($payload)->toArray()))->resolve();

        return $this->success(['selection' => $selection]);
    }

    // DELETE /api/platforms/{platform} — clear the connection.
    public function forget(Request $request): JsonResponse
    {
        $this->forgetConnection($this->currentUser($request));

        return $this->success(['selection' => null]);
    }

    // Resolve the descriptor for the current route's platform, or 404.
    private function descriptor(): PlatformDescriptor
    {
        $descriptor = $this->registry->get($this->platform());
        abort_if($descriptor === null, 404);

        return $descriptor;
    }
}
