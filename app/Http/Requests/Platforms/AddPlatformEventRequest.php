<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

class AddPlatformEventRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A single event-page URL (platform-validated server-side).
            'url' => ['required', 'string', 'max:500'],
        ];
    }
}
