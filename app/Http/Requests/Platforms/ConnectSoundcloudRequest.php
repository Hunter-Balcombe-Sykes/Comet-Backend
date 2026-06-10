<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

class ConnectSoundcloudRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A soundcloud.com profile / track / set link.
            'url' => ['required', 'string', 'max:500', 'regex:~^https?://(?:www\.|m\.)?soundcloud\.com/[a-z0-9_-]+(?:/[a-z0-9_-]+){0,2}/?(?:[?#].*)?$~i'],
        ];
    }
}
