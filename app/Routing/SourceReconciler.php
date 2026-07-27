<?php

namespace App\Routing;

use App\Catalog\CompiledCatalog;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Desired state, reconciled: a Placement becomes a row in
 * `routing.source_intents`, and an APPLIED intent becomes (or refreshes) a
 * connection. Nothing else in the codebase may create a platform connection
 * from a link — that single-writer property is what makes the intent ledger
 * a true account of why every connection exists.
 *
 * Reconcile, never replace (C4/C5): this never deletes a user's connection,
 * never reorders anything, and never resurrects what a tombstone refused.
 */
class SourceReconciler
{
    /**
     * @return array{intent_id: ?string, connection_id: ?string, verdict: string, block_reason: ?string}
     */
    public function reconcile(Placement $placement, RoutingContext $context, Iri $iri): array
    {
        $result = [
            'intent_id' => null,
            'connection_id' => null,
            'verdict' => $placement->verdict->value,
            'block_reason' => $placement->blockReason,
        ];

        if (! $placement->verdict->writesIntent() || $context->user === null || $placement->surfaceKey === null) {
            return $result;
        }

        $user = $context->user;
        $surface = CompiledCatalog::surface($placement->surfaceKey);
        $routingClass = (string) $surface['routing_class'];
        $identifier = $placement->identifier ?? $iri->canonical ?? $iri->raw;

        $verdict = $placement->verdict;
        $blockReason = $placement->blockReason;
        $conflictId = null;

        // Booking-class AUTO writes keep today's XOR: one auto-connection per
        // routing class. A second one is not an error and is not silently
        // dropped — it is held as a conflict the user resolves in the
        // suggestions inbox (Keep / Replace).
        if ($verdict === Verdict::Place && $this->isExclusiveAuto($routingClass) && ! $context->isDirectRequest()) {
            $incumbent = $this->incumbentFor($user, $routingClass, $placement->surfaceKey, $identifier);
            if ($incumbent !== null) {
                $verdict = Verdict::Hold;
                $blockReason = 'conflict';
                $conflictId = $incumbent;
            }
        }

        // Per-surface account cap.
        if ($verdict === Verdict::Place && $this->capReached($user, $placement->surfaceKey, (int) $surface['max_accounts'], $identifier)) {
            $verdict = Verdict::Hold;
            $blockReason = 'cap_reached';
        }

        $intentId = $this->upsertIntent($user, $placement, $context, $iri, $routingClass, $identifier, $verdict, $blockReason, $conflictId);
        $result['intent_id'] = $intentId;
        $result['verdict'] = $verdict->value;
        $result['block_reason'] = $blockReason;

        if ($verdict === Verdict::Place) {
            $connectionId = $this->applyIntent($user, $placement->surfaceKey, $routingClass, $identifier, $iri, $context);
            $result['connection_id'] = $connectionId;

            DB::table('routing.source_intents')
                ->where('id', $intentId)
                ->update(['connection_id' => $connectionId, 'resolved_at' => now(), 'updated_at' => now()]);
        }

        return $result;
    }

    private function isExclusiveAuto(string $routingClass): bool
    {
        return in_array($routingClass, ['booking', 'reservations', 'ordering'], true);
    }

    /** An existing auto-created connection in the same routing class, if any. */
    private function incumbentFor(User $user, string $routingClass, string $surfaceKey, string $identifier): ?string
    {
        return IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('routing_class', $routingClass)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('surface_key', '!=', $surfaceKey)->orWhere('resource_id', '!=', $identifier))
            ->value('id');
    }

    private function capReached(User $user, string $surfaceKey, int $maxAccounts, string $identifier): bool
    {
        $existing = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('surface_key', $surfaceKey)
            ->whereNull('deleted_at')
            ->where('resource_id', '!=', $identifier)
            ->count();

        return $existing >= max(1, $maxAccounts);
    }

    private function upsertIntent(
        User $user,
        Placement $placement,
        RoutingContext $context,
        Iri $iri,
        string $routingClass,
        string $identifier,
        Verdict $verdict,
        ?string $blockReason,
        ?string $conflictId,
    ): string {
        $now = now();

        $existing = DB::table('routing.source_intents')
            ->where('user_id', $user->id)
            ->where('surface_key', $placement->surfaceKey)
            ->where('identifier', $identifier)
            ->whereIn('state', ['proposed', 'applied', 'blocked'])
            ->first();

        if ($existing !== null) {
            // A user's dismissal is not re-proposed by a later harvest; only
            // live intents are advanced here.
            DB::table('routing.source_intents')->where('id', $existing->id)->update([
                'state' => $verdict->intentState(),
                'block_reason' => $blockReason,
                'conflicting_connection_id' => $conflictId,
                'canonical_url' => $iri->canonical,
                'confidence' => null,
                'updated_at' => $now,
            ]);

            return (string) $existing->id;
        }

        $id = (string) Str::uuid();
        DB::table('routing.source_intents')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'surface_key' => $placement->surfaceKey,
            'routing_class' => $routingClass,
            'identifier' => $identifier,
            'canonical_url' => $iri->canonical,
            'state' => $verdict->intentState(),
            'block_reason' => $blockReason,
            'conflicting_connection_id' => $conflictId,
            'origin' => $context->origin,
            'import_run_id' => $context->importRunId,
            'catalog_digest' => CompiledCatalog::digest(),
            'first_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /**
     * Applying an intent is an upsert on the connection, never a replace: an
     * existing row for the same (user, surface, resource) is left in place —
     * its payload, curation and settings belong to the user, not to the link
     * that happened to be pasted again.
     */
    private function applyIntent(
        User $user,
        string $surfaceKey,
        string $routingClass,
        string $identifier,
        Iri $iri,
        RoutingContext $context,
    ): string {
        $connection = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('surface_key', $surfaceKey)
            ->where('resource_id', $identifier)
            ->whereNull('deleted_at')
            ->first();

        if ($connection !== null) {
            return (string) $connection->id;
        }

        $connection = new IntegrationConnection([
            'user_id' => $user->id,
            'surface_key' => $surfaceKey,
            'routing_class' => $routingClass,
            'resource_id' => $identifier,
            'payload' => ConnectionPayload::forWrite(
                $iri->canonical,
                $identifier,
                (string) (CompiledCatalog::surface($surfaceKey)['identifier_kind'] ?? ''),
                $context->origin,
            ),
            'is_active' => true,
            'last_refresh_status' => 'pending',
        ]);
        $connection->created_by_catalog_digest = CompiledCatalog::digest();
        $connection->save();

        // First connection in an exclusive class owns the CTA.
        if ($this->isExclusiveAuto($routingClass)) {
            $hasPrimary = IntegrationConnection::query()
                ->where('user_id', $user->id)
                ->where('routing_class', $routingClass)
                ->where('is_primary', true)
                ->whereNull('deleted_at')
                ->exists();

            if (! $hasPrimary) {
                $connection->forceFill(['is_primary' => true])->save();
            }
        }

        return (string) $connection->id;
    }
}
