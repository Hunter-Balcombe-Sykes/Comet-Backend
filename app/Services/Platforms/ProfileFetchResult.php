<?php

namespace App\Services\Platforms;

// Outcome of InstagramScraper::fetchProfileResult(): either the raw actor item
// or the reason it failed. Exactly one of $profile / $failure is non-null.
//
// $thin marks a SUCCESSFUL fetch whose post timeline was missing — a profile we
// have, but must not treat as complete. Only ever set on the ok() path: a
// failure has no profile to be thin about.
final readonly class ProfileFetchResult
{
    private function __construct(
        public ?array $profile,
        public ?ProfileFetchFailure $failure,
        public bool $thin = false,
    ) {}

    public static function ok(array $profile, bool $thin = false): self
    {
        return new self($profile, null, $thin);
    }

    public static function failed(ProfileFetchFailure $failure): self
    {
        return new self(null, $failure);
    }
}
