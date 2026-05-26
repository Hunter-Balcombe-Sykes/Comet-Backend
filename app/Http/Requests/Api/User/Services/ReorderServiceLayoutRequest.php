<?php

namespace App\Http\Requests\Api\User\Services;

use App\Http\Requests\BaseFormRequest;

// V2: Validates full service layout reordering — categories with nested service ID arrays, supporting uncategorized buckets.
class ReorderServiceLayoutRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'categories' => ['required', 'array'],

            'categories.*.id' => ['nullable', 'uuid'], // null = Uncategorized bucket
            'categories.*.service_ids' => ['required', 'array'],
            'categories.*.service_ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
