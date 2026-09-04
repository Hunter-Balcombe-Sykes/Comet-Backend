<?php

namespace App\Routing\Verification;

/**
 * Booksy answers a dead salon with 200, not 404 — it redirects to its own
 * search page and asks the browser to show a "this business was deleted"
 * modal. The status line therefore says nothing; the landing URL says
 * everything.
 *
 * Measured 2026-09-03, real page and fabricated id side by side:
 *
 *   real  /en-us/904207_hairgameconcepts-…  → 200, still on /en-us/904207_…
 *   fake  /en-us/99999901_nope_…            → 200, landed on
 *                /en-us/s/hair-salon/134655_los-angeles?do=showBusinessDeletedModal
 *
 * Only the explicit marker is read as NotFound. Booksy also redirects to that
 * search path in cases we have NOT characterised (a moved listing, a region
 * change), and reading a bare redirect as "gone" would refuse a link belonging
 * to a salon that is still trading. A redirect without the marker is Found:
 * the safe direction is to keep the person's link.
 */
final class BooksyAdapter extends HttpVerificationAdapter
{
    private const DELETED_MARKER = 'showBusinessDeletedModal';

    protected function interpret(array $response, string $requestedUrl): VerificationVerdict
    {
        return str_contains((string) $response['finalUrl'], self::DELETED_MARKER)
            ? VerificationVerdict::NotFound
            : VerificationVerdict::Found;
    }
}
