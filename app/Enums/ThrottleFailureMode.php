<?php

namespace App\Enums;

/**
 * What a named rate limiter does when its backing store is unreachable.
 *
 * Closed is the default and stays the default: opening or degrading a limiter
 * is an explicit, reviewed opt-in per limiter name. See
 * FailOpenThrottleRequests for the two allow-lists and the reasoning behind
 * each member.
 */
enum ThrottleFailureMode: string
{
    /** Gate opens. Only for idempotent public reads and beacons. */
    case Open = 'open';

    /** RateLimiterUnavailableException -> a clean 503 with Retry-After. */
    case Closed = 'closed';

    /** Keep limiting, from a different store. See LeadSubmissionRateLimiter. */
    case Fallback = 'fallback';
}
