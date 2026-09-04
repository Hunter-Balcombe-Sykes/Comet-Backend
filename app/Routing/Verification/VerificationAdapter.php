<?php

namespace App\Routing\Verification;

/**
 * How to ask ONE brand whether a page exists.
 *
 * Contract for an implementation:
 *
 *  - Return NotFound ONLY on a definitive negative signal from the brand — a
 *    404, or a redirect to its own not-found/search page. NotFound is the only
 *    verdict that refuses a save, so anything ambiguous is Blocked. A timeout,
 *    403, 429, 5xx, an empty body, or a shape you did not expect are all
 *    Blocked, not NotFound.
 *  - Fetch through App\Services\Http\SafeUrlFetcher. The URL came from a user
 *    or a scrape, so it is category B under the outbound-HTTP guard — never a
 *    bare Http:: call, never an allowlist entry.
 *  - Be cheap and bounded. This runs on a queue but a person is waiting on the
 *    other end of it.
 *  - Be deterministic for a given response, so its behaviour can be pinned from
 *    a recorded fixture (tests/fixtures/recorded/) rather than from the live
 *    site.
 */
interface VerificationAdapter
{
    public function verify(string $url): VerificationVerdict;
}
