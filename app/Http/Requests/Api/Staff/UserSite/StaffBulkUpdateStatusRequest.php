<?php

namespace App\Http\Requests\Api\Staff\UserSite;

use Illuminate\Foundation\Http\FormRequest;

// V2: Validates staff bulk status change on up to 100 professional accounts.
class StaffBulkUpdateStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorization enforced at controller via authorizeForUser
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'uuid', 'distinct'],
            'status' => ['required', 'string', 'in:active,suspended'],
        ];
    }
}
