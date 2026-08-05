<?php

namespace App\Routing;

use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;

/**
 * The one place the booking/reservations/ordering capability match-arms
 * live. PlacementPolicy (record time) and SuggestionApplier (apply time)
 * both call this instead of each carrying their own copy — see #DRIFT-1.
 */
final class RoutingCapabilityGate
{
    /** The sanctioned capability read for a routing_class — never a raw account_type branch. */
    public static function denialFor(User $user, string $routingClass): ?string
    {
        $capabilities = AccountCapabilities::for($user);

        return match ($routingClass) {
            'booking' => $capabilities->can_use_booking ? null : 'booking is not available for this account',
            'reservations' => $capabilities->can_use_reservations ? null : 'reservations are not available for this account',
            'ordering' => $capabilities->can_use_online_ordering ? null : 'online ordering is not available for this account',
            default => null,
        };
    }
}
