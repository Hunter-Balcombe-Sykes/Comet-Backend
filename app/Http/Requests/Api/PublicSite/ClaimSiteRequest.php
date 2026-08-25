<?php

namespace App\Http\Requests\Api\PublicSite;

use App\Http\Requests\BaseFormRequest;

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
            // The frontend reads ?t= off the claim page URL and forwards it in
            // the BODY, so the token never reaches OUR access logs or Referer.
            // (It is still in the frontend's URL — the contract requires the
            // claim page to strip it with history.replaceState. Spec §6.3.)
            'claim_token' => ['nullable', 'string', 'max:128'],
        ];
    }
}
