<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\PlatformConnectRequest;
use App\Http\Requests\Platforms\PlatformHighlightsRequest;
use App\Jobs\Platforms\ConnectFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\ConnectResolver;
use App\Services\Platforms\HighlightsPicker;
use App\Services\Platforms\Payloads\LinkPayload;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Registry\PlatformRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Registry-driven controller for the link-only archetype. The route group injects
// the platform slug as a route default ('platform' => '<slug>'); this controller
// resolves the matching PlatformDescriptor and serves the uniform
// connect / selection / forget shape that the per-platform controllers used to.
//
// Storage + authorization come from ManagesIntegrationConnection (unchanged); the
// platform's URL/handle parsing comes from the descriptor's ConnectStrategy; the
// response shape comes from the descriptor's resourceClass(). LinkPayload is the
// typed boundary between the stored jsonb and the (contract-frozen) resource.
class GenericPlatformController extends ApiController
{
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    public function __construct(
        private readonly PlatformRegistry $registry,
        private readonly ConnectResolver $connectResolver,
        private readonly HighlightsPicker $picker,
    ) {}

    // The platform key for the ManagesIntegrationConnection trait. Read from the
    // route default the integrations group sets per migrated platform.
    protected function platform(): string
    {
        $platform = request()->route('platform');

        // Should never happen — every generic route sets the default — but fail
        // closed rather than write under a null platform.
        abort_if(! is_string($platform) || $platform === '', 404);

        return $platform;
    }

    // POST /api/platforms/{platform}/connect — resolve the input via the
    // descriptor's connect strategy (parse + any upstream fetch), store the
    // canonical selection, echo it. Multi-account platforms add an account row
    // (capped, shop-style); single-selection platforms upsert the one row.
    public function connect(PlatformConnectRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $descriptor = $this->descriptor();

        // Capability checkpoint (spec §9) — true for everyone today; the gate
        // exists so future paid-tier/account-type rules are a per-descriptor flag.
        $this->authorizeForUser($user, 'connect', [new IntegrationConnection(['user_id' => $user->id]), $descriptor]);

        $strategy = $descriptor->connectStrategy();
        abort_if($strategy === null, 404);

        // ConnectResolver opens a shared FetchBudget
        // (config('partna.http_fetch.connect_budget_seconds')) around the whole
        // resolve/identify, so EVERY collaborator that fetches during it is
        // bounded — not just the ones routing through SafeUrlFetcher. It also
        // decides sync vs deferred (Unit 11 W6): $outcome->deferred is false
        // unless BOTH the descriptor supports it and the platform is named in
        // config('partna.connect.deferred') — empty by default, so every branch
        // below this point is unreachable and behaviour is unchanged on merge.
        $outcome = $this->connectResolver->resolve($descriptor, $request->validated()[$descriptor->connectField()]);
        $result = $outcome->result;
        if ($result->failed()) {
            return $this->error($result->error ?? $descriptor->connectErrorMessage() ?? 'Enter a valid link.', $result->status);
        }

        $selection = $result->selection;

        // Link-only platforms keep their existing LinkPayload round-trip; every
        // other archetype stores the strategy's selection verbatim, exactly as
        // the per-platform controllers did. None of the 8 deferred platforms use
        // LinkPayload (they're feed/oEmbed archetypes), so this never runs on
        // the deferred branch below — kept common because it's a no-op there.
        if ($descriptor->payloadClass() === LinkPayload::class) {
            $selection = LinkPayload::fromArray($selection)->toArray();
        }

        $resourceClass = $descriptor->resourceClass();

        if ($outcome->deferred) {
            return $this->connectDeferred($user, $descriptor, $selection, $result->accountKey);
        }

        if ($descriptor->multiAccount()) {
            $key = $result->accountKey ?? $this->defaultAccountKey($selection);
            if ($key !== null) {
                if ($descriptor->hasHighlights()) {
                    // Re-adding an already-connected account keeps its curated highlights.
                    $selection['highlights'] = $this->preserveHighlights($user, $key);
                }
                $row = $this->writeAccountConnection($user, $key, $selection);
                if ($row === null) {
                    return $this->error('You can connect up to '.$this->maxAccounts().' accounts.', 422);
                }

                return $this->success(['id' => $row->resource_id, ...(new $resourceClass($selection))->resolve()]);
            }
        }

        $this->writeConnection($user, $selection);

        return $this->success((new $resourceClass($selection))->resolve());
    }

    /**
     * Deferred-connect write path (Unit 11 W6): $selection is the PARTIAL
     * payload identify() derived (no vendor call yet). Writes a 'pending' row
     * — MERGED over any existing payload so a reconnect never blanks the card
     * — dispatches ConnectFetchJob to fill it, and returns 202 with a body
     * that reuses today's 200 key names (see the class docblock's "202 body"
     * note): an unmodified dashboard client that discards the response and
     * only checks for 2xx keeps working, and a client that reads the body
     * gets the same field names either way.
     *
     * Mirrors connect()'s own multiAccount()/single-selection split: the
     * account-row write (writeAccountConnection) for the six multi-account
     * deferred platforms, the single default-resource write (writeConnection,
     * extended in W6 with its own $pending/merge support) for pinterest/strava.
     */
    private function connectDeferred(User $user, PlatformDescriptor $descriptor, array $selection, ?string $accountKey): JsonResponse
    {
        $slug = $descriptor->key();

        if ($descriptor->multiAccount()) {
            $key = $accountKey ?? $this->defaultAccountKey($selection);
            if ($key === null) {
                // Unreachable in practice — identify() always derives at least the
                // identity key on success (DeferredConnectParityTest) — defensive only.
                return $this->error($descriptor->connectErrorMessage() ?? 'Enter a valid link.', 422);
            }
            if ($descriptor->hasHighlights()) {
                // Same carry-forward as the sync path: on a reconnect, curated
                // highlights survive. Redundant with the merge below (which would
                // preserve an untouched 'highlights' key anyway) but kept explicit
                // to mirror the sync branch exactly and avoid a second code path
                // to reason about.
                $selection['highlights'] = $this->preserveHighlights($user, $key);
            }
            $row = $this->writeAccountConnection($user, $key, $selection, pending: true);
            if ($row === null) {
                return $this->error('You can connect up to '.$this->maxAccounts().' accounts.', 422);
            }
        } else {
            $row = $this->writeConnection($user, $selection, pending: true);
        }

        ConnectFetchJob::dispatch($row->id, $slug)->afterCommit();

        $statusUrl = url("/api/platforms/{$slug}/connect/status");
        $body = ['status' => 'pending'];
        // 'id' mirrors the 200 shape's own asymmetry (present on multi-account
        // platforms only — see the non-deferred branch above, which likewise
        // omits it for pinterest/strava).
        if ($descriptor->multiAccount()) {
            $body['id'] = $row->resource_id;
            $statusUrl .= '?account='.$row->resource_id;
        }
        $body['statusUrl'] = $statusUrl;

        // Selection keys present are exactly what identify() derived — a
        // same-named SUBSET of the 200 shape's keys (verified per platform by
        // DeferredConnectParityTest), not the full Resource-shaped payload:
        // running $selection through $resourceClass here would add explicit
        // null placeholders (e.g. 'name' => null) for fields identify() never
        // touches, implying "confirmed absent" rather than "not yet known".
        return $this->success([...$body, ...$selection], 202);
    }

    // GET /api/platforms/{platform}/connect/status?account={resourceId} — poll
    // endpoint for a deferred connect (Unit 11 W6). Only registered (see
    // routes/api/platforms.php) for descriptors where supportsDeferredConnect()
    // is true; `account` is the `id` the 202 body returned — omit it for
    // pinterest/strava (single-selection; requestedAccountRow() falls back to
    // the platform's one row). 404, never 403, for a resource that doesn't
    // exist or isn't the caller's: requestedAccountRow() is already scoped to
    // $user->integrationConnections(), so another user's row is never visible
    // to look up in the first place — no separate policy check needed (mirrors
    // recent()/highlights() above).
    public function connectStatus(Request $request): JsonResponse
    {
        $descriptor = $this->descriptor();
        $row = $this->requestedAccountRow($this->currentUser($request), $request->query('account'));

        if ($row === null) {
            return $this->error('Account not found.', 404);
        }

        // Stale-pending escape hatch: a worker that dies between dispatch and
        // failed() (or the process is killed outright) leaves the row 'pending'
        // forever with nothing to flip it — without this, the client polls
        // indefinitely. No new column, no reaper cron; five minutes comfortably
        // exceeds ConnectFetchJob's timeout (45s) + its two backoff retries.
        //
        // The error string is deliberately NOT connectFetchErrorMessage(): that
        // wording ("Could not find that Spotify link") would misattribute OUR
        // infrastructure dying to a vendor miss the job never actually
        // determined — same reasoning ConnectFetchJob's own lock-timeout catch
        // uses this exact sentence for (see that file).
        if ($row->last_refresh_status === 'pending') {
            if ($row->updated_at !== null && $row->updated_at->lt(now()->subMinutes(5))) {
                return $this->success(['status' => 'failed', 'error' => "We couldn't save your connection just then — please try again."]);
            }

            return $this->success(['status' => 'pending']);
        }

        if ($row->last_refresh_status === 'ok') {
            return $this->success([
                'status' => 'ready',
                'id' => $row->resource_id,
                'connection' => $row->payload ? $this->shape($descriptor, $row->payload) : null,
            ]);
        }

        // 'unavailable' / 'error' — last_refresh_error is the row's stored
        // user-facing sentence (ConnectFetchJob populates it from
        // connectFetchErrorMessage() for every expected failure), so it is
        // always safe to display verbatim; it never carries internal scraper
        // detail.
        return $this->success(['status' => 'failed', 'error' => $row->last_refresh_error]);
    }

    // GET /api/platforms/{platform}/recent?account={id} — picker items for the
    // requested account (first account when no id is given). Served from the
    // connection's `recent` snapshot when fresh (HighlightsPicker), so opening
    // the modal repeatedly no longer live-scrapes the vendor every time
    // (LIFE-21..24).
    public function recent(Request $request): JsonResponse
    {
        $strategy = $this->descriptor()->highlightsStrategy();
        abort_if($strategy === null, 404);

        $row = $this->requestedAccountRow($this->currentUser($request), $request->query('account'));
        if ($row === null) {
            return $this->error($strategy->notConnectedMessage(), 404);
        }

        $identity = $strategy->identity($row->payload);
        if ($identity === null) {
            return $this->error($strategy->notConnectedMessage(), 404);
        }

        $items = $this->picker->items($strategy, $row, $identity);
        if ($items === null) {
            return $this->error($strategy->loadErrorMessage(), 422);
        }

        return $this->success([$strategy->responseKey() => $items]);
    }

    // POST /api/platforms/{platform}/highlights?account={id} — snapshot the
    // chosen items onto that account's stored selection (empty list clears).
    //
    // W3 / LIFE-21 lock-boundary fix: everything vendor-facing (the picker's
    // live-fetch fallback, and any PreparesHighlightItems::prepare() pricing
    // call) now runs OUTSIDE the per-user connection lock. A lock is a
    // deliberate bottleneck — it serialises concurrent work — and holding one
    // across a call to someone else's server lets a stranger's latency decide
    // how long that bottleneck lasts. Mirrors ScheduledRefresh::run()'s
    // fetch-outside/write-inside shape exactly.
    public function highlights(PlatformHighlightsRequest $request): JsonResponse
    {
        $descriptor = $this->descriptor();
        $strategy = $descriptor->highlightsStrategy();
        abort_if($strategy === null, 404);

        $validated = $request->validated();
        $user = $this->currentUser($request);
        $accountId = $request->query('account');
        $chosenIds = $validated[$strategy->requestField()];
        $resourceClass = $descriptor->resourceClass();

        $row = $this->requestedAccountRow($user, $accountId);
        if (! $row || ! $row->payload) {
            return $this->error($strategy->notConnectedMessage(), 404);
        }

        $identity = $strategy->identity($row->payload);
        if ($identity === null) {
            return $this->error($strategy->notConnectedMessage(), 404);
        }

        $items = $this->picker->items($strategy, $row, $identity);
        if ($items === null) {
            return $this->error($strategy->loadErrorMessage(), 422);
        }
        $items = $this->picker->prepared($strategy, $items, $chosenIds);

        return $this->withConnectionLock($user, function () use ($user, $strategy, $items, $chosenIds, $accountId, $resourceClass): JsonResponse {
            // Authoritative re-read UNDER the lock. Load-bearing: reading
            // outside the lock and writing inside it, off the OUTSIDE read,
            // would reintroduce the lost update the lock exists to prevent —
            // another highlights save (or a scheduled refresh) landing in the
            // gap between the read above and the lock being acquired would be
            // silently overwritten by this write's stale $row->payload. This
            // re-read is what makes fetch-outside/write-inside safe.
            $fresh = $this->requestedAccountRow($user, $accountId);
            if (! $fresh || ! $fresh->payload) {
                return $this->error($strategy->notConnectedMessage(), 404);
            }

            $selection = $strategy->apply($fresh->payload, $items, $chosenIds);
            $this->writeConnection($user, $selection, $fresh->resource_id);

            return $this->success(['id' => $fresh->resource_id, ...(new $resourceClass($selection))->resolve()]);
        });
    }

    /** Canonical per-account key of a freshly-built selection (mirrors the
     *  deleted single-selection base's default — dedupe + id source). */
    private function defaultAccountKey(array $selection): ?string
    {
        $key = $selection['handle'] ?? $selection['input'] ?? $selection['url'] ?? $selection['link'] ?? null;

        return is_string($key) && trim($key) !== '' ? $key : null;
    }

    // GET /api/platforms/{platform}/selection — the first connected account's
    // selection (or null), hydrated through the descriptor's typed payload DTO.
    // accountRows()->first() matches the deleted single-selection base exactly for
    // both single- and multi-account platforms (the single row is stored under
    // resource_id = <slug>, which accountRows() includes).
    public function selection(Request $request): JsonResponse
    {
        $descriptor = $this->descriptor();
        $payload = $this->accountRows($this->currentUser($request))->first()?->payload;

        if ($payload === null) {
            return $this->success(['selection' => null]);
        }

        return $this->success(['selection' => $this->shape($descriptor, $payload)]);
    }

    // GET /api/platforms/{platform}/accounts — every connected account, ordered,
    // each with its public resource_id as `id`.
    public function accounts(Request $request): JsonResponse
    {
        $descriptor = $this->descriptor();

        return $this->success(['accounts' => $this->accountsList($descriptor, $this->currentUser($request))]);
    }

    // DELETE /api/platforms/{platform}/accounts/{id} — remove one account.
    public function removeAccount(Request $request, string $id): JsonResponse
    {
        $descriptor = $this->descriptor();
        $user = $this->currentUser($request);

        if (! $this->accountRows($user)->firstWhere('resource_id', $id)) {
            return $this->error('Account not found.', 404);
        }
        $this->forgetConnection($user, $id);

        return $this->success(['accounts' => $this->accountsList($descriptor, $user)]);
    }

    // DELETE /api/platforms/{platform} — clear every connection for the platform.
    public function forget(Request $request): JsonResponse
    {
        $this->forgetAllConnections($this->currentUser($request));

        return $this->success(['selection' => null]);
    }

    // Resolve the descriptor for the current route's platform, or 404.
    private function descriptor(): PlatformDescriptor
    {
        $descriptor = $this->registry->get($this->platform());
        abort_if($descriptor === null, 404);

        return $descriptor;
    }

    // Hydrate a stored payload through the descriptor's typed DTO, then serialize
    // through its (frozen) resource. The DTO is the single tolerant-hydration home;
    // the resource allowlists its own key subset, so any extra DTO key is dropped.
    private function shape(PlatformDescriptor $descriptor, array $payload): array
    {
        $payloadClass = $descriptor->payloadClass() ?? LinkPayload::class;
        $resourceClass = $descriptor->resourceClass();

        return (new $resourceClass($payloadClass::fromArray($payload)->toArray()))->resolve();
    }

    /** @return list<array<string,mixed>> */
    private function accountsList(PlatformDescriptor $descriptor, User $user): array
    {
        return $this->accountsListData($user, fn (array $payload) => $this->shape($descriptor, $payload));
    }
}
