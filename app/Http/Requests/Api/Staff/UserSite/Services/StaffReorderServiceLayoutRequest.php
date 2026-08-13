<?php

namespace App\Http\Requests\Api\Staff\UserSite\Services;

use App\Http\Requests\BaseFormRequest;

// V2: Validates full service layout reorder — accepts a nested array of categories each containing an ordered list of service IDs, supporting uncategorized buckets.
class StaffReorderServiceLayoutRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'categories' => ['required', 'array'],

            'categories.*.id' => ['nullable', 'uuid'], // null = Uncategorized bucket
            // `present`, not `required`: an EMPTY block is legitimate and, for
            // some layouts, unavoidable. `required` rejects `[]`, while
            // StaffServiceManagementController::reorderLayout()'s coverage rule
            // demands that EVERY category appear in the payload — so under
            // `required` a professional holding one empty category could never
            // have a layout saved at all, the two rules contradicting each
            // other. Empty categories are a first-class state:
            // ServiceCollections::list() deliberately keeps a user-created
            // collection with no items visible ("add your first service here"),
            // and an all-categorised layout has an empty uncategorised bucket.
            // The key must still be sent — an ABSENT service_ids is a malformed
            // block, and `present` still catches it. Kept identical to
            // ReorderServiceLayoutRequest (the owner twin) on purpose: the two
            // surfaces gate the same payload and must not drift.
            'categories.*.service_ids' => ['present', 'array'],
            'categories.*.service_ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
