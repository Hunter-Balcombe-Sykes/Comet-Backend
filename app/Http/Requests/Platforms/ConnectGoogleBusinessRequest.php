<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

class ConnectGoogleBusinessRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A Google Maps share link — short (maps.app.goo.gl / share.google) or full
            // /maps/place/ URL. Full URLs carry long data segments, hence the cap.
            'url' => ['required', 'string', 'max:1500', 'regex:~^https?://(?:maps\.app\.goo\.gl|goo\.gl|share\.google|g\.co|(?:[a-z]+\.)?google\.[a-z.]+)/~i'],
        ];
    }
}
