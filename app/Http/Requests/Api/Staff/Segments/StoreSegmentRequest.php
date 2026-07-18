<?php

namespace App\Http\Requests\Api\Staff\Segments;

use App\Http\Requests\BaseFormRequest;
use App\Services\Segments\Criteria\SegmentCriteria;
use App\Services\Segments\Criteria\SegmentCriterion;

// OV-A: validates segment create (name + filter definition). Update shares
// these rules via UpdateSegmentRequest.
//
// Per-criterion filter rules come from SegmentCriteria so that a criterion's
// validation and its query compilation live in the same file and cannot drift.
// Only structural keys are declared here.
class StoreSegmentRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'filters' => ['sometimes', 'array'],
            'filters.include_manual_members' => ['sometimes', 'boolean'],
        ];

        foreach (SegmentCriteria::all() as $criterion) {
            /** @var SegmentCriterion $criterion */
            $rules = array_merge($rules, $criterion->rules());
        }

        return $rules;
    }
}
