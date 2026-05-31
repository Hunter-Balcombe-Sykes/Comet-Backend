<?php

namespace App\Http\Requests\Api\User\Site;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

// Validates reordering smart links within one family (commerce or content).
class ReorderSmartLinksRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'family' => ['required', Rule::in(['commerce', 'content'])],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['uuid'],
        ];
    }
}
