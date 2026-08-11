<?php

namespace App\Ingest\Runtime\Effects;

use App\Ingest\Runtime\EffectNotAttempted;

/**
 * Performs ONE billed effect. Invoked from inside EffectLedger::once(), exactly
 * where HttpIo used to throw — so claim-first and charge-once hold by
 * construction and a driver need not know the ledger exists.
 *
 * Two rules a driver MUST honour, because the ledger's money guarantees rest on
 * them and nothing else can check them:
 *
 *   1. Throw EffectNotAttempted ONLY before the first vendor call. once() removes the
 *      claim on that exception; raising it after a request has left the process
 *      would let the same request be re-billed.
 *   2. Return NoAnswer whenever the vendor did not respond. Returning
 *      Answered(null) for an outage caches that outage as truth for the whole
 *      freshness window.
 */
interface BilledEffectDriver
{
    public function supports(string $kind, string $name): bool;

    /** @throws EffectNotAttempted before the first vendor call, and only then */
    public function run(BilledEffectContext $ctx): BilledEffectResult;
}
