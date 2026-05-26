<?php

namespace App\Http\Requests\Api\Staff\UserSite\Services;

use App\Http\Requests\BaseFormRequest;

// V2: Validates creation of a service category — requires a title (max 80 chars) with optional sort order.
class StaffStoreServiceCategoryRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:80'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
