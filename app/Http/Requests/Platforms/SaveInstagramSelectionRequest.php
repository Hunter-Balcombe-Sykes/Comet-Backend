<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

class SaveInstagramSelectionRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'images' => ['present', 'array', 'max:8'],
            'images.*' => ['string', 'url', 'max:2000'],
        ];
    }
}
