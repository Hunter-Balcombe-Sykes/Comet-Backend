<?php

namespace App\Services\Platforms\Concerns;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\Registry\Platform;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
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
        Platform::Booking->value,
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
        Platform::Reservations->value,
    ];

    /** @return array<string,mixed> */
    private function seededFinding(string $platform, string $resourceId, string $category, string $label, ?string $foundUrl): array
    {
        return [
            'platform' => $platform,
            'resourceId' => $resourceId,
            'category' => $category,
            'label' => $label,
            'foundUrl' => $foundUrl,
            'outcome' => 'seeded',
            'apply' => null,
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
        IntegrationConnection::updateOrCreate(
            ['user_id' => $userId, 'platform' => $platform, 'resource_id' => $resourceId],
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
     * the slot mid-swap and install a second live provider. Every other
     * finding (social, ordering, workplace) is unaffected — still lock-free,
     * byte-identical to before.
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
     */
    public function applyFinding(string $userId, array $finding): bool
    {
        $apply = $finding['apply'] ?? null;
        if (! is_array($apply)) {
            return true;
        }

        if ($this->isBookingSlotApply($finding, $apply)) {
            // Booking slot: remove + write are ONE span under the same key
            // every other booking writer takes. runApply()'s
            // applyFindingHandled() step is a proven no-op here (see the
            // escape hatch in isBookingSlotApply()) — no vendor call, no
            // dispatch, DB only.
            return $this->withBookingXorLock($userId, function () use ($userId, $apply): bool {
                $this->runApply($userId, $apply);

                return true;
            }, false); // $default=false => "contended, nothing applied"
        }

        if ($this->isReservationsSlotApply($finding, $apply)) {
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
     * Trap 4 / escape hatch: `apply.instagram` is produced in exactly one
     * place — GoogleBusinessAutoSync's Instagram/social recipe — and that
     * recipe is ALWAYS category:'social', never 'booking'. So a finding that
     * looks booking-eligible AND carries apply.instagram should be
     * structurally unreachable; report() makes a future hybrid recipe a loud
     * canary instead of silently holding a 10s lock across
     * applyFindingHandled()'s ~110s inline Apify scrape.
     *
     * @param  array<string,mixed>  $finding
     * @param  array<string,mixed>  $apply
     */
    private function isBookingSlotApply(array $finding, array $apply): bool
    {
        $looksBooking = ($finding['category'] ?? null) === 'booking'
            || array_intersect((array) ($apply['remove'] ?? []), self::BOOKING_SLOT_PLATFORMS) !== [];

        if (! $looksBooking) {
            return false;
        }

        if (is_array($apply['instagram'] ?? null)) {
            report(new \RuntimeException(
                'applyFinding: apply.instagram co-occurred with a booking-slot recipe (finding category='.
                var_export($finding['category'] ?? null, true).
                ') — should be structurally impossible; bailing to the unlocked path.'
            ));

            return false;
        }

        return true;
    }

    /**
     * Reservations counterpart to isBookingSlotApply() — same shape, same
     * escape hatch, RESERVATIONS_SLOT_PLATFORMS instead of the booking list.
     *
     * @param  array<string,mixed>  $finding
     * @param  array<string,mixed>  $apply
     */
    private function isReservationsSlotApply(array $finding, array $apply): bool
    {
        $looksReservations = ($finding['category'] ?? null) === 'reservations'
            || array_intersect((array) ($apply['remove'] ?? []), self::RESERVATIONS_SLOT_PLATFORMS) !== [];

        if (! $looksReservations) {
            return false;
        }

        if (is_array($apply['instagram'] ?? null)) {
            report(new \RuntimeException(
                'applyFinding: apply.instagram co-occurred with a reservations-slot recipe (finding category='.
                var_export($finding['category'] ?? null, true).
                ') — should be structurally impossible; bailing to the unlocked path.'
            ));

            return false;
        }

        return true;
    }

    /**
     * The remove-then-write body itself — a straight extraction of
     * applyFinding()'s pre-U1 implementation, unchanged. Called either
     * directly (non-slot findings, unlocked) or from inside the relevant
     * XOR lock's closure (booking/reservations findings).
     *
     * @param  array<string,mixed>  $apply
     */
    private function runApply(string $userId, array $apply): void
    {
        foreach ((array) ($apply['remove'] ?? []) as $platform) {
            if (! is_string($platform)) {
                continue;
            }
            IntegrationConnection::query()
                ->where('user_id', $userId)->where('platform', $platform)
                ->get()->each->delete();
        }

        if ($this->applyFindingHandled($userId, $apply)) {
            return;
        }

        if (is_array($apply['write'] ?? null)) {
            $w = $apply['write'];
            $this->write($userId, (string) $w['platform'], (string) $w['resourceId'], (array) $w['payload']);
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
}
