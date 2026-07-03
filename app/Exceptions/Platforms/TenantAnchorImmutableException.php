<?php

namespace App\Exceptions\Platforms;

use RuntimeException;

/**
 * Thrown when a save attempt tries to change `user_id` on an already-persisted
 * IntegrationConnection. A RuntimeException subclass is NOT in Laravel's
 * $internalDontReport list, so it is reported automatically to Nightwatch when
 * uncaught — no explicit report() call needed (contrast with the platform guard
 * above it, which throws a ValidationException that must be reported manually).
 *
 * Carries the connection id and both user_id values for triage. UUIDs only —
 * no PII is embedded in this exception.
 */
class TenantAnchorImmutableException extends RuntimeException
{
    public function __construct(
        public readonly string $connectionId,
        public readonly ?string $originalUserId,
        public readonly ?string $attemptedUserId,
    ) {
        parent::__construct(
            "Attempted to change user_id on connection {$connectionId} "
            ."from {$originalUserId} to {$attemptedUserId}."
        );
    }
}
