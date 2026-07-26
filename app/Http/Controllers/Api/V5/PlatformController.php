<?php

namespace App\Http\Controllers\Api\V5;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Models\V5\UserPlatform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformController extends ApiController
{
    use ResolveCurrentUser;

    /** GET /v5/platforms — user's connected platforms. */
    public function index(Request $request): JsonResponse
    {
        $professional = $request->attributes->get('professional');

        $platforms = UserPlatform::with(['platformDefinition.categories'])
            ->where('user_id', $professional->id)
            ->get()
            ->map(function (UserPlatform $up) {
                $def = $up->platformDefinition;
                if (! $def) return null;

                return [
                    'user_platform_id' => $up->id,
                    'id' => $def->id,
                    'name' => $def->name,
                    'logo' => $def->logo,
                    'platform_colour' => $def->platform_colour,
                    'url_format' => $def->url_format,
                    'is_source' => $def->is_source,
                    'is_url_source' => $def->is_url_source,
                    'identifier_value' => $up->identifier_value,
                    'identifier_name_type' => $up->identifier_name_type ?? $def->identifier_name_type,
                    'is_enabled' => $up->is_enabled,
                    'categories' => $def->categories->pluck('name')->implode(', '),
                    'category_names' => $def->categories->pluck('name')->toArray(),
                    'created_at' => $up->created_at?->toIso8601String(),
                    'updated_at' => $up->updated_at?->toIso8601String(),
                ];
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

        $existing = UserPlatform::where('user_id', $professional->id)
            ->where('platform_definition_id', $validated['platform_definition_id'])->first();
        if ($existing) {
            return response()->json(['message' => 'Already connected'], 409);
        }

        $up = UserPlatform::create([
            'user_id' => $professional->id,
            'platform_definition_id' => $validated['platform_definition_id'],
            'identifier_value' => $validated['identifier_value'],
        ]);

        return response()->json(['data' => ['user_platform_id' => $up->id]], 201);
    }

    public function show(Request $request, string $id): JsonResponse { return response()->json(['message' => 'Not implemented'], 501); }
    public function update(Request $request, string $id): JsonResponse { return response()->json(['message' => 'Not implemented'], 501); }
    public function destroy(Request $request, string $id): JsonResponse
    {
        $professional = $request->attributes->get('professional');
        $up = UserPlatform::where('user_id', $professional->id)->find($id);
        if (! $up) return response()->json(['message' => 'Not found'], 404);
        $up->delete();
        return response()->json(null, 204);
    }
    public function refresh(Request $request, string $id): JsonResponse { return response()->json(['message' => 'Refresh queued']); }
}
