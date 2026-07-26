<?php

namespace App\Http\Controllers\Api\V5;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Models\V5\Item;
use App\Models\V5\ItemValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemController extends ApiController
{
    use ResolveCurrentUser;

    /** GET /v5/items */
    public function index(Request $request): JsonResponse
    {
        $professional = $request->attributes->get('professional');

        $items = Item::with(['values', 'sources.userPlatform.platformDefinition', 'pools'])
            ->where('user_id', $professional->id)
            ->orderBy('sort_order')
            ->paginate(50);

        return response()->json($items);
    }

    /** POST /v5/items — create manual item or add from URL */
    public function store(Request $request): JsonResponse
    {
        $professional = $request->attributes->get('professional');

        $validated = $request->validate([
            'pool_id' => ['required', 'uuid', 'exists:v5.content_pools,id'],
            'identifier' => ['required', 'string', 'max:512'],
            'name' => ['required', 'string', 'max:512'],
            'item_type' => ['required', 'string'],
            'values' => ['nullable', 'array'],
            'values.*.field_name' => ['required', 'string'],
            'values.*.value' => ['required', 'string'],
            'values.*.format' => ['nullable', 'string'],
        ]);

        // Check for duplicate (merge key)
        $existing = Item::where('user_id', $professional->id)
            ->where('identifier', $validated['identifier'])
            ->first();

        if ($existing) {
            // Add to pool if not already there
            if (! $existing->pools()->where('content_pool_id', $validated['pool_id'])->exists()) {
                $existing->pools()->attach($validated['pool_id']);
            }
            return response()->json(['data' => $existing->load(['values', 'pools'])]);
        }

        $item = Item::create([
            'user_id' => $professional->id,
            'identifier' => $validated['identifier'],
            'name' => $validated['name'],
            'item_type' => $validated['item_type'],
        ]);

        // Attach to pool
        $item->pools()->attach($validated['pool_id']);

        // Create manual values
        if (! empty($validated['values'])) {
            foreach ($validated['values'] as $v) {
                ItemValue::create([
                    'item_id' => $item->id,
                    'item_source_id' => null, // manual entry
                    'field_name' => $v['field_name'],
                    'value' => $v['value'],
                    'format' => $v['format'] ?? 'text',
                    'is_manually_set' => true,
                    'is_resolved' => true,
                ]);
            }
        }

        return response()->json(['data' => $item->load(['values', 'pools'])], 201);
    }

    /** GET /v5/items/{id} */
    public function show(Request $request, string $id): JsonResponse
    {
        $professional = $request->attributes->get('professional');
        $item = Item::with(['values', 'sources.userPlatform.platformDefinition', 'pools'])
            ->where('user_id', $professional->id)
            ->find($id);

        if (! $item) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['data' => $item]);
    }

    /** PATCH /v5/items/{id} */
    public function update(Request $request, string $id): JsonResponse
    {
        $professional = $request->attributes->get('professional');
        $item = Item::where('user_id', $professional->id)->find($id);
        if (! $item) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:512'],
            'is_selected' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer'],
        ]);

        $item->update($validated);
        return response()->json(['data' => $item]);
    }

    /** DELETE /v5/items/{id} */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $professional = $request->attributes->get('professional');
        $item = Item::where('user_id', $professional->id)->find($id);
        if (! $item) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $item->delete();
        return response()->json(null, 204);
    }

    /** POST /v5/items/{id}/select */
    public function select(Request $request, string $id): JsonResponse
    {
        $professional = $request->attributes->get('professional');
        $item = Item::where('user_id', $professional->id)->find($id);
        if (! $item) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $item->update(['is_selected' => true]);
        return response()->json(['data' => $item]);
    }

    /** POST /v5/items/{id}/deselect */
    public function deselect(Request $request, string $id): JsonResponse
    {
        $professional = $request->attributes->get('professional');
        $item = Item::where('user_id', $professional->id)->find($id);
        if (! $item) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $item->update(['is_selected' => false]);
        return response()->json(['data' => $item]);
    }

    /** GET /v5/items/{id}/values */
    public function values(Request $request, string $id): JsonResponse
    {
        $professional = $request->attributes->get('professional');
        $item = Item::where('user_id', $professional->id)->find($id);
        if (! $item) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(['data' => $item->values()->orderBy('field_name')->get()]);
    }

    /** PATCH /v5/items/{id}/values/{valueId} */
    public function updateValue(Request $request, string $id, string $valueId): JsonResponse
    {
        $professional = $request->attributes->get('professional');
        $item = Item::where('user_id', $professional->id)->find($id);
        if (! $item) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $value = $item->values()->find($valueId);
        if (! $value) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'value' => ['required', 'string'],
            'is_manually_set' => ['sometimes', 'boolean'],
        ]);

        $validated['is_resolved'] = true;
        $value->update($validated);

        return response()->json(['data' => $value]);
    }
}
