<?php

namespace App\Http\Requests\Api\Staff\UserSite;

use Illuminate\Foundation\Http\FormRequest;

// V2: Validates staff status change on a professional account (active or suspended).
class StaffUpdateUserStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorization enforced at controller via authorizeForUser
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:active,suspended'],
        ];
    }
}
