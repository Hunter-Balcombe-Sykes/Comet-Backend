<?php

namespace App\Ingest\Runtime;

/**
 * An effect was not performed on purpose: off-manifest host, exhausted
 * budget, or a ledger refusal. Distinct from a failure — nothing broke, we
 * declined. Callers translate this into a stream outcome rather than an
 * error.
 */
class EffectRefused extends \RuntimeException {}
