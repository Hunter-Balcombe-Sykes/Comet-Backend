<?php

namespace App\Http\Controllers\Api\Site;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\Design\ApplyRestyleRequest;
use App\Http\Resources\Site\DesignKitRestyleResource;
use App\Models\Core\Site\DesignKitRestyle;
use App\Services\Design\DesignKitRestyleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Restyle from brand" (plan §13): preview diff, apply with an undo
 * snapshot, undo. Every write here is an explicit user action — the flow is
 * never silent, which is the §13 rule that separates it from the
 * fill-if-empty autopilot writes.
 */
class RestyleController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function __construct(private readonly DesignKitRestyleService $restyler) {}

    public function preview(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $site = $this->currentSite($user);

        return $this->success(['restyle' => $this->restyler->preview($site)]);
    }

    public function store(ApplyRestyleRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $site = $this->currentSite($user);

        $skeleton = new DesignKitRestyle;
        $skeleton->site_id = $site->id;
        $skeleton->setRelation('site', $site);
        $this->authorizeForUser($user, 'create', $skeleton);

        $restyle = $this->restyler->apply($site, $request->validated('columns'));

        return $this->success(['restyle' => new DesignKitRestyleResource($restyle)], 201);
    }

    public function undo(Request $request, string $restyleId): JsonResponse
    {
        $user = $this->currentUser($request);
        $site = $this->currentSite($user);

        $restyle = DesignKitRestyle::query()
            ->where('id', $restyleId)
            ->where('site_id', $site->id)
            ->first();

        if ($restyle === null) {
            // 404, never 403 — existence must not leak (house rule).
            abort(404, 'Restyle not found.');
        }

        $restyle->setRelation('site', $site);
        $this->authorizeForUser($user, 'update', $restyle);

        $this->restyler->undo($site, $restyle);

        return $this->success(['restyle' => new DesignKitRestyleResource($restyle->fresh())]);
    }
}
