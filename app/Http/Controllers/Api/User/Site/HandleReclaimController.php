<?php

namespace App\Http\Controllers\Api\User\Site;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\Site\ReclaimHandleRequest;
use App\Services\Site\ReclaimHandleAction;
use Illuminate\Http\JsonResponse;

class HandleReclaimController extends ApiController
{
    use ResolveCurrentUser;

    public function __construct(private readonly ReclaimHandleAction $action) {}

    public function store(ReclaimHandleRequest $request): JsonResponse
    {
        $pro = $this->currentUser($request);

        $this->action->execute($pro, $request->string('handle'), [
            'ip'         => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1024),
        ]);

        return response()->json(['status' => 'ok']);
    }
}
