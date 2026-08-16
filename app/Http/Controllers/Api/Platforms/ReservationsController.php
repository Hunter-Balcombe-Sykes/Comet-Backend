<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Catalog\LegacyPlatformMap;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Jobs\Content\EnrichPoolLinkJob;
use App\Jobs\Platforms\EnrichLinkCardJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Content\LinkPoolWriter;
use App\Services\Platforms\LinkCardScraper;
use App\Services\Platforms\OpenTableService;
use App\Services\Platforms\Payloads\CardPayload;
use App\Services\Platforms\Payloads\GoogleBusinessPayload;
use App\Services\Platforms\Payloads\SelectionPayload;
use App\Services\Platforms\ProviderDetector;
use App\Services\Platforms\Registry\Platform;
use App\Services\Platforms\WebsiteLinkHarvester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Reservations category — a single-slot smart-detect card. The user pastes a
// URL; we detect the provider:
//   - opentable → the keyless reservation widget (frontend calls POST /opentable/connect)
//   - custom    → everything else, stored here as a branded card
// Single-slot across the whole family.
//
// Convergence Phase 6, mirroring BookingController exactly: the "custom"
// fallback is no longer one shared `partna.reserve_link` row. The link is
// classified against the catalog first — every reservation host the harvester
// knows has its own surface (12/12, verified 2026-08-16) — so a SevenRooms or
// TheFork link becomes that brand's connection. Only a link matching nothing
// becomes a links-pool item under owner ruling 2A.
class ReservationsController extends ApiController
{
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    // Keyless reservation providers — each stores under its own platform key and
    // renders an iframe widget. Reservations is single-slot across the whole family.
    private const KEYLESS_PROVIDERS = [Platform::OpenTable->value, Platform::Resdiary->value, Platform::Nowbookit->value];

    public function __construct(
        private readonly ProviderDetector $detector,
        private readonly LinkCardScraper $scraper,
        private readonly OpenTableService $openTable,
        private readonly WebsiteLinkHarvester $harvester,
        private readonly LinkPoolWriter $pool,
    ) {}

    // The FAMILY key — the lock and FeatureAvailability only.
    protected function platform(): string
    {
        return 'reservations';
    }

    protected function routingClass(): ?string
    {
        return 'reservations';
    }

    // POST /api/platforms/reservations/detect — detect the provider for a URL.
    public function detect(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        // Sector-derived gate (2026-07-15): reservations are food-business-only
        // for a business account (partna keeps them unconditionally). This also
        // covers the keyless-provider redirect below — the actual opentable/
        // resdiary/nowbookit connect is separately gated via PlatformDescriptor::
        // availableFor() (GenericPlatformController::connect), since a client
        // could call those endpoints directly without hitting /detect first.
        if (! AccountCapabilities::for($user)->can_use_reservations) {
            return $this->error('Reservations are not available for your account.', 403);
        }

        $validated = $request->validate(['url' => ['required', 'string', 'max:1000']]);

        $provider = $this->detector->detectFor('reservations', $validated['url']);
        if (in_array($provider, self::KEYLESS_PROVIDERS, true)) {
            return $this->success(['provider' => $provider, 'next' => "{$provider}-connect", 'selection' => null]);
        }

        // Not a keyless widget provider → the card path. Minimal card now;
        // enrich off-thread (JOB-1).
        $url = $this->scraper->normalizeUrl($validated['url']);
        if (! $url) {
            return $this->error('Enter a valid link (https://...).', 422);
        }
        $meta = $this->scraper->minimalCard($url);

        // Classified outside the lock — a pure host match.
        $surface = $this->reservationSurfaceFor($url);

        // Cross-platform XOR lock (not the per-platform withConnectionLock) —
        // clearReservations() below can delete opentable/resdiary/nowbookit
        // rows, which a per-platform 'reservations' key can't mutually
        // exclude against those controllers. The EnrichLinkCardJob dispatch is
        // deliberately OUTSIDE the lock: under QUEUE_CONNECTION=sync it runs
        // inline and can take seconds, which would hold the lock far past its
        // 10s TTL (rule #1).
        $rid = null;
        $response = $this->withCrossPlatformLock(CacheKeyGenerator::reservationsXorLock((string) $user->id), function () use ($user, $url, $meta, $surface, &$rid) {
            $this->clearReservations($user);   // single-slot

            if ($surface === null) {
                // Owner ruling 2A — see BookingController::detect for the full
                // reasoning; this is the same decision on the same shape.
                $this->pool->add($user, $url, $meta['name'] ?? null, $meta['description'] ?? null);

                return $this->success([
                    'provider' => 'custom',
                    'next' => 'link-saved',
                    'routedTo' => ['pool' => LinkPoolWriter::POOL],
                    'selection' => null,
                ], 202);
            }

            $rid = $this->brandResourceId($surface);
            $payload = ['provider' => 'custom', 'source' => 'manual', ...$meta];
            $this->writeBrandCard($user, $surface, $rid, $payload);

            return $this->success([
                'provider' => 'custom',
                'next' => 'custom-saved',
                'status' => 'pending',
                'selection' => $this->shapeCustom(CardPayload::fromArray($payload)),
                'statusUrl' => url('/api/platforms/reservations/detect/status'),
            ], 202);
        });

        if ($response->getStatusCode() === 202) {
            if ($rid === null) {
                EnrichPoolLinkJob::dispatch((string) $user->id, $url)->afterCommit();
            } else {
                EnrichLinkCardJob::dispatch((string) $user->id, $this->platform(), $rid, $url, $surface)->afterCommit();
            }
        }

        return $response;
    }

    // GET /api/platforms/reservations/detect/status — poll the custom-card enrichment.
    public function detectStatus(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        // The row's resource id is the BRAND's now, not the fixed 'reservations'
        // default — looked up rather than assumed. Single-slot makes it unambiguous.
        $row = $this->cardRow($user);
        if ($row === null) {
            return $this->error('Link not found.', 404);
        }

        return $this->linkCardStatusResponse($user, (string) $row->resource_id, fn () => [
            'selection' => $this->shapeCustom(CardPayload::fromArray($row->fresh()?->payload)),
        ]);
    }

    // GET /api/platforms/reservations/status — read-aggregate (opentable | custom).
    public function status(Request $request): JsonResponse
    {
        return $this->success($this->statusFor($this->currentUser($request)));
    }

    // GET /api/platforms/reservations/suggestion — the rid-bearing OpenTable link
    // harvested from the user's Google Business connection (one-click connect).
    // Usually null once Google auto-sync seeds the opentable row directly.
    public function suggestion(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $gb = $user->integrationConnections()->where('platform', Platform::GoogleBusiness->value)->first();
        $suggestion = $gb
            ? $this->openTable->suggestionFromGoogleBusiness(GoogleBusinessPayload::fromArray($gb->payload)->toArray())
            : null;

        return $this->success(['suggestion' => $suggestion]);
    }

    // DELETE /api/platforms/reservations — forget whichever reservation connection exists.
    public function forget(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->withCrossPlatformLock(CacheKeyGenerator::reservationsXorLock((string) $user->id), function () use ($user) {
            $this->clearReservations($user);

            return $this->success($this->statusFor($user));
        });
    }

    // ── internals ────────────────────────────────────────────────

    /**
     * @return array{connected:bool, provider:?string, name:?string, url:?string, embedUrl:?string}
     */
    private function statusFor(User $user): array
    {
        foreach (self::KEYLESS_PROVIDERS as $provider) {
            $row = $user->integrationConnections()->where('platform', $provider)->first();
            if ($row) {
                $sel = SelectionPayload::fromArray($row->payload);

                return [
                    'connected' => true,
                    'provider' => $provider,
                    'name' => $sel->name,
                    'url' => $sel->url,
                    'embedUrl' => $sel->embedUrl,
                ];
            }
        }

        $row = $this->cardRow($user);
        if ($row !== null) {
            $custom = CardPayload::fromArray($row->payload);

            return [
                'connected' => true,
                // Still 'custom' on the wire — see BookingController::statusFor.
                'provider' => 'custom',
                'name' => $custom->name(),
                'url' => $custom->url(),
                'embedUrl' => null,
            ];
        }

        return ['connected' => false, 'provider' => null, 'name' => null, 'url' => null, 'embedUrl' => null];
    }

    /**
     * The single reservation CARD row — any reservations-class connection that
     * is not one of the three keyless widget providers (checked first above,
     * and carrying a selection rather than a card).
     */
    private function cardRow(User $user): ?IntegrationConnection
    {
        return $user->integrationConnections()
            ->where('routing_class', 'reservations')
            ->whereNotIn('platform', self::KEYLESS_PROVIDERS)
            ->orderBy('created_at')
            ->first();
    }

    /**
     * The catalog surface a pasted reservation URL belongs to, or null when
     * nothing recognises it — see BookingController::bookingSurfaceFor.
     */
    private function reservationSurfaceFor(string $url): ?string
    {
        $classified = $this->harvester->classify($url);
        if ($classified === null || $classified['category'] !== 'reservations') {
            return null;
        }

        $platform = $classified['platform'];

        return LegacyPlatformMap::surfaceFor($platform) ?? $platform;
    }

    /**
     * Remove every reservation-family connection (the single-slot guarantee).
     *
     * Convergence Phase 6: keyed on routing_class, not the three KEYLESS_PROVIDERS
     * slugs. Those three enumerated the family only while every other reservation
     * brand shared the retired 'reservations' pseudo-key; now SevenRooms, Resy,
     * TheFork and the rest each carry their own surface, so a slug list silently
     * under-covers and the "single slot" stops being single.
     *
     * routing_class travels with surface_key on every row by construction, so a
     * brand added later is covered without anyone remembering to add it.
     *
     * Dev does hold a user (ollies) with an opentable.reserve row and a SevenRooms
     * row live at once, but do NOT read that as this list under-covering: the old
     * three slugs plus the shared 'reservations' key did span both. It got there
     * through a write path that never calls this method at all — the Google
     * Business harvest seeds a reservation row directly. Widening the axis here
     * does not close that hole; it only stops a NEW one opening as brands split
     * off the shared key.
     */
    private function clearReservations(User $user): void
    {
        foreach ($user->integrationConnections()->where('routing_class', 'reservations')->get() as $row) {
            $row->delete();   // soft-delete; observer purges the sitepage cache
        }
    }

    /**
     * DTO-mediated, not a raw array read: NoUntypedPayloadAccessTest names this
     * file as a migrated read path, and detectStatus now reads a row it looked
     * up rather than a payload the caller already had in hand.
     *
     * @return array{provider:string, url:?string, name:?string, favicon:?string, logo:?string}
     */
    private function shapeCustom(CardPayload $payload): array
    {
        return [
            'provider' => 'custom',
            'url' => $payload->url(),
            'name' => $payload->name(),
            'favicon' => $payload->favicon(),
            'logo' => $payload->logo(),
        ];
    }
}
