<?php

namespace App\Services\Platforms\Registry\Bindings;

use App\Http\Resources\Platforms\TileConnectionResource;
use App\Services\Platforms\Payloads\SelectionPayload;
use App\Services\Platforms\Registry\PlatformCategory;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Registry\PlatformRouteShape;
use App\Services\Platforms\Strategies\Detect\HostMatch;

/**
 * PD-retirement P5 (2026-08-27): Square booking's full behavioural
 * contract, moved VERBATIM from the retired hand-written registration.
 * Connect stays bespoke (SquareController — routes(Bespoke), no
 * ConnectStrategy, no capability gate here: square gates itself inline,
 * like fresha). Not refreshable in the registry sense and no fetch
 * strategy — since 2026-09-02 the services come from the square_book
 * ingest connector, so the dashboard's refresh button reaches
 * RefreshController::refreshIngestOnly() and re-pulls that source.
 */
final class SquareBinding
{
    public static function configure(PlatformDescriptor $d): void
    {
        $d->category(PlatformCategory::Booking)
            ->resource(TileConnectionResource::class)
            ->payload(SelectionPayload::class)
            ->connectInput('url', ['required', 'string', 'max:1000', 'regex:#^https?://([a-z0-9-]+\.)*(squareup\.com|square\.site)(/[^\s]*)?$#i'], ['url.regex' => 'Enter a valid Square booking link (a squareup.com or square.site URL).'], true)
            // Smart-detect matcher (Plan 6): squareup.com / *.square.site.
            ->detect(new HostMatch('~(^|\.)(squareup\.com|square\.site)$~'))
            ->routes(PlatformRouteShape::Bespoke);
    }
}
