<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;

// Default connect: a platform supplies a normalizer closure (its existing
// URL/handle parsing). The spine does not own per-platform regexes — those stay
// in the platform's own code and are passed in. This keeps connect logic exactly
// where it is today while giving the registry a uniform handle.
class UrlConnect implements ConnectStrategy
{
    /** @param callable(string):(array<string,mixed>|null) $normalizer */
    public function __construct(private $normalizer) {}

    public function resolve(string $input): ConnectResult
    {
        $selection = ($this->normalizer)($input);

        return $selection === null ? ConnectResult::fail() : ConnectResult::ok($selection);
    }
}
