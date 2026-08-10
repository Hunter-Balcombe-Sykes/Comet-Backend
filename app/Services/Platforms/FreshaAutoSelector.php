<?php

namespace App\Services\Platforms;

use App\Models\Core\User\User;
use Illuminate\Support\Facades\Log;

/**
 * Decides WHOSE Fresha menu an auto-discovered booking link should show, and
 * projects it.
 *
 * A Fresha URL points at a salon, not a person, so a connection with no
 * `selection` is the encoding of "a human still has to choose". The dashboard
 * asks that question with a picker; an auto-routed link has no picker and no
 * human, so this unit answers it: match the account holder against the scraped
 * team, else fall back to the whole-store menu.
 *
 * Extracted rather than inlined because TWO strategies need it —
 * FreshaConnectFetch (the immediate path) and FreshaFetch (the scheduled
 * self-heal backstop) — and they have different constructor dependencies. A
 * copy in each would be the drift risk we avoided by not forking fetchStorewide.
 *
 * Every failure after the caller's single fetchMenu() degrades to a WORKING
 * storewide selection, never an error: that first scrape already returned the
 * whole-location services, so there is nothing left to fail on.
 */
final readonly class FreshaAutoSelector
{
    public function __construct(
        private FreshaScraper $scraper,
        private FreshaStaffMatcher $matcher,
        private FreshaServiceProjector $projector,
    ) {}

    /**
     * @param  array{storeName:?string, team:list<array<string,mixed>>, services:list<array<string,mixed>>}  $menu
     * @return array{selection: array<string,mixed>, matchTier: ?string, raw: list<array<string,mixed>>}
     */
    public function select(User $user, array $menu, string $url): array
    {
        $match = $this->matcher->matchWithTier($user, $menu['team']);
        $services = null;
        $employee = null;

        if ($match['employeeId'] !== null) {
            // slugFromUrl only understands /a/<slug>; a null slug means the stored
            // URL was never canonicalised, so the employee leg is impossible.
            // Guarded rather than passed through — fetchEmployeeServices(null) is
            // a TypeError, not a miss.
            $slug = $this->scraper->slugFromUrl($url);
            if ($slug !== null) {
                $services = $this->scraper->fetchEmployeeServices($slug, $match['employeeId']);
            }

            if ($services !== null && $services !== []) {
                $employee = $this->employeeFrom($menu['team'], $match['employeeId']);
            } else {
                $services = null;
            }
        }

        $mode = $services === null ? 'storewide' : 'employee';
        $services ??= $menu['services'];

        $projected = $this->projector->sync($user, $services);

        Log::info('fresha.auto_selection', [
            'user_id' => (string) $user->id,
            'mode' => $mode,
            'match_tier' => $match['tier'],
            'service_count' => count($projected['services']),
        ]);

        // services_max caps LISTING only — past it the dashboard truncates and the
        // owner cannot reach the tail to delete it. Storewide is the common outcome
        // for non-person handles, so a big salon lands here with no human in the loop.
        $cap = (int) config('partna.limits.pagination.services_max', 500);
        if (count($projected['services']) > $cap) {
            Log::warning('fresha.auto_selection.exceeds_listing_cap', [
                'user_id' => (string) $user->id,
                'projected' => count($projected['services']),
                'cap' => $cap,
            ]);
        }

        return [
            'selection' => [
                'url' => $url,
                'storeName' => $menu['storeName'],
                'mode' => $mode,
                // NESTED, not a flat id: FreshaFetch reads
                // $selection['employee']['employeeId'] and gates on mode === 'employee'.
                // A flat value makes every later refresh silently degrade to storewide.
                'employee' => $employee,
                'services' => $projected['services'],
                'hiddenServiceIds' => $projected['hiddenServiceIds'],
            ],
            'matchTier' => $match['tier'],
            'raw' => $projected['raw'],
        ];
    }

    /** @param  list<array<string,mixed>>  $team */
    private function employeeFrom(array $team, string $employeeId): ?array
    {
        foreach ($team as $member) {
            if ((string) ($member['employeeId'] ?? '') === $employeeId) {
                return ['employeeId' => $employeeId, 'displayName' => (string) ($member['displayName'] ?? '')];
            }
        }

        return null;
    }
}
