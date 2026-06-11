<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

class ConnectDeezerRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Any deezer.com artist link (locale prefixes allowed).
            'url' => ['required', 'string', 'max:300', 'regex:~^https?://(?:www\.)?deezer\.com/~i'],
        ];
    }
}
