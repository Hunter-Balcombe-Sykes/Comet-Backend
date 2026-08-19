<?php

namespace App\Routing;

use App\Catalog\LegacyPlatformMap;
use App\Jobs\Platforms\ConnectFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Applies a held/proposed source intent: the one write path behind the
 * suggestions inbox (accept). The legacy synced-modal's "Change to" swap
 * (B4 fold) used to share it — today its injection point in
 * InstagramController is dead and accept() is the only live caller, but the
 * extraction still stands for the original reason: two controllers
 * re-implementing the demote/create/settle transaction is the drift class
 * that produced three ConnectionPayload writers.
 */
class SuggestionApplier
{
    public function __construct(private readonly ConnectionIdentity $identity) {}

    /**
     * Connect a link that has no intent behind it — the standing Google-listing
     * OpenTable suggestion (2026-08-19), which is derived from the Google
     * Business payload on every read rather than recorded by the router.
     *
     * Same payload writer as every other lane (ConnectionPayload::forWrite):
     * a handle-identity surface needs `username` on the public wire, and a
     * hand-rolled ['url','source'] array is precisely the third writer that
     * once served blank sitepages.
     */
    public function applyDirect(User $user, string $surfaceKey, string $routingClass, string $identifier, string $url): IntegrationConnection
    {
        $connection = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('surface_key', $surfaceKey)
            ->where('resource_id', $identifier)
            ->whereNull('deleted_at')
            ->first();

        if ($connection !== null) {
            return $connection;
        }

        $connection = new IntegrationConnection([
            'surface_key' => $surfaceKey,
            'routing_class' => $routingClass,
            'resource_id' => $identifier,
            'payload' => ConnectionPayload::forWrite($url, $identifier, 'url', 'suggestion'),
            'is_active' => true,
            'last_refresh_status' => 'pending',
        ]);
        $connection->user()->associate($user);
        $connection->save();

        return $connection;
    }

    /**
     * Demote the conflicting incumbent (if any), create or reuse the
     * connection, and settle the intent — one transaction, so a replace never
     * has a two-primaries or half-applied window.
     *
     * @param  array<string, mixed>  $surface  compiled catalog surface data
     */
    public function apply(User $user, object $intent, array $surface): IntegrationConnection
    {
        // Capability re-check at APPLY time (2026-08-04): intents are durable
        // and the account's capability set can change between record and
        // accept. A FRESH intent is gated by PlacementPolicy::
        // capabilityDenial(); this path (accept / synced-modal swap) bypassed
        // it, so a stale Hold intent could install a booking/reservations/
        // ordering connection the connect controllers themselves 403. Shared
        // arms live in RoutingCapabilityGate (#DRIFT-1) — this and
        // PlacementPolicy both call it, so there is one place to change. On
        // denial the intent flips to the blocked/'gate' state the inbox
        // already renders (dismiss-only), and the caller surfaces a 403.
        $denied = RoutingCapabilityGate::denialFor($user, (string) $intent->routing_class);
        if ($denied !== null) {
            DB::table('routing.source_intents')->where('id', $intent->id)->update([
                'state' => 'blocked',
                'block_reason' => 'gate',
                'updated_at' => now(),
            ]);

            throw new AuthorizationException($denied);
        }

        return DB::transaction(function () use ($user, $intent, $surface) {
            // Replacing an incumbent. Two shapes share this column:
            //  - a booking-class CONFLICT: demote it rather than delete it —
            //    the user asked for a different primary, not for their data
            //    to go;
            //  - a single-account surface at its CAP (2026-08-19): a Swap.
            //    Here the incumbent IS the thing being replaced — one Mixcloud
            //    for another — so it is soft-deleted the way Disconnect does
            //    (observers fire: purge, ingest source, selections), and its
            //    primary flag, if it held one, carries over below.
            $inheritsPrimary = false;
            if ($intent->conflicting_connection_id !== null) {
                $incumbent = IntegrationConnection::query()
                    ->where('id', $intent->conflicting_connection_id)
                    ->where('user_id', $user->id)
                    ->first();
                if ($incumbent !== null && $intent->block_reason === 'cap_reached') {
                    $inheritsPrimary = (bool) $incumbent->is_primary;
                    if ($inheritsPrimary) {
                        // The partial unique index (one primary per class)
                        // must be clear before the new row takes it.
                        IntegrationConnection::query()->whereKey($incumbent->id)->update(['is_primary' => false]);
                    }
                    $incumbent->delete();
                } elseif ($incumbent !== null) {
                    IntegrationConnection::query()->whereKey($incumbent->id)->update(['is_primary' => false]);
                }
            }

            // #R4: the identity this intent names may already exist under a
            // different resource_id scheme — typically the legacy singleton
            // marker the bespoke connect flows write. Without this, accepting a
            // suggestion for an account the owner already has connected mints a
            // duplicate, exactly as the harvest path did (see
            // ConnectionIdentity, and SourceReconciler::applyIntent which
            // resolves the same question for the automatic lane).
            //
            // The incumbent being REPLACED is excluded: a Replace must resolve
            // to some other row, never to the very connection it just demoted.
            $aliasConnectionId = $this->identity->matchExisting(
                $user,
                (string) $intent->surface_key,
                (string) $intent->identifier,
                $intent->conflicting_connection_id !== null ? (string) $intent->conflicting_connection_id : null,
            );

            $connection = $aliasConnectionId !== null
                ? IntegrationConnection::query()->whereKey($aliasConnectionId)->first()
                : null;

            $connection ??= IntegrationConnection::query()
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

                // F14 (2026-08-20, whole-run critic): F9 wired the enrichment
                // fetch into SourceReconciler::applyIntent — the AUTO-place
                // path — but T9b is suggest-only by design, so every
                // connection its feature produces is born HERE, via accept,
                // and sat as the same nameless URL-as-account row F9 exists
                // to prevent until a scheduled refresh happened by. Same rule
                // as applyIntent, verbatim: CONTENT class only (booking
                // enrichment is owned by AutoBookingConnectDispatcher's
                // claimed/unclaimed rule; shop rows enrich through their own
                // connect jobs), only when the surface declares a fetch, and
                // afterCommit because this runs inside the transaction. Only
                // for a row created here — a matched-existing row came from a
                // lane that already owns its enrichment.
                $fetch = $surface['capabilities']['fetch'] ?? null;
                if ((string) $intent->routing_class === 'content' && is_string($fetch) && $fetch !== '') {
                    ConnectFetchJob::dispatch(
                        (string) $connection->id,
                        LegacyPlatformMap::legacyFor((string) $intent->surface_key),
                        systemInitiated: true,
                    )->afterCommit();
                }
            }

            // A booking-class Replace makes the new row the primary; a cap
            // Swap only does so when the row it retired held that flag.
            if ($intent->conflicting_connection_id !== null && ($intent->block_reason !== 'cap_reached' || $inheritsPrimary)) {
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
