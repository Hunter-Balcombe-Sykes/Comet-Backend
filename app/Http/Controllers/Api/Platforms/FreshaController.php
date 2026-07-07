<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\FreshaEmployeeServicesRequest;
use App\Http\Requests\Platforms\PlatformConnectRequest;
use App\Http\Requests\Platforms\SaveFreshaSelectionRequest;
use App\Http\Requests\Platforms\SetFreshaServiceVisibilityRequest;
use App\Http\Resources\Platforms\FreshaSelectionResource;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\FreshaScraper;
use App\Services\Platforms\Payloads\SelectionPayload;
use App\Services\Platforms\Registry\Platform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Test-mode endpoints for the Fresha integration. Saves a Fresha store URL
// globally (single-tenant test cache, no auth) and returns the staff list
// extracted from the page's __NEXT_DATA__ blob.
//
// Approach proven and documented in:
//   ~/Developer/platform link capabilites/fresha.md
//
// Promotion plan: when the test is done, extract scrape logic to
// App\Services\Platforms\FreshaScraper, persist via a platform_connections
// table per user, and wire to /account/platforms in Partna-Frontend.
class FreshaController extends ApiController
{
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;



    public function __construct(private readonly FreshaScraper $scraper) {}

    protected function platform(): string
    {
        return Platform::Fresha->value;
    }

    // The per-user Fresha connection payload is { url, selection } — the connected
    // store URL plus the saved { storeName, employee, services } blob (or null).
    private function freshaUrl(User $user): ?string
    {
        return SelectionPayload::fromArray($this->readConnection($user) ?? [])->url;
    }

    // POST /api/platforms/fresha/connect
    public function connect(PlatformConnectRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        // Fresha + Square are mutually exclusive booking providers (XOR).
        if ($this->hasConflictingConnection($user, Platform::Square->value)) {
            return $this->error('Disconnect Square before connecting Fresha — only one booking provider can be active at a time.', 409);
        }

        $validated = $request->validated();

        $url = $this->scraper->stripLocale($validated['url']);
        $menu = $this->scraper->fetchMenu($url);

        // Business Partna accounts book storewide — no team-member picker. Finalise
        // the selection here so connect() completes setup in one step; the dashboard
        // sees mode='storewide' and skips the picker. Capability-gated so the
        // account_type read stays inside AccountCapabilities.
        if (AccountCapabilities::for($user)->can_book_storewide) {
            $selection = [
                'url' => $url,
                'storeName' => $menu['storeName'],
                'mode' => 'storewide',
                'employee' => null,
                'services' => $menu['services'],
                'hiddenServiceIds' => [],
            ];
            $this->writeConnection($user, ['url' => $url, 'selection' => $selection]);

            return $this->success([
                'url' => $url,
                'mode' => 'storewide',
                'selection' => (new FreshaSelectionResource($selection))->resolve(),
            ]);
        }

        // Individual: preserve any existing selection (re-connecting the same store
        // keeps the saved team member); the dashboard re-picks via saveSelection.
        // FreshaSelection::toArray() returns the stored inner blob verbatim, so a
        // canonical stored selection round-trips byte-identically; a pending row
        // (selection null) carries forward as null, exactly as before.
        $existing = SelectionPayload::fromArray($this->readConnection($user) ?? []);
        $this->writeConnection($user, [
            'url' => $url,
            'selection' => $existing->selection?->toArray(),
        ]);

        return $this->success(['url' => $url, 'mode' => 'team', ...$menu]);
    }

    // GET /api/platforms/fresha/team — team + services for the saved URL.
    public function team(Request $request): JsonResponse
    {
        $url = $this->freshaUrl($this->currentUser($request));
        if (! $url) {
            return $this->error('No Fresha URL connected yet. POST one to /connect first.', 404);
        }

        return $this->success(['url' => $url, ...$this->scraper->fetchMenu($url)]);
    }

    // GET /api/platforms/fresha/url — peek at what's saved without re-scraping.
    public function show(Request $request): JsonResponse
    {
        return $this->success(['url' => $this->freshaUrl($this->currentUser($request))]);
    }

    // POST /api/platforms/fresha/selection — save which team member is "you"
    // plus the current service menu. Re-scrapes the saved URL so the stored
    // blob is server-authoritative (not whatever the client happened to hold).
    public function saveSelection(SaveFreshaSelectionRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $validated = $request->validated();

        $url = $this->freshaUrl($user);
        if (! $url) {
            return $this->error('No Fresha URL saved yet. Save one first.', 404);
        }

        $location = $this->scraper->fetchLocation($url);
        $employee = collect($this->scraper->extractTeam($location))->firstWhere('employeeId', $validated['employeeId']);
        if (! $employee) {
            return $this->error('That team member was not found on the saved Fresha page.', 404);
        }

        // Per-employee services via the booking GraphQL; fall back to the whole
        // location menu if that call fails (hash/version rotated).
        $slug = $this->scraper->slugFromUrl($url);
        $services = ($slug ? $this->scraper->fetchEmployeeServices($slug, $validated['employeeId']) : null)
            ?? $this->scraper->extractServices($location);

        // Preserve previously hidden services, dropping ids that no longer exist
        // in the refreshed menu so the hidden list never drifts stale.
        $serviceIds = array_map(static fn (array $s): string => (string) $s['serviceId'], $services);
        $existing = SelectionPayload::fromArray($this->readConnection($user) ?? []);
        $hidden = array_values(array_filter(
            $existing->selection?->hiddenServiceIds() ?? [],
            static fn ($id): bool => in_array($id, $serviceIds, true),
        ));

        $selection = [
            'url' => $url,
            'storeName' => $this->scraper->extractStoreName($location),
            'mode' => 'employee',
            'employee' => $employee,
            'services' => $services,
            'hiddenServiceIds' => $hidden,
        ];
        $this->writeConnection($user, ['url' => $url, 'selection' => $selection]);

        return $this->success((new FreshaSelectionResource($selection))->resolve());
    }

    // GET /api/platforms/fresha/employee-services?employeeId=X — the per-employee
    // menu for the dashboard preview (before saving). Same fallback as above.
    public function employeeServices(FreshaEmployeeServicesRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $url = $this->freshaUrl($this->currentUser($request));
        if (! $url) {
            return $this->error('No Fresha URL saved yet. Save one first.', 404);
        }

        $slug = $this->scraper->slugFromUrl($url);
        $services = ($slug ? $this->scraper->fetchEmployeeServices($slug, $validated['employeeId']) : null)
            ?? $this->scraper->extractServices($this->scraper->fetchLocation($url));

        return $this->success(['services' => $services]);
    }

    // GET /api/platforms/fresha/selection — read the saved selection (partna-pages
    // reads this; the dashboard reads it to restore its "saved" state on load).
    public function selection(Request $request): JsonResponse
    {
        $payload = SelectionPayload::fromArray($this->readConnection($this->currentUser($request)) ?? []);

        return $this->success([
            'selection' => $payload->selection !== null
                ? (new FreshaSelectionResource($payload->selection->toArray()))->resolve()
                : null,
            // Pending (Google-seeded) connections have a url but no selection — the
            // dashboard uses it to show "Finish setup" and open the picker.
            'url' => $payload->url,
        ]);
    }

    // POST /api/platforms/fresha/service-visibility — show/hide one service on the
    // public page. Toggles the service id in the saved selection's hiddenServiceIds
    // list; only ids present in the saved menu are accepted. Returns the updated
    // selection so the dashboard swaps state in place. (partna-pages filters the
    // services list by hiddenServiceIds at render time — the public payload is
    // shipped verbatim, so the hidden list is curation, not a privacy boundary.)
    public function setServiceVisibility(SetFreshaServiceVisibilityRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $validated = $request->validated();

        return $this->withConnectionLock($user, function () use ($user, $validated): JsonResponse {
            $payload = SelectionPayload::fromArray($this->readConnection($user) ?? []);
            $selection = $payload->selection;
            if ($selection === null) {
                return $this->error('No Fresha selection saved yet.', 404);
            }

            // Only toggle services that exist in the saved menu.
            $serviceIds = array_map(
                static fn ($s) => is_array($s) ? ($s['serviceId'] ?? null) : null,
                $selection->services(),
            );
            if (! in_array($validated['serviceId'], $serviceIds, true)) {
                return $this->error('That service is not part of the saved Fresha menu.', 404);
            }

            $hidden = array_values(array_filter(
                $selection->hiddenServiceIds(),
                static fn ($id): bool => is_string($id),
            ));

            if ($validated['hidden']) {
                $hidden = array_values(array_unique([...$hidden, $validated['serviceId']]));
            } else {
                $hidden = array_values(array_filter($hidden, static fn ($id): bool => $id !== $validated['serviceId']));
            }

            // Write back the inner blob VERBATIM with only hiddenServiceIds replaced —
            // FreshaSelection::toArray() returns the stored blob unchanged, so the
            // public (verbatim) selection payload never gains a canonical-null key.
            $inner = [...$selection->toArray(), 'hiddenServiceIds' => $hidden];
            $this->writeConnection($user, ['url' => $payload->url, 'selection' => $inner]);

            return $this->success((new FreshaSelectionResource($inner))->resolve());
        });
    }

    // DELETE /api/platforms/fresha — clear the saved URL and selection.
    public function forget(Request $request): JsonResponse
    {
        $this->forgetConnection($this->currentUser($request));

        return $this->success(['url' => null, 'selection' => null]);
    }

}
