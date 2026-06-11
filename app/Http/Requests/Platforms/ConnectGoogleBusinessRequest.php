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
            // Places-picker connect (canonical): the dashboard searches the
            // Places API by business name and sends the chosen result.
            'placeId' => ['required_without:url', 'string', 'max:300'],
            'name' => ['required_with:placeId', 'string', 'max:300'],
            'address' => ['nullable', 'string', 'max:500'],
            'lat' => ['required_with:placeId', 'numeric', 'between:-90,90'],
            'lng' => ['required_with:placeId', 'numeric', 'between:-180,180'],
            // Legacy connect: a Google Maps share link — short (maps.app.goo.gl /
            // share.google) or full /maps/place/ URL. Full URLs carry long data
            // segments, hence the cap. Kept so a pasted link still works.
            'url' => ['required_without:placeId', 'string', 'max:1500', 'regex:~^https?://(?:maps\.app\.goo\.gl|goo\.gl|share\.google|g\.co|(?:[a-z]+\.)?google\.[a-z.]+)/~i'],
        ];
    }
}
