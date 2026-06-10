<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

class ConnectTidalRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Any tidal.com / listen.tidal.com entity link.
            'url' => ['required', 'string', 'max:300', 'regex:~^https?://(?:www\.|listen\.)?tidal\.com/~i'],
        ];
    }
}
