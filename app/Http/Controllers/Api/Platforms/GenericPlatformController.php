<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\PlatformConnectRequest;
use App\Http\Requests\Platforms\PlatformHighlightsRequest;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
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

    public function __construct(private readonly PlatformRegistry $registry) {}

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

        $result = $strategy->resolve($request->validated()[$descriptor->connectField()]);
        if ($result->failed()) {
            return $this->error($result->error ?? $descriptor->connectErrorMessage() ?? 'Enter a valid link.', $result->status);
        }

        $selection = $result->selection;

        // Link-only platforms keep their existing LinkPayload round-trip; every
        // other archetype stores the strategy's selection verbatim, exactly as
        // the per-platform controllers did.
        if ($descriptor->payloadClass() === LinkPayload::class) {
            $selection = LinkPayload::fromArray($selection)->toArray();
        }

        $resourceClass = $descriptor->resourceClass();

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

    // GET /api/platforms/{platform}/recent?account={id} — fresh picker items for
    // the requested account (first account when no id is given).
    public function recent(Request $request): JsonResponse
    {
        $strategy = $this->descriptor()->highlightsStrategy();
        abort_if($strategy === null, 404);

        $row = $this->requestedAccountRow($this->currentUser($request), $request->query('account'));
        $identity = $strategy->identity($row?->payload ?? []);
        if ($identity === null) {
            return $this->error($strategy->notConnectedMessage(), 404);
        }

        $items = $strategy->recentItems($identity);
        if ($items === null) {
            return $this->error($strategy->loadErrorMessage(), 422);
        }

        return $this->success([$strategy->responseKey() => $items]);
    }

    // POST /api/platforms/{platform}/highlights?account={id} — snapshot the
    // chosen items onto that account's stored selection (empty list clears).
    // Locked read→mutate→write, mirroring the deleted per-platform controllers.
    public function highlights(PlatformHighlightsRequest $request): JsonResponse
    {
        $descriptor = $this->descriptor();
        $strategy = $descriptor->highlightsStrategy();
        abort_if($strategy === null, 404);

        $validated = $request->validated();
        $user = $this->currentUser($request);
        $accountId = $request->query('account');

        return $this->withConnectionLock($user, function () use ($user, $descriptor, $strategy, $validated, $accountId): JsonResponse {
            $row = $this->requestedAccountRow($user, $accountId);
            $selection = $row?->payload;
            if (! $row || ! $selection) {
                return $this->error($strategy->notConnectedMessage(), 404);
            }

            $identity = $strategy->identity($selection);
            if ($identity === null) {
                return $this->error($strategy->notConnectedMessage(), 404);
            }

            $items = $strategy->recentItems($identity);
            if ($items === null) {
                return $this->error($strategy->loadErrorMessage(), 422);
            }

            $selection = $strategy->apply($selection, $items, $validated[$strategy->requestField()]);
            $this->writeConnection($user, $selection, $row->resource_id);

            $resourceClass = $descriptor->resourceClass();

            return $this->success(['id' => $row->resource_id, ...(new $resourceClass($selection))->resolve()]);
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
    // accountRows()->first() matches SingleSelectionPlatformController exactly for
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
