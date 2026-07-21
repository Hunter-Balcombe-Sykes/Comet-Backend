<?php

namespace App\Http\Requests\Api\Staff\EarlyAccess;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

// Bulk "allow claiming": explicit ids[] OR all_waitlisted. authorize() is
// final-true from BaseFormRequest; the staffManage policy runs in the controller.
class StaffEarlyAccessApproveBulkRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'ids' => ['required_without:all_waitlisted', 'array', 'max:500'],
            'ids.*' => ['uuid', Rule::exists('pgsql.core.early_access_signups', 'id')],
            'all_waitlisted' => ['required_without:ids', 'boolean'],
        ];
    }
}
