<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

class SaveYoutubeMusicHighlightsRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // `present` (not `required`) so an empty array is a valid "clear
            // my highlights" submission.
            'itemIds' => ['present', 'array', 'max:5'],
            'itemIds.*' => ['string', 'max:30'],
        ];
    }
}
