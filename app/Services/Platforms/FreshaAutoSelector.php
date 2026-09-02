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
        private StaffNameMatcher $matcher,
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
                // reportFailure false: the stored slug is EXPECTED to be stale
                // sometimes (Fresha rotates venue slugs); the retry below at
                // the re-resolved slug is the attempt whose failure is news.
                $services = $this->scraper->fetchEmployeeServices($slug, $match['employeeId'], reportFailure: false);
            }

            // Fresha ROTATES venue slugs. The storewide fetch above absorbs that
            // silently (fetchLocation 404s, resolves the current slug, retries),
            // but this leg was handed slugFromUrl() off the STORED url and so
            // fired at the dead slug — no categories, silent degrade to the whole
            // salon's "from" prices for someone the matcher had positively
            // identified. Measured live on dev 2026-08-19: anseo-studio-v0v92jna →
            // anseo-studio-melbourne-140a-chapel-street-w8ajp04r, matchTier
            // 'first-exact' with mode 'storewide'.
            //
            // Re-resolve and retry ONCE. Not a loop, and not on the happy path:
            // the overwhelming majority of venues never move, and they must not
            // pay an extra outbound request. Deliberately independent of
            // lastResolvedSlug() — that is per-scraper-instance state this class
            // does not share (no singleton binding), and the auto path's menu
            // cache means fetchMenu may not have run at all this request.
            if (($services === null || $services === []) && $slug !== null) {
                $current = $this->scraper->resolveCurrentSlug($url);
                if ($current !== null && $current !== $slug) {
                    Log::info('fresha.auto_selection.slug_retry', ['from' => $slug, 'to' => $current]);
                    $services = $this->scraper->fetchEmployeeServices($current, $match['employeeId']);
                }
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
                return array_filter([
                    'employeeId' => $employeeId,
                    'displayName' => (string) ($member['displayName'] ?? ''),
                    // T23b: the member's own Fresha star rating survives the
                    // AUTO path too (the manual picker always kept it), so an
                    // employee-mode partna can wear their own stars.
                    'rating' => isset($member['rating']) && is_numeric($member['rating']) ? (float) $member['rating'] : null,
                ], static fn ($v) => $v !== null);
            }
        }

        return null;
    }
}
