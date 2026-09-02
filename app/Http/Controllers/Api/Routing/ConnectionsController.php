<?php

namespace App\Http\Controllers\Api\Routing;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Resources\Routing\RoutingConnectionResource;
use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Connections through the routing lens (plan §1): every live connection with
 * its `routingClass` and per-class `isPrimary`, plus the write that moves the
 * CTA — the backend half of the SetPrimarySheet (the successor to the old
 * "Swap" flow).
 *
 * `is_primary` is unique per (user, routing_class) at the DB
 * (idx_platform_connections_primary_per_class), so setPrimary demotes the
 * incumbent and promotes the target in one transaction — the invariant never
 * has a two-primaries window.
 */
class ConnectionsController extends ApiController
{
    use ResolveCurrentUser;

    public function index(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $connections = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->visible()
            ->orderBy('routing_class')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        // The per-class CTA choice at a glance: routing_class => connection id.
        $primary = [];
        foreach ($connections as $connection) {
            if ($connection->is_primary) {
                $primary[(string) $connection->routing_class] = (string) $connection->id;
            }
        }

        return $this->success([
            'connections' => RoutingConnectionResource::collection($connections),
            'primary' => $primary === [] ? (object) [] : $primary,
        ]);
    }

    /** POST /routing/connections/{connection}/primary — make this connection its class's CTA. */
    public function setPrimary(Request $request, string $connectionId): JsonResponse
    {
        $user = $this->currentUser($request);

        $connection = IntegrationConnection::query()->find($connectionId);
        if ($connection === null) {
            return $this->error('Connection not found.', 404);
        }

        $this->authorizeForUser($user, 'update', $connection);

        $demotedId = null;
        DB::transaction(function () use ($user, $connection, &$demotedId) {
            $demotedId = IntegrationConnection::query()
                ->where('user_id', $user->id)
                ->where('routing_class', $connection->routing_class)
                ->where('is_primary', true)
                ->whereKeyNot($connection->id)
                ->value('id');

            // Demote first: the partial unique index allows only one primary
            // per (user, routing_class) among live rows.
            if ($demotedId !== null) {
                IntegrationConnection::query()->whereKey($demotedId)->update(['is_primary' => false]);
            }

            $connection->forceFill(['is_primary' => true])->save();
        });

        return $this->success([
            'connection' => new RoutingConnectionResource($connection->refresh()),
            'demotedConnectionId' => $demotedId !== null ? (string) $demotedId : null,
        ]);
    }
}
