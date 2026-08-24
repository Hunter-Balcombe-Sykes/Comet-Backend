<?php

namespace App\Http\Requests\Api\Staff\UserSite;

use Illuminate\Foundation\Http\FormRequest;

// The address a claim-invite will be sent to, and the ONLY address that will
// then be able to claim the site (ClaimSiteService's email gate). Getting it
// wrong hands a real business's sitepage to someone else, so it is validated
// as an actual deliverable address, not just a string containing '@'.
class StaffAttachContactEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // policy is checked in the controller, as elsewhere here
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'contact_email' => ['required', 'string', 'email:rfc,dns', 'max:255'],
        ];
    }
}
