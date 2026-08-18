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

    /**
     * Owner decision, 2026-08-14: more than one category_id is a 422, not a
     * silent collapse. ServiceCollections::assign() is single-collection per
     * source, so a two-id payload previously stored the FIRST and returned 200
     * — discarding data the caller sent, with nothing surfaced to either side.
     * `max:1` still admits [] (the "move to Uncategorized" spelling).
     *
     * The owner and staff service request families are deliberately identical
     * here; change all four together or they drift.
     */
    public function messages(): array
    {
        return [
            'category_ids.max' => 'A service can sit in at most 50 categories.',
        ];
    }
}
