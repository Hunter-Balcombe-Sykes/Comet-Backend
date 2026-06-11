<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

class ConnectYoutubeMusicRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Artist/channel URL (music.youtube.com or youtube.com), a raw
            // UC… channel id, or an @handle — the scraper normalises.
            'url' => ['required', 'string', 'max:300'],
        ];
    }
}
