<?php

namespace App\Services\Platforms\Strategies\Fetch;

use RuntimeException;

// Raised by a fetch strategy when the upstream answered 304 Not Modified to a
// conditional request (If-None-Match / If-Modified-Since) — the stored payload is
// still current. PlatformRefresher catches this and does a QUIET last_refreshed_at
// bump: no payload write, no Cloudflare purge, no design-preset rebuild (nothing
// changed). A sibling of FetchShape/FetchUnavailableException; like them it extends
// RuntimeException and is caught EXPLICITLY in PlatformRefresher::refresh(), never as
// the generic parent (a real scraper crash must not masquerade as a quiet 304).
class FetchNotModifiedException extends RuntimeException
{
    public function __construct(public readonly string $platform)
    {
        parent::__construct("not_modified: {$platform}");
    }
}
