<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

class ConnectTicketekRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A Ticketek artist / tour / event URL (AU, NZ, or .com).
            'url' => ['required', 'string', 'max:1000', 'url', 'regex:~^https?://(?:www\.|premier\.)?ticketek\.(?:com\.au|co\.nz|com)/~i'],
            'label' => ['sometimes', 'nullable', 'string', 'max:80'],
        ];
    }
}
