<?php

namespace App\Exceptions\Auth;

use App\Contracts\HttpStatusCodeInterface;
use RuntimeException;
use Throwable;

/**
 * A route marked `revocation.strict` was reached without a trustworthy answer
 * to "is this session revoked?".
 *
 * The JWT itself is valid — signature, issuer, audience and expiry all passed.
 * What could not be established is whether the session behind it was signed
 * out, banned, or force-logged-out since the token was issued. That answer
 * lives in Redis (TokenRevocationService's blocklist), and VerifySupabaseJwt
 * fails OPEN when Redis cannot supply it: one Redis blip must not lock every
 * logged-in customer out of their dashboard.
 *
 * On the handful of surfaces where a revoked session could do irreversible or
 * credential-level damage, that trade inverts — see the signed-off list in
 * docs/superpowers/plans/2026-08-05-auth-selective-failclosed-PLAN.md §3.
 * There, "we cannot tell" must stop the request.
 *
 * WHY 503 AND NOT 401. A 401 would be a lie with a harmful consequence: it
 * tells the client its credentials are bad, and the frontend's natural
 * response is to clear the session and bounce the user to login. Nothing is
 * wrong with the token — the *store* is unreachable. 503 + Retry-After is the
 * honest answer and the one that makes the client come back rather than
 * re-authenticate. Deliberately mirrors RateLimiterUnavailableException, which
 * solved the identical problem for the rate limiter; the shape is copied
 * rather than reinvented so both degraded-Redis paths look the same on the
 * wire.
 *
 * Implements HttpStatusCodeInterface so bootstrap/app.php's dedicated branch
 * keeps both the status and the Retry-After header; the generic branch would
 * drop the header and mask the message as an opaque 500.
 */
class RevocationUnverifiableException extends RuntimeException implements HttpStatusCodeInterface
{
    /**
     * Seconds a client is asked to wait. Matches RateLimiterUnavailableException
     * deliberately: the real recovery time is unknowable from inside the
     * request, and a long value would keep clients away well after Redis came
     * back. Short and fixed beats a guess.
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
