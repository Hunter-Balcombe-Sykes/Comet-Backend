<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

class ConnectSkoolRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A skool.com community URL.
            'url' => ['required', 'string', 'max:500', 'regex:~^https?://(?:www\.)?skool\.com/[a-z0-9][a-z0-9-]*~i'],
        ];
    }
}
