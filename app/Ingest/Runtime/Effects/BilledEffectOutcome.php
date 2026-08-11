<?php

namespace App\Ingest\Runtime\Effects;

/**
 * Whether the vendor actually responded — NOT whether we got data.
 *
 * The distinction is load-bearing because `partna.ingest.effect_freshness_seconds`
 * defaults to seven days: settling a Places 429 or an Apify timeout as ok-with-null
 * would cache "this place has no data" for a week, and a misconfigured API key would
 * cache it for every place at once.
 */
enum BilledEffectOutcome
{
    /** The vendor answered. `data === null` means it answered "there is nothing here". */
    case Answered;

    /** We never got a response — outage, timeout, missing credential. Retryable. */
    case NoAnswer;
}
