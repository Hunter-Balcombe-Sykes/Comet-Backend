<?php

namespace App\Routing;

use App\Catalog\Enums\RoutingClass;
use App\Catalog\LegacyPlatformMap;
use App\Jobs\Content\ReparentBioItemsJob;
use App\Jobs\Platforms\ConnectFetchJob;
use App\Jobs\Platforms\InstagramConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\PreAccountBuildEvent;
use App\Models\Core\User\User;
use App\Services\Cache\ApifyBudget;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Content\LinkPoolWriter;
use App\Services\Platforms\AutoBookingConnectDispatcher;
use App\Services\PreAccount\BuildProgress;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
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
    /**
     * Store surfaces the probe runtime identifies (LinkProbeWorker's
     * cascade). Homed here, not on SuggestionsController (2026-09-05): both
     * the controller's accept() and SetupBatchApplier::acceptOne() need it —
     * the setup dialog's Continue never had it, so a store suggestion
     * accepted there wrote a bare connection with no storefront, no
     * catalogue and no fill (the JRLUSA squeakprobarber gap).
     */
    public const PROBED_STORE_SURFACES = ['shopify.store', 'woocommerce.store', 'squarespace.store', 'bigcartel.store'];

    /**
     * Eventbrite/Humanitix organiser surfaces (2026-09-06): both are
     * PlatformRouteShape::Bespoke with no ConnectStrategy of their own — the
     * bare connection this applier writes would carry no organiser name, no
     * events list, no avatar. Same reason PROBED_STORE_SURFACES bypasses this
     * applier for a probed storefront; the real write is
     * EventsSeeder::seedAccount(), reached via CommerceProbeJob exactly like
     * the store arm reaches StoreBrandSeeder.
     */
    public const PROBED_EVENT_ORGANISER_SURFACES = ['eventbrite.organiser', 'humanitix.organiser'];

    /**
     * D6 (2026-09-06): Cache::lock TTL/block-wait for the exclusive-slot lock
     * below. Same values as SourceReconciler::EXCLUSIVE_SLOT_LOCK_TTL/
     * EXCLUSIVE_SLOT_LOCK_BLOCK — this method serialises on the identical
     * key, so a mismatched wait would just mean whichever writer waits
     * shorter loses every contested race.
     */
    private const EXCLUSIVE_SLOT_LOCK_TTL = 10;

    private const EXCLUSIVE_SLOT_LOCK_BLOCK = 3;

    public function __construct(
        private readonly ConnectionIdentity $identity,
        private readonly AutoBookingConnectDispatcher $autoBookingConnect,
        private readonly LinkPoolWriter $links,
        private readonly ApifyBudget $apifyBudget,
    ) {}

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
            // Issue-13 fix: born 'ok' unless a fetch will actually run.
            'last_refresh_status' => ConnectionPayload::contentIsOwed($surfaceKey, $routingClass) ? 'pending' : 'ok',
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
    public function apply(User $user, object $intent, array $surface, bool $hidden = false): IntegrationConnection
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
            // #W2-SEC-12: scope by owner, not id alone — matching
            // SuggestionsController::findIntent()'s tenant-scoping discipline
            // so this method carries its own safety net rather than trusting
            // every future caller to pre-scope $intent.
            DB::table('routing.source_intents')
                ->where('id', $intent->id)
                ->where('user_id', $user->id)
                ->update([
                    'state' => 'blocked',
                    'block_reason' => 'gate',
                    'updated_at' => now(),
                ]);

            throw new AuthorizationException($denied);
        }

        // D6 (2026-09-06): nothing previously serialised this transaction
        // against a concurrent accept() of a SIBLING exclusive-class intent —
        // two near-simultaneous "Replace"/"Add" clicks for the same class
        // could both read the same stale incumbent and both write a live
        // connection. Same lock key every other exclusive-class writer takes
        // (SourceReconciler::exclusiveSlotLockKey(), the booking/reservations
        // XOR connect controllers), acquired OUTER to the transaction exactly
        // as SourceReconciler wraps its own settle-and-apply. Non-exclusive
        // classes take no lock, unchanged from before.
        $routingClass = (string) $intent->routing_class;
        $isExclusive = RoutingClass::from($routingClass)->isExclusiveAuto();
        $lockKey = $isExclusive ? $this->exclusiveSlotLockKey($routingClass, (string) $user->id) : null;

        $write = function () use ($user, $intent, $surface, $hidden, $isExclusive) {
            try {
                return DB::transaction(function () use ($user, $intent, $surface, $hidden, $isExclusive) {
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
                    $sameAccountIncumbent = null;
                    if ($intent->conflicting_connection_id !== null) {
                        $incumbent = IntegrationConnection::query()
                            ->where('id', $intent->conflicting_connection_id)
                            ->where('user_id', $user->id)
                            ->first();
                        // A cap "swap" whose incumbent IS this account is not a swap
                        // (2026-09-05, st_ali): the Google listing seeds Instagram as
                        // a legacy marker row (resource_id='instagram') with no
                        // username yet, the reconciler files the bio's @st_ali as
                        // cap_reached against it, and by apply time the scrape has
                        // filled the marker's username in. Deleting it here threw
                        // away the scraped name and avatar and minted a bare
                        // duplicate keyed by handle — the walk showed both for a
                        // beat, then a nameless "@st_ali" with no picture. Same
                        // three-scheme identity read the alias lookup below uses.
                        if ($incumbent !== null
                            && $intent->block_reason === 'cap_reached'
                            && $this->identity->matchWithin(collect([$incumbent]), (string) $intent->surface_key, (string) $intent->identifier) !== null) {
                            $sameAccountIncumbent = $incumbent;
                        } elseif ($incumbent !== null && $intent->block_reason === 'cap_reached') {
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
                    $aliasConnectionId = $sameAccountIncumbent === null
                        ? $this->identity->matchExisting(
                            $user,
                            (string) $intent->surface_key,
                            (string) $intent->identifier,
                            $intent->conflicting_connection_id !== null ? (string) $intent->conflicting_connection_id : null,
                        )
                        : null;

                    $connection = $sameAccountIncumbent
                        ?? ($aliasConnectionId !== null
                            ? IntegrationConnection::query()->whereKey($aliasConnectionId)->first()
                            : null);

                    $connection ??= IntegrationConnection::query()
                        ->where('user_id', $user->id)
                        ->where('surface_key', $intent->surface_key)
                        ->where('resource_id', $intent->identifier)
                        ->whereNull('deleted_at')
                        ->first();

                    // D5 (2026-09-06): about to mint a BRAND NEW connection for an
                    // exclusive class. $intent->conflicting_connection_id only names
                    // a rival the record-time writer (SourceReconciler) or accept-
                    // time resolveSwapIncumbent() already knew about — and the
                    // latter only re-checks within $intent->surface_key, not the
                    // whole class. A live rival under a DIFFERENT surface (this
                    // intent is Fresha, a Square connection now exists, both
                    // 'booking') would otherwise sail straight through and mint a
                    // second live connection next to it. Same query shape as
                    // SourceReconciler::incumbentFor(), scoped to the whole
                    // exclusive routing_class rather than one surface_key; the
                    // incumbent already demoted/deleted above (if any) is excluded
                    // by id so it is never mistaken for a NEW discovery. Guarded
                    // behind the exclusive-slot lock acquired in apply(), so this
                    // read sees every writer that already committed and blocks every
                    // one still waiting on the same lock.
                    if ($connection === null && $isExclusive) {
                        $liveRivalId = IntegrationConnection::query()
                            ->where('user_id', $user->id)
                            ->where('routing_class', $intent->routing_class)
                            ->whereNull('deleted_at')
                            ->when(
                                $intent->conflicting_connection_id !== null,
                                fn ($q) => $q->where('id', '!=', (string) $intent->conflicting_connection_id),
                            )
                            ->where(fn ($q) => $q->where('surface_key', '!=', $intent->surface_key)->orWhere('resource_id', '!=', $intent->identifier))
                            ->value('id');

                        if ($liveRivalId !== null) {
                            // Thrown BARE here, deliberately: DB::transaction()
                            // must roll back everything above (the incumbent
                            // demote/delete included) exactly as the 0-rows-
                            // settled RuntimeException below does — nothing
                            // partial survives a discovery this late. The 'blocked'
                            // write that should survive happens in $write's catch,
                            // AFTER the transaction has rolled back — writing it
                            // here would roll back with everything else instead of
                            // sticking.
                            throw new ExclusiveSlotConflictException((string) $intent->id, $liveRivalId);
                        }
                    }

                    if ($connection === null) {
                        $scrapeInstagram = $this->instagramScrapeOwed((string) $intent->surface_key);
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
                            // A pre-scrape apply (A.4) is born hidden: it ingests but
                            // stays off every consumer-facing surface until the setup
                            // dialog reveals or discards it. Only a row created here —
                            // an existing row's visibility is its owner's business.
                            'visibility' => $hidden ? IntegrationConnection::VISIBILITY_HIDDEN : IntegrationConnection::VISIBILITY_VISIBLE,
                            // Issue-13 fix: same predicate as the dispatch below.
                            'last_refresh_status' => $scrapeInstagram || ConnectionPayload::contentIsOwed((string) $intent->surface_key, (string) $intent->routing_class) ? 'pending' : 'ok',
                        ]);
                        // Whose this connection is. Not fillable (system-written), so
                        // it is assigned after construction, exactly as
                        // SourceReconciler::applyIntent does it.
                        //
                        // Load-bearing since 2026-09-03: a harvested link no longer
                        // auto-connects, so the workplace-vs-self answer that used to
                        // be written at RECORD time on the connection now has to
                        // survive the round trip through the intent and be written at
                        // ACCEPT time. The intent's own origin is the input — the
                        // origin the link was FOUND on decides the answer, and 'paste'
                        // (which this accept technically is) would read every
                        // workplace booking link as the person's own.
                        $connection->owner_scope = RoutingCapabilityGate::ownerScopeFor($user, (string) $intent->origin);
                        $connection->user()->associate($user);
                        $connection->save();

                        // The store is owed from the ACCEPT, not from the connect
                        // job's first line (2026-09-05, squeakprobarber + st_ali):
                        // ShopBrandConnectJob writes this same note when it starts,
                        // but between Continue and the queue picking it up the
                        // items.shop pass read ready with nothing in it, and the
                        // walk's "hold Continue until products are ready" had
                        // nothing to hold on — "Your products" was empty until a
                        // refresh. One STARTED per stage, so the job's own is a no-op.
                        if ((string) $intent->routing_class === 'shop') {
                            BuildProgress::noteForUser((string) $user->id, PreAccountBuildEvent::STAGE_SHOP, PreAccountBuildEvent::STATUS_STARTED, 'Syncing your store');
                        }
                        if ($scrapeInstagram) {
                            $this->preScrapeInstagram($user, $connection, (string) $intent->identifier);
                        }

                        // F14 (2026-08-20, whole-run critic): F9 wired the enrichment
                        // fetch into SourceReconciler::applyIntent — the AUTO-place
                        // path — but T9b is suggest-only by design, so every
                        // connection its feature produces is born HERE, via accept,
                        // and sat as the same nameless URL-as-account row F9 exists
                        // to prevent until a scheduled refresh happened by. Same rule
                        // as applyIntent, verbatim: CONTENT class only (booking
                        // enrichment goes through maybeDispatchAutoBooking() below,
                        // post-transaction; shop rows enrich through their own
                        // connect jobs), only when the surface declares a fetch, and
                        // afterCommit because this runs inside the transaction. Only
                        // for a row created here — a matched-existing row came from a
                        // lane that already owns its enrichment.
                        if (ConnectionPayload::contentIsOwed((string) $intent->surface_key, (string) $intent->routing_class)) {
                            ConnectFetchJob::dispatch(
                                (string) $connection->id,
                                LegacyPlatformMap::legacyFor((string) $intent->surface_key),
                                systemInitiated: true,
                            )->afterCommit();

                            // A.6(c): the bio scrape may have seeded this platform's
                            // videos/tracks as manual library items before ingest
                            // lands the real rows — fold the duplicates once items
                            // exist. The job self-releases until the source has rows.
                            ReparentBioItemsJob::dispatch((string) $connection->id)
                                ->delay(now()->addSeconds(60))
                                ->afterCommit();
                        }

                        // A.1b forward guard (2026-09-05): item 2 stopped a filed
                        // Choose/Hold from carding its own url a second time, but a
                        // card already sitting there from BEFORE the platform was
                        // recognised — or before this suggestion was accepted — would
                        // still sit under the connection this accept just created.
                        // Same coord CustomLinkSeeder::seedCustom() writes with, so
                        // this only ever retires a card for THIS canonical url.
                        if (is_string($intent->canonical_url ?? null) && $intent->canonical_url !== '') {
                            $this->links->removeByUrl($user, (string) $intent->canonical_url);
                        }
                    }

                    // A booking-class Replace makes the new row the primary; a cap
                    // Swap only does so when the row it retired held that flag.
                    if ($intent->conflicting_connection_id !== null && ($intent->block_reason !== 'cap_reached' || $inheritsPrimary)) {
                        $connection->forceFill(['is_primary' => true])->save();
                    }

                    // #W2-SEC-12: same owner-scoping as the denial branch above. A
                    // mismatched-owner $intent would otherwise let a foreign intent's
                    // surface/identifier data mint a connection under $user while this
                    // predicate matches 0 rows below, settling nothing — the
                    // connection creation above would then be the only visible
                    // effect, silently succeeding on inconsistent state. This is the
                    // LAST statement in the transaction, so throwing here rolls back
                    // the connection create/update and the incumbent demotion above,
                    // matching SourceReconciler::upsertIntent's "affected-row
                    // count as the invariant check" pattern (~:530-533).
                    $settled = DB::table('routing.source_intents')
                        ->where('id', $intent->id)
                        ->where('user_id', $user->id)
                        ->update([
                            'state' => 'applied',
                            'block_reason' => null,
                            'connection_id' => $connection->id,
                            'resolved_at' => now(),
                            'updated_at' => now(),
                        ]);

                    if ($settled === 0) {
                        throw new \RuntimeException("Could not settle source intent {$intent->id} for user {$user->id}");
                    }

                    // Booking/reservations/ordering are exclusive: only one incumbent
                    // connection per class. A sibling intent under a DIFFERENT
                    // identifier (same class, same user) can be legitimately
                    // 'proposed' at the same time as this one — neither had a
                    // connection to check against when either was first seen, so
                    // SourceReconciler's cap check never held either against the
                    // other (live: Akro Studio, 2026-09-06 — Google Business's
                    // generic book.squareup.com/appointments/… link and the
                    // website's own akro-studio.square.site both proposed for
                    // square.book before this accept, and rendered as two
                    // unrelated-looking "Square" cards). Accepting ONE settles the
                    // class; every OTHER live sibling must stop rendering as an
                    // independent suggestion. Blocked with the same cap_reached
                    // shape SourceReconciler::recordCapBlock() uses, so it renders
                    // (or doesn't, mid-setup — SetupPayload's blocked-intent guard)
                    // through the identical machinery and stays resolvable later as
                    // a Swap from the suggestions inbox.
                    if (RoutingClass::from((string) $intent->routing_class)->isExclusiveAuto()) {
                        DB::table('routing.source_intents')
                            ->where('user_id', $user->id)
                            ->where('routing_class', $intent->routing_class)
                            ->where('id', '!=', $intent->id)
                            ->whereIn('state', ['proposed', 'verifying'])
                            ->whereRaw('lower(identifier) != ?', [mb_strtolower((string) $intent->identifier)])
                            ->update([
                                'state' => 'blocked',
                                'block_reason' => 'cap_reached',
                                'conflicting_connection_id' => $connection->id,
                                'resolved_at' => now(),
                                'updated_at' => now(),
                            ]);
                    }

                    return $connection;
                });
            } catch (ExclusiveSlotConflictException $e) {
                // D5: the transaction above just rolled back in full (the
                // incumbent demote/delete included) — this write happens
                // AFTER that, as its own statement, so it is the only thing
                // that survives. Same 'conflict' shape SourceReconciler's own
                // cross-surface classification uses, so
                // questionFor()/actionsFor() already render it as an
                // immediately actionable Swap on the next inbox read.
                DB::table('routing.source_intents')
                    ->where('id', $e->intentId)
                    ->where('user_id', $user->id)
                    ->update([
                        'state' => 'blocked',
                        'block_reason' => 'conflict',
                        'conflicting_connection_id' => $e->conflictingConnectionId,
                        'resolved_at' => now(),
                        'updated_at' => now(),
                    ]);

                throw $e;
            }
        };

        if ($lockKey !== null) {
            try {
                $connection = Cache::lock($lockKey, self::EXCLUSIVE_SLOT_LOCK_TTL)->block(self::EXCLUSIVE_SLOT_LOCK_BLOCK, $write);
            } catch (LockTimeoutException $e) {
                throw new ExclusiveSlotContendedException($lockKey, $e);
            }
        } else {
            $connection = $write();
        }

        $this->maybeDispatchAutoBooking($user, (string) $intent->surface_key, $connection);

        return $connection;
    }

    /**
     * 2026-09-04: an accepted booking connection is born selection-less, and
     * before this the accept lane simply never enriched it — F14's comment
     * ("booking enrichment is owned by AutoBookingConnectDispatcher's
     * claimed/unclaimed rule") was true and the rule never fired here, so a
     * Get Started accept sat waiting on the client's picker round-trip.
     * Hand it to the same dispatcher SourceReconciler uses, under the same
     * kill switch and install-wide daily cap, and only while the person is
     * still in setup — a post-setup inbox accept keeps today's picker-first
     * flow. FreshaAutoSelector's picker-preserving degrade means a claimed
     * partna can only ever gain their OWN menu from this; an ambiguous match
     * still lands the team picker, now pre-scraped.
     *
     * AFTER the transaction, deliberately (mirrors SourceReconciler): the
     * dispatcher re-queries the row just written, and under sync queues the
     * Fresha strategy takes the booking-XOR lock this stack must not hold.
     * A row that already carries a selection is left alone — enrichment is
     * for the newborn, not a lane to overwrite a pick.
     */
    /**
     * Instagram is a SOCIAL surface with no catalog fetch, so a row created
     * here used to be born bare and stay bare: no profile picture, no
     * posts, no bio links (2026-09-05, st_ali retest #3 — the walk's card
     * wore the Instagram glyph, and the store the bio linked to never
     * surfaced). The direct Google lane runs InstagramConnectJob for its
     * placeholder; the signup lane routes the same handle through here and
     * owes the same scrape. Budgeted exactly as GoogleBusinessAutoSync::
     * dispatchInstagram() is — no token or no allowance means a bare row,
     * not a queued job that bills nothing.
     */
    /**
     * D6 (2026-09-06): the serialisation point for an exclusive routing
     * class — the SAME key every other writer of that class's slot takes
     * (booking/reservations XOR connect controllers, GoogleBusinessAutoSync's
     * seed lock, SourceReconciler's own exclusiveSlotLockKey()), so this
     * excludes them rather than merely excluding other accept() calls.
     *
     * Deliberately duplicated rather than shared with SourceReconciler's
     * private method of the same name/shape, for the same reason that
     * method's own docblock gives: it is a small, stable, three-case match
     * resolved from CacheKeyGenerator directly, not a utility worth a new
     * shared indirection between two callers.
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

    private function instagramScrapeOwed(string $surfaceKey): bool
    {
        return $surfaceKey === 'instagram.profile'
            && (bool) config('services.apify.token')
            && $this->apifyBudget->tryClaim('instagram');
    }

    private function preScrapeInstagram(User $user, IntegrationConnection $connection, string $username): void
    {
        // The walk's platforms loader waits for this: the bio's own store
        // link is only found inside the scrape, and the job answers under
        // the same token on every exit (InstagramConnectJob::settleOwed).
        BuildProgress::noteForUser((string) $user->id, PreAccountBuildEvent::STAGE_PLATFORMS, PreAccountBuildEvent::STATUS_STARTED, 'Syncing Instagram', [
            BuildProgress::TOKEN => InstagramConnectJob::OWED_TOKEN,
        ]);
        InstagramConnectJob::dispatch((string) $user->id, $username, (string) $connection->id)->afterCommit();
    }

    private function maybeDispatchAutoBooking(User $user, string $surfaceKey, IntegrationConnection $connection): void
    {
        if (! in_array($surfaceKey, ['fresha.book', 'square.book'], true)) {
            return;
        }
        if (! (bool) config('partna.connect.auto_booking.enabled', true)) {
            return;
        }
        if (! $user->isInSetup()) {
            return;
        }
        if ((($connection->payload ?? [])['selection'] ?? null) !== null) {
            return;
        }

        $this->autoBookingConnect->dispatchFor(
            (string) $user->id,
            $surfaceKey === 'square.book' ? 'square' : 'fresha',
        );
    }
}
