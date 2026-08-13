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
            // `present`, not `required`: an EMPTY block is legitimate and, for
            // some layouts, unavoidable. `required` rejects `[]`, while
            // UserServiceController::reorderLayout()'s coverage rule demands
            // that EVERY category appear in the payload — so under `required`
            // an owner holding one empty category could never save a layout at
            // all, the two rules contradicting each other. Empty categories are
            // a first-class state: ServiceCollections::list() deliberately keeps
            // a user-created collection with no items visible ("add your first
            // service here"), and an all-categorised layout has an empty
            // uncategorised bucket. The key must still be sent — an ABSENT
            // service_ids is a malformed block, and `present` still catches it.
            'categories.*.service_ids' => ['present', 'array'],
            'categories.*.service_ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
