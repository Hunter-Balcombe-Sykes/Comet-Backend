<?php

namespace App\Http\Controllers\Api\V5;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Services\V5\Registry\V5PlatformRegistry;
use Illuminate\Http\JsonResponse;

class PlatformDefinitionController extends ApiController
{
    use ResolveCurrentUser;

    public function __construct(
        private readonly V5PlatformRegistry $registry,
    ) {}

    /** GET /v5/platform-definitions — all platforms with resolved config. */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->registry->all()->values()->toArray(),
        ]);
    }

    /** GET /v5/platform-definitions/{id} */
    public function show(string $id): JsonResponse
    {
        $platform = $this->registry->find($id);
        if (! $platform) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['data' => $platform]);
    }
}
