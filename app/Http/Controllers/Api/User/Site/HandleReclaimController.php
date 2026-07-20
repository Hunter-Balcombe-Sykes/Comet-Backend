<?php

namespace App\Http\Controllers\Api\User\Site;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\Site\ReclaimHandleRequest;
use App\Services\Site\ReclaimHandleAction;
use Illuminate\Http\JsonResponse;

class HandleReclaimController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function __construct(private readonly ReclaimHandleAction $action) {}

    public function store(ReclaimHandleRequest $request): JsonResponse
    {
        $pro = $this->currentUser($request);
        $site = $this->currentSite($pro);
        // SEC-8: pending-deletion + ownership gate via SitePolicy, matching every
        // other Site-mutating endpoint in this domain.
        $this->authorizeForUser($pro, 'update', $site);

        $this->action->execute($pro, $request->string('handle'), [
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1024),
        ]);

        return $this->success(['status' => 'ok']);
    }
}
