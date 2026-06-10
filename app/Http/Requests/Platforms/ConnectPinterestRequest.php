<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

class ConnectPinterestRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // pinterest.com profile URL (any locale) or a bare handle.
            'url' => ['required', 'string', 'max:200'],
        ];
    }
}
