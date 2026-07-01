<?php

namespace App\Exceptions\Platforms;

use RuntimeException;

/**
 * Thrown when a platform value fails the PlatformRegistry gate in the
 * IntegrationConnection saving guard. Reported explicitly before the guard
 * re-throws a ValidationException — because ValidationException is in
 * Laravel's $internalDontReport list and would otherwise be invisible to
 * Nightwatch in a queued/background context.
 *
 * Carries the offending platform slug and the connection's user_id for
 * triage. A guard-trip is always anomalous: the request-layer
 * PlatformInRegistry Rule catches invalid platforms before they reach the
 * model, so this fires only when something bypasses that layer.
 */
class UnregisteredPlatformException extends RuntimeException
{
    public function __construct(
        public readonly string $platform,
        public readonly ?string $userId,
    ) {
        parent::__construct(
            "Attempted to save unregistered platform '{$platform}' for user {$userId}."
        );
    }
}
