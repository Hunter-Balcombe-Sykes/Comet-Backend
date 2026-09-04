<?php

namespace App\Routing\Verification;

use App\Services\Http\SafeUrlFetcher;

/**
 * The shared transport half of an L2 adapter: fetch the page safely, turn the
 * HTTP outcome into a verdict, and hand anything brand-specific to interpret().
 *
 * The asymmetry here is deliberate and is the whole safety argument for this
 * lane. NotFound is the ONLY verdict that refuses to save a person's link, so
 * it is reachable from exactly one thing: the brand explicitly saying the page
 * is gone. Every other outcome — a timeout, a 403, a rate limit, a 5xx, a
 * body we did not expect, a transport failure — is Blocked, which saves the
 * link and flags it. "We could not check" must never be spelled the same way
 * as "it is not there".
 *
 * The byte cap is left at the fetcher's default on purpose. Shrinking it looks
 * like a saving (we only need the status and the final URL) but exceeding the
 * cap THROWS rather than truncating, so a small cap would turn every heavy page
 * into Blocked — YouTube's channel HTML is 2 MB.
 */
abstract class HttpVerificationAdapter implements VerificationAdapter
{
    public function __construct(protected readonly SafeUrlFetcher $fetcher) {}

    public function verify(string $url): VerificationVerdict
    {
        // tryFetch, not fetch: a refused connection or an SSRF rejection is
        // "we could not check", and an exception here would reach the job's
        // catch and mean the same thing by a longer route.
        $response = $this->fetcher->tryFetch($url);
        if ($response === null) {
            return VerificationVerdict::Blocked;
        }

        $status = (int) $response['status'];

        // 410 alongside 404: a brand that bothers to distinguish "gone" from
        // "never existed" is being MORE definitive, not less.
        if ($status === 404 || $status === 410) {
            return VerificationVerdict::NotFound;
        }

        if ($status < 200 || $status >= 300) {
            return VerificationVerdict::Blocked;
        }

        return $this->interpret($response, $url);
    }

    /**
     * What a 200 means for this brand. Default: the page is there.
     *
     * Overridden by brands that answer a dead page with a redirect to a live
     * one, where the 200 is real and the page behind it is not.
     *
     * @param  array{status:int, body:string, finalUrl:string, contentType:string, etag:?string, lastModified:?string}  $response
     */
    protected function interpret(array $response, string $requestedUrl): VerificationVerdict
    {
        return VerificationVerdict::Found;
    }
}
