<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Registry\PlatformRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Per-integration public display toggles — which parts of a connected
// platform's synced content show on the owner's sitepage (e.g. Google
// Business reviews). The toggle set is declared on the PlatformDescriptor
// (displayToggles) so this controller is fully registry-driven; a platform
// with no declared toggles 404s. Values persist as a sparse JSONB map on
// every active connection row of that platform (absent key = ON), and the
// IntegrationConnection observer treats display_settings changes as
// meaningful — so a toggle flip purges the edge cache and the sitepage
// reflects it within seconds.
class DisplaySettingsController extends ApiController
{
    use ResolveCurrentUser;

    public function __construct(private readonly PlatformRegistry $registry) {}

    // GET /platforms/{platform}/display-settings
    public function show(Request $request, string $platform): JsonResponse
    {
        $descriptor = $this->registry->get($platform);
        if ($descriptor === null || ! $descriptor->hasDisplayToggles()) {
            return $this->error('This integration has no display settings.', 404);
        }

        $user = $this->currentUser($request);
        // first() (not value()) so the array cast applies — value() returns
        // the raw JSON string from the driver.
        $stored = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('platform', $platform)
            ->where('is_active', true)
            ->first(['display_settings'])
            ?->display_settings ?? [];

        return $this->success([
            'platform' => $platform,
            'toggles' => $this->shapeToggles($descriptor->displayToggleDefs(), (array) $stored),
        ]);
    }

    // PATCH /platforms/{platform}/display-settings
    public function update(Request $request, string $platform): JsonResponse
    {
        $descriptor = $this->registry->get($platform);
        if ($descriptor === null || ! $descriptor->hasDisplayToggles()) {
            return $this->error('This integration has no display settings.', 404);
        }

        $keys = array_column($descriptor->displayToggleDefs(), 'key');
        $validated = $request->validate([
            'toggles' => ['required', 'array'],
            'toggles.*' => ['boolean'],
        ]);
        $incoming = $validated['toggles'];

        $unknown = array_diff(array_keys($incoming), $keys);
        if ($unknown !== []) {
            return $this->error('Unknown display toggle: '.implode(', ', $unknown), 422);
        }

        $user = $this->currentUser($request);
        $connections = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('platform', $platform)
            ->where('is_active', true)
            ->get();

        if ($connections->isEmpty()) {
            return $this->error('Connect this integration first.', 404);
        }

        $merged = [];
        foreach ($connections as $connection) {
            // Sparse merge: only keys the owner has ever flipped are stored;
            // saving TRUE removes the key (back to the ON default) so the
            // stored map stays a list of deviations, not a full snapshot.
            $current = (array) ($connection->display_settings ?? []);
            foreach ($incoming as $key => $enabled) {
                if ((bool) $enabled) {
                    unset($current[$key]);
                } else {
                    $current[$key] = false;
                }
            }
            $connection->display_settings = $current === [] ? null : $current;
            $connection->save(); // observer → cache purge + payload rebuild
            $merged = $current;
        }

        return $this->success([
            'platform' => $platform,
            'toggles' => $this->shapeToggles($descriptor->displayToggleDefs(), $merged),
        ]);
    }

    /**
     * @param  array<int, array{key: string, label: string, description: string}>  $defs
     * @param  array<string, mixed>  $stored
     * @return array<int, array{key: string, label: string, description: string, enabled: bool}>
     */
    private function shapeToggles(array $defs, array $stored): array
    {
        return array_map(fn (array $def) => [
            'key' => $def['key'],
            'label' => $def['label'],
            'description' => $def['description'],
            'enabled' => ($stored[$def['key']] ?? true) !== false,
        ], $defs);
    }
}
