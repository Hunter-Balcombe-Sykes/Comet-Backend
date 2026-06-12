<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

class AddCustomLinkRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Any web page; scheme defaulted + SSRF-screened server-side.
            'url' => ['required', 'string', 'max:2048'],
        ];
    }
}
