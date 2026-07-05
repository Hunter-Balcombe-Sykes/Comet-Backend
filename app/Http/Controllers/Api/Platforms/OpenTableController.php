<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\OpenTableService;
use App\Services\Platforms\Payloads\GoogleBusinessPayload;
use App\Services\Platforms\Registry\Platform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// OpenTable's one bespoke endpoint. connect/selection/forget are registry-driven
// (OpenTableConnect strategy + GenericPlatformController); this survives because
// it reads ACROSS platforms (the Google Business connection), which the generic
// shape has no seam for.
class OpenTableController extends ApiController
{
    use ResolveCurrentUser;

    public function __construct(private readonly OpenTableService $service) {}

    // GET /api/platforms/opentable/suggestion
    // The OpenTable profile link (with the rid) already harvested from the
    // user's Google Business connection, so the dashboard can offer a one-click
    // connect — OpenTable blocks us from resolving slug links ourselves.
    public function suggestion(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $gb = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('platform', Platform::GoogleBusiness->value)
            ->first();

        $suggestion = $gb
            ? $this->service->suggestionFromGoogleBusiness(GoogleBusinessPayload::fromArray($gb->payload)->toArray())
            : null;

        return $this->success(['suggestion' => $suggestion]);
    }
}
