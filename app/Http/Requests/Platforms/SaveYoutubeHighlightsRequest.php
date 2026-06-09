<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

class SaveYoutubeHighlightsRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'videoIds' => ['present', 'array', 'max:5'],
            'videoIds.*' => ['string', 'max:30'],
        ];
    }
}
