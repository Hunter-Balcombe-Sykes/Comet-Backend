<?php

namespace App\Services\Platforms\Registry\Bindings;

use App\Http\Resources\Platforms\ResDiaryConnectionResource;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\Payloads\SelectionPayload;
use App\Services\Platforms\Registry\PlatformCategory;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Registry\PlatformRouteShape;
use App\Services\Platforms\ResDiaryService;
use App\Services\Platforms\Strategies\Connect\ResDiaryConnect;
use App\Services\Platforms\Strategies\Detect\ServiceMatch;

/**
 * PD-retirement P5 (2026-08-27): ResDiary's full behavioural contract,
 * moved VERBATIM from the retired hand-written registration. See
 * OpentableBinding for the shared reservations notes (capability gate via
 * the generic connect chain, lazy ServiceMatch resolution).
 */
final class ResdiaryBinding
{
    public static function configure(PlatformDescriptor $d): void
    {
        $d->category(PlatformCategory::Reservations)
            ->resource(ResDiaryConnectionResource::class)
            ->payload(SelectionPayload::class)
            // Connect strategy (FOUND-24) — parse-fail message is the frozen 422 contract.
            ->connect(fn () => new ResDiaryConnect(app(ResDiaryService::class)), 'Enter a ResDiary booking link (resdiary.com/...).')
            ->connectInput('url', ['required', 'string', 'max:2048'])
            ->requiresCapability(
                static fn (User $user): bool => AccountCapabilities::for($user)->can_use_reservations
            )
            ->detect(new ServiceMatch(fn (string $u) => app(ResDiaryService::class)->isResDiaryUrl($u)))
            ->routes(PlatformRouteShape::MultiAccount, null, false);
    }
}
