<?php

namespace App\Http\Controllers\Api\Platforms\Concerns;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

// Per-user platform-connection storage — the pilot replacement for
// ManagesPlatformSelection's single global cache key. Each controller declares
// its platform(); the selection blob the controller already builds is stored
// verbatim in the row's `payload`, keyed by (user, platform, resource_id).
//
// Single-selection platforms (Eventbrite, YouTube, Apple, Stan, Fresha, TikTok,
// Facebook) keep one row per user under the default resource id. Multi-resource
// platforms (Shopify brands) pass an explicit resource id.
//
// Writes go through the model, so IntegrationConnectionObserver fires and purges
// the user's sitepage edge cache automatically.
trait ManagesIntegrationConnection
{
    // The platform key stored in site.platform_connections.platform (must match
    // the migration CHECK constraint).
    abstract protected function platform(): string;

    // Single-selection platforms store one row per user under this resource id.
    protected function defaultResourceId(): string
    {
        return $this->platform();
    }

    /** All of the user's active connections for this platform, ordered. */
    protected function connectionsFor(User $user)
    {
        return $user->integrationConnections()
            ->where('platform', $this->platform())
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();
    }

    protected function connectionFor(User $user, ?string $resourceId = null): ?IntegrationConnection
    {
        return $user->integrationConnections()
            ->where('platform', $this->platform())
            ->where('resource_id', $resourceId ?? $this->defaultResourceId())
            ->first();
    }

    /** Upsert the selection payload for one resource; returns the row. */
    protected function writeConnection(User $user, array $payload, ?string $resourceId = null): IntegrationConnection
    {
        return IntegrationConnection::updateOrCreate(
            [
                'user_id' => $user->id,
                'platform' => $this->platform(),
                'resource_id' => $resourceId ?? $this->defaultResourceId(),
            ],
            [
                'payload' => $payload,
                'is_active' => true,
                'last_refreshed_at' => now(),
                'last_refresh_status' => 'ok',
                'last_refresh_error' => null,
                'consecutive_failures' => 0,
            ],
        );
    }

    /** Read one resource's selection payload (null when nothing is stored). */
    protected function readConnection(User $user, ?string $resourceId = null): ?array
    {
        return $this->connectionFor($user, $resourceId)?->payload;
    }

    /** Soft-delete one resource (or the default single selection). */
    protected function forgetConnection(User $user, ?string $resourceId = null): void
    {
        $this->connectionFor($user, $resourceId)?->delete();
    }

    /**
     * Serialise a read→mutate→write payload cycle for one user behind a per-user,
     * per-platform Redis lock. Prevents concurrent dashboard tabs / retries from
     * clobbering each other's JSONB writes (last-write-wins data loss).
     *
     * Returns the callback's JsonResponse, or a 423 (Locked) when another mutation
     * holds the lock past the block timeout so the dashboard can retry. The closure
     * form of block() releases the lock automatically on return or throw.
     *
     * $suffix scopes the lock more narrowly (e.g. one controller serving two
     * platforms that should not block each other).
     *
     * Note: assumes the using class extends ApiController (for error()).
     */
    protected function withConnectionLock(User $user, callable $callback, ?string $suffix = null): JsonResponse
    {
        $key = "platforms:{$this->platform()}:lock:{$user->id}".($suffix !== null ? ":{$suffix}" : '');

        try {
            return Cache::lock($key, 10)->block(5, $callback);
        } catch (LockTimeoutException) {
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }
    }
}
