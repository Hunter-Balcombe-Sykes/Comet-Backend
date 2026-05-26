<?php

namespace App\Http\Requests\Api\Staff\UserSite\Services;

use App\Http\Requests\BaseFormRequest;

// V2: Validates reordering of service categories — requires an ordered array of distinct UUIDs.
class StaffReorderServiceCategoryRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
