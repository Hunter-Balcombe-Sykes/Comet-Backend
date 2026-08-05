<?php

namespace App\Site\Pools;

use Illuminate\Support\Facades\DB;

/**
 * The ONE auto-sync-latest read/write (owner, 2026-08-05: "one grammar
 * everywhere"). Every source platform's toggle lives in its connection's
 * sparse display_settings — absent means ON, only an explicit false is off.
 * The two site-level columns this replaces (content_instagram_auto_enabled,
 * shop_auto_latest) were migrated into connection rows and dropped.
 *
 * `isOn` with NO active connection reports the sparse default — TRUE —
 * because that is the settings-wire contract (autoLatest reads true before
 * a store is ever connected, exactly as the old site column defaulted).
 * Callers that need "is there anything to sync" already know whether a
 * connection exists; the pool rule reads per-connection rows in SQL and
 * never calls this. `set` writes every active connection of the platform,
 * so a platform whose setting was historically site-wide (shop) keeps
 * behaving site-wide without a special case.
 */
class AutoSyncSetting
{
    public const KEY = 'auto_sync_latest';

    public static function isOn(string $userId, string $platform): bool
    {
        $rows = DB::connection('pgsql')->table('site.platform_connections')
            ->where('user_id', $userId)
            ->where('platform', $platform)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->pluck('display_settings');

        if ($rows->isEmpty()) {
            return true;
        }

        foreach ($rows as $raw) {
            $settings = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
            if (($settings[self::KEY] ?? true) !== false) {
                return true;
            }
        }

        return false;
    }

    public static function set(string $userId, string $platform, bool $on): void
    {
        $rows = DB::connection('pgsql')->table('site.platform_connections')
            ->where('user_id', $userId)
            ->where('platform', $platform)
            ->whereNull('deleted_at')
            ->get(['id', 'display_settings']);

        foreach ($rows as $row) {
            $settings = is_array($row->display_settings)
                ? $row->display_settings
                : (json_decode((string) $row->display_settings, true) ?: []);

            if ($on) {
                // Absent = ON: clearing the key rather than storing true keeps
                // the map sparse, matching DisplaySettingsController's writes.
                unset($settings[self::KEY]);
            } else {
                $settings[self::KEY] = false;
            }

            DB::connection('pgsql')->table('site.platform_connections')
                ->where('id', $row->id)
                ->update([
                    'display_settings' => $settings === [] ? null : json_encode($settings),
                    'updated_at' => now(),
                ]);
        }
    }
}
