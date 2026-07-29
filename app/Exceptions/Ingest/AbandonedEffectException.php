<?php

namespace App\Exceptions\Ingest;

use RuntimeException;

// Reported to Nightwatch whenever EffectLedger::markAbandoned() flips a claimed-but-
// unsettled billed effect to 'abandoned' (#WHK-4). A Log::warning is a breadcrumb and
// an ingest.anomalies row is a DB queue — neither reaches Nightwatch on its own;
// report() is the house pattern for exactly this (see CheckPlatformRefreshBacklogCommand).
class AbandonedEffectException extends RuntimeException
{
    /** Digests beyond this many are counted but not named in the message. */
    private const MAX_DIGESTS_IN_MESSAGE = 5;

    /** @param  list<string>  $digests */
    public function __construct(public readonly int $count, public readonly array $digests)
    {
        $shown = array_slice($digests, 0, self::MAX_DIGESTS_IN_MESSAGE);
        $more = $count - count($shown);

        parent::__construct(sprintf(
            'Billed effect claim%s abandoned after being left claimed-but-unsettled — vendor may have charged: %s%s',
            $count === 1 ? '' : 's',
            implode(', ', $shown),
            $more > 0 ? " (+{$more} more)" : ''
        ));
    }
}
