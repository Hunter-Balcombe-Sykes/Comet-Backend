<?php

namespace App\Http\Requests\Api\PublicSite;

use App\Http\Requests\BaseFormRequest;
use App\Services\Profile\SectorTaxonomy;

// Claim a pre-account (site-first signup) site by subdomain. authorize() is
// inherited final from BaseFormRequest (always true) — the claim invariants
// (build ready, not already claimed, one account per uid, email reuse) are
// all enforced by ClaimSiteService inside a locked transaction, not here.
class ClaimSiteRequest extends BaseFormRequest
{
    // PRIV-101: marketing_opt_in defaults to false when absent — mirrors
    // PublicCustomerLeadRequest's parse. Fail-closed: no explicit opt-in means
    // no sidest_updates subscription gets created by ClaimSiteService.
    protected function prepareForValidation(): void
    {
        $optIn = filter_var($this->input('marketing_opt_in'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        $this->merge([
            'marketing_opt_in' => $optIn ?? false,
        ]);
    }

    public function rules(): array
    {
        return [
            'subdomain' => ['required', 'string', 'max:63'],
            'marketing_opt_in' => ['boolean'],
            // A.8 (decision 8): the sign-up flow's own answers ride the claim.
            // All optional so the ManyChat claim page and older clients keep
            // claiming; the service applies only what arrives. The handle rule
            // mirrors SubdomainAvailabilityService::check()'s shape gate.
            'handle' => ['sometimes', 'nullable', 'string', 'min:3', 'max:63', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/i'],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'sector' => ['sometimes', 'nullable', 'string', 'max:80',
                fn (string $attr, mixed $value, \Closure $fail) => $value !== null && ! SectorTaxonomy::isValid((string) $value)
                    ? $fail('Unknown sector.') : null,
            ],
            // The frontend reads ?t= off the claim page URL and forwards it in
            // the BODY, so the token never reaches OUR access logs or Referer.
            // (It is still in the frontend's URL — the contract requires the
            // claim page to strip it with history.replaceState. Spec §6.3.)
            'claim_token' => ['nullable', 'string', 'max:128'],
        ];
    }
}
