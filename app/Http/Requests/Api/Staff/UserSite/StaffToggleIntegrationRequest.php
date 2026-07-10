<?php

namespace App\Http\Requests\Api\Staff\UserSite;

use App\Http\Requests\BaseFormRequest;

// OV-A: staff enable/disable of a user's platform integration.
class StaffToggleIntegrationRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
        ];
    }
}
