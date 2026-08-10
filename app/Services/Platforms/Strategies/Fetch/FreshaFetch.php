<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\FreshaAutoSelector;
use App\Services\Platforms\FreshaScraper;
use App\Services\Platforms\FreshaServiceProjector;
use App\Services\Platforms\Payloads\SelectionPayload;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use App\Services\Site\AdvisoryLockTimeoutException;

// Scheduled Fresha refresh: re-scrapes the saved selection's service menu so
// prices/durations/new services stay current without the user re-picking.
// Mirrors saveSelection()'s server-authoritative re-scrape exactly:
//   storewide  → whole-location menu
//   employee   → booking-GraphQL per-employee menu, falling back to the
//                whole-location menu when the pinned hash/version rotated
// hiddenServiceIds are pruned to ids that still exist (never drift stale);
// everything else in the stored blob rides through VERBATIM (FreshaSelection
// philosophy — the public payload never gains canonical-null keys).
// A connection with no saved selection 304s; an unreachable/empty menu
// throws unavailable so a transient scrape failure never wipes a selection.
final readonly class FreshaFetch implements FetchStrategy
{
    public function __construct(
        private FreshaScraper $scraper,
        private FreshaServiceProjector $projector,
        private FreshaAutoSelector $autoSelector,
    ) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = SelectionPayload::fromArray($connection->payload ?? []);
        $url = $payload->url;
        $selection = $payload->selection?->toArray();

        if (! $url) {
            throw new FetchNotModifiedException('fresha');
        }

        // SELF-HEAL: a selection-less row is normally "waiting for the user's
        // picker" and correctly 304s. An AUTO row has no picker and no human —
        // if its ConnectFetchJob failed, markTerminal left last_refreshed_at
        // unset, so this sweep picks the row up, 304s it, and
        // PlatformRefresher::recordNotModified writes it back to 'ok'. The row
        // then looks healthy, is serviceless, and is never re-fetched. Repair it
        // here instead. connectMode survives on failure precisely because
        // markTerminal never touches payload.
        if (! is_array($selection) && ($connection->payload['connectMode'] ?? null) === 'auto') {
            $user = User::query()->find($connection->user_id);
            if ($user === null) {
                throw new FetchNotModifiedException('fresha');
            }

            $menu = $this->scraper->fetchMenu($url);
            if ($menu['services'] === []) {
                throw new FetchUnavailableException('fresha_no_services');
            }

            $chosen = $this->autoSelector->select($user, $menu, $url);
            $next = $connection->payload;
            unset($next['connectMode']);

            return [...$next, 'url' => $url, 'selection' => $chosen['selection'],
                'matchTier' => $chosen['matchTier'], 'raw' => ['services' => $chosen['raw']]];
        }

        if (! is_array($selection)) {
            throw new FetchNotModifiedException('fresha');
        }

        $employeeId = is_array($selection['employee'] ?? null)
            ? (string) ($selection['employee']['employeeId'] ?? '')
            : '';
        $isEmployeeMode = ($selection['mode'] ?? null) === 'employee' && $employeeId !== '';

        $storeName = $selection['storeName'] ?? null;
        $services = null;

        if ($isEmployeeMode) {
            $slug = $this->scraper->slugFromUrl($url);
            $services = $slug ? $this->scraper->fetchEmployeeServices($slug, $employeeId) : null;
        }

        if ($services === null) {
            // Storewide mode, or the per-employee GraphQL fell back.
            $location = $this->scraper->fetchLocation($url);
            $services = $this->scraper->extractServices($location);
            $storeName = $this->scraper->extractStoreName($location) ?? $storeName;
        }

        if ($services === []) {
            throw new FetchUnavailableException('fresha_no_services');
        }

        // Prune hidden ids that no longer exist in the refreshed menu.
        $serviceIds = array_map(static fn (array $s): string => (string) $s['serviceId'], $services);
        $hidden = array_values(array_filter(
            is_array($selection['hiddenServiceIds'] ?? null) ? $selection['hiddenServiceIds'] : [],
            static fn ($id): bool => is_string($id) && in_array($id, $serviceIds, true),
        ));

        // Project the refreshed scrape into site.services (dedup by serviceId;
        // detached/suppressed rows honoured) and store the EFFECTIVE list; the
        // raw scrape persists privately at payload.raw (the revert source).
        // The kept hidden list seeds is_active on first-time projections only.
        $user = User::query()->find($connection->user_id);
        if ($user === null) {
            throw new FetchNotModifiedException('fresha');
        }
        try {
            $projected = $this->projector->sync($user, $services, $hidden);
        } catch (AdvisoryLockTimeoutException) {
            // Whole-branch review fix: mirrors FreshaConnectFetch::fetchStorewide()'s
            // identical catch — sync()'s pg_advisory_xact_lock (services:{user_id})
            // is a RuntimeException subtree PlatformRefresher::refresh() doesn't
            // know about (it only catches FetchNotModified/FetchShape/
            // FetchUnavailable), so left uncaught it escaped to
            // RefreshConnectionJob's handle(), which has no pending-guard of its
            // own to fall back on. Rethrowing as FetchUnavailableException routes
            // it back through the SAME recordFailure('unavailable') +
            // PlatformHealthNotifier path every other transient Fresha miss gets,
            // on the FIRST attempt, instead of relying on the job's $maxExceptions=3
            // generic-exception ceiling (see RefreshConnectionJob::failed()) — that
            // ceiling does eventually bump consecutive_failures too, but only after
            // three wasted retries (each re-waiting on a lock that's likely still
            // contended) and without ever calling the health notifier.
            throw new FetchUnavailableException('fresha_services_lock');
        }

        $inner = [
            ...$selection,
            'storeName' => $storeName,
            'services' => $projected['services'],
            'hiddenServiceIds' => $projected['hiddenServiceIds'],
        ];

        $storedRaw = ($connection->payload ?? [])['raw']['services'] ?? null;
        if ($inner == $selection && $storedRaw == $projected['raw']) {
            // Menu unchanged — quiet bookkeeping, no edge purge. (The projection
            // upserts above are idempotent, so re-running them costs nothing.)
            throw new FetchNotModifiedException('fresha');
        }

        return ['url' => $url, 'selection' => $inner, 'raw' => ['services' => $projected['raw']]];
    }
}
