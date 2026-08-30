<?php

namespace App\Services\Platforms\Concerns;

use App\Catalog\LegacyPlatformMap;
use App\Jobs\Platforms\ConnectFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\DailyCounterClaim;
use App\Services\Platforms\AutoBookingConnectDispatcher;
use App\Services\Platforms\FreshaScraper;
use App\Services\Platforms\Normalizers\FacebookNormalizer;
use App\Services\Platforms\Payloads\CardPayload;
use App\Services\Platforms\Registry\Platform;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\Strategies\Connect\UrlConnect;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// SLOP-101: the finding-shape + write/apply plumbing that GoogleBusinessAutoSync
// and InstagramAutoSync used to duplicate byte-for-byte (both mirror one
// another's seed() shape — see each class's docblock). Lifted here verbatim so
// a future change to the finding shape or the write/apply semantics only
// happens once.
//
// LIFE-105/106: also carries the booking-XOR lock both services take before
// touching a booking-category slot (fresha/square/booking) — see
// withBookingXorLock() below for why this can't live per-platform.
//
// PWL-9: generalised into runUnderSeedLock() so the reservations-XOR and
// per-platform seed locks below (withReservationsXorLock / withPlatformSeedLock)
// share the same acquire/timeout/log shape instead of re-deriving it.
trait BuildsAutoSyncFindings
{
    /** Cache::lock TTL (seconds) for an auto-sync seed lock — auto-released if a hung request never reaches block()'s timeout. */
    private const SEED_LOCK_TTL = 10;

    /** Cache::lock block() wait (seconds) before giving up and taking the caller's default outcome. */
    private const SEED_LOCK_BLOCK = 3;

    /**
     * U1: booking platforms a finding's `apply` recipe could remove/write —
     * the UNION of GoogleBusinessAutoSync::BOOKING_PLATFORMS (includes
     * 'booking') and InstagramAutoSync::BOOKING_PLATFORMS (does not). The two
     * seed-side consts stay separate and deliberately differ; this third list
     * must equal their union or it silently under-covers one producer — the
     * same drift shape as the platformConnectionLock suffix bug — so it is
     * pinned by a reflection test (BookingXorConnectRaceTest).
     */
    private const BOOKING_SLOT_PLATFORMS = [
        Platform::Fresha->value,
        Platform::Square->value,
    ];

    /**
     * U1 addendum (orchestrator decision): the reservations family carries the
     * identical unlocked bypass on the same seam — mirrors
     * GoogleBusinessAutoSync::RESERVATION_PLATFORMS, its only producer.
     */
    private const RESERVATIONS_SLOT_PLATFORMS = [
        Platform::OpenTable->value,
        Platform::Resdiary->value,
        Platform::Nowbookit->value,
    ];

    /**
     * $removePath overrides the modal's default "/platforms/<platform>" remove
     * link. Null for every single-row platform (where the default IS the row);
     * set only where one platform holds many rows and forget-everything would
     * be the wrong button — see LinkRouter::seedEvent.
     *
     * @return array<string,mixed>
     */
    private function seededFinding(string $platform, string $resourceId, string $category, string $label, ?string $foundUrl, ?string $removePath = null): array
    {
        return [
            'platform' => $platform,
            'resourceId' => $resourceId,
            'category' => $category,
            'label' => $label,
            'foundUrl' => $foundUrl,
            'outcome' => 'seeded',
            'apply' => null,
            'removePath' => $removePath,
        ];
    }

    /**
     * @param  array<string,mixed>  $apply
     * @return array<string,mixed>
     */
    private function conflictFinding(string $platform, string $resourceId, string $category, string $label, ?string $foundUrl, array $apply): array
    {
        return [
            'platform' => $platform,
            'resourceId' => $resourceId,
            'category' => $category,
            'label' => $label,
            'foundUrl' => $foundUrl,
            'outcome' => 'conflict',
            'apply' => $apply,
        ];
    }

    /**
     * Model write (not quiet): the observer purges the sitepage edge cache for
     * the newly-public connection. Shared by GoogleBusinessAutoSync::seed
     * (via seedReservation / seedBooking / seedOrdering / seedSocials) and
     * InstagramAutoSync::seed.
     *
     * @param  array<string,mixed>  $payload
     */
    private function write(string $userId, string $platform, string $resourceId, array $payload): void
    {
        // Match on surface_key, NOT platform. `platform` is a GENERATED column
        // in Postgres (split_part of surface_key, plus the SPECIAL_TO_LEGACY
        // CASE), so it only ever holds a legacy slug. Since convergence Phase 6
        // the router also passes CATALOG surface keys for brands added after P1
        // (uber_eats.order, thefork.reserve, …) — those have no legacy slug, and
        // matching a surface key against the generated column can never hit.
        // The lookup would miss on every re-sync and updateOrCreate would CREATE
        // a duplicate row each time instead of updating one.
        //
        // surface_key is what the mutator resolves either input to, so keying on
        // it is exactly equivalent for the 78 legacy slugs and correct for the rest.
        IntegrationConnection::updateOrCreate(
            [
                'user_id' => $userId,
                'surface_key' => LegacyPlatformMap::surfaceFor($platform) ?? $platform,
                'resource_id' => $resourceId,
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

    /**
     * "Change to" — install the found connection (Google enrichment or an
     * Instagram bio link) over the user's existing one. Removes whatever
     * currently occupies the slot, then either lets the consumer claim the
     * finding via applyFindingHandled() (GoogleBusinessAutoSync re-dispatches
     * the Instagram scrape instead of writing) or writes the `apply.write`
     * recipe directly. Idempotent + best-effort.
     *
     * U1: a finding that occupies the booking or reservations slot now runs
     * its remove-then-write as ONE atomic span under that family's shared XOR
     * lock (bookingXorLock / reservationsXorLock) — closing the gap that let
     * a concurrent Square/Fresha connect (or a second "Change to") observe
     * the slot mid-swap and install a second live provider. A finding whose
     * remove list spans BOTH families takes BOTH locks (booking outer,
     * reservations inner — see applyFinding()'s cross-family branch) so
     * neither family's delete is left unguarded. Every other finding (social,
     * ordering, workplace) is unaffected — still lock-free, byte-identical to
     * before.
     *
     * Unlike the connect controllers (which re-assert their conflict check
     * INSIDE the lock), this does not re-derive a conflict — the finding IS
     * the decision, made at seed time, and "Change to" means overwrite
     * whatever is there unconditionally. Its atomicity requirement is
     * remove+write, not check+write, so nothing is re-checked under the lock.
     *
     * @param  array<string,mixed>  $finding  a conflict finding (carries `apply`)
     * @return bool true = applied (or nothing to apply); false = the slot's
     *              lock was contended within SEED_LOCK_BLOCK seconds and
     *              NOTHING changed — the caller must NOT flip the finding to
     *              seeded, and should surface a 423 instead
     *
     * @throws AuthorizationException when a booking/reservations-slot finding
     *                                fails its apply-time capability re-check (see #SILENT-1: a
     *                                bare `true` here used to look like success while nothing
     *                                was written).
     */
    public function applyFinding(string $userId, array $finding): bool
    {
        $apply = $finding['apply'] ?? null;
        if (! is_array($apply)) {
            return true;
        }

        $looksBooking = $this->looksLikeBookingSlotApply($finding, $apply);
        $looksReservations = $this->looksLikeReservationsSlotApply($finding, $apply);

        // Capability RE-check at apply time (2026-08-04). The capability was
        // verified when the finding was RECORDED (GoogleBusinessAutoSync::
        // sync() gates on can_use_booking / can_use_reservations before
        // producing either family's recipe), but findings are durable and the
        // account's sector — hence its capability set — can change between
        // record and apply. Without this, a stale conflict finding was a
        // ticket to install a booking/reservations connection the connect
        // controllers themselves would 403. Mirrors the seed-time gate.
        //
        // #SILENT-1 (2026-08-05): this used to `return true` on denial. Both
        // callers read true as "applied", flipped the finding to 'seeded' and
        // returned 200 — but nothing was written, so the next read's
        // shapeFinding() found no connection and silently dropped the item.
        // Throwing instead makes this identical to its twin, SuggestionApplier
        // ::apply() — the call sits outside both controllers' lock closures
        // (and outside InstagramController's try, which only catches
        // LockTimeoutException), so it reaches the global handler untouched:
        // AuthorizationException -> AccessDeniedHttpException -> 403 +
        // Log::warning('Access denied'), for free.
        if ($looksBooking || $looksReservations) {
            $user = User::find($userId);
            $capabilities = $user ? AccountCapabilities::for($user) : null;
            $bookingDenied = $looksBooking && ($capabilities === null || ! $capabilities->can_use_booking);
            $reservationsDenied = $looksReservations && ($capabilities === null || ! $capabilities->can_use_reservations);
            if ($bookingDenied || $reservationsDenied) {
                throw new AuthorizationException(
                    $bookingDenied ? 'booking is not available for this account' : 'reservations are not available for this account'
                );
            }
        }

        // Trap 4 / escape hatch: `apply.instagram` is produced in exactly one
        // place — GoogleBusinessAutoSync's Instagram/social recipe — and that
        // recipe is ALWAYS category:'social', never 'booking'/'reservations'.
        // So a finding that looks booking- and/or reservations-eligible AND
        // carries apply.instagram should be structurally unreachable;
        // report() makes a future hybrid recipe a loud canary instead of
        // silently holding a 10s lock across applyFindingHandled()'s ~110s
        // inline Apify scrape. Checked ONCE here, ahead of both family
        // branches below (not once per family inside each predicate) — a
        // finding whose remove list happens to match BOTH BOOKING_SLOT_
        // PLATFORMS and RESERVATIONS_SLOT_PLATFORMS would otherwise trip this
        // same canary twice for one applyFinding() call.
        if (($looksBooking || $looksReservations) && is_array($apply['instagram'] ?? null)) {
            $slot = match (true) {
                $looksBooking && $looksReservations => 'booking+reservations',
                $looksBooking => 'booking',
                default => 'reservations',
            };
            report(new \RuntimeException(
                'applyFinding: apply.instagram co-occurred with a '.$slot.'-slot recipe (finding category='.
                var_export($finding['category'] ?? null, true).
                ') — should be structurally impossible; bailing to the unlocked path.'
            ));
            $looksBooking = false;
            $looksReservations = false;
        }

        if ($looksBooking && $looksReservations) {
            // U1 review fix: a cross-family recipe (remove spans BOTH
            // booking and reservations platforms) must hold BOTH XOR locks
            // for the whole remove+write span, or whichever family only got
            // the other lock would have its delete race that family's own
            // writers (e.g. the opentable delete below racing another
            // reservations-family delete on the same lock). No real producer emits a
            // remove list spanning both families today (each conflictFinding
            // call site hardcodes a single family's platform list) — this is
            // belt-and-braces for a legacy/hand-crafted finding, same spirit
            // as the escape hatch above.
            //
            // Deterministic order: bookingXorLock OUTER, reservationsXorLock
            // INNER. This cannot cycle: reservationsXorLock's own closure
            // here never itself acquires another lock (runApply() is a plain
            // DB read/delete/write), and nothing else in the codebase ever
            // holds reservationsXorLock and then requests bookingXorLock (or
            // platformConnectionLock) — every other reservationsXorLock
            // holder (ReservationsController, GoogleBusinessAutoSync::
            // seedReservation) takes it ALONE. So this is a strict two-level
            // chain (booking -> reservations), consistent with the existing
            // bookingXor-outer/platformConnectionLock-inner precedent in
            // ManagesIntegrationConnection::withCrossPlatformLock, and adds
            // no new edge that could complete a cycle against it.
            return $this->withBookingXorLock($userId, function () use ($userId, $apply): bool {
                return $this->withReservationsXorLock($userId, function () use ($userId, $apply): bool {
                    $this->runApply($userId, $apply);

                    return true;
                }, false);
            }, false); // $default=false => "contended, nothing applied"
        }

        if ($looksBooking) {
            // Booking slot: remove + write are ONE span under the same key
            // every other booking writer takes. runApply()'s
            // applyFindingHandled() step is a proven no-op here (see the
            // escape hatch above) — no vendor call, no dispatch, DB only.
            return $this->withBookingXorLock($userId, function () use ($userId, $apply): bool {
                $this->runApply($userId, $apply);

                return true;
            }, false); // $default=false => "contended, nothing applied"
        }

        if ($looksReservations) {
            // Same seam, reservations family (U1 addendum, orchestrator
            // decision): the identical unlocked bypass existed here too
            // (category:'reservations', remove => RESERVATIONS_SLOT_PLATFORMS)
            // — closing one arm while leaving its twin open would read as an
            // oversight to the next reviewer.
            return $this->withReservationsXorLock($userId, function () use ($userId, $apply): bool {
                $this->runApply($userId, $apply);

                return true;
            }, false);
        }

        $this->runApply($userId, $apply); // today's body, verbatim, unlocked

        return true;
    }

    /**
     * True when this finding's remove+write span occupies a BOOKING slot
     * (fresha/square/booking) and so must run under bookingXorLock. Primary
     * arm: every real producer stamps category:'booking' on the finding
     * (seededFinding/conflictFinding take it as an argument). Secondary arm
     * (belt and braces): a legacy stored finding with no category, caught by
     * intersecting `apply.remove` against BOOKING_SLOT_PLATFORMS. Deliberately
     * NOT keyed on `apply.write.platform` — BookingXorLockTest's synthetic
     * social findings carry write.platform='square' and must stay unlocked.
     *
     * Pure predicate — no side effects. The apply.instagram escape hatch
     * (Trap 4) that used to live in here is now checked once in applyFinding()
     * itself, after both this and looksLikeReservationsSlotApply() have run,
     * so a cross-family finding can't report() the same canary twice.
     *
     * @param  array<string,mixed>  $finding
     * @param  array<string,mixed>  $apply
     */
    private function looksLikeBookingSlotApply(array $finding, array $apply): bool
    {
        return ($finding['category'] ?? null) === 'booking'
            || ($apply['removeRoutingClass'] ?? null) === 'booking'
            || array_intersect((array) ($apply['remove'] ?? []), self::BOOKING_SLOT_PLATFORMS) !== [];
    }

    /**
     * Reservations counterpart to looksLikeBookingSlotApply() — same shape,
     * RESERVATIONS_SLOT_PLATFORMS instead of the booking list. Also a pure
     * predicate; see that method's docblock for the escape-hatch note.
     *
     * @param  array<string,mixed>  $finding
     * @param  array<string,mixed>  $apply
     */
    private function looksLikeReservationsSlotApply(array $finding, array $apply): bool
    {
        return ($finding['category'] ?? null) === 'reservations'
            || ($apply['removeRoutingClass'] ?? null) === 'reservations'
            || array_intersect((array) ($apply['remove'] ?? []), self::RESERVATIONS_SLOT_PLATFORMS) !== [];
    }

    /**
     * The remove-then-write body — applyFinding()'s pre-U1 implementation, with
     * the delete+write pair made atomic (#W2-LIFE-16). Called either directly
     * (non-slot findings, unlocked) or from inside the relevant XOR lock's
     * closure (booking/reservations findings).
     *
     * #W2-LIFE-16: "Change to" removed the user's existing connection and THEN
     * wrote the replacement, with nothing between them. Any throw from write()
     * — a constraint, an observer, a dropped DB connection — left the user with
     * an empty slot where a live link had been, and no record that a swap had
     * been half-done. The XOR lock the booking/reservations arms take does not
     * help here: it stops a CONCURRENT writer observing the gap, not the gap
     * itself. The two DB steps are now one transaction, so a failed write
     * restores the old connection instead of losing it.
     *
     * The transaction is deliberately scoped to the DB span only. The
     * applyFindingHandled() hook is NOT inside it: GoogleBusinessAutoSync
     * overrides it to dispatch InstagramConnectJob, which under the sync driver
     * runs INLINE and takes its own cache lock — running that inside an open
     * transaction is the classic dispatch-before-commit trap, made worse by
     * queue.php's `after_commit => false`. That branch has nothing to make
     * atomic anyway: the only producer of an `apply.instagram` recipe
     * (GoogleBusinessAutoSync's social conflict finding) carries `remove` and
     * never `write`, so its removals are not one half of a pair.
     *
     * IntegrationConnectionObserver is `$afterCommit = true`, so wrapping these
     * writes also defers the Cloudflare purge and the site touch to after the
     * commit — the same property SourceReconciler::reconcile() already relies
     * on, and strictly better than firing them per-row mid-swap as before.
     *
     * @param  array<string,mixed>  $apply
     */
    private function runApply(string $userId, array $apply): void
    {
        $write = is_array($apply['write'] ?? null) ? $apply['write'] : null;

        if ($write === null) {
            // Nothing to pair the removals with, so nothing to make atomic —
            // and this is the branch the handled hook takes. Byte-identical to
            // the old body, transaction and all its hazards absent.
            $this->applyRemovals($userId, $apply);
            $this->applyFindingHandled($userId, $apply);

            return;
        }

        DB::connection('pgsql')->transaction(function () use ($userId, $apply, $write): void {
            $this->applyRemovals($userId, $apply);

            // Still consulted in its original position so the hook's contract is
            // unchanged. A recipe carrying BOTH `instagram` and `write` would
            // dispatch from inside the transaction — no producer emits one, and
            // applyFinding() already report()s the neighbouring co-occurrence,
            // so this is noted rather than defended against twice.
            if ($this->applyFindingHandled($userId, $apply)) {
                return;
            }

            $this->write($userId, (string) $write['platform'], (string) $write['resourceId'], (array) $write['payload']);
        });
    }

    /**
     * The remove half of runApply() — extracted so both branches share one
     * body. Verbatim from the pre-#W2-LIFE-16 implementation.
     *
     * @param  array<string,mixed>  $apply
     */
    private function applyRemovals(string $userId, array $apply): void
    {
        foreach ((array) ($apply['remove'] ?? []) as $platform) {
            if (! is_string($platform)) {
                continue;
            }
            IntegrationConnection::query()
                ->where('user_id', $userId)->where('platform', $platform)
                ->get()->each->delete();
        }

        // Convergence Phase 6. `remove` is a STORED slug list — a finding is
        // persisted when it is raised and applied later — so its shape cannot
        // change without invalidating findings already sitting in the blob.
        // `removeRoutingClass` is therefore ADDITIVE: old findings keep clearing
        // their three slugs, new ones clear the whole family.
        //
        // It is needed because a slug list can no longer enumerate a family.
        // Phase 6 gives each booking/reservation brand its own key, so "remove
        // whatever currently occupies the booking slot" is a routing_class
        // question, not a list of names anyone can keep up to date.
        $removeClass = $apply['removeRoutingClass'] ?? null;
        if (is_string($removeClass) && $removeClass !== '') {
            IntegrationConnection::query()
                ->where('user_id', $userId)->where('routing_class', $removeClass)
                ->get()->each->delete();
        }
    }

    /**
     * Hook for a consumer to claim the `apply` recipe instead of the default
     * `write` branch — GoogleBusinessAutoSync overrides this to re-dispatch
     * the Instagram scrape (and skip the write) when `apply.instagram` is
     * present. Returning false (the default) falls through to the write.
     *
     * @param  array<string,mixed>  $apply
     */
    protected function applyFindingHandled(string $userId, array $apply): bool
    {
        return false;
    }

    // ── LIFE-105 / LIFE-106: booking XOR lock ───────────────────────────────

    private function bookingXorLockKey(string $userId): string
    {
        return CacheKeyGenerator::bookingXorLock($userId);
    }

    /**
     * Serializes the check-then-write for "at most one live booking
     * connection per user" — the invariant spans THREE platforms
     * (fresha / square / booking, and GB additionally has a fourth listed in
     * its own BOOKING_PLATFORMS), so it cannot be enforced by a per-platform
     * lock: ManagesIntegrationConnection::withConnectionLock() keys on
     * (platform, userId), which would let a concurrent Fresha seed and Square
     * seed both pass their own lock and each write a "no conflict" row. The
     * DB index `idx_platform_connections_unique_active` is UNIQUE
     * (user_id, platform, resource_id) — also per-platform by construction —
     * so it structurally cannot enforce a cross-platform XOR either. This
     * lock is therefore the ONLY serialization point for the invariant, which
     * is why every booking-category write path in BOTH GoogleBusinessAutoSync
     * and InstagramAutoSync must take it — a non-booking (social) write is
     * still safely covered by the per-platform unique index alone and does
     * NOT need this lock.
     *
     * The key is deliberately per-USER-ONLY (no platform suffix) — that's
     * what makes it shared across both services and across every booking
     * platform for one user.
     *
     * The key itself now lives on CacheKeyGenerator::bookingXorLock() (PWL-14
     * promoted it so controllers can share the identical string); this helper
     * just delegates to runUnderSeedLock() so withBookingXorLock()'s call
     * sites, its log message, and the TTL/block constants above stay unchanged.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @param  T  $default  returned when the lock can't be acquired within SEED_LOCK_BLOCK seconds
     * @return T
     */
    protected function withBookingXorLock(string $userId, Closure $callback, mixed $default): mixed
    {
        return $this->runUnderSeedLock(
            $this->bookingXorLockKey($userId),
            'platforms.auto_sync.booking_lock_timeout',
            ['user_id' => $userId],
            $callback,
            $default,
        );
    }

    // ── PWL-9: reservations-XOR + per-platform seed locks ───────────────────

    /**
     * Serializes the check-then-write for "at most one live reservation
     * connection per user" — the invariant spans FOUR platforms (opentable /
     * resdiary / nowbookit / reservations, GoogleBusinessAutoSync::
     * RESERVATION_PLATFORMS), so — exactly like booking above — it cannot be
     * enforced by a per-platform lock or the per-platform unique index. Only
     * GoogleBusinessAutoSync::seedReservation takes this today; kept as its
     * own method (not folded into withBookingXorLock) because the two XOR
     * families are independent slots and must never share a key — see
     * CacheKeyGenerator::reservationsXorLock's docblock.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @param  T  $default  returned when the lock can't be acquired within SEED_LOCK_BLOCK seconds
     * @return T
     */
    protected function withReservationsXorLock(string $userId, Closure $callback, mixed $default): mixed
    {
        return $this->runUnderSeedLock(
            CacheKeyGenerator::reservationsXorLock($userId),
            'platforms.auto_sync.reservations_lock_timeout',
            ['user_id' => $userId],
            $callback,
            $default,
        );
    }

    /**
     * Serializes a single-platform auto-sync write against a concurrent
     * dashboard/controller write to the SAME (platform, user) row — the
     * counterpart, on the seeder side, to ManagesIntegrationConnection::
     * withConnectionLock() on the controller side. Uses the identical key
     * (CacheKeyGenerator::platformConnectionLock) so both sides serialize
     * against each other, not just against themselves.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @param  T  $default  returned when the lock can't be acquired within SEED_LOCK_BLOCK seconds
     * @return T
     */
    protected function withPlatformSeedLock(string $userId, string $platform, Closure $callback, mixed $default): mixed
    {
        return $this->runUnderSeedLock(
            CacheKeyGenerator::platformConnectionLock($platform, $userId),
            'platforms.auto_sync.platform_lock_timeout',
            ['user_id' => $userId, 'platform' => $platform],
            $callback,
            $default,
        );
    }

    /**
     * Shared acquire/block/timeout shape for every auto-sync seed lock above:
     * try the lock for SEED_LOCK_BLOCK seconds, log + fall back to $default on
     * timeout. `source` is stamped on the log line so a timeout can be traced
     * to GoogleBusinessAutoSync vs InstagramAutoSync without the message text
     * needing to encode it.
     *
     * @template T
     *
     * @param  array<string,mixed>  $timeoutContext
     * @param  Closure(): T  $callback
     * @param  T  $default
     * @return T
     */
    private function runUnderSeedLock(string $key, string $timeoutLog, array $timeoutContext, Closure $callback, mixed $default): mixed
    {
        try {
            return Cache::lock($key, self::SEED_LOCK_TTL)->block(self::SEED_LOCK_BLOCK, $callback);
        } catch (LockTimeoutException) {
            Log::warning($timeoutLog, $timeoutContext + ['source' => static::class]);

            return $default;
        }
    }

    // NB there is deliberately NO BOOKING_PLATFORMS constant on this trait.
    // Phase 2.5 originally added one (protected, [fresha, square, booking]) and
    // it made GoogleBusinessAutoSync unloadable: that class already declares
    // `private const BOOKING_PLATFORMS` with the SAME values, and PHP compares
    // a trait/class constant clash by DEFINITION — visibility included — not by
    // value, so private-vs-protected alone is "incompatible" and fatals at class
    // composition. resolveBookingLink() below uses BOOKING_SLOT_PLATFORMS, which
    // is already exactly the XOR set {fresha, square, booking} it needs.

    /**
     * Resolve the Fresha row just written and hand it to ConnectFetchJob.
     *
     * Lives in the trait because BOTH producers need it: LinkRouter (Instagram
     * bio links) and GoogleBusinessAutoSync (a booking link on a Google
     * listing). They reach a seeded Fresha row by completely different routes —
     * LinkRouter through routeClassified, GB through its own seedBooking — so a
     * copy in each is exactly the drift the trait exists to prevent.
     *
     * Re-queried rather than threaded back through write()/RouteResult:
     * widening those return types to carry an id is blast radius this is scoped
     * to avoid, and one indexed lookup is cheaper than that coupling.
     *
     * connectMode is stamped HERE, not in resolveWrite(), because resolveWrite
     * is shared with origins that must not be marked auto.
     */
    protected function dispatchAutoBookingConnect(string $userId): void
    {
        // Delegates since 2026-08-19: a third producer (the routing lane's
        // SourceReconciler, for unclaimed pre-account sites) needed this and
        // could not take the trait — it would have acquired write()/
        // resolveBookingLink() alongside, contradicting its single-writer
        // property. The implementation moved to AutoBookingConnectDispatcher;
        // this method stays so both legacy producers are unchanged and the
        // shared-cap invariant still reads true.
        app(AutoBookingConnectDispatcher::class)->dispatchFor($userId);
    }

    /**
     * Install-wide daily ceiling on auto-triggered salon scrapes.
     *
     * Mirrors partna.routing.probe's global_daily_cap and exists for the same
     * reason: an unbounded outbound request the backend makes on a user's say-so
     * is a reliability risk to us and an amplification vector aimed at someone
     * else. Shared by both producers, so the ceiling is genuinely install-wide
     * rather than one budget per discovery route.
     *
     * Claims through DailyCounterClaim rather than a private counter, for the
     * reason its docblock gives: a hand-rolled `Cache::add` + `increment` is two
     * round trips, and if the key expires between them INCRBY recreates it with
     * NO TTL — permanent inevictable ballast under instance-wide volatile-lru.
     *
     * Fails OPEN on a cache outage: losing the ceiling is a smaller harm than
     * silently stopping every signup from connecting its booking menu.
     */
    protected function claimAutoBookingBudget(): bool
    {
        // Delegates for the same reason as dispatchAutoBookingConnect above. The
        // ceiling must stay ONE counter across every producer — a copy here
        // would make it per-route, which is exactly what this method exists to
        // prevent.
        return app(AutoBookingConnectDispatcher::class)->claimBudget();
    }

    /** @return array{platform:string, resourceId:string, payload:array<string,mixed>} */
    protected function resolveWrite(string $platform, string $url): array
    {
        if ($platform === Platform::Fresha->value) {
            // Canonicalise here, not at the call site: this trait is shared by
            // three classes with unrelated constructors, and a per-class override
            // is exactly what once made LinkRouter write username:'' for every
            // Facebook link (see socialUsername below).
            return ['platform' => $platform, 'resourceId' => $platform, 'payload' => [
                'url' => app(FreshaScraper::class)->canonicalUrl($url), 'selection' => null, 'source' => 'instagram',
            ]];
        }
        if ($platform === Platform::Square->value) {
            return ['platform' => $platform, 'resourceId' => $platform, 'payload' => [
                'url' => $url, 'source' => 'instagram',
            ]];
        }

        return ['platform' => $platform, 'resourceId' => $platform, 'payload' => [
            'username' => $this->socialUsername($platform, $url), 'url' => $url, 'source' => 'instagram',
        ]];
    }

    /**
     * LIFE-106: booking-category span under withBookingXorLock().
     *
     * The conflict set is BOOKING_SLOT_PLATFORMS — the full XOR set
     * {fresha, square, booking}. Under Decision 10 every non-Fresha/Square
     * brand (Booksy, Timely, Vagaro, …) lands on the shared 'booking' key, so
     * a user with Booksy live must still conflict with an incoming Fresha link.
     *
     * @param  array{platform:string,resourceId:string,payload:array<string,mixed>}  $write
     * @param  array{platform:string,category:string,label:string}  $classified
     * @return array{findings:list<array<string,mixed>>,unmatched:list<array<string,mixed>>,consumed:bool}
     */
    protected function resolveBookingLink(string $userId, string $platform, string $url, array $write, array $classified): array
    {
        // Convergence Phase 6: the conflict set is every connection whose
        // ROUTING CLASS is booking, not the three-slug BOOKING_SLOT_PLATFORMS
        // list. Under Decision 10 every non-Fresha/Square brand shared the
        // 'booking' key, so three slugs covered the family exactly. Phase 6
        // retires that shared key and gives each brand its own — booksy,
        // calendly.book, treatwell.book — so a slug list can no longer enumerate
        // the family, and a user could hold Fresha AND Booksy at once.
        //
        // routing_class is the right axis for the same reason RoutingCapabilityGate
        // keys on it: it travels with surface_key on every row by construction
        // (IntegrationConnection::booted fills it from the map/catalog), so a
        // brand added later joins the XOR set without anyone remembering to.
        $conflictingBooking = IntegrationConnection::query()
            ->where('user_id', $userId)
            ->where('routing_class', 'booking')
            ->where('surface_key', '!=', LegacyPlatformMap::surfaceFor($platform) ?? $platform)
            ->first();

        if ($conflictingBooking !== null) {
            return [
                'findings' => [$this->conflictFinding($write['platform'], $write['resourceId'], $classified['category'], $classified['label'], $url, [
                    // Both: the slug list keeps a pre-Phase-6 consumer working,
                    // removeRoutingClass is what actually clears the family now
                    // that each brand carries its own key.
                    'remove' => self::BOOKING_SLOT_PLATFORMS,
                    'removeRoutingClass' => 'booking',
                    'write' => $write,
                ])],
                'unmatched' => [],
                'consumed' => true,
            ];
        }

        $existing = IntegrationConnection::query()
            ->where('user_id', $userId)->where('platform', $platform)
            ->first();

        if ($existing === null) {
            if ($this->wasDisconnected($userId, 'platform', $platform)) {
                return ['findings' => [], 'unmatched' => [['url' => $url, 'label' => $classified['label']]], 'consumed' => true];
            }

            $this->write($userId, $write['platform'], $write['resourceId'], $write['payload']);

            return [
                'findings' => [$this->seededFinding($write['platform'], $write['resourceId'], $classified['category'], $classified['label'], $url)],
                'unmatched' => [],
                'consumed' => true,
            ];
        }

        $existingUrl = CardPayload::fromArray($existing->payload)->url();
        if ($existingUrl !== null && $this->sameUrl($existingUrl, $url)) {
            return ['findings' => [], 'unmatched' => [], 'consumed' => true];
        }

        return [
            'findings' => [$this->conflictFinding($write['platform'], $write['resourceId'], $classified['category'], $classified['label'], $url, [
                'remove' => [$write['platform']],
                'write' => $write,
            ])],
            'unmatched' => [],
            'consumed' => true,
        ];
    }

    /**
     * Best-effort handle from a canonical social profile URL ('' when none).
     *
     * Facebook is delegated to FacebookNormalizer — the same parser the manual
     * connect form uses (G4-4). It is resolved from the container rather than
     * injected because this trait is shared by three classes with unrelated
     * constructors; InstagramAutoSync used to own a facebook-aware OVERRIDE of
     * this method, which meant LinkRouter — using the same trait but with no
     * override — silently wrote `username: ''` for every routed Facebook link.
     * Handling it here is what makes the two paths agree.
     */
    protected function socialUsername(string $platform, string $url): string
    {
        // The platform's own catalog-wired normalizer is the authority
        // (UrlConnect wraps exactly the URL/handle parser each link platform
        // already ships — Threads/Reddit/Snapchat/Twitch/Kick/Discord/
        // Telegram/Medium/… all have one). The hand-kept pattern list below
        // predates that wiring and silently returned '' for every platform
        // it did not name — found live 2026-08-27 (plan 03): a pasted
        // instagram profile seeded username:'', and so did every social
        // outside the four listed. UrlConnect resolution is a pure parse
        // (no network), so probing it here is free; bespoke/scraper connect
        // strategies are deliberately NOT invoked.
        $strategy = app(PlatformRegistry::class)->get($platform)?->connectStrategy();
        if ($strategy instanceof UrlConnect) {
            $selection = $strategy->resolve($url)->selection;
            $username = (string) ($selection['username'] ?? $selection['handle'] ?? '');
            if ($username !== '') {
                return $username;
            }
        }

        if ($platform === 'facebook') {
            // A standalone regex here would share the blind spot for reserved
            // path segments (pages/people/…) that G4-4 fixed.
            $parsed = app(FacebookNormalizer::class)($url);

            return $parsed['username'] ?? '';
        }

        $patterns = [
            'tiktok' => '~tiktok\.com/@?([A-Za-z0-9._]+)~i',
            'x' => '~(?:twitter|x)\.com/([A-Za-z0-9_]+)~i',
            'linkedin' => '~linkedin\.com/(?:in|company)/([A-Za-z0-9-]+)~i',
            // Instagram's connect is bespoke (no UrlConnect to defer to), so
            // its profile shape lives here. Reserved segments guarded below.
            'instagram' => '~instagram\.com/([A-Za-z0-9._]+)~i',
        ];
        if (isset($patterns[$platform]) && preg_match($patterns[$platform], $url, $m)) {
            // The catalog's own Instagram exclusion list (Instagram.php),
            // verbatim, plus profile.php/share/tv — the plan-03 critic
            // caught this drifting (developer/about/legal/directory leaked
            // as fake usernames). Superset of the catalog is safe; a subset
            // is the bug class this line exists to close.
            $reserved = [
                'profile.php', 'p', 'reel', 'reels', 'stories', 'explore',
                'accounts', 'developer', 'about', 'legal', 'directory',
                'share', 'tv',
            ];

            return in_array(strtolower($m[1]), $reserved, true) ? '' : $m[1];
        }

        return '';
    }

    /**
     * The social-category equivalent of resolveBookingLink() — existing-row
     * lookup, soft-delete tombstone guard, same-url no-op, conflict finding.
     *
     * Extracted from InstagramAutoSync::handleClassifiedLink()'s social branch
     * when that method was deleted (Phase 7). LinkRouter's first cut replaced all
     * of it with a bare write(), which OVERWROTE a social connection the user had
     * set by hand and never produced a conflict finding — the Swap surface the
     * synced modal is built around.
     *
     * No lock, deliberately: each social platform is its own row, already
     * serialized by idx_platform_connections_unique_active. Booking is the
     * exception because its XOR invariant spans three platforms — see
     * withBookingXorLock()'s docblock for the contrast.
     *
     * @param  array{platform:string,resourceId:string,payload:array<string,mixed>}  $write
     * @param  array{platform:string,category:string,label:string}  $classified
     * @return array{findings:list<array<string,mixed>>,unmatched:list<array<string,mixed>>,consumed:bool}
     */
    protected function resolveSocialLink(string $userId, string $platform, string $url, array $write, array $classified): array
    {
        $existing = IntegrationConnection::query()
            ->where('user_id', $userId)->where('platform', $platform)
            ->first();

        if ($existing === null) {
            if ($this->wasDisconnected($userId, 'platform', $platform)) {
                return ['findings' => [], 'unmatched' => [['url' => $url, 'label' => $classified['label']]], 'consumed' => true];
            }

            $this->write($userId, $write['platform'], $write['resourceId'], $write['payload']);

            return [
                'findings' => [$this->seededFinding($write['platform'], $write['resourceId'], $classified['category'], $classified['label'], $url)],
                'unmatched' => [],
                'consumed' => true,
            ];
        }

        $existingUrl = CardPayload::fromArray($existing->payload)->url();
        if ($existingUrl !== null && $this->sameUrl($existingUrl, $url)) {
            return ['findings' => [], 'unmatched' => [], 'consumed' => true]; // already synced with the same link
        }

        return [
            'findings' => [$this->conflictFinding($write['platform'], $write['resourceId'], $classified['category'], $classified['label'], $url, [
                'remove' => [$write['platform']],
                'write' => $write,
            ])],
            'unmatched' => [],
            'consumed' => true,
        ];
    }

    /**
     * True when the user explicitly disconnected this slot before
     * (ManagesIntegrationConnection::forgetConnection() soft-deletes), so a
     * "no live row" answer is a TOMBSTONE, not a blank slate. The default
     * Eloquent scope hides it, and write() is an updateOrCreate that will not
     * see it either — the unique index is partial on `deleted_at IS NULL`, so
     * the insert SUCCEEDS and resurrects a connection the user chose to remove.
     *
     * $column mirrors whatever axis the caller's LIVE lookup used, so both
     * questions are asked about the same rows: 'platform' for the per-platform
     * social/booking rows, 'routing_class' for the reservations family slot,
     * 'surface_key' for one ordering brand.
     *
     * @param  'platform'|'surface_key'|'routing_class'  $column
     */
    protected function wasDisconnected(string $userId, string $column, string $value): bool
    {
        return IntegrationConnection::onlyTrashed()
            ->where('user_id', $userId)
            ->where($column, $value)
            ->exists();
    }

    protected function sameUrl(string $a, string $b): bool
    {
        return strtolower(rtrim(trim($a), '/')) === strtolower(rtrim(trim($b), '/'));
    }
}
