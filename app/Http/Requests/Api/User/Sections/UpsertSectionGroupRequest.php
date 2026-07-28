<?php

namespace App\Http\Requests\Api\User\Sections;

use App\Http\Requests\BaseFormRequest;

/**
 * A section's label/order override for one group (plan §7).
 *
 * Needed because a `group_by` other than `category` produces KEYS, not
 * records — grouping by month or first letter has no row to hang a label on.
 */
class UpsertSectionGroupRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->trimStrings(['label']);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'nullable', 'string', 'max:80'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'is_hidden' => ['sometimes', 'boolean'],
        ];
    }
}
