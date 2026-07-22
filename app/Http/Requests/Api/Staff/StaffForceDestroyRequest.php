<?php

namespace App\Http\Requests\Api\Staff;

use App\Http\Requests\BaseFormRequest;

class StaffForceDestroyRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'override_obligations' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.min' => 'Reason must be at least 10 characters — record the support ticket reference and the justification.',
            'reason.max' => 'Reason must be 500 characters or fewer.',
        ];
    }
}
