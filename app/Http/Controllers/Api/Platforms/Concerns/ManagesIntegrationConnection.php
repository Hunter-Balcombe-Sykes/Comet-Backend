<?php

namespace App\Http\Controllers\Api\Platforms\Concerns;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\FeatureAvailability\FeatureAvailability;
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

    // Single-selection platforms store one row per user under this resource id.
    protected function defaultResourceId(): string
    {
        return $this->platform();
    }

    /** All of the user's active connections for this platform, ordered. */
    protected function connectionsFor(User $user)
    {
        return $user->integrationConnections()
            ->where('platform', $this->platform())
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();
    }

    protected function connectionFor(User $user, ?string $resourceId = null): ?IntegrationConnection
    {
        $connection = $user->integrationConnections()
            ->where('platform', $this->platform())
            ->where('resource_id', $resourceId ?? $this->defaultResourceId())
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
     * Omitted (null) on every other call site (highlights saves, tile refreshes,
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
                'resource_id' => $resourceId ?? $this->defaultResourceId(),
            ]);
            $this->authorizeForUser($user, 'create', $skeleton);
        }

        if ($canonicalKey !== null) {
            $values['canonical_key'] = $canonicalKey;
        }
        if ($resourceKind !== null) {
            $values['resource_kind'] = $resourceKind;
        }

        return IntegrationConnection::updateOrCreate(
            [
                'user_id' => $user->id,
                'platform' => $this->platform(),
                'resource_id' => $resourceId ?? $this->defaultResourceId(),
            ],
            $values,
        );
    }

    protected function writeConnection(User $user, array $payload, ?string $resourceId = null, ?string $canonicalKey = null, ?string $resourceKind = null): IntegrationConnection
    {
        return $this->upsertConnection($user, [
            'payload' => $payload,
            'is_active' => true,
            'last_refreshed_at' => now(),
            'last_refresh_status' => 'ok',
            'last_refresh_error' => null,
            'consecutive_failures' => 0,
        ], $resourceId, canonicalKey: $canonicalKey, resourceKind: $resourceKind);
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
     * $suffix scopes the lock more narrowly (e.g. one controller serving two
     * platforms that should not block each other).
     *
     * Note: assumes the using class extends ApiController (for error()).
     */
    protected function withConnectionLock(User $user, callable $callback, ?string $suffix = null): JsonResponse
    {
        $key = CacheKeyGenerator::platformConnectionLock($this->platform(), $user->id, $suffix);

        try {
            return Cache::lock($key, 10)->block(5, $callback);
        } catch (LockTimeoutException) {
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }
    }

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
        return 5;
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
            'last_refreshed_at' => $pending ? null : now(),
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

    /**
     * Return the highlights array preserved from an existing account row, or [] when
     * there is no matching row or no saved highlights yet. Used by connect() actions to
     * carry highlights across a same-account re-add without resetting curation choices.
     *
     * @return list<array<string, mixed>>
     */
    protected function preserveHighlights(User $user, string $canonicalKey): array
    {
        $existing = $this->matchAccountByCanonical($user, $canonicalKey)?->payload;

        return data_get($existing, 'highlights', []);
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
