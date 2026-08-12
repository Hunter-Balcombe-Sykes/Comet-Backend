<?php

namespace App\Ingest\Runtime\Effects;

use App\Ingest\Runtime\EffectNotAttempted;

/**
 * A billed-effect driver that can tell, without calling the vendor, that it
 * will not attempt this effect at all.
 *
 * Deliberately a SEPARATE interface rather than a method on
 * BilledEffectDriver: a driver with no cheap up-front check should not be
 * forced to implement an empty one, and adding to the base contract would
 * change every implementer for the benefit of the one that has a budget.
 *
 * The reward for implementing it is narrow and purely about waste (#MONEY-2):
 * EffectLedger claims a row before running the effect, so a driver that only
 * discovers its refusal inside run() costs an INSERT plus the DELETE that
 * EffectNotAttempted triggers. Refusing here costs neither.
 *
 * MUST behave exactly like EffectNotAttempted's contract: throw ONLY when no
 * request has left the process. A precheck that throws after a vendor call
 * would delete a claim for an effect that may have been billed.
 *
 * A precheck is an OPTIMISATION, never the authority. Whatever it screens for
 * must still be enforced inside run() — it runs outside the claim, so it
 * cannot be race-free, and a driver that relied on it alone would spend money
 * the moment two workers checked at the same time.
 */
interface PrecheckableBilledEffect
{
    /** @throws EffectNotAttempted when the effect will not be attempted. */
    public function precheck(BilledEffectContext $context): void;
}
