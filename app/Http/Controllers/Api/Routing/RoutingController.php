<?php

namespace App\Http\Controllers\Api\Routing;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Routing\RouteLinkRequest;
use App\Routing\LinkRoutingService;
use App\Routing\RoutingContext;
use Illuminate\Http\JsonResponse;

/**
 * The named successor to CustomLinksController::addLink (plan §2).
 *
 *   POST /api/routing/preview — decide + explain, write nothing (debounced
 *   as the user types a URL).
 *   POST /api/routing/links   — observe → project → place → reconcile.
 *
 * The 202 envelope and `routedTo` shape are deliberately compatible with the
 * legacy endpoint so the frontend can move one screen at a time rather than
 * in a single flag day.
 */
class RoutingController extends ApiController
{
    use ResolveCurrentUser;

    public function __construct(private readonly LinkRoutingService $routing) {}

    public function preview(RouteLinkRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $result = $this->routing->preview(
            $request->validated()['url'],
            RoutingContext::forUser($user, 'paste'),
        );

        return $this->success($result);
    }

    public function store(RouteLinkRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $result = $this->routing->route(
            $request->validated()['url'],
            RoutingContext::forUser($user, 'paste'),
        );

        // 'ok' when something was actually connected; 'pending' otherwise —
        // matching the legacy contract, where pending meant "we accepted it,
        // work continues". A suggestion or a link item is exactly that.
        $status = $result['connectionId'] !== null ? 'ok' : 'pending';

        return $this->success(['status' => $status] + $result, 202);
    }
}
