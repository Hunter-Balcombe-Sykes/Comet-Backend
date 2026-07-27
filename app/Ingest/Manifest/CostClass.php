<?php

namespace App\Ingest\Manifest;

/**
 * What a run of this source costs us. Drives scheduler scoring and which
 * budget an effect claims against.
 */
enum CostClass: string
{
    /** Keyless, unmetered: RSS, oEmbed, plain HTML. */
    case Free = 'free';

    /** Metered API with a quota we pay for (Places, AI extraction). */
    case Metered = 'metered';

    /** Third-party actor run billed per invocation (Apify). */
    case Actor = 'actor';

    public function budgetWeight(): int
    {
        return match ($this) {
            self::Free => 1,
            self::Metered => 10,
            self::Actor => 50,
        };
    }
}
