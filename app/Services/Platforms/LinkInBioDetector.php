<?php

namespace App\Services\Platforms;

/**
 * Matches a URL against the curated link-in-bio host list — the ONLY signal
 * LinkInBioScanJob uses to decide whether a bio link is an aggregator page
 * worth unrolling, not a general "is this a link-in-bio-looking site"
 * heuristic. Dedicated aggregator hosts only — never website builders
 * (carrd.co etc.), whose pages are real sites, not link lists.
 */
class LinkInBioDetector
{
    private const HOSTS = [
        'linktr.ee', 'msha.ke', 'beacons.ai', 'stan.store',
        // 2026-07-23 expansion (signup-v2 A2): the live supernormal retest's
        // bio link was a linkin.bio page — unrecognized, so it landed as one
        // inert custom link instead of unrolling.
        'linkin.bio', 'lnk.bio', 'bio.link', 'campsite.bio', 'snipfeed.co',
        'komi.io', 'hoo.be', 'taplink.cc', 'solo.to', 'liinks.co',
        'heylink.me', 'allmylinks.com', 'direct.me',
        // M-1 (2026-08-21, industrybeans live): Sprout Social's link-in-bio.
        // Client-rendered shell, unrolled via LinkInBioApiUnroller::sprout().
        'sprout.link',
        // 2026-08-24 (themetapunter live): Lnk.Bio serves the same page on two
        // hostnames and only one of them is reachable — lnk.bio answers 403
        // behind Cloudflare, clk.bio answers 200 with the anchors intact. A
        // vendor's blocked-host verdict does not transfer to its own mirror.
        'clk.bio',
        // 2026-08-28 (igotyoubabeweddings live): bio.site is Squarespace's
        // Bio Sites, and its absence was expensive — the account's ONLY bio
        // link was a bio.site page, which classified as nothing, probed to
        // nothing, and was dropped without even a custom-link card. Note the
        // near-miss with 'bio.link' already on this list: different vendor,
        // one character apart. linkpop.com (Shopify's) and flow.page
        // (Flowcode) are the same species and join on the same evidence
        // grounds — dedicated aggregator hosts, not site builders.
        'bio.site', 'linkpop.com', 'flow.page',
    ];

    public function matches(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }
        foreach (self::HOSTS as $known) {
            if ($host === $known || str_ends_with($host, '.'.$known)) {
                return true;
            }
        }

        return false;
    }
}
