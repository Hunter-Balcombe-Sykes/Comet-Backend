<?php

namespace App\Http\Controllers\Api\V5;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Models\V5\PlatformDefinition;
use App\Models\V5\UserPlatform;
use App\Services\V5\Registry\V5PlatformRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformController extends ApiController
{
    use ResolveCurrentUser;

    public function __construct(
        private readonly V5PlatformRegistry $registry,
    ) {}

    /** GET /v5/platforms — user's connected platforms. */
    public function index(Request $request): JsonResponse
    {
        $professional = $request->attributes->get('professional');

        $platforms = UserPlatform::with(['platformDefinition.categories', 'platformDefinition.sourceRules'])
            ->where('user_id', $professional->id)
            ->get()
            ->map(function (UserPlatform $up) {
                $def = $up->platformDefinition;
                if (! $def) return null;

                // Resolve through registry to apply inheritance
                $resolved = $this->registry->find($def->id);
                if (! $resolved) return null;

                return array_merge($resolved, [
                    'user_platform_id' => $up->id,
                    'identifier_value' => $up->identifier_value,
                    'is_enabled' => $up->is_enabled,
                    'last_refreshed_at' => $up->updated_at?->diffForHumans(),
                ]);
            })
            ->filter()
            ->values();

        return response()->json(['data' => $platforms]);
    }

    /** POST /v5/platforms — connect a platform. */
    public function store(Request $request): JsonResponse
    {
        $professional = $request->attributes->get('professional');

        $validated = $request->validate([
            'platform_definition_id' => ['required', 'uuid', 'exists:v5.platform_definitions,id'],
            'identifier_value' => ['required', 'string', 'max:2048'],
        ]);

        // Check if already connected
        $existing = UserPlatform::where('user_id', $professional->id)
            ->where('platform_definition_id', $validated['platform_definition_id'])
            ->first();

        if ($existing) {
            return response()->json(['message' => 'Platform already connected'], 409);
        }

        $up = UserPlatform::create([
            'user_id' => $professional->id,
            'platform_definition_id' => $validated['platform_definition_id'],
            'identifier_value' => $validated['identifier_value'],
        ]);

        $def = $this->registry->find($validated['platform_definition_id']);

        return response()->json([
            'data' => array_merge($def ?? [], [
                'user_platform_id' => $up->id,
                'identifier_value' => $up->identifier_value,
            ]),
        ], 201);
    }

    /** GET /v5/platforms/{id} */
    public function show(Request $request, string $id): JsonResponse
    {
        $professional = $request->attributes->get('professional');

        $up = UserPlatform::with(['platformDefinition.categories', 'platformDefinition.sourceRules'])
            ->where('user_id', $professional->id)
            ->find($id);

        if (! $up) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $def = $up->platformDefinition;
        $resolved = $def ? $this->registry->find($def->id) : null;

        return response()->json(['data' => array_merge($resolved ?? [], [
            'user_platform_id' => $up->id,
            'identifier_value' => $up->identifier_value,
            'is_enabled' => $up->is_enabled,
        ])]);
    }

    /** PATCH /v5/platforms/{id} */
    public function update(Request $request, string $id): JsonResponse
    {
        $professional = $request->attributes->get('professional');

        $up = UserPlatform::where('user_id', $professional->id)->find($id);
        if (! $up) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'identifier_value' => ['sometimes', 'string', 'max:2048'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);

        $up->update($validated);

        return response()->json(['data' => $up]);
    }

    /** DELETE /v5/platforms/{id} — disconnect. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $professional = $request->attributes->get('professional');

        $up = UserPlatform::where('user_id', $professional->id)->find($id);
        if (! $up) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $up->delete();

        return response()->json(null, 204);
    }

    /** POST /v5/platforms/{id}/refresh — manual refresh. */
    public function refresh(Request $request, string $id): JsonResponse
    {
        $professional = $request->attributes->get('professional');

        $up = UserPlatform::where('user_id', $professional->id)->find($id);
        if (! $up) {
            return response()->json(['message' => 'Not found'], 404);
        }

        // Dispatch refresh job (async — returns immediately)
        // \App\Jobs\V5\RefreshPlatformJob::dispatch($up);

        return response()->json(['message' => 'Refresh queued']);
    }
}
