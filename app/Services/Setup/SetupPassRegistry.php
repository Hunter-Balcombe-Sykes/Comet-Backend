<?php

namespace App\Services\Setup;

use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;

/**
 * The setup dialog's pass vocabulary (A.9, wire §2). Order is the dialog's
 * order; every platforms.* pass is always present (U14 — the full dialog
 * always runs); item passes are pruned by SetupPayload when they would be
 * empty (the server omits the pass; the client never invents one).
 */
class SetupPassRegistry
{
    /** Pass → the connect roster categories (PlatformCategory) it draws from. */
    public const GROUP_CATEGORIES = [
        'platforms.booking' => ['booking'],
        'platforms.ordering' => ['online-ordering', 'reservations', 'booking'],
        'platforms.events' => ['events'],
        'platforms.stores' => ['shop'],
        'platforms.watch' => ['streaming'],
        'platforms.listen' => ['music'],
        'platforms.social' => ['social', 'content', 'education', 'business'],
    ];

    /**
     * The content pool behind an items.* pass — the pool IS the key's suffix
     * ('items.watch' → 'watch'). Derived, not tabled: a literal map here reads
     * as a surface→routing-class table to RoutingClassSingleLaneTest's guard.
     */
    public static function itemPool(string $key): ?string
    {
        return str_starts_with($key, 'items.') ? substr($key, strlen('items.')) : null;
    }

    /** Pass → the build-progress stage whose open 'started' row means not ready. */
    public const READY_STAGES = [
        'listing' => 'listing',
        'menu' => 'menu',
        'media' => 'media',
        'items.shop' => 'shop',
        'platforms.booking' => 'platforms',
        'platforms.ordering' => 'platforms',
        'platforms.events' => 'platforms',
        'platforms.stores' => 'platforms',
        'platforms.watch' => 'platforms',
        'platforms.listen' => 'platforms',
        'platforms.social' => 'platforms',
    ];

    /** @return list<string> */
    public static function keysFor(User $user): array
    {
        $caps = AccountCapabilities::for($user);

        if (! $caps->workplace_brand_is_site_identity) {
            return [
                'listing',
                'platforms.booking', 'platforms.events', 'platforms.stores',
                'platforms.watch', 'platforms.listen', 'platforms.social',
                'services',
                'items.watch', 'items.listen', 'items.events', 'items.shop',
                'media', 'links', 'done',
            ];
        }

        return [
            'platforms.ordering', 'platforms.events', 'platforms.stores',
            'platforms.watch', 'platforms.listen', 'platforms.social',
            $caps->can_use_menu ? 'menu' : 'services',
            'items.watch', 'items.listen', 'items.events', 'items.shop',
            'media', 'links', 'logo', 'done',
        ];
    }

    public static function isValidStep(User $user, string $step): bool
    {
        return in_array($step, self::keysFor($user), true);
    }
}
