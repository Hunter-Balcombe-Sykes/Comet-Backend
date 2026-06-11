<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

class ConnectBandcampRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Any {artist}.bandcamp.com URL — reduced to its origin server-side.
            'url' => ['required', 'string', 'max:500'],
        ];
    }
}
