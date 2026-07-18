<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\PublicFeature;
use App\Models\Core\Site\Site;
use App\Services\FeatureAvailability\FeatureAvailability;
use Illuminate\Http\Exceptions\HttpResponseException;

// Enforcement point for staff-disabled non-integration public features. Used by
// the public submit controllers (enquiry / subscribe / customer-leads), which all
// extend ApiController — so $this->error() (the shared JSON error shape) resolves
// at runtime. See docs/superpowers/specs/2026-07-18-feature-availability-enforcement-design.md.
trait GatesPublicFeature
{
    /**
     * 422 the request when the resolved site owner has $feature staff-disabled.
     *
     * Fail-open by design: a null owner or the absence of an applicable rule means
     * "available" (FeatureAvailability::allows() already returns true on absence).
     * 422 + a machine-readable error mirrors PublicEnquiryController's existing
     * "not accepting enquiries" and PublicReportController's "422 not 404 on public
     * endpoints" — the public-endpoint convention, not 404/503/403.
     */
    protected function assertPublicFeatureAvailable(?Site $site, PublicFeature $feature): void
    {
        $owner = $site?->user;

        if ($owner && ! FeatureAvailability::for($owner)->allows($feature->availabilityKey())) {
            throw new HttpResponseException(
                $this->error('This feature is currently unavailable.', 422, [], ['error' => 'FEATURE_UNAVAILABLE'])
            );
        }
    }
}
