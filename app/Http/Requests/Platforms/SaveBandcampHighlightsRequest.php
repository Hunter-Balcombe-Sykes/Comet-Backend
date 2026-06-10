<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

class SaveBandcampHighlightsRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'itemIds' => ['present', 'array', 'max:24'],
            'itemIds.*' => ['string', 'max:50'],
        ];
    }
}
