<?php

namespace App\Routing;

use App\Catalog\CompiledCatalog;
use App\Catalog\LegacyPlatformMap;
use App\Jobs\Platforms\ConnectFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\AutoBookingConnectDispatcher;
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
    public function __construct(
        private readonly ConnectionIdentity $identity,
        private readonly IriCanonicalizer $canonicaliser,
        private readonly AutoBookingConnectDispatcher $autoBookingConnect,
    ) {}

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

        // M-9 (matrix run 2, tashsultanamerch live): storefronts have a
        // SINGLE WRITER — StoreBrandSeeder via the commerce lane — because a
        // store is a storefront row + catalogue + fill + auto-select, not
        // just a connection. A scan-lane Place on a shop surface (the
        // myshopify tenant host projects straight here) used to bare-apply a
        // 'pending' connection with none of that: no storefront, no
        // products, nothing that ever syncs. Hand the URL to the commerce
        // probe instead (root URLs auto-connect through the full lane, deep
        // pages suggest per FI-10) and write no intent — the lane keeps its
        // own books. Direct requests (paste) keep today's path:
        // RoutingController already runs its own suggest-only probe. The
        // commerce lane's OWN writes (origin commerce_probe) must pass
        // through, or this arm intercepts the very writer it delegates to
        // (SuggestionsInboxTest's accept flow caught exactly that).
        if ($routingClass === 'shop' && $placement->verdict === Verdict::Place
            && ! $context->isDirectRequest() && $context->origin !== 'commerce_probe') {
            \App\Jobs\Platforms\CommerceProbeJob::dispatch(
                (string) $user->id,
                $iri->canonical ?? SecretParams::redactUrl($iri->raw) ?? '',
            );

            return $result;
        }
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
        // Choose consults it too (M-8, matrix run 2: thejunglegiants live —
        // youtube.com/@TheJungleGiants arrived in the Choose band while
        // @thejunglegiants was already connected, and the un-consulted alias
        // filed the user's OWN channel as a suggestion). An aliased Choose
        // upgrades to Place below: folding into a row we already hold adds no
        // account and needs no question. Hold/Note/Reject still skip the
        // lookup — nothing downstream of them reads it.
        $aliasConnectionId = in_array($verdict, [Verdict::Place, Verdict::Choose], true)
            ? $this->identity->matchExisting($user, $placement->surfaceKey, $identifier)
            : null;

        if ($verdict === Verdict::Choose && $aliasConnectionId !== null) {
            $verdict = Verdict::Place;
        }

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

        // Per-surface account cap. Skipped entirely when the identity resolver
        // matched an EXISTING row (alias or exact): folding a link into an
        // account we already hold adds no account, so it can never be what the
        // cap is guarding against — and with socials at max_accounts=1 (FI-1,
        // 2026-08-20) a mere exclude-the-alias-from-the-count would still let
        // OTHER over-cap legacy rows block the fold (#R4 test 4's shape).
        if ($verdict === Verdict::Place && $aliasConnectionId === null && $this->capReached($user, $placement->surfaceKey, (int) $surface['max_accounts'], $identifier, $aliasConnectionId)) {
            $verdict = Verdict::Hold;
            $blockReason = 'cap_reached';
            // On a SINGLE-account surface the cap names exactly one incumbent,
            // so the inbox can offer a Swap (owner, 2026-08-19: "for platforms
            // that should have limits, show a swap button instead of add").
            // Recorded as the conflicting connection — the same column the
            // booking XOR uses — so SuggestionApplier has one replace path.
            // A multi-account surface at its cap stays dismiss-only: there is
            // no one row a swap could mean.
            if ((int) $surface['max_accounts'] <= 1) {
                $conflictId = $this->soleIncumbentFor($user, $placement->surfaceKey, $identifier, $aliasConnectionId);
            }
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

            // M-8, other direction (thejunglegiants live, verify round): when
            // the mis-cased alias arrives BEFORE the connect, its Choose
            // proposal is already filed by the time this Place applies — and
            // nothing cleaned it up, so the inbox still offered the user
            // their own now-connected channel. A newly applied Place
            // supersedes any live proposal whose identifier is the same
            // account (case-insensitive: the fold rule that matters here is
            // exactly the M-7 one, and lower() is safe cross-driver).
            DB::table('routing.source_intents')
                ->where('user_id', $user->id)
                ->where('surface_key', $placement->surfaceKey)
                ->where('state', 'proposed')
                ->where('id', '!=', $intentId)
                ->whereRaw('lower(identifier) = ?', [mb_strtolower($identifier)])
                ->update(['state' => 'superseded', 'resolved_at' => now(), 'updated_at' => now()]);

            return [$intentId, $connectionId];
        });

        $result['intent_id'] = $intentId;
        $result['connection_id'] = $connectionId;
        $result['verdict'] = $verdict->value;
        $result['block_reason'] = $blockReason;

        // An unclaimed pre-account site has nobody to answer "whose menu is
        // this?", so a Fresha connection placed here would otherwise sit
        // selection-less and publish nothing — FreshaConnector::pull() refuses a
        // null selection_ref before any HTTP call (F7 2026-08-10, re-found as
        // R14 2026-08-19). FreshaAutoSelector answers it: the account holder's
        // own menu when the staff matcher identifies them, storewide when it
        // cannot.
        //
        // Gated on the USER's claim state, not a caller flag. This lane's
        // $autoConnectBooking is vestigial (LinkInBioScanJob:52-56) — a flag has
        // to survive every hop and that one did not. A claimed owner is present
        // and keeps their picker.
        //
        // AFTER the transaction, deliberately: dispatchFor() re-queries the row
        // just written and enqueues a job that must not run against a rolled-back
        // write. (ConnectFetchJob is dispatched afterCommit() as well, so this is
        // belt and braces, not redundancy — the re-query itself must see the row.)
        if ($verdict === Verdict::Place
            && $connectionId !== null
            && $placement->surfaceKey === 'fresha.book'
            && $user->isUnclaimed()
            && (bool) config('partna.connect.auto_booking.enabled', true)
        ) {
            $this->autoBookingConnect->dispatchFor((string) $user->id);
        }

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

    /** The one connection holding a single-account surface — what a Swap replaces. */
    private function soleIncumbentFor(User $user, string $surfaceKey, string $identifier, ?string $aliasConnectionId = null): ?string
    {
        return IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('surface_key', $surfaceKey)
            ->whereNull('deleted_at')
            ->when($aliasConnectionId !== null, fn ($q) => $q->where('id', '!=', $aliasConnectionId))
            ->where('resource_id', '!=', $identifier)
            ->orderBy('created_at')
            ->value('id');
    }

    /**
     * Record a cap-blocked intent for a link the LEGACY lane refused because
     * the brand's one slot is filled (owner, 2026-08-19).
     *
     * Before this, a harvest that found a second Uber Eats store quietly filed
     * it as a links-pool card — a public link the owner never asked for, with
     * no way to say "actually, use that one instead". It is the same situation
     * the reconciler already answers with `cap_reached` + the sole incumbent,
     * which the inbox renders as **Swap**; this is the door the legacy
     * routers (LinkRouter::seedOnlineOrdering / seedBooking / seedReservation,
     * still live for the Instagram + Google Business harvests) come through
     * until they move onto LinkRoutingService. Delete it with them.
     *
     * Idempotent: upsertIntent advances the live intent for this
     * (user, surface, identifier), so a nightly re-sync re-states the same
     * row rather than stacking duplicates. A dismissal is never re-proposed —
     * advanceLiveIntent only touches live states.
     */
    public function recordCapBlock(
        User $user,
        string $surfaceKey,
        string $routingClass,
        string $identifier,
        string $url,
        string $incumbentConnectionId,
        string $origin = 'google_business',
    ): void {
        $iri = $this->canonicaliser->canonicalize($url);
        $placement = new Placement(Verdict::Hold, $surfaceKey, $identifier, 'cap_reached');

        $this->upsertIntent(
            $user,
            $placement,
            RoutingContext::forUser($user, $origin),
            $iri,
            $routingClass,
            $identifier,
            Verdict::Hold,
            'cap_reached',
            $incumbentConnectionId,
        );
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

        // F9 (2026-08-20, the_046_official trace): a reconciler-applied row
        // starts as {url, source} — no name, no thumbnail — so the Platforms
        // page showed the bare URL as the account (the exact integrity
        // failure the spotify-episode bug shipped with). The interactive
        // connect flows enrich via ConnectFetchJob; system placements now
        // get the same job when the surface declares a fetch capability.
        //
        // CONTENT class only, deliberately: booking's enrichment is OWNED by
        // AutoBookingConnectDispatcher with its claimed/unclaimed rule (a
        // claimed user keeps the team-member picker — an unconditional fetch
        // here would auto-complete it; UnclaimedAutoBookingConnectTest pins
        // that), and shop rows enrich through their own connect jobs.
        // afterCommit: this runs inside reconcile()'s transaction.
        $surface = CompiledCatalog::surface($surfaceKey);
        $fetch = $surface['capabilities']['fetch'] ?? null;
        if ($routingClass === 'content' && is_string($fetch) && $fetch !== '') {
            ConnectFetchJob::dispatch((string) $connection->id, LegacyPlatformMap::legacyFor($surfaceKey), systemInitiated: true)->afterCommit();
        }

        return (string) $connection->id;
    }
}
