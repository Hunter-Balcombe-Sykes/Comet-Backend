<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\EventsPayload;
use App\Services\Platforms\Payloads\EventsAccountPayload;
use App\Services\Platforms\Payloads\StandaloneEventPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

// Shared base for the events platforms (Eventbrite + Humanitix). Two row
// kinds live under each platform:
//
//  - ACCOUNT rows — an organiser/host page whose upcoming events auto-refresh
//    daily. Multiple accounts supported (shop-style list, capped).
//  - STANDALONE EVENT rows (resource_id 'event-<id>') — a single event added
//    by URL, possibly from an organiser the user never connected. Removable
//    individually like any other selected item.
//
// Account-derived events can be hidden one at a time: the event id lands in
// the account row's hiddenEventIds and the event is pruned from next/upcoming
// at write + on every refresh, so readers (public wire included) never see it.
abstract class EventsPlatformController extends ApiController
{
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    private const MAX_STANDALONE_EVENTS = 10;

    /** Canonicalise an organiser/host URL, else null. */
    abstract protected function normalizeAccountUrl(string $input): ?string;

    /** Scrape an organiser/host page. @return array{organiser:?string, events:list<array<string,mixed>>}|null */
    abstract protected function fetchAccountEvents(string $url): ?array;

    /** Canonicalise a single event-page URL, else null. */
    abstract protected function normalizeEventUrl(string $input): ?string;

    /** Scrape one event page. @return array<string,mixed>|null */
    abstract protected function fetchSingleEvent(string $url): ?array;

    /** Human label ('Eventbrite') for error copy. */
    abstract protected function platformLabel(): string;

    /** Error copy for a bad organiser/host URL. */
    abstract protected function accountUrlError(): string;

    /** Error copy for a bad event URL. */
    abstract protected function eventUrlError(): string;

    // ── Accounts ──────────────────────────────────────────────────────────

    /** Shared connect flow — subclasses expose it under their typed FormRequest. */
    protected function addAccount(User $user, string $rawUrl): JsonResponse
    {
        $url = $this->normalizeAccountUrl($rawUrl);
        if (! $url) {
            return $this->error($this->accountUrlError(), 422);
        }

        $result = $this->fetchAccountEvents($url);
        if ($result === null) {
            return $this->error('Could not load that '.$this->platformLabel().' page.', 422);
        }

        // PWL-4: this write races removeEvent() (already locked) + ScheduledRefresh
        // on the same platform+user key. fetchAccountEvents() above is the scrape —
        // it stays OUTSIDE the lock; only the read→mutate→write is wrapped.
        return $this->withConnectionLock($user, function () use ($user, $url, $result): JsonResponse {
            // Re-adding an already-connected page keeps its per-event hides.
            $existingRow = $this->matchAccountByCanonical($user, $url);
            $hidden = EventsAccountPayload::fromArray($existingRow?->payload)->hiddenEventIds();

            $payload = EventsPayload::accountPayload($url, $result['organiser'], $result['events'], $hidden);
            $row = $this->writeAccountConnection($user, $url, $payload);
            if ($row === null) {
                return $this->error('You can connect up to '.$this->maxAccounts().' accounts.', 422);
            }

            return $this->success(['id' => $row->resource_id, ...$this->accountData($payload)]);
        });
    }

    // GET /api/platforms/{platform}/accounts — every connected organiser/host.
    public function accounts(Request $request): JsonResponse
    {
        return $this->success(['accounts' => $this->accountsData($this->currentUser($request))]);
    }

    // DELETE /api/platforms/{platform}/accounts/{id} — remove one account
    // (its events leave the sitepage with it).
    // PWL-4: races addAccount()/removeEvent() (both locked) + ScheduledRefresh.
    public function removeAccount(Request $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->withConnectionLock($user, function () use ($user, $id): JsonResponse {
            if (! $this->accountRows($user)->firstWhere('resource_id', $id)) {
                return $this->error('Account not found.', 404);
            }
            $this->forgetConnection($user, $id);

            return $this->success(['accounts' => $this->accountsData($user)]);
        });
    }

    // ── Standalone events ─────────────────────────────────────────────────

    /** Shared add-event flow — subclasses expose it under their typed FormRequest. */
    protected function addStandaloneEvent(User $user, string $rawUrl): JsonResponse
    {
        $url = $this->normalizeEventUrl($rawUrl);
        if (! $url) {
            return $this->error($this->eventUrlError(), 422);
        }

        $event = $this->fetchSingleEvent($url);
        if ($event === null) {
            return $this->error('Could not load that '.$this->platformLabel().' event.', 422);
        }

        $payload = EventsPayload::standalonePayload($event);
        $rid = 'event-'.$payload['id'];

        // PWL-4: races removeEvent() (already locked) + ScheduledRefresh on the
        // same platform+user key. fetchSingleEvent() above is the scrape — it
        // stays OUTSIDE the lock; only the cap-check + write is wrapped.
        return $this->withConnectionLock($user, function () use ($user, $payload, $rid): JsonResponse {
            if ($this->eventRows($user)->firstWhere('resource_id', $rid) === null
                && $this->eventRows($user)->count() >= self::MAX_STANDALONE_EVENTS) {
                return $this->error('You can add up to '.self::MAX_STANDALONE_EVENTS.' individual events.', 422);
            }

            $this->writeConnection($user, $payload, $rid, resourceKind: 'event');

            return $this->success(['selection' => $this->selectionData($user)]);
        });
    }

    // DELETE /api/platforms/{platform}/events/{id} — remove ONE event. A
    // standalone event row is deleted outright; an account-derived event is
    // hidden (id recorded on its account row, pruned from next/upcoming).
    public function removeEvent(Request $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->withConnectionLock($user, function () use ($user, $id): JsonResponse {
            $standalone = $this->eventRows($user)->firstWhere('resource_id', 'event-'.$id);
            if ($standalone) {
                $this->forgetConnection($user, $standalone->resource_id);

                return $this->success(['selection' => $this->selectionData($user)]);
            }

            // Find the account row owning the event and hide it there.
            foreach ($this->accountRows($user) as $row) {
                $account = EventsAccountPayload::fromArray($row->payload);
                $upcoming = EventsPayload::withIds($account->upcoming());
                if (! collect($upcoming)->contains(fn (array $e) => ($e['id'] ?? null) === $id)) {
                    continue;
                }

                $next = EventsPayload::accountPayload(
                    (string) ($account->url() ?? ''),
                    $account->organiser(),
                    $upcoming,
                    [...$account->hiddenEventIds(), $id],
                );
                $this->writeConnection($user, $next, $row->resource_id);

                return $this->success(['selection' => $this->selectionData($user)]);
            }

            return $this->error('Event not found.', 404);
        });
    }

    // ── Selection + forget ────────────────────────────────────────────────

    // GET /api/platforms/{platform}/selection — accounts + the merged event
    // list (account events + standalone, hidden pruned, soonest first). Legacy
    // top-level url/organiser/next/upcoming mirror the first account + merged
    // list so pre-rework consumers keep rendering during the deploy gap.
    public function selection(Request $request): JsonResponse
    {
        return $this->success(['selection' => $this->selectionData($this->currentUser($request))]);
    }

    // DELETE /api/platforms/{platform} — clear everything (accounts + events).
    // PWL-4: races every other writer above (all locked) + ScheduledRefresh.
    public function forget(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->withConnectionLock($user, function () use ($user): JsonResponse {
            $this->forgetAllConnections($user);

            return $this->success(['selection' => null]);
        });
    }

    // ── Shapes ────────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    protected function selectionData(User $user): ?array
    {
        $accounts = $this->accountsData($user);
        $events = $this->mergedEvents($user);
        if ($accounts === [] && $events === []) {
            return null;
        }

        $first = $accounts[0] ?? null;

        return [
            'accounts' => $accounts,
            'events' => $events,
            // Legacy mirror (pre-multi-account dashboard + sitepage shape).
            'url' => $first['url'] ?? null,
            'organiser' => $first['organiser'] ?? null,
            'next' => $events[0] ?? null,
            'upcoming' => $events,
        ];
    }

    /** @return list<array<string,mixed>> */
    protected function accountsData(User $user): array
    {
        return $this->accountsListData($user, fn (array $payload) => $this->accountData($payload));
    }

    /** @return array<string,mixed> */
    private function accountData(array $payload): array
    {
        $account = EventsAccountPayload::fromArray($payload);
        $upcoming = $this->dropElapsed(EventsPayload::withIds($account->upcoming()));

        return [
            'url' => $account->url(),
            'organiser' => $account->organiser(),
            'next' => $upcoming[0] ?? null,
            'upcoming' => $upcoming,
        ];
    }

    /**
     * Account events (tagged with their account) + standalone events, elapsed
     * dropped at read time, soonest first.
     *
     * @return list<array<string,mixed>>
     */
    protected function mergedEvents(User $user): array
    {
        $events = [];

        foreach ($this->accountRows($user) as $row) {
            foreach (EventsPayload::withIds(EventsAccountPayload::fromArray($row->payload)->upcoming()) as $event) {
                $events[] = [...$event, 'source' => 'account', 'accountId' => $row->resource_id];
            }
        }

        foreach ($this->eventRows($user) as $row) {
            $events[] = [...StandaloneEventPayload::fromArray($row->payload)->event(), 'source' => 'custom'];
        }

        $events = $this->dropElapsed($events);

        usort($events, function (array $a, array $b) {
            $aDate = $a['startDate'] ?? '';
            $bDate = $b['startDate'] ?? '';
            if ($aDate === '' || $bDate === '') {
                return $aDate === $bDate ? 0 : ($aDate === '' ? -1 : 1);
            }

            return Carbon::parse($aDate)->getTimestamp() <=> Carbon::parse($bDate)->getTimestamp();
        });

        return $events;
    }

    /**
     * Keep events whose end (or start, when no end is known) is still in the
     * future — an in-progress event still shows. Date strings carry the
     * event's LOCAL offset, so Carbon::parse (not string compare) is required.
     *
     * @param  list<array<string,mixed>>  $events
     * @return list<array<string,mixed>>
     */
    private function dropElapsed(array $events): array
    {
        $now = now();

        return array_values(array_filter($events, function (array $e) use ($now) {
            $compareDate = $e['endDate'] ?? $e['startDate'] ?? null;
            if (empty($compareDate)) {
                return true;
            }

            return Carbon::parse($compareDate)->gte($now);
        }));
    }

    /**
     * Standalone-event rows ('event-*'), ordered.
     *
     * @return Collection<int, IntegrationConnection>
     */
    protected function eventRows(User $user)
    {
        return $this->connectionsFor($user)->filter(
            fn (IntegrationConnection $row) => $row->resource_kind === 'event',
        )->values();
    }
}
