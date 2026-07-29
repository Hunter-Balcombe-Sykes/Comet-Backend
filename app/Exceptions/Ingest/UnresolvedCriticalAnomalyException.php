<?php

namespace App\Exceptions\Ingest;

use RuntimeException;

// Reported to Nightwatch by `ingest:anomalies` when one or more `critical`,
// unresolved `ingest.anomalies` rows have sat past the age gate with no prior
// alert (LIFE-20). ONE aggregate exception per sweep, never one per row — a
// login wall tripping 50 delete_guard rows at once must page once, not 50
// times. `kinds` is deduplicated so the message names what regressed, not how
// many times.
class UnresolvedCriticalAnomalyException extends RuntimeException
{
    /** Kinds beyond this many are counted but not named in the message. */
    private const MAX_KINDS_IN_MESSAGE = 5;

    /** @param  list<string>  $kinds  deduplicated anomaly kinds in this sweep */
    public function __construct(public readonly int $count, public readonly array $kinds, public readonly string $oldestDetectedAt)
    {
        $shown = array_slice($kinds, 0, self::MAX_KINDS_IN_MESSAGE);
        $more = count($kinds) - count($shown);

        parent::__construct(sprintf(
            '%d unresolved critical ingest anomal%s past the alert age gate (oldest detected %s): %s%s',
            $count,
            $count === 1 ? 'y' : 'ies',
            $oldestDetectedAt,
            implode(', ', $shown),
            $more > 0 ? " (+{$more} more kind(s))" : ''
        ));
    }
}
