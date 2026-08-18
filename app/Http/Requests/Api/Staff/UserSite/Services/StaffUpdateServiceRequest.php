<?php

namespace App\Http\Requests\Api\Staff\UserSite\Services;

use App\Http\Requests\BaseFormRequest;

// V2: Validates partial update of a service — all fields optional with PATCH semantics including title, price, category, description, duration, currency, active status, and sort order.
// #SVC-1: category_ids mirrors StoreServiceRequest.php (multi-category); category_id
// is kept as the legacy single-value alias, replacing the full membership set.
// Ownership of supplied ids is asserted in the controller, not here.
//
// Slice 3b Task 11: category_id lost its 'exists:service_categories,id' rule,
// matching what UpdateServiceCategoryAssignmentRequest already does on the
// owner side. That rule pointed at the LEGACY table, so once the staff routes
// cut over it 422'd every valid content.collections id — a staff member
// passing a perfectly good category got a validation error, while the plural
// category_ids spelling (which never carried the rule) worked. It also bought
// nothing security-wise: `exists` is not owner-scoped, so it accepted ANY
// professional's category id. The real check is, and always was, in the
// controller — assertCollectionBelongsToProfessional() for a content item,
// assertLegacyCategoryBelongsToProfessional() for the §C2 Fresha branch, both
// owner-scoped, both 422.
class StaffUpdateServiceRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'category_id' => ['sometimes', 'nullable', 'uuid'],
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
