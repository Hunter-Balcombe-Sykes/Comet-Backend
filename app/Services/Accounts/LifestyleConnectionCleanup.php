<?php

namespace App\Services\Accounts;

use App\Enums\SitepageId;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\PublicSite\SitepageDataResolverService;

/**
 * Soft-deletes a Business account's "lifestyle" platform connections — the
 * Listen / Community platforms whose sitepage page is STANDARD_ONLY
 * (Listen / Strava / Skool), which Business Partna accounts don't offer.
 *
 * Those integration groups are hidden from the business dashboard (Partna-
 * Frontend HIDDEN_GROUPS), so such a connection is un-removable there — an
 * orphan. `presentPageIds` already stops it advertising a page; this clears the
 * lingering data. Run on the partna→business switch (UserObserver) and via a
 * one-off command for existing orphans. Shop is deliberately excluded (Business
 * keeps Shop, managed via the Products page).
 */
class LifestyleConnectionCleanup
{
    /**
     * Platforms whose sitepage page is STANDARD_ONLY (partna-only). Derived from
     * the resolver's platform→page map so it can never drift from the presence
     * gate that hides these pages for business accounts.
     *
     * @return list<string>
     */
    public static function lifestylePlatforms(): array
    {
        return array_keys(array_filter(
            SitepageDataResolverService::PLATFORM_TO_PAGE,
            static fn (string $page): bool => in_array($page, SitepageId::STANDARD_ONLY, true),
        ));
    }

    /**
     * Soft-delete $user's non-deleted lifestyle connections — but ONLY when the
     * account can't use lifestyle pages (Business). No-op for standard accounts,
     * so it is always safe to call. Returns the number removed (or, when
     * $dryRun, the number that WOULD be removed).
     */
    public function forUser(User $user, bool $dryRun = false): int
    {
        if (AccountCapabilities::for($user)->can_use_lifestyle_pages) {
            return 0;
        }

        $query = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->whereIn('platform', self::lifestylePlatforms());

        if ($dryRun) {
            return $query->count();
        }

        // Delete per-model (not a mass ->delete()) so each soft-delete fires its
        // observers / cache invalidation, same as a dashboard disconnect.
        $connections = $query->get();
        foreach ($connections as $connection) {
            $connection->delete();
        }

        return $connections->count();
    }
}
