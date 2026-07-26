<?php

namespace App\Http\Controllers\Api\V5;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Models\V5\ContentPool;
use App\Models\V5\PlatformCategory;
use App\Services\V5\Registry\V5PlatformRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentPoolController extends ApiController
{
    use ResolveCurrentUser;

    public function __construct(
        private readonly V5PlatformRegistry $registry,
    ) {}

    /** GET /v5/pools */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->registry->standalonePools()->values()->toArray(),
        ]);
    }

    /** GET /v5/pools/{id} */
    public function show(string $id): JsonResponse
    {
        $pool = ContentPool::find($id);
        if (! $pool) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['data' => $pool]);
    }

    /** GET /v5/pools/{id}/items */
    public function items(Request $request, string $id): JsonResponse
    {
        $pool = ContentPool::find($id);
        if (! $pool) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $items = \App\Models\V5\Item::whereHas('pools', fn ($q) => $q->where('content_pool_id', $id))
            ->with(['values', 'sources.userPlatform.platformDefinition'])
            ->where('user_id', $request->attributes->get('professional')->id)
            ->orderBy('sort_order')
            ->paginate(50);

        return response()->json($items);
    }

    /** GET /v5/categories */
    public function categories(): JsonResponse
    {
        return response()->json([
            'data' => PlatformCategory::all()->toArray(),
        ]);
    }
}
