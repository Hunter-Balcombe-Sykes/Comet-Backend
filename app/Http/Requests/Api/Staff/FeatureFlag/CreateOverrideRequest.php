<?php

namespace App\Http\Requests\Api\Staff\FeatureFlag;

use Illuminate\Foundation\Http\FormRequest;

class CreateOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'professional_id' => ['required', 'uuid', 'exists:core.users,id'],
            'enabled' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
