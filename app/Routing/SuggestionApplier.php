<?php

namespace App\Routing;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;

/**
 * Applies a held/proposed source intent: the one write path shared by the
 * suggestions inbox (accept) and the legacy synced-modal's "Change to" swap
 * (B4 fold). Extracting it is what keeps intent application a single writer —
 * two controllers re-implementing the demote/create/settle transaction is the
 * drift class that produced three ConnectionPayload writers.
 */
class SuggestionApplier
{
    /**
     * Demote the conflicting incumbent (if any), create or reuse the
     * connection, and settle the intent — one transaction, so a replace never
     * has a two-primaries or half-applied window.
     *
     * @param  array<string, mixed>  $surface  compiled catalog surface data
     */
    public function apply(User $user, object $intent, array $surface): IntegrationConnection
    {
        return DB::transaction(function () use ($user, $intent, $surface) {
            // Replacing an incumbent: demote it rather than delete it. The
            // user asked for a different primary, not for their data to go.
            if ($intent->conflicting_connection_id !== null) {
                IntegrationConnection::query()
                    ->where('id', $intent->conflicting_connection_id)
                    ->where('user_id', $user->id)
                    ->update(['is_primary' => false]);
            }

            $connection = IntegrationConnection::query()
                ->where('user_id', $user->id)
                ->where('surface_key', $intent->surface_key)
                ->where('resource_id', $intent->identifier)
                ->whereNull('deleted_at')
                ->first();

            if ($connection === null) {
                $connection = new IntegrationConnection([
                    'surface_key' => $intent->surface_key,
                    'routing_class' => $intent->routing_class,
                    'resource_id' => $intent->identifier,
                    // Through ConnectionPayload like every other writer: a
                    // handle-identity surface needs `username` on the public
                    // wire or the sitepage renders blank (the showcase
                    // regression). A raw ['url','source'] here was a third,
                    // drifting writer.
                    'payload' => ConnectionPayload::forWrite(
                        (string) $intent->canonical_url,
                        (string) $intent->identifier,
                        (string) ($surface['identifier_kind'] ?? ''),
                        'suggestion',
                    ),
                    'is_active' => true,
                    'last_refresh_status' => 'pending',
                ]);
                $connection->user()->associate($user);
                $connection->save();
            }

            if ($intent->conflicting_connection_id !== null) {
                $connection->forceFill(['is_primary' => true])->save();
            }

            DB::table('routing.source_intents')->where('id', $intent->id)->update([
                'state' => 'applied',
                'block_reason' => null,
                'connection_id' => $connection->id,
                'resolved_at' => now(),
                'updated_at' => now(),
            ]);

            return $connection;
        });
    }
}
