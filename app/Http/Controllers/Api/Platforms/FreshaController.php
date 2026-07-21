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
use App\Models\Core\User\Service;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\FreshaScraper;
use App\Services\Platforms\FreshaServiceProjector;
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

    public function __construct(
        private readonly FreshaScraper $scraper,
        private readonly FreshaServiceProjector $projector,
    ) {}

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

        // Sector-derived gate (2026-07-15): a food business books via
        // Reservations, not Booking — Fresha is a bespoke connect flow (never
        // routes through GenericPlatformController), so it needs its own check.
        if (! AccountCapabilities::for($user)->can_use_booking) {
            return $this->error('Booking is not available for your account.', 403);
        }

        // Fresha + Square are mutually exclusive booking providers (XOR).
        if ($this->hasConflictingConnection($user, Platform::Square->value)) {
            return $this->error('Disconnect Square before connecting Fresha — only one booking provider can be active at a time.', 409);
        }

        $validated = $request->validated();

        $url = $this->scraper->stripLocale($validated['url']);
        $menu = $this->scraper->fetchMenu($url);

        return $this->withConnectionLock($user, function () use ($user, $url, $menu): JsonResponse {
            // Business Partna accounts book storewide — no team-member picker. Finalise
            // the selection here so connect() completes setup in one step; the dashboard
            // sees mode='storewide' and skips the picker. Capability-gated so the
            // account_type read stays inside AccountCapabilities.
            if (AccountCapabilities::for($user)->can_book_storewide) {
                // Project the scrape into site.services rows (deduped by serviceId;
                // owner edits + suppressions honoured) and store the EFFECTIVE list
                // in the public selection; the raw scrape lands at payload.raw
                // (private — the public allowlist ships only url+selection).
                $projected = $this->projector->sync($user, $menu['services']);
                $selection = [
                    'url' => $url,
                    'storeName' => $menu['storeName'],
                    'mode' => 'storewide',
                    'employee' => null,
                    'services' => $projected['services'],
                    'hiddenServiceIds' => $projected['hiddenServiceIds'],
                ];
                $this->writeConnection($user, [
                    'url' => $url,
                    'selection' => $selection,
                    'raw' => ['services' => $projected['raw']],
                ]);

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
            // (selection null) carries forward as null, exactly as before. The stored
            // raw scrape (revert source for detached projections) rides along too.
            $existingPayload = $this->readConnection($user) ?? [];
            $existing = SelectionPayload::fromArray($existingPayload);
            $carriedRaw = is_array($existingPayload['raw'] ?? null) ? $existingPayload['raw'] : null;
            $this->writeConnection($user, [
                'url' => $url,
                'selection' => $existing->selection?->toArray(),
                ...($carriedRaw !== null ? ['raw' => $carriedRaw] : []),
            ]);

            return $this->success(['url' => $url, 'mode' => 'team', ...$menu]);
        });
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

        return $this->withConnectionLock($user, function () use ($user, $url, $location, $employee, $services): JsonResponse {
            // Preserve previously hidden services, dropping ids that no longer exist
            // in the refreshed menu so the hidden list never drifts stale. The kept
            // list seeds is_active on first-time projections; projected rows then
            // own the hidden state (compose() re-derives the list from is_active).
            $serviceIds = array_map(static fn (array $s): string => (string) $s['serviceId'], $services);
            $existing = SelectionPayload::fromArray($this->readConnection($user) ?? []);
            $hidden = array_values(array_filter(
                $existing->selection?->hiddenServiceIds() ?? [],
                static fn ($id): bool => in_array($id, $serviceIds, true),
            ));

            $projected = $this->projector->sync($user, $services, $hidden);
            $selection = [
                'url' => $url,
                'storeName' => $this->scraper->extractStoreName($location),
                'mode' => 'employee',
                'employee' => $employee,
                'services' => $projected['services'],
                'hiddenServiceIds' => $projected['hiddenServiceIds'],
            ];
            $this->writeConnection($user, [
                'url' => $url,
                'selection' => $selection,
                'raw' => ['services' => $projected['raw']],
            ]);

            return $this->success((new FreshaSelectionResource($selection))->resolve());
        });
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

            // The projection owns the hidden state: flip is_active on the row
            // and let compose() re-derive hiddenServiceIds. Legacy connections
            // (no payload.raw yet — the projection marker) fall through to the
            // verbatim hidden-list write below, exactly as before.
            $storedPayload = $this->readConnection($user) ?? [];
            $rawServices = $storedPayload['raw']['services'] ?? null;
            if (is_array($rawServices)) {
                Service::query()
                    ->where('user_id', $user->id)
                    ->where('source', 'fresha')
                    ->where('external_id', $validated['serviceId'])
                    ->update(['is_active' => ! $validated['hidden']]);

                $composed = $this->projector->compose($user, $rawServices);
                $inner = [
                    ...$selection->toArray(),
                    'services' => $composed['services'],
                    'hiddenServiceIds' => $composed['hiddenServiceIds'],
                ];
                $this->writeConnection($user, [
                    'url' => $payload->url,
                    'selection' => $inner,
                    'raw' => ['services' => $rawServices],
                ]);

                return $this->success((new FreshaSelectionResource($inner))->resolve());
            }

            // Write back the inner blob VERBATIM with only hiddenServiceIds replaced —
            // FreshaSelection::toArray() returns the stored blob unchanged, so the
            // public (verbatim) selection payload never gains a canonical-null key.
            $inner = [...$selection->toArray(), 'hiddenServiceIds' => $hidden];
            $this->writeConnection($user, ['url' => $payload->url, 'selection' => $inner]);

            return $this->success((new FreshaSelectionResource($inner))->resolve());
        });
    }

    // DELETE /api/platforms/fresha — clear the saved URL and selection. The
    // synced projections soft-delete with deleted_origin='sync' so a later
    // reconnect restores them (curation intact); owner-suppressed rows
    // (deleted_origin='user') and detached (is_manual) rows keep their state —
    // a detached row is owner content and survives the disconnect live.
    public function forget(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        // The booking-XOR lock (not the per-platform withConnectionLock) —
        // this delete has exactly one owner: BookingController::clearBooking()
        // also deletes the fresha connection row as part of the single-slot
        // booking-family clear, under this same cross-platform key. Serializing
        // both on the per-platform 'fresha' key would leave clearBooking()
        // free to race this delete.
        return $this->withCrossPlatformLock(CacheKeyGenerator::bookingXorLock((string) $user->id), function () use ($user) {
            $this->forgetConnection($user);

            $synced = Service::query()
                ->where('user_id', $user->id)
                ->where('source', 'fresha')
                ->where('is_manual', false)
                ->get();
            foreach ($synced as $row) {
                $row->deleted_origin = 'sync';
                $row->saveQuietly();
                $row->delete();
            }

            return $this->success(['url' => null, 'selection' => null]);
        });
    }
}
