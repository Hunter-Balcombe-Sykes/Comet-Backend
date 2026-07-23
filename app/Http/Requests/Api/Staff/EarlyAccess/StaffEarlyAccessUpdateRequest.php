<?php

namespace App\Http\Requests\Api\Staff\EarlyAccess;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

// OV-A: staff edit of a waitlist row's fields. Status/lifecycle is NOT
// editable here — it moves through the invite endpoint + bootstrap only.
class StaffEarlyAccessUpdateRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->trimStrings(['workplace_or_industry', 'source_ref']);
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'string', Rule::in(['partna', 'business'])],
            'workplace_or_industry' => ['sometimes', 'nullable', 'string', 'max:160'],
            'platforms' => ['sometimes', 'array', 'max:10'],
            'platforms.*' => ['string', 'max:120'],

            // Correct/set the build source. Deliberately NOT `sometimes`: the
            // pairing rules must run so a lone source_ref (or source_type) is
            // rejected. Absent from the request → absent from validated(), so an
            // unrelated PATCH never nulls an existing source.
            'source_type' => ['nullable', 'string', Rule::in(array_keys(config('partna.pre_account.generators', []))), 'required_with:source_ref'],
            'source_ref' => ['nullable', 'string', 'max:300', 'required_with:source_type'],
        ];
    }
}
