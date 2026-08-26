<?php

namespace App\Http\Requests\Api\Staff\UserSite\Services;

use App\Http\Requests\BaseFormRequest;

// V2: Validates reordering of service categories — requires an ordered array of distinct UUIDs.
class StaffReorderServiceCategoryRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // #SEC-13: same bound as PoolController::reorder's itemIds; kept
            // identical to the owner twin.
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
