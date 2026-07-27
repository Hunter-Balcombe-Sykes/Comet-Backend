<?php

namespace App\Ingest\Message;

/**
 * "Not now, try again in N seconds" — a vendor rate limit, or an actor run
 * still collecting. RELEASES the source claim rather than holding it (plan
 * §4): a deferred run must not look stranded, or the 2h stranded detector
 * alarms on perfectly healthy Apify collect cycles.
 */
final readonly class Deferred extends Message
{
    public function __construct(
        public int $retryAfterSeconds,
        public string $reason,
    ) {}
}
