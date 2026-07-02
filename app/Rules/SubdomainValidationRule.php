<?php

namespace App\Rules;

use App\Services\Site\SubdomainAvailabilityService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

// Validates subdomain uniqueness for both user-facing and staff site update
// requests. Delegates to SubdomainAvailabilityService — the single source of
// truth shared with the live availability endpoint — so the save path and the
// real-time typing check can never disagree.
class SubdomainValidationRule implements ValidationRule
{
    public function __construct(
        private readonly ?string $currentSiteId,
        private readonly mixed $professional,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $check = app(SubdomainAvailabilityService::class)->check(
            (string) $value,
            $this->currentSiteId,
            $this->professional?->id,
        );

        if ($check['available']) {
            return;
        }

        match ($check['reason']) {
            SubdomainAvailabilityService::REASON_INVALID => null,
            SubdomainAvailabilityService::REASON_RESERVED => $fail('The subdomain "'.$value.'" is reserved and cannot be used.'),
            default => $fail('This subdomain is already taken.'),
        };
    }
}
