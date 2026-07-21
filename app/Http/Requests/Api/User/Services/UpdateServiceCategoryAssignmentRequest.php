<?php

namespace App\Http\Requests\Api\User\Services;

use App\Http\Requests\BaseFormRequest;

// Validates a service→category (re-)assignment. category_ids REPLACES the
// full membership set (multi-category); the legacy single category_id maps to
// a one-element set, and its explicit null moves the service to Uncategorized
// (zero memberships). One of the two keys must be present — this endpoint's
// sole job is membership editing, so an empty payload is a client bug. Named
// distinctly from UpdateServiceCategoryRequest, which validates a
// ServiceCategory entity's own title/sort_order.
class UpdateServiceCategoryAssignmentRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'category_ids' => ['sometimes', 'nullable', 'array', 'max:50'],
            'category_ids.*' => ['uuid', 'distinct'],
            'category_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    /**
     * At least one key must be PRESENT (null allowed — it's the "move to
     * Uncategorized" spelling). Enforced here because no built-in rule
     * expresses presence-without-requiredness across two fields.
     */
    public function after(): array
    {
        return [
            function ($validator) {
                if (! $this->exists('category_id') && ! $this->exists('category_ids')) {
                    $validator->errors()->add('category_ids', 'Provide category_ids (or the legacy category_id).');
                }
            },
        ];
    }
}
