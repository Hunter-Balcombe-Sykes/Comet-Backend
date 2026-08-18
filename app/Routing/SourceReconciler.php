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
 * never reorders anything, and never resurrects what a tombstone refused —
 * unless the user themselves pastes the link again, in which case the direct
 * request wins and the superseded tombstone is cleared (owner decision,
 * 2026-07-28).
 */
class SourceReconciler
{
    public function __construct(private readonly ConnectionIdentity $identity) {}

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
        // redactUrl() itself now fails closed (returns '' on a PCRE engine
        // error), so this fallback is unreachable for a non-null $iri->raw —
        // but "unreachable today" is precisely the assumption that already
        // failed once in this repo (#SEC-1). Never fall back to the raw,
        // possibly-secret-bearing URL.
        $identifier = $placement->identifier ?? $iri->canonical ?? SecretParams::redactUrl($iri->raw) ?? '';

        $verdict = $placement->verdict;
        $blockReason = $placement->blockReason;
        $conflictId = null;

        // #R4. `resource_id` means three different things on this table (see
        // ConnectionIdentity), so the raw `!=` comparisons below read the
        // build's own Instagram connection — resource_id 'instagram', the
        // legacy singleton marker — as a DIFFERENT account from the same
        // handle harvested out of the owner's Linktree. Resolved ONCE here and
        // threaded through all three consumers: the cap must not count an alias
        // as a second account, the booking XOR must not hold it as a conflict,
        // and applyIntent must reuse it rather than insert a duplicate.
        //
        // Gated on Place, deliberately. A non-Place verdict touches
        // site.platform_connections nowhere else in this method, and
        // SourceIntentUpsertRaceTest is built on exactly that invariant (it
        // provisions no connections table at all) — an unconditional lookup
        // here both breaks it and spends a query per Choose/Hold/Note that
        // nothing reads.
        $aliasConnectionId = $verdict === Verdict::Place
            ? $this->identity->matchExisting($user, $placement->surfaceKey, $identifier)
            : null;

        // Booking-class AUTO writes keep today's XOR: one auto-connection per
        // routing class. A second one is not an error and is not silently
        // dropped — it is held as a conflict the user resolves in the
        // suggestions inbox (Keep / Replace).
        if ($verdict === Verdict::Place && $this->isExclusiveAuto($routingClass) && ! $context->isDirectRequest()) {
            $incumbent = $this->incumbentFor($user, $routingClass, $placement->surfaceKey, $identifier, $aliasConnectionId);
            if ($incumbent !== null) {
                $verdict = Verdict::Hold;
                $blockReason = 'conflict';
                $conflictId = $incumbent;
            }
        }

        // Per-surface account cap.
        if ($verdict === Verdict::Place && $this->capReached($user, $placement->surfaceKey, (int) $surface['max_accounts'], $identifier, $aliasConnectionId)) {
            $verdict = Verdict::Hold;
            $blockReason = 'cap_reached';
        }

        // One transaction for the intent write plus (on Place) its connection
        // apply and settle-UPDATE — LIFE-16. Previously these were three
        // independent autocommit statements: a throw partway through
        // (IntegrationConnection::booted()'s saving() validator, a
        // QueryException from applyIntent()) could leave an `applied` intent
        // with connection_id IS NULL forever. Wrapping means a mid-write
        // failure now leaves NO intent row instead of a dangling one — see
        // SuggestionApplier::apply() for the same shape already in this
        // codebase. NO catch in here: letting a failure roll back the whole
        // transaction IS the fix. Every downstream side effect reachable from
        // applyIntent() (Cloudflare purge, site touch, ingest.sources sync,
        // content-selection seeding) is deferred past commit by
        // IntegrationConnectionObserver::$afterCommit — see the comment on
        // that property — so nothing here performs I/O inside the
        // transaction.
        [$intentId, $connectionId] = DB::transaction(function () use (
            $user, $placement, $context, $iri, $routingClass, $identifier, $verdict, $blockReason, $conflictId, $aliasConnectionId
        ) {
            $intentId = $this->upsertIntent($user, $placement, $context, $iri, $routingClass, $identifier, $verdict, $blockReason, $conflictId);

            if ($verdict !== Verdict::Place) {
                return [$intentId, null];
            }

            $connectionId = $this->applyIntent($user, $placement->surfaceKey, $routingClass, $identifier, $iri, $context, $aliasConnectionId);

            DB::table('routing.source_intents')
                ->where('id', $intentId)
                ->update(['connection_id' => $connectionId, 'resolved_at' => now(), 'updated_at' => now()]);

            return [$intentId, $connectionId];
        });

        $result['intent_id'] = $intentId;
        $result['connection_id'] = $connectionId;
        $result['verdict'] = $verdict->value;
        $result['block_reason'] = $blockReason;

        return $result;
    }

    private function isExclusiveAuto(string $routingClass): bool
    {
        return in_array($routingClass, ['booking', 'reservations', 'ordering'], true);
    }

    /**
     * An existing auto-created connection in the same routing class, if any.
     *
     * $aliasConnectionId is THIS identity wearing another scheme's clothes
     * (#R4) — never a rival for the class, so it is excluded rather than held
     * as a conflict the owner is asked to resolve against itself.
     */
    private function incumbentFor(User $user, string $routingClass, string $surfaceKey, string $identifier, ?string $aliasConnectionId = null): ?string
    {
        return IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('routing_class', $routingClass)
            ->whereNull('deleted_at')
            ->when($aliasConnectionId !== null, fn ($q) => $q->where('id', '!=', $aliasConnectionId))
            ->where(fn ($q) => $q->where('surface_key', '!=', $surfaceKey)->orWhere('resource_id', '!=', $identifier))
            ->value('id');
    }

    /** $aliasConnectionId: see incumbentFor() — an alias is not a second account. */
    private function capReached(User $user, string $surfaceKey, int $maxAccounts, string $identifier, ?string $aliasConnectionId = null): bool
    {
        $existing = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('surface_key', $surfaceKey)
            ->whereNull('deleted_at')
            ->when($aliasConnectionId !== null, fn ($q) => $q->where('id', '!=', $aliasConnectionId))
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

        // A user's dismissal is not re-proposed by a later harvest; only live
        // intents are advanced here.
        $fields = [
            'state' => $verdict->intentState(),
            'block_reason' => $blockReason,
            'conflicting_connection_id' => $conflictId,
            'canonical_url' => $iri->canonical,
            'confidence' => null,
            'updated_at' => $now,
        ];

        // 1. A live intent already exists -> advance it.
        if ($id = $this->advanceLiveIntent($user, $placement->surfaceKey, $identifier, $fields)) {
            return $id;
        }

        // 2. No live intent -> claim the slot. insertOrIgnore compiles to
        //    `ON CONFLICT DO NOTHING` (pgsql) / `INSERT OR IGNORE` (sqlite),
        //    so a concurrent winner comes back as 0 rows affected rather than
        //    a raised 23505 — which on Postgres would abort the enclosing
        //    LIFE-16 transaction (SQLSTATE 25P02; see
        //    ItemSlugAllocator::allocateSlug() for the same fix already in
        //    this codebase).
        $id = (string) Str::uuid();
        $inserted = DB::table('routing.source_intents')->insertOrIgnore([
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

        if ($inserted > 0) {
            return $id;
        }

        // 3. Lost the race: someone committed the live row between (1) and
        //    (2). Advance THEIR row — same conditional UPDATE, and the
        //    correct outcome (idempotent "already handled") rather than a
        //    500. reconcile() only reaches here for a verdict that
        //    writesIntent() (applied/proposed/blocked), which is always
        //    inside idx_source_intents_live's predicate, so a live row
        //    provably exists at this point; the throw below converts the
        //    impossible case into a loud invariant failure instead of a
        //    bogus id.
        return $this->advanceLiveIntent($user, $placement->surfaceKey, $identifier, $fields)
            ?? throw new \RuntimeException("Could not upsert source intent for {$placement->surfaceKey}:{$identifier}");
    }

    /**
     * Advance the one live intent for this (user, surface, identifier)
     * triple, if it exists. Null = no live row.
     *
     * Conditional UPDATE, not read-then-write: the affected-row count IS the
     * existence check, and the matched row is locked for the rest of the
     * enclosing transaction — which is what makes the re-read below
     * race-free. Race-free ONLY inside a transaction that holds that lock
     * (LIFE-16's DB::transaction in reconcile()); never call this outside one.
     *
     * @param  array<string, mixed>  $fields
     */
    private function advanceLiveIntent(User $user, ?string $surfaceKey, string $identifier, array $fields): ?string
    {
        $live = fn () => DB::table('routing.source_intents')
            ->where('user_id', $user->id)
            ->where('surface_key', $surfaceKey)
            ->where('identifier', $identifier)
            ->whereIn('state', ['proposed', 'applied', 'blocked']);

        if ($live()->update($fields) === 0) {
            return null;
        }

        // Safe re-read: idx_source_intents_live guarantees at most one
        // matching row, $fields['state'] is always back in the live set, and
        // our own UPDATE above holds its row lock until commit.
        return (string) $live()->value('id');
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
        ?string $aliasConnectionId = null,
    ): string {
        // A direct re-add supersedes an earlier refusal of this exact source:
        // leaving the tombstone would make the very next scan reject a link
        // the user explicitly restored. Narrow on purpose — a bare-surface
        // refusal covers every account on the surface, which one account's
        // re-add does not answer, so only the surface:identifier ref goes.
        if ($context->isDirectRequest()) {
            DB::table('routing.item_tombstones')
                ->where('user_id', $user->id)
                ->where('source_ref', $surfaceKey.':'.$identifier)
                ->delete();
        }

        // #R4: an existing row for this identity under ANY of the table's three
        // resource_id schemes. Reconcile, never replace — its payload, curation
        // and settings belong to the user, so nothing about it is rewritten
        // here, not even to align its resource_id with this lane's spelling.
        if ($aliasConnectionId !== null) {
            return $aliasConnectionId;
        }

        // Same-scheme fallback, re-read INSIDE the transaction: matchExisting()
        // ran before it opened, so a concurrent writer could have landed this
        // exact row in between.
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
