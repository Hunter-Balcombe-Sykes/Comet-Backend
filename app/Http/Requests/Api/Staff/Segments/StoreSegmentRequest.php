<?php

namespace App\Http\Requests\Api\Staff\Segments;

use App\Http\Requests\BaseFormRequest;
use App\Services\Profile\SectorTaxonomy;
use Illuminate\Validation\Rule;

// OV-A: validates segment create (name + filter definition). Update shares
// these rules via UpdateSegmentRequest.
class StoreSegmentRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'filters' => ['sometimes', 'array'],
            'filters.account_type' => ['sometimes', 'nullable', 'string', Rule::in(['partna', 'business'])],
            'filters.sector' => ['sometimes', 'nullable', 'array', 'max:50'],
            'filters.sector.*' => ['string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || ! SectorTaxonomy::isValid($value)) {
                    $fail("Unknown sector slug: {$value}");
                }
            }],
            'filters.created_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'filters.created_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:filters.created_from'],
            // true = any active integration; string = a specific platform key.
            'filters.has_integration' => ['sometimes', 'nullable', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_bool($value) && ! (is_string($value) && preg_match('/^[a-z][a-z0-9_-]*$/', $value))) {
                    $fail('has_integration must be true or a platform key.');
                }
            }],
            'filters.early_access' => ['sometimes', 'nullable', 'boolean'],
            'filters.include_manual_members' => ['sometimes', 'boolean'],
        ];
    }
}
