<?php

namespace App\Http\Requests\Api\Staff\EarlyAccess;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

// OV-A: staff manual-add to the early-access list (source=manual). Looser than
// the public form — staff can add with fewer than 2 platforms.
class StaffEarlyAccessStoreRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->trimStrings(['email', 'workplace_or_industry']);
        $this->lowercaseStrings(['email']);
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:320'],
            'type' => ['required', 'string', Rule::in(['partna', 'business'])],
            'workplace_or_industry' => ['nullable', 'string', 'max:160'],
            'platforms' => ['nullable', 'array', 'max:10'],
            'platforms.*' => ['string', 'max:120'],
        ];
    }
}
