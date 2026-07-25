<?php

namespace App\Http\Requests\Api\Staff\UserSite\Services;

use App\Http\Requests\BaseFormRequest;

// V2: Validates partial update of a service — all fields optional with PATCH semantics including title, price, category, description, duration, currency, active status, and sort order.
// #SVC-1: category_ids mirrors StoreServiceRequest.php (multi-category); category_id
// is kept as the legacy single-value alias, replacing the full membership set.
// Ownership of supplied ids is asserted in the controller, not here.
class StaffUpdateServiceRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'category_id' => ['sometimes', 'nullable', 'uuid', 'exists:service_categories,id'],
            'category_ids' => ['sometimes', 'nullable', 'array', 'max:50'],
            'category_ids.*' => ['uuid', 'distinct'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'price_cents' => ['sometimes', 'required', 'integer', 'min:0'],
            'currency_code' => ['sometimes', 'nullable', 'string', 'size:3'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
