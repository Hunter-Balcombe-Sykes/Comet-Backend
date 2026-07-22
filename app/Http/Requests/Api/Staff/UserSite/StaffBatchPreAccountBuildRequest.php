<?php

namespace App\Http\Requests\Api\Staff\UserSite;

use App\Http\Requests\BaseFormRequest;

// CSV batch marketing-build upload. Row-level validation happens per row in the
// controller (a bad row is reported, not fatal); only the file itself is
// validated here. authorize() is inherited final from BaseFormRequest.
class StaffBatchPreAccountBuildRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:2048'],
        ];
    }
}
