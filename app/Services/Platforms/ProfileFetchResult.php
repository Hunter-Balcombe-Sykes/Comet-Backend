<?php

namespace App\Services\Platforms;

// Outcome of InstagramScraper::fetchProfileResult(): either the raw actor item
// or the reason it failed. Exactly one of $profile / $failure is non-null.
final readonly class ProfileFetchResult
{
    private function __construct(
        public ?array $profile,
        public ?ProfileFetchFailure $failure,
    ) {}

    public static function ok(array $profile): self
    {
        return new self($profile, null);
    }

    public static function failed(ProfileFetchFailure $failure): self
    {
        return new self(null, $failure);
    }
}
