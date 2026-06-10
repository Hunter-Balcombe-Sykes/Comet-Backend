<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

class ConnectSquareRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A Square appointments / Square Online booking link.
            'url' => ['required', 'string', 'max:1000', 'url', 'regex:~^https?://(?:book\.squareup\.com|app\.squareup\.com|squareup\.com|[a-z0-9-]+\.square\.site)/~i'],
            'label' => ['sometimes', 'nullable', 'string', 'max:80'],
        ];
    }
}
