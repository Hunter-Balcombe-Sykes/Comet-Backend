<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

class ConnectBooksyRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A booksy.com business listing URL.
            'url' => ['required', 'string', 'max:500', 'regex:~^https?://(?:www\.)?booksy\.com/~i'],
        ];
    }
}
