<?php

namespace App\Http\Requests\Api\Staff;

use App\Http\Requests\BaseFormRequest;

// Wave 8 (2026-09-02): the staff settings page's own-record edit. `name` is
// the whole surface on purpose — role transitions stay on the audited
// second-actor path (PartnaStaffPolicy::update), the email is the auth
// identity (Supabase), and phone stays off every staff API surface per
// PartnaStaff::$hidden. Anything else in the body is simply not validated,
// so it never reaches the model.
class UpdateStaffMeRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
