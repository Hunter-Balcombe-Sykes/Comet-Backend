<?php

namespace App\Routing;

use App\Catalog\CompiledCatalog;
use App\Catalog\LegacyPlatformMap;
use App\Jobs\Platforms\CommerceProbeJob;
use App\Jobs\Platforms\ConnectFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\AutoBookingConnectDispatcher;
use App\Services\Platforms\YoutubeScraper;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
    /** Cache::lock TTL (seconds) for the exclusive-slot lock — auto-released if a wedged worker never reaches block()'s timeout. Mirrors BuildsAutoSyncFindings::SEED_LOCK_TTL. */
    private const EXCLUSIVE_SLOT_LOCK_TTL = 10;

    /** Cache::lock block() wait (seconds) before giving up and applying nothing. Mirrors BuildsAutoSyncFindings::SEED_LOCK_BLOCK. */
    private const EXCLUSIVE_SLOT_LOCK_BLOCK = 3;

    public function __construct(
        private readonly ConnectionIdentity $identity,
        private readonly IriCanonicalizer $canonicaliser,
        private readonly AutoBookingConnectDispatcher $autoBookingConnect,
        private readonly PreScrapeDispatcher $preScrape,
        private readonly YoutubeScraper $youtube,
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
        // Since 2026-09-03 decide() never emits Place for an unconfirmed
        // request, so the arm catches the CHOOSE band on every indirect origin
        // — not just sign-up builds, as it did while a post-claim harvest could
        // still reach Place. Without that widening a harvested store root would
        // write a bare proposed intent with none of the commerce lane's
        // enrichment (shop name, logo, deep-page rules) behind it, and the
        // inbox would render a nameless "Shopify?" the accept path could not
        // fill in. Place stays in the set for a confirmed non-paste origin.
        if ($routingClass === 'shop'
            && in_array($placement->verdict, [Verdict::Place, Verdict::Choose], true)
            && ! $context->isDirectRequest() && $context->origin !== 'commerce_probe') {
            $probeUrl = $iri->canonical ?? SecretParams::redactUrl($iri->raw) ?? '';
            CommerceProbeJob::owe((string) $user->id, $probeUrl);
            CommerceProbeJob::dispatch(
                (string) $user->id,
                $probeUrl,
                // Always suggest-only now. A store is a storefront row, a
                // catalogue, a fill and an auto-select — the biggest thing this
                // pipeline can install — and nobody asked for it: this arm is
                // only ever reached from a harvest. The user answers in the
                // inbox and the accept lane builds it for real.
                suggestOnly: true,
            );

            return $result;
        }
        // redactUrl() itself now fails closed (returns '' on a PCRE engine
        // error), so this fallback is unreachable for a non-null $iri->raw —
        // but "unreachable today" is precisely the assumption that already
        // failed once in this repo (#SEC-1). Never fall back to the raw,
        // possibly-secret-bearing URL.
        $identifier = $placement->identifier ?? $iri->canonical ?? SecretParams::redactUrl($iri->raw) ?? '';

        // F31 (2026-09-06): youtube.channel's own detectors capture whichever
        // identifier shape the URL happened to carry — a bare handle off an
        // @url, or the raw UC… id off a /channel/ url — as two different
        // strings for the SAME account, and every dedup below (matchExisting,
        // capReached, upsertIntent's exact-identifier M-8 check) compares
        // identifiers as opaque strings. Canonicalising the id shape to its
        // real handle HERE, before any of them read $identifier, means the
        // existing exact-match dedup and the naming machinery both treat it
        // exactly as they would an @handle discovery — no rewrite needed
        // anywhere else. Fails open (keeps the raw id) on no key/network
        // miss, same discipline as every other live-resolve in this pipeline.
        if ($placement->surfaceKey === 'youtube.channel' && preg_match('/^UC[A-Za-z0-9_-]{22}$/', $identifier) === 1) {
            $identifier = $this->youtube->handleFromChannelId($identifier) ?? $identifier;
        }

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
        // Gated, deliberately: an unconditional lookup would spend a query per
        // Hold/Note/Reject that nothing downstream reads.
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
        //
        // #W2-LIFE-3: that check and the write it guards have to be ONE
        // critical section. Read-then-write with nothing in between let two
        // concurrent harvests both see "no incumbent"; the loser's
        // forceFill(['is_primary' => true]) inside applyIntent() then raised an
        // uncaught 23505 on idx_platform_connections_primary_per_class INSIDE
        // the LIFE-16 transaction, taking the whole reconcile down with it (a
        // 500 / failed job) — so the "hold it as a conflict" semantics this
        // block exists to provide never ran at all. (The audit's stated impact,
        // "two rows both marked is_primary", is not the bug: that partial
        // unique index already makes it impossible.)
        //
        // LOCK OUTER, DB TRANSACTION INNER — never the reverse. Same ordering
        // as every other holder of these keys
        // (ManagesIntegrationConnection::withCrossPlatformLock,
        // BuildsAutoSyncFindings::runUnderSeedLock), and the only ordering that
        // cannot deadlock against them.
        $exclusiveLockKey = $verdict === Verdict::Place && $this->isExclusiveAuto($routingClass) && ! $context->isDirectRequest()
            ? $this->exclusiveSlotLockKey($routingClass, (string) $user->id)
            : null;

        $settle = function () use (
            $user, $placement, $context, $iri, $routingClass, $surface, $identifier, $aliasConnectionId, &$verdict, &$blockReason, &$conflictId
        ): array {
            // Choose is in the set since 2026-09-03. CONFLICT DETECTION is not
            // the same question as auto-connecting: a harvested booking link
            // that collides with the incumbent must still be filed as a Swap
            // ("Keep / Replace") rather than as a plain suggestion, because
            // SuggestionApplier has no XOR check of its own — it trusts
            // conflicting_connection_id, which only this arm writes. Without
            // the widening, accepting the suggestion would insert a second
            // is_primary row and raise 23505 on
            // idx_platform_connections_primary_per_class at accept time.
            if (in_array($verdict, [Verdict::Place, Verdict::Choose], true)
                && $this->isExclusiveAuto($routingClass) && ! $context->isDirectRequest()) {
                $incumbent = $this->incumbentFor($user, $routingClass, $placement->surfaceKey, $identifier, $aliasConnectionId);
                if ($incumbent !== null) {
                    $verdict = Verdict::Hold;
                    $blockReason = $this->isSettledWorkplaceSlot($user, $context, $incumbent) ? 'sibling_branch' : 'conflict';
                    $conflictId = $incumbent;
                }
            }

            // $verdict/$blockReason/$conflictId go in BY REFERENCE: the cap
            // check inside can still downgrade Place -> Hold, and reconcile()'s
            // $result (and the fresha auto-connect gate below it) must see that
            // downgrade — passing by value silently reported a `place` verdict
            // for a link the cap had just held.
            return $this->settleAndApply($user, $placement, $context, $iri, $routingClass, $surface, $identifier, $verdict, $blockReason, $conflictId, $aliasConnectionId);
        };

        if ($exclusiveLockKey !== null) {
            try {
                [$intentId, $connectionId] = Cache::lock($exclusiveLockKey, self::EXCLUSIVE_SLOT_LOCK_TTL)
                    ->block(self::EXCLUSIVE_SLOT_LOCK_BLOCK, $settle);
            } catch (LockTimeoutException) {
                // Contention: write NOTHING and say so. Mirrors
                // BuildsAutoSyncFindings::runUnderSeedLock's "$default =
                // nothing applied" contract — the intent is not written, the
                // connection is not created, and the next harvest re-proposes
                // the same link.
                Log::warning('routing.reconcile.exclusive_slot_lock_timeout', [
                    'user_id' => (string) $user->id,
                    'routing_class' => $routingClass,
                    'surface_key' => $placement->surfaceKey,
                ]);

                // NOTE the verdict in $result is the PLACEMENT's, not a settled one:
                // nothing was applied, but a caller matching on 'place' (e.g.
                // LinkInBioImporter::handlePlaced) will still count this as connected.
                // Contention-only and self-healing on the next harvest, but do not read
                // this return as "the placement happened".
                return $result;
            }
        } else {
            [$intentId, $connectionId] = $settle();
        }

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
        // Gated on the USER's state, not a caller flag. This lane's
        // $autoConnectBooking is vestigial (LinkInBioScanJob:52-56) — a flag has
        // to survive every hop and that one did not. Widened 2026-09-04 from
        // isUnclaimed() to isInSetup(): a claimed owner mid-signup gets the same
        // background enrichment as a build, and keeps their picker anyway —
        // FreshaAutoSelector only writes a selection for them when it lands
        // their OWN employee menu (its picker-preserving degrade), and
        // SquareAutoSelectJob has always stood down on no match. Post-setup, a
        // claimed owner still keeps today's picker-first flow.
        //
        // AFTER the transaction, deliberately: dispatchFor() re-queries the row
        // just written and enqueues a job that must not run against a rolled-back
        // write. (ConnectFetchJob is dispatched afterCommit() as well, so this is
        // belt and braces, not redundancy — the re-query itself must see the row.)
        //
        // AND OUTSIDE $settle's lock, equally deliberately: under
        // QUEUE_CONNECTION=sync this reaches FreshaConnectFetch.php:271, which
        // takes CacheKeyGenerator::bookingXorLock itself. Cache::lock is NOT
        // reentrant, so moving this call inside the closure self-deadlocks the
        // booking path against a lock it already holds. Do not move it.
        if ($verdict === Verdict::Place
            && $connectionId !== null
            && in_array($placement->surfaceKey, ['fresha.book', 'square.book'], true)
            && $user->isInSetup()
            && (bool) config('partna.connect.auto_booking.enabled', true)
        ) {
            $this->autoBookingConnect->dispatchFor((string) $user->id, $placement->surfaceKey === 'square.book' ? 'square' : 'fresha');
        }

        // Pre-scrape (A.4): a sign-up build's freshly proposed AUTO-band
        // suggestion starts syncing invisibly (hidden connection, A.3) so the
        // setup dialog has real items behind the tick. AFTER the transaction
        // and outside $settle's lock for the same reasons as the fresha gate
        // above — the dispatcher re-reads the intent row it needs.
        // isSelfServeSignup(), NOT isSignupBuild(): pre-scrape SPENDS (15 of 32
        // connectors are CostClass::Actor), and isSignupBuild() is true for any
        // unclaimed non-paste user — so outreach builds, which may sit
        // unclaimed for weeks with nobody to ask, were buying the same paid
        // scrapes as someone seconds from the setup dialog. Every other use of
        // isSignupBuild() is a SAFETY gate and must keep its wider meaning.
        if ($verdict === Verdict::Choose && $intentId !== null && $context->isSelfServeSignup()) {
            $this->preScrape->maybeApply($user, $placement, $surface, $intentId);
        }

        return $result;
    }

    /**
     * Everything downstream of the exclusive-slot check: the per-surface cap,
     * then the LIFE-16 transaction. Split out of reconcile() only so the
     * exclusive path can run it and the incumbent check together under one
     * lock — the body is unchanged.
     *
     * $verdict / $blockReason / $conflictId are BY REFERENCE: the cap check
     * below downgrades Place -> Hold, and the caller's result (plus the fresha
     * auto-connect gate) reads the downgraded values.
     *
     * @param  array<string, mixed>  $surface
     * @return array{0: string, 1: ?string}
     */
    private function settleAndApply(
        User $user,
        Placement $placement,
        RoutingContext $context,
        Iri $iri,
        string $routingClass,
        array $surface,
        string $identifier,
        Verdict &$verdict,
        ?string &$blockReason,
        ?string &$conflictId,
        ?string $aliasConnectionId,
    ): array {
        // Per-surface account cap. Skipped entirely when the identity resolver
        // matched an EXISTING row (alias or exact): folding a link into an
        // account we already hold adds no account, so it can never be what the
        // cap is guarding against — and with socials at max_accounts=1 (FI-1,
        // 2026-08-20) a mere exclude-the-alias-from-the-count would still let
        // OTHER over-cap legacy rows block the fold (#R4 test 4's shape).
        //
        // An INDIRECT Choose is capped here too since 2026-09-03, for the same
        // reason the XOR arm above widened: a harvest no longer reaches Place,
        // and SuggestionApplier's Swap path is driven entirely by the
        // block_reason/conflicting_connection_id this block writes. A plain
        // proposed intent over the cap would accept into an (N+1)th connection
        // the surface says cannot exist. A direct paste is left exactly as it
        // was — it has its own 422 slot_taken contract with the dashboard.
        if (($verdict === Verdict::Place || ($verdict === Verdict::Choose && ! $context->isDirectRequest()))
            && $aliasConnectionId === null
            && $this->capReached($user, $placement->surfaceKey, (int) $surface['max_accounts'], $identifier, $aliasConnectionId)) {
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
        return DB::transaction(function () use (
            $user, $placement, $context, $iri, $routingClass, $surface, $identifier, $verdict, $blockReason, $conflictId, $aliasConnectionId
        ) {
            $intentId = $this->upsertIntent($user, $placement, $context, $iri, $routingClass, $identifier, $verdict, $blockReason, $conflictId, (int) $surface['max_accounts']);

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
    }

    private function isExclusiveAuto(string $routingClass): bool
    {
        return in_array($routingClass, ['booking', 'reservations', 'ordering'], true);
    }

    /**
     * The serialisation point for an exclusive routing class — the SAME key
     * every other writer of that class's slot takes, so the reconciler
     * excludes them rather than merely excluding itself.
     *
     * Deliberately NOT BuildsAutoSyncFindings::withBookingXorLock and friends:
     * that trait is off limits here (its own docblock records why the
     * reconciler cannot take it), so the keys are resolved from
     * CacheKeyGenerator directly. Ordering has no XOR key of its own — its
     * family has always serialised on the platform-wide 'online-ordering'
     * connection lock, named as orderingFamilyLock() so this and
     * GoogleBusinessAutoSync::seedOrdering build one identical string.
     *
     * Null = not an exclusive class (isExclusiveAuto() is the gate; this
     * returning null for a class it admits would silently drop the lock).
     */
    private function exclusiveSlotLockKey(string $routingClass, string $userId): ?string
    {
        return match ($routingClass) {
            'booking' => CacheKeyGenerator::bookingXorLock($userId),
            'reservations' => CacheKeyGenerator::reservationsXorLock($userId),
            'ordering' => CacheKeyGenerator::orderingFamilyLock($userId),
            default => null,
        };
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

    /**
     * Is this link merely ANOTHER BRANCH of a workplace whose slot is already
     * settled — a question that has been ANSWERED rather than one still to ask?
     *
     * A chain's locations page carries one booking link per branch, and Fresha
     * models every branch as its own business (separate venue id, separate
     * owner id, empty `additionalLocations`), so ConnectionIdentity correctly
     * refuses to merge them and the XOR above holds every one after the first.
     * Six branches then became five "use this one instead?" cards — measured
     * on dev 2026-09-03, teegandyson and liamsaunders, five rows each. The
     * slot was never actually in dispute.
     *
     * Two things settle it, and BOTH are already recorded, so neither costs a
     * fetch:
     *
     *  · the incumbent is the account holder's OWN (`owner_scope` 'self') —
     *    harvested from their bio or typed by them. A link off their
     *    employer's site does not get to propose replacing that. This is the
     *    booking-lane counterpart of the 2026-09-03 identity gate, which drew
     *    the line at "an account says who you are, a booking link says how to
     *    reach you" and so left the action classes wide.
     *  · the incumbent's roster NAMED them (`selection.mode` 'employee').
     *    FreshaAutoSelector reaches that only via StaffNameMatcher AND a
     *    successful per-employee services fetch, so it is a positive
     *    identification of THIS person at THAT branch — strictly stronger
     *    evidence than a bare name match.
     *
     * A 'storewide' incumbent deliberately still conflicts: the roster did not
     * name them, so which branch is theirs is genuinely unknown and the
     * question is real.
     *
     * Scoped through ownerScopeFor() — the same derivation that stamps the
     * column — so this refuses a HARVEST, never a platform: a business
     * account's own website is its own brand and never lands here, and a
     * partna PASTING a branch link is stating a fact about themselves, which
     * still asks.
     *
     * One query, only on the already-rare conflict branch, and never on the
     * happy path.
     */
    private function isSettledWorkplaceSlot(User $user, RoutingContext $context, string $incumbentId): bool
    {
        if (RoutingCapabilityGate::ownerScopeFor($user, $context->origin) !== 'workplace') {
            return false;
        }

        $incumbent = IntegrationConnection::query()
            ->whereKey($incumbentId)
            ->first(['owner_scope', 'payload']);

        if ($incumbent === null) {
            return false;
        }

        return $incumbent->owner_scope === 'self'
            || data_get($incumbent->payload, 'selection.mode') === 'employee';
    }

    /** $aliasConnectionId: see incumbentFor() — an alias is not a second account. */
    private function capReached(User $user, string $surfaceKey, int $maxAccounts, string $identifier, ?string $aliasConnectionId = null): bool
    {
        $existing = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('surface_key', $surfaceKey)
            ->whereNull('deleted_at')
            // A hidden pre-scrape row holds no slot (A.3): the person has not
            // accepted it, so it must not block what they actually connect.
            ->visible()
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
        int $maxAccounts = 0,
    ): string {
        $now = now();

        // A user's dismissal is not re-proposed by a later harvest; only live
        // intents are advanced here.
        $fields = [
            'state' => $verdict->intentState(),
            'block_reason' => $blockReason,
            'conflicting_connection_id' => $conflictId,
            'canonical_url' => $iri->canonical,
            'updated_at' => $now,
        ];

        // Same coalesce-don't-clobber rule as identifier_label below: a later
        // pass through a lane that carried no band (a cap-reached Hold, a Note)
        // must not blank the band an earlier Place/Choose recorded — the setup
        // dialog preselects off it.
        if ($placement->band !== null) {
            $fields['band'] = $placement->band;
        }

        // Only when this pass carried one. A later pass through a lane that
        // has no name (the myshopify.com detector never fetches) must not
        // blank a name an earlier probe already earned — the same
        // coalesce-don't-clobber rule the brand row's favicon follows.
        if ($placement->identifierLabel !== null) {
            $fields['identifier_label'] = $placement->identifierLabel;
        }
        if ($placement->identifierIcon !== null) {
            $fields['identifier_icon'] = $placement->identifierIcon;
        }

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
        // A single-slot surface (max_accounts <= 1, e.g. one booking brand
        // found twice on a sign-up build) can never be cap-checked against a
        // REAL connection above — a sign-up build never places one, so
        // capReached() sees zero existing rows for either candidate and
        // never downgrades anything. Left alone, every candidate would
        // compute its OWN 'auto' band independently and the setup dialog
        // would pre-tick two accounts for one slot, tripping the accept
        // lane's exclusive-slot lock on Continue. Multi-slot surfaces (shop's
        // several stores) are exempt on purpose — item 4 assumes more than
        // one store can legitimately be pre-ticked at once.
        $band = $placement->band;
        if ($band === 'auto' && $maxAccounts <= 1 && $this->hasLiveAutoSibling($user, $placement->surfaceKey, $routingClass, $identifier)) {
            $band = 'suggest';
        }

        $id = (string) Str::uuid();
        $inserted = DB::table('routing.source_intents')->insertOrIgnore([
            'id' => $id,
            'user_id' => $user->id,
            'surface_key' => $placement->surfaceKey,
            'routing_class' => $routingClass,
            'identifier' => $identifier,
            'identifier_label' => $placement->identifierLabel,
            'identifier_icon' => $placement->identifierIcon,
            'canonical_url' => $iri->canonical,
            'state' => $verdict->intentState(),
            'block_reason' => $blockReason,
            'conflicting_connection_id' => $conflictId,
            'band' => $band,
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
     * Does this user already hold a LIVE, pre-ticked candidate for this
     * surface under a DIFFERENT identifier? Scoped to the insert branch of
     * upsertIntent() only — a re-scan of the SAME identifier advances its own
     * row instead of reaching here, so this never fights the coalesce rule
     * that lets a later pass with no band avoid blanking an earlier one.
     *
     * A narrow, accepted race: two truly concurrent first-sightings of the
     * same surface could both run this check before either commits its
     * insert, and both would then insert as 'auto'. Same tolerance as the
     * "lost the race" branch a few lines below — this file already accepts
     * that shape rather than adding cross-transaction locking for it.
     */
    private function hasLiveAutoSibling(User $user, ?string $surfaceKey, string $routingClass, string $excludeIdentifier): bool
    {
        // An exclusive class (booking / reservations / ordering) is ONE slot
        // across all of its brands — fresha beside square in the same bio is
        // the live shape (InstagramAutoSyncTest) — so the sibling check spans
        // the class, and a connection already holding the slot counts too:
        // pre-ticking a second candidate against it would only trip the cap
        // on Continue.
        $exclusive = $this->isExclusiveAuto($routingClass);

        $intentSibling = DB::table('routing.source_intents')
            ->where('user_id', $user->id)
            ->when($exclusive, fn ($q) => $q->where('routing_class', $routingClass))
            ->when(! $exclusive, fn ($q) => $q->where('surface_key', $surfaceKey))
            ->where('identifier', '!=', $excludeIdentifier)
            ->where('band', 'auto')
            ->whereIn('state', ['proposed', 'verifying', 'applied', 'blocked'])
            ->exists();
        if ($intentSibling) {
            return true;
        }

        return $exclusive && IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('routing_class', $routingClass)
            ->whereNull('deleted_at')
            ->visible()
            ->exists();
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
            // 'verifying' is LIVE and MUST be here (2026-09-03). It is one of
            // the four states idx_source_intents_live covers, so a second
            // harvest of the same link while its L2 check is still in flight
            // would find no row to advance, fall through to the INSERT, and
            // raise 23505 on that unique index — inside the LIFE-16
            // transaction, taking the whole reconcile down.
            ->whereIn('state', ['proposed', 'verifying', 'applied', 'blocked']);

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
            // Issue-13 fix (T27 hygiene, 2026-08-28): 'pending' means content
            // is still OWED (ConnectionPayload's contract) — true only where a
            // fetch will actually run. A link-only placement (no fetch
            // capability, or a non-content class whose enrichment nothing here
            // dispatches) previously sat 'pending' FOREVER; it is born
            // complete, so it is born 'ok'.
            'last_refresh_status' => ConnectionPayload::contentIsOwed($surfaceKey, $routingClass) ? 'pending' : 'ok',
        ]);
        $connection->created_by_catalog_digest = CompiledCatalog::digest();
        // Whose this is, decided from the origin while we still have it. A week
        // later the context is gone and the answer costs a re-scrape or a guess.
        // Non-fillable, like the digest above: system-written provenance, never
        // mass-assigned from a request.
        $connection->owner_scope = RoutingCapabilityGate::ownerScopeFor($user, $context->origin);

        // #W1-LIFE-3 / #W2-LIFE-2. The read above and this INSERT are a
        // classic pre-read/write gap: a concurrent reconcile for the same
        // (user, surface, resource) triple can commit between them, and the
        // loser then hits idx_platform_connections_unique_active
        // (20260727110005) — a 500 / failed job where the correct outcome is
        // the idempotent "already handled".
        //
        // SAVEPOINT, not a catch in place. We are inside reconcile()'s LIFE-16
        // transaction, and Postgres ABORTS the entire transaction on any
        // statement error: a 23505 caught where it was raised leaves every
        // later statement failing 25P02, so the re-read below could never run.
        // A nested DB::connection('pgsql')->transaction() emits SAVEPOINT /
        // RELEASE instead of BEGIN / COMMIT; on the violation Laravel rolls
        // back to the savepoint and rethrows, leaving the outer transaction
        // healthy. The catch therefore sits OUTSIDE the nested call, by which
        // point the savepoint is already gone. Exactly the idiom in
        // SiteProvisioningService::attemptSubdomain() (and Laravel's own
        // Builder::createOrFirst()); this repo has shipped the catch-in-place
        // version three times (see ProjectionWriter.php's note).
        //
        // Not insertOrIgnore, either (the SourceProvisioner idiom): that
        // bypasses Eloquent, and with it IntegrationConnectionObserver,
        // booted()'s saving() validators, HasUuids, and the non-fillable
        // created_by_catalog_digest set above.
        //
        // UniqueConstraintViolationException covers BOTH drivers — Postgres
        // matches 23505, SQLite matches the "UNIQUE constraint failed"
        // message — so never string-match a SQLSTATE here.
        try {
            DB::connection('pgsql')->transaction(function () use ($connection) {
                $connection->save();
            });
        } catch (UniqueConstraintViolationException $e) {
            // Under READ COMMITTED the winner is visible now: it committed,
            // which is precisely why we got the violation. The throw converts
            // an impossible re-read (violation on a row that then isn't there)
            // into a loud failure rather than a bogus id.
            $winnerId = IntegrationConnection::query()
                ->where('user_id', $user->id)
                ->where('surface_key', $surfaceKey)
                ->where('resource_id', $identifier)
                ->whereNull('deleted_at')
                ->value('id');

            if ($winnerId === null) {
                throw $e;
            }

            // Same early return as the $connection !== null arm above: the
            // winner already owns the is_primary decision and, for a content
            // surface, already dispatched its own ConnectFetchJob.
            return (string) $winnerId;
        }

        // First connection in an exclusive class owns the CTA.
        if ($this->isExclusiveAuto($routingClass)) {
            $hasPrimary = IntegrationConnection::query()
                ->where('user_id', $user->id)
                ->where('routing_class', $routingClass)
                ->where('is_primary', true)
                ->whereNull('deleted_at')
                ->exists();

            if (! $hasPrimary) {
                // #W2-LIFE-3, second half. Same savepoint reasoning as above,
                // against idx_platform_connections_primary_per_class
                // (20260727110008). Different resolution though: losing this
                // race means someone else's connection owns the CTA, which is
                // a perfectly good outcome — swallow and carry on with
                // is_primary false rather than rethrowing. reconcile() holds
                // the exclusive-slot lock across this whole span for auto
                // writes, so in practice only a DIRECT paste (which bypasses
                // the lock by design) can reach the catch.
                try {
                    DB::connection('pgsql')->transaction(function () use ($connection) {
                        $connection->forceFill(['is_primary' => true])->save();
                    });
                } catch (UniqueConstraintViolationException) {
                    // Keep the in-memory model honest with what the DB holds.
                    $connection->is_primary = false;
                }
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
        // AutoBookingConnectDispatcher with its in-setup rule (2026-09-04 —
        // was claimed/unclaimed; the picker survives either way because
        // FreshaAutoSelector only auto-completes a claimed partna's row when
        // it lands their own employee menu), and shop rows enrich through
        // their own connect jobs.
        // afterCommit: this runs inside reconcile()'s transaction.
        if (ConnectionPayload::contentIsOwed($surfaceKey, $routingClass)) {
            ConnectFetchJob::dispatch((string) $connection->id, LegacyPlatformMap::legacyFor($surfaceKey), systemInitiated: true)->afterCommit();
        }

        return (string) $connection->id;
    }
}
