<?php

namespace App\Http\Requests\Api\User\Services;

use App\Http\Requests\BaseFormRequest;

// V2: Validates new service category creation — title (required) and optional sort order.
class StoreServiceCategoryRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:80'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
