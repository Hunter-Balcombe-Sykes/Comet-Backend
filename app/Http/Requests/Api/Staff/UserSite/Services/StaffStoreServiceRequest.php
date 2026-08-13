<?php

namespace App\Http\Requests\Api\Staff\UserSite\Services;

use App\Http\Requests\BaseFormRequest;

// V2: Validates creation of a service — requires title and price in cents, with optional category, description, duration, currency, and active status.
// #SVC-1: category_ids mirrors StoreServiceRequest.php (multi-category); category_id
// is kept as the legacy single-value alias. Ownership of supplied ids is asserted
// in the controller, not here.
//
// Slice 3b Task 11: category_id lost its 'exists:service_categories,id' rule
// and gained 'sometimes', which is byte-for-byte what the owner-side
// StoreServiceRequest already declares. See StaffUpdateServiceRequest's
// docblock for the full reasoning — in short, the rule pointed at the LEGACY
// table while a staff create now lands a content item, so it 422'd every valid
// content.collections id; and it was never owner-scoped, so it added no
// protection the controller's own check doesn't already provide.
class StaffStoreServiceRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'category_id' => ['sometimes', 'nullable', 'uuid'],
            'category_ids' => ['sometimes', 'nullable', 'array', 'max:50'],
            'category_ids.*' => ['uuid', 'distinct'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price_cents' => ['required', 'integer', 'min:0'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
