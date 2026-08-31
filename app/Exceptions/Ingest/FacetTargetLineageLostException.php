<?php

namespace App\Exceptions\Ingest;

use RuntimeException;

// Thrown when a facet write's item was deleted between the identity resolve and the write, and
// content.item_merges cannot say where it went (#W1-DINT-8 / #W2-LIFE-5).
//
// NOT recoverable — the facets were NOT written. Deliberately thrown rather than skipped: a
// silently swallowed write is the defect class this campaign exists to remove, and the whole
// point of the retarget is that a missing parent is either explained or reported.
//
// Two writers can produce it legitimately, because neither leaves a ledger row:
// StaffServiceManagementController::forceDestroy() and ContentRetireChannelKindCommand both
// hard-delete content.items outright. Racing either surfaces here as
// 'no content.item_merges row explains it' — the correct loud failure, not a recovery.
//
// WHERE IT LANDS. On the connector path RunExecutor catches it, report()s, records a critical
// ingest.anomalies row and marks the run degraded; the next scheduled run re-derives the facets
// from ingest.record_versions onto the survivor. On the manual path it is a 500 the owner sees,
// with an actionable message instead of a raw foreign-key violation nobody can triage.
class FacetTargetLineageLostException extends RuntimeException
{
    public function __construct(
        public readonly string $userId,
        public readonly string $itemId,
        public readonly string $why,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(sprintf(
            'Facet write for user %s targeted item %s, which no longer exists, and %s.',
            $userId,
            $itemId,
            $why,
        ), 0, $previous);
    }
}
