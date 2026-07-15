<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Jobs\Platforms\EnrichLinkCardJob;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\LinkCardScraper;
use App\Services\Platforms\Payloads\CardPayload;
use App\Services\Platforms\Payloads\SelectionPayload;
use App\Services\Platforms\ProviderDetector;
use App\Services\Platforms\Registry\Platform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Booking category — a single-slot smart-detect card. The user pastes a URL;
// we detect the provider and tell the dashboard which existing flow to run:
//   - fresha  → the team-member picker (frontend calls POST /fresha/connect then /selection)
//   - square  → store the booking link  (frontend calls POST /square/connect)
//   - custom  → an unrecognised link, stored here as a branded card
// Known providers keep storing under their own platform keys (fresha/square);
// this 'booking' row holds ONLY the custom fallback. Single-slot: the dashboard
// offers no add affordance once any booking provider is connected, so /detect
// is only reached from an empty state in normal use.
class BookingController extends ApiController
{
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    public function __construct(
        private readonly ProviderDetector $detector,
        private readonly LinkCardScraper $scraper,
    ) {}

    protected function platform(): string
    {
        return Platform::Booking->value;
    }

    // POST /api/platforms/booking/detect — detect the provider for a pasted URL.
    public function detect(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        // Sector-derived gate (2026-07-15): a food business books via
        // Reservations, not Booking (partna + non-food business keep Booking
        // unconditionally). Covers the custom-card fallback below directly;
        // the Fresha/Square redirects are separately gated in their own bespoke
        // connect controllers (FreshaController/SquareController::connect).
        if (! AccountCapabilities::for($user)->can_use_booking) {
            return $this->error('Booking is not available for your account.', 403);
        }

        $validated = $request->validate(['url' => ['required', 'string', 'max:1000']]);

        $provider = $this->detector->detectFor('booking', $validated['url']);

        // Known providers run their own connect flow (unchanged endpoints).
        if ($provider === Platform::Fresha->value) {
            return $this->success(['provider' => 'fresha', 'next' => 'fresha-picker', 'selection' => null]);
        }
        if ($provider === Platform::Square->value) {
            return $this->success(['provider' => 'square', 'next' => 'square-connect', 'selection' => null]);
        }

        // Unknown → custom fallback. Minimal card now; enrich off-thread (JOB-1).
        $url = $this->scraper->normalizeUrl($validated['url']);
        if (! $url) {
            return $this->error('Enter a valid link (https://...).', 422);
        }
        $meta = $this->scraper->minimalCard($url);

        return $this->withConnectionLock($user, function () use ($user, $meta, $url) {
            $this->clearBooking($user);   // single-slot
            $payload = ['provider' => 'custom', 'source' => 'manual', ...$meta];
            $this->writePendingLinkCard($user, $payload);
            EnrichLinkCardJob::dispatch((string) $user->id, $this->platform(), $this->defaultResourceId(), $url)->afterCommit();

            return $this->success([
                'provider' => 'custom',
                'next' => 'custom-saved',
                'status' => 'pending',
                'selection' => $this->shapeCustom($payload),
                'statusUrl' => url('/api/platforms/booking/detect/status'),
            ], 202);
        });
    }

    // GET /api/platforms/booking/detect/status — poll the custom-card enrichment.
    public function detectStatus(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->linkCardStatusResponse($user, $this->defaultResourceId(), fn () => [
            'selection' => $this->shapeCustom($this->readConnection($user) ?? []),
        ]);
    }

    // GET /api/platforms/booking/status — read-aggregate across fresha/square/custom.
    public function status(Request $request): JsonResponse
    {
        return $this->success($this->statusFor($this->currentUser($request)));
    }

    // DELETE /api/platforms/booking — forget whichever booking-family connection exists.
    public function forget(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $this->clearBooking($user);

        return $this->success($this->statusFor($user));
    }

    // ── internals ────────────────────────────────────────────────

    /**
     * Aggregate connected-state across the booking-family connections. Priority
     * fresha > square > custom (only one is ever connected — single slot).
     *
     * @return array{connected:bool, provider:?string, name:?string, url:?string}
     */
    private function statusFor(User $user): array
    {
        $fresha = $user->integrationConnections()->where('platform', Platform::Fresha->value)->first();
        if ($fresha) {
            $sel = SelectionPayload::fromArray($fresha->payload);

            return [
                'connected' => true,
                'provider' => 'fresha',
                'name' => $sel->selection?->storeName(),
                'url' => $sel->url,
            ];
        }

        $square = $user->integrationConnections()->where('platform', Platform::Square->value)->first();
        if ($square) {
            return [
                'connected' => true,
                'provider' => 'square',
                'name' => null,
                'url' => SelectionPayload::fromArray($square->payload)->url,
            ];
        }

        $custom = CardPayload::fromArray($this->readConnection($user));
        if ($custom->provider() === 'custom') {
            return [
                'connected' => true,
                'provider' => 'custom',
                'name' => $custom->name(),
                'url' => $custom->url(),
            ];
        }

        return ['connected' => false, 'provider' => null, 'name' => null, 'url' => null];
    }

    /** Remove every booking-family connection (the single-slot guarantee). */
    private function clearBooking(User $user): void
    {
        foreach ([Platform::Fresha->value, Platform::Square->value] as $providerPlatform) {
            foreach ($user->integrationConnections()->where('platform', $providerPlatform)->get() as $row) {
                $row->delete();   // soft-delete; observer purges the sitepage cache
            }
        }
        $this->forgetConnection($user);   // the custom 'booking' row, if any
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
