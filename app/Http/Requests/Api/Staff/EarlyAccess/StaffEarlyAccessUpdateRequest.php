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
        $this->trimStrings(['workplace_or_industry']);
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'string', Rule::in(['partna', 'business'])],
            'workplace_or_industry' => ['sometimes', 'nullable', 'string', 'max:160'],
            'platforms' => ['sometimes', 'array', 'max:10'],
            'platforms.*' => ['string', 'max:120'],
        ];
    }
}
