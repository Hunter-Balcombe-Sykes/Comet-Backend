<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

class ConnectStravaRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A strava.com club URL (athlete profiles are login-walled).
            'url' => ['required', 'string', 'max:300', 'regex:~^https?://(?:www\.)?strava\.com/clubs/~i'],
        ];
    }
}
