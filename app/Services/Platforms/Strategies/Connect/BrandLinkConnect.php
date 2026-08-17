<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;
use App\Services\Platforms\WebsiteLinkHarvester;

/**
 * Connect strategy for a DERIVED brand platform (PlatformRouteShape::Brand).
 *
 * These brands are link-only by owner ruling: they are connectable,
 * disconnectable, listed and routed, and they source nothing. So the whole
 * "resolve" step is classifying the pasted URL and storing it as a card —
 * there is no upstream fetch, no API, no scrape.
 *
 * The brand/URL match is asserted BEFORE this runs, by
 * PlatformConnectRequest::after(). This class re-classifies rather than
 * trusting that, because a ConnectStrategy is also reachable from
 * ConnectResolver outside the request lifecycle and must not assume a
 * validated input.
 */
final class BrandLinkConnect implements ConnectStrategy
{
    public function __construct(
        private readonly string $slug,
        private readonly string $label,
    ) {}

    public function resolve(string $input): ConnectResult
    {
        $url = trim($input);

        $classified = app(WebsiteLinkHarvester::class)->classify($url);
        if ($classified === null) {
            return ConnectResult::fail('That does not look like a '.$this->label.' link.');
        }

        // Stored shape matches the card the link lane already writes for every
        // other brand of this kind, and matches this platform's DSAR allowlist
        // entry (url, name, favicon, logo, provider). favicon/logo are resolved
        // by the link-card enrichment lane, not here — a connect must not block
        // on fetching a remote icon.
        return ConnectResult::ok([
            'url' => $url,
            'name' => $classified['label'] ?? $this->label,
            'provider' => $this->label,
        ], $this->slug);
    }
}
