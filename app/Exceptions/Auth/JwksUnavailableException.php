<?php

namespace App\Exceptions\Auth;

use RuntimeException;
use Throwable;

/**
 * Thrown when the Supabase JWKS endpoint cannot be fetched or parsed —
 * an infrastructure outage, distinct from a bad/forged/expired token
 * (which fails later, at signature/claims verification).
 *
 * VerifySupabaseJwt uses the type to decide the failure response: a JWKS
 * outage is genuinely "service unavailable" (503), whereas a token-level
 * failure must stay a 401 so the client re-authenticates rather than retrying.
 */
class JwksUnavailableException extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct('Supabase JWKS unavailable: '.$previous->getMessage(), 0, $previous);
    }
}
