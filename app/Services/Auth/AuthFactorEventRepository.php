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
    ): string {
        $id = (string) Str::uuid();

        DB::connection('pgsql')->table(self::TABLE)->insert([
            'id' => $id,
            'user_id' => $userId,
            'session_id' => $sessionId,
            'event_type' => $eventType,
            'factor_id' => $factorId,
            'factor_type' => $factorType,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'metadata' => json_encode($metadata),
            'created_at' => now()->toIso8601String(),
        ]);

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
