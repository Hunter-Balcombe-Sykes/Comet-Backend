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
        $this->trimStrings(['email', 'workplace_or_industry', 'source_ref']);
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

            // Optional build source (handle / place id). Staff manual-add stays
            // looser than the public form — a source isn't required — but if given
            // it must be a complete pair with a known generator key (same rule as
            // PublicEarlyAccessSignupRequest). This is descriptive only: setting it
            // does NOT trigger a build (only the public flow calls requestBuild()).
            'source_type' => ['nullable', 'string', Rule::in(array_keys(config('partna.pre_account.generators', []))), 'required_with:source_ref'],
            'source_ref' => ['nullable', 'string', 'max:300', 'required_with:source_type'],
        ];
    }
}
