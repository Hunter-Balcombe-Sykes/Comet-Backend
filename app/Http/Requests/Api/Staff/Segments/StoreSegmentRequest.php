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
    /**
     * Object criteria accept a fixed set of sub-keys; anything else is dropped
     * rather than rejected, mirroring how the engine ignores unknown top-level
     * filter keys.
     */
    private const OBJECT_SUB_KEYS = [
        'ig_followers' => ['min', 'max', 'synced_within_days'],
        'analytics' => ['metric', 'window_days', 'min', 'max'],
    ];

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

    /** @return array<string, mixed> */
    public static function stripUnknownSubKeys(array $filters): array
    {
        foreach (self::OBJECT_SUB_KEYS as $key => $allowed) {
            if (isset($filters[$key]) && is_array($filters[$key])) {
                $filters[$key] = array_intersect_key($filters[$key], array_flip($allowed));
            }
        }

        return $filters;
    }

    protected function prepareForValidation(): void
    {
        $filters = $this->input('filters');

        if (is_array($filters)) {
            $this->merge(['filters' => self::stripUnknownSubKeys($filters)]);
        }
    }
}
