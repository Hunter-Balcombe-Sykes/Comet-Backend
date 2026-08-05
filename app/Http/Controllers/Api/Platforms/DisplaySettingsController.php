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
// with no declared toggles 404s.
//
// Every toggle is a sparse JSONB map on the active connection rows
// (site.platform_connections.display_settings; absent key = the toggle's
// declared default, ON unless the def carries 'default' => false). The
// `siteColumn` bridge died 2026-08-05 with the two site columns it served —
// Instagram's switch is a plain connection toggle now (AutoSyncSetting).
//
// Sparse storage is deviation-from-default: saving a toggle AT its default
// removes the key, saving the opposite stores it — so the JSONB map stays a
// list of deviations whatever each toggle's default is (bandcamp's
// show_all_releases is the first default-OFF toggle).
// Either write saves a model whose observer purges the edge cache + busts the
// backend cache, so a flip reflects on the sitepage within seconds.
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
        $defs = $descriptor->displayToggleDefs();

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
            'toggles' => $this->shapeToggles($defs, (array) $stored),
        ]);
    }

    // PATCH /platforms/{platform}/display-settings
    public function update(Request $request, string $platform): JsonResponse
    {
        $descriptor = $this->registry->get($platform);
        if ($descriptor === null || ! $descriptor->hasDisplayToggles()) {
            return $this->error('This integration has no display settings.', 404);
        }

        $defs = $descriptor->displayToggleDefs();
        $keys = array_column($defs, 'key');
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

        // toggle key => its declared default (absent = ON) for the sparse merge.
        $defaultByKey = [];
        foreach ($defs as $def) {
            $defaultByKey[$def['key']] = (bool) ($def['default'] ?? true);
        }

        // Authorize EVERY row this request could write, up front, before any
        // write happens (SEC-107). Ownership is already structurally
        // guaranteed here ($connections is queried scoped to $user->id), so
        // this is defence-in-depth, not a reachable authorization bypass fix.
        foreach ($connections as $connection) {
            $this->authorizeForUser($user, 'update', $connection);
        }

        // Sparse merge onto every active connection: only DEVIATIONS from
        // each toggle's declared default are stored; saving the default value
        // removes the key.
        $merged = [];
        foreach ($connections as $connection) {
            $current = (array) ($connection->display_settings ?? []);
            foreach ($incoming as $key => $enabled) {
                if ((bool) $enabled === ($defaultByKey[$key] ?? true)) {
                    unset($current[$key]);
                } else {
                    $current[$key] = (bool) $enabled;
                }
            }
            $connection->display_settings = $current === [] ? null : $current;
            $connection->save(); // observer → cache purge + payload rebuild
            $merged = $current;
        }

        return $this->success([
            'platform' => $platform,
            'toggles' => $this->shapeToggles($defs, $merged),
        ]);
    }

    /**
     * @param  array<int, array{key: string, label: string, description: string, default?: bool}>  $defs
     * @param  array<string, mixed>  $stored  the connection's display_settings
     * @return array<int, array{key: string, label: string, description: string, enabled: bool}>
     */
    private function shapeToggles(array $defs, array $stored): array
    {
        return array_map(function (array $def) use ($stored) {
            // Absent key = the toggle's declared default (ON unless the def
            // says 'default' => false); a stored value always wins.
            $enabled = (bool) ($stored[$def['key']] ?? $def['default'] ?? true);

            return [
                'key' => $def['key'],
                'label' => $def['label'],
                'description' => $def['description'],
                'enabled' => $enabled,
            ];
        }, $defs);
    }
}
