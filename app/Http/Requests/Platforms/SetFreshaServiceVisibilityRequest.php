<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

class SetFreshaServiceVisibilityRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'serviceId' => ['required', 'string', 'max:50'],
            'hidden' => ['required', 'boolean'],
        ];
    }
}
