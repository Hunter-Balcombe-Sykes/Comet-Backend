<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Typed access to audit.auth_factor_events.
 *
 * Single insertion point for factor audit events; single query for the
 * brute-force window check used by the MFA Verification Hook handler.
 * All callers go through this — never DB::table('audit.auth_factor_events')
 * directly in controllers/services.
 */
class AuthFactorEventRepository
{
    public const TABLE = 'audit.auth_factor_events';

    public const FAILURE_EVENT_TYPES = [
        'verify_failed',
        'verify_rejected_by_hook',
    ];

    /**
     * Insert a new event row. Returns the generated id.
     *
     * WHK-101: $webhookId is the DB-level backstop to the Redis-only
     * idempotency anchor in SupabaseAuthHookController — pass it for
     * hook-originated events so a redelivered webhook can't double-record
     * after a Redis blip. Uses insertOrIgnore() keyed on the partial unique
     * index auth_factor_events_webhook_id_uk (WHERE webhook_id IS NOT NULL);
     * omit it (leave null) for direct user actions with no webhook behind
     * them (e.g. MfaController::destroy's 'unenroll' event) — those rows are
     * never deduped against each other.
     *
     * On Postgres, insertOrIgnore suppresses ONLY unique/exclusion conflicts
     * — the factor_type CHECK (auth_factor_events_factor_type_check) and the
     * user_id FK (auth_factor_events_user_fk) still raise on a malformed
     * payload, they are not silently swallowed. NOTE the SQLite divergence:
     * `INSERT OR IGNORE` there is BROADER and also swallows NOT NULL/CHECK
     * violations, so SQLite tests cannot catch a bad-payload insert that
     * Postgres would reject.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $userId,
        string $eventType,
        ?string $factorId = null,
        ?string $factorType = null,
        ?string $sessionId = null,
        ?string $ip = null,
        ?string $userAgent = null,
        array $metadata = [],
        ?string $webhookId = null,
    ): string {
        $id = (string) Str::uuid();

        // insertOrIgnore returns an affected-row count, not the id. On a
        // suppressed duplicate (affected === 0) the freshly-minted $id above
        // was never persisted, so we look up the row that actually won the
        // conflict and return ITS id — returning $id blind here would lie
        // about what's in the database. The extra SELECT only runs on the
        // rare duplicate path, and app_backend already has SELECT.
        $affected = DB::connection('pgsql')->table(self::TABLE)->insertOrIgnore([
            'id' => $id,
            'user_id' => $userId,
            'session_id' => $sessionId,
            'event_type' => $eventType,
            'factor_id' => $factorId,
            'factor_type' => $factorType,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'metadata' => json_encode($metadata),
            'webhook_id' => $webhookId,
            'created_at' => now()->toIso8601String(),
        ]);

        if ($affected === 0 && $webhookId !== null) {
            $existing = DB::connection('pgsql')->table(self::TABLE)
                ->where('webhook_id', $webhookId)->value('id');

            if ($existing !== null) {
                return (string) $existing;
            }
        }

        return $id;
    }

    /**
     * Count failure events for a given (user, factor) inside a rolling
     * window. Used by the MFA Verification Hook to decide whether to
     * reject the current attempt.
     */
    public function countRecentFailures(string $userId, string $factorId, int $windowSeconds): int
    {
        return (int) DB::connection('pgsql')->table(self::TABLE)
            ->where('user_id', $userId)
            ->where('factor_id', $factorId)
            ->whereIn('event_type', self::FAILURE_EVENT_TYPES)
            ->where('created_at', '>=', now()->subSeconds($windowSeconds)->toIso8601String())
            ->count();
    }
}
