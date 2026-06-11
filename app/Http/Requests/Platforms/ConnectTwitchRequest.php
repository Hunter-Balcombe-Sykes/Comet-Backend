<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

class ConnectTwitchRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // twitch.tv channel URL or a bare handle; the controller canonicalizes.
            'url' => ['required', 'string', 'max:120'],
        ];
    }
}
