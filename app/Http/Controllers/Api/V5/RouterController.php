<?php

namespace App\Http\Controllers\Api\V5;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Services\V5\Router\V5Router;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RouterController extends ApiController
{
    use ResolveCurrentUser;

    public function __construct(
        private readonly V5Router $router,
    ) {}

    public function determine(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'scope_category' => ['nullable', 'string'],
            'scope_platform_id' => ['nullable', 'string', 'uuid'],
        ]);

        $result = $this->router->determine(
            $validated['url'],
            $validated['scope_platform_id'] ?? null,
            $validated['scope_category'] ?? null,
        );

        return response()->json($result->toArray());
    }
}
