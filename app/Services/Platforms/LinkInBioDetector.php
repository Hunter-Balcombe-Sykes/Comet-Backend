<?php

namespace App\Services\Platforms;

/**
 * Matches a URL against the curated 4-platform link-in-bio host list — the
 * ONLY signal LinkInBioScanJob uses to decide whether a bio link is a page
 * worth unrolling (Linktree/Milkshake/Beacons/Stan Store), not a general
 * "is this a link-in-bio-looking site" heuristic.
 */
class LinkInBioDetector
{
    private const HOSTS = ['linktr.ee', 'msha.ke', 'beacons.ai', 'stan.store'];

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
