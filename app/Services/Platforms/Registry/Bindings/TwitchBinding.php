<?php

namespace App\Services\Platforms\Registry\Bindings;

use App\Http\Resources\Platforms\LinkConnectionResource;
use App\Services\Platforms\Payloads\FeedPayload;
use App\Services\Platforms\Registry\PlatformCategory;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Registry\PlatformRouteShape;
use App\Services\Platforms\Strategies\Connect\TwitchConnect;
use App\Services\Platforms\TwitchScraper;

/**
 * Item 10a (2026-09-01): Twitch's upgrade from a LinkOnlyBindings row to a
 * behavioural binding — the platform becomes a data source (identity onto
 * the payload at connect, VODs into the watch pool via TwitchConnector), so
 * its contract no longer fits the link-only shape's one assumption. NOT YET
 * WIRED: DerivedDescriptorFactory::BEHAVIOUR_BINDINGS needs the 'twitch'
 * line and LinkOnlyBindings::MAP must drop its 'twitch' row in the same
 * commit (both spine-owned) — until then this class is inert and UrlConnect
 * keeps serving connects.
 *
 * What changes against the link-only row, and why:
 *  - connect: TwitchConnect (validation + one vendor profile call) replaces
 *    UrlConnect(TwitchNormalizer). The 422 copy is the frozen contract,
 *    verbatim from the retired row.
 *  - payload: FeedPayload replaces LinkPayload. Load-bearing, not cosmetic:
 *    GenericPlatformController round-trips LinkPayload selections through
 *    LinkPayload::fromArray()->toArray() at write time, which would strip
 *    every identity key TwitchConnect just fetched down to username/url.
 *    FeedPayload stores the selection verbatim and shapes reads (name/
 *    thumbnail/description/followers survive; the stored-only detection keys
 *    — socialLinks, the live block — stay off the wire).
 *  - everything else is the link-only shape verbatim: LinkConnectionResource,
 *    the Streaming category override (convergence-phases §1.2 — the
 *    dashboard grouping must not move), the url field + max:120 rule, the
 *    LinkOnly route archetype, refresh via the ingest lane only (no legacy
 *    fetch strategy, refreshable(false)).
 */
final class TwitchBinding
{
    public static function configure(PlatformDescriptor $d): void
    {
        $d->category(PlatformCategory::Streaming)
            ->resource(LinkConnectionResource::class)
            ->payload(FeedPayload::class)
            ->refreshable(false)
            // A CLOSURE, never an instance: descriptors are built at boot on
            // every request, and resolving a strategy eagerly there is the
            // trap DerivedDescriptorFactory's own comments spell out.
            ->connect(fn () => new TwitchConnect(app(TwitchScraper::class)), 'Enter your Twitch channel (twitch.tv/yourname).')
            ->connectInput('url', ['required', 'string', 'max:120'])
            ->routes(PlatformRouteShape::LinkOnly);
    }
}
