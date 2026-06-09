<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

class SaveApplePodcastHighlightsRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'episodeIds' => ['present', 'array', 'max:5'],
            'episodeIds.*' => ['string', 'max:30'],
        ];
    }
}
