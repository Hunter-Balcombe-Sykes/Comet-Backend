<?php

namespace App\Services\Platforms;

/**
 * Why a raw Place Details fetch produced no place. fetchPlaceDetails() collapsed
 * all of these into a bare null, which was fine for a best-effort card and wrong
 * for a ledgered billed effect: three of them involve no charge, and only ONE of
 * them is Google actually answering.
 */
enum PlaceDetailsFailure: string
{
    /** No server key configured. Never reached the network. */
    case NotConfigured = 'not_configured';

    /** The FIRST budget claim was denied, so no request left the process. */
    case BudgetDenied = 'budget_denied';

    /** Every attempt threw (timeout, DNS, reset). A request may still have been billed. */
    case Transport = 'transport';

    /** 429, 5xx, or an auth/argument 4xx — Google did not answer about this place. */
    case UpstreamError = 'upstream_error';

    /**
     * 404 only. Google answered: there is no such place. Terminal, and the one
     * failure a caller may treat as an ANSWER rather than an outage.
     */
    case NotFound = 'not_found';
}
