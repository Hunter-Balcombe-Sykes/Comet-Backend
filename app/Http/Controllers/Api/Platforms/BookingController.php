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
use App\Services\Platforms\Payloads\CardPayload;
use App\Services\Platforms\Payloads\SelectionPayload;
use App\Services\Platforms\ProviderDetector;
use App\Services\Platforms\Registry\Platform;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\WebsiteLinkHarvester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Booking category — a single-slot smart-detect card. The user pastes a URL;
// we detect the provider and tell the dashboard which existing flow to run:
//   - fresha  → the team-member picker (frontend calls POST /fresha/connect then /selection)
//   - square  → store the booking link  (frontend calls POST /square/connect)
//   - custom  → everything else, stored here as a branded card
// Single-slot: the dashboard offers no add affordance once any booking provider
// is connected, so /detect is only reached from an empty state in normal use.
//
// Convergence Phase 6: the "custom" fallback is no longer one shared
// `partna.booking_link` row. It resolves in two steps:
//
//   1. The link is classified against the catalog. Every booking host the
//      harvester knows has its own surface (18/18, verified 2026-08-16), so a
//      Booksy or Treatwell link becomes a `booksy.book` / `treatwell.book`
//      connection — visible to ingest, and to any later per-brand feature.
//   2. Only a link that matches NOTHING becomes a links-pool item under owner
//      ruling 2A. It is preserved with its provider label rather than written
//      to a surface nothing can read; the response says where it went, and the
//      booking slot stays honestly empty because nothing is connected.
//
// The wire is unchanged for (1) — still `provider: 'custom'`, still
// `next: 'custom-saved'`. Brand identity lives in surface_key now, which is the
// point of the phase; the dashboard's only branching values remain the two rich
// flows it actually implements.
class BookingController extends ApiController
{
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    public function __construct(
        private readonly ProviderDetector $detector,
        private readonly LinkCardScraper $scraper,
        private readonly WebsiteLinkHarvester $harvester,
        private readonly LinkPoolWriter $pool,
    ) {}

    // The FAMILY key — the lock and FeatureAvailability only. Rows carry brand
    // surfaces; reads scope on routingClass() below.
    protected function platform(): string
    {
        return 'booking';
    }

    protected function routingClass(): ?string
    {
        return 'booking';
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

        // Neither rich flow → the card path. Minimal card now; enrich
        // off-thread (JOB-1).
        $url = $this->scraper->normalizeUrl($validated['url']);
        if (! $url) {
            return $this->error('Enter a valid link (https://...).', 422);
        }
        $meta = $this->scraper->minimalCard($url);

        // Classified OUTSIDE the lock — it is a pure host match, and the lock
        // exists for the clear-then-write span below, not for this.
        $surface = $this->bookingSurfaceFor($url);

        // Cross-platform XOR lock (not the per-platform withConnectionLock) —
        // clearBooking() below can delete fresha/square rows, which a
        // per-platform 'booking' key can't mutually exclude against those
        // controllers. The EnrichLinkCardJob dispatch is deliberately OUTSIDE
        // the lock: under QUEUE_CONNECTION=sync it runs inline and can take
        // seconds, which would hold the lock far past its 10s TTL (rule #1).
        $rid = null;
        $response = $this->withCrossPlatformLock(CacheKeyGenerator::bookingXorLock((string) $user->id), function () use ($user, $url, $meta, $surface, &$rid) {
            $this->clearBooking($user);   // single-slot

            if ($surface === null) {
                // Owner ruling 2A: no brand home, so it is preserved as a link
                // rather than written to a retired surface. The booking slot
                // stays empty because nothing IS connected — reporting a
                // connection here would be the dashboard's one signal lying.
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
                'statusUrl' => url('/api/platforms/booking/detect/status'),
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

    // GET /api/platforms/booking/detect/status — poll the custom-card enrichment.
    public function detectStatus(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        // The row's resource id is the BRAND's now, not the fixed 'booking'
        // default, so it has to be looked up rather than assumed. Single-slot
        // makes "the one card row" unambiguous.
        $row = $this->cardRow($user);
        if ($row === null) {
            return $this->error('Link not found.', 404);
        }

        return $this->linkCardStatusResponse($user, (string) $row->resource_id, fn () => [
            'selection' => $this->shapeCustom(CardPayload::fromArray($row->fresh()?->payload)),
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

        return $this->withCrossPlatformLock(CacheKeyGenerator::bookingXorLock((string) $user->id), function () use ($user) {
            $this->clearBooking($user);

            return $this->success($this->statusFor($user));
        });
    }

    // ── internals ────────────────────────────────────────────────

    /**
     * Aggregate connected-state across the booking-family connections. Priority
     * fresha > square > custom (only one is ever connected — single slot).
     *
     * `setup` distinguishes "connected AND usable" from "connected but still
     * awaiting the owner" — an auto-harvested fresha row is {url, selection:null}
     * and renders nothing. Null when nothing is connected. This is the only
     * signal the dashboard has; /fresha/selection reveals the same state but is
     * not called from the Booking page's mount path.
     *
     * @return array{connected:bool, provider:?string, name:?string, url:?string, setup:?array{complete:bool, reason:?string, seededFrom:?string, seededAt:?string}}
     */
    private function statusFor(User $user): array
    {
        // active() on both provider lookups: a staff-disabled row is invisible
        // publicly (every read path filters is_active), so reporting it as
        // connected here left the dashboard contradicting the live site.
        $fresha = $user->integrationConnections()->where('platform', Platform::Fresha->value)->active()->first();
        if ($fresha) {
            $sel = SelectionPayload::fromArray($fresha->payload);

            return [
                'connected' => true,
                'provider' => 'fresha',
                'name' => $sel->selection?->storeName(),
                'url' => $sel->url,
                'setup' => $this->setupFor($fresha, $sel->source),
            ];
        }

        $square = $user->integrationConnections()->where('platform', Platform::Square->value)->active()->first();
        if ($square) {
            return [
                'connected' => true,
                'provider' => 'square',
                'name' => null,
                'url' => SelectionPayload::fromArray($square->payload)->url,
                'setup' => $this->setupFor($square, SelectionPayload::fromArray($square->payload)->source),
            ];
        }

        $row = $this->cardRow($user);
        if ($row !== null) {
            $custom = CardPayload::fromArray($row->payload);

            return [
                'connected' => true,
                // Still 'custom' on the wire even though the row now knows its
                // brand: the dashboard branches on exactly two values (fresha,
                // square) and treats everything else as one card. Reporting
                // 'booksy' here would be a new vocabulary it has no handler for.
                'provider' => 'custom',
                'name' => $custom->name(),
                'url' => $custom->url(),
                'setup' => ['complete' => true, 'reason' => null, 'seededFrom' => null, 'seededAt' => null],
            ];
        }

        return ['connected' => false, 'provider' => null, 'name' => null, 'url' => null, 'setup' => null];
    }

    /**
     * The single booking CARD row — any booking-class connection that is not one
     * of the two rich provider flows (which statusFor checks first, and which
     * carry a selection rather than a card).
     *
     * Keyed on routing_class for the same reason
     * ReservationsController::clearReservations is: before Phase 6 every
     * non-Fresha/Square booking brand shared the retired 'booking' key, so a
     * fixed resource id was enough to find it. Now each carries its own surface,
     * and a slug list would silently under-cover every brand added later.
     */
    private function cardRow(User $user): ?IntegrationConnection
    {
        return $user->integrationConnections()
            ->where('routing_class', 'booking')
            ->whereNotIn('platform', [Platform::Fresha->value, Platform::Square->value])
            ->orderBy('created_at')
            ->first();
    }

    /**
     * The catalog surface a pasted booking URL belongs to.
     *
     * Three arms, in order:
     *   1. a real booking brand (18/18 of the hosts the harvester knows);
     *   2. NOTHING recognised it — `direct.book`, the booking page no brand
     *      claims, which is nearly always the business's own site. Owner ruling
     *      2026-08-16: that link deserves a working Book button, and it had one
     *      before Phase 6;
     *   3. it classified as something else entirely (a social profile pasted
     *      into the booking box) — null, and the caller pools it. Deliberately
     *      NOT arm 2: the owner pasted a thing that is demonstrably not a
     *      booking page, and calling it one would be us being wrong on purpose.
     */
    private function bookingSurfaceFor(string $url): ?string
    {
        $classified = $this->harvester->classify($url);

        if ($classified === null) {
            return 'direct.book';
        }

        if ($classified['category'] !== 'booking') {
            return null;
        }

        $platform = $classified['platform'];

        return LegacyPlatformMap::surfaceFor($platform) ?? $platform;
    }

    /**
     * `reason` is a string rather than a bool so a second incompleteness cause
     * is additive. `seededFrom` is payload.source — written by the two
     * auto-harvest services, absent on every user-initiated connect — so the
     * prompt can say "we found this on your Instagram" instead of a generic nag.
     *
     * @return array{complete:bool, reason:?string, seededFrom:?string, seededAt:?string}
     */
    private function setupFor(IntegrationConnection $connection, ?string $source): array
    {
        $complete = app(PlatformRegistry::class)
            ->get(strtolower((string) $connection->platform))
            ?->isComplete($connection) ?? true;

        return [
            'complete' => $complete,
            'reason' => $complete ? null : 'awaiting_selection',
            'seededFrom' => $complete ? null : $source,
            'seededAt' => $complete ? null : $connection->created_at->toIso8601String(),
        ];
    }

    /**
     * Remove every booking-family connection (the single-slot guarantee).
     *
     * Convergence Phase 6: keyed on routing_class, which subsumes all three arms
     * the old version enumerated (fresha, square, and the retired shared
     * 'booking' key) AND every brand that has split off since. The old list
     * could only stay correct by being edited every time a booking brand was
     * added — the exact drift ReservationsController::clearReservations already
     * moved off, for the same reason.
     */
    private function clearBooking(User $user): void
    {
        foreach ($user->integrationConnections()->where('routing_class', 'booking')->get() as $row) {
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
