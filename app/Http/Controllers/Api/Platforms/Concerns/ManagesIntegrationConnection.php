<?php

namespace App\Http\Controllers\Api\Platforms\Concerns;

use App\Catalog\LegacyPlatformMap;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Routing\IriCanonicalizer;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\FeatureAvailability\FeatureAvailability;
use App\Services\Notifications\Dispatchers\IntegrationNotifier;
use App\Services\Platforms\BookingProviders;
use App\Services\Platforms\Payloads\CardPayload;
use App\Services\Platforms\Payloads\LinkPayload;
use App\Services\Site\AdvisoryLockTimeoutException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

// Per-user platform-connection storage — the pilot replacement for
// ManagesPlatformSelection's single global cache key. Each controller declares
// its platform(); the selection blob the controller already builds is stored
// verbatim in the row's `payload`, keyed by (user, platform, resource_id).
//
// Single-selection platforms (Eventbrite, YouTube, Apple, Stan, Fresha, TikTok,
// Facebook) keep one row per user under the default resource id. Multi-resource
// platforms (Shopify brands) pass an explicit resource id.
//
// Writes go through the model, so IntegrationConnectionObserver fires and purges
// the user's sitepage edge cache automatically.
trait ManagesIntegrationConnection
{
    // The platform key stored in site.platform_connections.platform (must match
    // the migration CHECK constraint).
    abstract protected function platform(): string;

    /**
     * Convergence Phase 6: non-null when this controller's family spans several
     * BRAND surfaces rather than one platform key, and its reads must therefore
     * scope on `routing_class` instead.
     *
     * Ordering is the case that forced it — `online-ordering` was one pseudo
     * platform holding every ordering link; now each link carries its own brand
     * surface (`uber_eats.order`, `doordash.order`, …) and a single-slug scope
     * sees none of them. routing_class travels with surface_key on every row by
     * construction (IntegrationConnection::booted stamps it), so a brand added
     * later is covered without anyone remembering to widen a list — the same
     * argument the retired reservations family clear made before it.
     *
     * platform() is still what the LOCK and FeatureAvailability key on: those
     * are per-FAMILY concerns, and the family key did not change.
     */
    protected function routingClass(): ?string
    {
        return null;
    }

    // Single-selection platforms store one row per user under this resource id.
    /**
     * The LEGACY SINGLETON identity: the platform slug standing in for "the
     * one account on this platform". It is not the account's identity, which
     * is why App\Routing\ConnectionIdentity has to translate it — see #R4,
     * where the build's 'instagram' row and the same handle harvested from a
     * Linktree read as two accounts and became two connections.
     *
     * KNOWN GAP, deliberately out of scope for #R4: the translation runs in
     * the routing lane only. A connect through THIS trait still writes the
     * marker without checking whether a routed row for the same account is
     * already present.
     */
    protected function defaultResourceId(): string
    {
        return $this->platform();
    }

    /**
     * The resource id a read or write addresses when the caller named none.
     *
     * Default: the platform slug (defaultResourceId). A controller whose
     * platform can ALSO be placed by the routing lane — which keys the row by
     * the resolved identifier, not the slug — overrides this to converge on the
     * user's existing row so both lanes read and write the same one.
     */
    protected function resolveResourceId(User $user, ?string $resourceId): string
    {
        return $resourceId ?? $this->defaultResourceId();
    }

    /** All of the user's active connections for this platform (or routing class), ordered. */
    protected function connectionsFor(User $user)
    {
        return $this->scopeToFamily($user->integrationConnections())
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Scope a connection query to this controller's family — one platform slug,
     * or the whole routing class when routingClass() is set.
     *
     * @template TQuery of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    private function scopeToFamily($query)
    {
        $class = $this->routingClass();

        return $class === null
            ? $query->where('platform', $this->platform())
            : $query->where('routing_class', $class);
    }

    protected function connectionFor(User $user, ?string $resourceId = null): ?IntegrationConnection
    {
        $connection = $this->scopeToFamily($user->integrationConnections())
            ->where('resource_id', $this->resolveResourceId($user, $resourceId))
            ->first();

        // Gate read access — null rows are left as-is (preserves the "not found"
        // contract; no throw on absent connections). A found row is checked against
        // the policy's view() ability (pure ownership, no pending-deletion guard).
        if ($connection) {
            $this->authorizeForUser($user, 'view', $connection);
        }

        return $connection;
    }

    /**
     * Upsert the selection payload for one resource; returns the row.
     *
     * Authorization: resolves whether this is a create (new row) or update
     * (existing row) before the upsert so the correct ability fires. Both
     * abilities run denyIfPendingDeletion; update additionally enforces ownership.
     *
     * $canonicalKey stamps the normalized account-identity column (FOUND-14) —
     * only the account path (writeAccountConnection) passes it, at connect time.
     * Omitted (null) on every other call site (tile refreshes,
     * single-selection writes) so those updates never clobber an already-stored
     * canonical_key back to NULL.
     *
     * $resourceKind stamps the resource_kind discriminator column (FOUND-34) —
     * 'event' / 'link' for standalone rows, omitted (null) for account rows —
     * mirroring canonical_key's stamp-only-if-not-null contract so an already-stored
     * value is never clobbered back to NULL by an unrelated update.
     */
    /**
     * OV-A persistence net: refuse any write to a platform staff have disabled for
     * this user (global or segment rule). 503 matches the FeatureGate convention.
     * Also stops a reconnect/refresh from resurrecting a taken-down connection.
     */
    private function assertPlatformAvailable(User $user): void
    {
        if (! FeatureAvailability::for($user)->allows('integration.'.$this->platform())) {
            abort(503, 'This integration is currently unavailable.');
        }
    }

    /**
     * Shared upsert body for every connection-write path (writeConnection,
     * writePendingLinkCard, writeAccountConnection): resolves create-vs-update
     * policy, asserts platform availability, then upserts. $canonicalKey /
     * $resourceKind are stamped only when the caller resolved one — an
     * omitted value must leave an already-stored column untouched.
     *
     * $mergePayload is the pending-write contract (writeAccountConnection's
     * $pending flag, Unit 11 W5 / LIFE-13..20): when true and a row already
     * exists, $values['payload'] is merged ONTO the existing stored payload
     * rather than replacing it — `[...($existing->payload ?? []), ...$new]`.
     * Load-bearing for three distinct bugs on a reconnect:
     *   1. UX — the card stays rendered (an identity stub at worst) instead
     *      of blanking while ConnectFetchJob's fetch is still queued.
     *   2. The Bandcamp 304 trap — BandcampFetch throws
     *      FetchNotModifiedException when auto_sync_latest is off; that's
     *      only correct if the OLD payload survived the pending write.
     *   3. The conditional-request trap — OEmbedFetch/YoutubeMusicFetch send
     *      If-None-Match from the stored refresh_etag; a 304 on reconnect is
     *      likewise only safe if the payload wasn't blanked first.
     */
    private function upsertConnection(
        User $user,
        array $values,
        ?string $resourceId,
        bool $mergePayload = false,
        ?string $canonicalKey = null,
        ?string $resourceKind = null,
    ): IntegrationConnection {
        $this->assertPlatformAvailable($user);

        // Resolve once so the skeleton, the lookup and the upsert key agree —
        // a controller that converges on an existing row (resolveResourceId)
        // must not create a second one under the default slug.
        $resourceId = $this->resolveResourceId($user, $resourceId);

        // Determine create vs. update before the upsert so the correct ability fires.
        $existing = $this->connectionFor($user, $resourceId);
        if ($existing) {
            // connectionFor already ran 'view' (ownership check); run 'update' for
            // the pending-deletion guard on top of ownership.
            $this->authorizeForUser($user, 'update', $existing);

            if ($mergePayload && array_key_exists('payload', $values)) {
                $values['payload'] = [...($existing->payload ?? []), ...$values['payload']];
            }
        } else {
            // No row yet — gate with a skeleton so the policy can check ownership
            // and pending-deletion without a real DB row.
            $skeleton = new IntegrationConnection([
                'user_id' => $user->id,
                'platform' => $this->platform(),
                'resource_id' => $resourceId,
            ]);
            $this->authorizeForUser($user, 'create', $skeleton);
        }

        if ($canonicalKey !== null) {
            $values['canonical_key'] = $canonicalKey;
        }
        if ($resourceKind !== null) {
            $values['resource_kind'] = $resourceKind;
        }

        $connection = IntegrationConnection::updateOrCreate(
            [
                'user_id' => $user->id,
                'platform' => $this->platform(),
                'resource_id' => $resourceId,
            ],
            $values,
        );

        // Bell notice on a genuine connect. wasRecentlyCreated is checked HERE and
        // not inside the notifier because it is a per-INSTANCE flag, true only on
        // the object that performed the insert — ConnectFetchJob's freshly-loaded
        // row always reports false, which is why that path calls the notifier
        // itself. The status and resource-kind guards live in the notifier, so a
        // 'pending' deferred write falls through silently here.
        if ($connection->wasRecentlyCreated) {
            app(IntegrationNotifier::class)->connected($connection);
        }

        return $connection;
    }

    /**
     * $pending (Unit 11 W6, deferred connect — mirrors writeAccountConnection's
     * parameter of the same name): true writes a 'pending' placeholder instead
     * of 'ok', MERGED over any existing payload rather than replacing it. This
     * is the single-selection counterpart to writeAccountConnection's pending
     * path — closes the gap W5 left open for strava, whose
     * multiAccount() is false so GenericPlatformController::connect() routes
     * them here, not through writeAccountConnection(). Same three bugs the
     * merge guards against (reconnect blanking the card, the Bandcamp 304
     * trap, the conditional-request trap) apply identically here — see
     * upsertConnection()'s docblock.
     */
    protected function writeConnection(User $user, array $payload, ?string $resourceId = null, ?string $canonicalKey = null, ?string $resourceKind = null, bool $pending = false): IntegrationConnection
    {
        return $this->upsertConnection($user, [
            'payload' => $payload,
            'is_active' => true,
            'last_refreshed_at' => $pending ? null : now(),
            'last_refresh_status' => $pending ? 'pending' : 'ok',
            'last_refresh_error' => null,
            'consecutive_failures' => 0,
        ], $resourceId, mergePayload: $pending, canonicalKey: $canonicalKey, resourceKind: $resourceKind);
    }

    /**
     * Async-connect variant of writeConnection: writes a usable MINIMAL card
     * immediately with status 'pending', so the connect action can return 202
     * before the slow enrichment fetch runs (JOB-1). EnrichLinkCardJob flips the
     * status to 'ok' once it has upgraded the display fields. Policy-gated exactly
     * like writeConnection (create vs update ability resolved before the upsert).
     *
     * $resourceKind stamps resource_kind only when non-null — same contract as
     * writeConnection (FOUND-34). Unlike writeAccountConnection's $pending path
     * below, this REPLACES the payload on a reconnect (unchanged behaviour) —
     * link cards have no merge-worthy prior content the way an account row does.
     */
    protected function writePendingLinkCard(User $user, array $payload, ?string $resourceId = null, ?string $resourceKind = null): IntegrationConnection
    {
        return $this->upsertConnection($user, [
            'payload' => $payload,
            'is_active' => true,
            'last_refreshed_at' => null,
            'last_refresh_status' => 'pending',
            'last_refresh_error' => null,
            'consecutive_failures' => 0,
        ], $resourceId, resourceKind: $resourceKind);
    }

    /**
     * Convergence Phase 6: the pending-link-card write for a family whose rows
     * carry BRAND surfaces, so the caller — not platform() — names the surface.
     *
     * upsertConnection() cannot serve this: it keys its updateOrCreate on
     * platform(), which for these families is now only a lock key, and matching
     * a surface key against the generated `platform` column can never hit
     * (BuildsAutoSyncFindings::write documents the same trap). Everything else is
     * kept: FeatureAvailability, the create-vs-update ability, and the connect
     * bell on a genuine insert.
     *
     * @param  array<string,mixed>  $payload
     */
    protected function writeBrandCard(User $user, string $surfaceKey, string $resourceId, array $payload): IntegrationConnection
    {
        $this->assertPlatformAvailable($user);

        $existing = $user->integrationConnections()
            ->where('surface_key', $surfaceKey)
            ->where('resource_id', $resourceId)
            ->first();

        if ($existing) {
            $this->authorizeForUser($user, 'update', $existing);
        } else {
            $this->authorizeForUser($user, 'create', new IntegrationConnection([
                'user_id' => $user->id,
                'platform' => $surfaceKey,
                'resource_id' => $resourceId,
            ]));
        }

        $connection = IntegrationConnection::updateOrCreate(
            ['user_id' => $user->id, 'surface_key' => $surfaceKey, 'resource_id' => $resourceId],
            [
                'payload' => $payload,
                'is_active' => true,
                'last_refreshed_at' => null,
                'last_refresh_status' => 'pending',
                'last_refresh_error' => null,
                'consecutive_failures' => 0,
            ],
        );

        if ($connection->wasRecentlyCreated) {
            app(IntegrationNotifier::class)->connected($connection);
        }

        return $connection;
    }

    /**
     * The single-slot resource id for a brand — its brand prefix. Byte-identical
     * to LinkRouter::brandResourceId(), and it has to be: the two write paths
     * address the same slot, and a different shape here would let a link routed
     * from a scrape and the same link pasted into the dashboard occupy two rows.
     */
    protected function brandResourceId(string $surfaceKey): string
    {
        return LegacyPlatformMap::legacyFor($surfaceKey);
    }

    /**
     * The connection already holding a single-slot brand surface, when the
     * incoming link is a DIFFERENT one — otherwise null.
     *
     * Owner ruling 2026-08-19. A brand card's resource_id is the brand prefix,
     * so `writeBrandCard`'s updateOrCreate SILENTLY replaced the incumbent: a
     * second Uber Eats store overwrote the first with no confirmation, and the
     * legacy ordering controller's answer — quietly filing it as a links-pool
     * item — was worse. Neither is a decision the owner made. The connect now
     * refuses (422 `slot_taken`) and the dashboard offers Swap, which re-posts
     * with `replace=true`.
     *
     * Same link = same slot: re-connecting to fix a payload, or re-pasting the
     * URL you already have, must keep working, so the comparison is on the
     * canonical IRI rather than the raw string (a trailing slash or an added
     * `?utm_source` is not a second store).
     *
     * @param  array<string,mixed>  $incoming  the connect strategy's resolved selection
     */
    protected function slotIncumbent(User $user, string $surfaceKey, string $resourceId, array $incoming): ?IntegrationConnection
    {
        $incomingUrl = LinkPayload::fromArray($incoming)->url ?? '';
        if (trim($incomingUrl) === '') {
            return null;
        }

        // EVERY live row on the surface, not just the brand-slug rid: the
        // router seeds these slots with url-derived resource ids
        // ('order-<hash>'), and a guard that only saw its own rid let a
        // manual connect write a silent second Uber Eats beside a
        // router-seeded incumbent (found live on dev, 2026-08-19).
        $rows = $user->integrationConnections()
            ->where('surface_key', $surfaceKey)
            ->get();

        foreach ($rows as $existing) {
            $existingUrl = CardPayload::fromArray($existing->payload)->url() ?? '';
            if (trim($existingUrl) === '') {
                continue; // nothing identifiable to keep — not an incumbent
            }
            if (! $this->sameLink($existingUrl, $incomingUrl)) {
                return $existing;
            }
        }

        return null;
    }

    /** Canonical-IRI equality, falling back to a trimmed compare when either side won't canonicalise. */
    private function sameLink(string $a, string $b): bool
    {
        $canonicaliser = app(IriCanonicalizer::class);
        $left = $canonicaliser->canonicalize($a)->canonical;
        $right = $canonicaliser->canonicalize($b)->canonical;

        if ($left !== null && $right !== null) {
            return $left === $right;
        }

        return strtolower(rtrim(trim($a), '/')) === strtolower(rtrim(trim($b), '/'));
    }

    /**
     * Poll response for an async link-card enrichment (JOB-1), mirroring the
     * Instagram connectStatus shape: pending → ready(+data) → failed. 404 when the
     * resource doesn't exist for the caller (never 403 — no existence leak).
     */
    protected function linkCardStatusResponse(User $user, string $resourceId, callable $whenReady): JsonResponse
    {
        $connection = $this->connectionFor($user, $resourceId);
        if (! $connection) {
            return $this->error('Link not found.', 404);
        }

        return match ($connection->last_refresh_status) {
            'pending' => $this->success(['status' => 'pending']),
            'ok' => $this->success(['status' => 'ready', ...$whenReady($connection)]),
            default => $this->success(['status' => 'failed']),
        };
    }

    /** Read one resource's selection payload (null when nothing is stored). */
    protected function readConnection(User $user, ?string $resourceId = null): ?array
    {
        return $this->connectionFor($user, $resourceId)?->payload;
    }

    /**
     * True when the user already has a non-deleted connection under a different,
     * mutually-exclusive platform. Booking providers (Fresha / Square) are XOR —
     * only one may be connected at a time. Enforced here as defence-in-depth; the
     * dashboard also disables the conflicting card.
     */
    protected function hasConflictingConnection(User $user, string $otherPlatform): bool
    {
        return $user->integrationConnections()
            ->where('platform', $otherPlatform)
            ->exists();
    }

    /**
     * The 409 for "another booking provider already holds this user's slot",
     * or null when the slot is free.
     *
     * Resolves the rival from BookingProviders::others($this->platform())
     * rather than naming a hardcoded sibling, so a third provider joins the
     * XOR by joining that list — not by someone finding and editing all six
     * call sites, where missing one fails OPEN (two booking providers live at
     * once). Callers must ALREADY hold bookingXorLock: this is the check half
     * of a check-then-write and races outside it (U1, 2026-07-25).
     */
    protected function bookingProviderConflict(User $user): ?JsonResponse
    {
        // Inert for the seven non-booking controllers that also take this
        // trait: others('shop') is the WHOLE family, so without this guard a
        // ShopController calling it would 409 against both providers.
        if (! BookingProviders::includes($this->platform())) {
            return null;
        }

        foreach (BookingProviders::others($this->platform()) as $rival) {
            if ($this->hasConflictingConnection($user, $rival)) {
                return $this->error(sprintf(
                    'Disconnect %s before connecting %s — only one booking provider can be active at a time.',
                    BookingProviders::label($rival),
                    BookingProviders::label($this->platform()),
                ), 409);
            }
        }

        return null;
    }

    /**
     * Soft-delete one resource (or the default single selection).
     *
     * Authorization: only runs 'delete' when a row exists (same null-preserving
     * pattern as connectionFor). The policy's delete() delegates to update(),
     * so both ownership and pending-deletion are checked.
     */
    protected function forgetConnection(User $user, ?string $resourceId = null): void
    {
        // connectionFor already ran 'view'; re-gate with 'delete' for the write-side check.
        $connection = $this->connectionFor($user, $resourceId);
        if ($connection) {
            $this->authorizeForUser($user, 'delete', $connection);
            $connection->delete();
        }
    }

    /**
     * Serialise a read→mutate→write payload cycle for one user behind a per-user,
     * per-platform Redis lock. Prevents concurrent dashboard tabs / retries from
     * clobbering each other's JSONB writes (last-write-wins data loss).
     *
     * Returns the callback's JsonResponse, or a 423 (Locked) when another mutation
     * holds the lock past the block timeout so the dashboard can retry. The closure
     * form of block() releases the lock automatically on return or throw.
     *
     * The key is PLATFORM-WIDE only — CacheKeyGenerator::platformConnectionLock()
     * no longer accepts a per-account suffix (removed 2026-07-21: two writers
     * that built different suffixes for the same platform+user silently failed
     * to exclude each other, a lost-update bug). Do not reintroduce one here.
     *
     * U2: also catches AdvisoryLockTimeoutException — FreshaController's
     * synchronous connect()/saveSelection() bodies call
     * FreshaServiceProjector::sync() from inside this closure, and that Postgres
     * advisory lock (services:{user_id}) now has a tighter, explicit 5s bound
     * and a typed exception, replacing the raw, uncaught SQLSTATE 55P03 a
     * timeout on the pre-existing (but unreliable — lost on reconnect)
     * session-level `SET lock_timeout` would otherwise have produced. Same
     * 423 either way: the client can't tell which lock contended.
     *
     * Whole-branch review fix: $ttlSeconds defaults to 10 (unchanged) but can
     * be raised for a caller whose closure holds the lock across FreshaServiceProjector::
     * sync() — the same rationale withCrossPlatformLock's docblock already
     * gives for its own $ttlSeconds param (a projection that itself waits up
     * to 5s on the services advisory lock before doing real work can
     * plausibly exceed 10s), applied one level down: this lock is the INNER
     * one FreshaController::connect()'s storewide branch and saveSelection()
     * hold WHILE running that same sync() call, under the outer 30s
     * bookingXorLock. Left at 10 expiring mid-projection would let
     * ConnectFetchJob / ScheduledRefresh / another saveSelection — every one
     * of which takes ONLY this 'fresha' platform key — in behind it, reopening
     * PWL-5. Checked every caller of this method (OnlineOrderingController,
     * FreshaController::connectDeferred()/setServiceVisibility()/forget() [via
     * withCrossPlatformLock, not this method], GoogleBusinessController,
     * CustomLinksController, EventsPlatformController, ShopController,
     * GenericPlatformController, SkoolController, InstagramController,
     * AppleController): none of the others ever calls
     * FreshaServiceProjector::sync() or waits on any advisory lock inside its
     * closure — every one is a fast DB read/write — so they all stay on the
     * default. Do not raise the default itself, same reasoning as
     * withCrossPlatformLock's docblock: a longer TTL on a path that doesn't
     * need it only lengthens the window a crashed process holds the lock.
     *
     * Note: assumes the using class extends ApiController (for error()).
     */
    protected function withConnectionLock(User $user, callable $callback, int $ttlSeconds = 10): JsonResponse
    {
        $key = CacheKeyGenerator::platformConnectionLock($this->platform(), $user->id);

        try {
            return Cache::lock($key, $ttlSeconds)->block(5, $callback);
        } catch (LockTimeoutException|AdvisoryLockTimeoutException) {
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }
    }

    /**
     * Like withConnectionLock() but for a caller-supplied cross-platform lock
     * key (e.g. CacheKeyGenerator::bookingXorLock / reservationsXorLock) — used
     * by the single-slot booking/reservations families whose clear+write spans
     * multiple platform rows and so cannot use the per-platform key. Same
     * block(5) / 423-on-timeout contract; TTL defaults to 10s but can be
     * raised for a caller whose closure holds the lock across genuinely
     * slower work (see $ttlSeconds below).
     *
     * U1 review fix: the 10s default is fine for a fast read→mutate→write
     * span, but FreshaController::connect()'s storewide branch runs
     * FreshaServiceProjector::sync() (a Postgres-bound projection, itself
     * waiting up to 5s on an advisory lock before doing real work) INSIDE
     * this lock — a span that can plausibly exceed 10s under load. A lock
     * that expires mid-write is worse than no lock: a concurrent
     * SquareController::connect would then acquire it and produce the exact
     * "both booking providers active" state this lock exists to prevent. That
     * call site passes 30 (matching FreshaConnectFetch.php's identical
     * projection under the raw Cache::lock it uses instead of this helper,
     * CA-W7). Every OTHER caller of this method — SquareController::connect,
     * FreshaController::connectDeferred()/forget(), BookingController,
     * ReservationsController — only does a fast DB read/write inside its
     * closure (never a scrape, sync(), or advisory-lock wait), so they stay on
     * the default. Do not raise the default itself: a longer TTL on a path
     * that doesn't need it only lengthens the window a crashed process holds
     * the lock.
     *
     * Lock-ordering rule (U1): a cross-platform lock from this method is
     * always acquired OUTER to platformConnectionLock, never the reverse.
     * FreshaController::connect()/connectDeferred() are the only call sites
     * that hold both at once (bookingXorLock outer, then withConnectionLock
     * inner) — every other bookingXorLock/reservationsXorLock holder
     * (SquareController::connect, FreshaController::forget,
     * BookingController, the auto-sync seeders, and
     * BuildsAutoSyncFindings::applyFinding) holds it ALONE. That keeps the
     * wait-for graph acyclic: nothing anywhere holds a platform lock and then
     * requests a cross-platform one. Reversing the order at the one nesting
     * site would create exactly that cycle against a concurrent
     * SquareController::connect — do not add one.
     */
    protected function withCrossPlatformLock(string $lockKey, callable $callback, int $ttlSeconds = 10): JsonResponse
    {
        try {
            return Cache::lock($lockKey, $ttlSeconds)->block(5, $callback);
        } catch (LockTimeoutException) {
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }
    }

    // ── Deliberately NOT locked (PWL-16 register) ────────────────────────
    // The 2026-07-21 platform write-path locking audit reviewed every writer to
    // site.platform_connections and DELIBERATELY left the following unlocked —
    // recorded here (at the locking helpers' own site) so a future sweep does
    // not re-flag them as missing-lock bugs. Each has no plausible concurrent
    // writer racing the SAME row, so a lock would add contention for no safety.
    //   • Link-only socials (facebook / tiktok / x / linkedin / threads /
    //     reddit): a single stored URL, never refreshed by a job and with no
    //     sibling writer — nothing to race.
    //   • skool REMOVED from the list above by CA-W4: config('partna.connect.
    //     deferred') naming it gives it a real sibling writer (ConnectFetchJob),
    //     so the "nothing to race" premise no longer holds for that path. Both
    //     the deferred pending write (SkoolController::connectDeferred, via
    //     withConnectionLock) and the job's own completion write now take the
    //     same per-user platform lock as every other deferred platform. The
    //     synchronous (flag-off) write stays exactly as unlocked/safe as before
    //     — still no sibling writer on that path.
    //   • square REMOVED from the list above by U1 (2026-07-25):
    //     SquareController::connect() had no lock at all, and Fresha's took
    //     only the 'fresha' platform key, so "at most one booking provider"
    //     rested on two unsynchronised check-then-write sequences — a real
    //     sibling writer (a concurrent Fresha connect), just not one a
    //     per-platform lock can see. Both connects now take the shared
    //     bookingXorLock before touching a square row; so does
    //     BuildsAutoSyncFindings::applyFinding's booking-slot branch. No
    //     per-platform 'square' lock was added — bookingXorLock alone already
    //     serialises every writer of that row. SquareController::forget()
    //     itself STAYS unlocked (U1 Q-A): a delete can never create a second
    //     booking provider, so it cannot violate the XOR invariant — the
    //     worst case is an idempotent double soft-delete racing
    //     BookingController::clearBooking(), or a concurrent Fresha connect
    //     seeing a stale 409. FreshaController::forget() IS locked, but for
    //     an unrelated reason (FreshaServiceProjector::sync()'s resurrection
    //     of deleted_origin='sync' rows), which Square's forget has no
    //     analogue of.
    //   • DisplaySettingsController::update(): writes only the display_settings
    //     column via Eloquent dirty-tracking; the only race is two concurrent
    //     display-settings PATCHes (not a connection-payload clobber) — low.
    //   • ConnectFetchJob::markTerminal/markOk + PlatformRefresher bookkeeping:
    //     touch only status columns (last_refresh_*) through a single logical
    //     writer — deliberately narrow, never a content write.
    //   • Menu* / workplaces / design_kits writers (MenuContentController,
    //     GoogleBusinessAutoSync::seedWorkplace, the website-scan appliers):
    //     write NON-connection tables — out of scope for platform_connections
    //     locking; they warrant their own row-locking audit if ever contended.

    // ── Multi-account support ────────────────────────────────────────────
    // A platform can hold several connected accounts as SEPARATE rows: the
    // legacy single row (resource_id = platform slug) plus rows keyed
    // 'acct-<hash-of-canonical-input>'. Non-account rows under the same
    // platform use other prefixes ('event-' standalone events, 'link-'
    // custom links) and are excluded from the account view. The per-row
    // model keeps PlatformRefresher + the public integrations endpoint
    // working unchanged — each account refreshes and ships independently.

    /** Max connected accounts per platform (mirrors shop's MAX_BRANDS). */
    protected function maxAccounts(): int
    {
        return 10;
    }

    /**
     * Normalize a platform-canonical identity value (URL / handle) the same way
     * everywhere it's used: hashed into accountResourceId, stored in the
     * canonical_key column, and matched against on lookup. Keeping this in one
     * place is what makes the hash pre-image and the stored column agree.
     */
    private function normalizeCanonicalKey(string $key): string
    {
        return strtolower(trim($key));
    }

    /** Stable account resource id from the platform-canonical input (URL / handle). */
    protected function accountResourceId(string $canonicalKey): string
    {
        return 'acct-'.substr(sha1($this->normalizeCanonicalKey($canonicalKey)), 0, 16);
    }

    /**
     * The user's account rows for this platform, ordered — the legacy
     * default-id row (if any) plus every 'acct-*' row.
     *
     * @return Collection<int, IntegrationConnection>
     */
    protected function accountRows(User $user)
    {
        return $this->connectionsFor($user)->filter(
            fn (IntegrationConnection $row) => $row->resource_kind !== 'event'
                && $row->resource_kind !== 'link',
        )->values();
    }

    /**
     * Resolve the account row a picker-style request targets: explicit
     * `?account=<resourceId>` when present, else the first account row.
     */
    protected function requestedAccountRow(User $user, ?string $accountId): ?IntegrationConnection
    {
        $rows = $this->accountRows($user);
        if ($accountId !== null && $accountId !== '') {
            return $rows->firstWhere('resource_id', $accountId);
        }

        return $rows->first();
    }

    /**
     * The row a connect-status poll is asking about. A deferred connect whose
     * FIRST fetch failed terminally is soft-deleted by ConnectFetchJob (F26:
     * a row that never fetched OK is not a connection — left live it showed
     * as a connected "Japanese Breakfast" in the Platforms table and
     * provisioned an ingest source that would fail forever). The poll that
     * follows still has to surface the stored error, so this reads the live
     * row first and then the family's most recently trashed failed row.
     */
    protected function connectStatusRow(User $user, ?string $accountId, bool $perAccount = true): ?IntegrationConnection
    {
        $live = $perAccount ? $this->requestedAccountRow($user, $accountId) : $this->connectionFor($user);
        if ($live !== null) {
            return $live;
        }

        $trashed = $this->scopeToFamily($user->integrationConnections()->onlyTrashed())
            ->whereNull('last_refreshed_at')
            ->whereIn('last_refresh_status', ['unavailable', 'error'])
            ->whereNotNull('last_refresh_error');
        if ($perAccount && $accountId !== null && $accountId !== '') {
            $trashed->where('resource_id', $accountId);
        }

        return $trashed->orderByDesc('deleted_at')->first();
    }

    /**
     * Upsert an account row keyed by its canonical input (FOUND-14). Re-connecting
     * an input that matches an existing row — by derived hash, or by the stored
     * canonical_key column (bridges legacy rows / hash-scheme drift) — updates
     * that row in place; otherwise a new row is created, capped at maxAccounts().
     * Returns null when the cap is hit so the controller can shape the 422.
     *
     * $pending (Unit 11 W5 / LIFE-13..20, deferred connect): true writes a
     * 'pending' placeholder row instead of 'ok' — used by the (not-yet-wired,
     * W6) async-connect path so the controller can return 202 while
     * ConnectFetchJob fills the payload. On a reconnect, the pending write
     * MERGES over the existing payload rather than replacing it — see
     * upsertConnection()'s docblock for the three bugs that prevents.
     */
    protected function writeAccountConnection(User $user, string $canonicalKey, array $payload, bool $pending = false): ?IntegrationConnection
    {
        $needle = $this->normalizeCanonicalKey($canonicalKey);
        $rid = $this->accountResourceId($canonicalKey);
        $rows = $this->accountRows($user);

        // Derived-hash match first, then the stored canonical_key (indexed +
        // DB-unique). Replaces the old 7-field JSONB payload scan.
        $existing = $rows->firstWhere('resource_id', $rid)
            ?? $rows->firstWhere('canonical_key', $needle);

        if (! $existing && $rows->count() >= $this->maxAccounts()) {
            return null;
        }

        $values = [
            'payload' => $payload,
            'is_active' => true,
            // A pending re-write keeps the row's last successful refresh:
            // "last refreshed" is when the vendor last answered, and
            // ConnectFetchJob reads a NULL here as "this account has never
            // been fetched OK" to decide whether a failed connect leaves a
            // row behind at all (F26).
            'last_refreshed_at' => $pending ? $existing?->last_refreshed_at : now(),
            'last_refresh_status' => $pending ? 'pending' : 'ok',
            'last_refresh_error' => null,
            'consecutive_failures' => 0,
        ];

        return $this->upsertConnection(
            $user,
            $values,
            $existing?->resource_id ?? $rid,
            mergePayload: $pending,
            canonicalKey: $needle,
        );
    }

    /** First account row whose canonical_key matches (normalized). */
    protected function matchAccountByCanonical(User $user, string $canonicalKey): ?IntegrationConnection
    {
        $needle = $this->normalizeCanonicalKey($canonicalKey);

        return $this->accountRows($user)->firstWhere('canonical_key', $needle);
    }

    /**
     * Account list in the dashboard wire shape: each row's payload shaped by
     * $shape with the row's resource id attached as `id`.
     *
     * @return list<array<string, mixed>>
     */
    protected function accountsListData(User $user, callable $shape): array
    {
        return $this->accountRows($user)
            ->map(fn (IntegrationConnection $row) => ['id' => $row->resource_id, ...$shape($row->payload ?? [])])
            ->values()
            ->all();
    }

    /** Soft-delete every row for this platform (accounts + standalone rows). */
    protected function forgetAllConnections(User $user): void
    {
        foreach ($this->connectionsFor($user) as $connection) {
            $this->authorizeForUser($user, 'delete', $connection);
            $connection->delete();
        }
    }
}
