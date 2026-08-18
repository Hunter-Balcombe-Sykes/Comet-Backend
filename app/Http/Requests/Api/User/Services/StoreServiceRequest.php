<?php

namespace App\Http\Requests\Api\User\Services;

use App\Http\Requests\BaseFormRequest;

// Validates new service creation — title, price, description, currency,
// duration, active state, and (multi-)category memberships. Ownership of the
// supplied category ids is asserted in the controller.
class StoreServiceRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price_cents' => ['required', 'integer', 'min:0'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            // Memberships: category_ids (multi) or the legacy single category_id.
            'category_ids' => ['sometimes', 'nullable', 'array', 'max:50'],
            'category_ids.*' => ['uuid', 'distinct'],
            'category_id' => ['sometimes', 'nullable', 'uuid'],
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
