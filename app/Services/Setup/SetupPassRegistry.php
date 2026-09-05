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
        'platforms.ordering' => ['online-ordering', 'reservations', 'booking', 'ordering'],
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

    /**
     * Pass → the build-progress stages whose open 'started' row means not
     * ready (any one of them open holds the pass).
     *
     * The platforms passes wait on LISTING and WEBSITE too (2026-09-05, st_ali
     * retest #2): the Google listing pull seeds the socials it lists and the
     * website scan seeds the ones the site links, and both START before the
     * signup claim — but the first `platforms` STARTED row is only written
     * when the link-page scan begins, ~40s later. In that window every
     * platforms pass read ready with nothing in it, the walk rendered an
     * empty "Everything we found" step, and the poll stopped.
     */
    public const READY_STAGES = [
        // STAGE_LISTING is a real, separate stage (GoogleBusinessEnrichJob
        // pulling reviews/photos for a listing you already picked) — not the
        // one this PASS's own readiness should track. The pass's job is
        // "have we finished trying to find you a workplace", which is
        // STAGE_WORKPLACE (InstagramSourceGenerator/BioMentionChainsJob's
        // bio-mention candidate search feeding site.workplace_candidates).
        // Pointing this at the wrong-but-real 'listing' stage meant
        // openStages()['listing'] was never set by the search that actually
        // gates this pass, so it reported ready:true from the very first
        // poll, before the search had even run. That both hid the loading
        // skeleton the pass otherwise gets (matching the platforms.* passes)
        // and let the payload's overall `busy` flag go false early, stopping
        // the dashboard's poll before a match could ever arrive on screen
        // (2026-09-05, squeakprobarber retest).
        'listing' => ['workplace'],
        'menu' => ['menu'],
        'media' => ['media'],
        'items.shop' => ['shop'],
        'platforms.booking' => ['platforms', 'listing', 'website'],
        'platforms.ordering' => ['platforms', 'listing', 'website'],
        'platforms.events' => ['platforms', 'listing', 'website'],
        'platforms.stores' => ['platforms', 'listing', 'website'],
        'platforms.watch' => ['platforms', 'listing', 'website'],
        'platforms.listen' => ['platforms', 'listing', 'website'],
        'platforms.social' => ['platforms', 'listing', 'website'],
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
