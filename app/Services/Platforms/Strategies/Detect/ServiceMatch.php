<?php

namespace App\Services\Platforms\Strategies\Detect;

use App\Services\Platforms\Strategies\Contracts\Detection;
use Closure;

// Delegates URL detection to a platform service's own matcher (OpenTable /
// ResDiary / NowBookit isXUrl). Mirrors ProviderDetector::matches()'s
// service-delegating arms — the full (urlish'd) URL is passed through unchanged.
final readonly class ServiceMatch implements Detection
{
    /** @param Closure(string):bool $matcher */
    public function __construct(private Closure $matcher) {}

    public function matches(string $url): bool
    {
        return ($this->matcher)($url);
    }
}
