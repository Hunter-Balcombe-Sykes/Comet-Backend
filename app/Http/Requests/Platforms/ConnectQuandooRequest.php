<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

class ConnectQuandooRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A quandoo restaurant page URL (any country domain).
            'url' => ['required', 'string', 'max:500', 'regex:~^https?://(?:www\.)?quandoo\.~i'],
        ];
    }
}
