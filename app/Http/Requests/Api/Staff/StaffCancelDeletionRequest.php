<?php

namespace App\Http\Requests\Api\Staff;

use App\Http\Requests\BaseFormRequest;

// #SEC-3: cancel() previously had no FormRequest, so it read raw input(). The
// only field is an optional free-text reason for the audit trail.
class StaffCancelDeletionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
