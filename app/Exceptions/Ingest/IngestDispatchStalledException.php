<?php

namespace App\Exceptions\Ingest;

use RuntimeException;

// Reported to Nightwatch when an `ingest:dispatch` tick claims one or more
// due sources and EVERY dispatch/run fails — the tick achieved nothing, which
// is a different incident than "one of N sources had a bad row" (already
// covered by the per-source report() at IngestDispatchCommand's catch) and is
// not derivable from that per-source signal: N identical exceptions collapse
// into one Nightwatch issue with a count, indistinguishable from a busy hour
// of the same flaky row (OBS-2).
class IngestDispatchStalledException extends RuntimeException
{
    public function __construct(public readonly int $claimed)
    {
        parent::__construct("Ingest dispatch tick claimed {$claimed} source(s) and dispatched none — the ingest pipeline is stopped.");
    }
}
