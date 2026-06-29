<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Models\Core\User\User;
use App\Services\Platforms\LinkCardScraper;
use App\Services\Platforms\Payloads\CardPayload;
use App\Services\Platforms\Payloads\SelectionPayload;
use App\Services\Platforms\ProviderDetector;
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
        return 'booking';
    }

    // POST /api/platforms/booking/detect — detect the provider for a pasted URL.
    public function detect(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $validated = $request->validate(['url' => ['required', 'string', 'max:1000']]);

        $provider = $this->detector->detectFor('booking', $validated['url']);

        // Known providers run their own connect flow (unchanged endpoints).
        if ($provider === 'fresha') {
            return $this->success(['provider' => 'fresha', 'next' => 'fresha-picker', 'selection' => null]);
        }
        if ($provider === 'square') {
            return $this->success(['provider' => 'square', 'next' => 'square-connect', 'selection' => null]);
        }

        // Unknown → custom fallback. Fetch the page once for a branded card.
        $url = $this->scraper->normalizeUrl($validated['url']);
        if (! $url) {
            return $this->error('Enter a valid link (https://...).', 422);
        }
        $meta = $this->scraper->snapshotOrMinimal($url);   // never null — minimal card on fetch failure

        return $this->withConnectionLock($user, function () use ($user, $meta) {
            $this->clearBooking($user);   // single-slot
            $payload = ['provider' => 'custom', 'source' => 'manual', ...$meta];
            $this->writeConnection($user, $payload);

            return $this->success([
                'provider' => 'custom',
                'next' => 'custom-saved',
                'selection' => $this->shapeCustom($payload),
            ]);
        });
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
        $fresha = $user->integrationConnections()->where('platform', 'fresha')->first();
        if ($fresha) {
            $sel = SelectionPayload::fromArray($fresha->payload);

            return [
                'connected' => true,
                'provider' => 'fresha',
                'name' => $sel->selection?->storeName(),
                'url' => $sel->url,
            ];
        }

        $square = $user->integrationConnections()->where('platform', 'square')->first();
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
        foreach (['fresha', 'square'] as $providerPlatform) {
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
