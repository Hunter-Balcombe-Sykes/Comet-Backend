<?php

namespace App\Http\Requests\Api\User\Services;

use App\Http\Requests\BaseFormRequest;

// V2: Validates service category reordering — requires an array of distinct UUIDs representing the new order.
class ReorderServiceCategoryRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // #SEC-13: same bound as PoolController::reorder's itemIds.
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
