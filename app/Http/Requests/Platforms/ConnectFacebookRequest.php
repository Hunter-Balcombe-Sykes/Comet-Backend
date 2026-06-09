<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

class ConnectFacebookRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:200'],
        ];
    }
}
