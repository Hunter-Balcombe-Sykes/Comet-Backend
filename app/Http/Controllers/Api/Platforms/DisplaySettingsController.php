<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Services\Platforms\Registry\PlatformRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Per-integration public display toggles — which parts of a connected
// platform's synced content show on the owner's sitepage (e.g. Google
// Business reviews). The toggle set is declared on the PlatformDescriptor
// (displayToggles) so this controller is fully registry-driven; a platform
// with no declared toggles 404s.
//
// A toggle is backed by ONE of two stores:
//   • default — a sparse JSONB map on every active connection row
//     (site.platform_connections.display_settings; absent key = the toggle's
//     declared default, ON unless the def carries 'default' => false).
//   • a `siteColumn` toggle — a boolean column on site.sites. Instagram's
//     `gallery` toggle is backed by `content_instagram_auto_enabled` so this
//     switch and the Content/Media "Latest content auto sync" switch are ONE
//     value (OFF hides ALL auto Instagram content — the curated reel/post
//     slots AND the integration card).
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

        $site = $this->needsSite($defs) ? $user->site()->first() : null;

        return $this->success([
            'platform' => $platform,
            'toggles' => $this->shapeToggles($defs, (array) $stored, $site),
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

        // toggle key => the site column that backs it (siteColumn toggles only),
        // and toggle key => its declared default (absent = ON) for the sparse merge.
        $columnByKey = [];
        $defaultByKey = [];
        foreach ($defs as $def) {
            if (isset($def['siteColumn'])) {
                $columnByKey[$def['key']] = $def['siteColumn'];
            }
            $defaultByKey[$def['key']] = (bool) ($def['default'] ?? true);
        }

        $site = $this->needsSite($defs) ? $user->site()->first() : null;

        // Authorize EVERY row this request could write, up front, before any
        // write happens (SEC-107). Ownership is already structurally
        // guaranteed here (both $connections and $site are queried scoped to
        // $user->id), so this is defence-in-depth, not a reachable
        // authorization bypass fix. The ordering is load-bearing: $site saves
        // FIRST below and its observer fires an edge-cache purge that can't
        // be rolled back — authorizing inline inside the write loop would
        // risk a half-applied write (site purged, connections never
        // touched), so every gate runs before the first save.
        foreach ($connections as $connection) {
            $this->authorizeForUser($user, 'update', $connection);
        }
        if ($site !== null) {
            $this->authorizeForUser($user, 'update', $site);
        }

        // Site-column-backed toggles write boolean columns on the owner's site;
        // a single save fires SiteObserver (backend cache bust + edge purge +
        // re-warm), so the unified Instagram switch reflects everywhere at once.
        foreach ($incoming as $key => $enabled) {
            $column = $columnByKey[$key] ?? null;
            if ($column !== null && $site !== null) {
                $site->{$column} = (bool) $enabled;
            }
        }
        if ($site !== null && $site->isDirty()) {
            $site->save();
        }

        // Connection-JSONB toggles: sparse merge onto every active connection.
        $jsonIncoming = array_diff_key($incoming, $columnByKey);
        $merged = [];
        if ($jsonIncoming !== []) {
            foreach ($connections as $connection) {
                // Sparse merge: only DEVIATIONS from each toggle's declared
                // default are stored; saving the default value removes the key.
                // (For the historical all-default-ON toggles this is byte-for-
                // byte the old "true removes / false stores" behaviour.)
                $current = (array) ($connection->display_settings ?? []);
                foreach ($jsonIncoming as $key => $enabled) {
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
        }

        return $this->success([
            'platform' => $platform,
            'toggles' => $this->shapeToggles($defs, $merged, $site),
        ]);
    }

    /**
     * Does any toggle read/write a site column (vs the connection JSONB)?
     *
     * @param  array<int, array{key: string, label: string, description: string, siteColumn?: string}>  $defs
     */
    private function needsSite(array $defs): bool
    {
        foreach ($defs as $def) {
            if (isset($def['siteColumn'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array{key: string, label: string, description: string, siteColumn?: string, default?: bool}>  $defs
     * @param  array<string, mixed>  $stored  the connection's display_settings (JSONB toggles)
     * @param  Site|null  $site  loaded when any toggle is site-column-backed
     * @return array<int, array{key: string, label: string, description: string, enabled: bool}>
     */
    private function shapeToggles(array $defs, array $stored, ?Site $site): array
    {
        return array_map(function (array $def) use ($stored, $site) {
            // Absent key = the toggle's declared default (ON unless the def
            // says 'default' => false); a stored value always wins.
            $enabled = isset($def['siteColumn'])
                ? ($site !== null && (bool) $site->{$def['siteColumn']})
                : (bool) ($stored[$def['key']] ?? $def['default'] ?? true);

            return [
                'key' => $def['key'],
                'label' => $def['label'],
                'description' => $def['description'],
                'enabled' => $enabled,
            ];
        }, $defs);
    }
}
