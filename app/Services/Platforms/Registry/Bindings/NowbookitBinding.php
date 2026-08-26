<?php

namespace App\Services\Platforms\Registry\Bindings;

use App\Http\Resources\Platforms\NowBookitConnectionResource;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\NowBookitService;
use App\Services\Platforms\Payloads\SelectionPayload;
use App\Services\Platforms\Registry\PlatformCategory;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Registry\PlatformRouteShape;
use App\Services\Platforms\Strategies\Connect\NowBookitConnect;
use App\Services\Platforms\Strategies\Detect\ServiceMatch;

/**
 * PD-retirement P5 (2026-08-27): NowBookit's full behavioural contract,
 * moved VERBATIM from the retired hand-written registration. See
 * OpentableBinding for the shared reservations notes (capability gate via
 * the generic connect chain, lazy ServiceMatch resolution).
 */
final class NowbookitBinding
{
    public static function configure(PlatformDescriptor $d): void
    {
        $d->category(PlatformCategory::Reservations)
            ->resource(NowBookitConnectionResource::class)
            ->payload(SelectionPayload::class)
            // Connect strategy (FOUND-24) — parse-fail message is the frozen 422 contract.
            ->connect(fn () => new NowBookitConnect(app(NowBookitService::class)), 'Enter a NowBookit booking link (nowbookit.com/...).')
            ->connectInput('url', ['required', 'string', 'max:2048'])
            // Smart-scoring plan (2026-08-27): the reservations PAGE left the
            // taxonomy, so a reservation widget is a public DESTINATION in its
            // own right — the lander's Reserve action sends visitors straight
            // to this URL (ActionCandidates reads this flag).
            ->destination()
            ->requiresCapability(
                static fn (User $user): bool => AccountCapabilities::for($user)->can_use_reservations
            )
            ->detect(new ServiceMatch(fn (string $u) => app(NowBookitService::class)->isNowBookitUrl($u)))
            ->routes(PlatformRouteShape::MultiAccount, null, false);
    }
}
