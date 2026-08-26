<?php

namespace App\Services\Platforms\Registry\Bindings;

use App\Http\Resources\Platforms\OpenTableConnectionResource;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\OpenTableService;
use App\Services\Platforms\Payloads\SelectionPayload;
use App\Services\Platforms\Registry\PlatformCategory;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Registry\PlatformRouteShape;
use App\Services\Platforms\Strategies\Connect\OpenTableConnect;
use App\Services\Platforms\Strategies\Detect\ServiceMatch;

/**
 * PD-retirement P5 (2026-08-27): OpenTable's full behavioural contract,
 * moved VERBATIM from the retired hand-written registration. OpenTable
 * keeps its bespoke suggestion() endpoint (routes/api/platforms.php) — it
 * reads across platforms (Google Business), which the generic shape has no
 * seam for; everything else is registry-driven (FOUND-24).
 *
 * Sector-derived gate (2026-07-15): connect() routes through
 * GenericPlatformController → IntegrationConnectionPolicy::connect → the
 * capability predicate, so gating here covers the platform in one place
 * (unlike Fresha/Square, which are bespoke and gate themselves inline).
 *
 * The ServiceMatch closure resolves OpenTableService lazily (app() at
 * match time) — the retired provider block resolved it eagerly at boot,
 * which the registry-build-at-boot rule exists to avoid; detection
 * behaviour is identical.
 */
final class OpentableBinding
{
    public static function configure(PlatformDescriptor $d): void
    {
        $d->category(PlatformCategory::Reservations)
            ->resource(OpenTableConnectionResource::class)
            ->payload(SelectionPayload::class)
            // Connect strategy (FOUND-24) — parse-fail message is the frozen 422 contract.
            ->connect(fn () => new OpenTableConnect(app(OpenTableService::class)), 'Enter an OpenTable restaurant link (opentable.com.au/...).')
            ->connectInput('url', ['required', 'string', 'max:2048'])
            ->requiresCapability(
                static fn (User $user): bool => AccountCapabilities::for($user)->can_use_reservations
            )
            // Reservations: keyless widget delegates to the service's matcher.
            ->detect(new ServiceMatch(fn (string $u) => app(OpenTableService::class)->isOpenTableUrl($u)))
            ->routes(PlatformRouteShape::MultiAccount, null, false);
    }
}
