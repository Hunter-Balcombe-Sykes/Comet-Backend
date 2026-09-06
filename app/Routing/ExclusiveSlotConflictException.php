<?php

namespace App\Routing;

use App\Contracts\HttpStatusCodeInterface;

/**
 * D5 (2026-09-06): thrown from inside SuggestionApplier::apply()'s exclusive-
 * slot lock when a LIVE rival connection is found in the same exclusive
 * routing_class, under a DIFFERENT surface_key, that $intent's own
 * conflicting_connection_id never named — a gap the same-surface_key-only
 * re-check in SuggestionsController::resolveSwapIncumbent() does not close
 * (that method only re-derives a rival within $intent->surface_key; a
 * cross-surface sibling — e.g. this intent is Fresha, the rival is Square,
 * both 'booking' — sails through untouched). The intent is already re-filed
 * as a 'conflict'-blocked Swap (same conflicting_connection_id column and
 * block_reason shape SourceReconciler::incumbentFor()'s own cross-surface
 * classification uses) by the time this is thrown, so accept() failing loud
 * here — instead of silently minting a second live connection next to the
 * rival — leaves the inbox with an immediately actionable "swap" card
 * (questionFor()'s 'conflict' branch) on the next read.
 */
final class ExclusiveSlotConflictException extends \RuntimeException implements HttpStatusCodeInterface
{
    public function __construct(public readonly string $intentId, public readonly string $conflictingConnectionId)
    {
        parent::__construct('Another connection now fills this slot — refresh to review the swap.');
    }

    /** @return array<string, string> */
    public function context(): array
    {
        return ['intent_id' => $this->intentId, 'conflicting_connection_id' => $this->conflictingConnectionId];
    }

    public function getHttpStatusCode(): int
    {
        return 409;
    }

    /** @return array<string, string|int> */
    public function getHttpHeaders(): array
    {
        return [];
    }
}
