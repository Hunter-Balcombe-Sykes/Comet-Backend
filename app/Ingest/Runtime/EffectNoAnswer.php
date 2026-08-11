<?php

namespace App\Ingest\Runtime;

/**
 * The vendor did not answer: an outage, a timeout, a credential that was never
 * configured. Distinct from "answered, and the answer is nothing".
 *
 * Raised by HttpIo when a driver returns BilledEffectOutcome::NoAnswer, and
 * settled by the ledger as 'failed' rather than 'ok' — with a seven-day
 * freshness window, an outage settled 'ok' serves "no data" as truth for a week,
 * and a missing API key would do it for every request at once.
 */
class EffectNoAnswer extends \RuntimeException {}
