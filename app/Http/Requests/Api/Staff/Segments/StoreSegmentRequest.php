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

    /**
     * Object criteria accept a fixed set of sub-keys; anything else is dropped
     * rather than rejected, mirroring how the engine ignores unknown top-level
     * filter keys. The allowed set is DERIVED from the criteria's own rules()
     * rather than hand-maintained, so a sub-key added to a criterion's rules()
     * is automatically allowed here too — there is no third schema location
     * that can fall out of sync.
     *
     * @return array<string, list<string>>
     */
    private static function objectSubKeys(): array
    {
        $subKeys = [];

        foreach (SegmentCriteria::all() as $criterion) {
            /** @var SegmentCriterion $criterion */
            foreach (array_keys($criterion->rules()) as $ruleKey) {
                $segments = explode('.', $ruleKey);

                // A named sub-key rule looks like "filters.<parent>.<child>" —
                // exactly three segments. Laravel's `*` each-element wildcard
                // (e.g. filters.sector.*, for list-of-scalars criteria) isn't a
                // real key, so it's excluded rather than treated as one.
                if (count($segments) === 3 && $segments[0] === 'filters' && $segments[2] !== '*') {
                    $subKeys[$segments[1]][] = $segments[2];
                }
            }
        }

        return $subKeys;
    }

    /** @return array<string, mixed> */
    public static function stripUnknownSubKeys(array $filters): array
    {
        foreach (self::objectSubKeys() as $key => $allowed) {
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
