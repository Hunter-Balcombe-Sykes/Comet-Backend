<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

class ConnectVimeoRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Any vimeo.com profile or channel URL; the controller rejects video/reserved paths.
            'url' => ['required', 'string', 'max:300', 'regex:~^https?://(?:www\.)?vimeo\.com/~i'],
        ];
    }
}
