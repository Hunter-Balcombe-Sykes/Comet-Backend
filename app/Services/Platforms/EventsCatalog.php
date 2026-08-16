<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Content\ManualEventWriter;
use App\Services\Notifications\Dispatchers\IntegrationNotifier;
use App\Services\Platforms\Payloads\EventsAccountPayload;
use App\Services\Platforms\Payloads\StandaloneEventPayload;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// Unified "Tickets & Events" catalogue over the Eventbrite + Humanitix platforms
// plus a custom-link fallback. A pasted URL is detected (eventbrite / humanitix /
// neither) and routed:
//
//  - an EVENT url  → added as a single standalone event (event-first, so pasting
//                    a Humanitix event link adds THAT event, not its whole host);
//  - an ACCOUNT    → the organiser/host is connected so its upcoming events
//    (organiser)     auto-refresh daily via the existing PlatformRefresher;
//  - anything else → a hand-added event in the `events` POOL, so it still
//                    renders alongside real events on the sitepage Events section.
//
// Convergence Phase 6 moved that last arm off the `partna.manual_event`
// connection surface and onto content.* (ManualEventWriter), for the same reason
// custom links moved in Phase 3: a pseudo-platform row is invisible to ingest,
// to identity resolution and to every pool feature. Code-only — the surface held
// 0 live rows on dev when the path moved. Two consequences worth naming:
//   - a hand-added event's `id` is a content item uuid now, not `event-<hash>`;
//   - a SITELESS user can no longer hold one. A pool item needs a section, which
//     hangs off the site. The connection lane allowed it; the pool does not, and
//     that is the pool's model rather than a regression to paper over.
//
// The Eventbrite/Humanitix scrapers, EventsPayload, the daily refresh, and the
// public allowlist are reused verbatim; this service only adds the detect/route
// and the cross-platform aggregation the single "Tickets & Events" card needs.
// The existing per-platform controllers are untouched.
class EventsCatalog
{
    /**
     * The retired pseudo-platform. Convergence Phase 6 stopped writing it, and
     * IntegrationConnection::RETIRED_SURFACES refuses new rows. Kept ONLY as the
     * read key for pre-migration rows in selection(), so an owner whose custom
     * event predates the move still sees it until the retirement command runs.
     */
    private const CUSTOM_PLATFORM = 'events-custom';

    private const MAX_ACCOUNTS = 5;   // per platform (mirrors ManagesIntegrationConnection::maxAccounts)

    // Manual order lives in site settings rather than on connection rows:
    // account-derived events share ONE connection row, so per-row sort_order
    // can never order the merged list. The settings key holds the full desired
    // event-id order; ids it doesn't mention keep the soonest-first fallback.
    private const ORDER_SETTINGS_KEY = 'events_order';

    private const MAX_ORDER_IDS = 200;

    /** @var array<string, array{eventUrl:callable,fetchEvent:callable,accountUrl:callable,fetchAccount:callable}> */
    private readonly array $adapters;

    public function __construct(
        private readonly EventbriteScraper $eventbrite,
        private readonly HumanitixScraper $humanitix,
        private readonly LinkCardScraper $linkCard,
        private readonly ProviderDetector $detector,
        private readonly ManualEventWriter $manualEvents,
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
     * @return array{ok:bool, error?:string, status?:int, selection?:?array, pending?:array{platform:string,connectionId:string,resourceId:string}}
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

                    return $this->storeStandalone($user, EventsPayload::standalonePayload($event));
                }

                // Else an organiser/host account → connect it (events auto-refresh).
                if (($accountUrl = ($a['accountUrl'])($raw)) !== null) {
                    // CA-W5: config('partna.connect.deferred') names this provider —
                    // skip the scrape entirely and let ConnectFetchJob fill the row.
                    // accountUrl resolution above always stays inline: for Humanitix
                    // it's a live fetch that IS the row's identity (§2 of the plan).
                    if ($this->shouldDefer($provider)) {
                        return $this->storeAccountDeferred($user, $provider, $accountUrl);
                    }

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

        // Convergence Phase 6 (hand-added) and slice 7 Phase 4 (standalone):
        // both now live in the `events` pool. Read first, because the
        // connection loop below has to know which URLs they cover.
        $cards = $this->manualEvents->cards($user);

        /** @var array<string, true> canonicalised URLs the pool already publishes */
        $pooled = [];
        foreach ($cards as $card) {
            if (is_string($card['url']) && $card['url'] !== '') {
                $pooled[strtolower(trim($card['url']))] = true;
            }
        }

        // Standalone events: eventbrite/humanitix singles + custom cards that
        // have NOT been carried to the pool yet.
        //
        // The skip is what keeps a migrated event off this list twice. It is
        // keyed on the canonical URL rather than deleting the connection row,
        // because the legacy rows stay live until Phase 6 drops the table
        // (parent's ordering law), and because it keeps an event added through
        // the per-platform `POST /platforms/{platform}/events` verb visible
        // here until the backfill next runs — that verb still writes a
        // connection and is Phase 6's to retire.
        foreach ($allPlatforms as $platform) {
            $platformRows = $byPlatform->get($platform, collect());
            $rows = $platformRows->filter(
                fn (IntegrationConnection $r) => $r->resource_kind === 'event',
            )->values();

            foreach ($rows as $row) {
                $standalone = StandaloneEventPayload::fromArray($row->payload);
                $event = $standalone->event();
                $link = is_string($event['link'] ?? null) ? strtolower(trim($event['link'])) : '';
                if ($link !== '' && isset($pooled[$link])) {
                    continue;
                }

                $id = $standalone->id();
                $events[] = [
                    ...$event,
                    'platform' => $platform,
                    'source' => $platform === self::CUSTOM_PLATFORM ? 'link' : 'standalone',
                    'removePath' => $platform === self::CUSTOM_PLATFORM
                        ? "/platforms/events/custom/{$id}"
                        : "/platforms/{$platform}/events/{$id}",
                ];
            }
        }

        // Same wire shape, same `source: 'link'`, same removePath contract; only
        // the id changed (a content item uuid, not `event-<hash>`). The dashboard
        // round-trips ids opaquely, so that is invisible to it.
        //
        // The dated half comes from the item's facets now: a MIGRATED standalone
        // event carries its real venue/dates/price, a hand-added one carries
        // nulls exactly as it always did.
        foreach ($cards as $card) {
            $events[] = [
                'id' => $card['id'],
                'name' => $card['name'],
                'description' => $card['description'],
                'link' => $card['url'],
                'venue' => $card['venue'],
                'location' => $card['location'],
                'startDate' => $card['startDate'],
                'endDate' => $card['endDate'],
                'startsAt' => $card['startDate'],
                'endsAt' => $card['endDate'],
                'price' => null,
                'priceMin' => $card['priceMin'],
                'currency' => $card['currency'],
                'availability' => null,
                'soldOut' => false,
                'image' => null,
                'platform' => self::CUSTOM_PLATFORM,
                'source' => 'link',
                'removePath' => "/platforms/events/custom/{$card['id']}",
            ];
        }

        if ($accounts === [] && $events === []) {
            return null;
        }

        return [
            'accounts' => $accounts,
            'events' => $this->sortEvents($this->dropElapsed($events), $this->orderIndex($user)),
        ];
    }

    /**
     * Persist the user's manual event order — the full desired id order, same
     * contract as CustomLinksController::reorderLinks. Stored via a locked
     * re-read + merge of site settings (the LIFE-3 rule: two concurrent
     * writers must not clobber each other's unrelated settings keys).
     *
     * $ids is list<mixed>, NOT list<string>: it arrives straight from the
     * request body. `ids.*  => string` only holds if the validator actually
     * ran, and this is a public service method — the is_string() filter below
     * is the load-bearing check, not a formality. Do not narrow this back.
     *
     * @param  list<mixed>  $ids
     * @return array{ok:true, selection:?array}|array{ok:false, error:string, status:int}
     */
    public function reorder(User $user, array $ids): array
    {
        $site = $user->site;
        if ($site === null) {
            return $this->fail('Site not found.', 404);
        }

        $ids = array_slice(array_values(array_unique(array_filter(
            $ids,
            fn ($id) => is_string($id) && $id !== '' && strlen($id) <= 160,
        ))), 0, self::MAX_ORDER_IDS);

        DB::connection('pgsql')->transaction(function () use ($site, $ids): void {
            $fresh = Site::query()->whereKey($site->id)->lockForUpdate()->first();
            if ($fresh === null) {
                return;
            }
            $settings = is_array($fresh->settings) ? $fresh->settings : [];
            $settings[self::ORDER_SETTINGS_KEY] = $ids;
            $fresh->settings = $settings;
            $fresh->save();
        });

        // The user's site relation was loaded BEFORE the write above — drop it
        // so orderIndex() reads the saved order, not the stale snapshot.
        $user->unsetRelation('site');

        return ['ok' => true, 'selection' => $this->selection($user)];
    }

    /** @return array<string,int> event id → manual position */
    private function orderIndex(User $user): array
    {
        $settings = $user->site?->settings;
        $order = is_array($settings) ? ($settings[self::ORDER_SETTINGS_KEY] ?? []) : [];
        if (! is_array($order)) {
            return [];
        }

        $index = [];
        foreach (array_values($order) as $position => $id) {
            if (is_string($id)) {
                $index[$id] = $position;
            }
        }

        return $index;
    }

    /**
     * Remove one hand-added event by its id.
     *
     * Tries the POOL first (where every new one lives since convergence Phase 6)
     * and falls back to the legacy connection. Both arms stay reachable until
     * the retirement command has run: an owner with a pre-migration event must
     * still be able to delete it, and the two id shapes cannot collide — one is
     * a uuid, the other a `<hash>` behind an `event-` prefix.
     */
    public function removeCustom(User $user, string $id): array
    {
        if ($this->manualEvents->remove($user, $id)) {
            return ['ok' => true, 'selection' => $this->selection($user)];
        }

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
    // platform_connections rows that EventsPlatformController::addAccount() and
    // ScheduledRefresh write — hence withPlatformLock, on the identical
    // CacheKeyGenerator::platformConnectionLock key.
    //
    // CA-W5: the closure below is DB-only on BOTH paths, but no longer for the
    // reason this comment used to give. Synchronously, fetchAccount() still runs
    // upstream in addByUrl() before we get here. On the deferred path it does
    // not run at all — it moves into ConnectFetchJob, dispatched by
    // EventsController::add() only AFTER this lock has released AND after the
    // ambient FetchBudget that wraps addByUrl() has closed. Never dispatch from
    // inside here: on QUEUE_CONNECTION=sync dispatch() runs handle() inline,
    // which would (a) block on this very lock key and (b) nest its own
    // FetchBudget::open() inside the caller's, whose inner finally clears the
    // outer deadline.
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

    /**
     * CA-W5 deferred sibling of storeAccount(): the scrape was skipped by the
     * caller, so this writes a PENDING row via writePendingAccountRow() and
     * returns a descriptor for EventsController::add() to dispatch from —
     * never from in here (see the rewritten lock comment on storeAccount()
     * above: dispatching inside this lock/closure would self-deadlock
     * ConnectFetchJob on the same key it takes to complete the write).
     *
     * @return array{ok:bool, error?:string, status?:int, selection?:?array, pending?:array{platform:string,connectionId:string,resourceId:string}}
     */
    private function storeAccountDeferred(User $user, string $platform, string $url): array
    {
        return $this->withPlatformLock($user, $platform, function () use ($user, $platform, $url): array {
            $rid = 'acct-'.substr(sha1(strtolower(trim($url))), 0, 16);
            $existing = IntegrationConnection::query()
                ->where('user_id', $user->id)->where('platform', $platform)->where('resource_id', $rid)
                ->first();

            if ($existing === null && $this->accountRows($user, $platform)->count() >= self::MAX_ACCOUNTS) {
                return $this->fail('You can connect up to '.self::MAX_ACCOUNTS.' organisers per platform.', 422);
            }

            $row = $this->writePendingAccountRow($user, $platform, $rid, $url, $existing);

            return [
                'ok' => true,
                'selection' => $this->selection($user),
                'pending' => ['platform' => $platform, 'connectionId' => (string) $row->id, 'resourceId' => $rid],
            ];
        });
    }

    /**
     * Slice 7 Phase 4 / parent §7 step 2: a standalone event is a
     * `content.items` row of kind `event` in the `events` pool, not a
     * `resource_kind='event'` connection.
     *
     * This is the step that stops step 3 — emptying the standalone payload on
     * the public integrations wire — being a data-loss event. Every event added
     * from here on lands where the pool can publish it; every event added
     * BEFORE is carried across by StandaloneEventBackfiller, on the same coord
     * and through the same mapper, so the two cannot drift.
     *
     * No connection lock and no per-platform cap any more, for the reasons
     * storeCustom() already gave: neither applies to a pool item. The write is
     * an idempotent upsert on a deterministic coord, so there is no
     * read-then-write span to serialise, and the pool's cap is the section's.
     * The retired MAX_EVENTS 422 is named in the wire manifest.
     *
     * The coord basis is the scraped `link`, NOT the normalised input URL: the
     * connection's own `event-<hex>` resource_id derives from `link` too
     * (EventsPayload::id), so a migrated row and a re-added one land on one
     * item.
     *
     * @param  array<string, mixed>  $payload
     */
    private function storeStandalone(User $user, array $payload): array
    {
        $event = StandaloneEventPayload::fromArray($payload)->event();
        $url = trim((string) ($event['link'] ?? ''));

        if ($url === '') {
            return $this->fail('That event has no link we can save.', 422);
        }

        $written = $this->manualEvents->addStandalone($user, $url, $event);
        if ($written === null) {
            return $this->fail('Add your site before saving events.', 422);
        }

        return ['ok' => true, 'selection' => $this->selection($user)];
    }

    /**
     * Convergence Phase 6: a hand-added event is a `content.items` row of kind
     * `event` in the `events` pool, not a `partna.manual_event` connection.
     *
     * Kept separate from storeStandalone() even now that both write the pool:
     * this arm is a LINK the owner called an event and has no dates, prices or
     * venue to project, so its thin projection is a different mapping — not a
     * degenerate case of the scraped one.
     *
     * `image` is deliberately dropped. Phase 3 declined to mint
     * content.media_assets for third-party image URLs (LinkPoolWriter's
     * docblock), and this lane inherits that decision rather than reopening it —
     * a hand-added event card carries no artwork.
     */
    private function storeCustom(User $user, string $raw): array
    {
        $url = $this->linkCard->normalizeUrl($raw);
        if ($url === null) {
            return $this->fail('Enter a valid link, or an Eventbrite / Humanitix URL.', 422);
        }

        $snap = $this->linkCard->snapshotOrMinimal($url);

        $written = $this->manualEvents->add($user, $url, $snap['name'] ?? null, $snap['description'] ?? null);
        if ($written === null) {
            return $this->fail('Add your site before saving events.', 422);
        }

        return ['ok' => true, 'selection' => $this->selection($user)];
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

        $connection = IntegrationConnection::updateOrCreate(
            ['user_id' => $user->id, 'platform' => $platform, 'resource_id' => $resourceId],
            $values,
        );

        // Bell notice on a genuine connect, gated on wasRecentlyCreated exactly as
        // ManagesIntegrationConnection::upsertConnection does (a per-instance flag,
        // true only on the object that inserted). Both callers route through here:
        // storeAccount()'s organiser row notifies, storeStandalone()'s per-event row
        // is dropped by the notifier's resource_kind guard. Both are correct, so
        // neither is special-cased.
        if ($connection->wasRecentlyCreated) {
            app(IntegrationNotifier::class)->connected($connection);
        }
    }

    /**
     * CA-W5 pending twin of writeRow(): same updateOrCreate, but 'pending' status
     * and a payload MERGED over whatever is stored rather than replacing it — that
     * merge is what carries hiddenEventIds (and the previously scraped
     * organiser/upcoming, so the card never blanks) across a reconnect while
     * ConnectFetchJob's fetch is still queued. Account rows only, so no
     * resource_kind stamp — same contract as writeRow()'s null default.
     */
    private function writePendingAccountRow(User $user, string $platform, string $resourceId, string $url, ?IntegrationConnection $existing): IntegrationConnection
    {
        return IntegrationConnection::updateOrCreate(
            ['user_id' => $user->id, 'platform' => $platform, 'resource_id' => $resourceId],
            [
                'payload' => [...EventsAccountPayload::fromArray($existing?->payload)->toArray(), 'url' => $url],
                'is_active' => true,
                'last_refreshed_at' => null,
                'last_refresh_status' => 'pending',
                'last_refresh_error' => null,
                'consecutive_failures' => 0,
            ],
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

    /** @return array{eventUrl:callable,fetchEvent:callable,accountUrl:callable,fetchAccount:callable}|null */
    private function adapter(string $provider): ?array
    {
        return $this->adapters[$provider] ?? null;
    }

    // Same lever, same strict in_array as DefersBespokeConnect::shouldDeferConnect()
    // — duplicated (not shared) because that trait is a controller concern requiring
    // ApiController + ManagesIntegrationConnection, neither of which a service has.
    private function shouldDefer(string $platform): bool
    {
        return in_array($platform, (array) config('partna.connect.deferred', []), true);
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
     * Manually-ordered events first (their saved positions), then the
     * soonest-first fallback for anything the order doesn't mention; dateless
     * events (e.g. custom links) sort last within the fallback.
     *
     * @param  list<array<string,mixed>>  $events
     * @param  array<string,int>  $orderIndex
     * @return list<array<string,mixed>>
     */
    private function sortEvents(array $events, array $orderIndex = []): array
    {
        usort($events, function (array $a, array $b) use ($orderIndex) {
            $aPos = $orderIndex[$a['id'] ?? ''] ?? null;
            $bPos = $orderIndex[$b['id'] ?? ''] ?? null;
            if ($aPos !== null || $bPos !== null) {
                if ($aPos === null) {
                    return 1;
                }
                if ($bPos === null) {
                    return -1;
                }

                return $aPos <=> $bPos;
            }

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
