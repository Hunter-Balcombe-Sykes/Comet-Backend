<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

class ConnectAppleMusicRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'artist' => ['required', 'string', 'max:200'],
        ];
    }
}
