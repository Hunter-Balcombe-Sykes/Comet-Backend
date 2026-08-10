<?php

namespace App\Services\Platforms;

// Why an Instagram profile fetch failed. fetchProfile() historically collapsed
// every one of these into a bare null, so callers could not tell a handle that
// genuinely does not exist (terminal — don't retry, don't pay for another
// scrape) from an upstream break (retryable, and NOT the prospect's fault).
//
// ProfileNotFound is the ONLY case that means "this account isn't real".
enum ProfileFetchFailure: string
{
    // No Apify token, or the configured actor has no adapter. Never reached the network.
    case NotConfigured = 'not_configured';

    // The HTTP call threw (timeout, DNS, connection reset).
    case Transport = 'transport';

    // Apify answered non-2xx.
    case UpstreamError = 'upstream_error';

    // 2xx, but the dataset wasn't a non-empty list of items.
    case MalformedPayload = 'malformed_payload';

    // The actor reached Instagram but could not read the profile — the account
    // may well exist. Retryable.
    case ProfileUnavailable = 'profile_unavailable';

    // The actor positively reported the handle does not exist. Terminal.
    case ProfileNotFound = 'profile_not_found';
}
