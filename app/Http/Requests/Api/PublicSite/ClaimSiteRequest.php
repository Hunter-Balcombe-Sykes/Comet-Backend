<?php

namespace App\Http\Requests\Api\PublicSite;

use App\Http\Requests\BaseFormRequest;

// Claim a pre-account (site-first signup) site by subdomain. authorize() is
// inherited final from BaseFormRequest (always true) — the claim invariants
// (build ready, not already claimed, one account per uid, email reuse) are
// all enforced by ClaimSiteService inside a locked transaction, not here.
class ClaimSiteRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'subdomain' => ['required', 'string', 'max:63'],
        ];
    }
}
