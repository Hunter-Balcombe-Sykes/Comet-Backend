<?php

namespace App\Services\Platforms\Strategies\Detect;

use App\Services\Platforms\Strategies\Contracts\Detection;

// Host-regex URL detection. Mirrors ProviderDetector::matches()'s host arms
// (fresha, square, eventbrite, humanitix) EXACTLY — same parse_url + strtolower +
// pattern.
final readonly class HostMatch implements Detection
{
    public function __construct(private string $pattern) {}

    public function matches(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return (bool) preg_match($this->pattern, $host);
    }
}
