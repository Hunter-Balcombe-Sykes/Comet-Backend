<?php

namespace App\Routing\Verification;

/**
 * L2's answer: does the page this link names actually exist?
 *
 * Three cases, never a boolean. The distinction that a boolean loses is the
 * one that matters most — "we asked and it isn't there" versus "we were not
 * allowed to ask" — and collapsing them is how a brand's bot protection turns
 * into a user's link being refused.
 */
enum VerificationVerdict: string
{
    /** We fetched it and the page is real. */
    case Found = 'found';

    /**
     * We fetched it and the page is not there. This is the ONLY verdict that
     * refuses a save, so an adapter may only return it on a definitive signal
     * — a 404, or a redirect to the brand's own not-found/search page. A
     * timeout, a 403, a 429, a 5xx and an empty body are all Blocked.
     */
    case NotFound = 'not_found';

    /**
     * We could not tell. Bot protection, no adapter for this brand, a network
     * failure, or a body we could not read. Resolves to save-and-flag: the
     * connection is written with verification_state 'unverified'.
     *
     * A BLOCK IS NEVER AN INVALIDITY. Class C brands (Instagram, Facebook,
     * TikTok, Pinterest, Reddit, Etsy, OpenTable, LinkedIn) return 200 for
     * fabricated handles, so treating a reachable page as proof would certify
     * fakes; and Facebook returns 400 to a realistic Chrome UA while returning
     * 200 to our own. Neither direction of that is evidence about the link.
     */
    case Blocked = 'blocked';
}
