<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\Payloads\EventsAccountPayload;
use App\Services\Platforms\Payloads\StandaloneEventPayload;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

// Unified "Tickets & Events" catalogue over the Eventbrite + Humanitix platforms
// plus a custom-link fallback. A pasted URL is detected (eventbrite / humanitix /
// neither) and routed:
//
//  - an EVENT url  → added as a single standalone event (event-first, so pasting
//                    a Humanitix event link adds THAT event, not its whole host);
//  - an ACCOUNT    → the organiser/host is connected so its upcoming events
//    (organiser)     auto-refresh daily via the existing PlatformRefresher;
//  - anything else → a snapshot "custom" event card stored under the
//                    `events-custom` platform (one-shot, never refreshed — same
//                    contract as custom links), so it still renders alongside real
//                    events on the sitepage Events section.
//
// The Eventbrite/Humanitix scrapers, EventsPayload, the daily refresh, and the
// public allowlist are reused verbatim; this service only adds the detect/route
// and the cross-platform aggregation the single "Tickets & Events" card needs.
// The existing per-platform controllers are untouched.
class EventsCatalog
{
    private const CUSTOM_PLATFORM = 'events-custom';

    private const MAX_ACCOUNTS = 5;   // per platform (mirrors ManagesIntegrationConnection::maxAccounts)

    private const MAX_EVENTS = 10;    // per platform (mirrors EventsPlatformController::MAX_STANDALONE_EVENTS)

    /** @var array<string, array{eventUrl:callable,fetchEvent:callable,accountUrl:callable,fetchAccount:callable}> */
    private readonly array $adapters;

    public function __construct(
        private readonly EventbriteScraper $eventbrite,
        private readonly HumanitixScraper $humanitix,
        private readonly LinkCardScraper $linkCard,
        private readonly ProviderDetector $detector,
    ) {
        // Build the per-provider callable map in the constructor so test mocks
        // bound before instantiation are captured by the closures correctly.
        $this->adapters = [
            'eventbrite' => [
                'eventUrl' => fn (string $u) => $this->eventbrite->normalizeEventUrl($u),
                'fetchEvent' => fn (string $u) => $this->eventbrite->fetchSingleEvent($u),
                'accountUrl' => fn (string $u) => $this->eventbrite->normalizeOrgUrl($u),
                'fetchAccount' => fn (string $u) => $this->eventbrite->fetchEvents($u),
            ],
            'humanitix' => [
                'eventUrl' => fn (string $u) => $this->humanitix->normalizeEventUrl($u),
                'fetchEvent' => fn (string $u) => $this->humanitix->fetchSingleEvent($u),
                'accountUrl' => fn (string $u) => $this->humanitix->resolveHostUrl($u),
                'fetchAccount' => fn (string $u) => $this->humanitix->fetchEvents($u),
            ],
        ];
    }

    /**
     * Detect + store a pasted URL.
     *
     * @return array{ok:bool, error?:string, status?:int, selection?:?array}
     */
    public function addByUrl(User $user, string $rawUrl): array
    {
        $raw = trim($rawUrl);
        $provider = $this->detector->detectFor('events', $raw);

        if ($provider !== null) {
            $a = $this->adapter($provider);

            if ($a !== null) {
                $label = ucfirst($provider);

                // Event-first: a single event URL adds just that event. (Humanitix's
                // host resolver would otherwise turn an event link into its whole
                // organiser, which isn't what "paste this event" means.)
                if (($eventUrl = ($a['eventUrl'])($raw)) !== null) {
                    $event = ($a['fetchEvent'])($eventUrl);
                    if (! is_array($event)) {
                        return $this->fail("Couldn't load that {$label} event.", 422);
                    }

                    return $this->storeStandalone($user, $provider, EventsPayload::standalonePayload($event));
                }

                // Else an organiser/host account → connect it (events auto-refresh).
                if (($accountUrl = ($a['accountUrl'])($raw)) !== null) {
                    $result = ($a['fetchAccount'])($accountUrl);
                    if (! is_array($result)) {
                        return $this->fail("Couldn't load that {$label} page.", 422);
                    }

                    return $this->storeAccount($user, $provider, $accountUrl, $result);
                }
                // Matched host but neither a recognised event nor account URL → custom.
            }
        }

        return $this->storeCustom($user, $raw);
    }

    /**
     * The unified catalogue: connected accounts (each with its upcoming events)
     * plus the merged event list (account events + standalone + custom), elapsed
     * dropped, soonest first. Every item carries its `platform` and the
     * `removePath` the dashboard hits to remove it.
     *
     * @return array{accounts:list<array<string,mixed>>, events:list<array<string,mixed>>}|null
     */
    public function selection(User $user): ?array
    {
        $accounts = [];
        $events = [];

        // Derive the live platform list from the registry so adding a new events
        // provider only requires a descriptor — no edit here.
        $eventPlatforms = $this->detector->providersFor('events');

        // Eager-load all rows for every relevant platform in one query, then split
        // in memory. Without this, rowsFor() runs once per platform per loop pass
        // (2 platforms × 2 loops + custom = 5 DB round-trips → 1).
        $allPlatforms = [...$eventPlatforms, self::CUSTOM_PLATFORM];
        $byPlatform = $user->integrationConnections()
            ->whereIn('platform', $allPlatforms)
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get()
            ->groupBy('platform');

        // Organiser/host accounts (eventbrite + humanitix) + their upcoming events.
        foreach ($eventPlatforms as $platform) {
            $platformRows = $byPlatform->get($platform, collect());
            $rows = $platformRows->filter(
                fn (IntegrationConnection $r) => $r->resource_kind !== 'event'
                    && $r->resource_kind !== 'link',
            )->values();

            foreach ($rows as $row) {
                $account = EventsAccountPayload::fromArray($row->payload);
                $upcoming = $this->dropElapsed(EventsPayload::withIds($account->upcoming()));
                $accounts[] = [
                    'id' => $row->resource_id,
                    'platform' => $platform,
                    'url' => $account->url(),
                    'organiser' => $account->organiser(),
                    'next' => $upcoming[0] ?? null,
                    'upcoming' => $upcoming,
                    'removePath' => "/platforms/{$platform}/accounts/{$row->resource_id}",
                ];
                foreach ($upcoming as $ev) {
                    $events[] = [
                        ...$ev,
                        'platform' => $platform,
                        'source' => 'account',
                        'accountId' => $row->resource_id,
                        // The existing /events/{id} endpoint hides an account event.
                        'removePath' => "/platforms/{$platform}/events/{$ev['id']}",
                    ];
                }
            }
        }

        // Standalone events: eventbrite/humanitix singles + custom cards.
        foreach ($allPlatforms as $platform) {
            $platformRows = $byPlatform->get($platform, collect());
            $rows = $platformRows->filter(
                fn (IntegrationConnection $r) => $r->resource_kind === 'event',
            )->values();

            foreach ($rows as $row) {
                $standalone = StandaloneEventPayload::fromArray($row->payload);
                $id = $standalone->id();
                $events[] = [
                    ...$standalone->event(),
                    'platform' => $platform,
                    'source' => $platform === self::CUSTOM_PLATFORM ? 'link' : 'standalone',
                    'removePath' => $platform === self::CUSTOM_PLATFORM
                        ? "/platforms/events/custom/{$id}"
                        : "/platforms/{$platform}/events/{$id}",
                ];
            }
        }

        if ($accounts === [] && $events === []) {
            return null;
        }

        return [
            'accounts' => $accounts,
            'events' => $this->sortEvents($this->dropElapsed($events)),
        ];
    }

    /** Remove one custom (events-custom) event by its id. */
    public function removeCustom(User $user, string $id): array
    {
        $row = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('platform', self::CUSTOM_PLATFORM)
            ->where('resource_id', 'event-'.$id)
            ->first();

        if ($row === null) {
            return $this->fail('Event not found.', 404);
        }

        $row->delete();

        return ['ok' => true, 'selection' => $this->selection($user)];
    }

    // ── Storage ───────────────────────────────────────────────────────────

    // PWL-13: this catalogue is a second writer of the SAME eventbrite/humanitix
    // platform_connections rows EventsPlatformController::addAccount() (locked)
    // and ScheduledRefresh (locked) write — races them without withPlatformLock.
    // The vendor fetch (fetchAccount) already ran upstream in addByUrl() before
    // this is called, so the whole read→mutate→write cycle here is DB-only and
    // safe to hold the lock across.
    private function storeAccount(User $user, string $platform, string $url, array $result): array
    {
        return $this->withPlatformLock($user, $platform, function () use ($user, $platform, $url, $result): array {
            $rid = 'acct-'.substr(sha1(strtolower(trim($url))), 0, 16);
            $existing = IntegrationConnection::query()
                ->where('user_id', $user->id)->where('platform', $platform)->where('resource_id', $rid)
                ->first();

            if ($existing === null && $this->accountRows($user, $platform)->count() >= self::MAX_ACCOUNTS) {
                return $this->fail('You can connect up to '.self::MAX_ACCOUNTS.' organisers per platform.', 422);
            }

            // Re-connecting the same organiser keeps its per-event hides.
            $hidden = EventsAccountPayload::fromArray($existing?->payload)->hiddenEventIds();
            $payload = EventsPayload::accountPayload(
                $url,
                $result['organiser'] ?? null,
                is_array($result['events'] ?? null) ? $result['events'] : [],
                $hidden,
            );
            $this->writeRow($user, $platform, $rid, $payload);

            return ['ok' => true, 'selection' => $this->selection($user)];
        });
    }

    // PWL-13: same duplicate-writer race as storeAccount() above, against
    // EventsPlatformController::addStandaloneEvent()/removeEvent() (locked) and
    // ScheduledRefresh. fetchEvent already ran upstream in addByUrl(); storeCustom()
    // does its own fetch (linkCard->snapshotOrMinimal) before calling in here, so
    // this method's body is DB-only same as storeAccount's.
    private function storeStandalone(User $user, string $platform, array $payload): array
    {
        return $this->withPlatformLock($user, $platform, function () use ($user, $platform, $payload): array {
            $rid = 'event-'.$payload['id'];
            $exists = IntegrationConnection::query()
                ->where('user_id', $user->id)->where('platform', $platform)->where('resource_id', $rid)
                ->exists();

            if (! $exists && $this->eventRows($user, $platform)->count() >= self::MAX_EVENTS) {
                return $this->fail('You can add up to '.self::MAX_EVENTS.' individual events per platform.', 422);
            }

            // Covers both the direct-event path and the events-custom fallback
            // (storeCustom below also routes through here) — both are 'event-*' rows.
            $this->writeRow($user, $platform, $rid, $payload, resourceKind: 'event');

            return ['ok' => true, 'selection' => $this->selection($user)];
        });
    }

    private function storeCustom(User $user, string $raw): array
    {
        $url = $this->linkCard->normalizeUrl($raw);
        if ($url === null) {
            return $this->fail('Enter a valid link, or an Eventbrite / Humanitix URL.', 422);
        }

        $snap = $this->linkCard->snapshotOrMinimal($url);
        $payload = EventsPayload::standalonePayload([
            'name' => $snap['name'],
            'venue' => null,
            'location' => null,
            'startDate' => null,
            'endDate' => null,
            // Enriched-event keys (2026-07-17) — shape parity with the scraped
            // platforms so every event card carries the same field set. A custom
            // link knows none of them; soldOut is the one bool (unknown = false).
            'description' => $snap['description'] ?? null,
            'startsAt' => null,
            'endsAt' => null,
            'price' => null,
            'priceMin' => null,
            'currency' => null,
            'availability' => null,
            'soldOut' => false,
            'image' => $snap['logo'] ?? $snap['favicon'],
            'link' => $snap['url'],
        ]);

        return $this->storeStandalone($user, self::CUSTOM_PLATFORM, $payload);
    }

    /**
     * Direct model upsert — fires IntegrationConnectionObserver (sitepage cache
     * purge). Ownership is inherent (scoped to the authed user); the route's
     * EnforcePendingDeletionReadOnly handles the pending-deletion guard.
     *
     * $resourceKind stamps resource_kind (FOUND-34) only when non-null — account
     * rows (storeAccount) omit it so the column stays NULL for those rows.
     */
    private function writeRow(User $user, string $platform, string $resourceId, array $payload, ?string $resourceKind = null): void
    {
        $values = [
            'payload' => $payload,
            'is_active' => true,
            'last_refreshed_at' => now(),
            'last_refresh_status' => 'ok',
            'last_refresh_error' => null,
            'consecutive_failures' => 0,
        ];
        if ($resourceKind !== null) {
            $values['resource_kind'] = $resourceKind;
        }

        IntegrationConnection::updateOrCreate(
            ['user_id' => $user->id, 'platform' => $platform, 'resource_id' => $resourceId],
            $values,
        );
    }

    /**
     * Serialise a read→mutate→write cycle behind the SAME per-(user, platform)
     * lock ManagesIntegrationConnection::withConnectionLock (controllers) and
     * ScheduledRefresh already use — CacheKeyGenerator::platformConnectionLock()
     * is the one formula every writer of `site.platform_connections` must build
     * (PWL-13). $platform here is $provider ('eventbrite'/'humanitix'), which
     * is exactly what EventbriteController::platform() / HumanitixController::
     * platform() return, so this contends on the identical key.
     *
     * EventsCatalog has no JsonResponse to return (unlike the controller trait),
     * so a timeout surfaces as a 423 through the existing fail() array contract
     * instead — EventsController::add() maps result['status'] straight onto the
     * HTTP response.
     *
     * @return array{ok:bool, error?:string, status?:int, selection?:?array}
     */
    private function withPlatformLock(User $user, string $platform, callable $write): array
    {
        try {
            return Cache::lock(CacheKeyGenerator::platformConnectionLock($platform, (string) $user->id), 10)->block(5, $write);
        } catch (LockTimeoutException) {
            return $this->fail('Another change is still saving — please retry in a moment.', 423);
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return array{eventUrl:callable,fetchEvent:callable,accountUrl:callable,fetchAccount:callable}|null */
    private function adapter(string $provider): ?array
    {
        return $this->adapters[$provider] ?? null;
    }

    private function rowsFor(User $user, string $platform)
    {
        return $user->integrationConnections()
            ->where('platform', $platform)
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();
    }

    /** Account rows = anything that isn't a standalone 'event' or 'link' resource_kind row. */
    private function accountRows(User $user, string $platform)
    {
        return $this->rowsFor($user, $platform)->filter(
            fn (IntegrationConnection $r) => $r->resource_kind !== 'event'
                && $r->resource_kind !== 'link',
        )->values();
    }

    private function eventRows(User $user, string $platform)
    {
        return $this->rowsFor($user, $platform)->filter(
            fn (IntegrationConnection $r) => $r->resource_kind === 'event',
        )->values();
    }

    /**
     * Keep events whose end (or start, when no end is known) is still in the
     * future. Date strings carry the event's LOCAL offset, so Carbon::parse (not
     * string compare) is required.
     *
     * @param  list<array<string,mixed>>  $events
     * @return list<array<string,mixed>>
     */
    private function dropElapsed(array $events): array
    {
        $now = now();

        return array_values(array_filter($events, function (array $e) use ($now) {
            $when = $e['endDate'] ?? $e['startDate'] ?? null;
            if (empty($when)) {
                return true;
            }

            return Carbon::parse($when)->gte($now);
        }));
    }

    /**
     * Soonest first; dateless events (e.g. custom links) sort last.
     *
     * @param  list<array<string,mixed>>  $events
     * @return list<array<string,mixed>>
     */
    private function sortEvents(array $events): array
    {
        usort($events, function (array $a, array $b) {
            $aDate = $a['startDate'] ?? '';
            $bDate = $b['startDate'] ?? '';
            if ($aDate === '' || $bDate === '') {
                return $aDate === $bDate ? 0 : ($aDate === '' ? 1 : -1);
            }

            return Carbon::parse($aDate)->getTimestamp() <=> Carbon::parse($bDate)->getTimestamp();
        });

        return $events;
    }

    /** @return array{ok:false, error:string, status:int} */
    private function fail(string $message, int $status): array
    {
        return ['ok' => false, 'error' => $message, 'status' => $status];
    }
}
