<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Jobs\Platforms\EnrichLinkCardJob;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\LinkCardScraper;
use App\Services\Platforms\OpenTableService;
use App\Services\Platforms\Payloads\CardPayload;
use App\Services\Platforms\Payloads\GoogleBusinessPayload;
use App\Services\Platforms\Payloads\SelectionPayload;
use App\Services\Platforms\ProviderDetector;
use App\Services\Platforms\Registry\Platform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Reservations category — a single-slot smart-detect card. The user pastes a
// URL; we detect the provider:
//   - opentable → the keyless reservation widget (frontend calls POST /opentable/connect)
//   - custom    → an unrecognised link, stored here as a branded card
// OpenTable keeps storing under its own 'opentable' platform key; this
// 'reservations' row holds ONLY the custom fallback. Single-slot.
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
    ) {}

    protected function platform(): string
    {
        return Platform::Reservations->value;
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

        // Unknown → custom fallback. Minimal card now; enrich off-thread (JOB-1).
        $url = $this->scraper->normalizeUrl($validated['url']);
        if (! $url) {
            return $this->error('Enter a valid link (https://...).', 422);
        }
        $meta = $this->scraper->minimalCard($url);

        // Cross-platform XOR lock (not the per-platform withConnectionLock) —
        // clearReservations() below can delete opentable/resdiary/nowbookit
        // rows, which a per-platform 'reservations' key can't mutually
        // exclude against those controllers. The EnrichLinkCardJob dispatch is
        // deliberately OUTSIDE the lock: under QUEUE_CONNECTION=sync it runs
        // inline and can take seconds, which would hold the lock far past its
        // 10s TTL (rule #1).
        $response = $this->withCrossPlatformLock(CacheKeyGenerator::reservationsXorLock((string) $user->id), function () use ($user, $meta) {
            $this->clearReservations($user);   // single-slot
            $payload = ['provider' => 'custom', 'source' => 'manual', ...$meta];
            $this->writePendingLinkCard($user, $payload);

            return $this->success([
                'provider' => 'custom',
                'next' => 'custom-saved',
                'status' => 'pending',
                'selection' => $this->shapeCustom($payload),
                'statusUrl' => url('/api/platforms/reservations/detect/status'),
            ], 202);
        });

        if ($response->getStatusCode() === 202) {
            EnrichLinkCardJob::dispatch((string) $user->id, $this->platform(), $this->defaultResourceId(), $url)->afterCommit();
        }

        return $response;
    }

    // GET /api/platforms/reservations/detect/status — poll the custom-card enrichment.
    public function detectStatus(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->linkCardStatusResponse($user, $this->defaultResourceId(), fn () => [
            'selection' => $this->shapeCustom($this->readConnection($user) ?? []),
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

        $custom = CardPayload::fromArray($this->readConnection($user));
        if ($custom->provider() === 'custom') {
            return [
                'connected' => true,
                'provider' => 'custom',
                'name' => $custom->name(),
                'url' => $custom->url(),
                'embedUrl' => null,
            ];
        }

        return ['connected' => false, 'provider' => null, 'name' => null, 'url' => null, 'embedUrl' => null];
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

    /** @return array{provider:string, url:?string, name:?string, favicon:?string, logo:?string} */
    private function shapeCustom(array $payload): array
    {
        return [
            'provider' => 'custom',
            'url' => $payload['url'] ?? null,
            'name' => $payload['name'] ?? null,
            'favicon' => $payload['favicon'] ?? null,
            'logo' => $payload['logo'] ?? null,
        ];
    }
}
