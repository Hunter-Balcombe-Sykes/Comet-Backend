<?php

namespace App\Exceptions\Http;

use App\Contracts\HttpStatusCodeInterface;
use RuntimeException;
use Throwable;

/**
 * The rate-limiter's backing store is unreachable on a route whose limiter is
 * NOT in the fail-open allowlist (see FailOpenThrottleRequests).
 *
 * Those limiters ARE the security control on their route — enumeration
 * surfaces, account claim, public write forms. Serving them unmetered because
 * a cache node died would turn an availability incident into an abuse window,
 * so they fail closed. What this exception fixes is the SHAPE of that failure:
 * today a dead store surfaces as a raw RedisException, which the renderer's
 * generic branch turns into an opaque 500 with no Retry-After. A 503 with a
 * Retry-After is the honest answer — the service is temporarily unavailable
 * and the client should come back, not treat this as a permanent error.
 *
 * Implements HttpStatusCodeInterface so the renderer's dedicated branch keeps
 * both the status and the header; the generic branch would drop the header and
 * mask the message.
 */
class RateLimiterUnavailableException extends RuntimeException implements HttpStatusCodeInterface
{
    /**
     * Seconds a client is asked to wait. Deliberately short and fixed: the real
     * recovery time is unknowable from inside the request, and a long value
     * would keep clients away well after the store came back.
     */
    private const RETRY_AFTER_SECONDS = 5;

    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('Service temporarily unavailable. Please try again shortly.', 0, $previous);
    }

    public function getHttpStatusCode(): int
    {
        return 503;
    }

    /**
     * @return array<string, string|int>
     */
    public function getHttpHeaders(): array
    {
        return ['Retry-After' => self::RETRY_AFTER_SECONDS];
    }
}
