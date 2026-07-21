<?php

namespace App\Http\Requests\Api\User\Services;

use App\Http\Requests\BaseFormRequest;

// Validates a service→category re-assignment. `present` (not `sometimes`):
// this endpoint's sole job is setting category_id, so an absent key is a
// client bug; an explicit null moves the service to Uncategorized. Named
// distinctly from UpdateServiceCategoryRequest, which validates a
// ServiceCategory entity's own title/sort_order.
class UpdateServiceCategoryAssignmentRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'category_id' => ['present', 'nullable', 'uuid'],
        ];
    }
}
