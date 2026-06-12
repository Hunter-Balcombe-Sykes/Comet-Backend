<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Base for the connect/selection/forget platforms (no picker step). Subclasses
// implement connect() — the only part that differs — plus platform() and the
// resource class that shapes responses.
//
// Platforms that opt into multiple connected accounts (watch/listen/events
// groups) override supportsMultipleAccounts(): connect() then ADDS an account
// row (capped, shop-style) instead of replacing the single selection, and the
// /accounts + DELETE /accounts/{id} routes manage the list. selection() always
// returns the FIRST account so existing consumers keep working.
abstract class SingleSelectionPlatformController extends ApiController
{
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    /** @return class-string The connection resource that shapes responses. */
    abstract protected function resourceClass(): string;

    /** Watch/listen/events platforms flip this to allow multiple accounts. */
    protected function supportsMultipleAccounts(): bool
    {
        return false;
    }

    /** Canonical per-account key of a freshly-built selection (dedupe + id source). */
    protected function accountKeyOf(array $selection): ?string
    {
        $key = $selection['handle'] ?? $selection['input'] ?? $selection['url'] ?? $selection['link'] ?? null;

        return is_string($key) && trim($key) !== '' ? $key : null;
    }

    // GET /api/platforms/{platform}/selection — first account (compat shape).
    public function selection(Request $request): JsonResponse
    {
        $payload = $this->accountRows($this->currentUser($request))->first()?->payload;
        $resource = $this->resourceClass();

        return $this->success(['selection' => $payload ? (new $resource($payload))->resolve() : null]);
    }

    // GET /api/platforms/{platform}/accounts — every connected account, ordered.
    public function accounts(Request $request): JsonResponse
    {
        return $this->success(['accounts' => $this->accountsData($this->currentUser($request))]);
    }

    // DELETE /api/platforms/{platform}/accounts/{id} — remove one account.
    public function removeAccount(Request $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);
        if (! $this->accountRows($user)->firstWhere('resource_id', $id)) {
            return $this->error('Account not found.', 404);
        }
        $this->forgetConnection($user, $id);

        return $this->success(['accounts' => $this->accountsData($user)]);
    }

    // DELETE /api/platforms/{platform} — clear every connection for the platform.
    public function forget(Request $request): JsonResponse
    {
        $this->forgetAllConnections($this->currentUser($request));

        return $this->success(['selection' => null]);
    }

    /** Store + echo the freshly-connected selection (adds an account when multi). */
    protected function connected(\App\Models\Core\User\User $user, array $selection): JsonResponse
    {
        $resource = $this->resourceClass();

        if ($this->supportsMultipleAccounts() && ($key = $this->accountKeyOf($selection)) !== null) {
            $row = $this->writeAccountConnection($user, $key, $selection);
            if ($row === null) {
                return $this->error('You can connect up to '.$this->maxAccounts().' accounts.', 422);
            }

            return $this->success(['id' => $row->resource_id, ...(new $resource($selection))->resolve()]);
        }

        $this->writeConnection($user, $selection);

        return $this->success((new $resource($selection))->resolve());
    }

    /** @return list<array<string, mixed>> */
    protected function accountsData(\App\Models\Core\User\User $user): array
    {
        $resource = $this->resourceClass();

        return $this->accountsListData($user, fn (array $payload) => (new $resource($payload))->resolve());
    }
}
