<?php

namespace App\Services\Platforms\Concerns;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\Normalizers\FacebookNormalizer;
use App\Services\Platforms\Payloads\CardPayload;
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
        // controllers themselves would 403. Mirrors the seed-time gate; a
        // blocked apply reports true ("nothing to apply") rather than 423 —
        // the finding is obsolete, not contended.
        if ($looksBooking || $looksReservations) {
            $user = User::find($userId);
            $capabilities = $user ? AccountCapabilities::for($user) : null;
            if ($capabilities === null
                || ($looksBooking && ! $capabilities->can_use_booking)
                || ($looksReservations && ! $capabilities->can_use_reservations)) {
                return true;
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
            // controller (e.g. the opentable delete below racing
            // ReservationsController::forget()). No real producer emits a
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
            || array_intersect((array) ($apply['remove'] ?? []), self::RESERVATIONS_SLOT_PLATFORMS) !== [];
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

    // NB there is deliberately NO BOOKING_PLATFORMS constant on this trait.
    // Phase 2.5 originally added one (protected, [fresha, square, booking]) and
    // it made GoogleBusinessAutoSync unloadable: that class already declares
    // `private const BOOKING_PLATFORMS` with the SAME values, and PHP compares
    // a trait/class constant clash by DEFINITION — visibility included — not by
    // value, so private-vs-protected alone is "incompatible" and fatals at class
    // composition. resolveBookingLink() below uses BOOKING_SLOT_PLATFORMS, which
    // is already exactly the XOR set {fresha, square, booking} it needs.

    /** @return array{platform:string, resourceId:string, payload:array<string,mixed>} */
    protected function resolveWrite(string $platform, string $url): array
    {
        if ($platform === Platform::Fresha->value) {
            return ['platform' => $platform, 'resourceId' => $platform, 'payload' => [
                'url' => $url, 'selection' => null, 'source' => 'instagram',
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
        $conflictingBooking = IntegrationConnection::query()
            ->where('user_id', $userId)
            ->whereIn('platform', self::BOOKING_SLOT_PLATFORMS)
            ->where('platform', '!=', $platform)
            ->first();

        if ($conflictingBooking !== null) {
            return [
                'findings' => [$this->conflictFinding($write['platform'], $write['resourceId'], $classified['category'], $classified['label'], $url, [
                    'remove' => self::BOOKING_SLOT_PLATFORMS,
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
            $wasDisconnected = IntegrationConnection::onlyTrashed()
                ->where('user_id', $userId)->where('platform', $platform)
                ->exists();

            if ($wasDisconnected) {
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
        ];
        if (isset($patterns[$platform]) && preg_match($patterns[$platform], $url, $m)) {
            return strtolower($m[1]) === 'profile.php' ? '' : $m[1];
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
            // A soft-deleted row means the user explicitly disconnected this
            // platform before (ManagesIntegrationConnection::forgetConnection()
            // soft-deletes on disconnect) — a tombstone, not "never connected".
            // The default Eloquent scope hides it, so treating "no live row" as a
            // blank slate would silently resurrect a connection the user chose to
            // remove. Route the link to unmatched instead (still addable by hand).
            $wasDisconnected = IntegrationConnection::onlyTrashed()
                ->where('user_id', $userId)->where('platform', $platform)
                ->exists();

            if ($wasDisconnected) {
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

    protected function sameUrl(string $a, string $b): bool
    {
        return strtolower(rtrim(trim($a), '/')) === strtolower(rtrim(trim($b), '/'));
    }
}
