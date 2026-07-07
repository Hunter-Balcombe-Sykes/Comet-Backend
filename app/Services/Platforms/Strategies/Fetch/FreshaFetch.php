<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\FreshaScraper;
use App\Services\Platforms\Payloads\SelectionPayload;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;

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
    public function __construct(private FreshaScraper $scraper) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = SelectionPayload::fromArray($connection->payload ?? []);
        $url = $payload->url;
        $selection = $payload->selection?->toArray();

        if (! $url || ! is_array($selection)) {
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

        $inner = [
            ...$selection,
            'storeName' => $storeName,
            'services' => $services,
            'hiddenServiceIds' => $hidden,
        ];

        if ($inner == $selection) {
            // Menu unchanged — quiet bookkeeping, no edge purge.
            throw new FetchNotModifiedException('fresha');
        }

        return ['url' => $url, 'selection' => $inner];
    }
}
