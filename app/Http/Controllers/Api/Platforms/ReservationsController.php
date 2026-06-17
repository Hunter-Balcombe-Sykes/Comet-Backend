<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Models\Core\User\User;
use App\Services\Platforms\LinkCardScraper;
use App\Services\Platforms\OpenTableService;
use App\Services\Platforms\ProviderDetector;
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

    public function __construct(
        private readonly ProviderDetector $detector,
        private readonly LinkCardScraper $scraper,
        private readonly OpenTableService $openTable,
    ) {}

    protected function platform(): string
    {
        return 'reservations';
    }

    // POST /api/platforms/reservations/detect — detect the provider for a URL.
    public function detect(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $validated = $request->validate(['url' => ['required', 'string', 'max:1000']]);

        if ($this->detector->detectFor('reservations', $validated['url']) === 'opentable') {
            return $this->success(['provider' => 'opentable', 'next' => 'opentable-connect', 'selection' => null]);
        }

        // Unknown → custom fallback.
        $url = $this->scraper->normalizeUrl($validated['url']);
        if (! $url) {
            return $this->error('Enter a valid link (https://...).', 422);
        }
        $meta = $this->scraper->snapshotOrMinimal($url);   // never null — minimal card on fetch failure

        return $this->withConnectionLock($user, function () use ($user, $meta) {
            $this->clearReservations($user);   // single-slot
            $payload = ['provider' => 'custom', 'source' => 'manual', ...$meta];
            $this->writeConnection($user, $payload);

            return $this->success([
                'provider' => 'custom',
                'next' => 'custom-saved',
                'selection' => $this->shapeCustom($payload),
            ]);
        });
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
        $gb = $user->integrationConnections()->where('platform', 'google-business')->first();
        $suggestion = $gb && is_array($gb->payload)
            ? $this->openTable->suggestionFromGoogleBusiness($gb->payload)
            : null;

        return $this->success(['suggestion' => $suggestion]);
    }

    // DELETE /api/platforms/reservations — forget whichever reservation connection exists.
    public function forget(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $this->clearReservations($user);

        return $this->success($this->statusFor($user));
    }

    // ── internals ────────────────────────────────────────────────

    /**
     * @return array{connected:bool, provider:?string, name:?string, url:?string, embedUrl:?string}
     */
    private function statusFor(User $user): array
    {
        $ot = $user->integrationConnections()->where('platform', 'opentable')->first();
        if ($ot) {
            return [
                'connected' => true,
                'provider' => 'opentable',
                'name' => data_get($ot->payload, 'name'),
                'url' => data_get($ot->payload, 'url'),
                'embedUrl' => data_get($ot->payload, 'embedUrl'),
            ];
        }

        $custom = $this->readConnection($user);
        if (is_array($custom) && ($custom['provider'] ?? null) === 'custom') {
            return [
                'connected' => true,
                'provider' => 'custom',
                'name' => $custom['name'] ?? null,
                'url' => $custom['url'] ?? null,
                'embedUrl' => null,
            ];
        }

        return ['connected' => false, 'provider' => null, 'name' => null, 'url' => null, 'embedUrl' => null];
    }

    /** Remove every reservation-family connection (the single-slot guarantee). */
    private function clearReservations(User $user): void
    {
        foreach ($user->integrationConnections()->where('platform', 'opentable')->get() as $row) {
            $row->delete();   // soft-delete; observer purges the sitepage cache
        }
        $this->forgetConnection($user);   // the custom 'reservations' row, if any
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
