<?php

namespace App\Services\Platforms\Registry\Bindings;

use App\Http\Resources\Platforms\InstagramConnectionResource;
use App\Services\Platforms\Payloads\InstagramPayload;
use App\Services\Platforms\Registry\PlatformCategory;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Registry\PlatformRouteShape;

/**
 * PD-retirement P5 (2026-08-27): Instagram's full behavioural contract,
 * moved VERBATIM from the LAST hand-written registration. Not refreshable —
 * refresh is paid Apify, never in the cron. Connect is bespoke
 * (InstagramController, hand-written routes) — no ConnectStrategy, no
 * connectInput, Bespoke shape.
 *
 * Instagram stays in DerivedDescriptorFactory::NEVER_UPGRADE: with a
 * Bespoke shape and no connect field it looks exactly like the routeless
 * descriptors upgrades() exists to fix, and an upgrade would bolt a Brand
 * link-connect over the real scraper-backed flow.
 *
 * Display toggle (2026-08-05, platforms-as-sources): the old site-column
 * gallery toggle migrated into the connection's own display_settings under
 * the ONE auto-sync key. Turning it off still hides ALL auto Instagram
 * content — the curated reel/post slots and the integration card read the
 * same value through AutoSyncSetting.
 */
final class InstagramBinding
{
    public static function configure(PlatformDescriptor $d): void
    {
        $d->category(PlatformCategory::Social)
            ->resource(InstagramConnectionResource::class)
            ->payload(InstagramPayload::class)
            ->displayToggles([
                ['key' => 'auto_sync_latest', 'label' => 'Latest post', 'description' => 'Your newest post joins your site automatically.'],
            ])
            ->routes(PlatformRouteShape::Bespoke);
    }
}
